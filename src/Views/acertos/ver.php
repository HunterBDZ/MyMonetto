<?php
/**
 * Um acerto de saldo, só para ler: o valor, a conta, o dia em que entrou e o dia do saldo do banco que ele acerta.
 * Excluir fica aqui, em vermelho, enquanto o mês dele estiver aberto. Variáveis: $acerto, $conta, $varias, $fechado
 * (mensagem ou null), $volta.
 */
$ac = $acerto;
$rotulo = \Financeiro\Controllers\AcertoController::rotulo($ac);
?>
<div class="ct ct-editar ac">
    <header class="lf-topo ct-ed-topo">
        <a class="lf-x lf-voltar" href="<?= e($volta) ?>" aria-label="<?= e(t('voltar')) ?>"><?= svgIcon('chevron', 20, 'lf-seta') ?></a>
        <h1 class="lf-titulo"><?= e(t('Acerto de saldo')) ?></h1>
    </header>
    <p class="ct-escada-sub"><?= e(t('Não é gasto nem entrada: acerta o saldo do app com o do banco.')) ?></p>
    <section class="card ct-form ac-previa">
        <?php if ($fechado !== null): ?><p class="aviso aviso-warn" role="status"><?= e($fechado) ?> <a class="link" href="/mes?mes=<?= e(substr((string) $ac['data'], 0, 8) . '01') ?>"><?= e(t('Abrir o Mês')) ?></a></p><?php endif; ?>
        <dl class="ac-linhas">
            <div class="ac-linha"><dt><?= e(t('Descrição')) ?></dt><dd><?= e($rotulo) ?></dd></div>
            <div class="ac-linha"><dt><?= e(t('Valor')) ?></dt><dd class="num"><b><?= e(moneySigned((float) $ac['valor'])) ?></b></dd></div>
            <?php if ($varias && $conta !== null): ?><div class="ac-linha"><dt><?= e(t('Conta')) ?></dt><dd><?= e(nomeConta($conta)) ?></dd></div><?php endif; ?>
            <div class="ac-linha"><dt><?= e(t('Entrou no saldo em')) ?></dt><dd><?= e(diaMesAno((string) $ac['data'])) ?></dd></div>
            <?php if ((string) $ac['referente'] !== (string) $ac['data']): ?>
                <div class="ac-linha"><dt><?= e(t('Acerta o saldo do banco de')) ?></dt><dd><?= e(diaMesAno((string) $ac['referente'])) ?></dd></div>
            <?php endif; ?>
            <div class="ac-linha"><dt><?= e(t('Lançado por')) ?></dt><dd><?= e((string) $ac['usuario_nome']) ?></dd></div>
        </dl>
    </section>
    <?php if ($fechado === null): ?>
    <form method="post" action="/acertos/<?= (int) $ac['id'] ?>/excluir" class="ct-excluir" data-confirm="<?= e(t('Excluir este acerto? O saldo volta a ter a diferença.')) ?>">
        <?= csrfField() ?><input type="hidden" name="volta" value="<?= e($volta) ?>">
        <button type="submit" class="btn-link-danger"><?= e(t('Excluir acerto')) ?></button>
    </form>
    <?php endif; ?>
</div>
