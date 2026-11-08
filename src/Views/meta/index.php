<?php
/**
 * Metas: o que vale em cada mês, por categoria de gasto, e as contas fixas.
 * De cima para baixo: o topo com o seletor de meses; a resposta, "Para usar neste mês", que é o que entrou e já
 * estava na conta, menos o gasto e menos as contas fixas que ainda faltam pagar, com a conta ao lado; e uma tabela
 * por grupo (Essenciais, Não essenciais, Contas fixas): a meta, o gasto, a barra fina e o que falta. Clicar na linha
 * abre o editor de recorrência na própria linha: valor, a partir de que mês e até quando (POST /meta/editar; nas
 * contas, POST /meta/contas/{id}, com o dia do vencimento).
 * Variáveis: $mes, $meses, $hoje, $opcoes (mês => rótulo), $essenciais, $naoEssenciais, $contas (linhas do
 * MetaController::linha), $addEss, $addNao, $elegiveis, $erro, $renda, $totalMeta, $gastoTotal, $gastoComMeta,
 * $gastoSemMeta, $metaEss, $metaNaoEss, $gastoEss, $gastoNaoEss e $totalContas. O gasto é o que saiu da conta, como
 * no Mês: a compra no cartão conta pela fatura paga, e não na categoria dela.
 */
use Financeiro\Services\ChecklistContas;

$csrf = csrfField();
$valorCampo = static fn(float $v): string => numInput($v, fmod(abs($v), 1) > 0.004 ? 2 : 0);
$erroDe = static fn(int $cat): ?array => $erro !== null && (int) ($erro['cat'] ?? 0) === $cat ? $erro : null;
$mesTitulo = t('{mes} de {ano}', ['mes' => mb_convert_case(mesExtenso($mes), MB_CASE_TITLE), 'ano' => substr($mes, 0, 4)]);
$esteMes = substr($hoje, 0, 7) === substr($mes, 0, 7);

/* -------- a resposta: o que entrou e já estava na conta − o gasto − as contas fixas que ainda faltam pagar -------- */
$fechado = false;
foreach ($meses as $m) {
    if ((string) $m['mes'] === $mes) {
        $fechado = ($m['situacao'] ?? '') === 'REAL';
    }
}
// as contas fixas do mês que ainda faltam pagar (as mesmas do Mês e do Início); num mês fechado, nada falta
$porPagar = 0.0;
$faltaConta = [];        // categoria => o que falta pagar dela no mês
$agendaConta = [];       // categoria => o dia do pagamento agendado: ainda não saiu, então continua em "falta pagar"
if (!$fechado) {
    foreach (ChecklistContas::aPagar($mes, $hoje) as $c) {
        $porPagar += (float) $c['falta'];
        $faltaConta[(int) $c['categoria_id']] = (float) $c['falta'];
        if ($c['status'] === 'agendado') {
            $agendaConta[(int) $c['categoria_id']] = (string) $c['data_pagamento'];
        }
    }
}
$porPagar = round($porPagar, 2);
$paraUsar = round($renda - $gastoTotal - $porPagar, 2);
$noVermelho = $paraUsar < -0.004;

/* -------- o editor: valor, a partir de, até (e, nas contas, o dia do vencimento) -------- */
$editor = static function (array $l, string $acao, bool $conta) use ($csrf, $mes, $opcoes, $valorCampo, $erroDe): void {
    $erro  = $erroDe($l['id']);
    $valor = $l['valor'] > 0.004 || $l['proprio'] ? $valorCampo($l['valor']) : '';
    $desde = $erro !== null && isset($opcoes[$erro['desde'] ?? '']) ? (string) $erro['desde'] : $mes;
    $ate   = $erro !== null ? (string) ($erro['ate'] ?? '') : '';
    $id    = 'ed-' . ($conta ? 'conta-' : '') . $l['id'];
    $idErro = $id . '-erro'; ?>
    <form class="mt-ed" method="post" action="<?= e($acao) ?>">
        <?= $csrf ?>
        <input type="hidden" name="ver" value="<?= e($mes) ?>">
        <?php if ($conta): ?>
            <input type="hidden" name="valor_era" value="<?= e($valor) ?>">
            <input type="hidden" name="desde_era" value="<?= e($mes) ?>">
        <?php else: ?>
            <input type="hidden" name="categoria_id" value="<?= (int) $l['id'] ?>">
        <?php endif; ?>
        <div class="mt-ed-campos">
            <label class="mt-campo mt-campo-valor">
                <span><?= e(t('Valor por mês')) ?></span>
                <input type="text" name="valor" inputmode="decimal" autocomplete="off" enterkeyhint="done" placeholder="<?= e(t('sem meta')) ?>"
                       value="<?= e($erro !== null ? (string) $erro['valor'] : $valor) ?>" data-mes-valor<?= $erro !== null ? ' aria-invalid="true" aria-describedby="' . e($idErro) . '" data-autofocus' : '' ?>>
            </label>
            <label class="mt-campo">
                <span><?= e(t('A partir de')) ?></span>
                <select name="desde" id="<?= e($id) ?>-desde" data-meta-desde>
                    <?php foreach ($opcoes as $k => $rotulo): ?>
                        <option value="<?= e($k) ?>"<?= $k === $desde ? ' selected' : '' ?>><?= e($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="mt-campo">
                <span><?= e(t('Até')) ?></span>
                <select name="ate" data-meta-ate>
                    <option value=""<?= $ate === '' ? ' selected' : '' ?>><?= e(t('Sem fim')) ?></option>
                    <option value="so"<?= $ate === 'so' ? ' selected' : '' ?>><?= e(t('Só este mês')) ?></option>
                    <?php foreach ($opcoes as $k => $rotulo): ?>
                        <option value="<?= e($k) ?>"<?= $k === $ate ? ' selected' : '' ?><?= $k <= $desde ? ' disabled' : '' ?>><?= e(t('até {mes}', ['mes' => monthLabel($k)])) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php if ($conta): ?>
                <label class="mt-campo mt-campo-dia">
                    <span><?= e(t('Vence no dia')) ?></span>
                    <input type="text" name="dia" inputmode="numeric" pattern="[0-9]*" maxlength="2" autocomplete="off" required
                           value="<?= e($erro !== null && isset($erro['dia']) ? (string) $erro['dia'] : (string) $l['dia']) ?>">
                </label>
            <?php endif; ?>
        </div>
        <?php if ($erro !== null): ?><p class="mt-erro" id="<?= e($idErro) ?>" role="alert"><?= svgIcon('alert', 16) ?><?= e((string) $erro['msg']) ?></p><?php endif; ?>
        <div class="mt-ed-acoes">
            <button type="submit" name="acao" value="salvar" class="btn btn-principal btn-sm"><?= e(t('Salvar')) ?></button>
            <?php if (!$conta && ($l['padrao'] !== null || $l['valor'] > 0.004)): ?>
                <button type="submit" name="acao" value="remover" class="btn-link"><?= e(t('Sem meta')) ?></button>
            <?php endif; ?>
        </div>
    </form>
<?php };

/* -------- o valor próprio do mês, com a meta dos outros meses -------- */
$proprio = static fn(array $l): string => $l['proprio']
    ? '<small class="mt-proprio">' . e(t('só em {mes}, a meta é {valor}', ['mes' => mesExtenso($mes), 'valor' => num((float) $l['padrao'])])) . '</small>'
    : '';

/* -------- as colunas de uma linha: meta, gasto, a barra fina e o que falta -------- */
// no celular, a meta aparece ao lado da barra ("meta 1.600,00")
$colunas = static function (float $valor, float $gasto, bool $semMeta, string $falta = '', ?string $semTxt = null): void {
    $p = $valor > 0.004 ? min(1.0, $gasto / $valor) : 0.0;
    $passou = $valor > 0.004 && $gasto - $valor > 0.004; ?>
    <span class="mt-c mt-c-meta num"><span class="sr-only mt-rot"><?= e(t('meta')) ?> </span><?= $semMeta ? '<span class="mt-sem">' . e($semTxt ?? t('sem meta')) . '</span>' : e(num($valor)) ?></span>
    <span class="mt-c mt-c-gasto"><span class="sr-only"><?= e(t('gasto')) ?> </span><?= $gasto > 0.004 ? valor(-$gasto, 'saida', ['centavos' => false]) : '<span class="num fraco">' . e(num(0)) . '</span>' ?></span>
    <span class="mt-c mt-c-barra" aria-hidden="true"><span class="meta-barra<?= $passou ? ' passou' : '' ?>"><i style="--p:<?= e(number_format($p, 3, '.', '')) ?>"></i></span></span>
    <span class="mt-c mt-c-falta num<?= $passou ? ' alerta' : '' ?>"><?php
        if ($falta !== '') {
            echo $falta;
        } elseif ($semMeta) {
            echo '';
        } elseif ($passou) {
            echo e(t('passou {valor}', ['valor' => num($gasto - $valor)]));
        } else {
            echo e(t('falta {valor}', ['valor' => num(max(0.0, $valor - $gasto))]));
        } ?></span>
<?php };

/* -------- uma categoria -------- */
$linha = static function (array $l) use ($editor, $proprio, $colunas): void {
    $valor = (float) $l['valor'];
    $gasto = (float) $l['gasto'];
    $r = $valor > 0.004 ? $gasto / $valor : 0.0;
    $cls = $valor <= 0.004 ? 'is-none' : ($r > 1.0001 ? 'is-over' : ($r >= 0.8 ? 'is-near' : 'is-ok')); ?>
    <details class="mt-linha <?= $cls ?>" id="c<?= (int) $l['id'] ?>" data-valor="<?= e(number_format($valor, 2, '.', '')) ?>" data-gasto="<?= e(number_format($gasto, 2, '.', '')) ?>" data-nome="<?= e($l['nome']) ?>"<?= !empty($l['aberta']) ? ' open' : '' ?>>
        <summary>
            <span class="mt-c mt-c-nome"><?= iconeCategoria((int) $l['id'], (string) $l['nome'], $l['grupo'], 20, 'mt-ic') ?><span class="mt-nome-txt"><span class="mt-nome"><?= e($l['nome']) ?></span><?= $proprio($l) ?></span></span>
            <?php $colunas($valor, $gasto, $valor <= 0.004); ?>
        </summary>
        <?php $editor($l, '/meta/editar', false); ?>
    </details>
<?php };

/* -------- o cabeçalho das colunas e o total do grupo -------- */
$cabecalho = static function (string $primeira, float $meta, float $gasto): void { ?>
    <div class="mt-cab" aria-hidden="true">
        <span class="mt-c mt-c-nome"><?= e($primeira) ?></span>
        <span class="mt-c mt-c-meta"><?= e(t('Meta')) ?></span>
        <span class="mt-c mt-c-gasto"><?= e(t('Gasto')) ?></span>
        <span class="mt-c mt-c-barra"></span>
        <span class="mt-c mt-c-falta"></span>
    </div>
<?php };

$grupo = static function (string $titulo, string $id, array $cats, float $meta, float $gasto) use ($linha, $cabecalho): void {
    if ($cats === []) {
        return;
    } ?>
    <section class="mt-grupo" aria-labelledby="mt-<?= e($id) ?>">
        <div class="secao-cab">
            <h2 id="mt-<?= e($id) ?>"><?= e($titulo) ?></h2>
            <p class="num"><?= t('{gasto} de {meta}', ['gasto' => e(num($gasto)), 'meta' => e(num($meta))]) ?></p>
        </div>
        <?php $cabecalho(t('Categoria'), $meta, $gasto); ?>
        <div class="mt-lista"><?php foreach ($cats as $c) { $linha($c); } ?></div>
    </section>
<?php };

/* -------- uma conta fixa -------- */
// nas contas, o que falta é o mesmo da resposta (ChecklistContas): a Fatura do cartão paga conta como paga, mesmo
// com o gasto dela contado pelas compras
$linhaConta = static function (array $l) use ($editor, $proprio, $csrf, $mes, $hoje, $colunas, $faltaConta, $agendaConta, $fechado): void {
    $valor = (float) $l['valor'];
    $gasto = (float) $l['gasto'];
    $resta = $faltaConta[(int) $l['id']] ?? 0.0;
    $agenda = $agendaConta[(int) $l['id']] ?? null;
    $falta = $valor <= 0.004 ? '' : ($agenda !== null && $resta > 0.004
        ? '<span class="fraco">' . e(t('agendado dia {dia}', ['dia' => (int) substr($agenda, 8, 2)])) . '</span>'
        : ($resta > 0.004
        ? e(t('falta {valor}', ['valor' => num($resta)]))
        : '<span class="mt-paga">' . ($fechado && $gasto < 0.005 ? e(t('sem pagamento')) : e(t('paga'))) . '</span>'));
    if ($l['do_cartao']) { ?>
        <a class="mt-linha mt-ro" id="conta-<?= (int) $l['id'] ?>" href="/cartao#fatura" data-valor="<?= e(number_format($valor, 2, '.', '')) ?>" data-gasto="<?= e(number_format($gasto, 2, '.', '')) ?>" data-nome="<?= e($l['nome']) ?>">
            <span class="mt-c mt-c-nome"><?= iconeCategoria((int) $l['id'], (string) $l['nome'], $l['grupo'], 20, 'mt-ic') ?><span class="mt-nome-txt"><span class="mt-nome"><?= e($l['nome']) ?></span><small><?= e(t('vem do Cartão, ver a fatura')) ?></small></span></span>
            <?php $colunas($valor, $gasto, false, $falta); ?>
        </a>
        <?php return;
    }
    $desativarDe = max($mes, substr($hoje, 0, 7) . '-01'); ?>
    <details class="mt-linha mt-conta" id="conta-<?= (int) $l['id'] ?>" data-valor="<?= e(number_format($valor, 2, '.', '')) ?>" data-gasto="<?= e(number_format($gasto, 2, '.', '')) ?>" data-nome="<?= e($l['nome']) ?>"<?= !empty($l['aberta']) ? ' open' : '' ?>>
        <summary>
            <span class="mt-c mt-c-nome"><?= iconeCategoria((int) $l['id'], (string) $l['nome'], $l['grupo'], 20, 'mt-ic') ?><span class="mt-nome-txt"><span class="mt-nome"><?= e($l['nome']) ?></span><small class="num"><?= e(t('vence dia {dia}', ['dia' => $l['dia']])) ?></small><?= $proprio($l) ?></span></span>
            <?php $colunas($valor, $gasto, $valor <= 0.004, $falta, false, t('sem valor')); ?>
        </summary>
        <?php $editor($l, '/meta/contas/' . $l['conta'], true); ?>
        <form class="mt-ed-fora" method="post" action="/meta/contas/<?= (int) $l['conta'] ?>/desativar">
            <?= $csrf ?>
            <input type="hidden" name="ver" value="<?= e($mes) ?>">
            <input type="hidden" name="desde" value="<?= e($desativarDe) ?>">
            <button type="submit" class="btn-link"><?= e(t('Desativar a partir de {mes}', ['mes' => monthLabel($desativarDe)])) ?></button>
        </form>
    </details>
<?php };

$optAdd = static function (array $cats): void {
    foreach ($cats as $c) { ?>
        <option value="<?= (int) $c['id'] ?>"><?= e($c['nome']) ?></option>
    <?php }
};
$temAdd = $addEss !== [] || $addNao !== [];
$erroNova = $erroDe(-1);
foreach ($contas as &$c) {
    $c['aberta'] = $erroDe($c['id']) !== null;
}
unset($c);
$gastoContas = round(array_sum(array_column($contas, 'gasto')), 2);
?>
<div class="grade mt" data-renda="<?= e(number_format($renda, 2, '.', '')) ?>" data-gasto-total="<?= e(number_format($gastoTotal, 2, '.', '')) ?>" data-por-pagar="<?= e(number_format($porPagar, 2, '.', '')) ?>">
    <?php \Financeiro\View::partial('partials/topo', ['titulo' => t('Metas'), 'apoio' => $mesTitulo, 'mes' => $mes, 'meses' => $meses, 'href' => '/meta?mes=', 'hoje' => $hoje,
        'acoes' => dica('metas', t('Metas'))]); ?>

    <!-- a resposta: o que dá para usar, com a conta ao lado -->
    <section class="faixa-grade mt-resposta" aria-labelledby="mt-resposta-t">
        <?php if ($renda <= 0.004 && $gastoTotal <= 0.004): ?>
            <div class="vazio mt-vazio">
                <p class="titulo" id="mt-resposta-t"><?= e(t('As metas mostram quanto ainda dá para gastar em cada categoria.')) ?></p>
                <p><?= e(t('Lance as entradas do mês para ver o que fica para usar.')) ?></p>
                <a class="btn btn-contorno" href="/lancar?volta=<?= e(rawurlencode('/meta?mes=' . $mes)) ?>" data-lancar><?= svgIcon('plus', 16) ?><?= e(t('Lançar uma entrada')) ?></a>
            </div>
        <?php else: ?>
            <div class="mt-resposta-num">
                <h2 class="rot-resposta" id="mt-resposta-t"><?= $noVermelho ? e(t('No vermelho')) : ($esteMes ? e(t('Para usar neste mês')) : e(t('Para usar em {mes}', ['mes' => mesExtenso($mes)]))) ?></h2>
                <p class="resposta-num<?= $noVermelho ? ' alerta' : '' ?>" data-para-usar><?= valor($paraUsar, 'neutro', ['moeda' => true]) ?></p>
                <?php if (!$fechado && $porPagar > 0.004): ?>
                    <p class="mt-resposta-sub"><?= e(t('já sem as contas fixas que faltam pagar')) ?></p>
                <?php endif; ?>
            </div>
            <dl class="mt-conta-dl num" aria-label="<?= e(t('A conta do valor para usar')) ?>">
                <div><dt><?= e(t('Entrou e já estava na conta')) ?></dt><dd><?= valor($renda, 'neutro', ['centavos' => false]) ?></dd></div>
                <div><dt><?= e(t('Gastou')) ?></dt><dd><?= valor(-$gastoTotal, 'saida', ['centavos' => false]) ?></dd></div>
                <?php if (!$fechado): ?>
                    <div><dt><?= e(t('Contas fixas a pagar')) ?></dt><dd><?= valor(-$porPagar, 'previsto', ['centavos' => false]) ?></dd></div>
                <?php endif; ?>
                <div class="mt-conta-fim"><dt><?= $noVermelho ? e(t('No vermelho')) : e(t('Para usar')) ?></dt><dd><?= valor($paraUsar, 'neutro', ['centavos' => false]) ?></dd></div>
            </dl>
        <?php endif; ?>
        <?php if ($gastoSemMeta > 0.004): ?>
            <div class="mt-notas">
                <p class="num"><?= e(t('Do gasto, {valor} foi em categorias sem meta.', ['valor' => num($gastoSemMeta)])) ?></p>
            </div>
        <?php endif; ?>
    </section>

    <!-- as tabelas por grupo -->
    <div class="faixa-grade mt-tabelas">
        <form class="mt-add" method="get" action="/meta">
            <input type="hidden" name="mes" value="<?= e($mes) ?>">
            <label for="meta-add"><?= e(t('Adicionar categoria')) ?></label>
            <select id="meta-add" name="add" data-autosubmit<?= $temAdd ? '' : ' disabled' ?>>
                <option value=""><?= $temAdd ? e(t('Escolher categoria…')) : e(t('Todas as categorias já têm meta')) ?></option>
                <?php if ($addEss !== []): ?><optgroup label="<?= e(t('Essenciais')) ?>"><?php $optAdd($addEss); ?></optgroup><?php endif; ?>
                <?php if ($addNao !== []): ?><optgroup label="<?= e(t('Não essenciais')) ?>"><?php $optAdd($addNao); ?></optgroup><?php endif; ?>
            </select>
            <noscript><button type="submit" class="btn btn-contorno"<?= $temAdd ? '' : ' disabled' ?>><?= e(t('Adicionar')) ?></button></noscript>
        </form>

        <?php $grupo(t('Essenciais'), 'ess', $essenciais, $metaEss, $gastoEss); ?>
        <?php $grupo(t('Não essenciais'), 'nao', $naoEssenciais, $metaNaoEss, $gastoNaoEss); ?>

        <!-- contas fixas: valor, vencimento e até quando valem -->
        <section class="mt-grupo" id="contas-fixas" aria-labelledby="mt-contas">
            <div class="secao-cab">
                <h2 id="mt-contas"><?= e(t('Contas fixas')) ?></h2>
                <p class="num"><?= t('{gasto} de {meta}', ['gasto' => e(num($gastoContas)), 'meta' => e(num($totalContas))]) ?></p>
            </div>
            <?php if ($contas !== []): ?>
                <?php $cabecalho(t('Conta fixa'), $totalContas, $gastoContas); ?>
                <div class="mt-lista"><?php foreach ($contas as $c) { $linhaConta($c); } ?></div>
            <?php endif; ?>
            <details class="mt-incluir" id="conta-nova"<?= $erroNova !== null || $contas === [] ? ' open' : '' ?>>
                <summary><?= svgIcon('plus', 16) ?><?= e(t('Adicionar conta fixa')) ?></summary>
                <form class="mt-ed" method="post" action="/meta/contas">
                    <?= $csrf ?>
                    <input type="hidden" name="ver" value="<?= e($mes) ?>">
                    <div class="mt-ed-campos">
                        <label class="mt-campo mt-campo-nome">
                            <span><?= e(t('Nome')) ?></span>
                            <input type="text" name="nome" maxlength="100" autocomplete="off" required list="contas-elegiveis"
                                   value="<?= e((string) ($erroNova['nome'] ?? '')) ?>"<?= $erroNova !== null ? ' aria-invalid="true" aria-describedby="conta-nova-erro" data-autofocus' : '' ?>>
                        </label>
                        <?php if ($elegiveis !== []): ?>
                            <datalist id="contas-elegiveis"><?php foreach ($elegiveis as $n): ?><option value="<?= e($n) ?>"></option><?php endforeach; ?></datalist>
                        <?php endif; ?>
                        <label class="mt-campo mt-campo-valor">
                            <span><?= e(t('Valor por mês')) ?></span>
                            <input type="text" name="valor" inputmode="decimal" autocomplete="off" value="<?= e((string) ($erroNova['valor'] ?? '')) ?>">
                        </label>
                        <label class="mt-campo mt-campo-dia">
                            <span><?= e(t('Vence no dia')) ?></span>
                            <input type="text" name="dia" inputmode="numeric" pattern="[0-9]*" maxlength="2" autocomplete="off" required value="<?= e((string) ($erroNova['dia'] ?? '')) ?>">
                        </label>
                        <label class="mt-campo">
                            <span><?= e(t('A partir de')) ?></span>
                            <select name="desde">
                                <?php foreach ($opcoes as $k => $rotulo): ?>
                                    <option value="<?= e($k) ?>"<?= $k === $mes ? ' selected' : '' ?>><?= e($rotulo) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <?php if ($erroNova !== null): ?><p class="mt-erro" id="conta-nova-erro" role="alert"><?= svgIcon('alert', 16) ?><?= e((string) $erroNova['msg']) ?></p><?php endif; ?>
                    <div class="mt-ed-acoes"><button type="submit" class="btn btn-principal btn-sm"><?= e(t('Adicionar')) ?></button></div>
                </form>
            </details>
        </section>
    </div>
</div>
