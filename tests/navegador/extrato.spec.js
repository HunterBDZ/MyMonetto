// Extrato: o livro-razão de largura total. A faixa do período, os filtros numa linha com a busca (a tecla "/" põe o
// cursor nela), a tabela agrupada por dia com a soma do dia, o vazio da busca e o período escrito inteiro.
import { test, expect } from './fixtures.js';

test('a faixa do período, a tabela por dia e as colunas do livro-razão', async ({ page }) => {
    await page.goto('/lancamentos?mes=2026-11-01');
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Extrato');
    const faixa = page.getByRole('region', { name: /^Resumo de Novembro de 2026/ });
    await expect(faixa.getByText('Entrou', { exact: true })).toBeVisible();
    await expect(faixa.getByText('Gastou', { exact: true })).toBeVisible();
    // o saldo da conta (o que veio do mês anterior, mais o que entrou, menos o que saiu), e não "o que entrou menos o gasto"
    await expect(faixa.getByText('Saldo', { exact: true })).toBeVisible();
    await expect(faixa.getByText('Saldo do período', { exact: true })).toHaveCount(0);

    const tabela = page.getByRole('table', { name: /Lançamentos de Novembro de 2026/ });
    await expect(tabela.getByRole('columnheader')).toHaveText(['Descrição', 'Categoria', 'Conta', 'Valor']);
    // cada dia abre um grupo, com a soma do dia à direita
    const dia = tabela.locator('.ext-dia').first();
    await expect(dia.locator('th')).not.toBeEmpty();
    await expect(dia.locator('.ext-soma')).toHaveText(/^[+−][\d.]+,\d\d$/);
    // o que saiu é vermelho com "−"; o que entrou, verde com "+"
    await expect(tabela.locator('.ext-linha .v-saida').first()).toHaveText(/^−/);
    await expect(tabela.locator('.ext-linha .v-entrada').first()).toHaveText(/^\+/);
    // sem o formulário de lançar nem a lista de contas do mês na tela
    await expect(page.locator('form[data-lf]:not(dialog form)')).toHaveCount(0);
});

test('o período aparece inteiro, com a maiúscula só no mês', async ({ page }) => {
    await page.goto('/lancamentos?mes=2026-11-01');
    const periodo = page.getByRole('combobox', { name: 'período' });
    await expect(periodo.locator('option:checked')).toHaveText('Novembro de 2026');
    // o texto cabe na caixa: nada cortado
    const cabe = await periodo.evaluate((el) => {
        const c = document.createElement('canvas').getContext('2d');
        const s = getComputedStyle(el);
        c.font = `${s.fontWeight} ${s.fontSize} ${s.fontFamily}`;
        const livre = el.clientWidth - parseFloat(s.paddingLeft) - parseFloat(s.paddingRight);
        return c.measureText(el.selectedOptions[0].text).width <= livre;
    });
    expect(cabe).toBe(true);
});

test('a tecla / põe o cursor na busca; sem resultado, a tela diz onde buscou e oferece todos os meses', async ({ page }) => {
    await page.goto('/lancamentos?mes=2026-11-01');
    const busca = page.getByRole('searchbox', { name: 'buscar' });
    await page.locator('body').press('/');
    await expect(busca).toBeFocused();
    await busca.fill('farmaciazz');
    await busca.press('Enter');
    await expect(page.getByText('Nada com “farmaciazz” em novembro.')).toBeVisible();
    await page.getByRole('link', { name: 'Buscar em todos os meses' }).click();
    await expect(page).toHaveURL(/periodo=tudo/);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Extrato');
});

test.describe('no celular', () => {
    test.use({ viewport: { width: 390, height: 844 } });

    test('a tabela vira lista e nada rola para o lado', async ({ page }) => {
        await page.goto('/lancamentos?mes=2026-11-01');
        const linha = page.locator('.ext-linha').first();
        await expect(linha.locator('.ext-sub')).toBeVisible();
        await expect(linha.locator('.ext-c-cat')).toBeHidden();
        const largura = await page.evaluate(() => document.documentElement.scrollWidth);
        expect(largura).toBeLessThanOrEqual(390);
    });
});
