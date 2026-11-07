<?php
/**
 * Acertar saldo: o saldo que o banco mostra numa data, de cada conta (partials/saldos_contas, com a diferença para o
 * app enquanto se digita). Enviar mostra a conta antes de lançar ($previa: o banco, o app, o acerto e o dia em que
 * ele entra); só "Lançar o acerto" grava. Variáveis: $contas (as ativas, com o saldo de hoje), $varias, $antes (o que
 * foi digitado e o erro, ou null), $previa (AcertoController::previa, ou null), $volta, $hoje, $minimo.
 */
$a = is_array($antes ?? null) ? $antes : [];
$quando = ($a['quando'] ?? 'hoje') === 'outro' ? 'outro' : 'hoje';
$data = is_string($a['data'] ?? null) ? $a['data'] : $hoje;
$descricao = is_string($a['descricao'] ?? null) ? $a['descricao'] : '';
$erro = is_string($a['msg'] ?? null) ? $a['msg'] : null;
$algum = $previa !== null && array_filter($previa, static fn(array $p): bool => $p['acerto'] !== null) !== [];
?>
<div class="ct ct-editar ac">
    <header class="lf-topo ct-ed-topo">
        <a class="lf-x lf-voltar" href="<?= e($volta) ?>" aria-label="<?= e(t('voltar')) ?>"><?= svgIcon('chevron', 20, 'lf-seta') ?></a>
        <h1 class="lf-titulo"><?= e(t('Acertar saldo')) ?></h1>
    </header>
    <p class="ct-escada-sub"><?= e(t('Para quando o app e o banco não batem e não vale procurar a diferença. Digite o saldo que o banco mostra: o app lança a diferença como um acerto, e o saldo volta a bater. O acerto não é gasto nem entrada.')) ?></p>

    <?php if ($previa !== null): ?>
        <section class="card ct-form ac-previa" aria-label="<?= e(t('A conta do acerto')) ?>">
            <h2 class="ac-previa-t"><?= e(t('Saldo em {data}', ['data' => diaMes((string) $a['data'])])) ?></h2>
            <dl class="ac-linhas">
                <?php foreach ($previa as $p): ?>
                    <div class="ac-linha">
                        <dt><?= e($varias ? nomeConta($p['conta']) : t('Conta')) ?></dt>
                        <dd class="num">
                            <span><?= e(t('No banco: {valor}', ['valor' => num($p['banco'])])) ?></span>
                            <span><?= e(t('No app: {valor}', ['valor' => num($p['app'])])) ?></span>
                            <?php if ($p['acerto'] === null): ?>
                                <b class="pos"><?= e(t('Bate')) ?></b>
                            <?php else: ?>
                                <b><?= e(t('Acerto de {valor}', ['valor' => moneySigned($p['acerto'])])) ?></b>
                                <?php if ($p['entra'] !== (string) $a['data']): ?>
                                    <small><?= e(t('{mes} está fechado: o acerto entra em {data}.', ['mes' => mb_convert_case(mesExtenso(substr((string) $a['data'], 0, 8) . '01'), MB_CASE_TITLE), 'data' => diaMes($p['entra'])])) ?></small>
                                <?php endif; ?>
                            <?php endif; ?>
                        </dd>
                    </div>
                <?php endforeach; ?>
            </dl>
            <?php if ($algum): ?>
                <form method="post" action="/acertos" class="ac-confirmar">
                    <?= csrfField() ?>
                    <input type="hidden" name="volta" value="<?= e($volta) ?>">
                    <input type="hidden" name="quando" value="<?= e($quando) ?>">
                    <input type="hidden" name="data" value="<?= e((string) $a['data']) ?>">
                    <input type="hidden" name="descricao" value="<?= e($descricao) ?>">
                    <?php foreach ((array) ($a['contas'] ?? []) as $id => $v): if ((string) $v === '') { continue; } ?>
                        <input type="hidden" name="saldo_conta[<?= (int) $id ?>]" value="<?= e((string) $v) ?>">
                    <?php endforeach; ?>
                    <input type="hidden" name="confirmar" value="1">
                    <button class="btn btn-primary" type="submit"><?= e(t('Lançar o acerto')) ?></button>
                    <a class="btn-link" href="/acertos/novo?volta=<?= e(rawurlencode($volta)) ?>"><?= e(t('Corrigir')) ?></a>
                </form>
            <?php else: ?>
                <p><?= e(t('O saldo bate com o banco. Não há nada para acertar.')) ?></p>
                <a class="btn btn-contorno" href="<?= e($volta) ?>"><?= e(t('Voltar')) ?></a>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <form class="card ct-form ac-form" method="post" action="/acertos">
            <?= csrfField() ?>
            <input type="hidden" name="volta" value="<?= e($volta) ?>">
            <?php if ($erro !== null): ?><p class="aviso aviso-error" id="ac-erro" role="alert"><?= e($erro) ?></p><?php endif; ?>
            <?php if ($varias): ?>
                <p class="ac-rotulo"><?= e(t('Saldo que o banco mostra, em cada conta')) ?></p>
                <?php \Financeiro\View::partial('partials/saldos_contas', ['contas' => $contas, 'digitado' => (array) ($a['contas'] ?? []), 'pref' => 'ac', 'invalido' => $erro !== null]); ?>
            <?php else: $c = $contas[0]; $id = (int) $c['id']; ?>
                <label class="ac-campo" for="ac-saldo"><span><?= e(t('Saldo que o banco mostra')) ?></span>
                    <input id="ac-saldo" type="text" name="saldo_conta[<?= $id ?>]" inputmode="decimal" autocomplete="off" enterkeyhint="done" placeholder="<?= e(t('ex.: 1.546,00')) ?>"
                           value="<?= e((string) (($a['contas'] ?? [])[$id] ?? '')) ?>" data-conf-app="<?= e(number_format((float) $c['saldo'], 2, '.', '')) ?>" aria-describedby="ac-app"<?= $erro !== null ? ' aria-invalid="true" data-autofocus' : '' ?>>
                    <small class="conf-app num" id="ac-app"><?= e(t('No app: {valor}', ['valor' => num((float) $c['saldo'])])) ?></small>
                    <output class="conf-dif num" data-conf-dif aria-live="polite"></output>
                </label>
            <?php endif; ?>
            <fieldset class="quando" data-quando>
                <legend class="ac-rotulo"><?= e(t('Em que dia')) ?></legend>
                <label class="chip-r"><input type="radio" name="quando" value="hoje"<?= $quando === 'hoje' ? ' checked' : '' ?>><span><?= e(t('Hoje')) ?></span></label>
                <label class="chip-r"><input type="radio" name="quando" value="outro"<?= $quando === 'outro' ? ' checked' : '' ?>><span><?= e(t('Outro dia')) ?></span></label>
                <input type="date" name="data" class="quando-data" value="<?= e($data) ?>" min="<?= e($minimo) ?>" max="<?= e($hoje) ?>" aria-label="<?= e(t('data do saldo')) ?>">
            </fieldset>
            <label><span><?= e(t('Descrição (opcional)')) ?></span><input type="text" name="descricao" maxlength="255" value="<?= e($descricao) ?>" placeholder="<?= e(t('Acerto com o banco')) ?>"></label>
            <button class="btn btn-primary" type="submit"><?= e(t('Ver o acerto')) ?></button>
        </form>
    <?php endif; ?>
</div>
