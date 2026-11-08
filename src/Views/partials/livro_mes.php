<?php
/**
 * O livro-caixa do mês no Início (Destino::livro): a conta da resposta em linhas, como um extrato. O dinheiro do mês,
 * menos o que já saiu, menos o que ainda vai sair e a reserva, e embaixo de um fio de 2px a última linha: "Dá para
 * gastar" em azul (a resposta), ou "Fecha com" em âmbar (abaixo da reserva) ou em vermelho (no vermelho). Cada linha
 * leva o quadrado da cor da barra logo acima, que ela explica; a primeira, o dinheiro do mês, é a barra inteira.
 * Só rótulo e número: nada de nota embaixo nem título à vista (o nome da tabela é só para o leitor de tela).
 * Variáveis: $livro (Destino::livro), $titulo (o nome da tabela, para o leitor de tela).
 */
$livro = (array) ($livro ?? []);
$fim = (array) ($livro['fim'] ?? []);
$titulo = (string) ($titulo ?? '');
$valor = static fn(float $v): string => ($v < -0.004 ? MENOS : '') . num(abs($v));
?>
<table class="livro-mes">
    <?php if ($titulo !== ''): ?><caption class="sr-only"><?= e($titulo) ?></caption><?php endif; ?>
    <tbody>
        <?php foreach ((array) ($livro['linhas'] ?? []) as $i => $l): ?>
            <tr<?= $i === 0 ? ' class="livro-mes-base"' : '' ?>>
                <th scope="row">
                    <span class="livro-mes-rot"><?php if ((string) $l['classe'] !== ''): ?><i class="<?= e((string) $l['classe']) ?>" aria-hidden="true"></i><?php endif; ?><?= e((string) $l['rotulo']) ?></span>
                </th>
                <td class="num"><?= e($valor((float) $l['valor'])) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
    <?php if ($fim !== []): ?>
    <tfoot>
        <tr class="livro-mes-fim tom-<?= e((string) $fim['tom']) ?>">
            <th scope="row"><span class="livro-mes-rot"><i class="<?= e((string) $fim['classe']) ?>" aria-hidden="true"></i><?= e((string) $fim['rotulo']) ?></span></th>
            <td class="num"><?= e($valor((float) $fim['valor'])) ?></td>
        </tr>
    </tfoot>
    <?php endif; ?>
</table>
