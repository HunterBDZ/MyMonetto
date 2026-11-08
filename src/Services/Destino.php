<?php
namespace Financeiro\Services;

use Financeiro\Relogio;

/**
 * "Para onde vai o dinheiro do mês" (a barra do Início e do Mês) e as linhas que o livro-razão do Mês precisa além das
 * categorias de renda e de gasto. Nada é calculado de novo: tudo sai da linha do plano (MotorFinanceiro::calcular) e
 * dos dados do motor, com a mesma regra dele (no mês aberto, cada categoria conta o maior entre o lançado e o previsto).
 * O lançamento agendado (data depois de hoje) ainda não saiu: fica no que falta pagar, até o dia dele.
 *
 * O dinheiro do mês é o que sobrou do mês anterior mais tudo o que entra (MotorFinanceiro::balancoTotal). Ele se
 * divide em: o que já saiu, as contas que faltam pagar, o que falta das metas do dia a dia, o que dá para gastar e a
 * reserva. As cinco partes somam o dinheiro do mês; quando o mês fecha abaixo de zero, as saídas passam dele e
 * 'falta' diz quanto.
 */
final class Destino
{
    /**
     * As partes do dinheiro do mês. null num mês fechado (não há o que dividir: tudo já aconteceu).
     * @param array $dados MotorFinanceiro::dados()
     * @param array $linha a linha do mês no plano (MotorFinanceiro::calcular()[mes])
     * @param string $mesHoje o mês de hoje, Y-m-01
     * @return ?array{total:float, saiu:float, contas:float, metas:float, gastar:float, reserva:float, falta:float, livre:float,
     *   veio:float, entradas:float, reservaMeta:float}
     *   livre = o que fica na conta no fim do mês sem o adiantamento automático do empréstimo (gastar + reserva − falta);
     *   veio + entradas = total (o que veio do mês anterior e o que entra no mês); reservaMeta = a reserva de Ajustes
     */
    public static function doMes(array $dados, array $linha, string $mesHoje, ?string $hoje = null): ?array
    {
        $estado = (string) ($linha['estado'] ?? 'real');
        if ($estado === 'real') {
            return null;
        }
        $mes = (string) $linha['mes'];
        $aberto = $estado === 'aberto';
        [$entradas, $saidas] = MotorFinanceiro::balancoTotal($linha);
        $auto = (float) ($linha['amortizacao_auto'] ?? 0.0);
        $total = round((float) $linha['sobrou_mes_anterior'] + $entradas, 2);

        // o que ainda falta sair, categoria por categoria: no mês aberto, o que o plano conta (o maior entre o previsto e
        // o lançado) menos o lançado até hoje (o agendado ainda não saiu); no futuro, o previsto
        $p = MotorFinanceiro::previsaoDoMes($dados, $mes, $aberto ? 'aberto' : 'previsto', $mesHoje);
        $rTudo = $aberto ? ($dados['real'][$mes] ?? []) : [];
        $r = $aberto ? (MotorFinanceiro::realizado($hoje ?? Relogio::hoje())['real'][$mes] ?? []) : [];
        $contas = $metas = 0.0;
        foreach ($p + $rTudo as $c => $_) {
            $cat = $dados['cats'][$c] ?? null;
            if ($cat === null || $cat['operacional'] !== 1 || $cat['sinal'] === 1) {
                continue;
            }
            $falta = max(0.0, max((float) ($p[$c] ?? 0.0), (float) ($rTudo[$c] ?? 0.0)) - (float) ($r[$c] ?? 0.0));
            if ($cat['grupo'] === 'Dia a dia') {
                $metas += $falta;
            } else {
                $contas += $falta;
            }
        }
        // a parcela do empréstimo paga à parte é uma conta: a que ainda não foi lançada falta pagar
        $parc = $dados['esp']['parc'] ?? null;
        $contas += max(0.0, (float) ($linha['parcela_parte'] ?? 0.0) - ($parc !== null ? (float) ($r[$parc] ?? 0.0) : 0.0));

        $contas = round($contas, 2);
        $metas = round($metas, 2);
        $saiu = round(max(0.0, $saidas - $auto - $contas - $metas), 2);
        $livre = round($total - $saiu - $contas - $metas, 2);
        $reserva = max(0.0, (float) ($dados['aj']['reserva_minima'] ?? 0.0));
        return [
            'total' => $total, 'saiu' => $saiu, 'contas' => $contas, 'metas' => $metas,
            'gastar' => round(max(0.0, $livre - $reserva), 2), 'reserva' => round(max(0.0, min($reserva, $livre)), 2),
            'falta' => round(max(0.0, -$livre), 2), 'livre' => $livre,
            'veio' => round((float) $linha['sobrou_mes_anterior'], 2), 'entradas' => round($total - (float) $linha['sobrou_mes_anterior'], 2),
            'reservaMeta' => round($reserva, 2),
        ];
    }

    /**
     * O livro-caixa do mês, no Início (o dono achou a legenda de cinco cores difícil de ler de relance): a conta da
     * resposta em linhas, como um extrato, e a barra com as mesmas partes. O dinheiro do mês, menos o que já saiu,
     * menos o que ainda vai sair (contas a pagar e o que falta das metas, numa linha só), menos a reserva quando ela
     * cabe, dá a última linha: "Dá para gastar" (em azul, a resposta), ou "Fecha com" quando não sobra nada para gastar
     * (abaixo da reserva, ou negativo no vermelho). Linha zerada não aparece. Os números são os de doMes(): nenhum novo.
     * Só rótulo e número, sem nota embaixo: o dono não quer subtexto ("quando tem muito texto fica na cara que foi
     * feito com IA").
     * @param array $d Destino::doMes()
     * @return array{estado:string, linhas:list<array{rotulo:string, valor:float, classe:string}>,
     *   fim:array{rotulo:string, valor:float, classe:string, tom:string}, segmentos:list<array>}
     *   estado: gastar | justo | abaixo | vermelho; segmentos: para partials/destino (só a barra), na ordem das linhas
     */
    public static function livro(array $d, string $mes): array
    {
        $vaiSair = round($d['contas'] + $d['metas'], 2);
        $estado = $d['falta'] > 0.004 ? 'vermelho'
            : ($d['gastar'] > 0.004 ? 'gastar' : ($d['livre'] < (float) ($d['reservaMeta'] ?? 0.0) - 0.004 ? 'abaixo' : 'justo'));
        $linhas = [['rotulo' => t('Dinheiro de {mes}', ['mes' => mesExtenso($mes)]), 'valor' => (float) $d['total'], 'classe' => '']];
        if ($d['saiu'] >= 0.005) {
            $linhas[] = ['rotulo' => t('Já saiu'), 'valor' => -(float) $d['saiu'], 'classe' => 's-saiu'];
        }
        if ($vaiSair >= 0.005) {
            $linhas[] = ['rotulo' => t('Ainda vai sair'), 'valor' => -$vaiSair, 'classe' => 's-previsto'];
        }
        $comReserva = in_array($estado, ['gastar', 'justo'], true) && $d['reserva'] >= 0.005;
        if ($comReserva) {
            $linhas[] = ['rotulo' => t('Fica de reserva'), 'valor' => -(float) $d['reserva'], 'classe' => 's-reserva'];
        }
        $fim = match ($estado) {
            'vermelho' => ['rotulo' => t('Fecha com'), 'valor' => -(float) $d['falta'], 'classe' => 's-falta', 'tom' => 'alerta'],
            'abaixo'   => ['rotulo' => t('Fecha com'), 'valor' => (float) $d['livre'], 'classe' => 's-reserva', 'tom' => 'atencao'],
            default    => ['rotulo' => t('Dá para gastar'), 'valor' => (float) $d['gastar'], 'classe' => 's-gastar', 'tom' => 'azul'],
        };
        // a barra: as mesmas partes, na escala do dinheiro do mês; no vermelho, o que passa dele é a falta (a barra a desenha)
        $segmentos = [
            ['rotulo' => t('Já saiu'), 'valor' => (float) $d['saiu'], 'classe' => 's-saiu'],
            ['rotulo' => t('Ainda vai sair'), 'valor' => $vaiSair, 'classe' => 's-previsto'],
        ];
        if ($estado === 'gastar') {
            $segmentos[] = ['rotulo' => t('Dá para gastar'), 'valor' => (float) $d['gastar'], 'classe' => 's-gastar'];
        }
        if ($comReserva || $estado === 'abaixo') {
            $segmentos[] = ['rotulo' => $estado === 'abaixo' ? t('Fecha com') : t('Fica de reserva'), 'valor' => (float) ($estado === 'abaixo' ? $d['livre'] : $d['reserva']), 'classe' => 's-reserva'];
        }
        return ['estado' => $estado, 'linhas' => $linhas, 'fim' => $fim, 'segmentos' => $segmentos];
    }

    /**
     * Os segmentos do partials/destino, na ordem do fato ao previsto; o azul marca o que dá para gastar.
     * @param array $d Destino::doMes()
     */
    public static function segmentos(array $d): array
    {
        // no vermelho, as saídas passam do dinheiro do mês: no lugar do "Dá para gastar" (zero), a falta, negativa,
        // para a legenda somar o total
        $resposta = (float) ($d['falta'] ?? 0.0) > 0.004
            ? ['rotulo' => t('Falta para zerar'), 'valor' => -(float) $d['falta'], 'classe' => 's-falta']
            : ['rotulo' => t('Dá para gastar'), 'valor' => $d['gastar'], 'classe' => 's-gastar', 'destaque' => true];
        return [
            ['rotulo' => t('Já saiu'), 'valor' => $d['saiu'], 'classe' => 's-saiu'],
            ['rotulo' => t('Contas a pagar'), 'valor' => $d['contas'], 'classe' => 's-contas'],
            ['rotulo' => t('Metas que faltam'), 'valor' => $d['metas'], 'classe' => 's-metas'],
            $resposta,
            ['rotulo' => t('Reserva'), 'valor' => $d['reserva'], 'classe' => 's-reserva'],
        ];
    }

    /**
     * O que foi guardado no mês até agora, já descontados os resgates: poupança e investimentos (o lançado até hoje).
     */
    public static function guardado(array $dados, string $mes, ?string $hoje = null): float
    {
        $r = MotorFinanceiro::realizado($hoje ?? Relogio::hoje())['real'][$mes] ?? [];
        $v = static fn(string $k): float => ($id = $dados['esp'][$k] ?? null) !== null ? (float) ($r[$id] ?? 0.0) : 0.0;
        return round($v('guard') - $v('resg') + $v('aplic') - $v('resgInv'), 2);
    }

    /**
     * As linhas do livro-razão que não são renda nem gasto, pela regra do plano: crédito e resgates (entram) e poupança,
     * investimentos, adiantamento, a parcela paga à parte e o adiantamento que o plano sugere sozinho (saem). Com as
     * linhas de renda e de gasto e o saldo do mês anterior, elas fecham no fim do mês do plano.
     * Cada linha: id (0 no adiantamento sugerido), cat (a categoria, null no sugerido), entra, valor (o que o plano conta,
     * com o agendado), real (o realizado: o agendado fica de fora até o dia dele, MotorFinanceiro::realizado), prev (o
     * previsto no mês) e ult (o dia do último lançamento realizado).
     * @return list<array{id:int, cat:?array, entra:bool, valor:float, real:float, prev:float, auto:bool, ult:?string}>
     */
    public static function movimentos(array $dados, array $linha, ?string $hoje = null): array
    {
        $mes = (string) $linha['mes'];
        $estado = (string) ($linha['estado'] ?? 'real');
        $r = $estado === 'previsto' ? [] : ($dados['real'][$mes] ?? []);
        $feito = MotorFinanceiro::realizado($hoje ?? Relogio::hoje());
        $rFeito = $estado === 'previsto' ? [] : ($feito['real'][$mes] ?? []);
        $p = $dados['prev'][$mes] ?? [];
        $esp = $dados['esp'];
        $auto = (float) ($linha['amortizacao_auto'] ?? 0.0);
        $out = [];
        $ult = $feito['ult'][$mes] ?? [];
        $linhaDe = static function (?int $id, bool $entra, float $valor) use ($dados, $rFeito, $p, $ult): ?array {
            if ($id === null || !isset($dados['cats'][$id])) {
                return null;
            }
            $real = round((float) ($rFeito[$id] ?? 0.0), 2);
            $prev = round((float) ($p[$id] ?? 0.0), 2);
            $valor = round($valor, 2);
            if (abs($valor) < 0.005 && abs($real) < 0.005) {
                return null;
            }
            return ['id' => $id, 'cat' => $dados['cats'][$id], 'entra' => $entra, 'valor' => $valor, 'real' => $real, 'prev' => $prev, 'auto' => false,
                    'ult' => $real >= 0.005 ? ($ult[$id] ?? null) : null];
        };
        $em = static fn(?int $id, array $v): float => $id !== null ? (float) ($v[$id] ?? 0.0) : 0.0;
        // o crédito do empréstimo segue a regra das entradas; poupança e investimentos, só o lançado
        $cred = match ($estado) {
            'real'   => $em($esp['cred'], $r),
            'aberto' => max($em($esp['cred'], $r), $em($esp['cred'], $p)),
            default  => $em($esp['cred'], $p),
        };
        $out[] = $linhaDe($esp['cred'], true, $cred);
        $out[] = $linhaDe($esp['resg'], true, $em($esp['resg'], $r));
        $out[] = $linhaDe($esp['resgInv'] ?? null, true, $em($esp['resgInv'] ?? null, $r));
        $out[] = $linhaDe($esp['guard'], false, $em($esp['guard'], $r));
        $out[] = $linhaDe($esp['aplic'] ?? null, false, $em($esp['aplic'] ?? null, $r));
        $out[] = $linhaDe($esp['amort'], false, max(0.0, (float) ($linha['amortizacao_extra'] ?? 0.0) - $auto));
        $out[] = $linhaDe($esp['parc'] ?? null, false, (float) ($linha['parcela_parte'] ?? 0.0));
        $out = array_values(array_filter($out));
        if ($auto >= 0.005) {
            $out[] = ['id' => 0, 'cat' => null, 'entra' => false, 'valor' => round($auto, 2), 'real' => 0.0, 'prev' => 0.0, 'auto' => true, 'ult' => null];
        }
        return $out;
    }
}
