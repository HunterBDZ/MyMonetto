<?php
namespace Financeiro\Services;

use Financeiro\Controllers\CartaoController;
use Financeiro\Controllers\MetaController;
use Financeiro\Models\AjusteModel;
use Financeiro\Models\CartaoModel;
use Financeiro\Models\CategoriaModel;
use Financeiro\Models\ContaRecorrenteModel;
use Financeiro\Models\MesModel;
use Financeiro\Relogio;

/**
 * "Precisa de atenção", no Início: o que pede uma ação agora, do mais urgente para o menos. Nenhum número novo: cada
 * aviso vem do que o app já calcula (ChecklistContas, CartaoFatura::painel, a meta do mês contra o gasto como as
 * Metas contam (MetaController::gastosDoMes) e a situação dos meses). A conferência com o banco não vira aviso desde
 * a 2.1.4, a pedido do dono. Vencimento em dia que não é útil só atrasa depois do próximo dia útil (DiaUtil), aqui
 * como no Mês e no Cartão.
 *
 * Ordem: contas e faturas atrasadas; as que vencem em até DIAS dias; mês anterior aberto depois do dia DIA_FECHAR;
 * fatura fechada que vence depois; meta estourada. No mesmo nível, a data mais próxima.
 */
final class Atencao
{
    /** Quantos avisos a faixa mostra; o resto fica em "e mais N", na lista. */
    public const MAX = 3;

    /** "Vence logo": o último dia para pagar sem atraso está a até tantos dias. */
    public const DIAS = 3;

    /** Depois deste dia do mês, o mês anterior ainda aberto vira aviso. */
    public const DIA_FECHAR = 5;

    /**
     * @return list<array{tipo:string, nivel:int, data:string, titulo:string, detalhe:string, acao:string, href:string}>
     *   tipo: conta_atrasada | conta_vence | fatura_vencida | fatura_vence | fatura_fechada | mes_aberto | meta
     */
    public static function avisos(string $hoje): array
    {
        $meses = array_column(MesModel::todos(), 'situacao', 'mes');
        if ($meses === []) {
            return [];                                   // site sem plano: nada a avisar
        }
        $mesCal = substr($hoje, 0, 7) . '-01';
        $out = [];

        // faturas do cartão: a fechada que ainda não foi paga, vencendo ou vencida
        [$faturas, $pularCat] = self::faturas($hoje);
        array_push($out, ...$faturas);

        // contas recorrentes do mês (e, perto da virada, as do mês seguinte que vencem logo)
        foreach ([$mesCal, CartaoFatura::somaMeses($mesCal, 1)] as $i => $mes) {
            if (($meses[$mes] ?? '') !== 'PREVISAO') {
                continue;
            }
            foreach (ChecklistContas::aPagar($mes, $hoje) as $c) {
                if ($c['categoria_id'] === $pularCat || $c['falta'] < 0.005) {
                    continue;
                }
                $nome = nomeCategoria((string) $c['categoria_nome']);
                $q = ['categoria' => $c['categoria_id'], 'valor' => numInput((float) $c['falta'])];
                if ($mes > $mesCal) {
                    $q['data'] = $c['vencimento'];   // conta do mês seguinte: o pagamento cai no mês dela
                }
                $href = '/lancar?' . http_build_query($q + ['volta' => '/']);
                if ($c['status'] === 'atrasado') {
                    $out[] = self::aviso('conta_atrasada', 0, $c['vencimento'],
                        t('{conta} venceu {data}', ['conta' => $nome, 'data' => diaMes($c['vencimento'])]),
                        t('{valor} sem pagamento lançado.', ['valor' => money((float) $c['falta'])]), t('Lançar'), $href);
                } elseif ($c['status'] === 'pendente' && self::dias($hoje, $c['paga_ate']) <= self::DIAS) {
                    $out[] = self::aviso('conta_vence', 1, $c['paga_ate'],
                        self::vence($nome, $c['vencimento'], $c['paga_ate'], $hoje),
                        t('Previsto: {valor}.', ['valor' => money((float) $c['falta'])]), t('Lançar'), $href);
                }
            }
        }

        // mês que já terminou e continua aberto, passado o começo do mês seguinte
        if ((int) substr($hoje, 8, 2) > self::DIA_FECHAR) {
            foreach ($meses as $mes => $situacao) {
                if ($mes < $mesCal && $situacao === 'PREVISAO') {
                    $out[] = self::aviso('mes_aberto', 2, $mes, t('{mes} ainda está aberto', ['mes' => mesAno($mes)]),
                        t('Feche o mês para conferir com o banco.'), t('Fechar'), '/mes/fechar?mes=' . rawurlencode($mes));
                    break;
                }
            }
        }

        // categoria que passou da meta no mês, a que passou mais primeiro: a meta do mês (com as mudanças de
        // recorrência) e o gasto como a tela Metas conta (com o cartão, pela data da compra), para dizer o mesmo que ela
        if (($meses[$mesCal] ?? '') === 'PREVISAO') {
            $prev = MotorFinanceiro::dados()['prev'][$mesCal] ?? [];
            $gastos = MetaController::gastosDoMes($mesCal, $hoje === Relogio::hoje() ? null : $hoje);
            // as contas fixas ficam de fora, como nas Metas (lá a conta paga a mais aparece "paga"); a fatura avisa pelo Cartão
            $fora = array_flip(array_map('intval', array_column(ContaRecorrenteModel::ativas(), 'categoria_id')));
            $fora[(int) CartaoModel::categoriaFatura()] = true;
            $passou = [];
            foreach (CategoriaModel::gastos() as $c) {
                $id = (int) $c['id'];
                $meta = round((float) ($prev[$id] ?? 0.0), 2);
                $gasto = (float) ($gastos[$id]['gasto'] ?? 0.0);
                if (!isset($fora[$id]) && $meta > 0.004 && $gasto - $meta >= 0.005) {
                    $passou[] = [$gasto - $meta, $c, $meta, $gasto, $gastos[$id]['cartao'] ?? false];
                }
            }
            usort($passou, static fn(array $a, array $b): int => $b[0] <=> $a[0]);
            foreach ($passou as $k => [$excesso, $c, $meta, $gasto, $cartao]) {
                $vars = ['gasto' => num($gasto), 'meta' => num($meta), 'resto' => num($excesso)];
                $out[] = self::aviso('meta', 5, sprintf('%04d', $k), t('{categoria} passou da meta', ['categoria' => nomeCurto($c)]),
                    $cartao ? t('{gasto} de {meta}, passou {resto}, com o cartão', $vars) : t('{gasto} de {meta}, passou {resto}', $vars),
                    t('Ver metas'), '/meta#c' . (int) $c['id']);
            }
        }

        usort($out, static fn(array $a, array $b): int => [$a['nivel'], $a['data'], $a['titulo']] <=> [$b['nivel'], $b['data'], $b['titulo']]);
        return $out;
    }

    /**
     * As faturas fechadas e não pagas de cada cartão ativo (com a aba Cartão), pelo status do próprio Cartão. Só as que
     * têm compra lançada: sem compra, o app não sabe o valor, e a Fatura do cartão segue como uma conta do Mês.
     * @return array{0:list<array>, 1:?int} os avisos e a categoria da Fatura a tirar das contas (quando o Cartão avisa por ela)
     */
    private static function faturas(string $hoje): array
    {
        $cartoes = AjusteModel::usa('usa_cartao') ? CartaoModel::ativos() : [];
        if ($cartoes === []) {
            return [[], null];
        }
        $pagas = CartaoFatura::faturasPagas(MotorFinanceiro::dados()['prev'], true, $hoje)['porCartao'];   // o pagamento agendado ainda não pagou
        $out = [];
        $comCompras = false;
        foreach ($cartoes as $cartao) {
            $compras = CartaoModel::compras($cartao['id']);
            $comCompras = $comCompras || $compras !== [];
            $doCartao = $pagas[$cartao['id']] ?? [];
            $painel = CartaoFatura::painel($cartao, $compras, $hoje, array_map(static fn(array $f): float => $f['pago'], $doCartao));
            foreach ($painel['faturas'] as $f) {
                if (!in_array($f['status'], ['fechada', 'vencida'], true) || $f['total'] < 0.005) {
                    continue;
                }
                $falta = (float) ($f['falta'] ?? $f['total']);
                $detalhe = t('{cartao} · faltam {valor}', ['cartao' => $cartao['nome'], 'valor' => money($falta)]);
                $href = CartaoController::url($cartao['id'], 'fatura=' . $f['mes']) . '#fatura';
                if ($f['status'] === 'vencida') {
                    $out[] = self::aviso('fatura_vencida', 0, $f['vence'], t('Fatura venceu {data}', ['data' => diaMes($f['vence'])]),
                        $detalhe, t('Pagar'), $href);
                } elseif (self::dias($hoje, $f['pagaAte']) <= self::DIAS) {
                    $out[] = self::aviso('fatura_vence', 1, $f['pagaAte'], self::vence(t('Fatura'), $f['vence'], $f['pagaAte'], $hoje),
                        $detalhe, t('Pagar'), $href);
                } else {
                    $out[] = self::aviso('fatura_fechada', 3, $f['pagaAte'], t('Fatura fechada, vence {data}', ['data' => diaMes($f['vence'])]),
                        $detalhe, t('Ver'), $href);
                }
            }
        }
        return [$out, $comCompras ? CartaoModel::categoriaFatura() : null];
    }

    /** "Luz vence hoje", "amanhã", "25/11", ou "15/11 · paga até 16/11" quando o vencimento não cai em dia útil. */
    private static function vence(string $nome, string $vencimento, string $pagaAte, string $hoje): string
    {
        if ($pagaAte !== $vencimento) {
            return t('{conta} vence {data} · paga até {util}', ['conta' => $nome, 'data' => diaMes($vencimento), 'util' => diaMes($pagaAte)]);
        }
        return match (self::dias($hoje, $vencimento)) {
            0 => t('{conta} vence hoje', ['conta' => $nome]),
            1 => t('{conta} vence amanhã', ['conta' => $nome]),
            default => t('{conta} vence {data}', ['conta' => $nome, 'data' => diaMes($vencimento)]),
        };
    }

    private static function dias(string $de, string $ate): int
    {
        return (int) (new \DateTimeImmutable($de))->diff(new \DateTimeImmutable($ate))->format('%r%a');
    }

    private static function aviso(string $tipo, int $nivel, string $data, string $titulo, string $detalhe, string $acao, string $href): array
    {
        // a frase começa com maiúscula, mesmo quando começa pelo mês ("novembro de 2026") ou por um nome digitado
        $titulo = mb_strtoupper(mb_substr($titulo, 0, 1)) . mb_substr($titulo, 1);
        return ['tipo' => $tipo, 'nivel' => $nivel, 'data' => $data, 'titulo' => $titulo, 'detalhe' => $detalhe, 'acao' => $acao, 'href' => $href];
    }
}
