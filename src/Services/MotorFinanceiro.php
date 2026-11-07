<?php
namespace Financeiro\Services;

use Financeiro\Config;
use Financeiro\Database;
use Financeiro\Models\AcertoModel;
use Financeiro\Models\AjusteModel;
use Financeiro\Models\ContaModel;
use Financeiro\Models\LancamentoModel;
use Financeiro\Models\MesModel;
use Financeiro\Relogio;
use Financeiro\Sql;

/**
 * Motor de cálculo — porta fiel da cascata do plano.
 *
 * Regras (ver docs/01-modelo-financeiro.md e docs/02-historico-decisoes.md):
 * - crédito do empréstimo, amortização extra e poupança NUNCA entram como renda ou gasto
 *   (`categorias.operacional = 0`); entram só no fluxo de caixa, em linhas separadas;
 * - "balanço" nas telas é sempre balancoTotal() (fluxo de caixa completo). `resultado_op`
 *   (entra − sai) é interno do motor e dos testes;
 * - mês aberto (PREVISAO e ≤ mês de hoje): gastos = Σ max(real, previsto) por categoria (D5);
 * - a Fatura do cartão desconta da previsão o que já foi pago da fatura que vence no mês, mesmo num
 *   mês anterior (CartaoFatura::atribuir; ver previsaoDoMes): a fatura não conta duas vezes;
 * - com MOTOR_V2=1, a sobra acima da reserva amortiza o empréstimo "olhando à frente": só o
 *   que mantém todos os meses seguintes ≥ reserva (D4); `objetivo` 3 (só a meta) não amortiza.
 *
 * - saldo devedor informado (ajustes saldo_devedor e saldo_devedor_mes): a dívida passa a esse valor
 *   no início do mês informado, antes da parcela. O extrato do banco já contém o que aconteceu antes,
 *   então nos meses anteriores o motor não supõe adiantamento automático.
 *
 * dados() lê o banco uma vez; calcular() é aritmética pura sobre esse array (a sugestão roda
 * o motor centenas de vezes por requisição sem tocar no banco).
 */
final class MotorFinanceiro
{
    // As categorias que o app procura, pela chave (categorias.chave): o nome delas pode mudar.
    public const CAT_SALARIO = 'salario';
    public const CAT_CREDITO = 'credito_emprestimo';
    public const CAT_AMORT   = 'amortizacao';
    public const CAT_GUARDAR = 'guardar';
    public const CAT_RESGATE = 'resgate_poupanca';
    public const CAT_PARCELA = 'parcela';
    public const CAT_APLICAR = 'aplicar';
    public const CAT_RESGATE_INV = 'resgate_investimento';
    public const CAT_FATURA  = 'fatura';

    private static ?array $planoCache = null;
    private static ?array $dadosCache = null;
    /** @var array<string, array> realizado(), por dia */
    private static array $realizadoCache = [];

    public static function v2(): bool
    {
        return Config::get('MOTOR_V2', '0') === '1';
    }

    public static function limparCache(): void
    {
        self::$planoCache = null;
        self::$dadosCache = null;
        self::$realizadoCache = [];
        ContaModel::limparCache();
        SugestaoAlocacao::limparCache();
        GastosMensais::limparCache();
        GastoPorCategoria::limparCache();
    }

    /**
     * Tudo o que o motor precisa, numa leitura só (cache por requisição; limparCache() zera).
     * prev = previsão efetiva (view previsao_efetiva: o valor do mês, senão a meta, senão nada);
     * excecao[mes][cat] = o mês tem linha própria em `previsao`; metas[cat] = a meta que vale como
     * previsão padrão (só gasto ativo — a mesma condição da view); fatura = os pagamentos de fatura,
     * cada um na sua fatura (CartaoFatura::faturasPagas; cat null sem cartão).
     * @return array{meses:list<array{mes:string,situacao:string}>, aj:array<string,float>, loanIni:float,
     *   cats:array<int,array{id:int,nome:string,apelido:?string,grupo:string,sinal:int,operacional:int,essencial:int,ativo:int,ordem:int}>,
     *   prev:array<string,array<int,float>>, excecao:array<string,array<int,true>>, metas:array<int,float>,
     *   real:array<string,array<int,float>>, n:array<string,array<int,int>>,
     *   ult:array<string,array<int,string>>, esp:array{cred:?int,amort:?int,guard:?int,resg:?int},
     *   fatura:array{cat:?int, vence:int, faturas:array<string, array>}, aportes:array<string, float>,
     *   acertos:array<string, float>} (acertos = os acertos de saldo do banco somados por mês, AcertoModel::porMes)
     */
    public static function dados(): array
    {
        if (self::$dadosCache !== null) {
            return self::$dadosCache;
        }
        $pdo = Database::connection();

        $meses = array_map(fn($m) => ['mes' => (string) $m['mes'], 'situacao' => (string) $m['situacao']], MesModel::todos());
        $aj    = AjusteModel::mapa();
        if (($aj['usa_emprestimo'] ?? 1.0) < 1.0) {             // sem a aba Empréstimo, é como se não houvesse dívida
            foreach (['parcela_mensal', 'n_parcelas', 'juros_mes', 'saldo_devedor', 'recebido_emprestimo'] as $k) {
                $aj[$k] = 0.0;
            }
        }
        $loanIni = AjusteModel::devedorPor($aj['parcela_mensal'] ?? 0.0, $aj['n_parcelas'] ?? 0.0, $aj['juros_mes'] ?? 0.0);

        $cats = [];
        $esp  = ['cred' => null, 'amort' => null, 'guard' => null, 'resg' => null, 'parc' => null, 'aplic' => null, 'resgInv' => null];
        $chavesEsp = [self::CAT_CREDITO => 'cred', self::CAT_AMORT => 'amort', self::CAT_GUARDAR => 'guard', self::CAT_RESGATE => 'resg', self::CAT_PARCELA => 'parc', self::CAT_APLICAR => 'aplic', self::CAT_RESGATE_INV => 'resgInv'];
        foreach ($pdo->query('SELECT id, nome, apelido, grupo, sinal, operacional, essencial, ativo, ordem, chave FROM categorias ORDER BY ordem, id') as $c) {
            $id = (int) $c['id'];
            $cats[$id] = [
                'id' => $id, 'nome' => (string) $c['nome'], 'apelido' => $c['apelido'] !== null ? (string) $c['apelido'] : null,
                'grupo' => (string) $c['grupo'], 'sinal' => (int) $c['sinal'], 'operacional' => (int) $c['operacional'],
                'essencial' => (int) $c['essencial'], 'ativo' => (int) $c['ativo'], 'ordem' => (int) $c['ordem'],
                'chave' => $c['chave'] !== null ? (string) $c['chave'] : null,
            ];
            if (isset($chavesEsp[(string) $c['chave']])) {
                $esp[$chavesEsp[(string) $c['chave']]] = $id;
            }
        }

        $prev = $excecao = $metas = [];
        foreach ($pdo->query('SELECT mes, categoria_id, valor, fonte FROM previsao_efetiva WHERE fonte IS NOT NULL') as $r) {
            $m = (string) $r['mes'];
            $c = (int) $r['categoria_id'];
            $prev[$m][$c] = (float) $r['valor'];
            if ($r['fonte'] === 'mes') {
                $excecao[$m][$c] = true;
            }
        }
        // início no meio do mês: nesse 1º mês, a meta conta só pelos dias que faltam (valor próprio do mês fica como está)
        $inicio = AjusteModel::dataDe((float) ($aj['data_inicio'] ?? 0.0));
        if ($inicio !== null && (int) substr($inicio, 8, 2) > 1) {
            $mesIni = substr($inicio, 0, 8) . '01';
            $fracao = (diasNoMes($mesIni) - (int) substr($inicio, 8, 2) + 1) / diasNoMes($mesIni);
            foreach ($prev[$mesIni] ?? [] as $c => $v) {
                if (!isset($excecao[$mesIni][$c])) {
                    $prev[$mesIni][$c] = round($v * $fracao, 2);
                }
            }
        }
        foreach ($pdo->query('SELECT categoria_id, valor FROM metas') as $r) {
            $c = $cats[(int) $r['categoria_id']] ?? null;
            if ($c !== null && $c['ativo'] === 1 && $c['operacional'] === 1 && $c['sinal'] === -1) {
                $metas[$c['id']] = (float) $r['valor'];
            }
        }

        $real = $n = $ult = [];
        $mesDaData = Sql::inicioMes('data');
        $sql = "SELECT {$mesDaData} AS mes, categoria_id, SUM(valor) AS total, COUNT(*) AS n, MAX(data) AS ult
                FROM lancamentos GROUP BY {$mesDaData}, categoria_id";
        foreach ($pdo->query($sql) as $r) {
            $m = (string) $r['mes'];
            $c = (int) $r['categoria_id'];
            $real[$m][$c] = (float) $r['total'];
            $n[$m][$c]    = (int) $r['n'];
            $ult[$m][$c]  = (string) $r['ult'];
        }

        return self::$dadosCache = [
            'meses' => $meses, 'aj' => $aj, 'loanIni' => $loanIni, 'cats' => $cats,
            'prev' => $prev, 'excecao' => $excecao, 'metas' => $metas,
            'real' => $real, 'n' => $n, 'ult' => $ult, 'esp' => $esp,
            'fatura' => CartaoFatura::faturasPagas($prev, ($aj['usa_cartao'] ?? 1.0) >= 1.0),
            'aportes' => ContaModel::aportes(),
            'acertos' => AcertoModel::porMes(),
        ];
    }

    /**
     * Agendado em toda conta: o lançamento (ou a compra no cartão) com data depois de hoje ainda não aconteceu. A previsão
     * o conta (o plano usa dados(), com tudo o que foi lançado), mas nenhum realizado o conta até o dia dele: o "Gastou"
     * e o "Já saiu" do Início e do Mês, a coluna Realizado do Mês, o gasto das Metas, o "Para onde foi"
     * (GastoPorCategoria), o Relatório e o PDF, o "Precisa de atenção" e o aviso depois de lançar. Esta é a regra única:
     * os dados do motor com o lançado só até $hoje (real, n, ult e os pagamentos de fatura), pelo critério SQL de
     * LancamentoModel::realizado. Mês já terminado não muda, porque todo lançamento dele é passado.
     * @param ?string $hoje Y-m-d (padrão: hoje)
     */
    public static function realizado(?string $hoje = null): array
    {
        $hoje ??= Relogio::hoje();
        if (isset(self::$realizadoCache[$hoje])) {
            return self::$realizadoCache[$hoje];
        }
        $dados = self::dados();
        $real = $n = $ult = [];
        $mesDaData = Sql::inicioMes('data');
        $sql = "SELECT {$mesDaData} AS mes, categoria_id, SUM(valor) AS total, COUNT(*) AS n, MAX(data) AS ult
                FROM lancamentos WHERE " . LancamentoModel::realizado('data', $hoje) . " GROUP BY {$mesDaData}, categoria_id";
        foreach (Database::connection()->query($sql) as $r) {
            $m = (string) $r['mes'];
            $c = (int) $r['categoria_id'];
            $real[$m][$c] = (float) $r['total'];
            $n[$m][$c]    = (int) $r['n'];
            $ult[$m][$c]  = (string) $r['ult'];
        }
        $dados['real'] = $real;
        $dados['n'] = $n;
        $dados['ult'] = $ult;
        $dados['fatura'] = CartaoFatura::faturasPagas($dados['prev'], ($dados['aj']['usa_cartao'] ?? 1.0) >= 1.0, $hoje);
        return self::$realizadoCache[$hoje] = $dados;
    }

    /**
     * A previsão de cada categoria num mês ainda não fechado, como o motor a usa: a do mês (ou a meta),
     * com o override, e a Fatura do cartão sem o que já foi pago da fatura que vence nele (dados['fatura']),
     * para a fatura paga num mês anterior não entrar de novo. Num mês aberto, a Fatura vira o lançado no mês
     * mais o que ainda falta dessa fatura: o lançado pode ser de outra fatura (paga adiantada, no fim do
     * mês anterior ao vencimento, ou atrasada), e o motor usa o maior entre lançado e previsto. Pagamento
     * com data num mês futuro não desconta, porque o motor não soma o lançado de mês futuro.
     * @param string $estado 'aberto' (vale o lançado do mês) ou 'previsto'
     * @param string $mesHoje o mês de hoje, Y-m-01
     * @return array<int, float>
     */
    public static function previsaoDoMes(array $dados, string $mes, string $estado, string $mesHoje, array $override = []): array
    {
        $p = $dados['prev'][$mes] ?? [];
        if ($override !== []) {
            $p = array_replace($p, $override);
        }
        $fat = $dados['fatura']['cat'] ?? null;
        $pagos = $fat !== null ? ($dados['fatura']['faturas'][$mes]['meses'] ?? []) : [];
        if ($fat === null || ($pagos === [] && $estado !== 'aberto')) {
            return $p;
        }
        $pago = 0.0;
        foreach ($pagos as $quando => $v) {
            if ($quando <= $mesHoje && ($estado === 'aberto' || $quando < $mes)) {
                $pago += $v;
            }
        }
        $falta = max(0.0, (float) ($p[$fat] ?? 0.0) - $pago);
        $p[$fat] = $estado === 'aberto' ? (float) ($dados['real'][$mes][$fat] ?? 0.0) + $falta : $falta;
        return $p;
    }

    /**
     * A cascata mês a mês, aritmética pura sobre dados().
     * opts: override [mes=>[cat=>valor]] (só meses PREVISAO), v2 (bool, padrão MOTOR_V2),
     *       aberto (bool, padrão true: regra D5), objetivo (1 os dois · 2 só quitar · 3 só a meta;
     *       padrão ajustes.objetivo; ignorado sem v2), hoje ('Y-m-d'), tratarComoReal (string[]),
     *       extra [mes=>valor] (o "e se" do Anual: fora de mês REAL, + entra e − sai no mês, como uma
     *       categoria operacional a mais; nada é gravado),
     *       adiantar [mes=>valor] (a simulação da aba Empréstimo: fora de mês REAL, soma à amortização
     *       extra lançada no mês; nada é gravado).
     */
    public static function calcular(array $dados, array $opts = []): array
    {
        $aj       = $dados['aj'];
        $cats     = $dados['cats'];
        $esp      = $dados['esp'];
        $v2       = (bool) ($opts['v2'] ?? self::v2());
        $regraAberto = (bool) ($opts['aberto'] ?? true);
        $objetivo = (int) ($opts['objetivo'] ?? (int) round($aj['objetivo'] ?? 1));
        if (!in_array($objetivo, [1, 2, 3], true)) {
            $objetivo = 1;
        }
        $hoje     = (string) ($opts['hoje'] ?? Relogio::hoje());
        $mesHoje  = substr($hoje, 0, 7) . '-01';
        $tcr      = array_flip($opts['tratarComoReal'] ?? []);
        $override = $opts['override'] ?? [];
        $extra    = $opts['extra'] ?? [];
        $adiantar = $opts['adiantar'] ?? [];

        $reserva  = (float) ($aj['reserva_minima'] ?? 0.0);
        $meta     = (float) ($aj['meta_final'] ?? 0.0);
        $parcela  = (float) ($aj['parcela_mensal'] ?? 0.0);
        $jurosMes = (float) ($aj['juros_mes'] ?? 0.0);
        $iniParcela = self::mesInicioParcela($aj);
        $informado  = self::saldoInformado($aj);

        // ---- passo 1: componentes de cada mês (não dependem do encadeamento)
        $comp = [];
        foreach ($dados['meses'] as $m) {
            $mes   = $m['mes'];
            $ehReal = $m['situacao'] === 'REAL' || isset($tcr[$mes]);
            $estado = $ehReal ? 'real' : ($mes <= $mesHoje ? 'aberto' : 'previsto');
            $real  = $dados['real'][$mes] ?? [];

            $entra = $sai = 0.0;
            $cred = $amortReal = $guard = $resg = $aplic = $resgInv = $parcLanc = 0.0;
            $usaMov = false;
            if ($ehReal) {
                foreach ($real as $c => $v) {
                    $cat = $cats[$c] ?? null;
                    if ($cat !== null && $cat['operacional'] === 1) {
                        if ($cat['sinal'] === 1) { $entra += $v; } else { $sai += $v; }
                    }
                }
                $cred      = self::em($real, $esp['cred']);
                $amortReal = self::em($real, $esp['amort']);
                $guard     = self::em($real, $esp['guard']);
                $resg      = self::em($real, $esp['resg']);
                $aplic     = self::em($real, $esp['aplic'] ?? null);
                $resgInv   = self::em($real, $esp['resgInv'] ?? null);
                $parcLanc  = self::em($real, $esp['parc'] ?? null);
                $usaMov    = true;
            } else {
                $p = self::previsaoDoMes($dados, $mes, $estado === 'aberto' && $regraAberto ? 'aberto' : 'previsto', $mesHoje, $override[$mes] ?? []);
                if ($estado === 'aberto' && $regraAberto) {
                    foreach ($p + $real as $c => $_) {
                        $cat = $cats[$c] ?? null;
                        if ($cat === null || $cat['operacional'] !== 1) {
                            continue;
                        }
                        if ($cat['sinal'] === 1) {
                            $entra += max((float) ($real[$c] ?? 0.0), (float) ($p[$c] ?? 0.0));   // D5 vale para entradas também
                        } else {
                            $sai += max((float) ($real[$c] ?? 0.0), (float) ($p[$c] ?? 0.0));
                        }
                    }
                    $cred      = max(self::em($real, $esp['cred']), self::em($p, $esp['cred']));
                    $amortReal = self::em($real, $esp['amort']);
                    $guard     = self::em($real, $esp['guard']);
                    $resg      = self::em($real, $esp['resg']);
                    $aplic     = self::em($real, $esp['aplic'] ?? null);
                    $resgInv   = self::em($real, $esp['resgInv'] ?? null);
                    $usaMov    = true;
                } else {
                    foreach ($p as $c => $v) {
                        $cat = $cats[$c] ?? null;
                        if ($cat !== null && $cat['operacional'] === 1) {
                            if ($cat['sinal'] === 1) { $entra += (float) $v; } else { $sai += (float) $v; }
                        }
                    }
                    $cred = self::em($p, $esp['cred']);
                }
            }
            if (!$ehReal && isset($extra[$mes])) {
                $x = (float) $extra[$mes];
                if ($x > 0) {
                    $entra += $x;
                } else {
                    $sai -= $x;
                }
            }
            if (!$ehReal && isset($adiantar[$mes])) {
                $amortReal += (float) $adiantar[$mes];
            }
            // somas de dinheiro em centavos: arredondar reproduz o SUM() do banco ao centavo
            $comp[] = [
                'mes' => $mes, 'situacao' => $m['situacao'], 'estado' => $estado, 'real' => $ehReal,
                'entra' => round($entra, 2), 'sai' => round($sai, 2), 'cred' => round($cred, 2),
                'amort' => round($amortReal, 2), 'mov' => $usaMov ? round($guard - $resg, 2) : 0.0,
                'inv' => $usaMov ? round($aplic - $resgInv, 2) : 0.0, 'parc' => round($parcLanc, 2),
            ];
        }

        // ---- passo 2: encadeamento
        $saldoIni    = (float) ($aj['saldo_ini'] ?? 0.0);
        $poupancaIni = (float) ($aj['poupanca_ini'] ?? 0.0);
        $investIni   = (float) ($aj['investimentos_ini'] ?? 0.0);
        $parcelaAParte = (int) round((float) ($aj['parcela_modo'] ?? 1.0)) === 2;
        $loanIni     = (float) $dados['loanIni'];
        $n = count($comp);
        $linhas = [];

        $aportes = $dados['aportes'] ?? [];
        $acertos = $dados['acertos'] ?? [];
        for ($i = 0; $i < $n; $i++) {
            $k = $comp[$i];
            // o saldo inicial de uma conta criada com meses fechados entra no começo do 1º mês aberto (ContaModel)
            $saldoIni += (float) ($aportes[$k['mes']] ?? 0.0);
            // o acerto de saldo do banco é dinheiro que entra ou sai da conta no mês dele, fora das categorias: conta no
            // balanço do mês (balancoTotal) e no que fica na conta, mas não no Entrou, no Gastou nem nas metas
            $acerto = round((float) ($acertos[$k['mes']] ?? 0.0), 2);
            if ($informado !== null && $k['mes'] === $informado['mes']) {
                $loanIni = $informado['valor'];
            }
            $antesDoSaldo = $informado !== null && $k['mes'] < $informado['mes'];

            // a parcela só começa a ser descontada (e a abater a dívida) a partir de ajustes.consignado_inicio
            $jurosV      = $loanIni > 0 ? $loanIni * $jurosMes : 0.0;
            $cobra       = $iniParcela === null || $k['mes'] >= $iniParcela;
            $parcelaPaga = ($cobra && $loanIni > 0.01) ? min($parcela, $loanIni + $jurosV) : 0.0;
            $la          = max(0.0, $loanIni + $jurosV - $parcelaPaga);

            // Fora de mês REAL, a parcela sai do salário previsto (ele chega líquido na conta) ou, se é paga
            // à parte, sai da conta. No REAL, valem os lançamentos: o salário líquido ou a categoria da parcela.
            $entra = $k['entra'];
            $parcelaDesc = 0.0;
            $parcelaParte = $k['parc'];
            if (!$k['real']) {
                if ($parcelaAParte) {
                    $parcelaParte = $parcelaPaga;
                } else {
                    $entra -= $parcelaPaga;
                    $parcelaDesc = $parcelaPaga;
                    $parcelaParte = 0.0;
                }
            }
            $resultadoOp = $entra - $k['sai'];
            $fluxo = $resultadoOp + $k['cred'] - $parcelaParte;
            $mov   = $k['mov'];
            $inv   = $k['inv'];

            if ($k['real']) {
                $adiantado = min($k['amort'], $la);
            } elseif (!$v2) {
                $base  = min($k['amort'], $la);
                $livre = $fluxo - $base - $mov - $inv;
                $adiantado = $base + ($livre <= 0 || $antesDoSaldo
                    ? 0.0
                    : min($la - $base, max(0.0, $livre - max(0.0, $reserva - $saldoIni - $acerto))));
            } else {
                $base = min($k['amort'], $la);
                $auto = 0.0;
                if ($objetivo !== 3 && !$antesDoSaldo) {
                    // olha à frente: o menor saldo projetado (sem novos adiantamentos) acima da reserva
                    $proj = $saldoIni + $acerto + $fluxo - $base - $mov - $inv;
                    $melhor = $proj - $reserva;
                    $ll = $la - $base;
                    for ($q = $i + 1; $q < $n; $q++) {
                        $kq = $comp[$q];
                        $jq = $ll > 0 ? $ll * $jurosMes : 0.0;
                        $pq = (($iniParcela === null || $kq['mes'] >= $iniParcela) && $ll > 0.01) ? min($parcela, $ll + $jq) : 0.0;
                        $ll = max(0.0, $ll + $jq - $pq);
                        $bq = min($kq['amort'], $ll);
                        $ll -= $bq;
                        $proj += ($kq['real'] ? $kq['entra'] - $kq['parc'] : $kq['entra'] - $pq) - $kq['sai'] + $kq['cred'] - $bq - $kq['mov'] - $kq['inv'];
                        if ($proj - $reserva < $melhor) {
                            $melhor = $proj - $reserva;
                        }
                    }
                    $auto = min($la - $base, max(0.0, $melhor));
                }
                $adiantado = $base + $auto;
            }

            $fica    = $saldoIni + $acerto + $fluxo - $adiantado - $mov - $inv;
            $loanFim = $la - $adiantado;
            $poupFim = $poupancaIni + $mov;
            $investFim = $investIni + $inv;
            $total   = $fica + $poupFim;

            $linhas[$k['mes']] = [
                'mes'                 => $k['mes'],
                'situacao'            => $k['situacao'],
                'estado'              => $k['estado'],
                'sobrou_mes_anterior' => $saldoIni,
                'entra'               => $entra,
                'entra_bruto'         => $k['entra'],
                'sai'                 => $k['sai'],
                'resultado_op'        => $resultadoOp,
                'parcela_descontada'  => $parcelaDesc,
                'parcela_parte'       => $parcelaParte,
                'juros'               => $jurosV,
                'credito_emprestimo'  => $k['cred'],
                'amortizacao_extra'   => $adiantado,
                // a parte que o plano sugere sozinho (não foi lançada nem prevista): a sobra não a conta como saída
                'amortizacao_auto'    => $k['real'] ? 0.0 : max(0.0, $adiantado - min($k['amort'], $la)),
                'poupanca_liquido'    => $mov,
                'invest_liquido'      => $inv,
                'acerto'              => $acerto,
                'fica_na_conta'       => $fica,
                'falta_pagar'         => $loanFim,
                'poupanca_saldo'      => $poupFim,
                'invest_saldo'        => $investFim,
                'total'               => $total,
                'quitado'             => $loanFim <= 0.01,
                'bateu_reserva'       => $fica >= $reserva,
                'bateu_meta'          => $meta > 0.004 && $total >= $meta,
            ];

            $saldoIni    = $fica;
            $loanIni     = $loanFim;
            $poupancaIni = $poupFim;
            $investIni   = $investFim;
        }

        return $linhas;
    }

    private static function em(array $valores, ?int $cat): float
    {
        return $cat === null ? 0.0 : (float) ($valores[$cat] ?? 0.0);
    }

    /** ajustes.consignado_inicio (AAAAMM) → 'Y-m-01'; vazio/0 = a parcela desconta desde o primeiro mês. */
    public static function mesInicioParcela(array $aj): ?string
    {
        return self::mesDe((float) ($aj['consignado_inicio'] ?? 0));
    }

    /**
     * O saldo devedor informado (o que o extrato diz que falta antes da parcela do mês), ou null.
     * @return ?array{mes:string, valor:float}
     */
    public static function saldoInformado(array $aj): ?array
    {
        $mes   = self::mesDe((float) ($aj['saldo_devedor_mes'] ?? 0));
        $valor = (float) ($aj['saldo_devedor'] ?? 0);
        return $mes !== null && $valor > 0 ? ['mes' => $mes, 'valor' => $valor] : null;
    }

    /** AAAAMM → 'Y-m-01'; vazio ou inválido = null. */
    private static function mesDe(float $aaaamm): ?string
    {
        $v = (int) round($aaaamm);
        if ($v < 190001 || $v > 299912 || $v % 100 < 1 || $v % 100 > 12) {
            return null;
        }
        return sprintf('%04d-%02d-01', intdiv($v, 100), $v % 100);
    }

    /**
     * Plano com as opções padrão (MOTOR_V2 do .env, mês aberto ligado, objetivo gravado, hoje).
     * O cache estático vale só para override vazio.
     */
    public static function calcularPlano(array $override = []): array
    {
        if ($override !== []) {
            return self::calcular(self::dados(), ['override' => $override]);
        }
        return self::$planoCache ??= self::calcular(self::dados());
    }

    public static function linhaDoMes(string $mes): ?array
    {
        return self::calcularPlano()[$mes] ?? null;
    }

    /**
     * Balanço "de verdade": tudo que entrou (salário/outras/empréstimo/resgates) menos tudo
     * que saiu (gastos/amortização extra/guardado na poupança/aplicado em investimentos) — fluxo de caixa completo do
     * mês. Único lugar onde essa conta é feita; toda tela que mostra "balanço" usa este método.
     * @return array{0:float,1:float,2:float} [entradas, saidas, balanco]
     */
    public static function balancoTotal(array $linha): array
    {
        $inv = (float) ($linha['invest_liquido'] ?? 0.0);
        $acerto = (float) ($linha['acerto'] ?? 0.0);
        $entradas = $linha['entra'] + $linha['credito_emprestimo'] + max(0, -$linha['poupanca_liquido']) + max(0, -$inv) + max(0.0, $acerto);
        $saidas   = $linha['sai'] + $linha['amortizacao_extra'] + max(0, $linha['poupanca_liquido']) + max(0, $inv) + (float) ($linha['parcela_parte'] ?? 0.0) + max(0.0, -$acerto);
        return [$entradas, $saidas, $entradas - $saidas];
    }

    public static function saldoCalculadoHoje(): float
    {
        $saldoIni = AjusteModel::valor('saldo_ini');
        return LancamentoModel::saldoCalculado($saldoIni, Relogio::hoje());
    }

    /** Mês com o menor "fica na conta" de toda a projeção. */
    public static function mesMaisApertado(): ?array
    {
        $plano = self::calcularPlano();
        if (empty($plano)) {
            return null;
        }
        return array_reduce($plano, fn($carry, $linha) =>
            $carry === null || $linha['fica_na_conta'] < $carry['fica_na_conta'] ? $linha : $carry
        );
    }

    public static function mesQueEmprestimoQuita(): ?string
    {
        return self::quitaEm(self::calcularPlano());
    }

    public static function mesQueBateMeta(): ?string
    {
        foreach (self::calcularPlano() as $mes => $linha) {
            if ($linha['bateu_meta']) {
                return $mes;
            }
        }
        return null;
    }

    /** Primeiro mês de um plano qualquer (saída de calcular()) com a dívida quitada. */
    public static function quitaEm(array $plano): ?string
    {
        foreach ($plano as $mes => $linha) {
            if ($linha['quitado']) {
                return $mes;
            }
        }
        return null;
    }

    public static function ultimoMes(): ?array
    {
        $plano = self::calcularPlano();
        return $plano === [] ? null : end($plano);
    }
}
