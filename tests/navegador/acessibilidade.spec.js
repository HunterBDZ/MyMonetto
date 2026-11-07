// Acessibilidade básica: a varredura do axe nas telas principais, nos temas Claro e Escuro. Só reprova o que o axe
// classifica como grave (serious) ou crítico (critical). Roda só no Chromium (playwright.config.js).
import AxeBuilder from '@axe-core/playwright';
import { test, expect, escolherTema, contaSemPagamento, TELAS, TELAS_AJUSTES } from './fixtures.js';

// Violações reais que o app ainda tem: [regra do axe, elemento]. As varreduras das telas ignoram só esse elemento
// nessa regra, para pegar qualquer violação nova. Se aparecer uma, ponha aqui e crie um teste "esperado falhar".
const CONHECIDAS = [];

/**
 * As violações graves da página: "regra (impacto): elemento, elemento". Sem `regras`, roda todas e deixa de fora as
 * conhecidas; com `regras`, roda só essas e não deixa nada de fora.
 */
async function graves(page, regras = null) {
    const axe = new AxeBuilder({ page });
    if (regras) {
        axe.withRules(regras);
    }
    const { violations } = await axe.analyze();
    return violations
        .filter((v) => v.impact === 'serious' || v.impact === 'critical')
        .map((v) => [v, v.nodes.map((n) => n.target.join(' ')).filter((alvo) => regras || !CONHECIDAS.some(([r, re]) => r === v.id && re.test(alvo)))])
        .filter(([, alvos]) => alvos.length > 0)
        .map(([v, alvos]) => `${v.id} (${v.impact}): ${alvos.join(', ')}`);
}

/** Espera o painel terminar de entrar: no meio da animação a opacidade ainda não é 1, e o axe mede o contraste errado. */
async function fimDaAnimacao(page, seletor) {
    await page.locator(seletor).evaluate((el) => Promise.all(el.getAnimations().map((a) => a.finished)));
}

for (const tema of ['claro', 'escuro']) {
    test.describe(`tema ${tema}`, () => {
        test.beforeEach(async ({ page, baseURL }) => {
            await escolherTema(page, baseURL, tema);
        });
        test.afterEach(async ({ page, baseURL }) => {
            await escolherTema(page, baseURL, 'auto');
        });

        for (const [nome, caminho] of [...TELAS, ...TELAS_AJUSTES]) {
            test(nome, async ({ page }) => {
                await page.goto(caminho);
                expect(await graves(page)).toEqual([]);
            });
        }

        test('Ajustes com as categorias abertas', async ({ page }) => {
            await page.goto('/ajustes?aberto=categorias');
            expect(await graves(page)).toEqual([]);
        });

        test('editar categoria', async ({ page }) => {
            await page.goto('/ajustes?aberto=categorias');
            await page.locator('#categorias').getByRole('link', { name: /Mercado/ }).click();
            await expect(page.getByRole('heading', { level: 1, name: 'Mercado' })).toBeVisible();      // o título é o nome
            expect(await graves(page)).toEqual([]);
        });

        test('Acertar saldo: o formulário e a conta antes de lançar', async ({ page }) => {
            await page.goto('/acertos/novo');
            expect(await graves(page)).toEqual([]);
            await page.locator('.ac-form').getByLabel('Saldo que o banco mostra').fill('1,00');
            await page.locator('.ac-form').getByRole('button', { name: 'Ver o acerto' }).click();
            await expect(page.locator('.ac-previa')).toBeVisible();
            expect(await graves(page)).toEqual([]);
        });

        test('privacidade', async ({ page }) => {
            await page.goto('/privacidade');
            expect(await graves(page)).toEqual([]);
        });

        test('diálogo de lançar', async ({ page }) => {
            await page.goto('/');
            await page.getByRole('complementary', { name: 'menu' }).getByRole('link', { name: 'Lançar' }).click();
            await expect(page.getByRole('dialog', { name: 'lançar' })).toBeVisible();
            expect(await graves(page)).toEqual([]);
            // com a lista de categorias aberta e uma opção em destaque
            const campo = page.getByRole('dialog', { name: 'lançar' }).getByRole('combobox', { name: 'Categoria' });
            await campo.click();
            await campo.press('ArrowDown');
            await expect(campo).toHaveAttribute('aria-activedescendant', /.+/);
            expect(await graves(page)).toEqual([]);
        });

        test('Mês com a edição do previsto aberta, o mês fechado e o Fechar', async ({ page }) => {
            await page.goto('/mes');
            await page.locator('tr', { hasText: 'Mercado' }).locator('details.mes-ed > summary').click();
            await fimDaAnimacao(page, 'details.mes-ed[open] > .mes-ed-painel');
            expect(await graves(page)).toEqual([]);
            await page.goto('/mes?mes=2026-10-01');
            expect(await graves(page)).toEqual([]);
            await page.goto('/mes/fechar');
            expect(await graves(page)).toEqual([]);
        });

        test('diálogo de lançar com o erro no campo', async ({ page }) => {
            await page.goto('/');
            await page.keyboard.press('n');
            const dialogo = page.getByRole('dialog', { name: 'lançar' });
            await dialogo.getByRole('textbox', { name: 'valor' }).fill('');
            await dialogo.getByRole('button', { name: 'Lançar' }).click();
            await expect(dialogo.getByText('Digite o valor.')).toBeVisible();
            expect(await graves(page)).toEqual([]);
        });

        test('Início com contas do mês a pagar e o Extrato com a conta vencida', async ({ page, baseURL }) => {
            await contaSemPagamento(page, baseURL, 'Luz');
            await page.goto('/');
            await expect(page.getByRole('region', { name: 'Contas do mês' })).toBeVisible();
            expect(await graves(page)).toEqual([]);
            await page.goto('/lancamentos');
            await expect(page.locator('.ext-vencida')).toContainText('Luz');
            expect(await graves(page)).toEqual([]);
        });

        test('Extrato: busca sem resultado e filtros abertos', async ({ page }) => {
            await page.goto('/lancamentos?q=nada-com-isso&min=1');
            await expect(page.getByText(/Buscar em todos os meses|Nada encontrado/).first()).toBeVisible();
            expect(await graves(page)).toEqual([]);
        });

        test.describe('no celular', () => {
            test.use({ viewport: { width: 390, height: 844 }, hasTouch: true });

            test('Início, com a barra de baixo e o Mais aberto', async ({ page }) => {
                await page.goto('/');
                expect(await graves(page)).toEqual([]);
                const barra = page.getByRole('navigation', { name: 'principal' });
                await barra.getByText('Mais', { exact: true }).click();
                await expect(barra.getByRole('link', { name: 'Ajustes' })).toBeVisible();
                await fimDaAnimacao(page, '.tab-mais > .menu-painel');
                expect(await graves(page)).toEqual([]);
            });

            test('o Mais no modo organizar', async ({ page }) => {
                await page.goto('/');
                const barra = page.getByRole('navigation', { name: 'principal' });
                await barra.getByText('Mais', { exact: true }).click();
                await barra.getByRole('button', { name: 'Organizar menu' }).click();
                await expect(barra.getByRole('button', { name: 'Pronto' })).toBeVisible();
                await fimDaAnimacao(page, '.tab-mais > .menu-painel');
                expect(await graves(page)).toEqual([]);
            });

            test('Temas, com uma paleta em experiência', async ({ page }) => {
                await page.goto('/temas');
                await page.getByRole('group', { name: 'Azulejo' }).getByRole('button', { name: 'Escuro' }).click();
                await expect(page.getByRole('status').filter({ hasText: 'Azulejo, escuro' })).toBeVisible();
                expect(await graves(page)).toEqual([]);
            });
        });

        test('menu lateral no modo organizar', async ({ page }) => {
            await page.goto('/');
            await page.getByRole('complementary', { name: 'menu' }).getByRole('button', { name: 'Organizar menu' }).click();
            await expect(page.getByRole('complementary', { name: 'menu' }).getByRole('button', { name: 'Pronto' })).toBeVisible();
            expect(await graves(page)).toEqual([]);
        });
    });

    test.describe(`tema ${tema}, fora da conta`, () => {
        test.use({ logado: false, colorScheme: tema === 'escuro' ? 'dark' : 'light' });

        test('Login', async ({ page }) => {
            await page.goto('/login');
            // a vitrine entra com opacidade: o contraste só vale depois que a cena parou
            await page.waitForFunction(() => document.getAnimations().every((a) => a.playState !== 'running'));
            expect(await graves(page)).toEqual([]);
        });

        test('aviso do navegador e página que não existe', async ({ page, erros }) => {
            erros.permitir(/status of 404/);
            await page.goto('/ajuda/conexao');
            expect(await graves(page)).toEqual([]);
            await page.goto('/nao-existe');
            expect(await graves(page)).toEqual([]);
        });
    });
}

test.describe('violações já corrigidas não voltam', () => {
    test.afterEach(async ({ page, baseURL }) => {
        await escolherTema(page, baseURL, 'auto');
    });

    test('o <html> não recebe aria-pressed quando a conta tem tema', async ({ page, baseURL }) => {
        await escolherTema(page, baseURL, 'claro');
        await page.goto('/');
        expect(await graves(page, ['aria-allowed-attr'])).toEqual([]);
    });

    test('o seletor de mês tem no nome o texto que mostra', async ({ page }) => {
        await page.goto('/mes');
        expect(await graves(page, ['label-content-name-mismatch'])).toEqual([]);
    });

    test('a escada de faturas tem no nome o texto que mostra', async ({ page }) => {
        await page.goto('/cartao');
        expect(await graves(page, ['label-content-name-mismatch'])).toEqual([]);
    });
});
