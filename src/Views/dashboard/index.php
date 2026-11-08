<?php
/**
 * Início: a resposta. O topo é o contexto ("Novembro de 2026" e o dia); o painel responde quanto dá para gastar até o
 * fim do mês, com a barra "para onde vai o dinheiro do mês" (Services\Destino); a faixa de números diz o que já
 * aconteceu (em conta hoje, entrou, gasto e guardado); as contas do mês com Pagar e o carimbo, ao lado dos últimos
 * lançamentos por dia; embaixo, para onde foi o gasto. "Precisa de atenção" só aparece quando
 * há algo, sem repetir o que as contas do mês já mostram.
 * Sem nenhum lançamento e sem dinheiro previsto, a tela diz para que serve e oferece o primeiro lançamento.
 * Variáveis: $hoje, $mesRef, $mesCal, $estadoRef, $reserva, $conta, $contas (só com várias), $painel, $sobra (Sobra), $resposta (DashboardController::resposta), $mesPrevisao, $ultimos, $avisos,
 * $contasMes (a pagar), $contasPagas, $destino (Destino::doMes), $guardado, $temLancamento, $roteiro, $somasDia (a soma
 * do dia inteiro de cada dia à vista). O lançamento agendado (depois de hoje) aparece em cinza, com "agendado", como no
 * Extrato, e fica fora do "Gastou" e do "Já saiu" até o dia dele.
 */
use Financeiro\Services\Destino;
use Financeiro\View;

$csrf     = csrfField();
$mesNome  = mesExtenso($mesRef);
$mesTit   = mb_convert_case($mesNome, MB_CASE_TITLE);
$previsto = $sobra['base'] === 'prevista';
$p        = $painel;
$topMax   = $p['top'] !== [] ? max(array_column($p['top'], 'valor')) : 0.0;
$share    = static fn(float $v, float $tot): int => $tot > 0.004 ? (int) round($v / $tot * 100) : 0;

// o topo: o dia da semana e o dia do mês
$diasSem = [t('domingo'), t('segunda-feira'), t('terça-feira'), t('quarta-feira'), t('quinta-feira'), t('sexta-feira'), t('sábado')];
$tsHoje  = (int) strtotime($hoje);
$diaSem  = $diasSem[(int) date('w', $tsHoje)];
$diaSem  = mb_strtoupper(mb_substr($diaSem, 0, 1)) . mb_substr($diaSem, 1);
$apoio   = substr($hoje, 0, 7) === substr($mesRef, 0, 7)
    ? $diaSem . ', ' . t('dia {dia} de {dias}', ['dia' => (int) date('j', $tsHoje), 'dias' => diasNoMes($mesRef)])
    : $diaSem . ', ' . t('{dia} de {mes}', ['dia' => (int) date('j', $tsHoje), 'mes' => mesExtenso($hoje)]);

// a resposta: o rótulo e o número, nada mais (o dono não quer subtexto). Sem nada para gastar, a resposta é como o mês
// fecha: com sinal, em âmbar abaixo da reserva e em vermelho no negativo. A conta fica no livro-caixa ao lado.
$noMesHoje = $mesPrevisao === $mesCal;
$varsAte   = ['dia' => diasNoMes($mesPrevisao), 'mes' => mesExtenso($mesPrevisao)];
$mesFrase  = mb_convert_case(mesExtenso($mesPrevisao), MB_CASE_TITLE);
$rotulo = t('Em conta hoje');
$numero = valor($conta, 'neutro', ['moeda' => true]);
$tipo = $resposta['tipo'] ?? 'conta';
if ($resposta !== null) {
    $v = (float) $resposta['valor'];
    switch ($tipo) {
        case 'vermelho':
            $rotulo = t('{mes} fecha com', ['mes' => $mesFrase]);
            $numero = valor(-$v, 'neutro', ['moeda' => true, 'classe' => 'alerta']);
            break;
        case 'abaixo':
            $rotulo = t('{mes} fecha com', ['mes' => $mesFrase]);
            $numero = valor(round($reserva - $v, 2), 'neutro', ['moeda' => true, 'classe' => 'atencao']);
            break;
        default:
            $rotulo = $noMesHoje ? t('Dá para gastar até dia {dia}', $varsAte) : t('Dá para gastar até o fim de {mes}', $varsAte);
            $numero = valor($v, 'neutro', ['moeda' => true]);
    }
}

// "Precisa de atenção" sem repetir as contas do mês: a conta fixa vencida ou vencendo já está lá, com o Pagar
$nasContas = array_flip(array_map(static fn(array $c): int => (int) $c['categoria_id'], $contasMes));
$avisos = array_values(array_filter($avisos, static function (array $a) use ($nasContas): bool {
    if (!in_array($a['tipo'], ['conta_atrasada', 'conta_vence'], true)) {
        return true;
    }
    parse_str((string) parse_url((string) $a['href'], PHP_URL_QUERY), $q);
    return !isset($nasContas[(int) ($q['categoria'] ?? 0)]);
}));

// as contas do mês: as que faltam (vencidas primeiro) e as pagas, com o carimbo
$nContas = count($contasMes) + count($contasPagas);
$nPagas = count(array_filter($contasPagas, static fn(array $c): bool => empty($c['agendado'])));     // a agendada ainda não foi paga
$vence = static function (array $c) use ($hoje): string {
    $v = $c['vencimento'] !== null ? (string) $c['vencimento'] : null;
    return match (true) {
        $v === null => '',
        $v === $hoje => t('vence hoje'),
        $c['atrasado'] => t('venceu dia {dia}', ['dia' => (int) substr($v, 8, 2)]),
        $v < $hoje => t('vence {data}, paga até {util}', ['data' => diaMes($v), 'util' => diaMes((string) $c['paga_ate'])]),
        default => t('vence dia {dia}', ['dia' => (int) substr($v, 8, 2)]),
    };
};

// os últimos lançamentos, por dia
$porDia = [];
foreach ($ultimos as $r) {
    $porDia[(string) $r['data']][] = $r;
}
$nomeDia = static function (string $data) use ($hoje, $diasSem): string {
    if ($data === $hoje) {
        return t('Hoje');
    }
    if ($data === date('Y-m-d', strtotime($hoje . ' -1 day'))) {
        return t('Ontem');
    }
    $ds = $diasSem[(int) date('w', (int) strtotime($data))];
    $ds = mb_strtoupper(mb_substr($ds, 0, 1)) . mb_substr($ds, 1);
    $rotulo = t('{dia_semana}, {dia}', ['dia_semana' => explode('-', $ds)[0], 'dia' => (int) substr($data, 8, 2)]);
    return $data > $hoje ? t('{data} (agendado)', ['data' => $rotulo]) : $rotulo;
};
$entra = static fn(array $r): bool => (int) $r['sinal'] > 0;

$vazio = !$temLancamento && ($destino === null || $destino['total'] < 0.005);
?>
<div class="grade ini">
    <?php View::partial('partials/topo', ['mes' => $mesRef, 'apoio' => $apoio]); ?>

    <?php if (!empty($roteiro)): ?>
    <section class="faixa-grade roteiro" aria-labelledby="roteiro-t">
        <div class="secao-cab"><h2 id="roteiro-t"><?= e(t('Primeiros passos')) ?></h2></div>
        <ul class="roteiro-lista">
            <?php foreach ($roteiro as [$href, $texto, $feito]): ?>
                <li class="<?= $feito ? 'is-feito' : '' ?>"><?= svgIcon($feito ? 'check' : 'plus', 16) ?><?= $feito ? '<span>' . e($texto) . '</span>' : '<a href="' . e($href) . '">' . e($texto) . '</a>' ?></li>
            <?php endforeach; ?>
        </ul>
        <div class="roteiro-fim">
            <a class="link" href="/ajuda"><?= e(t('Ver a ajuda')) ?></a>
            <form method="post" action="/roteiro/dispensar"><?= $csrf ?><button type="submit" class="btn-link"><?= e(t('Dispensar')) ?></button></form>
        </div>
    </section>
    <?php endif; ?>

<?php if ($vazio): ?>
    <section class="faixa-grade ini-vazio" aria-labelledby="ini-vazio-t">
        <h2 id="ini-vazio-t"><?= e(t('O mês começa aqui')) ?></h2>
        <p><?= e(t('Aqui você vai ver quanto dá para gastar até o fim do mês, as contas a pagar e o que entrou e saiu.')) ?></p>
        <a class="btn btn-principal" href="/lancar?volta=%2F" data-lancar><?= svgIcon('plus', 16) ?><?= e(t('Lançar o primeiro valor')) ?></a>
    </section>
<?php else: ?>

    <!-- a resposta e, ao lado, a conta dela: a barra do dinheiro do mês e o livro-caixa que a explica -->
    <section class="faixa-grade" aria-labelledby="ini-resposta-t">
        <div class="painel ini-painel" data-resposta="<?= e($tipo) ?>">
            <div class="ini-resposta">
                <h2 class="rot-resposta" id="ini-resposta-t"><?= e($rotulo) ?> <?= in_array($tipo, ['vermelho', 'abaixo'], true) ? dica('fecha', t('esse valor')) : dica('gastar', t('esse valor')) ?></h2>
                <p class="resposta-num"><?= $numero ?></p>
            </div>
            <?php if ($destino !== null && $destino['total'] >= 0.005): $livro = Destino::livro($destino, $mesPrevisao); ?>
                <div class="ini-destino" data-destino data-mov-barra="ini-<?= e($mesPrevisao) ?>" data-mov-dia="<?= e($hoje) ?>" data-total="<?= e(number_format($destino['total'], 2, '.', '')) ?>" data-gastar="<?= e(number_format($destino['gastar'], 2, '.', '')) ?>">
                    <?php View::partial('partials/destino', ['segmentos' => $livro['segmentos'], 'total' => $destino['total'], 'legenda' => false]); ?>
                    <?php View::partial('partials/livro_mes', ['livro' => $livro, 'titulo' => t('A conta de {mes}', ['mes' => mesExtenso($mesPrevisao)])]); ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($temLancamento): ?>
    <!-- o que já aconteceu no mês -->
    <section class="faixa-grade" aria-labelledby="ini-mes-t">
        <h2 class="sr-only" id="ini-mes-t"><?= e($previsto ? t('{mes} previsto', ['mes' => $mesTit]) : t('{mes} até hoje', ['mes' => $mesTit])) ?></h2>
        <dl class="numeros ini-numeros">
            <div>
                <dt><?= e(t('Em conta hoje')) ?> <button type="button" class="ini-olho" data-ocultar aria-pressed="false" aria-label="<?= e(t('esconder valores')) ?>"><?= svgIcon('eye', 16, 'olho-aberto') ?><?= svgIcon('eye-off', 16, 'olho-fechado') ?></button></dt>
                <dd data-mov-conta="em-conta" data-mov-valor="<?= e(number_format($conta, 2, '.', '')) ?>"><?= valor($conta, 'neutro', ['classe' => $conta < -0.004 ? 'alerta' : '']) ?>
                    <?php if ($conta < -0.004): ?><span class="nota alerta"><?= e(t('no vermelho')) ?></span><?php elseif ($reserva > 0.004 && $conta < $reserva - 0.004): ?><span class="nota atencao"><?= e(t('abaixo da reserva')) ?></span><?php endif; ?>
                    <?php if ($contas !== []): ?>
                        <details class="ini-contas">
                            <summary><?= e(t('Ver cada conta')) ?></summary>
                            <ul class="saldo-contas num" aria-label="<?= e(t('saldo de cada conta')) ?>">
                                <?php foreach ($contas as $c): ?>
                                    <li><span><?= e(nomeConta($c)) ?></span><?= valor((float) $c['saldo'], 'neutro', ['classe' => (float) $c['saldo'] < -0.004 ? 'alerta' : '', 'centavos' => false]) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                    <?php endif; ?>
                </dd>
            </div>
            <div><dt><?= e(t('Entrou')) ?></dt><dd data-entrou><?= valor((float) $sobra['renda'], $previsto ? 'previsto' : 'entrada') ?></dd></div>
            <div><dt><?= e(t('Gastou')) ?></dt><dd data-gasto><?= valor((float) $sobra['saidas'], $previsto ? 'previsto' : 'saida') ?></dd></div>
            <div><dt><?= e(t('Guardado')) ?></dt><dd data-guardado><?= $guardado >= 0 ? valor($guardado, 'saida') : valor(-$guardado, 'entrada') ?></dd></div>
        </dl>
    </section>
    <?php endif; ?>

    <?php if ($avisos !== []): ?>
    <div class="faixa-grade ini-aten">
        <?php View::partial('partials/atencao', ['avisos' => $avisos, 'max' => \Financeiro\Services\Atencao::MAX]); ?>
    </div>
    <?php endif; ?>

    <div class="faixa-grade">
        <div class="duas">
            <section id="contas-mes" aria-labelledby="ini-contas-t">
                <div class="secao-cab">
                    <h2 id="ini-contas-t"><?= e(t('Contas do mês')) ?></h2>
                    <?php if ($nContas > 0): ?><p class="num"><?= e(tn($nContas, '{pagas} de 1 paga', '{pagas} de {n} pagas', ['pagas' => $nPagas])) ?></p><?php endif; ?>
                </div>
                <?php if ($nContas === 0): ?>
                    <p class="ini-nada"><?= e(t('As contas fixas do mês aparecem aqui, com o dia de vencimento.')) ?> <a class="link" href="/meta"><?= e(t('Ver as contas fixas')) ?></a></p>
                <?php else: ?>
                <ul>
                    <?php foreach ($contasMes as $c):
                        $id = (int) $c['categoria_id'];
                        $nome = nomeCurto($c);
                        $quando = $vence($c); ?>
                        <li class="linha-lista com-acao ini-conta<?= $c['atrasado'] ? ' is-vencida' : '' ?>">
                            <?= iconeCategoria($id, (string) $c['nome'], (string) $c['grupo'], 20, 'linha-ic') ?>
                            <a class="linha-nome ini-conta-nome" href="/lancar?categoria=<?= $id ?>&amp;volta=%2F" data-lancar data-categoria="<?= $id ?>">
                                <b><?= e($nome) ?></b><?php if ($quando !== ''): ?><small class="<?= $c['atrasado'] ? 'atencao' : '' ?>"><?= e($quando) ?></small><?php endif; ?>
                            </a>
                            <?= valor((float) $c['previsto'], 'previsto', ['centavos' => false]) ?>
                            <form class="linha-acao" method="post" action="/lancamentos/pago" data-espera="<?= e(t('Lançando…')) ?>">
                                <?= $csrf ?>
                                <input type="hidden" name="categoria_id" value="<?= $id ?>">
                                <input type="hidden" name="volta" value="/">
                                <button type="submit" class="btn btn-contorno btn-sm btn-pagar"><span class="circ" aria-hidden="true"></span><?= e(t('Pagar')) ?><span class="sr-only"> <?= e($nome) ?></span></button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                    <?php foreach ($contasPagas as $c): ?>
                        <?php if (!empty($c['agendado'])): ?>
                            <li class="linha-lista com-acao ini-conta">
                                <?= iconeCategoria((int) $c['categoria_id'], (string) $c['nome'], (string) $c['grupo'], 20, 'linha-ic') ?>
                                <span class="linha-nome"><b><?= e(nomeCurto($c)) ?></b><small><?= e(t('agendado {data}', ['data' => diaMes((string) $c['data'])])) ?></small></span>
                                <?= valor((float) $c['pago'], 'previsto', ['centavos' => false]) ?>
                            </li>
                        <?php continue; endif; ?>
                        <li class="linha-lista com-acao ini-conta is-paga" data-mov-pago="<?= (int) $c['categoria_id'] ?>">
                            <?= iconeCategoria((int) $c['categoria_id'], (string) $c['nome'], (string) $c['grupo'], 20, 'linha-ic') ?>
                            <span class="linha-nome"><b><?= e(nomeCurto($c)) ?></b></span>
                            <?= valor((float) $c['pago'], 'saida', ['centavos' => false]) ?>
                            <span class="linha-acao"><?= carimbo((string) $c['data']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </section>

            <section aria-labelledby="ini-ult-t">
                <div class="secao-cab">
                    <h2 id="ini-ult-t"><?= e(t('Últimos lançamentos')) ?></h2>
                    <p><?= e($mesNome) ?></p>
                </div>
                <?php if ($ultimos === []): ?>
                    <div class="ini-nada">
                        <p><?= e(t('Os lançamentos de {mes} aparecem aqui, do mais novo para o mais antigo.', ['mes' => $mesNome])) ?></p>
                        <a class="btn btn-contorno btn-sm" href="/lancar?volta=%2F" data-lancar><?= svgIcon('plus', 16) ?><?= e(t('Lançar')) ?></a>
                    </div>
                <?php else: ?>
                    <?php foreach ($porDia as $data => $linhas):
                        $agendado = (string) $data > $hoje;
                        $somaDia = (float) ($somasDia[(string) $data] ?? 0.0); ?>
                        <p class="razao-dia"><span><?= e($nomeDia((string) $data)) ?></span><span class="num<?= $agendado ? ' v-previsto' : '' ?>"><?= e(($somaDia > 0.004 ? '+' : ($somaDia < -0.004 ? MENOS : '')) . num(abs($somaDia))) ?></span></p>
                        <ul>
                            <?php foreach ($linhas as $r):
                                $cat = ['nome' => $r['categoria_nome'], 'apelido' => $r['apelido']];
                                $desc = descricaoTela(trim((string) $r['descricao']), $cat); ?>
                                <li>
                                    <a class="linha-lista" href="/lancamentos/<?= (int) $r['id'] ?>/editar?volta=%2F">
                                        <?= iconeCategoria((int) $r['categoria_id'], (string) $r['categoria_nome'], (string) ($r['grupo'] ?? ''), 20, 'linha-ic') ?>
                                        <span class="linha-nome"><b><?= e($desc !== '' ? $desc : nomeCurto($cat)) ?></b><small><?= e(nomeCurto($cat)) ?><?= $agendado ? ' · ' . e(t('agendado')) : '' ?></small></span>
                                        <?= $agendado
                                            ? '<span class="num v-previsto">' . ($entra($r) ? '+' : MENOS) . e(num((float) $r['valor'])) . '</span>'
                                            : valor((float) $r['valor'], $entra($r) ? 'entrada' : 'saida', ['centavos' => false]) ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endforeach; ?>
                    <p class="secao-pe"><a href="/lancamentos?mes=<?= e(substr($mesRef, 0, 7)) ?>"><?= e(t('Ver o extrato de {mes}', ['mes' => $mesNome])) ?></a></p>
                <?php endif; ?>
            </section>
        </div>
    </div>

    <div class="faixa-grade">
        <div class="duas">
            <!-- para onde foi o gasto do mês -->
            <section aria-labelledby="ini-onde-t">
                <div class="secao-cab">
                    <h2 id="ini-onde-t"><?= e(t('Para onde foi')) ?></h2>
                    <p><?= e(t('% dos gastos')) ?></p>
                </div>
                <?php $categorizar = $p['semCategoria'] !== null ? '/cartao/compras/' . (int) $p['semCategoria']['compras'][0] . '/editar' : null; ?>
                <?php if ($p['top'] === []): ?>
                    <p class="ini-nada"><?= e(t('Os gastos de {mes} aparecem aqui, por categoria.', ['mes' => $mesNome])) ?></p>
                <?php else: ?>
                    <ol>
                        <?php foreach ($p['top'] as $t): $semCat = $p['porCompra'] && (int) $t['id'] === \Financeiro\Services\GastoPorCategoria::SEM_CATEGORIA; ?>
                            <li>
                                <a class="linha-lista ini-onde" href="<?= $semCat ? e((string) $categorizar) : '/lancamentos?mes=' . e(substr($mesRef, 0, 7)) . '&amp;cat=' . (int) $t['id'] ?>">
                                    <?= iconeCategoria((int) $t['id'], (string) $t['nome_banco'], $semCat ? 'Cartao' : '', 20, 'linha-ic') ?>
                                    <span class="linha-nome"><b><?= e($t['nome']) ?></b><span class="meta-barra" aria-hidden="true"><i style="--p:<?= e(number_format($topMax > 0 ? $t['valor'] / $topMax : 0, 3, '.', '')) ?>"></i></span></span>
                                    <span class="num ini-onde-v"><?= e(num($t['valor'])) ?><small><?= $share($t['valor'], $p['ondeTotal']) ?>%</small></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
                <?php if ($categorizar !== null): ?>
                    <p class="ini-nota num"><?= t('Compras no cartão sem categoria: {valor}. {link}', ['valor' => e(num($p['semCategoria']['valor'])), 'link' => '<a class="link" href="' . e($categorizar) . '">' . e(t('Categorizar')) . '</a>']) ?></p>
                <?php endif; ?>
                <?php if ($p['cartaoAberto']): ?>
                    <p class="ini-nota"><?= $p['porCompra']
                        ? t('Cartão pela data da compra. {link}', ['link' => '<a class="link" href="/?onde=fatura#ini-onde-t">' . e(t('Ver por fatura')) . '</a>'])
                        : t('Cartão pela fatura paga. {link}', ['link' => '<a class="link" href="/?onde=compra#ini-onde-t">' . e(t('Ver pela data da compra')) . '</a>']) ?></p>
                <?php endif; ?>
            </section>

        </div>
    </div>
<?php endif; ?>
</div>
