<?php
/**
 * Extrato: o livro-razão do período, de largura total. Variáveis: $mes, $meses, $situacao, $estado, $hoje, $usuarios,
 * $f, $quem, $categoria (?array), $categorias (array), $q, $modo ('' = o mês; ano, tudo, datas), $periodo (?de/ate),
 * $rotuloPeriodo, $ondePeriodo (o período no meio da frase: "em novembro de 2026"), $valores (min/max como digitados), $erroValor, $params (filtros ativos, com mes), $limite,
 * $linhas, $totais, $filtrado, $renda (?float, só sem filtro), $urlAtual, $urlPlanilha,
 * $form (não usado aqui: o Lançar é o diálogo do layout), $contasMes (ChecklistContas::fixasAPagar).
 * Com mais de uma conta: $contas (todas, para o filtro), $conta (a filtrada, ou null), $transferencias (as do período,
 * com ↔ e fora dos totais), $saldoConta (o saldo da conta filtrada); $nomesCartao (com mais de um cartão).
 * $acertos (os acertos de saldo do período, com ± e fora do Entrou, do Gastou e da soma do dia; sem coluna no resumo,
 * que só confundia), $abertura (o saldo com que o mês começou, para a linha do fim e o resumo, ou null).
 * De cima para baixo: o topo (Extrato, o período e o seletor de mês; Importar e a planilha no ⋯), a faixa do período
 * (Entrou, Gasto, as movimentações quando há, e o saldo), os filtros numa linha com a busca (/ foca, extrato.js) e a
 * tabela agrupada por dia, com a soma do dia. "Contas do mês" mora no Início e no Mês: aqui, só a linha discreta da
 * conta vencida. A busca sem resultado diz onde buscou e oferece todos os meses.
 */

/** Cabeçalho do grupo de um dia: Hoje · Ontem · Qua, 14 de out (agendado, se for depois de hoje). */
$rotuloDia = static function (string $data) use ($hoje): string {
    if ($data === $hoje) {
        return t('Hoje');
    }
    if ($data === date('Y-m-d', (int) strtotime($hoje . ' -1 day'))) {
        return t('Ontem');
    }
    $ts = (int) strtotime($data);
    $rotulo = mb_convert_case(\Financeiro\Idioma::diaSemana((int) date('w', $ts)), MB_CASE_TITLE) . ', ' . (substr($data, 0, 4) === substr($hoje, 0, 4)
        ? t('{dia} de {mes}', ['dia' => (int) date('j', $ts), 'mes' => \Financeiro\Idioma::mesCurto((int) date('n', $ts))])
        : t('{dia} de {mes} de {ano}', ['dia' => (int) date('j', $ts), 'mes' => \Financeiro\Idioma::mesCurto((int) date('n', $ts)), 'ano' => date('Y', $ts)]));
    return $data > $hoje ? t('{data} (agendado)', ['data' => $rotulo]) : $rotulo;
};

/** URL do extrato com os filtros atuais trocados por $mudar ('' remove). */
$url = static function (array $mudar = []) use ($params): string {
    $p = array_filter(array_merge($params, $mudar), static fn($v) => $v !== '' && $v !== null && $v !== 0);
    return '/lancamentos?' . http_build_query($p, '', '&', PHP_QUERY_RFC3986);
};
$semMes = array_diff_key($params, ['mes' => 1]);
$sufixo = $semMes !== [] ? '&' . http_build_query($semMes, '', '&', PHP_QUERY_RFC3986) : '';
$voltaEnc = rawurlencode($urlAtual);
// o mês por extenso, com a 1ª letra maiúscula só no mês: "Novembro de 2026"
$mesTitulo = static fn(string $m): string => t('{mes} de {ano}', ['mes' => mb_convert_case(mesExtenso($m), MB_CASE_TITLE), 'ano' => substr($m, 0, 4)]);

// as linhas por dia, quando a ordem é por dia; em valor ou alfabética, uma lista corrida (cada linha diz a data)
$contas = $contas ?? [];
$conta = $conta ?? null;
$transferencias = $transferencias ?? [];
$acertos = $acertos ?? [];
$abertura = $abertura ?? null;
$nomesCartao = $nomesCartao ?? [];
$nomeContaDe = [];
foreach ($contas !== [] ? $contas : \Financeiro\Models\ContaModel::todas() as $c) {
    $nomeContaDe[(int) $c['id']] = nomeConta($c);
}
$agrupado = $ordem === '' || $ordem === 'dia-asc';
$dias = [];
if ($agrupado) {
    foreach ($linhas as $r) {
        $dias[$r['data']][] = $r;
    }
    foreach ($transferencias as $tr) {
        $dias[$tr['data']][] = ['transf' => true] + $tr;
    }
    foreach ($acertos as $ac) {
        $dias[$ac['data']][] = ['acerto' => true] + $ac;
    }
    $ordem === '' ? krsort($dias) : ksort($dias);
} else {
    $extras = array_merge(array_map(static fn(array $tr): array => ['transf' => true] + $tr, $transferencias), array_map(static fn(array $ac): array => ['acerto' => true] + $ac, $acertos));
    $dias = $linhas === [] && $extras === [] ? [] : ['tudo' => array_merge($linhas, $extras)];
}
$temLinhas = $linhas !== [] || $transferencias !== [] || $acertos !== [];

// a linha do saldo com que o mês começou: no fim da lista (a mais antiga), ou no começo em "Mais antigos"; com a
// lista paginada, só quando ela chega ao começo do mês
$linhaAbertura = static function () use ($abertura): void {
    if ($abertura === null) {
        return;
    }
    $rot = $abertura['primeiro'] ? t('Saldo no início') : t('Saldo que veio de {mes}', ['mes' => mesExtenso($abertura['mes_anterior'])]); ?>
    <tbody class="ext-abertura">
        <tr class="ext-linha" data-ex-abertura>
            <td class="ext-c-desc" colspan="3"><span class="ext-ic ext-transf-ic" aria-hidden="true">↓</span><span><?= e($rot) ?></span><?php if ($abertura['previsto']): ?> <small class="ext-agendado"><?= e(t('previsto')) ?></small><?php endif; ?></td>
            <td class="ext-c-valor"><?= $abertura['previsto'] ? valor($abertura['valor'], 'previsto', ['centavos' => false]) : valor($abertura['valor'], 'neutro', ['centavos' => false]) ?></td>
        </tr>
    </tbody>
<?php };
$exibidas = count($linhas);
$faltam   = max(0, (int) $totais['n'] - $exibidas);

// as categorias por classe no filtro: entradas, essenciais, não essenciais, movimentações
$classeDe = static function (array $c): string {
    if ((int) $c['operacional'] === 0) { return 'mov'; }
    if ((int) $c['sinal'] === 1)       { return 'entradas'; }
    return (int) $c['essencial'] === 1 ? 'essenciais' : 'naoess';
};
$classes = [
    'entradas'   => ['rotulo' => t('Entradas'),        'todas' => t('Todas as entradas'),       'cats' => []],
    'essenciais' => ['rotulo' => t('Essenciais'),      'todas' => t('Todas as essenciais'),     'cats' => []],
    'naoess'     => ['rotulo' => t('Não essenciais'),  'todas' => t('Todas as não essenciais'), 'cats' => []],
    'mov'        => ['rotulo' => t('⇄ Movimentações'), 'todas' => t('Todas as movimentações'),  'cats' => []],
];
foreach ($categorias as $c) {
    $classes[$classeDe($c)]['cats'][] = $c;
}
$catAtual = $categoria !== null ? (int) $categoria['id'] : 0;
// "Importar extrato": com a conta filtrada, ela já vem escolhida
$urlImportar = '/importar' . ($conta !== null ? '?destino=conta%3A' . (int) $conta['id'] : '');

// o topo: o período por extenso; o seletor de mês só no modo do mês
$periodoTxt = $modo === '' ? $mesTitulo($mes) : $rotuloPeriodo;
$acoes = '<details class="menu ext-menu"><summary aria-label="' . e(t('mais ações')) . '">' . svgIcon('more', 20) . '</summary><div class="menu-painel">'
    . '<a class="menu-item" href="' . e($urlImportar) . '">' . svgIcon('arrow-up', 18) . e(t('Importar extrato')) . '</a>'
    . '<a class="menu-item" href="' . e($urlPlanilha) . '">' . svgIcon('download', 18) . e(t('Exportar planilha (CSV)')) . '</a>'
    . '<a class="menu-item" href="/acertos/novo?volta=' . e($voltaEnc) . '">' . svgIcon('check', 18) . e(t('Acertar saldo com o banco')) . '</a>'
    . '</div></details>';
if ($modo !== '') {
    $acoes = '<a class="btn btn-contorno btn-sm ext-voltar-mes" href="' . e($url(['periodo' => '', 'de' => '', 'ate' => ''])) . '">' . e(t('Ver só {mes}', ['mes' => mesAno($mes)])) . '</a>' . $acoes;
}

// a conta fixa vencida: uma linha discreta (a lista inteira mora no Início e no Mês)
$vencidas = array_values(array_filter($contasMes ?? [], static fn(array $c): bool => !empty($c['atrasado'])));

// o valor de uma linha, pela regra de cor: saiu em vermelho com "−", entrou em verde com "+", movimentação neutra
// com ⇄, agendado (depois de hoje) em cinza, e a soma do dia agendado também; o agendado fica fora do Entrou e do
// Gastou, com a nota "mais X agendado"
$valorLinha = static function (array $r) use ($hoje): string {
    $v = (float) $r['valor'];
    $mov = (int) $r['operacional'] !== 1;
    $ent = !$mov && (int) $r['sinal'] > 0;
    if ($r['data'] > $hoje) {
        return '<span class="num v-previsto">' . ($mov ? '⇄ ' : ($ent ? '+' : MENOS)) . e(num($v)) . '</span>';
    }
    if ($mov) {
        return '<span class="num ext-v-mov">⇄ ' . e(num($v)) . '</span>';
    }
    return valor($ent ? $v : -$v, $ent ? 'entrada' : 'saida', ['centavos' => false]);
};
// a soma do dia: o que entrou menos o que saiu (as movimentações e transferências ficam fora, como nos totais)
$somaDia = static function (array $rows): float {
    $s = 0.0;
    foreach ($rows as $r) {
        if (empty($r['transf']) && empty($r['acerto']) && (int) $r['operacional'] === 1) {
            $s += (int) $r['sinal'] > 0 ? (float) $r['valor'] : -(float) $r['valor'];
        }
    }
    return round($s, 2);
};
$colunas = 4;
?>
<div class="grade ext">
    <?php \Financeiro\View::partial('partials/topo', $modo === ''
        ? ['titulo' => t('Extrato'), 'apoio' => $periodoTxt, 'mes' => $mes, 'meses' => $meses, 'href' => '/lancamentos?mes=', 'sufixo' => $sufixo, 'acoes' => $acoes]
        : ['titulo' => t('Extrato'), 'apoio' => $periodoTxt, 'acoes' => $acoes]); ?>

    <?php if ($temLinhas): ?>
        <section class="faixa-grade ext-resumo" aria-label="<?= e(t('Resumo de {periodo}', ['periodo' => $periodoTxt])) ?>">
            <?php
            $temMov = (float) $totais['mov'] > 0.004;
            $nCol = 3 + ($temMov ? 1 : 0) + ($filtrado ? 1 : 0) + ($abertura !== null ? 1 : 0); ?>
            <dl class="numeros" style="--colunas:<?= $nCol ?>">
                <?php if ($filtrado): ?>
                    <div><dt><?= e(t('Achados')) ?></dt><dd class="num"><?= e(num((int) $totais['n'], 0)) ?><span class="nota"><?= e(tn((int) $totais['n'], 'lançamento', 'lançamentos')) ?></span></dd></div>
                <?php endif; ?>
                <?php if ($abertura !== null): ?>
                    <div><dt><?= e($abertura['primeiro'] ? t('Saldo no início') : t('Veio de {mes}', ['mes' => mesExtenso($abertura['mes_anterior'])])) ?></dt><dd data-ex-total="abertura"><?= $abertura['previsto'] ? valor($abertura['valor'], 'previsto') : valor($abertura['valor']) ?><?php if ($abertura['previsto']): ?><span class="nota"><?= e(t('previsto')) ?></span><?php endif; ?></dd></div>
                <?php endif; ?>
                <div><dt><?= e(t('Entrou')) ?></dt><dd data-ex-total="entrada"><?= valor((float) $totais['entrada'], 'entrada') ?><?php if ((float) ($totais['entrada_ag'] ?? 0) > 0.004): ?><span class="nota"><?= e(t('mais {valor} agendado', ['valor' => num((float) $totais['entrada_ag'])])) ?></span><?php endif; ?></dd></div>
                <div><dt><?= e(t('Gastou')) ?></dt><dd data-ex-total="gasto"><?= valor(-(float) $totais['gasto'], 'saida') ?><?php if ((float) ($totais['gasto_ag'] ?? 0) > 0.004): ?><span class="nota"><?= e(t('mais {valor} agendado', ['valor' => num((float) $totais['gasto_ag'])])) ?></span><?php endif; ?></dd></div>
                <?php if ($temMov): ?>
                    <div><dt><?= e(t('Movimentações')) ?></dt><dd><span class="num ext-v-mov">⇄ <?= valor((float) $totais['mov']) ?></span><span class="nota"><?= e(t('guardado e empréstimo')) ?></span></dd></div>
                <?php endif; ?>
                <div><dt><?= e(t('Saldo do período')) ?></dt><dd data-ex-total="soma"><?= valor((float) $totais['soma']) ?><span class="nota"><?= e(t('o que entrou menos o gasto')) ?></span></dd></div>
            </dl>
            <?php if (!empty($saldoConta)): ?>
                <p class="ext-saldo-conta"><?= t('Saldo de {conta} em {data}: {valor}', ['conta' => e(nomeConta($saldoConta['conta'])), 'data' => e(diaMes($saldoConta['data'])), 'valor' => valor((float) $saldoConta['valor'])]) ?></p>
            <?php endif; ?>
            <?php if ($vencidas !== []): ?>
                <p class="ext-vencida atencao"><?= svgIcon('alert', 16) ?><span><?= e(tn(count($vencidas), '1 conta vencida: {nomes}.', '{n} contas vencidas: {nomes}.', ['nomes' => implode(', ', array_map(static fn(array $c): string => nomeCurto($c), $vencidas))])) ?></span> <a class="link" href="/mes"><?= e(t('Ver no Mês')) ?></a></p>
            <?php endif; ?>
        </section>
    <?php elseif ($vencidas !== []): ?>
        <section class="faixa-grade ext-resumo">
            <p class="ext-vencida atencao"><?= svgIcon('alert', 16) ?><span><?= e(tn(count($vencidas), '1 conta vencida: {nomes}.', '{n} contas vencidas: {nomes}.', ['nomes' => implode(', ', array_map(static fn(array $c): string => nomeCurto($c), $vencidas))])) ?></span> <a class="link" href="/mes"><?= e(t('Ver no Mês')) ?></a></p>
        </section>
    <?php endif; ?>

    <section class="faixa-grade ext-livro" aria-label="<?= e(t('extrato')) ?>">
        <!-- filtros: uma linha, com a busca na frente -->
        <form class="ext-filtros" method="get" action="/lancamentos" role="search" id="ex-filtro-form">
            <?php foreach (array_diff_key($params, ['q' => 1, 'f' => 1, 'cat' => 1, 'ordem' => 1, 'periodo' => 1, 'de' => 1, 'ate' => 1, 'min' => 1, 'max' => 1, 'quem' => 1, 'conta' => 1]) as $k => $v): ?>
                <input type="hidden" name="<?= e((string) $k) ?>" value="<?= e((string) $v) ?>">
            <?php endforeach; ?>

            <div class="ext-busca">
                <span class="ext-busca-ic" aria-hidden="true"><?= svgIcon('search', 16) ?></span>
                <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('Buscar')) ?>" aria-label="<?= e(t('buscar')) ?>"
                       enterkeyhint="search" autocomplete="off" maxlength="80" aria-keyshortcuts="/" data-ext-busca>
                <kbd class="ext-busca-kbd" aria-hidden="true">/</kbd>
            </div>

            <?php $selVal = $catAtual > 0 ? (string) $catAtual : ($f !== '' ? 'g:' . $f : ''); ?>
            <select class="ext-sel" aria-label="<?= e(t('categoria')) ?>" id="ex-cat-sel">
                <option value=""><?= e(t('Todas as categorias')) ?></option>
                <?php foreach ($classes as $gf => $cl): if ($cl['cats'] === []) { continue; } ?>
                <optgroup label="<?= e($cl['rotulo']) ?>">
                    <option value="g:<?= e($gf) ?>"<?= $selVal === 'g:' . $gf ? ' selected' : '' ?>><?= e($cl['todas']) ?></option>
                    <?php foreach ($cl['cats'] as $c): ?>
                        <option value="<?= (int) $c['id'] ?>"<?= (int) $c['id'] === $catAtual ? ' selected' : '' ?>><?= e(nomeCurto($c)) ?></option>
                    <?php endforeach; ?>
                </optgroup>
                <?php endforeach; ?>
            </select>

            <?php $periodos = ['' => $mesTitulo($mes), 'ano' => t('O ano de {ano}', ['ano' => substr($mes, 0, 4)]), 'tudo' => t('Todos os meses'), 'datas' => t('Entre datas')]; ?>
            <select class="ext-sel ext-sel-periodo" name="periodo" aria-label="<?= e(t('período')) ?>" data-ex-periodo>
                <?php foreach ($periodos as $pv => $plbl): ?>
                    <option value="<?= e($pv) ?>"<?= $modo === $pv ? ' selected' : '' ?>><?= e($plbl) ?></option>
                <?php endforeach; ?>
            </select>

            <?php $ordens = ['' => t('Mais recentes'), 'dia-asc' => t('Mais antigos'), 'valor-desc' => t('Maior valor'), 'valor-asc' => t('Menor valor'), 'alfa' => t('De A a Z')]; ?>
            <select class="ext-sel ext-sel-ordem" name="ordem" aria-label="<?= e(t('ordenar')) ?>" data-autosubmit>
                <?php foreach ($ordens as $ov => $olbl): ?>
                    <option value="<?= e($ov) ?>"<?= $ordem === $ov ? ' selected' : '' ?>><?= e($olbl) ?></option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="btn btn-contorno ext-buscar"><?= e(t('Buscar')) ?></button>

            <div class="ext-datas<?= $modo === 'datas' ? ' is-on' : '' ?>" data-ex-datas>
                <label class="ext-campo"><span><?= e(t('De')) ?></span><input type="date" name="de" value="<?= e((string) ($periodo['de'] ?? '')) ?>"></label>
                <label class="ext-campo"><span><?= e(t('Até')) ?></span><input type="date" name="ate" value="<?= e((string) ($periodo['ate'] ?? '')) ?>"></label>
            </div>

            <?php $maisAberto = $valores['min'] !== '' || $valores['max'] !== '' || $quem > 0 || $erroValor !== null || $conta !== null; ?>
            <details class="ext-mais"<?= $maisAberto ? ' open' : '' ?>>
                <summary><?= $contas !== [] ? e(t('Mais filtros: conta, valor e quem lançou')) : e(t('Mais filtros: valor e quem lançou')) ?></summary>
                <div class="ext-mais-c">
                    <?php if ($contas !== []): ?>
                        <label class="ext-campo"><span><?= e(t('Conta')) ?></span>
                            <select name="conta">
                                <option value=""><?= e(t('Todas as contas')) ?></option>
                                <?php foreach ($contas as $c): ?><option value="<?= (int) $c['id'] ?>"<?= $conta !== null && $c['id'] === $conta['id'] ? ' selected' : '' ?>><?= e(nomeConta($c)) ?></option><?php endforeach; ?>
                            </select>
                        </label>
                    <?php endif; ?>
                    <label class="ext-campo"><span><?= e(t('Valor mínimo')) ?></span><input type="text" name="min" inputmode="decimal" autocomplete="off" placeholder="<?= e(numInput(0)) ?>" value="<?= e($valores['min']) ?>"<?= $erroValor !== null ? ' aria-invalid="true" aria-describedby="ex-erro-valor"' : '' ?>></label>
                    <label class="ext-campo"><span><?= e(t('Valor máximo')) ?></span><input type="text" name="max" inputmode="decimal" autocomplete="off" placeholder="<?= e(numInput(0)) ?>" value="<?= e($valores['max']) ?>"<?= $erroValor !== null ? ' aria-invalid="true" aria-describedby="ex-erro-valor"' : '' ?>></label>
                    <?php if (count($usuarios) > 1): ?>
                        <label class="ext-campo"><span><?= e(t('Quem lançou')) ?></span>
                            <select name="quem">
                                <option value=""><?= e(t('Todas as pessoas')) ?></option>
                                <?php foreach ($usuarios as $u): ?><option value="<?= (int) $u['id'] ?>"<?= (int) $u['id'] === $quem ? ' selected' : '' ?>><?= e((string) $u['nome']) ?></option><?php endforeach; ?>
                            </select>
                        </label>
                    <?php endif; ?>
                    <?php if ($erroValor !== null): ?><p class="ext-erro" id="ex-erro-valor" role="alert"><?= e($erroValor) ?></p><?php endif; ?>
                </div>
            </details>
        </form>

        <?php if ($filtrado): ?>
            <div class="ext-ativos">
                <?php if ($q !== ''): ?>
                    <a class="chip is-on" href="<?= e($url(['q' => ''])) ?>"><span class="sr-only"><?= e(t('tirar busca')) ?> </span>“<?= e($q) ?>” <span aria-hidden="true">×</span></a>
                <?php endif; ?>
                <?php if ($conta !== null): ?>
                    <a class="chip is-on" href="<?= e($url(['conta' => ''])) ?>" aria-label="<?= e(t('tirar filtro {nome}', ['nome' => nomeConta($conta)])) ?>"><?= e(nomeConta($conta)) ?> <span aria-hidden="true">×</span></a>
                <?php endif; ?>
                <?php if ($categoria !== null): ?>
                    <a class="chip is-on" href="<?= e($url(['cat' => ''])) ?>" aria-label="<?= e(t('tirar filtro {nome}', ['nome' => nomeCurto($categoria)])) ?>"><?= e(nomeCurto($categoria)) ?> <span aria-hidden="true">×</span></a>
                <?php endif; ?>
                <?php if ($valores['min'] !== '' || $valores['max'] !== ''): ?>
                    <a class="chip is-on" href="<?= e($url(['min' => '', 'max' => ''])) ?>"><span class="sr-only"><?= e(t('tirar filtro de valor')) ?> </span><?= e($valores['max'] === '' ? t('A partir de {valor}', ['valor' => money((float) parseMoedaBr($valores['min']))]) : ($valores['min'] === '' ? t('Até {valor}', ['valor' => money((float) parseMoedaBr($valores['max']))]) : t('De {min} a {max}', ['min' => money((float) parseMoedaBr($valores['min'])), 'max' => money((float) parseMoedaBr($valores['max']))]))) ?> <span aria-hidden="true">×</span></a>
                <?php endif; ?>
                <?php foreach ($usuarios as $u): if ($quem > 0 && (int) $u['id'] === $quem): ?>
                    <a class="chip is-on" href="<?= e($url(['quem' => ''])) ?>" aria-label="<?= e(t('tirar filtro {nome}', ['nome' => (string) $u['nome']])) ?>"><?= e((string) $u['nome']) ?> <span aria-hidden="true">×</span></a>
                <?php endif; endforeach; ?>
                <a class="btn-link" href="<?= e($url(['f' => '', 'cat' => '', 'conta' => '', 'q' => '', 'min' => '', 'max' => '', 'quem' => '', 'periodo' => '', 'de' => '', 'ate' => ''])) ?>"><?= e(t('Limpar filtros')) ?></a>
            </div>
        <?php endif; ?>

        <?php if (!$temLinhas): ?>
            <div class="vazio ext-vazio">
                <?php if ($q !== ''): ?>
                    <p class="titulo"><?= e($modo === '' ? t('Nada com “{q}” em {mes}.', ['q' => $q, 'mes' => mesExtenso($mes)]) : t('Nada com “{q}” {onde}.', ['q' => $q, 'onde' => $ondePeriodo])) ?></p>
                    <?php if ($modo !== 'tudo'): ?>
                        <a class="btn btn-contorno" href="<?= e($url(['periodo' => 'tudo', 'de' => '', 'ate' => ''])) ?>"><?= svgIcon('search', 16) ?><?= e(t('Buscar em todos os meses')) ?></a>
                    <?php endif; ?>
                <?php elseif ($modo !== '' || $valores['min'] !== '' || $valores['max'] !== '' || $quem > 0 || $conta !== null): ?>
                    <p class="titulo"><?= e(t('Nada encontrado com esses filtros {onde}.', ['onde' => $ondePeriodo])) ?></p>
                    <a class="btn-link" href="<?= e($url(['f' => '', 'cat' => '', 'conta' => '', 'q' => '', 'min' => '', 'max' => '', 'quem' => '', 'periodo' => '', 'de' => '', 'ate' => ''])) ?>"><?= e(t('Limpar filtros')) ?></a>
                <?php elseif ($categoria !== null || $f !== ''): ?>
                    <p class="titulo"><?= e($categoria !== null ? t('Nada de {categoria} em {mes}.', ['mes' => mesExtenso($mes), 'categoria' => nomeCurto($categoria)]) : t('Nada nesse grupo em {mes}.', ['mes' => mesExtenso($mes)])) ?></p>
                    <a class="btn btn-contorno" href="<?= e($url(['periodo' => 'tudo'])) ?>"><?= e(t('Ver em todos os meses')) ?></a>
                <?php elseif ($estado === 'previsto'): ?>
                    <p class="titulo"><?= e(t('{mes} ainda não chegou.', ['mes' => mb_convert_case(mesExtenso($mes), MB_CASE_TITLE)])) ?></p>
                    <p><?= e(t('O Extrato mostra o que foi lançado. O que está previsto para o mês fica na tela Mês.')) ?></p>
                    <a class="btn btn-contorno" href="/mes?mes=<?= e($mes) ?>"><?= e(t('Ver o previsto no Mês')) ?></a>
                <?php else: ?>
                    <p class="titulo"><?= e(t('Nada lançado em {mes}.', ['mes' => mesExtenso($mes)])) ?></p>
                    <p><?= e(t('Aqui fica cada valor que entrou e saiu, dia a dia. Lance um gasto ou importe o extrato do banco.')) ?></p>
                    <div class="ext-vazio-acoes">
                        <a class="btn btn-contorno" href="/lancar?volta=<?= e($voltaEnc) ?>" data-lancar><?= svgIcon('plus', 16) ?><?= e(t('Lançar')) ?></a>
                        <a class="btn-link" href="<?= e($urlImportar) ?>"><?= e(t('Importar extrato')) ?></a>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <table class="ext-tabela" data-mov-lista="extrato">
                <caption class="sr-only"><?= e(t('Lançamentos de {periodo}', ['periodo' => $periodoTxt])) ?></caption>
                <thead>
                    <tr>
                        <th scope="col"><?= e(t('Descrição')) ?></th>
                        <th scope="col" class="ext-c-cat"><?= e(t('Categoria')) ?></th>
                        <th scope="col" class="ext-c-conta"><?= e(t('Conta')) ?></th>
                        <th scope="col" class="ext-c-valor"><?= e(t('Valor')) ?></th>
                    </tr>
                </thead>
                <?php if ($ordem === 'dia-asc') { $linhaAbertura(); } ?>
                <?php $i = 0; foreach ($dias as $data => $rows): ?>
                    <tbody>
                        <?php if ($agrupado): $sd = $somaDia($rows); ?>
                            <tr class="ext-dia">
                                <th scope="rowgroup" colspan="<?= $colunas - 1 ?>"><?= e($rotuloDia((string) $data)) ?></th>
                                <td class="ext-c-valor"><?php if (abs($sd) >= 0.005): ?><span class="sr-only"><?= e(t('soma do dia')) ?> </span><?= (string) $data > $hoje
                                    ? '<span class="num v-previsto ext-soma">' . ($sd > 0 ? '+' : MENOS) . e(num(abs($sd))) . '</span>'
                                    : valor($sd, 'auto', ['centavos' => false, 'classe' => 'ext-soma']) ?><?php endif; ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($rows as $r): ?>
                            <?php if (!empty($r['acerto'])):
                                $va = (float) $r['valor'];
                                $quandoA = $agrupado ? '' : diaMes($r['data']); ?>
                                <tr class="ext-linha ext-acerto" data-mov-linha="a<?= (int) $r['id'] ?>">
                                    <td class="ext-c-desc">
                                        <span class="ext-ic ext-transf-ic" aria-hidden="true">±</span>
                                        <a href="/acertos/<?= (int) $r['id'] ?>?volta=<?= e($voltaEnc) ?>"><?= e(\Financeiro\Controllers\AcertoController::rotulo($r)) ?></a>
                                        <small class="ext-sub"><?= e(t('Acerto de saldo')) ?></small>
                                    </td>
                                    <td class="ext-c-cat"><?= e(t('Acerto de saldo')) ?></td>
                                    <td class="ext-c-conta"><?= e($nomeContaDe[(int) $r['conta_id']] ?? '') ?></td>
                                    <td class="ext-c-valor"><?php if ($quandoA !== ''): ?><small class="ext-quando"><?= e($quandoA) ?></small><?php endif; ?><span class="num ext-v-mov"><?= e(($va > 0 ? '+' : MENOS) . num(abs($va))) ?></span></td>
                                </tr>
                            <?php continue; endif; ?>
                            <?php if (!empty($r['transf'])):
                                $de = (int) $r['de_conta_id'];
                                $para = (int) $r['para_conta_id'];
                                $sinalT = $conta === null ? '' : ($conta['id'] === $para ? '+' : MENOS);
                                $quandoT = $agrupado ? '' : diaMes($r['data']); ?>
                                <tr class="ext-linha ext-transf" data-mov-linha="t<?= (int) $r['id'] ?>">
                                    <td class="ext-c-desc">
                                        <span class="ext-ic ext-transf-ic" aria-hidden="true">↔</span>
                                        <a href="/transferencias/<?= (int) $r['id'] ?>/editar?volta=<?= e($voltaEnc) ?>"><?= e(trim((string) $r['descricao']) !== '' ? (string) $r['descricao'] : t('Transferência')) ?></a>
                                        <small class="ext-sub"><?= e(t('Transferência')) ?></small>
                                    </td>
                                    <td class="ext-c-cat"><?= e(t('Transferência')) ?></td>
                                    <td class="ext-c-conta"><?= e(t('De {de} para {para}', ['de' => $nomeContaDe[$de] ?? '', 'para' => $nomeContaDe[$para] ?? ''])) ?></td>
                                    <td class="ext-c-valor"><?php if ($quandoT !== ''): ?><small class="ext-quando"><?= e($quandoT) ?></small><?php endif; ?><span class="num ext-v-mov">↔ <?= e($sinalT . num((float) $r['valor'])) ?></span><?php if ($r['data'] > $hoje): ?><small class="ext-agendado"><?= e(t('agendado')) ?></small><?php endif; ?></td>
                                </tr>
                            <?php continue; endif; ?>
                            <?php
                            $cat = ['nome' => $r['categoria_nome'], 'apelido' => $r['apelido']];
                            $desc = descricaoTela(trim((string) $r['descricao']), $cat);
                            $rot = $desc !== '' ? $desc : nomeCurto($cat);
                            $contaNome = $nomeContaDe[(int) $r['conta_id']] ?? '';
                            ?>
                            <tr class="ext-linha" data-mov-linha="<?= (int) $r['id'] ?>"<?= $i === $limite - 60 && $i > 0 ? ' id="ex-r' . $i . '"' : '' ?>>
                                <td class="ext-c-desc">
                                    <?= iconeCategoria((int) $r['categoria_id'], (string) $r['categoria_nome'], (string) $r['grupo'], 20, 'ext-ic') ?>
                                    <a href="/lancamentos/<?= (int) $r['id'] ?>/editar?volta=<?= e($voltaEnc) ?>"><?= e($rot) ?></a>
                                    <small class="ext-sub"><?= e(nomeCurto($cat)) ?></small>
                                </td>
                                <td class="ext-c-cat"><?= e(nomeCurto($cat)) ?></td>
                                <td class="ext-c-conta"><?= e($contaNome) ?></td>
                                <td class="ext-c-valor"><?php if (!$agrupado): ?><small class="ext-quando"><?= e(diaMes($r['data'])) ?></small><?php endif; ?><?= $valorLinha($r) ?><?php if ($r['data'] > $hoje): ?><small class="ext-agendado"><?= e(t('agendado')) ?></small><?php endif; ?></td>
                            </tr>
                            <?php $i++; ?>
                        <?php endforeach; ?>
                    </tbody>
                <?php endforeach; ?>
                <?php if ($ordem === '' && $faltam === 0) { $linhaAbertura(); } ?>
            </table>

            <?php if ($faltam > 0): ?>
                <p class="ext-mais-linhas"><a class="btn btn-contorno" href="<?= e($url(['limite' => $limite + 60])) ?>#ex-r<?= (int) $limite ?>"><?= e(t('Ver mais ({n})', ['n' => $faltam])) ?></a></p>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</div>
