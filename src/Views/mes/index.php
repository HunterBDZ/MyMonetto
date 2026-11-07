<?php
/**
 * Mês: o objeto do app. O topo é o mês (com o seletor de meses e o ⋯); o painel diz com quanto o mês fecha, com o
 * botão Fechar e a barra "para onde vai o dinheiro do mês"; embaixo, o livro-razão: uma tabela com os grupos Entradas,
 * Contas fixas, Dia a dia e Guardado e empréstimo, as colunas Previsto, Realizado e Situação, e o fim do mês sob o fio
 * duplo. No celular, cada linha vira o nome, o realizado e, embaixo, o previsto e a situação.
 * Base dos números, a mesma do plano: fechado = só o lançado; aberto = em cada categoria, o maior entre o lançado e o
 * previsto; futuro = previsto. Assim a coluna Previsto soma o fim do mês do plano. A coluna Realizado é o realizado
 * ('real'): o agendado (data depois de hoje) fica fora dela até o dia dele, mas conta no Previsto ('lancado').
 * Mês aberto ou futuro: clicar no previsto abre a edição da previsão na linha (POST /mes/previsao); "+ incluir" põe no
 * mês uma categoria sem previsão. Mês fechado: outro estado, com Planejado, Realizado e Diferença, e o fio de fechamento.
 * Nenhum cálculo de motor aqui: tudo vem do MesController (e de Services\Destino).
 */
use Financeiro\Services\Destino;
use Financeiro\View;

$fmt     = static fn(float $v): string => num($v);
$csrf    = csrfField();
$mesLbl  = monthLabel($mes);
$mesNome = mesExtenso($mes);
$mesTit  = mb_convert_case($mesNome, MB_CASE_TITLE);
$fechado = $estado === 'real';
$mesCal  = substr((string) $hoje, 0, 8) . '01';
$fimMesData = date('Y-m-t', strtotime($mes));

/* ---------------------------------------------------------------- base dos valores */
// conta (Fixos/Cartão/Ano): fechado → lançado; aberto → o maior entre lançado e previsto; futuro → previsto.
// Vem pronto do MesController, pela regra do motor: a Fatura do cartão paga num mês anterior não conta de novo.
$valorConta = static fn(array $l): float => $l['valor'];
$valorDia = static fn(array $l): float => match ($estado) {
    'real'   => $l['real'],
    'aberto' => max($l['lancado'], $l['prev']),
    default  => $l['prev'],
};
$valorEnt = $valorDia;

$contas = array_merge($contasAbertas, $contasPagas);
if (!$editavel) {
    usort($contas, static fn($a, $b) => $valorConta($b) <=> $valorConta($a));
}
usort($dia, static fn($a, $b) => $valorDia($b) <=> $valorDia($a));
$movEntra = array_values(array_filter($movimentos, static fn(array $m): bool => $m['entra']));
$movSai   = array_values(array_filter($movimentos, static fn(array $m): bool => !$m['entra']));

$inicioMes   = isset($linha['sobrou_mes_anterior']) ? (float) $linha['sobrou_mes_anterior'] : 0.0;
// o acerto de saldo com o banco do mês (AcertoModel): ajusta o que veio do mês anterior, com sinal
$acertoMes   = round((float) ($linha['acerto'] ?? 0.0), 2);
$totEntradas = $inicioMes + $acertoMes + array_sum(array_map($valorEnt, $entradas)) + array_sum(array_column($movEntra, 'valor'));
$totContas   = array_sum(array_map($valorConta, $contas));
$totDia      = array_sum(array_map($valorDia, $dia));
$totGuardado = array_sum(array_column($movSai, 'valor'));
$totSaidas   = $totContas + $totDia + $totGuardado;
$fimMes      = (float) $linha['fica_na_conta'];


/* ---------------------------------------------------------------- peças das células */
$fraco = '<span class="fraco" aria-label="' . e(t('nada lançado')) . '">·</span>';
// o previsto: saída com "−", em cinza; o realizado: saída em vermelho com "−", entrada em verde com "+"
$prevTxt = static fn(float $v, bool $saida): string => valor($saida ? -$v : $v, 'previsto', ['centavos' => false]);
$realTxt = static fn(float $v, bool $saida): string => abs($v) < 0.005 ? $fraco : valor($v, $saida ? 'saida' : 'entrada', ['centavos' => false]);
$diferenca = static function (float $real, float $plan, bool $saida): string {
    $d = round($real - $plan, 2);
    if (abs($d) < 0.005) {
        return '';
    }
    if ($saida) {
        return $d > 0
            ? '<span class="mes-dif alerta">' . e(t('passou {valor}', ['valor' => num($d)])) . '</span>'
            : '<span class="mes-dif">' . e(t('sobrou {valor}', ['valor' => num(-$d)])) . '</span>';
    }
    return $d > 0
        ? '<span class="mes-dif v-entrada">' . e(t('{valor} a mais', ['valor' => num($d)])) . '</span>'
        : '<span class="mes-dif atencao">' . e(t('faltou {valor}', ['valor' => num(-$d)])) . '</span>';
};
$carimboDe = static fn(?string $data): string => $data !== null && $data !== ''
    ? carimbo($data)
    : '<span class="carimbo">' . svgIcon('check', 12) . e(t('pago')) . '</span>';

/* ---------------------------------------------------------------- edição da previsão (mês em PREVISAO) */
// O previsto de cada linha é um <details>: clicar abre, na própria linha, o painel que grava a previsão do mês
// (POST /mes/previsao). A meta é a previsão padrão de todo mês; um valor próprio do mês é "ajustado" e pode voltar à meta.
$ehUltimo = $mes === $ultimo;
$erroDe   = static fn(array $l): ?array => $erroLinha !== null && (int) ($erroLinha['cat'] ?? 0) === (int) $l['id'] ? $erroLinha : null;

// veio menos que o previsto (mês aberto): a pessoa diz se esse foi o valor final do mês ou se ainda falta
$aMenos = static function (array $l, string $tipo) use ($csrf, $mes): void {
    $am = $l['a_menos'] ?? null;
    if ($am === null) {
        return;
    } ?>
    <form class="mes-final" method="post" action="/mes/previsao">
        <?= $csrf ?>
        <input type="hidden" name="mes" value="<?= e($mes) ?>">
        <input type="hidden" name="categoria_id" value="<?= (int) $l['id'] ?>">
        <p class="mes-final-txt"><?= t('Veio {veio} de {previsto} previstos', ['veio' => '<b class="num">' . e(money($am['veio'])) . '</b>', 'previsto' => '<span class="num">' . e(money($am['previsto'])) . '</span>']) ?></p>
        <?php if ($am['parcela']): ?><p class="mes-final-nota"><?= e(t('Os dois já sem a parcela do empréstimo.')) ?></p><?php endif; ?>
        <div class="mes-final-acoes">
            <button type="submit" name="acao" value="final" class="btn btn-contorno btn-sm"><?= e(t('Foi o valor final')) ?></button>
            <button type="submit" name="acao" value="falta" class="btn btn-contorno btn-sm"><?= $tipo === 'entrada' ? e(t('Ainda falta receber')) : e(t('Ainda falta pagar')) ?></button>
        </div>
        <p class="mes-final-nota"><?= e(t('Valor final: a previsão deste mês passa a ser o que veio. Os outros meses não mudam.')) ?></p>
    </form>
<?php };

$painel = static function (array $l, string $tipo) use ($csrf, $mes, $mesLbl, $estado, $fmt, $erroDe, $ehUltimo, $aMenos): void {
    $aMenos($l, $tipo);
    $erro = $erroDe($l);
    $meta = $l['meta_padrao'];
    $info = [];
    if ($meta !== null) {
        $info[] = $l['ajuste'] ? t('Valor só deste mês. A meta é {valor}.', ['valor' => $fmt((float) $meta)]) : t('Segue a meta, que vale para todo mês sem ajuste.');
    }
    if ($estado === 'aberto') {
        $ja = $tipo === 'conta' ? (float) ($l['pago'] ?? $l['real']) : (float) $l['real'];
        if ($ja > 0.004) {
            $info[] = $tipo === 'entrada' ? t('Recebido até hoje: {valor}.', ['valor' => $fmt($ja)]) : t('Lançado até hoje: {valor}.', ['valor' => $fmt($ja)]);
        }
    }
    $info[] = $tipo === 'entrada' ? t('Muda só a previsão. O que você já recebeu continua igual.') : t('Muda só a previsão. O que você já gastou continua igual.');
    $temAcoes = ($meta !== null && $l['ajuste']) || $l['prev'] > 0.004;
    $idErro = 'mes-erro-' . (int) $l['id']; ?>
    <form class="mes-form" method="post" action="/mes/previsao">
        <?= $csrf ?>
        <input type="hidden" name="mes" value="<?= e($mes) ?>">
        <input type="hidden" name="categoria_id" value="<?= (int) $l['id'] ?>">
        <div class="mes-form-l1">
            <label class="mes-form-campo">
                <span><?= e(t('previsto em {mes}', ['mes' => $mesLbl])) ?></span>
                <input type="text" name="valor" inputmode="decimal" autocomplete="off" enterkeyhint="done"
                       value="<?= e($erro !== null ? (string) $erro['valor'] : numInput((float) $l['prev'])) ?>" data-mes-valor<?= $erro !== null ? ' aria-invalid="true" aria-describedby="' . $idErro . '" data-autofocus' : '' ?>>
            </label>
            <button type="submit" name="acao" value="salvar" class="btn btn-principal btn-sm mes-salvar"><?= e(t('Salvar')) ?></button>
        </div>
        <?php if ($erro !== null): ?><p class="campo-erro" id="<?= $idErro ?>" role="alert"><?= e((string) $erro['msg']) ?></p><?php endif; ?>
        <?php if ($info !== []): ?><p class="mes-form-info"><?= e(implode(' ', $info)) ?></p><?php endif; ?>
        <?php if (!$ehUltimo): ?>
            <label class="mes-prop"><input type="checkbox" name="propagar" value="1"<?= !empty($erro['propagar']) ? ' checked' : '' ?>><span><?= e(t('Também nos meses seguintes')) ?></span></label>
        <?php endif; ?>
        <?php if ($temAcoes): ?>
            <div class="mes-form-acoes">
                <?php if ($meta !== null && $l['ajuste']): ?><button type="submit" name="acao" value="meta" class="btn-link"><?= e(t('Voltar à meta ({valor})', ['valor' => $fmt((float) $meta)])) ?></button><?php endif; ?>
                <?php if ($l['prev'] > 0.004): ?><button type="submit" name="acao" value="tirar" class="btn-link mes-tirar"><?= e(t('Tirar deste mês')) ?></button><?php endif; ?>
            </div>
        <?php endif; ?>
    </form>
<?php };

// a célula do previsto: num mês editável, o número abre a edição na linha
$celulaPrev = static function (array $l, float $v, bool $saida, string $tipo) use ($editavel, $prevTxt, $painel, $erroDe): void {
    if (!$editavel || !empty($l['do_cartao'])) { ?>
        <td class="c-prev"><span class="mes-cel"><?= e(t('previsto')) ?> </span><?= $prevTxt($v, $saida) ?></td>
        <?php return;
    } ?>
    <td class="c-prev">
        <details class="mes-linha mes-ed"<?= $erroDe($l) !== null ? ' open' : '' ?>>
            <summary><span class="sr-only"><?= e(t('Editar o previsto de {nome}:', ['nome' => $l['nome']])) ?> </span><span class="mes-cel"><?= e(t('previsto')) ?> </span><?= $prevTxt($v, $saida) ?></summary>
            <div class="mes-ed-painel"><?php $painel($l, $tipo); ?></div>
        </details>
    </td>
<?php };

$nomeCel = static fn(array $l, string $nome): string => '<td><span class="cat">'
    . iconeCategoria((int) $l['id'], (string) ($l['nome_completo'] ?? $l['nome']), (string) ($l['grupo'] ?? ''), 20)
    . '<span class="mes-nome">' . e($nome) . '</span></span></td>';

/* ---------------------------------------------------------------- situação */
// conta: o carimbo quando paga; vencida em âmbar com o Pagar (só no mês de hoje, como no Início); veio menos
$situConta = static function (array $l) use ($estado, $fmt, $mes, $mesCal, $hoje, $csrf, $carimboDe): string {
    if (!empty($l['do_cartao'])) {
        return '<a class="mes-status mes-status-previsto link" href="/cartao?fatura=' . e($mes) . '#fatura">' . e(t('Vem do Cartão, ver a fatura')) . '</a>';
    }
    $st = $l['status'];
    if ($estado === 'previsto') {
        return $l['vencimento'] !== null ? '<span class="mes-status mes-status-previsto fraco">' . e(t('vence dia {dia}', ['dia' => (int) substr((string) $l['vencimento'], 8, 2)])) . '</span>' : '';
    }
    if ($st === 'agendado') {
        // o pagamento tem data depois de hoje: ainda não saiu, mas não pede pagamento
        return '<span class="mes-status mes-status-previsto fraco">' . e(t('agendado dia {dia}', ['dia' => (int) substr((string) $l['data_pagamento'], 8, 2)])) . '</span>';
    }
    $am = $l['a_menos'] ?? null;
    if ($am !== null || $st === 'pago_parcial') {
        return '<span class="mes-status mes-status-parcial atencao">' . e(t('Veio {veio} de {previsto} previstos', ['veio' => $fmt((float) ($am['veio'] ?? $l['pago'])), 'previsto' => $fmt((float) ($am['previsto'] ?? $l['prev']))])) . '</span>';
    }
    $pagoTotal = $l['pago'] > 0.004 && $l['pago'] >= $l['prev'] - 0.004;
    if (in_array($st, ['pago', 'pago_atrasado'], true) || ($st === null && $pagoTotal)) {
        return '<span class="mes-status mes-status-pago">' . $carimboDe($l['data_pagamento'] ?? null) . '</span>';
    }
    $quando = '';
    if ($st === 'atrasado') {
        $quando = '<span class="mes-status mes-status-venceu atencao">' . e(t('venceu dia {dia}', ['dia' => (int) substr((string) $l['vencimento'], 8, 2)])) . '</span>';
    } elseif ($st === 'pendente') {
        // vence num dia que não é útil: o dia em que ainda dá para pagar sem atraso fica na dica
        $util = ($l['paga_ate'] ?? $l['vencimento']) !== $l['vencimento'] ? ' title="' . e(t('paga até {util} sem atraso', ['util' => diaMes((string) $l['paga_ate'])])) . '"' : '';
        $quando = '<span class="mes-status mes-status-vence"' . $util . '>' . e(t('vence dia {dia}', ['dia' => (int) substr((string) $l['vencimento'], 8, 2)])) . '</span>';
    } else {
        $quando = '<span class="mes-status mes-status-venceu sr-only">' . e(t('não pago')) . '</span>';
    }
    // o Pagar lança o previsto hoje (o mesmo do Início): só conta fixa do mês de hoje, ativa e sem nada lançado
    $pagar = '';
    if ($mes === $mesCal && $l['grupo'] === 'Fixos' && $l['ativo'] && $l['prev'] > 0.004 && $l['lancado'] <= 0.004) {
        $pagar = '<form class="mes-pagar" method="post" action="/lancamentos/pago" data-espera="' . e(t('Lançando…')) . '">' . $csrf
            . '<input type="hidden" name="categoria_id" value="' . (int) $l['id'] . '">'
            . '<input type="hidden" name="volta" value="' . e('/mes?mes=' . $mes) . '">'
            . '<button type="submit" class="btn btn-contorno btn-sm btn-pagar"><span class="circ" aria-hidden="true"></span>' . e(t('Pagar'))
            . '<span class="sr-only"> ' . e($l['nome']) . '</span></button></form>';
    }
    return '<span class="mes-situ">' . $quando . $pagar . '</span>';
};

// dia a dia: a barra fina com o gasto e quanto falta (ou quanto passou) da meta do mês
$situDia = static function (array $l) use ($estado): string {
    if ($estado === 'previsto') {
        if ($l['meta_padrao'] === null) {
            return '';
        }
        return $l['ajuste']
            ? '<span class="mes-origem is-ajuste">' . e(t('ajustado neste mês, a meta é {valor}', ['valor' => num((float) $l['meta_padrao'])])) . '</span>'
            : '<span class="mes-origem fraco">' . e(t('segue a meta')) . '</span>';
    }
    // o mês com valor próprio compara com ele, não com a meta de todo mês ("0 de 800", não "0 de 1.000")
    $ref = !empty($l['ajuste']) ? (float) $l['prev'] : ($l['meta'] ?? null);
    if ($ref === null && $l['prev'] > 0.004) {
        $ref = (float) $l['prev'];
    }
    $gasto = (float) $l['real'];
    if ($ref === null || ($ref <= 0.004 && $gasto <= 0.004)) {
        return '';
    }
    if ($ref <= 0.004) {
        // tirada do mês (previsto zero) com gasto: o plano conta o gasto, mas não há meta para passar
        return '<span class="mes-status mes-status-previsto fraco">' . e(t('sem previsão')) . '</span>';
    }
    $passou = $gasto > $ref + 0.004;
    $frac = $ref > 0.004 ? min(1.0, $gasto / $ref) : 1.0;
    return '<span class="meta-linha mes-meta ' . ($passou ? 'fora-meta' : 'na-meta') . '" data-meta="' . e(number_format($ref, 2, '.', '')) . '">'
        . '<span class="meta-barra' . ($passou ? ' passou' : '') . '" aria-hidden="true"><i style="--p:' . number_format($frac, 3, '.', '') . '"></i></span>'
        . ($passou
            ? '<span class="mes-status mes-status-venceu alerta">' . e(t('passou {valor}', ['valor' => num($gasto - $ref)])) . '</span>'
            : '<span class="mes-status mes-status-pago">' . e(t('falta {valor}', ['valor' => num($ref - $gasto)])) . '</span>')
        . '</span>';
};

/* ---------------------------------------------------------------- linhas */
$linhaConta = static function (array $l) use ($fechado, $valorConta, $celulaPrev, $realTxt, $prevTxt, $situConta, $diferenca, $nomeCel): void { ?>
    <tr id="cat-<?= (int) $l['id'] ?>" data-mov-pago="<?= (int) $l['id'] ?>"<?= !empty($l['do_cartao']) ? ' class="mes-do-cartao"' : '' ?>>
        <?= $nomeCel($l, nomeCategoria($l['nome_completo'])) ?>
        <?php if ($fechado): ?>
            <td class="c-prev"><span class="mes-cel"><?= e(t('planejado')) ?> </span><?= $prevTxt((float) $l['prev'], true) ?></td>
            <td class="c-real"><?= $realTxt((float) $l['real'], true) ?></td>
            <td class="situacao"><?= $diferenca((float) $l['real'], (float) $l['prev'], true) ?></td>
        <?php else: ?>
            <?php $celulaPrev($l, $valorConta($l), true, 'conta'); ?>
            <td class="c-real"><?= $realTxt((float) ($l['pago'] ?? $l['real']), true) ?></td>
            <td class="situacao"><?= $situConta($l) ?></td>
        <?php endif; ?>
    </tr>
<?php };

$linhaDia = static function (array $l) use ($fechado, $estado, $valorDia, $celulaPrev, $realTxt, $prevTxt, $situDia, $diferenca, $nomeCel): void { ?>
    <tr id="cat-<?= (int) $l['id'] ?>">
        <?= $nomeCel($l, $l['nome']) ?>
        <?php if ($fechado): ?>
            <td class="c-prev"><span class="mes-cel"><?= e(t('planejado')) ?> </span><?= $prevTxt((float) $l['prev'], true) ?></td>
            <td class="c-real"><?= $realTxt((float) $l['real'], true) ?></td>
            <td class="situacao"><?= $diferenca((float) $l['real'], (float) $l['prev'], true) ?></td>
        <?php else: ?>
            <?php $celulaPrev($l, $valorDia($l), true, 'dia'); ?>
            <td class="c-real"><?= $estado === 'previsto' ? '' : $realTxt((float) $l['real'], true) ?></td>
            <td class="situacao"><?= $situDia($l) ?></td>
        <?php endif; ?>
    </tr>
<?php };

$linhaEntrada = static function (array $l) use ($fechado, $estado, $fmt, $valorEnt, $celulaPrev, $realTxt, $prevTxt, $diferenca, $nomeCel): void {
    $am = $l['a_menos'] ?? null; ?>
    <tr id="cat-<?= (int) $l['id'] ?>">
        <?= $nomeCel($l, $l['nome']) ?>
        <?php if ($fechado): ?>
            <td class="c-prev"><span class="mes-cel"><?= e(t('planejado')) ?> </span><?= $prevTxt((float) $l['prev'], false) ?></td>
            <td class="c-real"><?= $realTxt((float) $l['real'], false) ?></td>
            <td class="situacao"><?= $diferenca((float) $l['real'], (float) $l['prev'], false) ?></td>
        <?php else: ?>
            <?php $celulaPrev($l, $valorEnt($l), false, 'entrada'); ?>
            <td class="c-real"><?= $estado === 'previsto' ? '' : $realTxt((float) $l['real'], false) ?></td>
            <td class="situacao"><?php if ($am !== null): ?><span class="mes-status mes-status-parcial atencao"><?= e(t('Veio {veio} de {previsto} previstos', ['veio' => $fmt($am['veio']), 'previsto' => $fmt($am['previsto'])])) ?></span><?php endif; ?></td>
        <?php endif; ?>
    </tr>
<?php };

// poupança, investimentos, crédito e empréstimo: só leitura (o lançado e o que o plano conta)
$linhaMov = static function (array $m) use ($fechado, $estado, $prevTxt, $realTxt): void {
    $saida = !$m['entra'];
    $nome = $m['auto'] ? t('Adiantar o empréstimo') : nomeCurto($m['cat']); ?>
    <tr class="mes-mov"<?= $m['auto'] ? ' data-adiantar' : '' ?>>
        <td><span class="cat"><?= $m['auto'] ? iconeCategoria(0, 'Amortização extra do empréstimo', 'Mov', 20) : iconeCategoria((int) $m['id'], (string) $m['cat']['nome'], (string) $m['cat']['grupo'], 20) ?><span class="mes-nome"><?= e($nome) ?></span></span></td>
        <td class="c-prev"><span class="mes-cel"><?= e($fechado ? t('planejado') : t('previsto')) ?> </span><?= $prevTxt($fechado ? (float) $m['prev'] : (float) $m['valor'], $saida) ?></td>
        <td class="c-real"><?= $estado === 'previsto' || $m['auto'] ? '' : $realTxt((float) $m['real'], $saida) ?></td>
        <td class="situacao"><?php if ($m['auto']): ?><small class="fraco"><?= e(t('pelo plano, se não for gasto')) ?></small><?php elseif ($m['ult'] !== null): ?><small class="fraco"><?= e(t('lançado dia {dia}', ['dia' => (int) substr((string) $m['ult'], 8, 2)])) ?></small><?php endif; ?></td>
    </tr>
<?php };

// o total do grupo conta até o novo quando uma ação o muda (movimento.js): a chave é o mês e o grupo
$grupo = static function (string $nome, float $total, bool $saida, string $chave) use ($mes): void { ?>
    <tr class="grupo"><th colspan="4" scope="colgroup"><?= e($nome) ?> <span class="num" data-mov-conta="<?= e('grupo-' . $chave . '-' . $mes) ?>" data-mov-valor="<?= e(number_format(abs($total), 2, '.', '')) ?>"><?= e(($saida && $total > 0.004 ? MENOS : '') . num(abs($total))) ?></span></th></tr>
<?php };

// "+ incluir": categorias sem previsão neste mês
$incluirConta = static function () use ($incluir, $csrf, $mes, $mesLbl, $erroLinha, $ehUltimo): void {
    if ($incluir === []) {
        return;
    }
    $ids  = array_map(static fn($l) => (int) $l['id'], $incluir);
    $erro = $erroLinha !== null && (($erroLinha['ancora'] ?? null) === 'incluir' || in_array((int) ($erroLinha['cat'] ?? 0), $ids, true))
        ? $erroLinha : null;
    $porGrupo = [];
    foreach ($incluir as $l) {
        $porGrupo[grupoLabel($l['grupo'])][] = $l;
    } ?>
    <details class="mes-incluir" id="incluir"<?= $erro !== null ? ' open' : '' ?>>
        <summary><?= svgIcon('plus', 16) ?><?= e(t('Incluir categoria em {mes}', ['mes' => $mesLbl])) ?></summary>
        <form class="mes-form" method="post" action="/mes/previsao">
            <?= $csrf ?>
            <input type="hidden" name="mes" value="<?= e($mes) ?>">
            <input type="hidden" name="ancora" value="incluir">
            <label class="mes-form-campo">
                <span><?= e(t('categoria')) ?></span>
                <select name="categoria_id" required>
                    <option value=""><?= e(t('escolher…')) ?></option>
                    <?php foreach ($porGrupo as $g => $ls): ?>
                        <optgroup label="<?= e($g) ?>">
                            <?php foreach ($ls as $l): ?>
                                <option value="<?= (int) $l['id'] ?>"<?= $erro !== null && (int) $erro['cat'] === (int) $l['id'] ? ' selected' : '' ?>><?= e(nomeCategoria($l['nome_completo'])) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="mes-form-l1">
                <label class="mes-form-campo">
                    <span><?= e(t('previsto em {mes}', ['mes' => $mesLbl])) ?></span>
                    <input type="text" name="valor" inputmode="decimal" autocomplete="off" enterkeyhint="done" placeholder="<?= e(numInput(0)) ?>"
                           value="<?= e($erro !== null ? (string) $erro['valor'] : '') ?>"<?= $erro !== null ? ' aria-invalid="true" aria-describedby="mes-incluir-erro" data-autofocus' : '' ?>>
                </label>
                <button type="submit" name="acao" value="salvar" class="btn btn-contorno btn-sm mes-salvar"><?= e(t('Incluir')) ?></button>
            </div>
            <?php if ($erro !== null): ?><p class="campo-erro" id="mes-incluir-erro" role="alert"><?= e((string) $erro['msg']) ?></p><?php endif; ?>
            <?php if (!$ehUltimo): ?>
                <label class="mes-prop"><input type="checkbox" name="propagar" value="1"<?= !empty($erro['propagar']) ? ' checked' : '' ?>><span><?= e(t('Também nos meses seguintes')) ?></span></label>
            <?php endif; ?>
        </form>
    </details>
<?php };

/* ---------------------------------------------------------------- o topo */
$apoio = match ($estado) {
    'real'   => ($fechamento['data'] ?? null) !== null
        ? (!empty($fechamento['conferido'])
            ? t('Fechado em {data}, conferido com o banco', ['data' => diaMes((string) $fechamento['data'])])
            : t('Fechado em {data}', ['data' => diaMes((string) $fechamento['data'])]))
        : (!empty($fechamento['conferido']) ? t('Fechado, conferido com o banco') : t('Fechado')),
    'aberto' => $mes === $mesCal
        ? t('Aberto até dia {fim}, hoje é dia {dia}', ['fim' => diasNoMes($mes), 'dia' => (int) substr((string) $hoje, 8, 2)])
        : t('Aberto, terminou dia {fim}', ['fim' => diasNoMes($mes)]),
    default  => t('Previsto, começa dia 1'),
};
ob_start();
if ($menu['reabrir'] || $menu['desfazer']): ?>
    <details class="menu mes-menu">
        <summary aria-label="<?= e(t('mais ações')) ?>"><?= svgIcon('more', 20) ?></summary>
        <div class="menu-painel">
            <?php if ($menu['reabrir']): ?>
                <a class="menu-item" href="/relatorio?tipo=mensal&amp;mes=<?= e($mes) ?>"><?= svgIcon('relatorio', 18) ?><?= e(t('Relatório de {mes}', ['mes' => $mesLbl])) ?></a>
                <form method="post" action="/mes/reabrir">
                    <?= $csrf ?><input type="hidden" name="mes" value="<?= e($mes) ?>">
                    <button type="submit" class="menu-item"><?= svgIcon('chevron', 18) ?><?= e(t('Reabrir {mes}', ['mes' => $mesLbl])) ?></button>
                </form>
            <?php endif; ?>
            <?php if ($menu['desfazer']): ?>
                <form method="post" action="/mes/desfazer">
                    <?= $csrf ?>
                    <input type="hidden" name="lote" value="<?= e($menu['desfazer']['lote']) ?>">
                    <input type="hidden" name="mes" value="<?= e($mes) ?>">
                    <button type="submit" class="menu-item mes-desfazer"><?= svgIcon('x', 18) ?><span class="mes-desfazer-txt"><?= e(t('Desfazer a última alteração')) ?><small class="num"><?= e(diaMesHora($menu['desfazer']['criado_em'])) ?></small></span></button>
                </form>
            <?php endif; ?>
        </div>
    </details>
<?php endif;
$acoesTopo = (string) ob_get_clean();

// a resposta do painel: com quanto o mês fecha, e onde isso fica diante da reserva
$perto = abs($fimMes - $reserva) < 0.005;
$apoioFim = match (true) {
    $fimMes < -0.004              => '<span class="alerta">' . e(t('no vermelho')) . '</span>',
    $reserva > 0.004 && $perto    => e(t('na reserva')),
    $fimMes < $reserva - 0.004    => '<span class="atencao">' . e(t('{valor} abaixo da reserva', ['valor' => num($reserva - $fimMes)])) . '</span>',
    $reserva > 0.004              => e(t('{valor} acima da reserva', ['valor' => num($fimMes - $reserva)])),
    default                       => '',
};
// Fechar: um botão de verdade; antes do fim do mês, desligado e com o motivo
$podeFechar = $menu['fechar'];
$fechaJa = $podeFechar && \Financeiro\Models\MesModel::podeFechar($mes, (string) $hoje);     // a mesma regra do POST
?>
<div class="grade mes">
    <?php View::partial('partials/topo', ['mes' => $mes, 'meses' => $meses, 'href' => '/mes?mes=', 'hoje' => $hoje, 'anual' => true, 'apoio' => $apoio, 'acoes' => $acoesTopo]); ?>

    <section class="faixa-grade" aria-labelledby="mes-resposta-t">
        <div class="painel mes-painel<?= $fechado ? ' is-fechado' : '' ?>">
            <div class="mes-painel-l1">
                <div>
                    <h2 class="rot-resposta" id="mes-resposta-t"><?= e($fechado ? t('{mes} fechou com', ['mes' => $mesTit]) : t('{mes} fecha com', ['mes' => $mesTit])) ?></h2>
                    <p class="resposta-num" data-previsao-fim="<?= e(number_format($fimMes, 2, '.', '')) ?>" data-mov-conta="<?= e('fecha-' . $mes) ?>" data-mov-valor="<?= e(number_format($fimMes, 2, '.', '')) ?>"><?= valor($fimMes, 'neutro', ['moeda' => true, 'classe' => $fimMes < -0.004 ? 'alerta' : '']) ?></p>
                    <?php if ($apoioFim !== ''): ?><p class="por-dia"><?= $apoioFim ?></p><?php endif; ?>
                </div>
                <?php if ($podeFechar): ?>
                    <div class="mes-acao">
                        <?php if ($fechaJa): ?>
                            <a class="btn btn-principal" href="/mes/fechar?mes=<?= e($mes) ?>" data-fechar><?= e(t('Fechar {mes}', ['mes' => $mesNome])) ?></a>
                        <?php else: ?>
                            <button class="btn btn-contorno" type="button" aria-disabled="true" aria-describedby="mes-fechar-quando" data-fechar><?= e(t('Fechar {mes}', ['mes' => $mesNome])) ?></button>
                            <small id="mes-fechar-quando"><?= e(t('a partir de {data}', ['data' => t('{dia} de {mes}', ['dia' => (int) substr($fimMesData, 8, 2), 'mes' => $mesNome])])) ?></small>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php if ($destino !== null && $destino['total'] >= 0.005): ?>
                <div class="mes-destino" data-destino data-mov-barra="<?= e('mes-' . $mes) ?>" data-total="<?= e(number_format($destino['total'], 2, '.', '')) ?>">
                    <?php View::partial('partials/destino', [
                        'titulo' => t('Para onde vai o dinheiro de {mes}', ['mes' => $mesNome]),
                        'segmentos' => Destino::segmentos($destino), 'total' => $destino['total'], 'nivel' => 'h3',
                    ]); ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="faixa-grade" aria-label="<?= e($fechado ? t('Planejado e realizado') : t('Previsto e realizado')) ?>">
        <table class="livro mes-livro<?= $fechado ? ' is-fechado' : '' ?>" data-livro-fim="<?= e(number_format($totEntradas - $totSaidas, 2, '.', '')) ?>">
            <caption class="sr-only"><?= e(t('{mes} de {ano}: previsto e realizado por categoria', ['mes' => $mesTit, 'ano' => substr($mes, 0, 4)])) ?></caption>
            <thead>
                <tr>
                    <th scope="col"><?= e(t('Categoria')) ?></th>
                    <th scope="col" class="c-prev"><?= e($fechado ? t('Planejado') : t('Previsto')) ?></th>
                    <th scope="col" class="c-real"><?= e(t('Realizado')) ?></th>
                    <th scope="col" class="situacao"><?= e($fechado ? t('Diferença') : t('Situação')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php $grupo(t('Entradas'), $totEntradas, false, 'entradas'); ?>
                <tr data-saldo-anterior>
                    <td><span class="cat"><?= iconeCategoria(0, 'Guardar na poupança', 'Poupanca', 20) ?><span class="mes-nome"><?= e(t('Saldo de {mes}', ['mes' => mesExtenso(date('Y-m-01', strtotime($mes . ' -1 month')))])) ?></span></span></td>
                    <td class="c-prev"><span class="mes-cel"><?= e($fechado ? t('planejado') : t('previsto')) ?> </span><?= valor($inicioMes, 'previsto', ['centavos' => false]) ?></td>
                    <td class="c-real"><?= $estado === 'previsto' ? '' : valor($inicioMes, 'neutro', ['centavos' => false]) ?></td>
                    <td class="situacao"></td>
                </tr>
                <?php if (abs($acertoMes) >= 0.005): ?>
                    <tr data-acerto-saldo>
                        <td><span class="cat"><span class="ext-ic ext-transf-ic mes-acerto-ic" aria-hidden="true">±</span><span class="mes-nome"><?= e(t('Acerto de saldo')) ?></span></span></td>
                        <td class="c-prev"><span class="mes-cel"><?= e($fechado ? t('planejado') : t('previsto')) ?> </span><?= valor($acertoMes, 'previsto', ['centavos' => false]) ?></td>
                        <td class="c-real"><?= valor($acertoMes, $acertoMes < 0 ? 'saida' : 'entrada', ['centavos' => false]) ?></td>
                        <td class="situacao"><small class="fraco"><?= e(t('com o banco')) ?></small></td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($entradas as $l) { $linhaEntrada($l); } ?>
                <?php foreach ($movEntra as $m) { $linhaMov($m); } ?>

                <?php if ($contas !== []): ?>
                    <?php $grupo(t('Contas fixas'), $totContas, true, 'contas'); ?>
                    <?php foreach ($contas as $l) { $linhaConta($l); } ?>
                <?php endif; ?>

                <?php if ($dia !== []): ?>
                    <?php $grupo(t('Dia a dia'), $totDia, true, 'dia'); ?>
                    <?php foreach ($dia as $l) { $linhaDia($l); } ?>
                <?php endif; ?>

                <?php if ($movSai !== []): ?>
                    <?php $grupo(t('Guardado e empréstimo'), $totGuardado, true, 'guardado'); ?>
                    <?php foreach ($movSai as $m) { $linhaMov($m); } ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <?php // no mês aberto, o que o plano conta (a coluna Previsto); no fechado, o que aconteceu (a coluna Realizado)
                $celTot = static fn(string $html): string => $fechado ? '<td class="c-prev"></td><td class="c-real">' . $html . '</td>' : '<td class="c-prev">' . $html . '</td><td class="c-real"></td>'; ?>
                <tr>
                    <td><?= e(t('Entradas')) ?></td>
                    <?= $celTot(e(num($totEntradas))) ?>
                    <td class="situacao"></td>
                </tr>
                <tr>
                    <td><?= e(t('Saídas e guardado')) ?></td>
                    <?= $celTot(e(($totSaidas > 0.004 ? MENOS : '') . num($totSaidas))) ?>
                    <td class="situacao"></td>
                </tr>
                <tr class="total-final">
                    <td><?= e(t('Fim do mês')) ?></td>
                    <?= $celTot('<span data-fim-mes data-mov-conta="' . e('fim-' . $mes) . '" data-mov-valor="' . e(number_format($fimMes, 2, '.', '')) . '">' . valor($fimMes, 'neutro', ['classe' => $fimMes < -0.004 ? 'alerta' : '']) . '</span>') ?>
                    <td class="situacao"><small class="fraco"><?= e($fechado ? t('fechado, o que ficou na conta') : ($estado === 'previsto' ? t('previsto') : t('previsto, com o que falta'))) ?></small></td>
                </tr>
            </tfoot>
        </table>
        <?php if ($editavel) { $incluirConta(); } ?>
    </section>
</div>
