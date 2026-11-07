<?php
namespace Financeiro;

/** As perguntas da aba Ajuda. Os textos ficam aqui, sem depender de internet, e passam por t() como os das telas. */
final class Ajuda
{
    /** @return list<array{titulo: string, itens: list<array{id: string, pergunta: string, resposta: string, link?: array{0: string, 1: string}}>}> */
    public static function grupos(): array
    {
        $grupos = [
            ['titulo' => t('Começar'), 'itens' => [
                ['id' => 'comecar', 'pergunta' => t('Como começo a usar?'), 'resposta' => t('Registre o que entra e o que sai em Lançar. Depois, veja em Mês como ele deve terminar.')],
                ['id' => 'inicio-errado', 'pergunta' => t('Errei os valores do começo. E agora?'), 'resposta' => t('Corrija em Ajustes, em Ponto de partida.')],
                ['id' => 'moeda', 'pergunta' => t('Posso trocar a moeda?'), 'resposta' => t('Sim, em Ajustes. Muda o símbolo em todo o app, para todas as pessoas. Os valores já lançados não são convertidos: 100 continua 100. O formato do número segue o seu idioma.')],
                ['id' => 'fuso', 'pergunta' => t('O dia está errado. Como acerto o fuso?'), 'resposta' => t('Em Ajustes, em Moeda e fuso horário. O fuso decide o que é hoje: a data que já vem no Lançar, os vencimentos e a virada do mês.')],
                ['id' => 'carro', 'pergunta' => t('Não tenho carro. Preciso das categorias de carro?'), 'resposta' => t('Não. Desmarque Carro em Ajustes, em O que você usa: as categorias de carro sem nenhum uso saem das listas. Marque de novo para trazê-las de volta.')],
            ]],
            ['titulo' => t('Dia a dia'), 'itens' => [
                ['id' => 'valor', 'pergunta' => t('Como digito o valor?'), 'resposta' => t('Digite como você fala: 45 é {valor}. Para centavos, use vírgula: {exemplo}.', ['valor' => money(45), 'exemplo' => num(45.9)])],
                ['id' => 'corrigir', 'pergunta' => t('Errei um lançamento. Como corrijo?'), 'resposta' => t('Abra o Extrato, escolha o lançamento e edite ou apague.')],
                ['id' => 'categoria', 'pergunta' => t('Como crio uma categoria?'), 'resposta' => t('Em Ajustes, abra Categorias. Lá também dá para mudar tudo numa categoria: nome, tipo, vencimento, se é essencial e o ícone. O formulário de Lançar tem um atalho para lá.')],
            ]],
            ['titulo' => t('Entender os números'), 'itens' => [
                ['id' => 'em-conta', 'pergunta' => t('O que é "Em conta hoje"?'), 'resposta' => t('É o que a conta deve ter agora: o valor inicial, mais o que entrou, menos o que saiu. Para ver se está certo, compare com o saldo do banco.')],
                ['id' => 'reserva', 'pergunta' => t('O que é a reserva?'), 'resposta' => t('É o mínimo que você quer manter na conta. O app avisa quando um mês previsto fica abaixo dele. Você escolhe o valor em Ajustes.')],
                ['id' => 'previsao', 'pergunta' => t('Como é feita a previsão do fim do mês?'), 'resposta' => t('Ela soma o que já aconteceu com o que ainda está previsto. Em cada categoria, conta o maior valor entre o gasto e o previsto. Se você escolheu adiantar o empréstimo, o que sobra acima da reserva sai da previsão, mas nenhum pagamento é feito.')],
                ['id' => 'gastar', 'pergunta' => t('Como o Início calcula quanto dá para gastar?'), 'resposta' => t('É a previsão para o fim do mês menos a reserva. A previsão já conta as contas e as metas que ainda faltam, então esse valor é o que sobra além do plano. Se você escolheu adiantar o empréstimo, a conta é feita sem o adiantamento, e o que você não gastar vai para ele.')],
                ['id' => 'numeros', 'pergunta' => t('Por que os números mudam de uma tela para outra?'), 'resposta' => t('Cada sobra diz a base. A sobra até hoje é o que entrou menos o que saiu até agora. A sobra prevista é o mês inteiro, contando o que ainda está previsto. Depois que o mês é fechado, fica uma só: a sobra do mês. Se a parcela do empréstimo sai do salário, a sobra usa o salário sem ela. O balanço é quanto a conta mudou: inclui também o que foi guardado, investido ou adiantado do empréstimo.')],
                ['id' => 'conferir', 'pergunta' => t('E se o saldo não bater com o banco?'), 'resposta' => t('Em geral, é um lançamento esquecido ou repetido. Revise o Extrato e lance o que faltou. Se a diferença não vale procurar, use o Acertar o saldo, que aparece depois de conferir no Início, ou o Acertar saldo com o banco, no menu ⋯ do Extrato. O app lança a diferença como um acerto: muda o saldo, mas não é gasto nem entrada.')],
            ]],
            ['titulo' => t('Mês e metas'), 'itens' => [
                ['id' => 'metas', 'pergunta' => t('O que é uma meta?'), 'resposta' => t('É quanto você quer gastar por mês em uma categoria. Em Metas, escolha a partir de que mês ela vale e até quando: sem fim, só um mês ou até um mês escolhido. Também dá para corrigir um mês que já fechou.')],
                ['id' => 'fechar', 'pergunta' => t('O que é fechar o mês?'), 'resposta' => t('É conferir o mês e guardar o resultado: o previsto dá lugar ao que foi lançado. O botão aparece em Mês. Dá para reabrir depois, e isso não desfaz um ajuste aplicado no mês seguinte.')],
            ]],
            ['titulo' => t('Cartão'), 'itens' => [
                ['id' => 'cartao', 'pergunta' => t('Registro a compra ou a fatura?'), 'resposta' => t('Cadastre o cartão na aba Cartão e registre as compras em Lançar. Elas formam as próximas faturas. No plano, conta a fatura, no mês em que vence. Nas Metas e em Para onde foi, cada compra conta na categoria dela, pela data da compra. Nada conta duas vezes.')],
            ]],
            ['titulo' => t('Empréstimo'), 'itens' => [
                ['id' => 'simular', 'pergunta' => t('Como simulo um adiantamento?'), 'resposta' => t('Na aba Empréstimo, digite o valor e o mês. O app mostra quanto falta, quando termina e os juros a menos. Nada é gravado.')],
                ['id' => 'sobra', 'pergunta' => t('O que acontece com o que sobra?'), 'resposta' => t('Em Ajustes, em O que você usa, escolha entre deixar na conta e adiantar o empréstimo. Isso só muda a previsão: nenhum pagamento é feito.')],
                ['id' => 'consolidar', 'pergunta' => t('Paguei um adiantamento. Como registro?'), 'resposta' => t('Na aba Empréstimo, abra Já paguei e informe o valor e a data.')],
            ]],
            ['titulo' => t('Planejar'), 'itens' => [
                ['id' => 'investimentos', 'pergunta' => t('Por que os rendimentos não aparecem em Investimentos?'), 'resposta' => t('A aba soma só o que você registrou ao guardar, aplicar ou resgatar. Rendimentos não entram.')],
                ['id' => 'simulador', 'pergunta' => t('O que a tela Simular faz?'), 'resposta' => t('Mostra os próximos meses e testa mudanças. Nada é salvo. Ela fica no menu, com o nome Simular.')],
                ['id' => 'melhor-mes', 'pergunta' => t('O que é o melhor mês da Wishlist?'), 'resposta' => t('É o mês em que a compra cabe sem deixar a conta abaixo da reserva nem atrasar o empréstimo. É uma estimativa, que muda junto com os seus números.')],
                ['id' => 'cotacao', 'pergunta' => t('Como funciona a cotação de outra moeda?'), 'resposta' => t('A compra é convertida para {moeda} pela cotação comercial, sem impostos nem taxa do cartão. Em fim de semana e feriado, vale o último fechamento.', ['moeda' => moedaPlural()])],
                ['id' => 'relatorio', 'pergunta' => t('Como baixo o relatório?'), 'resposta' => t('Na aba Relatório, escolha mensal ou anual, escolha o período e use Baixar PDF.')],
            ]],
            ['titulo' => t('Conta e dados'), 'itens' => [
                ['id' => 'backup', 'pergunta' => t('Como faço backup?'), 'resposta' => t('O MyMonetto faz uma cópia por dia. Em Ajustes, em Backup e atualização, você baixa uma cópia, restaura um backup e procura versões novas.')],
                ['id' => 'celular', 'pergunta' => t('Como uso no celular?'), 'resposta' => t('Em Ajustes, abra Usar no celular, ligue o acesso pela rede e leia o código com a câmera. O celular precisa estar na mesma rede, e o computador, ligado.')],
                ['id' => 'senha', 'pergunta' => t('Esqueci a senha. E agora?'), 'resposta' => t('Outra pessoa com conta pode definir uma senha nova em Ajustes, em Pessoas. Se não houver outra pessoa, peça a quem instalou o app.')],
                ['id' => 'pessoas', 'pergunta' => t('Como dou acesso a outra pessoa?'), 'resposta' => t('Em Ajustes, em Pessoas, informe nome, nome de usuário e senha. Todas as pessoas veem os mesmos dados.')],
                ['id' => 'aparencia', 'pergunta' => t('Como troco o idioma ou o tema?'), 'resposta' => t('O idioma fica em Ajustes, e o tema, na tela Temas. Cada conta tem os seus.')],
                ['id' => 'dados', 'pergunta' => t('Onde ficam os meus dados?'), 'resposta' => t('No computador onde o app foi instalado. A internet só é usada para a cotação de moedas, os links colados na Wishlist e a busca por versão nova. Detalhes em Privacidade, no fim desta página.')],
                ['id' => 'aviso', 'pergunta' => t('Por que o navegador diz que a conexão não é segura?'), 'resposta' => t('É esperado: na instalação, o MyMonetto criou um certificado próprio, e o navegador ainda não o conhece. A conexão continua criptografada, e os dados não saem da sua rede.'),
                    'link' => ['/ajuda/conexao', t('Os passos de cada navegador')]],
            ]],
        ];
        // sem cartão ou sem empréstimo, as perguntas deles somem junto com a aba
        $fora = array_filter([
            !Models\AjusteModel::usa('usa_cartao') ? t('Cartão') : null,
            !Models\AjusteModel::usa('usa_emprestimo') ? t('Empréstimo') : null,
        ]);
        return array_values(array_filter($grupos, static fn(array $g): bool => !in_array($g['titulo'], $fora, true)));
    }
}
