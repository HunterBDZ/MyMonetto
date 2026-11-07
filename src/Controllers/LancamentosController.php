<?php
namespace Financeiro\Controllers;

use Financeiro\Auth;
use Financeiro\Database;
use Financeiro\Models\AcertoModel;
use Financeiro\Models\AjusteModel;
use Financeiro\Models\CartaoModel;
use Financeiro\Models\CategoriaModel;
use Financeiro\Models\ContaModel;
use Financeiro\Models\TransferenciaModel;
use Financeiro\Models\LancamentoModel;
use Financeiro\Models\MesModel;
use Financeiro\Models\MetaModel;
use Financeiro\Models\PrevisaoModel;
use Financeiro\Models\UsuarioModel;
use Financeiro\Relogio;
use Financeiro\Services\ChecklistContas;
use Financeiro\Services\Investimentos;
use Financeiro\Services\LancarForm;
use Financeiro\Services\MotorFinanceiro;
use Financeiro\Services\Planilha;
use Financeiro\Sql;
use Financeiro\View;

/**
 * Lançar, extrato e editar.
 * Todo POST: requireLogin + csrfCheck; `volta` sempre por voltaSegura(); valor por parseMoedaBr()
 * (aceita -, − e –; grava abs()); data dentro de [primeiro dia de MesModel::primeiro(),
 * último dia de MesModel::ultimo()] (invariante 9) em store, update e desfazer. Mês fechado não muda: lançar, editar,
 * excluir, desfazer e pagar num mês fechado são recusados (erroMesFechado), também para mover um lançamento até ele.
 */
final class LancamentosController
{
    /** Filtros de grupo do extrato (`f=`), por classe da categoria. */
    private const FILTROS = ['entradas', 'essenciais', 'naoess', 'mov'];
    private const POR_PAGINA = 60;

    /* ================================================================== extrato */

    public function index(): void
    {
        Auth::requireLogin();

        $b = self::busca();
        $mes = $b['mes'];
        $meses = $b['meses'];
        $filtro = $b['filtro'];
        $limite = min(5000, max(self::POR_PAGINA, (int) (self::get('limite') ?? self::POR_PAGINA)));

        $linhas = LancamentoModel::listar($mes, $filtro + ['limite' => $limite]);
        $totais = LancamentoModel::totais($mes, $filtro);
        $filtrado = $b['filtrado'];
        [$transferencias, $saldoConta] = self::transferenciasDoExtrato($b, $linhas, (int) $totais['n']);
        $acertos = self::acertosDoExtrato($b, $linhas, (int) $totais['n']);

        // renda = a mesma palavra e o mesmo número do gráfico (entra do motor), nunca "entrou" (D2)
        $renda = null;
        if (!$filtrado) {
            $linhaPlano = MotorFinanceiro::linhaDoMes($mes);
            $renda = $linhaPlano !== null ? (float) $linhaPlano['entra'] : LancamentoModel::somaOperacional($mes, 1);
        }

        $situacao = 'PREVISAO';
        foreach ($meses as $m) {
            if ($m['mes'] === $mes) {
                $situacao = (string) $m['situacao'];
            }
        }

        $params = $b['params'];
        $urlAtual = '/lancamentos?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986)
            . ($limite > self::POR_PAGINA ? '&limite=' . $limite : '');

        $categorias = CategoriaModel::ativas();

        View::render('lancamentos/index', [
            'pageTitle'  => $b['modo'] === '' ? t('extrato · {mes} · MyMonetto', ['mes' => monthLabel($mes)]) : t('extrato · {periodo} · MyMonetto', ['periodo' => $b['rotulo']]),
            'mes'        => $mes,
            'meses'      => $meses,
            'situacao'   => $situacao,
            'estado'     => estadoMes($mes, $situacao),
            'hoje'       => Relogio::hoje(),
            'usuarios'   => $b['usuarios'],
            'f'          => $b['f'],
            'quem'       => $b['quem'],
            'categoria'  => $b['categoria'],
            'categorias' => $categorias,
            'q'          => $b['q'],
            'ordem'      => $b['ordem'],
            'modo'       => $b['modo'],
            'periodo'    => $b['periodo'],
            'rotuloPeriodo' => $b['rotulo'],
            'ondePeriodo' => $b['onde'],
            'valores'    => $b['valores'],
            'erroValor'  => $b['erroValor'],
            'params'     => $params,
            'limite'     => $limite,
            'linhas'     => $linhas,
            'totais'     => $totais,
            'filtrado'   => $filtrado,
            'renda'      => $renda,
            'urlAtual'   => $urlAtual,
            'urlPlanilha' => '/lancamentos/planilha?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986),
            'contas'     => $b['contas'],
            'conta'      => $b['conta'],
            'transferencias' => $transferencias,
            'acertos'    => $acertos,
            'somaAcertos' => self::somaAcertos($b),
            'abertura'   => self::abertura($b),
            'saldoConta' => $saldoConta,
            'nomesCartao' => count(CartaoModel::ativos()) > 1 ? array_column(CartaoModel::ativos(), 'nome', 'id') : [],
            'form'       => LancarForm::dados() + ['modo' => 'pagina', 'pref' => 'pg', 'volta' => $urlAtual, 'topo' => false],
            // "Contas do mês" com o botão Pago: só no mês de hoje, sem busca nem filtro
            'contasMes'  => $mes === Relogio::mes() && !$filtrado ? ChecklistContas::fixasAPagar() : [],
        ], 'extrato');
    }

    /**
     * As transferências que entram na lista do extrato, e o saldo da conta filtrada. Só com mais de uma conta (com uma,
     * não há transferência) e sem filtro de categoria, que elas não têm. Ficam no mesmo período das linhas mostradas:
     * se a lista vai ser paginada, a partir do dia da última linha à vista.
     * @return array{0:list<array>, 1:?array{conta:array, data:string, valor:float}}
     */
    private static function transferenciasDoExtrato(array $b, array $linhas, int $total): array
    {
        if (count($b['contas']) < 2) {
            return [[], null];
        }
        $mes = $b['mes'];
        $de = $b['periodo'] !== null ? $b['periodo']['de'] : $mes;
        $ate = $b['periodo'] !== null ? $b['periodo']['ate'] : date('Y-m-t', strtotime($mes));
        $hoje = Relogio::hoje();
        $saldo = null;
        if ($b['conta'] !== null) {
            $quando = $ate === null || $ate > $hoje ? $hoje : $ate;
            $saldo = ['conta' => $b['conta'], 'data' => $quando, 'valor' => ContaModel::saldo($b['conta']['id'], $quando)];
        }
        if ($b['filtro']['categoria_id'] !== null || $b['filtro']['classe'] !== null || $b['quem'] > 0) {
            return [[], $saldo];
        }
        if ($linhas !== [] && count($linhas) < $total && in_array($b['ordem'], ['', 'dia-asc'], true)) {
            $ultima = (string) $linhas[count($linhas) - 1]['data'];
            if ($b['ordem'] === '') {
                $de = max((string) $de, $ultima);
            } else {
                $ate = min((string) ($ate ?? $ultima), $ultima);
            }
        }
        $lista = TransferenciaModel::listar($de, $ate, $b['conta']['id'] ?? null, $b['q']);
        $min = $b['filtro']['min'] ?? null;
        $max = $b['filtro']['max'] ?? null;
        $lista = array_values(array_filter($lista, static fn(array $t): bool => ($min === null || (float) $t['valor'] >= $min) && ($max === null || (float) $t['valor'] <= $max)));
        return [$lista, $saldo];
    }

    /**
     * Os acertos de saldo que entram na lista do extrato, como as transferências: sem filtro de categoria, de grupo nem
     * de pessoa (eles não têm), na conta filtrada, no período das linhas à vista.
     * @return list<array>
     */
    private static function acertosDoExtrato(array $b, array $linhas, int $total): array
    {
        if ($b['filtro']['categoria_id'] !== null || $b['filtro']['classe'] !== null || $b['quem'] > 0) {
            return [];
        }
        [$de, $ate] = self::periodoDoExtrato($b);
        if ($linhas !== [] && count($linhas) < $total && in_array($b['ordem'], ['', 'dia-asc'], true)) {
            $ultima = (string) $linhas[count($linhas) - 1]['data'];
            if ($b['ordem'] === '') {
                $de = max((string) $de, $ultima);
            } else {
                $ate = min((string) ($ate ?? $ultima), $ultima);
            }
        }
        $min = $b['filtro']['min'] ?? null;
        $max = $b['filtro']['max'] ?? null;
        return array_values(array_filter(AcertoModel::listar($de, $ate, $b['conta']['id'] ?? null, $b['q']),
            static fn(array $a): bool => ($min === null || abs((float) $a['valor']) >= $min) && ($max === null || abs((float) $a['valor']) <= $max)));
    }

    /** A soma dos acertos do período inteiro (para o resumo, mesmo com a lista paginada), com os mesmos filtros. */
    private static function somaAcertos(array $b): float
    {
        $b2 = $b;
        $lista = self::acertosDoExtrato($b2, [], 0);
        return round(array_sum(array_map(static fn(array $a): float => (float) $a['valor'], $lista)), 2);
    }

    /** O período do extrato: [de, ate], datas inclusivas, null = sem limite. */
    private static function periodoDoExtrato(array $b): array
    {
        return $b['periodo'] !== null ? [$b['periodo']['de'], $b['periodo']['ate']] : [$b['mes'], date('Y-m-t', strtotime($b['mes']))];
    }

    /**
     * O saldo com que o mês começou ("Saldo que veio de outubro"), para a linha do fim do extrato e o resumo. Só no
     * modo do mês, sem busca nem filtro além da conta, e na ordem por dia. Mês que já começou: o saldo de fato no fim
     * da véspera (o mesmo do Início e do Mês fechado). Mês que ainda não começou: o que o plano prevê, só para o total.
     * @return ?array{valor:float, previsto:bool, mes_anterior:string, primeiro:bool}
     */
    private static function abertura(array $b): ?array
    {
        $f = $b['filtro'];
        if ($b['modo'] !== '' || $f['categoria_id'] !== null || $f['classe'] !== null || $b['quem'] > 0 || $b['q'] !== ''
            || isset($f['min']) || isset($f['max']) || !in_array($b['ordem'], ['', 'dia-asc'], true)) {
            return null;
        }
        $mes = $b['mes'];
        $vespera = date('Y-m-d', strtotime($mes . ' -1 day'));
        $primeiro = MesModel::primeiro();
        $base = ['mes_anterior' => date('Y-m-01', strtotime($vespera)), 'primeiro' => $primeiro !== null && $mes <= (string) $primeiro];
        if ($mes <= Relogio::hoje()) {
            $valor = $b['conta'] !== null
                ? ContaModel::saldo($b['conta']['id'], $vespera)
                : LancamentoModel::saldoCalculado(AjusteModel::valor('saldo_ini'), $vespera);
            return ['valor' => round($valor, 2), 'previsto' => false] + $base;
        }
        if ($b['conta'] !== null) {
            return null;
        }
        $linha = MotorFinanceiro::linhaDoMes($mes);
        return $linha !== null ? ['valor' => round((float) $linha['sobrou_mes_anterior'], 2), 'previsto' => true] + $base : null;
    }

    /**
     * GET /lancamentos/planilha — o resultado filtrado do extrato em CSV (Services\Planilha), com os mesmos
     * parâmetros da tela. Passou de Planilha::LIMITE linhas, volta com um aviso para escolher um período menor.
     */
    public function planilha(): void
    {
        Auth::requireLogin();

        $b = self::busca();
        $n = LancamentoModel::totais($b['mes'], $b['filtro'])['n'];
        $volta = '/lancamentos?' . http_build_query($b['params'], '', '&', PHP_QUERY_RFC3986);
        if ($n > Planilha::LIMITE) {
            flash('warn', t('São {n} lançamentos, e a planilha leva até {limite}. Escolha um período menor.', ['n' => num($n, 0), 'limite' => num(Planilha::LIMITE, 0)]));
            redirecionar($volta, 303);
        }
        $p = $b['periodo'];
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . Planilha::nomeArquivo($b['modo'], $b['mes'], $p['de'] ?? null, $p['ate'] ?? null) . '"');
        header('Cache-Control: private, no-store');
        $saida = fopen('php://output', 'wb');
        Planilha::escrever(LancamentoModel::emLotes($b['mes'], $b['filtro']), $saida);
        fclose($saida);
    }

    /**
     * Os filtros do extrato, lidos da URL, os mesmos para a tela e para a planilha:
     * mes; periodo ('' o mês, ano, tudo, datas com de/ate); categoria (cat ou f); quem; q; min e max (valor); ordem.
     * @return array{mes:string, meses:array, modo:string, periodo:?array{de:?string,ate:?string}, rotulo:string, onde:string,
     *   usuarios:array, f:string, quem:int, categoria:?array, q:string, ordem:string,
     *   valores:array{min:string,max:string}, erroValor:?string, filtro:array, filtrado:bool, params:array}
     */
    public static function busca(): array
    {
        $meses  = MesModel::todos();
        $mesCal = Relogio::mes();
        $padrao = mesValido($mesCal, $meses, MesModel::ultimo() ?? $mesCal);
        $mes    = mesValido(self::get('mes'), $meses, $padrao);

        $usuarios = UsuarioModel::todos();
        $idsUsuarios = array_map('intval', array_column($usuarios, 'id'));

        $f    = self::get('f') ?? '';
        $f    = in_array($f, self::FILTROS, true) ? $f : '';
        $quem = (int) (self::get('quem') ?? 0);
        $quem = in_array($quem, $idsUsuarios, true) ? $quem : 0;
        $cat  = (int) (self::get('cat') ?? self::get('categoria') ?? 0);
        $categoria = $cat > 0 ? CategoriaModel::find($cat) : null;
        $cat  = $categoria ? $cat : 0;
        $q    = mb_substr(trim(self::get('q') ?? self::get('busca') ?? ''), 0, 80);
        // a conta (com mais de uma): as arquivadas também, para ver o que houve nelas
        $contas = count(ContaModel::todas()) > 1 ? ContaModel::todas() : [];
        $conta = null;
        foreach ($contas as $c) {
            if ((int) (self::get('conta') ?? 0) === $c['id']) {
                $conta = $c;
            }
        }
        $ordem = self::get('ordem') ?? '';
        $ordem = in_array($ordem, ['dia-desc', 'dia-asc', 'valor-desc', 'valor-asc', 'alfa'], true) ? $ordem : '';

        // período: o mês da tela (padrão), o ano dele, todos os meses ou entre duas datas
        $modo = self::get('periodo') ?? '';
        $modo = in_array($modo, ['ano', 'tudo', 'datas'], true) ? $modo : '';
        $dataOk = static fn(?string $d): ?string => $d !== null && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $d, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $d : null;
        $de = $dataOk(self::get('de'));
        $ate = $dataOk(self::get('ate'));
        if ($de !== null && $ate !== null && $de > $ate) {
            [$de, $ate] = [$ate, $de];
        }
        $periodo = match ($modo) {
            'ano'   => ['de' => substr($mes, 0, 4) . '-01-01', 'ate' => substr($mes, 0, 4) . '-12-31'],
            'tudo'  => ['de' => null, 'ate' => null],
            'datas' => ['de' => $de, 'ate' => $ate],
            default => null,
        };
        $rotulo = match ($modo) {
            'ano'   => substr($mes, 0, 4),
            'tudo'  => t('Todos os meses'),
            'datas' => $de !== null && $ate !== null ? t('De {de} a {ate}', ['de' => diaMesAno($de), 'ate' => diaMesAno($ate)])
                : ($de !== null ? t('Desde {de}', ['de' => diaMesAno($de)]) : ($ate !== null ? t('Até {ate}', ['ate' => diaMesAno($ate)]) : t('Todos os meses'))),
            default => mb_strtoupper(mb_substr(mesAno($mes), 0, 1)) . mb_substr(mesAno($mes), 1),
        };
        // o mesmo período no meio da frase ("Nada encontrado com esses filtros em novembro de 2026.")
        $onde = match ($modo) {
            'ano'   => t('em {ano}', ['ano' => substr($mes, 0, 4)]),
            'tudo'  => t('em todos os meses'),
            'datas' => $de !== null && $ate !== null ? t('de {de} a {ate}', ['de' => diaMesAno($de), 'ate' => diaMesAno($ate)])
                : ($de !== null ? t('desde {de}', ['de' => diaMesAno($de)]) : ($ate !== null ? t('até {ate}', ['ate' => diaMesAno($ate)]) : t('em todos os meses'))),
            default => t('em {mes}', ['mes' => mesAno($mes)]),
        };

        // valor mínimo e máximo, sem sinal; o que não dá para ler é avisado e fica de fora
        $valores = ['min' => mb_substr(trim(self::get('min') ?? ''), 0, 20), 'max' => mb_substr(trim(self::get('max') ?? ''), 0, 20)];
        $limites = [];
        $erroValor = null;
        foreach ($valores as $k => $txt) {
            if ($txt === '') {
                continue;
            }
            $v = parseMoedaBr($txt);
            if ($v === null || $v < 0) {                    // valor negativo também: o filtro é sem sinal
                $erroValor = t('Digite o valor como 45 ou 45,90.');
                $valores[$k] = '';
                continue;
            }
            $limites[$k] = round(abs($v), 2);
        }
        if (isset($limites['min'], $limites['max']) && $limites['min'] > $limites['max']) {
            [$limites['min'], $limites['max']] = [$limites['max'], $limites['min']];
            [$valores['min'], $valores['max']] = [$valores['max'], $valores['min']];
        }

        $filtro = [
            'classe'       => $f ?: null,
            'usuario_id'   => $quem ?: null,
            'categoria_id' => $cat ?: null,
            'conta_id'     => $conta['id'] ?? null,
            'q'            => $q,
            'ordem'        => $ordem,
        ] + ($periodo !== null ? ['periodo' => $periodo] : []) + $limites;

        $params = array_filter([
            'mes' => $mes, 'periodo' => $modo, 'de' => $modo === 'datas' ? (string) $de : '', 'ate' => $modo === 'datas' ? (string) $ate : '',
            'f' => $f, 'quem' => $quem ?: '', 'cat' => $cat ?: '', 'conta' => $conta['id'] ?? '', 'q' => $q,
            'min' => isset($limites['min']) ? $valores['min'] : '', 'max' => isset($limites['max']) ? $valores['max'] : '', 'ordem' => $ordem,
        ], static fn($v) => $v !== '');

        return [
            'mes' => $mes, 'meses' => $meses, 'modo' => $modo, 'periodo' => $periodo, 'rotulo' => $rotulo, 'onde' => $onde,
            'usuarios' => $usuarios, 'f' => $f, 'quem' => $quem, 'categoria' => $categoria, 'q' => $q, 'ordem' => $ordem,
            'valores' => $valores, 'erroValor' => $erroValor, 'filtro' => $filtro, 'contas' => $contas, 'conta' => $conta,
            'filtrado' => $f !== '' || $quem > 0 || $cat > 0 || $conta !== null || $q !== '' || $modo !== '' || $limites !== [],
            'params' => $params,
        ];
    }

    /* ================================================================== lançar (página) */

    /** GET /lancar — fallback sem JS do diálogo, e a volta dos erros/duplicados. */
    public function lancarForm(): void
    {
        Auth::requireLogin();

        $volta  = voltaSegura(self::get('volta'), '/');
        $antigo = self::tirarAntigo(null);
        if ($antigo === null) {
            // PAGAR sem JS: /lancar?categoria=9&valor=340,00&descricao=Faxineira&volta=/
            $pre = array_filter([
                'pagou'        => preg_match('/^cartao(:\d+)?$/D', (string) self::get('pagou')) ? self::get('pagou') : null,
                'categoria_id' => (int) (self::get('categoria') ?? 0) ?: null,
                'valor'        => self::get('valor'),
                'descricao'    => self::get('descricao'),
            ], static fn($v) => $v !== null && $v !== '');
            // PAGAR de um mês que ainda não começou: &data=AAAA-MM-DD → "outro dia" (cai no mês da conta)
            $dataPre = (string) (self::get('data') ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dataPre) && self::validarData($dataPre) === null) {
                $pre['quando'] = 'outro';
                $pre['data'] = $dataPre;
            }
            $antigo = $pre !== [] ? $pre : null;
        }

        View::render('lancamentos/lancar', [
            'pageTitle'        => t('lançar · MyMonetto'),
            'semDialogoLancar' => true,
            'form'             => LancarForm::dados() + ['modo' => 'pagina', 'pref' => 'pg', 'volta' => $volta, 'antigo' => $antigo],
        ], '');
    }

    public function store(): void
    {
        Auth::requireLogin();
        csrfCheck();

        $volta = voltaSegura(self::post('volta'), '');
        if (self::post('modo') === 'transferir' && ContaModel::varias()) {
            self::transferir($volta);
        }
        if (preg_match('/^cartao(?::(\d+))?$/D', (string) self::post('pagou'), $mc)) {
            // "Pagou com: Cartão": vira uma compra no cartão (não é lançamento da conta; no plano só a fatura conta)
            $r = CartaoController::criarCompraDe($_POST, null, isset($mc[1]) ? (int) $mc[1] : null);
            if ($r['erro'] !== null) {
                $_SESSION['lanc_antigo'] = self::antigoDe($_POST) + ['erro' => $r['erro']];
                redirecionar('/lancar?volta=' . rawurlencode($volta !== '' ? $volta : '/cartao'), 303);
            }
            // de volta à aba Cartão: no cartão da compra
            $destino = $volta !== '' && !preg_match('#^/cartao(?:[?\#]|$)#', $volta) ? $volta : CartaoController::url($r['cartao']);
            flash('success', $r['aviso'], ['url' => '/cartao/compras/' . $r['id'] . '/excluir', 'label' => t('desfazer'),
                                           'campos' => ['volta' => $destino]]);
            redirecionar($destino);
        }
        [$erro, $d] = self::validar($_POST);
        if ($erro !== null) {
            $_SESSION['lanc_antigo'] = self::antigoDe($_POST) + ['erro' => $erro];
            redirecionar('/lancar?volta=' . rawurlencode($volta !== '' ? $volta : '/'), 303);
        }

        if (self::post('confirmar_dup') !== '1' && ($dup = LancamentoModel::existeIgual($d['data'], $d['categoria_id'], $d['valor'])) !== null) {
            $_SESSION['lanc_antigo'] = self::antigoDe($_POST) + ['duplicado' => [
                'descricao' => (string) $dup['descricao'],
                'quem'      => (string) $dup['usuario_nome'],
                'data'      => (string) $dup['data'],
            ]];
            redirecionar('/lancar?volta=' . rawurlencode($volta !== '' ? $volta : '/'), 303);
        }

        MesModel::ensureExists(substr($d['data'], 0, 8) . '01');   // dentro de [primeiro, último]: nunca cria mês novo
        $id = LancamentoModel::criar($d['data'], $d['descricao'], $d['categoria_id'], $d['valor'], (int) Auth::id(), $d['conta_id']);
        MotorFinanceiro::limparCache();                          // o aviso conta com o que acabou de entrar

        $destino = $volta !== '' ? $volta : '/lancamentos?mes=' . substr($d['data'], 0, 8) . '01';
        flash('success', self::avisoLancado($d['cat'], $d['valor'], $d['data'], $destino), [
            'url'    => '/lancamentos/' . $id . '/excluir',
            'label'  => t('desfazer'),
            'campos' => ['volta' => $destino],
        ]);
        redirecionar($destino);
    }

    /** O modo Transferir do lançar: valida, grava e volta com o aviso e o desfazer. */
    private static function transferir(string $volta): never
    {
        $r = TransferenciaController::criarDe($_POST);
        if ($r['erro'] !== null || isset($r['negativo'])) {
            // erro, ou a conta ficaria negativa: volta ao lançar preenchido, com o erro ou a pergunta
            $_SESSION['lanc_antigo'] = self::antigoDe($_POST) + ($r['erro'] !== null ? ['erro' => $r['erro']] : ['negativo' => $r['negativo']]);
            redirecionar('/lancar?volta=' . rawurlencode($volta !== '' ? $volta : '/'), 303);
        }
        $destino = $volta !== '' ? $volta : '/lancamentos?mes=' . substr($r['data'], 0, 8) . '01';
        flash('success', $r['aviso'], ['url' => '/transferencias/' . $r['id'] . '/excluir', 'label' => t('desfazer'), 'campos' => ['volta' => $destino]]);
        redirecionar($destino);
    }

    /**
     * POST /lancamentos/pago — o botão Pago das "Contas do mês" (Início e Extrato): lança a conta fixa com o valor
     * previsto, na data de hoje, sem formulário. O aviso tem o desfazer do Lançar (excluir o lançamento).
     */
    public function pago(): void
    {
        Auth::requireLogin();
        csrfCheck();

        $volta = voltaSegura(self::post('volta'), '/');
        $r = self::pagarConta((int) (self::post('categoria_id') ?? 0), (int) Auth::id());
        if ($r['erro'] !== null) {
            flash('warn', $r['erro']);
            redirecionar($volta);
        }
        flash('success', t('{texto} · Pago hoje.', ['texto' => self::valorTexto($r['cat'], $r['valor'], true)]), [
            'url'    => '/lancamentos/' . $r['id'] . '/excluir',
            'label'  => t('desfazer'),
            'campos' => ['volta' => $volta],
        ]);
        redirecionar($volta);
    }

    /**
     * Paga uma conta fixa do mês de hoje: só se ela está em ChecklistContas::fixasAPagar (Fixos, ativa, previsão
     * maior que zero e nada lançado no mês), conferido dentro da transação, para o segundo clique não lançar de novo.
     * @return array{erro:?string, id:int, cat:array, valor:float}
     */
    public static function pagarConta(int $categoriaId, int $usuarioId, ?string $hoje = null): array
    {
        $hoje ??= Relogio::hoje();
        $nada = ['id' => 0, 'cat' => [], 'valor' => 0.0];
        $cat = $categoriaId > 0 ? CategoriaModel::find($categoriaId) : null;
        if ($cat === null || $cat['grupo'] !== 'Fixos' || (int) $cat['ativo'] !== 1) {
            return ['erro' => t('Essa conta não pode ser paga por aqui. Use o Lançar.')] + $nada;
        }
        $pdo = Database::connection();
        $abriu = !$pdo->inTransaction();
        if ($abriu) {
            $pdo->beginTransaction();
        }
        try {
            // trava a categoria (no SQLite, a transação já tem a vez de escrever): dois cliques entram um de cada vez
            $pdo->prepare('SELECT id FROM categorias WHERE id = :id' . Sql::travandoLinhas())->execute(['id' => $categoriaId]);
            MotorFinanceiro::limparCache();
            $item = null;
            foreach (ChecklistContas::fixasAPagar($hoje) as $c) {
                if ($c['categoria_id'] === $categoriaId) {
                    $item = $c;
                }
            }
            $erro = $item === null ? t('A conta {nome} já foi paga ou não tem valor previsto neste mês.', ['nome' => nomeCurto($cat)]) : (self::validarData($hoje) ?? self::erroMesFechado($hoje));
            if ($erro !== null) {
                if ($abriu) {
                    $pdo->rollBack();
                }
                return ['erro' => $erro] + $nada;
            }
            // na conta padrão, como todo lançamento que não diz a conta
            $id = LancamentoModel::criar($hoje, (string) $cat['nome'], $categoriaId, (float) $item['previsto'], $usuarioId, ContaModel::padrao());
            if ($abriu) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($abriu && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            MotorFinanceiro::limparCache();
        }
        return ['erro' => null, 'id' => $id, 'cat' => $cat, 'valor' => (float) $item['previsto']];
    }

    /* ================================================================== editar */

    public function editForm(string $id): void
    {
        Auth::requireLogin();

        $l = LancamentoModel::find((int) $id);
        if (!$l) {
            renderErro(404, t('Lançamento não encontrado'), t('Pode ter sido excluído.'));
        }
        $volta = voltaSegura(self::get('volta'), '/lancamentos?mes=' . substr($l['data'], 0, 8) . '01');
        $fechado = self::erroMesFechado((string) $l['data']);

        View::render('lancamentos/editar', [
            'pageTitle'  => t('editar · MyMonetto'),
            'lancamento' => $l,
            'volta'      => $volta,
            'fechado'    => $fechado,
            'form'       => LancarForm::dados() + [
                'fechado'    => $fechado,
                'modo'       => 'editar',
                'pref'       => 'ed',
                'volta'      => $volta,
                'lancamento' => $l,
                'antigo'     => self::tirarAntigo((int) $l['id']),
            ],
        ], 'extrato');
    }

    public function update(string $id): void
    {
        Auth::requireLogin();
        csrfCheck();

        $l = LancamentoModel::find((int) $id);
        if (!$l) {
            renderErro(404, t('Lançamento não encontrado'), t('Pode ter sido excluído.'));
        }
        $volta = voltaSegura(self::post('volta'), '/lancamentos?mes=' . substr($l['data'], 0, 8) . '01');

        [$erro, $d] = self::validar($_POST, (int) $l['id']);
        $erro = self::erroMesFechado((string) $l['data']) ?? $erro;     // nem sai de um mês fechado
        if ($erro !== null) {
            $_SESSION['lanc_antigo'] = self::antigoDe($_POST) + ['erro' => $erro, 'id' => (int) $l['id']];
            redirecionar('/lancamentos/' . (int) $l['id'] . '/editar?volta=' . rawurlencode($volta), 303);
        }

        MesModel::ensureExists(substr($d['data'], 0, 8) . '01');
        LancamentoModel::atualizar((int) $l['id'], $d['data'], $d['descricao'], $d['categoria_id'], $d['valor'], $d['conta_id']);

        flash('success', t('{texto} · salvo', ['texto' => self::valorTexto($d['cat'], $d['valor'], true)]));
        redirecionar($volta);
    }

    public function destroy(string $id): void
    {
        Auth::requireLogin();
        csrfCheck();

        $l = LancamentoModel::find((int) $id);
        $mesL = $l ? substr($l['data'], 0, 8) . '01' : Relogio::mes();
        $volta = voltaSegura(self::post('volta'), '/lancamentos?mes=' . $mesL);
        if (preg_match('#^/lancamentos/' . (int) $id . '/editar(?:[?\#]|$)#', $volta)) {
            $volta = '/lancamentos?mes=' . $mesL;       // a página de edição dele deixou de existir
        }
        if (!$l) {
            flash('warn', t('Esse lançamento já tinha sido excluído.'));
            redirecionar($volta);
        }
        if (($fechado = self::erroMesFechado((string) $l['data']) ?? Investimentos::erroCascata([(int) $l['id']])) !== null) {
            flash('error', $fechado);                    // mês fechado, ou um resgate de depois passaria do saldo guardado
            redirecionar($volta);
        }

        LancamentoModel::excluir((int) $l['id']);
        $token = bin2hex(random_bytes(12));
        $_SESSION['lanc_desfazer'] = [
            'token' => $token,
            'linha' => [
                'id' => (int) $l['id'], 'data' => $l['data'], 'descricao' => $l['descricao'],
                'categoria_id' => (int) $l['categoria_id'], 'valor' => (float) $l['valor'],
                'usuario_id' => (int) $l['usuario_id'], 'criado_em' => $l['criado_em'],
                'conta_id' => (int) $l['conta_id'], 'cartao_id' => $l['cartao_id'] !== null ? (int) $l['cartao_id'] : null,
                'fatura' => $l['fatura'],
            ],
        ];

        flash('success', t('excluído · {texto}', ['texto' => self::valorTexto($l, (float) $l['valor'], true)]), [
            'url'    => '/lancamentos/desfazer',
            'label'  => t('desfazer'),
            'campos' => ['volta' => $volta, 'token' => $token],
        ]);
        redirecionar($volta);
    }

    /** Desfaz a última exclusão desta sessão (o token vem no form do aviso). */
    public function desfazer(): void
    {
        Auth::requireLogin();
        csrfCheck();

        $volta = voltaSegura(self::post('volta'), '/lancamentos');
        $d = $_SESSION['lanc_desfazer'] ?? null;
        $token = self::post('token') ?? '';
        if (!is_array($d) || !is_string($d['token'] ?? null) || !hash_equals($d['token'], $token)) {
            flash('warn', t('Não há nada para desfazer.'));
            redirecionar($volta);
        }
        unset($_SESSION['lanc_desfazer']);

        $linha = $d['linha'];
        $cat = CategoriaModel::find((int) $linha['categoria_id']);
        $erroData = self::validarData((string) $linha['data']) ?? self::erroMesFechado((string) $linha['data'])
            ?? Investimentos::erroCascata([], [$linha]);
        if ($cat === null || $erroData !== null) {
            flash('error', $erroData ?? t('Escolha uma categoria.'));
            redirecionar($volta);
        }

        MesModel::ensureExists(substr($linha['data'], 0, 8) . '01');
        LancamentoModel::restaurar($linha);
        flash('success', t('restaurado · {texto}', ['texto' => self::valorTexto($cat, (float) $linha['valor'], true)]));
        redirecionar($volta);
    }

    /* ================================================================== validação */

    /**
     * Data de lançamento aceitável? null = sim; senão a mensagem (`data?`, `data antes de set/26`,
     * `data depois de dez/27`). Limites: primeiro dia de MesModel::primeiro() e último dia de
     * MesModel::ultimo() — assim MesModel::ensureExists() nunca cria mês novo (invariantes 9 e 17).
     */
    public static function validarData(string $data): ?string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $data, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return t('Escolha uma data válida.');
        }
        $primeiro = MesModel::primeiroDia();
        $ultimo   = MesModel::ultimo();
        if ($primeiro === null || $ultimo === null) {
            return t('Escolha uma data válida.');
        }
        if ($data < $primeiro) {
            return t('A data é antes do início, {data}. Escolha outra.', ['data' => diaMes($primeiro)]);
        }
        if ($data > date('Y-m-t', strtotime($ultimo))) {
            return t('A data é depois de {mes}. Escolha outra.', ['mes' => mesAno($ultimo)]);
        }
        return null;
    }

    /**
     * Mês fechado não muda: null se o mês da data está aberto; senão a mensagem ("Outubro de 2026 está fechado. Para
     * mudar, reabra o mês no Mês."). Vale para lançar, editar (a data de antes e a nova), excluir, desfazer e pagar,
     * para as transferências e, no cartão, para o mês da fatura em que a compra cai.
     */
    public static function erroMesFechado(string $data): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $data) || MesModel::situacao(substr($data, 0, 8) . '01') !== 'REAL') {
            return null;
        }
        $mes = mesAno(substr($data, 0, 8) . '01');
        return t('{mes} está fechado. Para mudar, reabra o mês no Mês.', ['mes' => mb_strtoupper(mb_substr($mes, 0, 1)) . mb_substr($mes, 1)]);
    }

    /** `quando` (hoje|ontem|outro + data) → Y-m-d. Sem `quando`, usa `data` se vier (clientes antigos). */
    public static function dataDoQuando(array $in, ?string $hoje = null): string
    {
        $hoje ??= Relogio::hoje();
        $quando = is_string($in['quando'] ?? null) ? $in['quando'] : '';
        $data   = is_string($in['data'] ?? null) ? trim($in['data']) : '';
        return match ($quando) {
            'hoje'  => $hoje,
            'ontem' => date('Y-m-d', strtotime($hoje . ' -1 day')),
            'outro' => $data,
            default => $data !== '' ? $data : $hoje,
        };
    }

    /**
     * @return array{0:?string, 1:?array{data:string,descricao:string,categoria_id:int,valor:float,cat:array}}
     */
    private static function validar(array $in, ?int $editando = null): array
    {
        $valor = parseMoedaBr(is_string($in['valor'] ?? null) ? $in['valor'] : null);
        $valor = $valor === null ? null : round(abs($valor), 2);     // "-50" / "−50" gravam 50 (o sinal vem da categoria)
        if ($valor === null || $valor <= 0 || $valor > valorMaximo()) {
            return [motivoValor(is_string($in['valor'] ?? null) ? $in['valor'] : null) ?? t('Digite um valor.'), null];
        }

        $categoriaId = (int) (is_string($in['categoria_id'] ?? null) ? $in['categoria_id'] : 0);
        $cat = $categoriaId > 0 ? CategoriaModel::find($categoriaId) : null;
        if ($cat === null) {
            return [t('Escolha uma categoria.'), null];
        }

        $data = self::dataDoQuando($in);
        if (($erro = self::validarData($data) ?? self::erroMesFechado($data)) !== null) {
            return [$erro, null];
        }
        // resgatar mais do que estava guardado deixaria a poupança (ou os investimentos) negativa; e mudar um guardar ou
        // um resgate não pode deixar um resgate de depois maior que o saldo (efeito cascata)
        if (($erro = Investimentos::erroResgate($categoriaId, $valor, $data, $editando)
                ?? Investimentos::erroCascata($editando !== null ? [$editando] : [], [['categoria_id' => $categoriaId, 'valor' => $valor, 'data' => $data]])) !== null) {
            return [$erro, null];
        }

        $descricao = is_string($in['descricao'] ?? null) ? $in['descricao'] : '';
        $descricao = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $descricao));
        if (mb_strlen($descricao) > 255) {
            return [t('Descrição muito longa. Use até 255 letras.'), null];
        }
        if ($descricao === '') {
            $descricao = (string) $cat['nome'];
        }

        // a conta: conta:id do "Pagou com" (com várias); sem ela, a padrão no novo e a mesma no editar
        $contaId = null;
        if (preg_match('/^conta:(\d+)$/D', is_string($in['pagou'] ?? null) ? $in['pagou'] : '', $mc)) {
            $conta = ContaModel::achar((int) $mc[1]);
            if ($conta === null || !$conta['ativa']) {
                return [t('Escolha a conta.'), null];
            }
            $contaId = $conta['id'];
        }

        return [null, [
            'data' => $data, 'descricao' => $descricao, 'categoria_id' => $categoriaId,
            'valor' => $valor, 'cat' => $cat, 'conta_id' => $contaId,
        ]];
    }

    /* ================================================================== apoio */

    /** `Delivery −56,80` · `Salário +3.000,00` · `Guardar na poupança ⇄ 500,00` (sem o nome: só o valor). */
    private static function valorTexto(array $cat, float $valor, bool $comNome = false): string
    {
        $v = match (true) {
            (int) $cat['operacional'] !== 1 => '⇄ ' . num($valor),
            (int) $cat['sinal'] < 0         => MENOS . num($valor),
            default                         => '+' . num($valor),
        };
        return $comNome ? nomeCurto(['apelido' => $cat['apelido'] ?? null, 'nome' => $cat['nome'] ?? $cat['categoria_nome'] ?? '']) . ' ' . $v : $v;
    }

    /**
     * Aviso depois de lançar: `Delivery −56,80 · Ainda dá para gastar 343,20 da meta.`; categoria sem previsão no mês:
     * só `Salário +3.000,00`. O número é o da tela para onde a pessoa volta: nas Metas, o gasto como elas contam (com o
     * cartão, pela data da compra, e a frase diz "com o cartão" quando isso muda o número); nas outras (Início, Mês,
     * Extrato), o lançado na conta, como o Mês. Os dois são o realizado: o lançamento com data depois de hoje é
     * agendado, fica fora do gasto até o dia dele (MotorFinanceiro::realizado), e o aviso diz isso.
     */
    private static function avisoLancado(array $cat, float $valor, string $data, string $destino = ''): string
    {
        $txt = self::valorTexto($cat, $valor, true);
        if ($data > Relogio::hoje()) {
            $txt = t('{texto}, agendado para {data}', ['texto' => $txt, 'data' => diaMes($data)]);
        }
        $mes = substr($data, 0, 8) . '01';
        if ((int) $cat['operacional'] === 1 && (int) $cat['sinal'] < 0) {
            $previsto = PrevisaoModel::valor((int) $cat['id'], $mes);
            if ($previsto > 0) {
                $gasto = round((float) (MotorFinanceiro::realizado()['real'][$mes][(int) $cat['id']] ?? 0.0), 2);
                $cartao = false;
                if (preg_match('#^/meta(?:[?\#]|$)#', $destino)) {
                    $m = MetaController::gastosDoMes($mes)[(int) $cat['id']] ?? null;
                    $gasto = $m['gasto'] ?? $gasto;
                    $cartao = $m['cartao'] ?? false;
                }
                $resta = round($previsto - $gasto, 2);
                $nome = nomeCurto(['apelido' => $cat['apelido'] ?? null, 'nome' => $cat['nome'] ?? $cat['categoria_nome'] ?? '']);
                $vars = ['valor' => num(abs($resta)), 'nome' => $nome, 'texto' => $txt];
                if (MetaModel::valor((int) $cat['id']) !== null) {   // a previsão vem da meta; sem meta, é só uma estimativa
                    if ($cartao) {
                        return $resta < -0.004
                            ? t('{texto} · Passou {valor} da meta de {nome}, com o cartão.', $vars)
                            : t('{texto} · Ainda dá para gastar {valor} da meta, com o cartão.', $vars);
                    }
                    return $resta < -0.004
                        ? t('{texto} · Passou {valor} da meta de {nome}.', $vars)
                        : t('{texto} · Ainda dá para gastar {valor} da meta.', $vars);
                }
                return $resta < -0.004
                    ? t('{texto} · Passou {valor} do previsto para {nome}.', $vars)
                    : t('{texto} · Ainda restam {valor} do previsto.', $vars);
            }
        }
        return $txt;
    }

    /**
     * POST /lancamentos cujo token era de uma sessão que venceu e acabou de ser recriada
     * pelo cookie de lembrar (csrfCheck): nada é gravado; volta ao lançar preenchido.
     */
    public static function sessaoRenovada(): never
    {
        $volta = voltaSegura(self::post('volta'), '/');
        $_SESSION['lanc_antigo'] = self::antigoDe($_POST) + ['erro' => t('Sua sessão venceu. Confira e salve.')];
        redirecionar('/lancar?volta=' . rawurlencode($volta), 303);
    }

    /**
     * POST /lancamentos sem sessão (ela venceu, ou os cookies foram apagados): Auth::requireLogin manda para a tela de
     * entrar. O que foi digitado fica na sessão nova, e depois de entrar o Lançar volta preenchido, com o aviso.
     * Nada é gravado: o token do formulário era da sessão que acabou.
     */
    public static function guardarSemSessao(): void
    {
        $volta = voltaSegura(self::post('volta'), '/');
        $_SESSION['lanc_antigo'] = self::antigoDe($_POST) + ['erro' => t('Sua sessão venceu. Confira e salve.')];
        $_SESSION['next'] = '/lancar?volta=' . rawurlencode($volta);
    }

    /** O que o usuário digitou, para a página voltar preenchida (erro ou duplicado). */
    private static function antigoDe(array $in): array
    {
        $s = static fn(string $k, int $max): string => is_string($in[$k] ?? null) ? mb_substr($in[$k], 0, $max) : '';
        $quando = $s('quando', 8);
        return [
            'pagou'        => preg_match('/^(conta|cartao)(:\d{1,9})?$/D', $s('pagou', 20)) ? $s('pagou', 20) : 'conta',
            'modo'         => $s('modo', 12) === 'transferir' ? 'transferir' : '',
            'de_conta'     => (int) $s('de_conta', 12),
            'para_conta'   => (int) $s('para_conta', 12),
            'estorno'      => $s('estorno', 1) === '1' ? '1' : '',
            'parcelas'     => (string) (int) $s('parcelas', 3),
            'parcelas_outro' => (string) (int) $s('parcelas_outro', 3),
            'parcela_atual'  => (string) (int) $s('parcela_atual', 3),
            'valor'        => $s('valor', 40),
            'categoria_id' => (int) $s('categoria_id', 12),
            'descricao'    => $s('descricao', 255),
            'quando'       => in_array($quando, ['hoje', 'ontem', 'outro'], true) ? $quando : 'hoje',
            'data'         => preg_match('/^\d{4}-\d{2}-\d{2}$/D', $s('data', 10)) ? $s('data', 10) : '',
        ];
    }

    /** Lê (e consome) o formulário guardado na sessão; $id = só se for da edição deste lançamento. */
    private static function tirarAntigo(?int $id): ?array
    {
        $a = $_SESSION['lanc_antigo'] ?? null;
        if (!is_array($a) || (int) ($a['id'] ?? 0) !== (int) $id) {
            return null;
        }
        unset($_SESSION['lanc_antigo']);
        return $a;
    }

    private static function get(string $k): ?string
    {
        return is_string($_GET[$k] ?? null) ? $_GET[$k] : null;
    }

    private static function post(string $k): ?string
    {
        return is_string($_POST[$k] ?? null) ? $_POST[$k] : null;
    }
}
