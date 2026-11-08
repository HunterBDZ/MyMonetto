<?php
declare(strict_types=1);

use Financeiro\Router;
use Financeiro\Controllers\AuthController;
use Financeiro\Controllers\DashboardController;
use Financeiro\Controllers\LancamentosController;
use Financeiro\Controllers\MesController;
use Financeiro\Controllers\MetaController;
use Financeiro\Controllers\CategoriaController;
use Financeiro\Controllers\FuturoController;
use Financeiro\Controllers\AjudaController;
use Financeiro\Controllers\AcertoController;
use Financeiro\Controllers\AjustesController;
use Financeiro\Controllers\DuasEtapasController;
use Financeiro\Controllers\CartaoController;
use Financeiro\Controllers\EmprestimoController;
use Financeiro\Controllers\WishlistController;
use Financeiro\Controllers\SistemaController;
use Financeiro\Controllers\TemaController;
use Financeiro\Controllers\IdiomaController;
use Financeiro\Controllers\MenuController;
use Financeiro\Controllers\InvestimentosController;
use Financeiro\Controllers\ComecarController;
use Financeiro\Controllers\RelatorioController;
use Financeiro\Controllers\ContaController;
use Financeiro\Controllers\TransferenciaController;
use Financeiro\Controllers\ImportacaoController;

$router = new Router();

// ---- sessão
$router->get('/login', [AuthController::class, 'form']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/login/codigo', [AuthController::class, 'codigoForm']);
$router->post('/login/codigo', [AuthController::class, 'codigo']);
$router->get('/criar-conta', [AuthController::class, 'criarContaForm']);
$router->post('/criar-conta', [AuthController::class, 'criarConta']);
$router->post('/logout', [AuthController::class, 'logout']);

// ---- início
$router->get('/', [DashboardController::class, 'index']);
$router->get('/atencao', [DashboardController::class, 'atencao']);
$router->post('/roteiro/dispensar', [DashboardController::class, 'dispensarRoteiro']);
$router->get('/ajuda', [AjudaController::class, 'index']);
$router->get('/ajuda/conexao', [AjudaController::class, 'conexao']);
$router->get('/privacidade', [AjudaController::class, 'privacidade']);
$router->get('/favicon.ico', [AjudaController::class, 'favicon']);

// ---- lançar e extrato
$router->get('/lancar', [LancamentosController::class, 'lancarForm']);
$router->get('/lancamentos', [LancamentosController::class, 'index']);
$router->get('/lancamentos/planilha', [LancamentosController::class, 'planilha']);
$router->post('/lancamentos', [LancamentosController::class, 'store']);
$router->post('/lancamentos/desfazer', [LancamentosController::class, 'desfazer']);
$router->post('/lancamentos/pago', [LancamentosController::class, 'pago']);
$router->get('/lancamentos/{id}/editar', [LancamentosController::class, 'editForm']);
$router->post('/lancamentos/{id}/excluir', [LancamentosController::class, 'destroy']);
$router->post('/lancamentos/{id}', [LancamentosController::class, 'update']);
$router->post('/transferencias/desfazer', [TransferenciaController::class, 'desfazer']);
$router->get('/transferencias/{id}/editar', [TransferenciaController::class, 'editarForm']);
$router->post('/transferencias/{id}/excluir', [TransferenciaController::class, 'excluir']);
$router->post('/transferencias/{id}', [TransferenciaController::class, 'atualizar']);
// ---- acertos de saldo com o banco
$router->get('/acertos/novo', [AcertoController::class, 'novo']);
$router->post('/acertos', [AcertoController::class, 'criar']);
$router->post('/acertos/desfazer', [AcertoController::class, 'desfazer']);
$router->get('/acertos/{id}', [AcertoController::class, 'ver']);
$router->post('/acertos/{id}/excluir', [AcertoController::class, 'excluir']);
$router->get('/importar', [ImportacaoController::class, 'index']);
$router->post('/importar', [ImportacaoController::class, 'enviar']);
$router->get('/importar/modelo', [ImportacaoController::class, 'modelo']);
$router->get('/importar/colunas', [ImportacaoController::class, 'colunasForm']);
$router->post('/importar/colunas', [ImportacaoController::class, 'colunas']);
$router->get('/importar/revisar', [ImportacaoController::class, 'revisarForm']);
$router->post('/importar/revisar', [ImportacaoController::class, 'revisar']);
$router->post('/importar/regras/{id}/remover', [ImportacaoController::class, 'removerRegra']);
$router->post('/importar/{id}/desfazer', [ImportacaoController::class, 'desfazer']);
$router->post('/categorias', [CategoriaController::class, 'criar']);
$router->post('/categorias/{id}/remover', [CategoriaController::class, 'remover']);
$router->get('/categorias/{id}/icone', [CategoriaController::class, 'iconeAntigo']);
$router->get('/categorias/{id}', [CategoriaController::class, 'editarForm']);
$router->post('/categorias/{id}', [CategoriaController::class, 'salvar']);

// ---- meta (metas de gasto por categoria)
$router->get('/meta', [MetaController::class, 'index']);
$router->post('/meta/editar', [MetaController::class, 'editar']);
$router->post('/meta/contas', [MetaController::class, 'novaConta']);
$router->post('/meta/contas/{id}/desativar', [MetaController::class, 'desativarConta']);
$router->post('/meta/contas/{id}/reativar', [MetaController::class, 'reativarConta']);
$router->post('/meta/contas/{id}', [MetaController::class, 'salvarConta']);

// ---- mês e fechar mês
$router->get('/mes', [MesController::class, 'index']);
$router->post('/mes/previsao', [MesController::class, 'salvarPrevisao']);
$router->post('/mes/desfazer', [MesController::class, 'desfazerLote']);
$router->get('/mes/fechar', [MesController::class, 'fecharForm']);
$router->post('/mes/fechar', [MesController::class, 'fechar']);
$router->post('/mes/reabrir', [MesController::class, 'reabrir']);

// ---- futuro
// ---- cartão (a compra entra pelo POST /lancamentos com pagou=cartao)
$router->get('/cartao', [CartaoController::class, 'index']);
$router->post('/cartao/config', [CartaoController::class, 'salvarConfig']);
$router->post('/cartao/plano', [CartaoController::class, 'plano']);
$router->post('/cartao/arquivar', [CartaoController::class, 'arquivar']);
$router->post('/cartao/pagar', [CartaoController::class, 'pagar']);
$router->post('/cartao/compras/desfazer', [CartaoController::class, 'desfazer']);
$router->get('/cartao/compras/{id}/editar', [CartaoController::class, 'editarForm']);
$router->post('/cartao/compras/{id}/excluir', [CartaoController::class, 'excluir']);
$router->post('/cartao/compras/{id}', [CartaoController::class, 'atualizar']);

$router->get('/futuro', [FuturoController::class, 'index']);
$router->get('/emprestimo', [EmprestimoController::class, 'index']);
$router->post('/emprestimo/adiantamento', [EmprestimoController::class, 'registrar']);
$router->post('/emprestimo/dados', [EmprestimoController::class, 'salvarDados']);

// ---- temas
$router->get('/temas', [TemaController::class, 'index']);
$router->post('/temas', [TemaController::class, 'salvar']);

// ---- primeira configuração
$router->get('/comecar', [ComecarController::class, 'index']);
$router->post('/comecar', [ComecarController::class, 'salvar']);

// ---- relatório
$router->get('/relatorio', [RelatorioController::class, 'index']);
$router->get('/relatorio/pdf', [RelatorioController::class, 'pdf']);

// ---- investimentos
$router->get('/investimentos', [InvestimentosController::class, 'index']);

// ---- idioma
$router->post('/idioma', [IdiomaController::class, 'salvar']);

// ---- ordem das abas do menu (modo organizar)
$router->post('/menu/ordem', [MenuController::class, 'salvarOrdem']);

// ---- wishlist
$router->get('/wishlist', [WishlistController::class, 'index']);
$router->post('/wishlist', [WishlistController::class, 'salvar']);
$router->post('/wishlist/ler-link', [WishlistController::class, 'lerLink']);
$router->get('/wishlist/{id}/foto', [WishlistController::class, 'foto']);
$router->get('/wishlist/{id}/editar', [WishlistController::class, 'editar']);
$router->post('/wishlist/{id}/mover', [WishlistController::class, 'mover']);
$router->post('/wishlist/{id}/comprei', [WishlistController::class, 'comprei']);
$router->post('/wishlist/{id}/devolver', [WishlistController::class, 'devolver']);
$router->post('/wishlist/{id}/excluir', [WishlistController::class, 'excluir']);
$router->post('/wishlist/{id}/restaurar', [WishlistController::class, 'restaurar']);
$router->post('/wishlist/{id}', [WishlistController::class, 'atualizar']);

// ---- ajustes (menu do avatar)
$router->get('/ajustes', [AjustesController::class, 'index']);
$router->post('/ajustes/salvar', [AjustesController::class, 'salvar']);
$router->post('/ajustes/senha', [AjustesController::class, 'trocarSenha']);
$router->post('/ajustes/pessoas', [AjustesController::class, 'criarPessoa']);
$router->post('/ajustes/pessoas/{id}/senha', [AjustesController::class, 'senhaDePessoa']);
$router->post('/ajustes/pessoas/{id}/remover', [AjustesController::class, 'removerPessoa']);
$router->post('/contas', [ContaController::class, 'salvar']);
$router->post('/contas/{id}/arquivar', [ContaController::class, 'arquivar']);
$router->post('/contas/{id}/reativar', [ContaController::class, 'reativar']);
$router->post('/contas/{id}/mover', [ContaController::class, 'mover']);
$router->post('/ajustes/inicio', [AjustesController::class, 'salvarInicio']);
$router->post('/ajustes/regional', [AjustesController::class, 'salvarRegional']);
$router->post('/ajustes/recursos', [AjustesController::class, 'salvarRecursos']);
$router->post('/ajustes/icone', [AjustesController::class, 'salvarIcone']);
$router->post('/ajustes/icone/remover', [AjustesController::class, 'removerIcone']);
$router->get('/icone', [AjustesController::class, 'icone']);
$router->post('/ajustes/aparelhos/sair', [AjustesController::class, 'sairAparelhos']);
$router->get('/ajustes/duas-etapas', [DuasEtapasController::class, 'index']);
$router->post('/ajustes/duas-etapas/ligar', [DuasEtapasController::class, 'ligar']);
$router->get('/ajustes/duas-etapas/codigos', [DuasEtapasController::class, 'codigos']);
$router->post('/ajustes/duas-etapas/desligar', [DuasEtapasController::class, 'desligar']);

// ---- backup, atualização e acesso pela rede
$router->get('/sistema', [SistemaController::class, 'index']);
$router->post('/sistema/backup', [SistemaController::class, 'fazerBackup']);
$router->post('/sistema/exportar', [SistemaController::class, 'exportar']);
$router->post('/sistema/restaurar', [SistemaController::class, 'restaurar']);
$router->post('/sistema/consultar', [SistemaController::class, 'consultar']);
$router->post('/sistema/atualizar', [SistemaController::class, 'atualizar']);
$router->get('/rede', [SistemaController::class, 'rede']);
$router->post('/rede', [SistemaController::class, 'salvarRede']);

return $router;
