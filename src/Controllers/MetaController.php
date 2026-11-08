<?php
namespace Financeiro\Controllers;

use Financeiro\Auth;
use Financeiro\Database;
use Financeiro\Models\CartaoModel;
use Financeiro\Models\CategoriaModel;
use Financeiro\Models\ContaRecorrenteModel;
use Financeiro\Models\MesModel;
use Financeiro\Relogio;
use Financeiro\Services\CartaoFatura;
use Financeiro\Services\GastosMensais;
use Financeiro\Services\MetaRecorrencia;
use Financeiro\Services\MotorFinanceiro;
use Financeiro\Services\PrevisaoEdicao;
use Financeiro\Services\PrevisaoLote;
use Financeiro\View;

/**
 * Metas: quanto gastar por mês em cada categoria de gasto, e as contas fixas (valor e dia do vencimento).
 * O seletor de meses mostra o valor que vale em cada mês: a meta, que é a previsão padrão de todo mês ainda
 * em PREVISAO (view previsao_efetiva), ou o valor próprio do mês. Cada linha abre um editor com o valor, o mês
 * em que começa e até quando vale (MetaRecorrencia), também em mês fechado. A gravação é um lote do
 * PrevisaoLote, com desfazer; o aviso mostra o efeito no fim do último mês.
 * O gasto de cada categoria é o que saiu da conta (os lançamentos), como no Mês: a compra no cartão não entra na
 * categoria dela, só a fatura paga, na conta da fatura. O dono não quer o cartão nos indicadores das Metas (2.1.3).
 */
final class MetaController
{
    public function index(): void
    {
        Auth::requireLogin();
        $hoje  = Relogio::hoje();
        $meses = MesModel::todos();
        if ($meses === []) {
            View::render('dashboard/vazio', ['pageTitle' => t('meta · MyMonetto')], 'meta');
            return;
        }
        $mesCal = substr($hoje, 0, 7) . '-01';
        $padrao = in_array($mesCal, array_column($meses, 'mes'), true) ? $mesCal : MesModel::referencia($hoje);
        $mes    = mesValido($_GET['mes'] ?? null, $meses, $padrao);
        $d      = MotorFinanceiro::dados();

        // o mês escolhido: a renda (o que entrou mais o que veio do mês anterior) e o gasto de cada categoria
        $linhaMes   = GastosMensais::matriz()[$mes] ?? ['renda' => 0.0, 'cats' => []];
        $entradas   = (float) ($linhaMes['renda'] ?? 0.0);
        $gastosMes = self::gastosDoMes($mes);
        $gastoDe   = static fn(int $id): float => $gastosMes[$id]['gasto'] ?? 0.0;
        $linhaPlano = MotorFinanceiro::linhaDoMes($mes);
        $sobra      = $linhaPlano !== null ? (float) ($linhaPlano['sobrou_mes_anterior'] ?? 0.0) : 0.0;

        $erro = $_SESSION['meta_erro'] ?? null;
        unset($_SESSION['meta_erro']);
        $erro = is_array($erro) ? $erro : null;

        // contas fixas: as recorrentes ativas, com o valor do mês
        $contas = [];
        $ehConta = [];
        $catFatura = CartaoFatura::mesesDoCartao($hoje) !== null ? CartaoModel::categoriaFatura() : null;
        foreach (ContaRecorrenteModel::ativas() as $r) {
            $id = (int) $r['categoria_id'];
            $c = $d['cats'][$id] ?? null;
            if ($c === null) {
                continue;
            }
            $ehConta[$id] = true;
            $contas[] = self::linha($d, $c, $mes, $gastoDe($id)) + [
                'conta' => (int) $r['id'], 'dia' => (int) $r['dia_vencimento'], 'do_cartao' => $id === $catFatura,
                'nome' => $c['grupo'] === 'Cartao' ? nomeCategoria($c['nome']) : nomeCurto($c),
            ];
        }

        // categorias: as que têm meta ou valor no mês, mais a que acabou de ser escolhida em "Adicionar categoria"
        $add = (int) ($_GET['add'] ?? 0);
        $ess = $naoEss = $addEss = $addNao = [];
        $gastoTotal = 0.0;
        foreach (CategoriaModel::gastos() as $c) {
            $id = (int) $c['id'];
            $gastoTotal += $gastoDe($id);
            if (isset($ehConta[$id])) {
                continue;
            }
            $l = self::linha($d, $d['cats'][$id] ?? $c, $mes, $gastoDe($id)) + ['nome' => nomeCurto($c)];
            $l['aberta'] = $id === $add || (int) ($erro['cat'] ?? 0) === $id;
            $ehEss = (int) $c['essencial'] === 1;
            if ($l['padrao'] !== null || $l['valor'] > 0.004 || $l['aberta']) {
                $ehEss ? ($ess[] = $l) : ($naoEss[] = $l);
            } else {
                $ehEss ? ($addEss[] = $l) : ($addNao[] = $l);
            }
        }

        // erro numa linha que não aparece (a categoria saiu da lista, por exemplo): vira aviso
        $ids = array_column(array_merge($ess, $naoEss, $contas), 'id');
        if ($erro !== null && (int) ($erro['cat'] ?? 0) !== -1 && !in_array((int) ($erro['cat'] ?? 0), $ids, true)) {
            flash('error', (string) ($erro['msg'] ?? t('Algo deu errado. Tente de novo.')));
        }

        // o que tem valor no mês (categorias e contas) é "gasto em metas"; o resto do gasto é "sem meta"
        $totalMeta = $gastoComMeta = 0.0;
        foreach (array_merge($ess, $naoEss, $contas) as $l) {
            if ($l['valor'] > 0.004) {
                $totalMeta += $l['valor'];
                $gastoComMeta += $l['gasto'];
            }
        }
        $soma = static fn(array $ls, string $k): float => round(array_sum(array_column($ls, $k)), 2);

        // os meses do editor: todos, com o fechado marcado
        $opcoes = [];
        foreach ($meses as $m) {
            $k = (string) $m['mes'];
            $opcoes[$k] = $m['situacao'] === 'REAL' ? t('{mes}, fechado', ['mes' => monthLabel($k)]) : monthLabel($k);
        }

        $elegiveis = [];
        foreach (ContaRecorrenteModel::categoriasElegiveis() as $c) {
            if (!isset($ehConta[$c['id']])) {
                $elegiveis[] = $c['grupo'] === 'Cartao' ? nomeCategoria($c['nome']) : nomeCurto($c);
            }
        }

        View::render('meta/index', [
            'pageTitle'     => t('meta · MyMonetto'),
            'mes'           => $mes,
            'meses'         => $meses,
            'hoje'          => $hoje,
            'opcoes'        => $opcoes,
            'essenciais'    => $ess,
            'naoEssenciais' => $naoEss,
            'addEss'        => $addEss,
            'addNao'        => $addNao,
            'contas'        => $contas,
            'elegiveis'     => $elegiveis,
            'erro'          => $erro,
            'renda'         => round($entradas + $sobra, 2),
            'totalMeta'     => round($totalMeta, 2),
            'gastoTotal'    => round($gastoTotal, 2),
            'gastoComMeta'  => round($gastoComMeta, 2),
            'gastoSemMeta'  => round($gastoTotal - $gastoComMeta, 2),
            'metaEss'       => $soma($ess, 'valor'),
            'metaNaoEss'    => $soma($naoEss, 'valor'),
            'gastoEss'      => $soma($ess, 'gasto'),
            'gastoNaoEss'   => $soma($naoEss, 'gasto'),
            'totalContas'   => $soma($contas, 'valor'),
        ], 'meta');
    }

    /**
     * O gasto de cada categoria de gasto no mês, como as Metas contam: o que saiu da conta (os lançamentos), o mesmo
     * número do Mês. A compra no cartão não entra na categoria dela; a fatura paga conta na categoria da fatura. O
     * "Precisa de atenção" usa esta mesma conta. É o gasto realizado: o agendado (data depois de hoje) fica de fora
     * até o dia dele (MotorFinanceiro::realizado).
     * @return array<int, array{gasto:float}>
     */
    public static function gastosDoMes(string $mes, ?string $hoje = null): array
    {
        $out = [];
        foreach (GastosMensais::matriz($hoje)[$mes]['cats'] ?? [] as $id => $c) {
            $out[(int) $id] = ['gasto' => round((float) ($c['real'] ?? 0.0), 2)];
        }
        return $out;
    }

    /**
     * Uma categoria no mês: o valor que vale nele, a meta de todo mês e se o mês tem valor próprio diferente dela.
     * @return array{id:int, grupo:string, essencial:int, valor:float, padrao:?float, proprio:bool, gasto:float}
     */
    private static function linha(array $d, array $c, string $mes, float $gasto): array
    {
        $id = (int) $c['id'];
        $valor = round((float) ($d['prev'][$mes][$id] ?? 0.0), 2);
        $padrao = PrevisaoEdicao::meta($d, $id);
        return [
            'id' => $id, 'grupo' => (string) $c['grupo'], 'essencial' => (int) $c['essencial'],
            'valor' => $valor, 'padrao' => $padrao, 'gasto' => round($gasto, 2),
            'proprio' => !empty($d['excecao'][$mes][$id]) && $padrao !== null && abs($valor - $padrao) >= 0.005,
        ];
    }

    /* ================================================================== POST /meta/editar */

    /**
     * Valor de uma categoria com recorrência. Campos: categoria_id, valor, desde (AAAA-MM-01), ate ('' = sem fim,
     * 'so' = só o mês de desde, ou AAAA-MM-01), acao (salvar | remover = sem meta), ver (o mês da tela, para voltar).
     */
    public function editar(): void
    {
        Auth::requireLogin();
        csrfCheck();

        $cat = (int) ($_POST['categoria_id'] ?? 0);
        $ver = self::mesPost('ver') ?? Relogio::mes();
        $volta = '/meta?mes=' . $ver . '#c' . $cat;
        $d = MotorFinanceiro::dados();
        if (self::doCartao($cat)) {
            flash('warn', t('A Fatura do cartão deste mês vem das compras lançadas no Cartão'));
            redirecionar('/cartao#fatura', 303);
        }
        $remover = ($_POST['acao'] ?? '') === 'remover';
        $bruto = $remover ? '' : trim(is_string($_POST['valor'] ?? null) ? $_POST['valor'] : '');
        try {
            [$desde, $ate] = self::periodo();
            $valor = self::valorPost($bruto);
            $plano = MetaRecorrencia::plano($d, $cat, $valor, $desde, $ate);
        } catch (\InvalidArgumentException $e) {
            self::voltarComErro($volta, $cat, $e->getMessage(), $bruto);
        }

        $nome = nomeCurto($d['cats'][$cat]);
        self::gravarEAvisar($plano, $valor !== null && $valor > 0.004 ? $nome . ' ' . num($valor) : t('{nome} sem meta', ['nome' => $nome]),
            $desde, $ate, $volta);
    }

    /* ================================================================== contas fixas */

    /** POST /meta/contas: conta fixa nova (nome, valor, dia do vencimento, desde). Nome de uma categoria que já existe usa ela. */
    public function novaConta(): void
    {
        Auth::requireLogin();
        csrfCheck();

        $ver = self::mesPost('ver') ?? Relogio::mes();
        $volta = '/meta?mes=' . $ver . '#contas-fixas';
        $nome = is_string($_POST['nome'] ?? null) ? $_POST['nome'] : '';
        $nome = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $nome));
        $bruto = trim(is_string($_POST['valor'] ?? null) ? $_POST['valor'] : '');
        $dia = self::diaPost();
        try {
            if ($nome === '') {
                throw new \InvalidArgumentException(t('Digite o nome da conta.'));
            }
            if (mb_strlen($nome) > 100) {
                throw new \InvalidArgumentException(t('Nome muito longo. Use até 100 letras.'));
            }
            if ($dia === null) {
                throw new \InvalidArgumentException(t('Digite um dia de 1 a 31.'));
            }
            [$desde] = self::periodo();
            $valor = self::valorPost($bruto);
            $cat = self::categoriaDaConta($nome);
            // conta nova pede o valor (sem ele, a linha só dizia "falta 0,00"); a Fatura do cartão vem do Cartão
            if (($valor === null || $valor <= 0.004) && ($cat === null || !self::doCartao($cat))) {
                throw new \InvalidArgumentException(t('Digite o valor da conta por mês.'));
            }
        } catch (\InvalidArgumentException $e) {
            self::voltarComErro($volta, -1, $e->getMessage(), $bruto, ['nome' => $nome, 'dia' => (string) ($_POST['dia'] ?? '')]);
        }

        $pdo = Database::connection();
        $lote = null;
        try {
            $pdo->beginTransaction();
            if ($cat === null) {
                $cat = CategoriaModel::criar($nome, 'saida', true, null, true);
                if ($cat === null) {
                    throw new \InvalidArgumentException(t('Já existe uma categoria chamada {nome}.', ['nome' => $nome]));
                }
                MotorFinanceiro::limparCache();
            }
            ContaRecorrenteModel::upsert($cat, $dia);
            if ($valor !== null && $valor > 0.004 && !self::doCartao($cat)) {
                $plano = MetaRecorrencia::plano(MotorFinanceiro::dados(), $cat, $valor, $desde, null);
                $lote = PrevisaoLote::gravar('propagar', $plano['celulas'], (int) Auth::id(), null, true, $plano['meta']);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            MotorFinanceiro::limparCache();
            if ($e instanceof \InvalidArgumentException) {
                self::voltarComErro($volta, -1, $e->getMessage(), $bruto, ['nome' => $nome, 'dia' => (string) $dia]);
            }
            error_log('meta/contas: ' . $e->getMessage());
            flash('error', t('Não foi possível salvar. Tente de novo.'));
            redirecionar($volta, 303);
        }
        MotorFinanceiro::limparCache();
        $c = MotorFinanceiro::dados()['cats'][$cat] ?? ['nome' => $nome, 'apelido' => null];
        $segs = [t('{nome} · dia {dia}', ['dia' => $dia, 'nome' => nomeCurto($c)])];
        if ($lote !== null) {
            $segs[] = t('{valor} a partir de {mes}', ['valor' => num((float) $valor), 'mes' => monthLabel($desde)]);
        }
        flash('success', implode(' · ', $segs));
        redirecionar('/meta?mes=' . $ver . '#conta-' . $cat, 303);
    }

    /**
     * POST /meta/contas/{id}: o valor da conta com recorrência e o dia do vencimento. Se só o dia mudou (o valor,
     * o "a partir de" e o "até" estão como vieram), só o dia é gravado.
     */
    public function salvarConta(string $id): void
    {
        Auth::requireLogin();
        csrfCheck();

        $conta = ContaRecorrenteModel::buscar((int) $id);
        $ver = self::mesPost('ver') ?? Relogio::mes();
        if ($conta === null || (int) $conta['ativo'] !== 1) {
            flash('warn', t('Essa conta já tinha sido removida.'));
            redirecionar('/meta?mes=' . $ver . '#contas-fixas', 303);
        }
        $cat = (int) $conta['categoria_id'];
        $volta = '/meta?mes=' . $ver . '#conta-' . $cat;
        $bruto = trim(is_string($_POST['valor'] ?? null) ? $_POST['valor'] : '');
        $dia = self::diaPost();
        $d = MotorFinanceiro::dados();
        $plano = null;
        try {
            if ($dia === null) {
                throw new \InvalidArgumentException(t('Digite um dia de 1 a 31.'));
            }
            [$desde, $ate] = self::periodo();
            $valor = self::valorPost($bruto);
            $mexeuNoValor = $bruto !== (string) ($_POST['valor_era'] ?? '') || $desde !== (string) ($_POST['desde_era'] ?? '') || $ate !== null;
            if ($mexeuNoValor && !self::doCartao($cat)) {
                $plano = MetaRecorrencia::plano($d, $cat, $valor, $desde, $ate);
            }
        } catch (\InvalidArgumentException $e) {
            self::voltarComErro($volta, $cat, $e->getMessage(), $bruto, ['dia' => (string) ($_POST['dia'] ?? '')]);
        }

        $nome = (string) $conta['grupo'] === 'Cartao' ? nomeCategoria((string) $conta['categoria_nome']) : nomeCurto(['nome' => $conta['categoria_nome'], 'apelido' => $conta['apelido']]);
        $diaMudou = $dia !== (int) $conta['dia_vencimento'];
        if ($plano === null) {
            if ($diaMudou) {
                ContaRecorrenteModel::upsert($cat, $dia);
            }
            flash('success', $diaMudou ? t('{nome} · dia {dia}', ['dia' => $dia, 'nome' => $nome]) : t('Nada mudou.'));
            redirecionar($volta, 303);
        }
        $oQue = ($valor !== null && $valor > 0.004 ? $nome . ' ' . num($valor) : $nome . ' ' . num(0.0)) . ($diaMudou ? ' · ' . t('dia {dia}', ['dia' => $dia]) : '');
        self::gravarEAvisar($plano, $oQue, $desde, $ate, $volta, $diaMudou ? static fn() => ContaRecorrenteModel::upsert($cat, $dia) : null);
    }

    /**
     * POST /meta/contas/{id}/desativar: a conta sai da lista e do plano a partir de desde (o mês da tela ou,
     * se ele já passou, o mês de hoje). O aviso traz "desfazer", que reativa e devolve os valores.
     */
    public function desativarConta(string $id): void
    {
        Auth::requireLogin();
        csrfCheck();

        $conta = ContaRecorrenteModel::buscar((int) $id);
        $ver = self::mesPost('ver') ?? Relogio::mes();
        $volta = '/meta?mes=' . $ver . '#contas-fixas';
        if ($conta === null || (int) $conta['ativo'] !== 1) {
            flash('warn', t('Essa conta já tinha sido removida.'));
            redirecionar($volta, 303);
        }
        $cat = (int) $conta['categoria_id'];
        $desde = max(self::mesPost('desde') ?? Relogio::mes(), Relogio::mes());
        $d = MotorFinanceiro::dados();
        $pdo = Database::connection();
        $lote = null;
        try {
            $pdo->beginTransaction();
            if (!self::doCartao($cat) && MetaRecorrencia::editavel($d['cats'][$cat] ?? null) && in_array($desde, array_column($d['meses'], 'mes'), true)) {
                $plano = MetaRecorrencia::plano($d, $cat, null, $desde, null);
                $lote = PrevisaoLote::gravar('propagar', $plano['celulas'], (int) Auth::id(), null, true, $plano['meta']);
            }
            ContaRecorrenteModel::desativar((int) $id);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            MotorFinanceiro::limparCache();
            error_log('meta/contas/desativar: ' . $e->getMessage());
            flash('error', t('Não foi possível salvar. Tente de novo.'));
            redirecionar($volta, 303);
        }
        MotorFinanceiro::limparCache();
        $nome = (string) $conta['grupo'] === 'Cartao' ? nomeCategoria((string) $conta['categoria_nome']) : nomeCurto(['nome' => $conta['categoria_nome'], 'apelido' => $conta['apelido']]);
        flash('success', $lote !== null ? t('{nome} desativada · fora do plano a partir de {mes}', ['nome' => $nome, 'mes' => monthLabel($desde)]) : t('{nome} removida', ['nome' => $nome]), [
            'url' => '/meta/contas/' . (int) $id . '/reativar', 'label' => t('desfazer'),
            'campos' => ['lote' => (string) $lote, 'ver' => $ver],
        ]);
        redirecionar($volta, 303);
    }

    /** POST /meta/contas/{id}/reativar: o desfazer de desativar. A conta volta, com os valores que tinha no plano. */
    public function reativarConta(string $id): void
    {
        Auth::requireLogin();
        csrfCheck();

        $conta = ContaRecorrenteModel::buscar((int) $id);
        $ver = self::mesPost('ver') ?? Relogio::mes();
        if ($conta === null) {
            flash('error', t('Algo deu errado. Tente de novo.'));
            redirecionar('/meta?mes=' . $ver . '#contas-fixas', 303);
        }
        $cat = (int) $conta['categoria_id'];
        $lote = is_string($_POST['lote'] ?? null) ? $_POST['lote'] : '';
        $pdo = Database::connection();
        try {
            $pdo->beginTransaction();
            ContaRecorrenteModel::upsert($cat, (int) $conta['dia_vencimento']);
            if (preg_match('/^[0-9a-f]{16}$/', $lote)) {
                PrevisaoLote::desfazer($lote, (int) Auth::id());
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            MotorFinanceiro::limparCache();
            error_log('meta/contas/reativar: ' . $e->getMessage());
            flash('error', t('Não foi possível desfazer. Tente de novo.'));
            redirecionar('/meta?mes=' . $ver . '#contas-fixas', 303);
        }
        MotorFinanceiro::limparCache();
        $nome = (string) $conta['grupo'] === 'Cartao' ? nomeCategoria((string) $conta['categoria_nome']) : nomeCurto(['nome' => $conta['categoria_nome'], 'apelido' => $conta['apelido']]);
        flash('success', t('{nome} · dia {dia}', ['dia' => (int) $conta['dia_vencimento'], 'nome' => $nome]));
        redirecionar('/meta?mes=' . $ver . '#conta-' . $cat, 303);
    }

    /* ================================================================== internos */

    /**
     * Grava o plano (e o que mais tiver de ir junto) numa transação, e avisa: o que mudou, em que meses, o efeito no
     * fim do último mês e o primeiro mês que fica abaixo da reserva ou no vermelho, com "desfazer".
     */
    private static function gravarEAvisar(array $plano, string $oQue, string $desde, ?string $ate, string $volta, ?callable $junto = null): never
    {
        $antes = MotorFinanceiro::calcularPlano();
        $pdo = Database::connection();
        $lote = null;
        try {
            $pdo->beginTransaction();
            $origem = $plano['meta'] !== null || count($plano['celulas']) > 1 ? 'propagar' : 'manual';
            $lote = PrevisaoLote::gravar($origem, $plano['celulas'], (int) Auth::id(), null, true, $plano['meta']);
            if ($junto !== null) {
                $junto();
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            MotorFinanceiro::limparCache();
            error_log('meta/editar: ' . $e->getMessage());
            flash('error', t('Não foi possível salvar. Tente de novo.'));
            redirecionar($volta, 303);
        }
        MotorFinanceiro::limparCache();
        if ($lote === null) {
            flash('success', $junto !== null ? $oQue : t('Nada mudou.'));
            redirecionar($volta, 303);
        }
        $depois = MotorFinanceiro::calcularPlano();

        $segs = [$oQue, match (true) {
            $ate === null   => t('a partir de {mes}', ['mes' => monthLabel($desde)]),
            $ate === $desde => monthLabel($desde),
            default         => monthLabel($desde) . ' → ' . monthLabel($ate),
        }];
        $ult = array_key_last($depois);
        if ($ult !== null && isset($antes[$ult])
            && abs((float) $antes[$ult]['fica_na_conta'] - (float) $depois[$ult]['fica_na_conta']) >= 0.5) {
            $segs[] = t('fim de {mes} {antes} → {depois}', ['mes' => monthLabel((string) $ult), 'antes' => num((float) $antes[$ult]['fica_na_conta']), 'depois' => num((float) $depois[$ult]['fica_na_conta'])]);
        }
        $reserva = (float) (MotorFinanceiro::dados()['aj']['reserva_minima'] ?? 0.0);
        $alerta  = PrevisaoEdicao::piorou($antes, $depois, $desde, $reserva);
        if ($alerta !== null) {
            $segs[] = $alerta['tipo'] === 'vermelho' ? t('atenção: {mes} no vermelho', ['mes' => monthLabel($alerta['mes'])]) : t('atenção: {mes} abaixo da reserva', ['mes' => monthLabel($alerta['mes'])]);
        }
        flash($alerta !== null ? 'warn' : 'success', implode(' · ', $segs),
            ['url' => '/mes/desfazer', 'label' => t('desfazer'), 'campos' => ['lote' => $lote, 'volta' => $volta]]);
        redirecionar($volta, 303);
    }

    /**
     * O período do POST: desde (um mês que existe; o mês de hoje se faltar) e ate (null = sem fim; 'so' = desde).
     * @return array{0:string, 1:?string}
     */
    private static function periodo(): array
    {
        $desde = self::mesPost('desde');
        $bruto = is_string($_POST['ate'] ?? null) ? $_POST['ate'] : '';
        if ($desde === null) {
            throw new \InvalidArgumentException(t('Não encontramos esse mês. Volte e tente de novo.'));
        }
        if ($bruto === '') {
            return [$desde, null];
        }
        if ($bruto === 'so') {
            return [$desde, $desde];
        }
        $ate = self::mesPost('ate');
        if ($ate === null) {
            throw new \InvalidArgumentException(t('Não encontramos esse mês. Volte e tente de novo.'));
        }
        return [$desde, $ate];
    }

    /** Valor digitado: vazio = sem meta (null); inválido recusa. */
    private static function valorPost(string $bruto): ?float
    {
        if ($bruto === '') {
            return null;
        }
        $v = parseMoedaBr($bruto);
        if ($v === null || $v < 0 || $v > valorMaximo()) {
            // i18n: t('Digite um valor, por exemplo 1.200 ou 1.200,50.')
            throw new \InvalidArgumentException(motivoValor($bruto) ?? t(PrevisaoEdicao::ERRO_VALOR));
        }
        return round($v, 2);
    }

    private static function diaPost(): ?int
    {
        $s = trim(is_string($_POST['dia'] ?? null) ? $_POST['dia'] : '');
        return ctype_digit($s) && (int) $s >= 1 && (int) $s <= 31 ? (int) $s : null;
    }

    /** Um mês do POST que existe em `meses`, ou null. */
    private static function mesPost(string $campo): ?string
    {
        $m = $_POST[$campo] ?? null;
        if (!is_string($m) || !preg_match('/^\d{4}-\d{2}-01$/', $m)) {
            return null;
        }
        return in_array($m, array_column(MesModel::todos(), 'mes'), true) ? $m : null;
    }

    /** A Fatura do cartão, quando o Cartão alimenta o plano: o valor dela vem das compras, não daqui. */
    private static function doCartao(int $cat): bool
    {
        return CartaoFatura::mesesDoCartao() !== null && $cat === CartaoModel::categoriaFatura();
    }

    /**
     * A categoria de uma conta nova pelo nome digitado: uma das que podem ser conta (pelo nome ou pelo nome que
     * aparece na tela, que pode estar traduzido), ou null para criar. Nome de outra categoria qualquer recusa, e o de
     * uma conta fixa ativa também (sem acento e sem caixa): ela não é sobrescrita.
     * @throws \InvalidArgumentException
     */
    private static function categoriaDaConta(string $nome): ?int
    {
        $chave = mb_strtolower($nome);
        $ativas = array_flip(array_map('intval', array_column(ContaRecorrenteModel::ativas(), 'categoria_id')));
        foreach (ContaRecorrenteModel::categoriasElegiveis() as $c) {
            foreach ([$c['nome'], nomeCategoria($c['nome']), nomeCurto($c)] as $n) {
                if (mb_strtolower((string) $n) === $chave) {
                    // a conta que já existe não é sobrescrita: muda-se pela linha dela, que mostra o que vale hoje
                    if (isset($ativas[(int) $c['id']])) {
                        throw new \InvalidArgumentException(t('Já existe uma conta fixa com esse nome.'));
                    }
                    return (int) $c['id'];
                }
            }
        }
        if (CategoriaModel::porNome($nome) !== null) {
            throw new \InvalidArgumentException(t('Já existe uma categoria chamada {nome}.', ['nome' => $nome]));
        }
        return null;
    }

    /** @param int $cat a linha que reabre com o erro (-1 = o formulário de conta nova) */
    private static function voltarComErro(string $volta, int $cat, string $msg, string $valor, array $mais = []): never
    {
        $_SESSION['meta_erro'] = ['cat' => $cat, 'msg' => $msg, 'valor' => $valor,
            'desde' => is_string($_POST['desde'] ?? null) ? $_POST['desde'] : '', 'ate' => is_string($_POST['ate'] ?? null) ? $_POST['ate'] : ''] + $mais;
        redirecionar($volta, 303);
    }
}
