<?php
namespace Financeiro\Services;

use Financeiro\Controllers\DashboardController;
use Financeiro\Database;
use Financeiro\Models\AjusteModel;
use Financeiro\Models\CartaoModel;
use Financeiro\Relogio;

/**
 * Os fatos dos relatórios, os mesmos na tela e no PDF. Juízo só sobre mês encerrado, porque o plano do
 * mês em andamento usa o maior entre lançado e planejado. Regra sem base se cala: com pouco histórico,
 * sai só planejado contra real. Toda frase é "categoria: fato", sem gênero nem pessoa.
 */
final class RelatorioDados
{
    /** Diferença de categoria só conta a partir disto: da renda do período e do planejado dela. */
    private const RELEVANTE_RENDA = 0.02;
    private const RELEVANTE_PLANO = 0.10;
    private const ENTRADA_ABAIXO = 0.05;
    private const ACIMA_DA_MEDIA = 0.25;
    private const MAX_ITENS = 2;
    private const MAX_CATEGORIAS = 8;
    private const ENCERRADOS_PARA_MEDIA = 3;
    private const ENCERRADOS_PARA_HEATMAP = 6;
    private const COMPARACOES_PARA_PLACAR_ANUAL = 3;

    /** Os períodos que dá para escolher. @return array{meses:list<array{mes:string,estado:string}>, anos:list<int>, mesPadrao:?string, anoPadrao:?int} */
    public static function periodos(?string $hoje = null): array
    {
        $hoje ??= Relogio::hoje();
        $mesHoje = substr($hoje, 0, 8) . '01';
        $meses = [];
        $anos = [];
        $padrao = null;
        foreach (MotorFinanceiro::calcular(MotorFinanceiro::dados(), ['hoje' => $hoje]) as $m => $l) {
            if ($m > $mesHoje) {
                break;
            }
            $meses[] = ['mes' => (string) $m, 'estado' => (string) $l['estado']];
            $anos[(int) substr((string) $m, 0, 4)] = true;
            if ($l['estado'] === 'real') {
                $padrao = (string) $m;
            }
        }
        $ultimo = $meses === [] ? null : $meses[count($meses) - 1]['mes'];
        return ['meses' => array_reverse($meses), 'anos' => array_reverse(array_keys($anos)), 'mesPadrao' => $padrao ?? $ultimo,
                'anoPadrao' => $ultimo !== null ? (int) substr($ultimo, 0, 4) : null];
    }

    /* ================================================================== mensal */

    /**
     * @param bool $porFatura o "Para onde foi" com o cartão pela fatura paga no mês, como o plano; o padrão, com o
     *   cartão aberto (GastoPorCategoria::ativo()), conta cada compra na categoria dela, pela data da compra
     */
    public static function mensal(string $mes, ?string $hoje = null, bool $porFatura = false): ?array
    {
        $hoje ??= Relogio::hoje();
        $dados = MotorFinanceiro::dados();
        $plano = MotorFinanceiro::calcular($dados, ['hoje' => $hoje]);
        $l = $plano[$mes] ?? null;
        if ($l === null || $mes > substr($hoje, 0, 8) . '01') {
            return null;
        }
        $encerrado = $l['estado'] === 'real';
        $reserva = (float) ($dados['aj']['reserva_minima'] ?? 0.0);
        // a mesma sobra das outras telas (Sobra): no mês encerrado, a do mês; em andamento, a prevista e a até hoje
        $sobraPlano = Sobra::prevista($l);
        $sobrou = $sobraPlano['sobra'];
        $porCompra = !$porFatura && GastoPorCategoria::ativo();
        // o que já aconteceu é o realizado: no mês em andamento, o agendado (data depois de hoje) fica de fora do "Para
        // onde foi", do "Gastou até agora" e da fatura até o dia dele, e continua no planejado (MotorFinanceiro::realizado)
        $feito = MotorFinanceiro::realizado($hoje);
        // o "Para onde foi" e o que foi bom ou ruim usam a mesma conta: com o cartão aberto, pela data da compra
        $cats = self::categoriasDoMes($feito, $mes, $porCompra, $hoje);
        $encerradosAntes = self::encerradosAte($plano, $mes);
        $achados = $encerrado
            ? self::achadosDoMes($dados, $plano, $mes, $l, $sobrou, $cats, $encerradosAntes, $reserva, $hoje, $porCompra)
            : ['bom' => [], 'ruim' => [], 'melhorar' => [], 'conferir' => []];
        $nomeMes = self::maiuscula(mesExtenso($mes));
        $ano = substr($mes, 0, 4);
        return [
            'tipo' => 'mensal', 'mes' => $mes, 'estado' => (string) $l['estado'], 'encerrado' => $encerrado, 'gerado' => Relogio::agora()->format('Y-m-d H:i'), 'hoje' => $hoje,
            'titulo' => t('{mes} de {ano}', ['mes' => $nomeMes, 'ano' => $ano]),
            'situacao' => $encerrado ? t('Mês encerrado') : t('Mês em andamento: lançado até {data}, o resto é planejado', ['data' => diaMes(min($hoje, date('Y-m-t', (int) strtotime($mes))))]),
            'manchete' => self::manchete($sobrou, $encerrado, (float) $l['fica_na_conta'], (float) $l['poupanca_liquido'] + (float) ($l['invest_liquido'] ?? 0.0)),
            'sobrou' => $sobrou, 'entrou' => (float) $l['entra'], 'saiu' => (float) $l['sai'], 'fica' => (float) $l['fica_na_conta'],
            'sobras' => $encerrado ? [$sobraPlano] : array_values(array_filter([Sobra::ateHoje($mes, $hoje), $sobraPlano])),
            'reserva' => $reserva, 'conferencia' => self::conferencia($dados, $mes, $hoje),
            'ponte' => $encerrado ? self::ponteEncerrado($l) : self::ponteAndamento($feito, $l),
            'divida' => self::divida($dados, $l),
            'achados' => $achados,
            'categorias' => self::topCategorias($cats), 'fatura' => self::fatura($feito, $mes, $hoje),
            'porCompra' => $porCompra, 'cartaoAberto' => GastoPorCategoria::ativo(),
            'semCategoria' => $porCompra ? GastoPorCategoria::semCategoria($mes, $hoje) : null,
            'placar' => $encerrado ? self::placar($dados, $plano, $mes) : null,
            'placarInicio' => FotoPlano::primeiroMes(),
            'previsao' => self::previsao($dados, $plano, $mes, $hoje, 6),
            'autonomia' => self::autonomia($dados, $plano, $mes, $encerradosAntes),
            'temCartao' => self::cartoes() !== [], 'temDivida' => (float) $l['falta_pagar'] > 0.004 || (float) ($dados['aj']['parcela_mensal'] ?? 0) > 0.004,
            'tendencia' => self::tendencia($plano, $mes), 'ritmo' => self::ritmo($mes, $hoje),
        ];
    }

    /** Até seis meses, terminando neste: como este mês se compara. */
    private static function tendencia(array $plano, string $mes): array
    {
        $saida = [];
        foreach ($plano as $m => $l) {
            if ($m <= $mes) {
                $saida[] = ['mes' => (string) $m, 'estado' => (string) $l['estado'], 'entrou' => (float) $l['entra'], 'saiu' => (float) $l['sai']];
            }
        }
        return array_slice($saida, -6);
    }

    /** Gasto acumulado dia a dia, neste mês e no anterior. @return ?array{dias:int, atual:list<float>, anterior:list<float>, mes:string, mesAnterior:string} */
    private static function ritmo(string $mes, string $hoje): ?array
    {
        $ant = date('Y-m-01', strtotime($mes . ' -1 month'));
        $st = Database::connection()->prepare(
            "SELECT l.data, SUM(l.valor) AS total FROM lancamentos l JOIN categorias c ON c.id = l.categoria_id
             WHERE c.operacional = 1 AND c.sinal = -1 AND l.data >= ? AND l.data <= ? AND l.data <= ? GROUP BY l.data"
        );
        $st->execute([$ant, date('Y-m-t', (int) strtotime($mes)), $hoje]);
        $dia = [];
        foreach ($st as $r) {
            $dia[substr((string) $r['data'], 0, 7)][(int) substr((string) $r['data'], 8, 2)] = (float) $r['total'];
        }
        $acumula = static function (array $porDia, int $ate): array {
            $soma = 0.0;
            $out = [];
            for ($d = 1; $d <= $ate; $d++) {
                $soma += (float) ($porDia[$d] ?? 0.0);
                $out[] = round($soma, 2);
            }
            return $out;
        };
        $chave = substr($mes, 0, 7);
        $chaveAnt = substr($ant, 0, 7);
        if (empty($dia[$chave])) {
            return null;
        }
        $diasMes = diasNoMes($mes);
        $ate = substr($hoje, 0, 7) === $chave ? (int) substr($hoje, 8, 2) : $diasMes;
        return ['dias' => max($diasMes, diasNoMes($ant)), 'atual' => $acumula($dia[$chave], $ate), 'anterior' => empty($dia[$chaveAnt]) ? [] : $acumula($dia[$chaveAnt], diasNoMes($ant)),
                'mes' => $mes, 'mesAnterior' => $ant];
    }

    /**
     * A frase-resultado do topo. A sobra é o balanço da conta (Sobra), em que o guardado (poupança e investimento) sai;
     * por isso a frase separa o gasto do guardado: num mês em que a casa guardou, a conta que fica menor não é falta.
     *   guardou e ainda sobrou:            "Guardou R$ 500,00 e ainda sobraram R$ 104,06."
     *   guardou mais do que sobrou do gasto: "Guardou R$ 500,00; R$ 195,94 vieram do que já estava na conta."
     *   gastou mais do que entrou:          "Faltaram R$ 80,00." (com o guardado: "…, sem contar os R$ 500,00 guardados.")
     * @param float $guardou o que foi guardado e aplicado, já sem o que foi tirado (negativo: tirou)
     */
    public static function manchete(float $sobrou, bool $encerrado, float $fica, float $guardou = 0.0): string
    {
        if (!$encerrado) {
            return t('Pelo planejado, o mês termina com {valor} na conta.', ['valor' => money($fica)]);
        }
        $guardou = round(max(0.0, $guardou), 2);
        if ($sobrou > 0.004) {
            return $guardou > 0.004 ? t('Guardou {guardado} e ainda sobraram {valor}.', ['guardado' => money($guardou), 'valor' => money($sobrou)])
                : t('Sobraram {valor}.', ['valor' => money($sobrou)]);
        }
        if ($sobrou < -0.004) {
            if ($guardou > 0.004 && $sobrou + $guardou >= -0.004) {
                return t('Guardou {guardado}; {valor} vieram do que já estava na conta.', ['guardado' => money($guardou), 'valor' => money(abs($sobrou))]);
            }
            return $guardou > 0.004 ? t('Faltaram {valor}, sem contar os {guardado} guardados.', ['valor' => money(abs($sobrou + $guardou)), 'guardado' => money($guardou)])
                : t('Faltaram {valor}.', ['valor' => money(abs($sobrou))]);
        }
        return $guardou > 0.004 ? t('Guardou {guardado}, e entrou o mesmo que saiu.', ['guardado' => money($guardou)]) : t('Entrou o mesmo que saiu.');
    }

    /** O acerto de saldo com o banco do mês, logo depois do "Começou com", quando houver (AcertoModel). */
    private static function ponteAcerto(array $l): array
    {
        $v = round((float) ($l['acerto'] ?? 0.0), 2);
        return abs($v) >= 0.005 ? [['rotulo' => t('Acerto de saldo com o banco'), 'valor' => $v, 'tipo' => 'mov', 'classe' => $v < 0 ? 'gasto' : 'entrada']] : [];
    }

    /** @return list<array{rotulo:string, valor:float, tipo:string}> uma escada que fecha no centavo */
    private static function ponteEncerrado(array $l): array
    {
        $r = [['rotulo' => t('Começou com'), 'valor' => (float) $l['sobrou_mes_anterior'], 'tipo' => 'inicio', 'classe' => 'total']];
        $r = array_merge($r, self::ponteAcerto($l));
        $r[] = ['rotulo' => t('Entrou'), 'valor' => (float) $l['entra'], 'tipo' => 'mov', 'classe' => 'entrada'];
        $r[] = ['rotulo' => t('Gastou'), 'valor' => -(float) $l['sai'], 'tipo' => 'mov', 'classe' => 'gasto'];
        return array_merge($r, self::ponteResto($l));
    }

    private static function ponteAndamento(array $dados, array $l): array
    {
        $mes = (string) $l['mes'];
        $lanEnt = $lanSai = 0.0;
        foreach ($dados['real'][$mes] ?? [] as $c => $v) {
            $cat = $dados['cats'][$c] ?? null;
            if ($cat !== null && $cat['operacional'] === 1) {
                if ($cat['sinal'] === 1) {
                    $lanEnt += (float) $v;
                } else {
                    $lanSai += (float) $v;
                }
            }
        }
        $r = [['rotulo' => t('Começou com'), 'valor' => (float) $l['sobrou_mes_anterior'], 'tipo' => 'inicio', 'classe' => 'total']];
        $r = array_merge($r, self::ponteAcerto($l));
        $r[] = ['rotulo' => t('Entrou até agora'), 'valor' => $lanEnt, 'tipo' => 'mov', 'classe' => 'entrada'];
        $falta = round((float) $l['entra'] - $lanEnt, 2);
        if (abs($falta) >= 0.005) {
            $r[] = ['rotulo' => t('Ainda planejado para entrar'), 'valor' => $falta, 'tipo' => 'plan', 'classe' => 'entrada'];
        }
        $r[] = ['rotulo' => t('Gastou até agora'), 'valor' => -$lanSai, 'tipo' => 'mov', 'classe' => 'gasto'];
        $falta = round((float) $l['sai'] - $lanSai, 2);
        if (abs($falta) >= 0.005) {
            $r[] = ['rotulo' => t('Ainda planejado para gastar'), 'valor' => -$falta, 'tipo' => 'plan', 'classe' => 'gasto'];
        }
        return array_merge($r, self::ponteResto($l, t('Termina com, pelo planejado')));
    }

    private static function ponteResto(array $l, ?string $fim = null): array
    {
        $r = [];
        // classe: como a tela pinta a linha. O guardado e o empréstimo são neutros: não são gasto (redesenho, § 6.7)
        $add = static function (string $rotulo, float $v, string $classe) use (&$r): void {
            if (abs($v) >= 0.005) {
                $r[] = ['rotulo' => $rotulo, 'valor' => $v, 'tipo' => 'mov', 'classe' => $classe];
            }
        };
        $add(t('Crédito do empréstimo'), (float) $l['credito_emprestimo'], 'divida');
        $add(t('Parcela do empréstimo'), -(float) $l['parcela_parte'], 'divida');
        $add(t('Adiantou do empréstimo'), -(float) $l['amortizacao_extra'], 'divida');
        $mov = (float) $l['poupanca_liquido'];
        $add($mov >= 0 ? t('Guardou na poupança') : t('Tirou da poupança'), -$mov, 'guardado');
        $inv = (float) ($l['invest_liquido'] ?? 0.0);
        $add($inv >= 0 ? t('Aplicou em investimentos') : t('Resgatou de investimentos'), -$inv, 'guardado');
        $r[] = ['rotulo' => $fim ?? t('Terminou com'), 'valor' => (float) $l['fica_na_conta'], 'tipo' => 'fim', 'classe' => 'total'];
        return $r;
    }

    /** A dívida tem a própria conta: juros aumentam, parcela e adiantamento diminuem. */
    private static function divida(array $dados, array $l): ?array
    {
        $fim = (float) $l['falta_pagar'];
        $juros = (float) $l['juros'];
        $adiantado = (float) $l['amortizacao_extra'];
        if ($fim <= 0.004 && $juros <= 0.004 && $adiantado <= 0.004) {
            return null;
        }
        $parcela = (float) ($l['parcela_descontada'] ?: $l['parcela_parte']);
        return ['fim' => $fim, 'juros' => $juros, 'adiantado' => $adiantado, 'parcela' => $parcela];
    }

    /**
     * Os gastos do mês, em ordem de valor. $porCompra: o real é o de GastoPorCategoria, sem negativo; a Fatura do cartão
     * fica só com o que não tem compra registrada ("Fatura sem detalhe", sem planejado, porque o plano dela é o da
     * fatura inteira), e as compras sem categoria são uma linha a mais (id GastoPorCategoria::SEM_CATEGORIA).
     * $dados: o realizado (MotorFinanceiro::realizado), para o agendado ficar de fora no mês em andamento.
     * @return list<array{id:int,nome:string,nome_banco:string,grupo:string,essencial:bool,planejado:float,real:float,dif:float,semPlano?:bool}>
     */
    private static function categoriasDoMes(array $dados, string $mes, bool $porCompra = false, ?string $hoje = null): array
    {
        $out = [];
        $prev = $dados['prev'][$mes] ?? [];
        $real = $porCompra ? GastoPorCategoria::doMes($mes, $hoje) : ($dados['real'][$mes] ?? []);
        $fat = $porCompra ? GastoPorCategoria::categoriaFatura() : null;
        foreach ($prev + $real as $c => $_) {
            if ($porCompra && $c === GastoPorCategoria::SEM_CATEGORIA) {
                $r = max(0.0, (float) $real[$c]);
                if ($r > 0.004) {
                    $out[] = ['id' => $c, 'nome' => t('Cartão sem categoria'), 'nome_banco' => '', 'grupo' => 'Cartao', 'essencial' => false,
                              'planejado' => 0.0, 'real' => $r, 'dif' => $r, 'semPlano' => true];
                }
                continue;
            }
            $cat = $dados['cats'][$c] ?? null;
            if ($cat === null || $cat['operacional'] !== 1 || $cat['sinal'] !== -1) {
                continue;
            }
            $p = $c === $fat ? 0.0 : round((float) ($prev[$c] ?? 0.0), 2);
            $r = round((float) ($real[$c] ?? 0.0), 2);
            if ($porCompra) {
                $r = max(0.0, $r);
            }
            if ($p <= 0.004 && $r <= 0.004) {
                continue;
            }
            $out[] = ['id' => (int) $c, 'nome' => $c === $fat ? t('Fatura sem detalhe') : nomeCurto($cat), 'nome_banco' => $cat['nome'], 'grupo' => $cat['grupo'],
                      'essencial' => $cat['essencial'] === 1, 'planejado' => $p, 'real' => $r, 'dif' => round($r - $p, 2)] + ($c === $fat ? ['semPlano' => true] : []);
        }
        usort($out, static fn($a, $b) => [$b['real'], $b['planejado']] <=> [$a['real'], $a['planejado']]);
        return $out;
    }

    private static function topCategorias(array $cats): array
    {
        $top = array_slice($cats, 0, self::MAX_CATEGORIAS);
        $resto = array_slice($cats, self::MAX_CATEGORIAS);
        return ['linhas' => $top, 'outros' => $resto === [] ? null : [
            'n' => count($resto), 'planejado' => round(array_sum(array_column($resto, 'planejado')), 2), 'real' => round(array_sum(array_column($resto, 'real')), 2),
        ], 'totalPlanejado' => round(array_sum(array_column($cats, 'planejado')), 2), 'totalReal' => round(array_sum(array_column($cats, 'real')), 2)];
    }

    private static function encerradosAte(array $plano, string $mes): array
    {
        $out = [];
        foreach ($plano as $m => $l) {
            if ($m < $mes && $l['estado'] === 'real') {
                $out[] = (string) $m;
            }
        }
        return $out;
    }

    /* ------------------------------------------------------------------ o que foi bom, ruim e pode melhorar */

    private static function relevante(array $c, float $renda): bool
    {
        $d = abs($c['dif']);
        return $renda > 0.004 && $d >= $renda * self::RELEVANTE_RENDA && ($c['planejado'] <= 0.004 || $d >= $c['planejado'] * self::RELEVANTE_PLANO);
    }

    /** $cats: as do "Para onde foi" (categoriasDoMes); $porCompra diz como os meses anteriores são lidos. */
    private static function achadosDoMes(array $dados, array $plano, string $mes, array $l, float $sobrou, array $cats, array $antes, float $reserva, string $hoje, bool $porCompra = false): array
    {
        $renda = (float) $l['entra'];
        $bom = $ruim = $conferir = [];
        if ($sobrou > 0.004) {
            $por100 = $renda > 0.004 ? (int) round($sobrou / $renda * 100) : 0;
            $bom[] = ['peso' => $sobrou, 'texto' => $por100 > 0
                ? t('Sobraram {valor}: {n} de cada 100 {moeda} que entraram.', ['valor' => money($sobrou), 'n' => $por100, 'moeda' => moedaPlural()])
                : t('Sobraram {valor}.', ['valor' => money($sobrou)])];
        } elseif ($sobrou < -0.004) {
            // a sobra conta o guardado como saída: só é ruim o que passou do que entrou mesmo sem guardar (§ 7.3 do redesenho)
            $semGuardar = round($sobrou + max(0.0, (float) $l['poupanca_liquido'] + (float) ($l['invest_liquido'] ?? 0.0)), 2);
            if ($semGuardar < -0.004) {
                $ruim[] = ['peso' => abs($semGuardar), 'texto' => t('Os gastos passaram o que entrou em {valor}, sem contar o que foi guardado.', ['valor' => money(abs($semGuardar))])];
            }
        }
        foreach ($cats as $c) {
            if (!empty($c['semPlano'])) {                  // "Fatura sem detalhe" e "Cartão sem categoria" não têm plano para comparar
                continue;
            }
            if ($c['planejado'] > 0.004 && $c['real'] <= 0.004) {
                if ($c['planejado'] >= $renda * self::RELEVANTE_RENDA) {
                    $conferir[] = ['peso' => $c['planejado'], 'texto' => t('{categoria}: {valor} planejados e nada lançado. Vale conferir se faltou lançar.', ['categoria' => $c['nome'], 'valor' => money($c['planejado'])])];
                }
                continue;
            }
            if (!self::relevante($c, $renda)) {
                continue;
            }
            if ($c['dif'] < 0) {
                $bom[] = ['peso' => abs($c['dif']), 'texto' => t('{categoria}: {valor} abaixo do planejado.', ['categoria' => $c['nome'], 'valor' => money(abs($c['dif']))])];
            } elseif ($c['planejado'] <= 0.004) {
                $ruim[] = ['peso' => $c['dif'], 'texto' => t('{categoria}: {valor} sem nada planejado.', ['categoria' => $c['nome'], 'valor' => money($c['real'])])];
            } elseif ($c['grupo'] !== 'Ano') {                 // conta pontual oscila de mês a mês: o anual é que julga
                $ruim[] = ['peso' => $c['dif'], 'texto' => self::textoAcima($dados, $c, $antes, $porCompra)];
            }
        }
        if ($reserva > 0.004 && (float) $l['fica_na_conta'] >= $reserva) {
            $bom[] = ['peso' => 0.0, 'texto' => t('A conta terminou acima do mínimo de {valor}.', ['valor' => money($reserva)])];
        }
        $guardou = (float) $l['poupanca_liquido'] + (float) ($l['invest_liquido'] ?? 0.0);
        if ($guardou > 0.004) {
            $bom[] = ['peso' => $guardou, 'texto' => t('Guardou ou aplicou {valor}.', ['valor' => money($guardou)])];
        }
        if ((float) $l['amortizacao_extra'] > 0.004) {
            $bom[] = ['peso' => (float) $l['amortizacao_extra'], 'texto' => t('Adiantou {valor} do empréstimo, além da parcela.', ['valor' => money((float) $l['amortizacao_extra'])])];
        }
        $prevEntra = self::somaEntradas($dados, $dados['prev'][$mes] ?? []);
        $realEntra = self::somaEntradas($dados, $dados['real'][$mes] ?? []);
        if ($prevEntra > 0.004 && $realEntra < $prevEntra * (1 - self::ENTRADA_ABAIXO)) {
            $ruim[] = ['peso' => $prevEntra - $realEntra, 'texto' => t('Entrou {valor} a menos do que o planejado.', ['valor' => money($prevEntra - $realEntra)])];
        }
        $aviso = self::alertaProximos($plano, $mes, $reserva);
        if ($aviso !== null) {
            $ruim[] = $aviso;
        }
        return ['bom' => self::maiores($bom), 'ruim' => self::maiores($ruim), 'conferir' => self::maiores($conferir),
                'melhorar' => self::melhorar($dados, $plano, $mes, $cats, $renda, $reserva, $hoje)];
    }

    private static function textoAcima(array $dados, array $c, array $antes, bool $porCompra = false): string
    {
        $base = t('{categoria}: {valor} acima do planejado', ['categoria' => $c['nome'], 'valor' => money($c['dif'])]);
        $real = static fn(string $m): float => (float) ($porCompra ? max(0.0, GastoPorCategoria::doMes($m)[$c['id']] ?? 0.0) : ($dados['real'][$m][$c['id']] ?? 0.0));
        $ultimo = $antes === [] ? null : end($antes);
        if ($ultimo !== null) {
            $rAntes = $real($ultimo);
            $pAntes = (float) ($dados['prev'][$ultimo][$c['id']] ?? 0.0);
            if ($rAntes > $pAntes * (1 + self::RELEVANTE_PLANO) && $pAntes > 0.004) {
                return t('{fato}, pelo segundo mês seguido.', ['fato' => $base]);
            }
        }
        if (count($antes) >= self::ENCERRADOS_PARA_MEDIA) {
            $soma = 0.0;
            foreach ($antes as $m) {
                $soma += $real($m);
            }
            $media = $soma / count($antes);
            if ($media > 0.004 && $c['real'] > $media * (1 + self::ACIMA_DA_MEDIA)) {
                return t('{fato}, e {valor} acima da média dos meses anteriores.', ['fato' => $base, 'valor' => money($c['real'] - $media)]);
            }
        }
        return t('{fato}.', ['fato' => $base]);
    }

    private static function somaEntradas(array $dados, array $valores): float
    {
        $s = 0.0;
        foreach ($valores as $c => $v) {
            $cat = $dados['cats'][$c] ?? null;
            if ($cat !== null && $cat['operacional'] === 1 && $cat['sinal'] === 1) {
                $s += (float) $v;
            }
        }
        return $s;
    }

    private static function alertaProximos(array $plano, string $mes, float $reserva): ?array
    {
        $vistos = 0;
        foreach ($plano as $m => $l) {
            if ($m <= $mes) {
                continue;
            }
            if (++$vistos > 3) {
                break;
            }
            $fica = (float) $l['fica_na_conta'];
            if ($fica < -0.004) {
                return ['peso' => abs($fica), 'texto' => t('Pelo planejado, a conta fica negativa em {mes}: {valor}.', ['mes' => mesExtenso((string) $m), 'valor' => money($fica)])];
            }
            if ($reserva > 0.004 && $fica < $reserva - 0.004) {
                return ['peso' => $reserva - $fica, 'texto' => t('Pelo planejado, a conta fica abaixo do mínimo em {mes}: {valor}.', ['mes' => mesExtenso((string) $m), 'valor' => money($fica)])];
            }
        }
        return null;
    }

    /** @return list<string> os maiores, sem os pesos */
    private static function maiores(array $itens): array
    {
        usort($itens, static fn($a, $b) => $b['peso'] <=> $a['peso']);
        return array_column(array_slice($itens, 0, self::MAX_ITENS), 'texto');
    }

    /**
     * Só aritmética: o plano com 10% a menos numa categoria não essencial do dia a dia. Entra se muda
     * algo: termina de pagar antes, tira um mês de baixo do mínimo ou, sem dívida, deixa a conta maior.
     * @return list<string>
     */
    private static function melhorar(array $dados, array $plano, string $ref0, array $cats, float $renda, float $reserva, string $hoje): array
    {
        $parcela = (float) ($dados['aj']['parcela_mensal'] ?? 0.0);
        $juros = (float) ($dados['aj']['juros_mes'] ?? 0.0);
        $ref = max(date('Y-m-01', strtotime($ref0 . ' +1 month')), substr($hoje, 0, 8) . '01');
        $futuros = array_values(array_filter(array_keys($plano), static fn($m) => $m >= $ref && $plano[$m]['estado'] !== 'real'));
        if ($futuros === []) {
            return [];
        }
        $base = AnualResumo::numeros($plano, $reserva, $ref, $parcela, $juros);
        $candidatas = array_values(array_filter($cats, static fn($c) => !$c['essencial'] && $c['grupo'] === 'Dia a dia' && $c['real'] >= $renda * self::RELEVANTE_RENDA));
        $out = [];
        foreach (array_slice($candidatas, 0, 3) as $c) {
            $corte = round($c['real'] * 0.10, 2);
            if ($corte < 1.0) {
                continue;
            }
            $sim = MotorFinanceiro::calcular($dados, ['hoje' => $hoje, 'extra' => array_fill_keys($futuros, $corte)]);
            $novo = AnualResumo::numeros($sim, $reserva, $ref, $parcela, $juros);
            $meses = $base['quita'] !== null && $novo['quita'] !== null ? AnualResumo::mesesEntre($novo['quita'], $base['quita']) : 0;
            if ($meses > 0) {
                $out[] = tn($meses, '{categoria}: {valor} a menos por mês termina de pagar o empréstimo 1 mês antes e evita {juros} de juros.', '{categoria}: {valor} a menos por mês termina de pagar o empréstimo {n} meses antes e evita {juros} de juros.',
                    ['categoria' => $c['nome'], 'valor' => money($corte), 'juros' => money(max(0.0, (float) $base['juros'] - (float) $novo['juros']))]);
            } elseif ($novo['abaixo'] < $base['abaixo']) {
                $out[] = t('{categoria}: {valor} a menos por mês tira {n} meses de baixo do mínimo na conta.', ['categoria' => $c['nome'], 'valor' => money($corte), 'n' => $base['abaixo'] - $novo['abaixo']]);
            } elseif ($base['juros'] !== null && $novo['juros'] !== null && (float) $base['juros'] - (float) $novo['juros'] >= 5.0) {
                $out[] = t('{categoria}: {valor} a menos por mês evita {juros} de juros do empréstimo.', ['categoria' => $c['nome'], 'valor' => money($corte), 'juros' => money((float) $base['juros'] - (float) $novo['juros'])]);
            } elseif ($base['quita'] === null || !$dados['loanIni']) {
                $mesFim = $futuros[min(11, count($futuros) - 1)];
                $ganho = (float) $sim[$mesFim]['fica_na_conta'] - (float) $plano[$mesFim]['fica_na_conta'];
                if ($ganho > 0.004) {
                    $out[] = t('{categoria}: {valor} a menos por mês deixa {total} a mais na conta em {mes}.', ['categoria' => $c['nome'], 'valor' => money($corte), 'total' => money($ganho), 'mes' => monthLabel($mesFim)]);
                }
            }
            if (count($out) >= self::MAX_ITENS) {
                break;
            }
        }
        return $out;
    }

    /* ------------------------------------------------------------------ conferência, fatura e placar */

    /** A última conferência do mês até hoje, a mesma do Início (DashboardController::conferenciaAtual). */
    private static function conferencia(array $dados, string $mes, string $hoje): array
    {
        $c = DashboardController::conferenciaAtual(min($hoje, date('Y-m-t', (int) strtotime($mes))), $mes);
        if ($c === null) {
            return ['tipo' => 'sem', 'texto' => t('Sem conferência com o banco neste mês')];
        }
        return $c['bate']
            ? ['tipo' => 'ok', 'texto' => t('Conferido com o banco em {data}: bate', ['data' => diaMes((string) $c['data'])])]
            : ['tipo' => 'dif', 'texto' => t('Conferido com o banco em {data}: diferença de {valor}', ['data' => diaMes((string) $c['data']), 'valor' => money(abs((float) $c['dif']))])];
    }

    /** @return list<array> os cartões ativos, se a aba Cartão está em uso */
    private static function cartoes(): array
    {
        return AjusteModel::usa('usa_cartao') ? CartaoModel::ativos() : [];
    }

    /** @return list<array> as compras de todos os cartões ativos */
    private static function compras(): array
    {
        $out = [];
        foreach (self::cartoes() as $c) {
            array_push($out, ...CartaoModel::compras($c['id']));
        }
        return $out;
    }

    /**
     * O que tinha na fatura do mês. O plano continua contando só a fatura; isto é para ler, não para julgar.
     * O pago é o desta fatura, mesmo que antes do mês (CartaoFatura::atribuir), para bater com as compras dela.
     * Com vários cartões, entram só os cartões cuja fatura do mês recebeu pagamento, e o "sem detalhe" é o de cada um,
     * como no "Para onde foi" (GastoPorCategoria): a compra de outro cartão não explica o que este pagou.
     * Sem nenhum pagamento, as compras de todos, como "Compras na fatura deste mês".
     */
    private static function fatura(array $dados, string $mes, string $hoje): ?array
    {
        $cat = CartaoModel::categoriaFatura();
        $cartoes = self::cartoes();
        if ($cartoes === [] || $cat === null) {
            return null;
        }
        $ligado = ($dados['fatura']['cat'] ?? null) === $cat;
        $pagoDe = [];                                   // cartão => o pago da fatura do mês
        foreach ($ligado ? $cartoes : [] as $c) {
            $v = round((float) ($dados['fatura']['porCartao'][$c['id']][$mes]['pago'] ?? 0.0), 2);
            if ($v > 0.004) {
                $pagoDe[$c['id']] = $v;
            }
        }
        $itens = [];
        $semDetalhe = $aMais = 0.0;
        foreach ($cartoes as $c) {
            if ($pagoDe !== [] && !isset($pagoDe[$c['id']])) {
                continue;
            }
            $doCartao = CartaoFatura::porFatura(CartaoModel::compras($c['id']))[$mes] ?? [];
            array_push($itens, ...$doCartao);
            if (isset($pagoDe[$c['id']])) {
                $somaC = round(array_sum(array_column($doCartao, 'valor')), 2);
                $semDetalhe += max(0.0, round($pagoDe[$c['id']] - $somaC, 2));
                $aMais += max(0.0, round($somaC - $pagoDe[$c['id']], 2));
            }
        }
        $pago = $ligado ? round(array_sum($pagoDe), 2) : round((float) ($dados['real'][$mes][$cat] ?? 0.0), 2);
        if ($pago <= 0.004 && $itens === []) {
            return null;
        }
        $grupos = [];
        foreach ($itens as $i) {
            $c = $i['compra']['categoria_id'] !== null ? (int) $i['compra']['categoria_id'] : 0;
            $grupos[$c] = ($grupos[$c] ?? 0.0) + (float) $i['valor'];
        }
        $linhas = [];
        foreach ($grupos as $c => $v) {
            $linhas[] = ['nome' => isset($dados['cats'][$c]) ? nomeCurto($dados['cats'][$c]) : t('Sem categoria'), 'valor' => round($v, 2)];
        }
        usort($linhas, static fn($a, $b) => $b['valor'] <=> $a['valor']);
        $soma = round(array_sum(array_column($linhas, 'valor')), 2);
        if (!$ligado) {                                 // sem o vínculo de cada pagamento: a fatura inteira contra as compras
            $semDetalhe = max(0.0, round($pago - $soma, 2));
            $aMais = $pago > 0.004 ? max(0.0, round($soma - $pago, 2)) : 0.0;
        }
        return ['pago' => $pago, 'soma' => $soma, 'linhas' => array_slice($linhas, 0, 6),
                'restoCategorias' => round(array_sum(array_column(array_slice($linhas, 6), 'valor')), 2),
                'semDetalhe' => round($semDetalhe, 2), 'aMais' => round($aMais, 2), 'paga' => $pago > 0.004];
    }

    /** O que o plano dizia no começo do mês contra o que aconteceu, e de onde veio a diferença. */
    private static function placar(array $dados, array $plano, string $mes): ?array
    {
        $foto = FotoPlano::valida($mes);
        $previsto = $foto['meses'][$mes]['fica'] ?? null;
        if ($foto === null || $previsto === null) {
            return null;
        }
        $real = (float) $plano[$mes]['fica_na_conta'];
        $difs = [];
        $fotoCats = $foto['cats'] ?? [];
        $reais = $dados['real'][$mes] ?? [];
        foreach ($fotoCats + $reais as $c => $_) {
            $cat = $dados['cats'][$c] ?? null;
            if ($cat === null || $cat['operacional'] !== 1) {
                continue;
            }
            $d = (float) ($reais[$c] ?? 0.0) - (float) ($fotoCats[$c] ?? 0.0);
            $efeito = $cat['sinal'] === 1 ? $d : -$d;
            if (abs($efeito) >= 1.0) {
                $difs[] = ['nome' => nomeCurto($cat), 'efeito' => round($efeito, 2)];
            }
        }
        $linhaFoto = $foto['meses'][$mes];
        foreach ([[t('Adiantamento do empréstimo'), 'amort', 'amortizacao_extra'], [t('Poupança'), 'mov', 'poupanca_liquido'], [t('Investimentos'), 'inv', 'invest_liquido']] as [$nome, $chaveFoto, $chavePlano]) {
            if (isset($linhaFoto[$chaveFoto])) {
                $efeito = (float) $linhaFoto[$chaveFoto] - (float) ($plano[$mes][$chavePlano] ?? 0.0);
                if (abs($efeito) >= 1.0) {
                    $difs[] = ['nome' => $nome, 'efeito' => round($efeito, 2)];
                }
            }
        }
        usort($difs, static fn($a, $b) => abs($b['efeito']) <=> abs($a['efeito']));
        return ['mes' => $mes, 'criada' => (string) $foto['criada_em'], 'previsto' => (float) $previsto, 'real' => $real, 'dif' => round($real - (float) $previsto, 2), 'causas' => array_slice($difs, 0, 3)];
    }

    /* ------------------------------------------------------------------ previsão: contratado, estimado, cenários */

    private static function previsao(array $dados, array $plano, string $mesBase, string $hoje, int $n): array
    {
        $parcela = (float) ($dados['aj']['parcela_mensal'] ?? 0.0);
        $jurosMes = (float) ($dados['aj']['juros_mes'] ?? 0.0);
        $reserva = (float) ($dados['aj']['reserva_minima'] ?? 0.0);
        $ini = max(date('Y-m-01', strtotime($mesBase . ' +1 month')), substr($hoje, 0, 8) . '01');
        $meses = array_slice(array_values(array_filter(array_keys($plano), static fn($m) => $m >= $ini)), 0, $n);
        if ($meses === []) {
            return ['meses' => [], 'contratado' => [], 'cenarios' => [], 'estimado' => [], 'marcos' => null, 'quita' => null, 'ganho' => null];
        }
        $totais = CartaoFatura::totais(self::compras());
        $contratado = [];
        foreach ($meses as $m) {
            $contratado[] = ['mes' => $m, 'cartao' => (float) ($totais[$m] ?? 0.0), 'emprestimo' => (float) $plano[$m]['parcela_descontada'] + (float) $plano[$m]['parcela_parte']];
        }
        $extraGasto = $extraRenda = [];
        foreach ($plano as $m => $l) {
            if ($m >= $ini && $l['estado'] !== 'real') {
                $extraGasto[$m] = -round((float) $l['sai'] * 0.10, 2);
                $extraRenda[$m] = -round((float) $l['entra_bruto'] * 0.10, 2);
            }
        }
        // em "o que tem menos o que deve": a conta sozinha engana, porque com menos dinheiro o plano adianta menos e ela até sobe
        $liquido = static fn(array $l): float => (float) $l['fica_na_conta'] + (float) $l['poupanca_saldo'] + (float) ($l['invest_saldo'] ?? 0.0) - max(0.0, (float) $l['falta_pagar']);
        $linha = static function (array $p) use ($meses, $liquido): array {
            return array_map(static fn($m) => $liquido($p[$m]), $meses);
        };
        // sem dívida daqui em diante, não há o que dizer sobre terminar de pagar
        $temDivida = array_sum(array_map(static fn($m) => (float) $plano[$m]['falta_pagar'], $meses)) > 0.01;
        $resumo = static function (array $p) use ($reserva, $ini, $parcela, $jurosMes, $meses, $temDivida): array {
            $num = AnualResumo::numeros($p, $reserva, $ini, $parcela, $jurosMes);
            $min = null;
            foreach ($meses as $m) {
                if ($min === null || (float) $p[$m]['fica_na_conta'] < $min['fica']) {
                    $min = ['mes' => $m, 'fica' => (float) $p[$m]['fica_na_conta']];
                }
            }
            return ['fim' => (float) $p[end($meses)]['fica_na_conta'], 'menor' => $min, 'quita' => $temDivida ? $num['quita'] : null, 'juros' => $num['juros']];
        };
        $pGasto = MotorFinanceiro::calcular($dados, ['hoje' => $hoje, 'extra' => $extraGasto]);
        $pRenda = MotorFinanceiro::calcular($dados, ['hoje' => $hoje, 'extra' => $extraRenda]);
        $semAdiantar = (int) round((float) ($dados['aj']['objetivo'] ?? 1)) !== 3 ? MotorFinanceiro::calcular($dados, ['hoje' => $hoje, 'objetivo' => 3]) : [];
        return [
            'meses' => $meses, 'contratado' => $contratado, 'reserva' => $reserva,
            'estimado' => array_map(static fn($m) => (float) $plano[$m]['fica_na_conta'], $meses),
            'cenarios' => [
                ['nome' => t('Se nada mudar'), 'linha' => $linha($plano)] + $resumo($plano),
                ['nome' => t('Gastando 10% a mais'), 'linha' => $linha($pGasto)] + $resumo($pGasto),
                ['nome' => t('Entrando 10% a menos'), 'linha' => $linha($pRenda)] + $resumo($pRenda),
            ],
            'marcos' => AnualResumo::marcos($plano, $reserva, $ini),
            'quita' => $temDivida ? AnualResumo::quitacao($plano, $parcela, $jurosMes) : null,
            'ganho' => $temDivida ? AnualResumo::ganho($plano, $semAdiantar, $parcela, $jurosMes) : null,
        ];
    }

    /** "Se a renda parasse": conta e poupança sobre os gastos essenciais de verdade. Sem nota nem meta. */
    private static function autonomia(array $dados, array $plano, string $mes, array $antes): ?array
    {
        $base = array_slice(array_merge($antes, $plano[$mes]['estado'] === 'real' ? [$mes] : []), -self::ENCERRADOS_PARA_MEDIA);
        if ($base === []) {
            return null;
        }
        $soma = 0.0;
        foreach ($base as $m) {
            foreach ($dados['real'][$m] ?? [] as $c => $v) {
                $cat = $dados['cats'][$c] ?? null;
                if ($cat !== null && $cat['operacional'] === 1 && $cat['sinal'] === -1 && $cat['essencial'] === 1) {
                    $soma += (float) $v;
                }
            }
        }
        $media = $soma / count($base);
        if ($media < 1.0) {
            return null;
        }
        $tem = (float) $plano[$mes]['fica_na_conta'] + (float) $plano[$mes]['poupanca_saldo'];
        return ['meses' => round(max(0.0, $tem) / $media, 1), 'tem' => $tem, 'essenciais' => round($media, 2), 'baseMeses' => count($base)];
    }

    /* ================================================================== anual */

    /** @param bool $porFatura ver mensal() */
    public static function anual(int $ano, ?string $hoje = null, bool $porFatura = false): ?array
    {
        $hoje ??= Relogio::hoje();
        $dados = MotorFinanceiro::dados();
        $plano = MotorFinanceiro::calcular($dados, ['hoje' => $hoje]);
        $meses = array_values(array_filter(array_keys($plano), static fn($m) => (int) substr((string) $m, 0, 4) === $ano));
        if ($meses === [] || $meses[0] > substr($hoje, 0, 8) . '01') {
            return null;
        }
        $reserva = (float) ($dados['aj']['reserva_minima'] ?? 0.0);
        $encerrados = array_values(array_filter($meses, static fn($m) => $plano[$m]['estado'] === 'real'));
        $n = count($encerrados);
        $tabela = [];
        foreach ($meses as $m) {
            $l = $plano[$m];
            $tabela[] = ['mes' => (string) $m, 'estado' => (string) $l['estado'], 'entrou' => (float) $l['entra'], 'saiu' => (float) $l['sai'],
                         'sobrou' => Sobra::de($l)['sobra'],
                         'fica' => (float) $l['fica_na_conta'], 'divida' => max(0.0, (float) $l['falta_pagar']),
                         'liquido' => (float) $l['fica_na_conta'] + (float) $l['poupanca_saldo'] + (float) ($l['invest_saldo'] ?? 0.0) - max(0.0, (float) $l['falta_pagar'])];
        }
        $some = static fn(string $k): float => round(array_sum(array_map(static fn($m) => (float) ($plano[$m][$k] ?? 0.0), $encerrados)), 2);
        $entrou = $some('entra');
        $saiu = $some('sai') + $some('parcela_parte');
        $sobrou = round($entrou - $saiu, 2);
        $ult = $n > 0 ? $plano[end($encerrados)] : null;
        $pri = $n > 0 ? $plano[$encerrados[0]] : null;
        $valorLiq = static fn(array $l, string $conta): float => (float) $l[$conta] + (float) $l['poupanca_saldo'] + (float) ($l['invest_saldo'] ?? 0.0) - max(0.0, (float) $l['falta_pagar']);
        $liqFim = $ult !== null ? $valorLiq($ult, 'fica_na_conta') : null;
        $porCompra = !$porFatura && GastoPorCategoria::ativo();
        $onde = self::categoriasDoAno($dados, $encerrados, $porCompra);   // a mesma conta no "Para onde foi" e no que foi bom ou ruim
        $ref = substr($hoje, 0, 8) . '01';
        $titulo = $n === 0
            ? t('{ano}: nenhum mês encerrado ainda', ['ano' => $ano])
            : ($n === count($meses) && $n === 12 ? t('{ano}: o ano inteiro', ['ano' => $ano]) : tn($n, '{ano}: 1 mês encerrado', '{ano}: {n} meses encerrados', ['ano' => $ano]));
        $placares = [];
        foreach ($encerrados as $m) {
            $p = self::placar($dados, $plano, $m);
            if ($p !== null) {
                $placares[] = $p;
            }
        }
        return [
            'tipo' => 'anual', 'ano' => $ano, 'gerado' => Relogio::agora()->format('Y-m-d H:i'), 'hoje' => $hoje, 'titulo' => $titulo, 'encerrados' => $n, 'totalMeses' => count($meses),
            'situacao' => $n > 0 ? t('De {de} a {ate}. Só os meses encerrados entram nos totais.', ['de' => monthLabel($encerrados[0]), 'ate' => monthLabel(end($encerrados))]) : t('Os totais aparecem quando o primeiro mês for encerrado.'),
            // a mesma frase do mês: o que sobrou depois de guardar, e o guardado com o nome dele
            'manchete' => $n === 0 ? t('Ainda não há mês encerrado neste ano.')
                : self::manchete(round($sobrou - ($some('poupanca_liquido') + $some('invest_liquido')), 2), true, 0.0, $some('poupanca_liquido') + $some('invest_liquido')),
            'entrou' => $entrou, 'saiu' => $saiu, 'sobrou' => $sobrou, 'guardou' => round($some('poupanca_liquido') + $some('invest_liquido'), 2),
            'adiantou' => $some('amortizacao_extra'), 'juros' => $some('juros'),
            'contaInicio' => $pri !== null ? (float) $pri['sobrou_mes_anterior'] : null, 'contaFim' => $ult !== null ? (float) $ult['fica_na_conta'] : null,
            'dividaFim' => $ult !== null ? max(0.0, (float) $ult['falta_pagar']) : null,
            'liquido' => $liqFim, 'reserva' => $reserva, 'tabela' => $tabela,
            'destaques' => self::destaques($tabela),
            'achados' => $n === 0 ? ['bom' => [], 'ruim' => [], 'melhorar' => [], 'conferir' => []] : self::achadosDoAno($dados, $plano, $encerrados, $onde, $entrou, $sobrou, $reserva, $some('amortizacao_extra'), round($some('poupanca_liquido') + $some('invest_liquido'), 2), $hoje),
            'categorias' => ['linhas' => array_slice($onde, 0, 12), 'totalReal' => round(array_sum(array_column($onde, 'real')), 2), 'totalPlanejado' => round(array_sum(array_column($onde, 'planejado')), 2), 'outros' => count($onde) > 12 ? [
                'n' => count($onde) - 12, 'real' => round(array_sum(array_column(array_slice($onde, 12), 'real')), 2), 'planejado' => round(array_sum(array_column(array_slice($onde, 12), 'planejado')), 2)] : null],
            'heatmap' => $n >= self::ENCERRADOS_PARA_HEATMAP ? self::heatmap($dados, $encerrados, $onde, $porCompra) : null,
            'porCompra' => $porCompra, 'cartaoAberto' => GastoPorCategoria::ativo(),
            'previsao' => self::previsao($dados, $plano, date('Y-m-01', strtotime($ref . ' -1 month')), $hoje, 12),
            'placares' => count($placares) >= self::COMPARACOES_PARA_PLACAR_ANUAL ? $placares : [],
            'placaresN' => count($placares),
            'erroMedio' => count($placares) >= self::COMPARACOES_PARA_PLACAR_ANUAL ? round(array_sum(array_map(static fn($p) => abs($p['dif']), $placares)) / count($placares), 2) : null,
            'placarInicio' => FotoPlano::primeiroMes(),
            'autonomia' => $n > 0 ? self::autonomia($dados, $plano, (string) end($encerrados), array_slice($encerrados, 0, -1)) : null,
            'temCartao' => self::cartoes() !== [], 'temDivida' => array_sum(array_column($tabela, 'divida')) > 0.004,
        ];
    }

    private static function categoriasDoAno(array $dados, array $meses, bool $porCompra = false): array
    {
        $soma = [];
        foreach ($meses as $m) {
            foreach (self::categoriasDoMes($dados, $m, $porCompra) as $c) {
                $id = $c['id'];
                $soma[$id] ??= $c + ['n' => 0];
                if ($soma[$id]['n'] > 0) {
                    $soma[$id]['planejado'] += $c['planejado'];
                    $soma[$id]['real'] += $c['real'];
                }
                $soma[$id]['n']++;
            }
        }
        foreach ($soma as &$c) {
            $c['planejado'] = round($c['planejado'], 2);
            $c['real'] = round($c['real'], 2);
            $c['dif'] = round($c['real'] - $c['planejado'], 2);
        }
        unset($c);
        $out = array_values($soma);
        usort($out, static fn($a, $b) => [$b['real'], $b['planejado']] <=> [$a['real'], $a['planejado']]);
        return $out;
    }

    private static function destaques(array $tabela): array
    {
        $enc = array_values(array_filter($tabela, static fn($t) => $t['estado'] === 'real'));
        if (count($enc) < 2) {
            return [];
        }
        $melhor = $pior = $enc[0];
        foreach ($enc as $t) {
            $melhor = $t['sobrou'] > $melhor['sobrou'] ? $t : $melhor;
            $pior = $t['sobrou'] < $pior['sobrou'] ? $t : $pior;
        }
        $saida = [['rotulo' => t('Mês em que mais sobrou'), 'mes' => $melhor['mes'], 'valor' => $melhor['sobrou']]];
        if ($pior['mes'] !== $melhor['mes']) {
            // a sobra do mês já desconta o guardado: um mês negativo pode ser só um mês em que a casa guardou, então não é "faltou"
            $saida[] = ['rotulo' => t('Mês em que menos sobrou'), 'mes' => $pior['mes'], 'valor' => $pior['sobrou']];
        }
        return $saida;
    }

    private static function achadosDoAno(array $dados, array $plano, array $encerrados, array $cats, float $entrou, float $sobrou, float $reserva, float $adiantou, float $guardou, string $hoje): array
    {
        $bom = $ruim = [];
        // "sobrou" com o sentido da manchete: depois de guardar e aplicar, que viram um item à parte
        $sobra = round($sobrou - max(0.0, $guardou), 2);
        if ($sobra > 0.004) {
            $por100 = $entrou > 0.004 ? (int) round($sobra / $entrou * 100) : 0;
            $bom[] = ['peso' => $sobra, 'texto' => $por100 > 0
                ? t('Sobraram {valor}: {n} de cada 100 {moeda} que entraram.', ['valor' => money($sobra), 'n' => $por100, 'moeda' => moedaPlural()])
                : t('Sobraram {valor}.', ['valor' => money($sobra)])];
        } elseif ($sobrou < -0.004) {
            $ruim[] = ['peso' => abs($sobrou), 'texto' => t('Saiu {valor} a mais do que entrou, sem contar adiantamentos e aplicações.', ['valor' => money(abs($sobrou))])];
        }
        foreach ($cats as $c) {
            if (!empty($c['semPlano']) || $c['planejado'] <= 0.004 && $c['real'] <= 0.004 || !self::relevante($c, $entrou) || $c['real'] <= 0.004) {
                continue;
            }
            if ($c['dif'] < 0) {
                $bom[] = ['peso' => abs($c['dif']), 'texto' => t('{categoria}: {valor} abaixo do planejado.', ['categoria' => $c['nome'], 'valor' => money(abs($c['dif']))])];
            } elseif ($c['planejado'] <= 0.004) {
                $ruim[] = ['peso' => $c['dif'], 'texto' => t('{categoria}: {valor} sem nada planejado.', ['categoria' => $c['nome'], 'valor' => money($c['real'])])];
            } else {
                $ruim[] = ['peso' => $c['dif'], 'texto' => t('{fato}.', ['fato' => t('{categoria}: {valor} acima do planejado', ['categoria' => $c['nome'], 'valor' => money($c['dif'])])])];
            }
        }
        if ($guardou > 0.004) {
            $bom[] = ['peso' => $guardou, 'texto' => t('Guardou ou aplicou {valor}.', ['valor' => money($guardou)])];
        }
        if ($adiantou > 0.004) {
            $bom[] = ['peso' => $adiantou, 'texto' => t('Adiantou {valor} do empréstimo, além da parcela.', ['valor' => money($adiantou)])];
        }
        $negativos = count(array_filter($encerrados, static fn($m) => (float) $plano[$m]['entra'] - (float) $plano[$m]['sai'] - (float) $plano[$m]['parcela_parte'] < -0.004));
        if ($negativos > 0 && count($encerrados) > 1) {
            $ruim[] = ['peso' => 0.0, 'texto' => tn($negativos, 'Em 1 mês saiu mais do que entrou.', 'Em {n} meses saiu mais do que entrou.')];
        }
        $ult = (string) end($encerrados);
        $aviso = self::alertaProximos($plano, $ult, $reserva);
        if ($aviso !== null) {
            $ruim[] = $aviso;
        }
        $porMes = array_map(static function ($c) use ($encerrados) {
            $c['real'] = $c['real'] / max(1, count($encerrados));
            return $c;
        }, $cats);
        return ['bom' => self::maiores($bom), 'ruim' => self::maiores($ruim), 'conferir' => [],
                'melhorar' => self::melhorar($dados, $plano, $ult, $porMes, $entrou / max(1, count($encerrados)), $reserva, $hoje)];
    }

    /** Categorias × meses encerrados, em valores: as oito maiores. $porCompra: os valores de GastoPorCategoria. */
    private static function heatmap(array $dados, array $encerrados, array $cats, bool $porCompra = false): array
    {
        $linhas = [];
        $max = 0.0;
        foreach (array_slice($cats, 0, 8) as $c) {
            $vals = [];
            foreach ($encerrados as $m) {
                $v = round($porCompra ? max(0.0, GastoPorCategoria::doMes($m)[$c['id']] ?? 0.0) : (float) ($dados['real'][$m][$c['id']] ?? 0.0), 2);
                $vals[] = $v;
                $max = max($max, $v);
            }
            $linhas[] = ['nome' => $c['nome'], 'valores' => $vals];
        }
        return ['meses' => $encerrados, 'linhas' => $linhas, 'max' => $max];
    }

    private static function maiuscula(string $s): string
    {
        return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }

    /** Valor distante arredondado: quanto mais longe, menos certeza cabe no número. */
    public static function cerca(float $v): string
    {
        $passo = abs($v) >= 10000 ? 1000 : 100;
        return t('cerca de {valor}', ['valor' => moedaSimbolo() . NBSP . num(round($v / $passo) * $passo, 0)]);
    }
}
