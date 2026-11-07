<?php
namespace Financeiro\Controllers;

use Financeiro\Auth;
use Financeiro\Database;
use Financeiro\Models\CartaoModel;
use Financeiro\Models\LancamentoModel;
use Financeiro\Models\MesModel;
use Financeiro\Models\MetaModel;
use Financeiro\Models\SaldoConferenciaModel;
use Financeiro\Models\ContaModel;
use Financeiro\Relogio;
use Financeiro\Services\CartaoFatura;
use Financeiro\Services\ChecklistContas;
use Financeiro\Services\Destino;
use Financeiro\Services\MotorFinanceiro;
use Financeiro\Services\PrevisaoEdicao;
use Financeiro\Services\PrevisaoLote;
use Financeiro\Services\Sobra;
use Financeiro\Services\SugestaoAlocacao;
use Financeiro\View;

/**
 * Mês e Fechar mês. A previsão só é gravada por PrevisaoLote::gravar()
 * (invariante 7): aqui, pela edição linha a linha (POST /mes/previsao) e pelo Fechar mês.
 * Desfazer e reabrir ficam no menu ⋯ do mês. A meta é a previsão padrão de todo mês; o que
 * se grava no mês é a exceção (view previsao_efetiva).
 */
final class MesController
{
    public const OBJETIVOS = [1 => 'os dois', 3 => 'só a meta', 2 => 'só quitar'];

    /* ================================================================== GET /mes */

    public function index(): void
    {
        Auth::requireLogin();
        $hoje  = Relogio::hoje();
        $meses = MesModel::todos();
        if ($meses === []) {
            View::render('dashboard/vazio', ['pageTitle' => t('mês · MyMonetto')], 'mes');
            return;
        }
        $mesCal   = substr($hoje, 0, 7) . '-01';
        // sem ?mes=: o mês do calendário enquanto ele dura, mesmo fechado antes do fim; o seguinte ainda não começou
        $padrao   = in_array($mesCal, array_column($meses, 'mes'), true) ? $mesCal : MesModel::referencia($hoje);
        $mes      = mesValido($_GET['mes'] ?? null, $meses, $padrao);
        $situacao = self::situacaoEm($meses, $mes);
        $estado   = estadoMes($mes, $situacao, $hoje);
        $editavel = $situacao === 'PREVISAO';
        $ultimo   = (string) end($meses)['mes'];

        $d     = MotorFinanceiro::dados();
        $plano = MotorFinanceiro::calcularPlano();
        $linha = $plano[$mes];
        $prev  = $d['prev'][$mes] ?? [];
        // o lançado, que o plano conta (com o agendado), e o realizado, da coluna Realizado: o agendado (data depois de
        // hoje) fica fora dela até o dia dele (MotorFinanceiro::realizado)
        $real  = $d['real'][$mes] ?? [];
        $feito = MotorFinanceiro::realizado($hoje)['real'][$mes] ?? [];
        $cats  = $d['cats'];
        $metas = MetaModel::todas();
        $saldoIni = (float) ($d['aj']['saldo_ini'] ?? 0.0);
        $reserva  = (float) ($d['aj']['reserva_minima'] ?? 0.0);

        // em conta: saldo real pelos lançamentos até hoje (mês corrente ou passado); projeção do motor (mês futuro)
        $projetado = $mes > $mesCal;
        $emConta = $projetado
            ? (float) $linha['fica_na_conta']
            : LancamentoModel::saldoCalculado($saldoIni, min($hoje, date('Y-m-t', strtotime($mes))));

        $status = [];
        foreach (ChecklistContas::statusPorMes($mes, $hoje) as $s) {
            $status[(int) $s['categoria_id']] = $s;
        }

        // com o cartão ligado ao plano, a Fatura do cartão da fatura aberta em diante vem dele (só leitura aqui)
        $doCartaoDesde = CartaoFatura::mesesDoCartao($hoje);
        $catFatura = $doCartaoDesde !== null ? CartaoModel::categoriaFatura() : null;
        $faturaDoCartao = $editavel && $catFatura !== null && $mes >= $doCartaoDesde;

        // a previsão do mês como o plano a usa (a Fatura do cartão sem o que já foi pago dela em outro mês)
        $prevPlano = $estado === 'real' ? [] : MotorFinanceiro::previsaoDoMes($d, $mes, $estado, $mesCal);
        $parcelaDescontada = (float) ($linha['parcela_descontada'] ?? 0.0);
        $excecao = $d['excecao'][$mes] ?? [];
        $dia = $diaZeradas = $contasLinhas = $entradas = $incluir = [];
        foreach ($cats as $id => $c) {
            $p = round((float) ($prev[$id] ?? 0.0), 2);
            $r = round((float) ($real[$id] ?? 0.0), 2);
            $l = [
                'id' => $id, 'nome' => nomeCurto($c), 'nome_completo' => $c['nome'], 'chave' => $c['chave'] ?? null, 'grupo' => $c['grupo'],
                'essencial' => (bool) ($c['essencial'] ?? true), 'prev' => $p, 'ativo' => $c['ativo'] === 1,
                'real' => round((float) ($feito[$id] ?? 0.0), 2), 'lancado' => $r,
                'meta' => $metas[$id] ?? null,
                // previsão: a meta vale para todo mês; 'ajuste' = este mês tem valor próprio
                'meta_padrao' => PrevisaoEdicao::meta($d, $id), 'ajuste' => isset($excecao[$id]),
                'do_cartao' => $faturaDoCartao && $id === $catFatura,
                // conta ou entrada que veio menos que o previsto no mês aberto: a pessoa diz se foi o valor final ou se ainda falta
                'a_menos' => $editavel && $estado === 'aberto' ? PrevisaoEdicao::aMenos($d, $id, $mes, $parcelaDescontada) : null,
            ];
            $temValor = $p > 0.004 || $r > 0.004;
            if (!$temValor && !$l['ativo']) {
                continue;
            }
            switch ($c['grupo']) {
                case 'Dia a dia':
                    if ($temValor) {
                        $dia[] = $l;
                    } else {
                        // sem meta nem previsão no mês e sem gasto: fora da lista (dá para incluir no mês, como uma conta)
                        $diaZeradas[] = $l;
                        if ($editavel && $l['ativo']) {
                            $incluir[] = $l;
                        }
                    }
                    break;
                case 'Fixos':
                case 'Cartao':
                case 'Ano':
                    if (!$temValor) {
                        if ($editavel && $l['ativo'] && !$l['do_cartao']) {
                            $incluir[] = $l;                     // conta sem previsão no mês: "+ incluir"
                        }
                        break;
                    }
                    $s = $status[$id] ?? null;
                    $l['recorrente'] = $s !== null;
                    $l['status'] = $s['status'] ?? null;
                    $l['vencimento'] = $s['vencimento'] ?? null;
                    $l['paga_ate'] = $s['paga_ate'] ?? null;
                    $l['data_pagamento'] = $s['data_pagamento'] ?? null;
                    $l['pago'] = $s !== null ? round((float) $s['pago'], 2) : $l['real'];
                    if ($l['do_cartao']) {
                        $l['a_menos'] = null;                // a previsão é do Cartão
                    }
                    // fechado: o lançado; aberto: o maior entre lançado e previsto; futuro: o previsto (a regra do motor)
                    $pPlano = (float) ($prevPlano[$id] ?? 0.0);
                    $l['valor'] = round(match ($estado) { 'real' => $r, 'aberto' => max($r, $pPlano), default => $pPlano }, 2);
                    $contasLinhas[] = $l;
                    break;
                case 'Entradas':
                    if ($l['ativo'] || $temValor) {
                        $entradas[] = $l;
                    }
                    break;
            }
        }

        // com a parcela descontada do salário (consignado), o Salário aparece líquido: é o que cai na conta. A previsão
        // guardada continua bruta, para o plano devolver o valor cheio quando o empréstimo acabar (ver salvarPrevisao)
        // no mês fechado, o planejado do Salário também é o líquido: o que se compara com o que caiu na conta
        $parcelaPlanejada = $estado === 'real' ? self::parcelaDescontada($plano, $d, $mes) : $parcelaDescontada;
        if ($parcelaPlanejada > 0.004) {
            foreach ($entradas as &$e) {
                if (($cats[$e['id']]['chave'] ?? null) === MotorFinanceiro::CAT_SALARIO) {
                    $e['prev'] = round(max(0.0, (float) $e['prev'] - $parcelaPlanejada), 2);
                }
            }
            unset($e);
        }

        // ordem das contas: atrasadas → a vencer (por data) → sem vencimento; pagas à parte
        $pagas = $abertas = [];
        foreach ($contasLinhas as $l) {
            if ($editavel && in_array($l['status'], ['pago', 'pago_atrasado'], true)) {
                $pagas[] = $l;
            } else {
                $abertas[] = $l;
            }
        }
        $ordem = static fn(array $l): array => [
            $l['status'] === 'atrasado' ? 0 : ($l['recorrente'] && $l['status'] !== 'inexistente' ? 1 : 2),
            $l['recorrente'] ? (string) $l['vencimento'] : '',
            array_search($l['grupo'], ['Fixos', 'Cartao', 'Ano'], true), $l['nome'],
        ];
        if ($editavel) {
            usort($abertas, static fn($a, $b) => $ordem($a) <=> $ordem($b));
        }

        $menu = [
            'fechar'   => $editavel && $mes <= $mesCal,
            'reabrir'  => !$editavel,
            'desfazer' => PrevisaoLote::ultimoDesfazivel(),
        ];

        // erro da última edição (valor inválido etc.): reabre a linha com o que foi digitado
        $erro = $_SESSION['mes_erro'] ?? null;
        unset($_SESSION['mes_erro']);
        $erroLinha = is_array($erro) && ($erro['mes'] ?? '') === $mes ? $erro : null;

        View::render('mes/index', [
            'pageTitle' => t('{mes} · mês · MyMonetto', ['mes' => monthLabel($mes)]),
            'mes' => $mes, 'meses' => $meses, 'hoje' => $hoje, 'situacao' => $situacao, 'estado' => $estado,
            'editavel' => $editavel, 'ultimo' => $ultimo,
            'emConta' => round($emConta, 2), 'projetado' => $projetado, 'reserva' => $reserva,
            'dia' => $dia, 'diaZeradas' => $diaZeradas, 'contasAbertas' => $abertas, 'contasPagas' => $pagas,
            'entradas' => $entradas, 'linha' => $linha, 'menu' => $menu,
            'incluir' => $incluir, 'erroLinha' => $erroLinha,
            // o resumo do mês: a mesma linha do plano que dá a previsão do fim do mês
            'sobra' => Sobra::prevista($linha),
            // o livro-razão: poupança, investimentos, crédito e empréstimo; a barra do dinheiro do mês; o fechamento
            'movimentos' => Destino::movimentos($d, $linha, $hoje),
            'destino' => Destino::doMes($d, $linha, $mesCal, $hoje),
            'fechamento' => $editavel ? null : self::fechamento($mes),
        ], 'mes');
    }

    /* ================================================================== POST /mes/previsao */

    /**
     * "Veio menos que o previsto" (POST /mes/previsao com acao final ou falta): final grava a previsão do
     * mês igual ao que veio, só neste mês; falta não muda nada e diz o que o plano continua contando.
     * Null quando não há o que decidir: o mês não começou ou já veio o previsto.
     * @return ?array{celulas:list<array{0:int,1:string,2:?float}>, aviso:string, valor:float}
     */
    public static function valorFinal(array $d, int $cat, string $mes, string $acao, string $hoje): ?array
    {
        $linha = MotorFinanceiro::calcular($d, ['hoje' => $hoje])[$mes] ?? null;
        if ($linha === null || $linha['estado'] !== 'aberto') {
            return null;
        }
        $am = PrevisaoEdicao::aMenos($d, $cat, $mes, (float) $linha['parcela_descontada']);
        if ($am === null) {
            return null;
        }
        $c = $d['cats'][$cat];
        if ($acao === 'falta') {
            $vars = ['valor' => self::valorCurto(round($am['previsto'] - $am['veio'], 2)), 'nome' => nomeCurto($c)];
            return ['celulas' => [], 'valor' => $am['final'], 'aviso' => $c['sinal'] === 1
                ? t('O plano continua contando os {valor} que faltam receber de {nome}.', $vars)
                : t('O plano continua contando os {valor} que faltam pagar de {nome}.', $vars)];
        }
        return ['celulas' => PrevisaoEdicao::celulas($d, $cat, $mes, 'salvar', $am['final'], false), 'valor' => $am['final'],
                'aviso' => t('Valor final de {nome}: {valor}', ['nome' => nomeCurto($c), 'valor' => self::valorCurto($am['veio'])])];
    }

    /**
     * Edição da previsão de uma categoria no mês (e, com propagar, nos meses seguintes).
     * Campos: mes, categoria_id, acao (salvar | tirar | meta | final | falta), valor, propagar=1.
     * final e falta respondem ao "veio menos que o previsto" (valorFinal()): o valor vem do que foi lançado.
     */
    public function salvarPrevisao(): void
    {
        Auth::requireLogin();
        csrfCheck();

        $mes = self::mesPost();
        if ($mes === null) {
            flash('error', t('Não encontramos esse mês. Volte e tente de novo.'));
            redirecionar('/mes', 303);
        }
        $cat   = (int) ($_POST['categoria_id'] ?? 0);
        $volta = '/mes?mes=' . $mes . ($cat > 0 ? '#cat-' . $cat : '');
        $acao  = is_string($_POST['acao'] ?? null) ? $_POST['acao'] : 'salvar';
        $bruto = is_string($_POST['valor'] ?? null) ? trim($_POST['valor']) : '';
        $propagar = ($_POST['propagar'] ?? '') === '1';
        $incluindo = ($_POST['ancora'] ?? '') === 'incluir';     // veio do "+ incluir conta"

        $d = MotorFinanceiro::dados();
        $doCartaoDesde = CartaoFatura::mesesDoCartao();
        if ($doCartaoDesde !== null && $cat === CartaoModel::categoriaFatura() && $mes >= $doCartaoDesde) {
            flash('warn', t('A Fatura do cartão deste mês vem das compras lançadas no Cartão'));
            redirecionar('/cartao?fatura=' . $mes . '#fatura', 303);
        }
        $final = null;
        try {
            if ($acao === 'final' || $acao === 'falta') {
                $final = self::valorFinal($d, $cat, $mes, $acao, \Financeiro\Relogio::hoje());
                if ($final === null || $acao === 'falta') {
                    flash('success', $final['aviso'] ?? t('Nada mudou.'));     // falta: nada é gravado
                    redirecionar($volta, 303);
                }
                $celulas = $final['celulas'];
                $propagar = false;                                      // valor final: só este mês
            } else {
                $digitado = $acao === 'salvar' ? parseMoedaBr($bruto) : null;
                // o Salário é visto e digitado líquido; a previsão guarda o bruto (o plano desconta a parcela enquanto ela existir)
                if ($digitado !== null && ($d['cats'][$cat]['chave'] ?? null) === MotorFinanceiro::CAT_SALARIO) {
                    $digitado += (float) (MotorFinanceiro::linhaDoMes($mes)['parcela_descontada'] ?? 0.0);
                }
                $celulas = PrevisaoEdicao::celulas($d, $cat, $mes, $acao, $digitado, $propagar);
            }
        } catch (\InvalidArgumentException $e) {
            if (!$incluindo && !PrevisaoEdicao::editavel($d['cats'][$cat] ?? null)) {
                flash('error', $e->getMessage());                // não há linha para reabrir com o erro
                redirecionar('/mes?mes=' . $mes, 303);
            }
            // o valor recusado diz por quê, quando dá (acima do máximo, vírgula e ponto trocados)
            $msg = $e->getMessage() === t(PrevisaoEdicao::ERRO_VALOR) ? (motivoValor($bruto) ?? $e->getMessage()) : $e->getMessage();
            $_SESSION['mes_erro'] = ['mes' => $mes, 'cat' => $cat, 'msg' => $msg, 'valor' => $bruto,
                'propagar' => $propagar, 'ancora' => $incluindo ? 'incluir' : null];
            redirecionar($incluindo ? '/mes?mes=' . $mes . '#incluir' : $volta, 303);
        }

        $antes = MotorFinanceiro::calcularPlano();
        try {
            $lote = PrevisaoLote::gravar($propagar ? 'propagar' : 'manual', $celulas, (int) Auth::id());
        } catch (\InvalidArgumentException) {
            flash('error', t('Não foi possível salvar: {mes} está fechado.', ['mes' => mesAno($mes)]));   // fechado no meio do caminho
            redirecionar($volta, 303);
        } catch (\Throwable $e) {
            error_log('mes/previsao: ' . $e->getMessage());
            flash('error', t('Não foi possível salvar. Tente de novo.'));
            redirecionar($volta, 303);
        }
        if ($lote === null) {
            flash('success', t('Nada mudou.'));
            redirecionar($volta, 303);
        }
        $depois = MotorFinanceiro::calcularPlano();

        $c    = $d['cats'][$cat];
        $novo = $celulas[0][2];
        $meta = PrevisaoEdicao::meta($d, $cat);
        $oQue = match (true) {
            $final !== null          => $final['aviso'],
            $novo === null           => t('{nome} segue a meta {valor}', ['valor' => self::valorCurto((float) $meta), 'nome' => nomeCurto($c)]),
            $acao === 'tirar'        => t('{nome} tirado', ['nome' => nomeCurto($c)]),
            default                  => nomeCurto($c) . ' ' . self::valorCurto($novo - (isset($digitado) && ($c['chave'] ?? null) === MotorFinanceiro::CAT_SALARIO ? (float) (MotorFinanceiro::linhaDoMes($mes)['parcela_descontada'] ?? 0.0) : 0.0)),
        };
        $ultimoCel = (string) end($celulas)[1];
        $segs = [$oQue, monthLabel($mes) . ($ultimoCel !== $mes ? ' → ' . monthLabel($ultimoCel) : '')];
        if (isset($antes[$mes], $depois[$mes])) {
            $segs[] = t('fim de {mes} {antes} → {depois}', ['mes' => mesCurto($mes), 'antes' => num((float) $antes[$mes]['fica_na_conta']), 'depois' => num((float) $depois[$mes]['fica_na_conta'])]);
        }
        $reserva = (float) ($d['aj']['reserva_minima'] ?? 0.0);
        $alerta  = PrevisaoEdicao::piorou($antes, $depois, $mes, $reserva);
        if ($alerta !== null) {
            $segs[] = $alerta['tipo'] === 'vermelho' ? t('atenção: {mes} no vermelho', ['mes' => monthLabel($alerta['mes'])]) : t('atenção: {mes} abaixo da reserva', ['mes' => monthLabel($alerta['mes'])]);
        }
        flash($alerta !== null ? 'warn' : 'success', implode(' · ', $segs),
            ['url' => '/mes/desfazer', 'label' => t('desfazer'), 'campos' => ['lote' => $lote, 'mes' => $mes]]);
        redirecionar($volta, 303);
    }

    /* ================================================================== POST /mes/desfazer */

    public function desfazerLote(): void
    {
        Auth::requireLogin();
        csrfCheck();

        $lote = is_string($_POST['lote'] ?? null) ? $_POST['lote'] : '';
        $mes  = self::mesPost();
        $volta = voltaSegura(is_string($_POST['volta'] ?? null) ? $_POST['volta'] : null, '/mes' . ($mes !== null ? '?mes=' . $mes : ''));
        if (!preg_match('/^[0-9a-f]{16}$/', $lote)) {
            flash('error', t('Algo deu errado. Tente de novo.'));
            redirecionar($volta, 303);
        }
        try {
            $r = PrevisaoLote::desfazer($lote, (int) Auth::id());
        } catch (\InvalidArgumentException) {
            flash('error', t('Algo deu errado. Tente de novo.'));
            redirecionar($volta, 303);
        } catch (\Throwable $e) {
            error_log('mes/desfazer: ' . $e->getMessage());
            flash('error', t('Não foi possível desfazer. Tente de novo.'));
            redirecionar($volta, 303);
        }
        if (!empty($r['ja_desfeito'])) {
            flash('warn', t('Isso já tinha sido desfeito.'));
        } else {
            $n = (int) $r['restauradas'];
            $t = (int) $r['total'];
            flash($n === $t ? 'success' : 'warn', $n === $t ? tn($n, 'Desfeito: 1 valor voltou ao que era.', 'Desfeito: {n} valores voltaram ao que eram.') : t('Desfeito em parte: {n} de {total} valores.', ['n' => $n, 'total' => $t]));
        }
        redirecionar($volta, 303);
    }

    /* ================================================================== GET /mes/fechar */

    public function fecharForm(): void
    {
        Auth::requireLogin();
        $hoje   = Relogio::hoje();
        $meses  = MesModel::todos();
        $mesCal = substr($hoje, 0, 7) . '-01';
        // sem ?mes=: o mais antigo ainda em PREVISAO até o mês do calendário
        $padrao = $mesCal;
        foreach ($meses as $m) {
            if ($m['situacao'] === 'PREVISAO' && $m['mes'] <= $mesCal) {
                $padrao = (string) $m['mes'];
                break;
            }
        }
        if ($meses === []) {
            View::render('dashboard/vazio', ['pageTitle' => t('fechar mês · MyMonetto')], 'mes');
            return;
        }
        $mes = mesValido($_GET['mes'] ?? null, $meses, $padrao);
        if (!in_array($mes, array_column($meses, 'mes'), true)) {
            $mes = (string) ($meses[0]['mes'] ?? $mesCal);
        }
        $situacao = self::situacaoEm($meses, $mes);

        if ($mes > $mesCal) {
            http_response_code(400);
            View::render('mes/fechar', [
                'pageTitle' => t('fechar {mes} · MyMonetto', ['mes' => monthLabel($mes)]),
                'mes' => $mes, 'meses' => $meses, 'hoje' => $hoje, 'bloqueado' => true,
            ], 'mes');
            return;
        }

        $real = $situacao === 'REAL';
        $d = MotorFinanceiro::dados();
        // bloco 1: como ficará depois de fechado (em REAL já é o fechado)
        $plano = $real ? MotorFinanceiro::calcularPlano()
                       : MotorFinanceiro::calcular($d, ['hoje' => $hoje, 'tratarComoReal' => [$mes]]);
        if (!isset($plano[$mes])) {
            View::render('dashboard/vazio', ['pageTitle' => t('fechar mês · MyMonetto')], 'mes');
            return;
        }
        $linha = $plano[$mes];
        [$entrou, $saiu, $balanco] = MotorFinanceiro::balancoTotal($linha);

        $parcelaDescontada = self::parcelaDescontada($plano, $d, $mes);

        // bloco 2: desvios (3 maiores |real − previsto| ≥ 10, só operacionais)
        $desvios = [];
        $avisoParcela = null;
        foreach ($d['cats'] as $id => $c) {
            if ($c['operacional'] !== 1) {
                continue;
            }
            $r = round((float) ($d['real'][$mes][$id] ?? 0.0), 2);
            $p = round((float) ($d['prev'][$mes][$id] ?? 0.0), 2);
            if (($c['chave'] ?? null) === MotorFinanceiro::CAT_SALARIO && $parcelaDescontada > 0 && $p > 0) {
                // o salário deveria chegar líquido da parcela; se chegou igual ao bruto previsto, avisa
                $pLiq = round(max(0.0, $p - $parcelaDescontada), 2);
                if ($r > 0 && abs($r - $p) < 1.0) {
                    $avisoParcela = t('A renda entrou com o valor cheio ({bruto}), sem descontar a parcela do empréstimo ({parcela}). Lance o valor que caiu na conta ou ajuste a 1ª parcela na aba Empréstimo.', ['bruto' => num($r), 'parcela' => num($parcelaDescontada)]);
                }
                if (abs($r - $pLiq) < abs($r - $p)) {
                    $p = $pLiq;
                }
            }
            if (abs($r - $p) >= 10 - 0.005) {
                $desvios[] = ['nome' => nomeCurto($c), 'real' => $r, 'prev' => $p, 'dif' => $r - $p, 'sinal' => $c['sinal']];
            }
        }
        usort($desvios, static fn($a, $b) => abs($b['dif']) <=> abs($a['dif']));
        $desvios = array_slice($desvios, 0, 3);

        $fimMes = date('Y-m-t', strtotime($mes));
        $dataBanco = min($hoje, $fimMes);
        $faltam = (int) ((strtotime($fimMes) - strtotime($hoje)) / 86400);

        // bloco 4: prévia da sugestão do mês seguinte, como se este já estivesse fechado
        $prox = null;
        foreach ($meses as $m) {
            if ($m['mes'] > $mes) {
                $prox = $m['situacao'] === 'PREVISAO' ? (string) $m['mes'] : null;
                break;
            }
        }
        $previa = null;
        if (!$real && $prox !== null && MotorFinanceiro::v2()) {
            $obj = (int) round((float) ($d['aj']['objetivo'] ?? 1));
            $obj = isset(self::OBJETIVOS[$obj]) ? $obj : 1;
            $s = SugestaoAlocacao::calcular($prox, $obj, false, ['hoje' => $hoje, 'tratarComoReal' => [$mes]]);
            if ($s['meses'] !== []) {
                $diaTotal = array_sum($s['valores'][$prox] ?? []);
                $refMeses = $s['ref']['meses'];
                $previa = [
                    'mes' => $prox, 'obj' => $obj, 'dia' => $diaTotal, 'livre' => (float) ($s['niveis'][$prox] ?? 0.0),
                    // o rótulo da base é o do mês que está sendo fechado (`base out`)
                    'base' => in_array($mes, $refMeses, true)
                        ? mesCurto($mes)
                        : (self::refRotulo($refMeses) ?? $s['rotulo_ref']),
                    'n' => count(SugestaoAlocacao::celulas($s, 'so', array_keys($s['mudancas']['so']))),
                ];
            }
        }

        $erro = $_SESSION['fe_erro'] ?? null;
        unset($_SESSION['fe_erro']);

        View::render('mes/fechar', [
            'pageTitle' => $real ? t('{mes} · fechado · MyMonetto', ['mes' => monthLabel($mes)]) : t('fechar {mes} · MyMonetto', ['mes' => monthLabel($mes)]),
            'mes' => $mes, 'meses' => $meses, 'hoje' => $hoje, 'bloqueado' => false, 'real' => $real,
            'entrou' => $entrou, 'saiu' => $saiu, 'saldoMes' => $balanco,
            // a sobra até hoje (no mês fechado, a do mês) e o que leva dela ao balanço: as movimentações
            'sobra' => Sobra::de($linha) + ['base' => $real ? 'mes' : 'hoje'],
            'movimentos' => [
                'cred' => (float) $linha['credito_emprestimo'], 'amort' => (float) $linha['amortizacao_extra'],
                'poup' => (float) $linha['poupanca_liquido'], 'inv' => (float) ($linha['invest_liquido'] ?? 0.0),
            ],
            'contaAntes' => (float) $linha['sobrou_mes_anterior'], 'contaDepois' => (float) $linha['fica_na_conta'],
            'desvios' => $desvios, 'avisoParcela' => $avisoParcela,
            'dataBanco' => $dataBanco, 'dataMin' => self::dataMinBanco($hoje), 'faltam' => max(0, $faltam),
            'previa' => $previa, 'erro' => is_array($erro) && ($erro['mes'] ?? '') === $mes ? $erro : null,
            // com mais de uma conta, um campo para cada uma, com o saldo do app na data do banco
            'contasBanco' => !$real && ContaModel::varias() ? array_map(static fn(array $c): array => $c + ['saldo' => ContaModel::saldo($c['id'], $dataBanco)], ContaModel::ativas()) : [],
        ], 'mes');
    }

    /** Limite inferior da data do saldo do banco: o mesmo de /conferir (60 dias, nunca antes da véspera do 1º mês). */
    private static function dataMinBanco(string $hoje): string
    {
        $minimo = date('Y-m-d', strtotime('-60 days', strtotime($hoje)));
        $primeiro = MesModel::primeiroDia();
        if ($primeiro !== null) {
            $minimo = max($minimo, date('Y-m-d', strtotime('-1 day', strtotime($primeiro))));
        }
        return $minimo;
    }

    /* ================================================================== POST /mes/fechar */

    public function fechar(): void
    {
        Auth::requireLogin();
        csrfCheck();

        $hoje = Relogio::hoje();
        $mes = self::mesPost();
        if ($mes === null) {
            flash('error', t('Não encontramos esse mês. Volte e tente de novo.'));
            redirecionar('/mes', 303);
        }
        $meses = MesModel::todos();
        $volta = '/mes/fechar?mes=' . $mes;
        if (self::situacaoEm($meses, $mes) === 'REAL') {
            redirecionar($volta, 303);                           // já fechado: nada a gravar
        }
        if (!MesModel::podeFechar($mes, $hoje)) {
            // a mesma regra da tela: só a partir do último dia do mês
            $fim = MesModel::fechaAPartirDe($mes);
            flash('error', t('Dá para fechar {mes} a partir de {data}.', ['mes' => mesExtenso($mes), 'data' => t('{dia} de {mes}', ['dia' => (int) substr($fim, 8, 2), 'mes' => mesExtenso($mes)])]));
            redirecionar('/mes?mes=' . $mes, 303);
        }

        // saldo do banco (opcional); com várias contas, o de cada uma (saldo_conta[id])
        $saldoBruto = trim(is_string($_POST['saldo'] ?? null) ? $_POST['saldo'] : '');
        $saldo = null;
        $porConta = [];
        $brutosContas = [];
        $dataSaldo = min($hoje, date('Y-m-t', strtotime($mes)));
        $erroContas = null;
        if (is_array($_POST['saldo_conta'] ?? null) && ContaModel::varias()) {
            [$porConta, $brutosContas, $erroContas] = DashboardController::lerSaldosContas($_POST['saldo_conta']);
        }
        if ($saldoBruto !== '' || $porConta !== [] || $erroContas !== null) {
            $saldo = $saldoBruto !== '' ? parseMoedaBr($saldoBruto) : 0.0;
            $erro = $erroContas;                                 // uma das contas não deu para ler
            if ($erro === null && $saldo === null) {
                $erro = t('Digite o saldo como aparece no banco, por exemplo 1.200,50.');
            } elseif ($erro === null && ($_POST['quando'] ?? 'hoje') === 'outro') {
                $data = (string) ($_POST['data'] ?? '');
                $ts = preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) ? strtotime($data) : false;
                if ($ts === false || date('Y-m-d', $ts) !== $data || $data > $hoje || $data < self::dataMinBanco($hoje)) {
                    $erro = t('Escolha uma data válida.');
                } else {
                    $dataSaldo = $data;
                }
            }
            if ($erro !== null) {
                $_SESSION['fe_erro'] = ['mes' => $mes, 'msg' => $erro, 'saldo' => $saldoBruto, 'contas' => $brutosContas];
                redirecionar($volta . '#banco', 303);
            }
            if ($saldoBruto === '') {
                $saldo = null;                                   // só as contas
            }
        }

        $prox = null;
        foreach ($meses as $m) {
            if ($m['mes'] > $mes) {
                $prox = $m['situacao'] === 'PREVISAO' ? (string) $m['mes'] : null;
                break;
            }
        }
        $aplicar = ($_POST['aplicar'] ?? '') === '1' && $prox !== null && MotorFinanceiro::v2();

        // uma transação só: conferência → congela as metas do mês → REAL → limpa cache →
        // sugestão do mês seguinte. Congelar = gravar como linhas do mês a previsão que vinha da
        // meta, para o mês fechado não mudar quando a meta mudar depois (reabrir descongela).
        $pdo = Database::connection();
        $lote = null;
        $mudou = 0;
        try {
            $pdo->beginTransaction();
            if ($saldo !== null) {
                SaldoConferenciaModel::registrar($dataSaldo, round($saldo, 2), (int) Auth::id());
            }
            foreach ($porConta as $contaId => $v) {
                SaldoConferenciaModel::registrar($dataSaldo, $v, (int) Auth::id(), $contaId);
            }
            PrevisaoLote::congelar($mes, (int) Auth::id());
            MesModel::setSituacao($mes, 'REAL');
            MotorFinanceiro::limparCache();
            if ($aplicar) {
                $obj = (int) round((float) (MotorFinanceiro::dados()['aj']['objetivo'] ?? 1));
                $obj = isset(self::OBJETIVOS[$obj]) ? $obj : 1;
                $s = SugestaoAlocacao::calcular($prox, $obj, false, ['hoje' => $hoje]);   // o mês fechado já conta
                $celulas = SugestaoAlocacao::celulas($s, 'so', array_keys($s['mudancas']['so']));
                $lote = PrevisaoLote::gravar('fechar', $celulas, (int) Auth::id());
                $mudou = count($celulas);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            MotorFinanceiro::limparCache();
            error_log('mes/fechar: ' . $e->getMessage());
            $_SESSION['fe_erro'] = ['mes' => $mes, 'msg' => t('Não foi possível fechar. Nada foi gravado. Tente de novo.'), 'saldo' => $saldoBruto];
            redirecionar($volta, 303);
        }
        MotorFinanceiro::limparCache();

        // diz o que mudou no mês seguinte; reabrir este mês desfaz esse ajuste junto
        $aviso = t('{mes} fechado.', ['mes' => mesAno($mes)]);
        if ($lote !== null) {
            $aviso .= ' · ' . tn($mudou, 'Em {prox}, 1 categoria passou a usar o valor sugerido.', 'Em {prox}, {n} categorias passaram a usar os valores sugeridos.', ['prox' => mesAno((string) $prox)])
                . ' · ' . t('Se reabrir este mês, esse ajuste também é desfeito.');
        } elseif ($prox !== null) {
            $aviso .= ' · ' . t('Os valores previstos de {prox} não mudaram.', ['prox' => mesAno($prox)]);
        }
        // o saldo do banco não bateu (de um centavo em diante): o Início abre o Conferir, com o botão "Acertar o saldo"
        $conf = ($saldo !== null || $porConta !== []) ? DashboardController::conferenciaAtual($hoje) : null;
        $acertos = $conf !== null && $conf['data'] === $dataSaldo ? ($conf['acertos'] ?? []) : [];
        if ($acertos !== []) {
            $aviso .= ' · ' . t('O banco mostra uma diferença de {valor}. Para acertar, veja Conferir com o banco.', ['valor' => moneySigned(round(array_sum(array_column($acertos, 'valor')), 2))]);
        }
        flash('success', $aviso,
            ['url' => '/mes/reabrir', 'label' => t('reabrir'), 'campos' => ['mes' => $mes]]);
        redirecionar($acertos !== [] ? '/?conferir=1#conferir' : '/', 303);
    }

    /* ================================================================== POST /mes/reabrir */

    public function reabrir(): void
    {
        Auth::requireLogin();
        csrfCheck();

        $mes = self::mesPost();
        if ($mes === null) {
            flash('error', t('Não encontramos esse mês. Volte e tente de novo.'));
            redirecionar('/mes', 303);
        }
        $volta = voltaSegura(is_string($_POST['volta'] ?? null) ? $_POST['volta'] : null, '/mes?mes=' . $mes);
        if (self::situacaoEm(MesModel::todos(), $mes) === 'REAL') {
            // reabre e descongela (as linhas que o fechamento gravou a partir da meta saem, se
            // ninguém mexeu nelas): o mês volta a seguir as metas de hoje
            $pdo = Database::connection();
            try {
                $pdo->beginTransaction();
                MesModel::setSituacao($mes, 'PREVISAO');
                PrevisaoLote::descongelar($mes, (int) Auth::id());
                $ajuste = PrevisaoLote::desfazerAjusteDoFechamento($mes, (int) Auth::id());
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('mes/reabrir: ' . $e->getMessage());
                flash('error', t('Não foi possível reabrir. Tente de novo.'));
                redirecionar($volta, 303);
            }
            MotorFinanceiro::limparCache();
            $aviso = t('{mes} reaberto.', ['mes' => mesAno($mes)]);
            if ($ajuste !== null) {
                $aviso .= ' · ' . ($ajuste['restauradas'] === $ajuste['total']
                    ? t('O ajuste feito em {prox} ao fechar foi desfeito.', ['prox' => mesAno($ajuste['mes'])])
                    : t('O ajuste feito em {prox} ao fechar foi desfeito, menos nos valores que você mudou depois.', ['prox' => mesAno($ajuste['mes'])]));
            }
            flash('success', $aviso);
        } else {
            flash('warn', t('{mes} já está aberto.', ['mes' => mesAno($mes)]));
        }
        redirecionar($volta, 303);
    }

    /* ================================================================== internos */

    /**
     * A parcela do empréstimo descontada do salário num mês: o salário lançado (real) é o líquido, e a previsão do
     * Salário é o bruto. Só a partir de ajustes.consignado_inicio (mesma regra do motor). 0 quando é paga à parte.
     */
    private static function parcelaDescontada(array $plano, array $d, string $mes): float
    {
        $chaves = array_keys($plano);
        $pos = array_search($mes, $chaves, true);
        $loanIni = $pos > 0 ? (float) $plano[$chaves[$pos - 1]]['falta_pagar'] : (float) ($d['loanIni'] ?? 0.0);
        $iniParcela = MotorFinanceiro::mesInicioParcela($d['aj']);
        $aParte = (int) round((float) ($d['aj']['parcela_modo'] ?? 1.0)) === 2;
        return (!$aParte && $loanIni > 0.01 && ($iniParcela === null || $mes >= $iniParcela))
            ? round(min((float) ($d['aj']['parcela_mensal'] ?? 0.0), $loanIni + (float) $plano[$mes]['juros']), 2)
            : 0.0;
    }

    /**
     * Quando o mês foi fechado e se o saldo do banco foi conferido dentro dele (o Fechar grava a conferência).
     * @return array{data:?string, conferido:bool}
     */
    private static function fechamento(string $mes): array
    {
        $pdo = Database::connection();
        $st = $pdo->prepare('SELECT fechado_em FROM meses WHERE mes = ?');
        $st->execute([$mes]);
        $quando = $st->fetchColumn();
        $st = $pdo->prepare('SELECT 1 FROM saldo_conferencias WHERE data >= ? AND data <= ? LIMIT 1');
        $st->execute([$mes, date('Y-m-t', strtotime($mes))]);
        return ['data' => is_string($quando) && $quando !== '' ? substr($quando, 0, 10) : null, 'conferido' => (bool) $st->fetchColumn()];
    }

    /** `1.200,00` · `159,90`: dinheiro sempre com centavos (docs/decisoes.md). */
    private static function valorCurto(float $v): string
    {
        return num($v);
    }

    private static function situacaoEm(array $meses, string $mes): string
    {
        foreach ($meses as $m) {
            if ($m['mes'] === $mes) {
                return (string) $m['situacao'];
            }
        }
        return 'PREVISAO';
    }

    /** `mes` do POST, só se for um mês cadastrado. */
    private static function mesPost(): ?string
    {
        $mes = $_POST['mes'] ?? null;
        if (!is_string($mes) || !preg_match('/^\d{4}-\d{2}-01$/', $mes)) {
            return null;
        }
        return in_array($mes, array_column(MesModel::todos(), 'mes'), true) ? $mes : null;
    }

    /**
     * Rótulo curto da referência, pelo nome dos meses: `set` (1 mês) ou `set–out` (média de 2+,
     * do primeiro ao último); null sem histórico.
     */
    public static function refRotulo(array $mesesRef): ?string
    {
        if ($mesesRef === []) {
            return null;
        }
        return count($mesesRef) === 1 ? mesCurto($mesesRef[0]) : mesCurto($mesesRef[0]) . '–' . mesCurto(end($mesesRef));
    }
}
