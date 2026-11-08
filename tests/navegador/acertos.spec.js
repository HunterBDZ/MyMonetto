// Acerto de saldo com o banco: a diferença lançada de uma vez, fora das categorias. Na demonstração (dia 22 de novembro,
// outubro fechado), o acerto de 31/10 entra em 1º de novembro. Cada teste desfaz o que lançou, porque o servidor é do
// processo inteiro e outros testes contam os números do Extrato.
import { test, expect } from './fixtures.js';

test.describe.configure({ mode: 'serial' });

// "4.376,76" → 4376.76
const ler = (txt) => parseFloat(txt.replace(/[^\d,−-]/g, '').replace('−', '-').replace(/\./g, '').replace(',', '.'));
const escrever = (v) => v.toFixed(2).replace('.', ',');

test('Acertar saldo pelo Extrato: o saldo de um dia de outubro (fechado) entra em 1º de novembro', async ({ page }) => {
    await page.goto('/lancamentos?mes=2026-11-01');
    const veio = ler(await page.locator('[data-ex-total="abertura"]').innerText());
    await expect(page.locator('[data-ex-abertura]')).toContainText('Saldo que veio de outubro');

    await page.locator('.ext-menu summary').click();
    await page.getByRole('link', { name: 'Acertar saldo com o banco' }).click();
    await expect(page.getByRole('heading', { name: 'Acertar saldo' })).toBeVisible();
    const form = page.locator('.ac-form');
    await form.getByLabel('Saldo que o banco mostra').fill(escrever(veio - 0.37));
    await form.locator('label.chip-r', { hasText: 'Outro dia' }).click();
    await form.getByLabel('data do saldo').fill('2026-10-31');
    await form.getByRole('button', { name: 'Ver o acerto' }).click();

    // a prévia: nada gravado ainda
    const previa = page.locator('.ac-previa');
    await expect(previa).toContainText('Acerto de −R$ 0,37');
    await expect(previa).toContainText('Outubro está fechado: o acerto entra em 01/11');
    await previa.getByRole('button', { name: 'Lançar o acerto' }).click();

    const aviso = page.getByRole('status').filter({ hasText: 'Saldo acertado' });
    await expect(aviso).toContainText('−R$ 0,37');
    await expect(page).toHaveURL(/\/lancamentos/);
    await page.goto('/lancamentos?mes=2026-11-01');
    const linha = page.locator('tr.ext-acerto');
    await expect(linha).toContainText('Acerto do saldo de outubro');
    await expect(linha).toContainText('−0,37');
    // o resumo não tem coluna de acertos: o acerto aparece só como linha, no dia dele
    await expect(page.locator('[data-ex-total="acertos"]')).toHaveCount(0);
    // o saldo que veio de outubro não muda: outubro está fechado
    expect(ler(await page.locator('[data-ex-total="abertura"]').innerText())).toBeCloseTo(veio, 2);

    // abre para ler, e excluir tem desfazer
    await linha.getByRole('link').click();
    await expect(page.getByRole('heading', { name: 'Acerto de saldo' })).toBeVisible();
    page.once('dialog', (d) => d.accept());
    await page.getByRole('button', { name: 'Excluir acerto' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Acerto excluído' })).toBeVisible();
    await page.goto('/lancamentos?mes=2026-11-01');
    await expect(page.locator('tr.ext-acerto')).toHaveCount(0);
});

test('o Início não tem o Conferir com o banco, nem o aviso da conferência (2.1.4)', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('#conferir')).toHaveCount(0);
    await expect(page.getByText('Conferir com o banco')).toHaveCount(0);
    await expect(page.getByText('A conferência com o banco não bateu')).toHaveCount(0);
    await page.goto('/atencao');
    await expect(page.locator('[data-aviso="conferencia"]')).toHaveCount(0);
});
