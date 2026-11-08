<?php
/**
 * A barra "para onde vai o dinheiro do mês" (o momento do Início e do Mês): uma barra com um segmento por destino,
 * proporcional ao valor, e a legenda com cor, nome e valor (5 colunas no computador; linhas com fio no celular).
 * A cor vai do fato ao previsto, e o azul só marca a resposta. Uso:
 *   View::partial('partials/destino', ['titulo' => t('Para onde vai o dinheiro de {mes}', [...]), 'segmentos' => [
 *       ['rotulo' => t('Já saiu'), 'valor' => 908.98, 'classe' => 's-saiu'],
 *       ['rotulo' => t('Contas a pagar'), 'valor' => 4908.20, 'classe' => 's-contas'],
 *       ['rotulo' => t('Metas que faltam'), 'valor' => 2371.02, 'classe' => 's-metas'],
 *       ['rotulo' => t('Dá para gastar'), 'valor' => 2398.66, 'classe' => 's-gastar', 'destaque' => true],
 *       ['rotulo' => t('Reserva'), 'valor' => 1500.00, 'classe' => 's-reserva'],
 *   ]]);
 * Variáveis: $segmentos (rótulo, valor >= 0, classe: s-saiu|s-contas|s-metas|s-previsto|s-gastar|s-reserva, destaque
 * opcional); $titulo (opcional); $total (opcional; sem ele, a soma); $nivel (opcional, 'h2' ou 'h3', o nível do título);
 * $legenda (opcional, padrão true: no Início, quem explica a barra é o livro-caixa, partials/livro_mes).
 * Segmento sem valor (zero ou negativo) não entra na barra, mas fica na legenda; o negativo (a falta, quando o mês fecha
 * no vermelho) aparece com "−" em vermelho, e a legenda soma o total.
 * A escala é o dinheiro do mês: quando as partes passam dele (no vermelho), a barra vai até a soma delas, uma marca
 * mostra onde o dinheiro acaba e o que passa dela é a falta, hachurada em vermelho. Antes, as partes enchiam a barra
 * como se coubessem no total escrito em cima dela.
 */
$segmentos = array_values(array_filter((array) ($segmentos ?? []), 'is_array'));
$titulo = (string) ($titulo ?? '');
$nivel = ($nivel ?? 'h2') === 'h3' ? 'h3' : 'h2';
$positivo = array_sum(array_map(static fn(array $s): float => max(0.0, (float) ($s['valor'] ?? 0)), $segmentos));
$total = isset($total) ? (float) $total : $positivo;
$legenda = $legenda ?? true;
$excede = $total > 0.004 && $positivo - $total >= 0.005 ? round($positivo - $total, 2) : 0.0;   // a falta, desenhada
$partes = [];
foreach ($segmentos as $s) {
    $partes[] = t('{nome} {valor}', ['nome' => mb_strtolower((string) ($s['rotulo'] ?? '')), 'valor' => num((float) ($s['valor'] ?? 0))]);
}
$descricao = t('De {total}: {partes}.', ['total' => num($total), 'partes' => implode('; ', $partes)])
    . ($excede > 0 ? ' ' . t('Passa {valor} do dinheiro do mês.', ['valor' => num($excede)]) : '');
?>
<div class="destino">
    <?php if ($titulo !== ''): ?>
        <div class="destino-cab"><<?= $nivel ?>><?= e($titulo) ?></<?= $nivel ?>><span class="num"><?= e(num($total)) ?></span></div>
    <?php endif; ?>
    <div class="barra-caixa">
        <div class="barra" role="img" aria-label="<?= e($descricao) ?>">
            <?php foreach ($segmentos as $s):
                $v = round((float) ($s['valor'] ?? 0), 2);
                if ($v <= 0) {
                    continue;
                } ?>
                <span class="<?= e((string) ($s['classe'] ?? '')) ?>" style="--v:<?= e(number_format($v, 2, '.', '')) ?>" title="<?= e(t('{nome}: {valor}', ['nome' => (string) ($s['rotulo'] ?? ''), 'valor' => num($v)])) ?>"></span>
            <?php endforeach; ?>
        </div>
        <?php if ($excede > 0): ?>
            <span class="barra-falta" style="--ate:<?= e(number_format($total / $positivo, 4, '.', '')) ?>" title="<?= e(t('Passa {valor} do dinheiro do mês.', ['valor' => num($excede)])) ?>" aria-hidden="true"></span>
        <?php endif; ?>
    </div>
    <?php if ($legenda): ?>
    <ul class="legenda">
        <?php foreach ($segmentos as $s): ?>
            <li<?= !empty($s['destaque']) ? ' class="destaque"' : '' ?>><i class="<?= e((string) ($s['classe'] ?? '')) ?>" aria-hidden="true"></i><span class="rot"><?= e((string) ($s['rotulo'] ?? '')) ?></span><span class="val num<?= (float) ($s['valor'] ?? 0) < -0.004 ? ' alerta' : '' ?>"><?= e(num((float) ($s['valor'] ?? 0))) ?></span></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>
