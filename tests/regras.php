<?php
/**
 * As regras do app, num banco de demonstração descartável e com o relógio parado (FIN_RELOGIO): o resultado não
 * depende dos dados nem do dia de quem roda. tests/run.php roda este arquivo duas vezes: num dia comum e, com --borda,
 * às 23:50 do último dia do mês, só com a primeira parte (o que olha a data de hoje) e a conferência do site.
 *
 * Este processo cria a pasta temporária e, com FIN_REGRAS_DRIVER=mysql, um banco MySQL vazio; as regras rodam num
 * processo filho, com FIN_DADOS nessa pasta e FIN_RELOGIO. No fim, a pasta e o banco somem, mesmo se o filho morrer.
 * O MySQL é o de FIN_REGRAS_DB_HOST, FIN_REGRAS_DB_USER e FIN_REGRAS_DB_PASS, que precisa poder criar e apagar bancos.
 *
 * Uso: php tests/regras.php [--borda]. Sai com 1 se algo falhar.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Rode pela linha de comando.\n");
}
// um dia comum, no meio do mês; e o último dia do mês, quando faltam minutos para o seguinte
const RELOGIO = '2026-11-22 15:30:00';
const RELOGIO_BORDA = '2026-11-30 23:50:00';
$borda = in_array('--borda', $argv, true);

// ---- este processo: prepara o banco descartável, roda as regras num filho e apaga tudo no fim
if (getenv('FIN_REGRAS_PASTA') === false) {
    require __DIR__ . '/../src/autoload.php';
    $pasta = sys_get_temp_dir() . '/financeiro-regras-' . bin2hex(random_bytes(6));
    if (!@mkdir($pasta, 0700)) {
        echo "FALHA não deu para criar a pasta temporária das regras em " . sys_get_temp_dir() . "\n\n0 ok, 1 falha(s)\n";
        exit(1);
    }
    $admin = null;
    $banco = '';
    $saida = '';
    $fim = 1;
    try {
        if (getenv('FIN_REGRAS_DRIVER') === 'mysql') {
            $host = getenv('FIN_REGRAS_DB_HOST') ?: '127.0.0.1';
            $usuario = getenv('FIN_REGRAS_DB_USER') ?: 'root';
            $senha = (string) getenv('FIN_REGRAS_DB_PASS');
            $admin = new PDO('mysql:host=' . $host . ';charset=utf8mb4', $usuario, $senha, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $banco = 'financeiro_regras_' . bin2hex(random_bytes(4));
            $admin->exec("CREATE DATABASE `{$banco}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
            $admin->exec("USE `{$banco}`");
            // o schema.sql, um comando por vez: ele não tem gatilho nem procedimento, então não usa DELIMITER
            foreach (preg_split('/;\s*\n/', (string) file_get_contents(__DIR__ . '/../database/schema.sql')) ?: [] as $comando) {
                if (trim($comando) !== '') {
                    $admin->exec($comando);
                }
            }
            file_put_contents($pasta . '/config.env', implode("\n", ['APP_ENV=production', 'DB_DRIVER=mysql', 'DB_HOST=' . $host, 'DB_NAME=' . $banco,
                'DB_USER=' . $usuario, 'DB_PASS=' . $senha, 'FIN_MODO=instalador', 'SESSION_LIFETIME=28800', 'MOTOR_V2=1', 'ACESSO_REDE=0', 'CONFIAR_PROXY=0']) . "\n");
        }
        $env = array_filter(getenv(), 'is_string');
        $env['FIN_DADOS'] = $env['FIN_REGRAS_PASTA'] = $pasta;
        $env['FIN_RELOGIO'] = $borda ? RELOGIO_BORDA : RELOGIO;
        $proc = proc_open([...\Financeiro\Atualizacao::comandoPhp(), __FILE__, ...($borda ? ['--borda'] : [])],
            [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $tubos, \Financeiro\Atualizacao::pastaDoProcesso(dirname(__DIR__)), $env);
        if (is_resource($proc)) {
            while (($linha = fgets($tubos[1])) !== false) {
                echo $linha;
                $saida .= $linha;
            }
            fclose($tubos[1]);
            $fim = proc_close($proc);
        }
    } catch (\Throwable $e) {
        $saida .= 'FALHA não deu para preparar o banco das regras — ' . $e->getMessage() . "\n";
        echo $saida;
    } finally {
        if ($admin !== null && $banco !== '') {
            $admin->exec("DROP DATABASE IF EXISTS `{$banco}`");
        }
        \Financeiro\Atualizacao::removerPasta($pasta);
    }
    // o filho morreu antes do resumo: conta o que deu para ver e mais uma falha
    if (!preg_match('/^\d+ ok, \d+ falha\(s\)$/', trim((string) strrchr("\n" . rtrim($saida), "\n")))) {
        $okVistos = preg_match_all('/^ok /m', $saida);
        $falhasVistas = preg_match_all('/^FALHA /m', $saida);
        echo "FALHA as regras pararam antes do fim (saída {$fim})\n\n{$okVistos} ok, " . ($falhasVistas + 1) . " falha(s)\n";
        exit(1);
    }
    exit($fim === 0 ? 0 : 1);
}

// ---- o filho: o banco de demonstração nasce aqui, com o relógio já parado
if (getenv('FIN_DADOS') !== getenv('FIN_REGRAS_PASTA') || !str_starts_with(basename((string) getenv('FIN_REGRAS_PASTA')), 'financeiro-regras-')) {
    echo "Rode por tests/regras.php.\n";
    exit(1);
}
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    // @ continua calando, e a linha de comando do FrankenPHP começa com os cabeçalhos enviados
    if (!(error_reporting() & $no) || (headers_sent() && (str_contains($str, 'headers already sent') || str_contains($str, 'after headers have already been sent')))) {
        return true;
    }
    throw new \ErrorException($str, 0, $no, $file, $line);
});

$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['HTTPS']          = 'on';

define('FIN_SEM_DADOS_INICIAIS', true);   // a primeira execução só cria as tabelas: a demonstração vem logo abaixo
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/conferencia.php';

use Financeiro\Auth;
use Financeiro\Config;
use Financeiro\DuasEtapas;
use Financeiro\Controllers\AjustesController;
use Financeiro\Controllers\CartaoController;
use Financeiro\Controllers\CategoriaController;
use Financeiro\Controllers\DashboardController;
use Financeiro\Controllers\EmprestimoController;
use Financeiro\Controllers\WishlistController;
use Financeiro\Controllers\TemaController;
use Financeiro\Controllers\InvestimentosController;
use Financeiro\Services\Investimentos;
use Financeiro\Temas;
use Financeiro\Pdf\Documento;
use Financeiro\Services\FotoPlano;
use Financeiro\Services\RelatorioDados;
use Financeiro\Controllers\RelatorioController;
use Financeiro\Services\Horizonte;
use Financeiro\Site;
use Financeiro\SiteIcone;
use Financeiro\Sql;
use Financeiro\Idioma;
use Financeiro\Preparo;
use Financeiro\Relogio;
use Financeiro\Controllers\FuturoController;
use Financeiro\Controllers\LancamentosController;
use Financeiro\Controllers\MesController;
use Financeiro\Controllers\MetaController;
use Financeiro\Controllers\MenuController;
use Financeiro\Menu;
use Financeiro\Database;
use Financeiro\Icones;
use Financeiro\Models\AjusteModel;
use Financeiro\Models\CartaoModel;
use Financeiro\Models\CategoriaModel;
use Financeiro\Models\ContaRecorrenteModel;
use Financeiro\Models\LancamentoModel;
use Financeiro\Models\MesModel;
use Financeiro\Models\MetaModel;
use Financeiro\Models\PrevisaoModel;
use Financeiro\Models\UsuarioModel;
use Financeiro\Models\ContaModel;
use Financeiro\Controllers\TransferenciaController;
use Financeiro\Controllers\AcertoController;
use Financeiro\Services\CartaoVisual;
use Financeiro\Services\EmprestimoSimulacao;
use Financeiro\Services\Cotacao;
use Financeiro\Services\LinkProduto;
use Financeiro\Services\WishlistPrevisao;
use Financeiro\Models\WishlistModel;
use Financeiro\Services\AnualResumo;
use Financeiro\Services\CartaoFatura;
use Financeiro\Services\GastoPorCategoria;
use Financeiro\Services\GastosMensais;
use Financeiro\Services\ChecklistContas;
use Financeiro\Services\LancarForm;
use Financeiro\Services\MotorFinanceiro;
use Financeiro\Services\PrevisaoEdicao;
use Financeiro\Services\PrevisaoLote;
use Financeiro\Services\MetaRecorrencia;

$ok = 0;
$falhas = 0;
$check = static function (string $nome, bool $cond, string $detalhe = '') use (&$ok, &$falhas): void {
    if ($cond) {
        $ok++;
        echo "ok    {$nome}\n";
    } else {
        $falhas++;
        echo "FALHA {$nome}" . ($detalhe !== '' ? " — {$detalhe}" : '') . "\n";
    }
};

// a demonstração de database/instalar.php --demo, num banco que acabou de nascer (nunca num que já tem dados)
$pdo = Database::connection();
if ((int) $pdo->query('SELECT COUNT(*) FROM categorias')->fetchColumn() > 0) {
    echo "O banco das regras precisa nascer vazio.\n";
    exit(1);
}
Preparo::preparar($pdo, true);
MotorFinanceiro::limparCache();

// ---- sessão simulada (primeiro usuário), em português e em reais
$uid = (int) $pdo->query('SELECT MIN(id) FROM usuarios')->fetchColumn();
$_SESSION['usuario_id']    = $uid;
$_SESSION['usuario_nome']  = 'teste';
$_SESSION['sessao_versao'] = (int) UsuarioModel::sessaoVersao($uid);
Idioma::usar('pt');
Site::usarMoeda('BRL');

// o relógio do app e o do banco estão parados na hora das regras
$hoje = Relogio::hoje();
$mesHoje = Relogio::mes();
$relogio = $borda ? RELOGIO_BORDA : RELOGIO;
$check('relógio: o app e o banco param na hora das regras', Relogio::agora()->format('Y-m-d H:i:s') === $relogio
    && (string) $pdo->query('SELECT ' . Sql::agora())->fetchColumn() === $relogio && (string) $pdo->query('SELECT ' . Sql::hoje())->fetchColumn() === substr($relogio, 0, 10));

$pagina = static function (string $cls, string $metodo, string $path): string {
    $_SERVER['REQUEST_URI'] = $path;
    $_GET = [];
    ob_start();
    (new $cls())->$metodo();
    return (string) ob_get_clean();
};

// ==== primeira parte: o que olha a data de hoje (telas, meses, previsão, fechar, cartão). Roda nas duas passadas.

// ---- 3b. textos da auditoria: rótulos que não enganam
$htmlIni = $pagina(DashboardController::class, 'index', '/');
$htmlAno = $pagina(FuturoController::class, 'index', '/futuro');
$htmlMeta = $pagina(MetaController::class, 'index', '/meta');
$check('Metas não chama o disponível de renda', !str_contains($htmlMeta, 'Renda do mês') && str_contains($htmlMeta, 'Para usar neste mês'));
$check('Anual não diz "acima da reserva em todos os meses"', !str_contains($htmlAno, 'acima da reserva em todos'));
$check('Início sem o selo "Acima da reserva"', !str_contains($htmlIni, 'Acima da reserva'));

// ---- 3d. investimentos: vêm dos lançamentos e não são renda nem gasto
$catAplic = $pdo->query("SELECT id, operacional FROM categorias WHERE nome = 'Aplicar em investimentos'")->fetch(PDO::FETCH_ASSOC);
$check('investimentos: a categoria de aplicar existe e não é renda nem gasto', $catAplic !== false && (int) $catAplic['operacional'] === 0);
MotorFinanceiro::limparCache();
$antesInv = Investimentos::resumo($hoje);
$linhaAntes = MotorFinanceiro::linhaDoMes($mesHoje);
$saldoAntes = LancamentoModel::saldoCalculado(AjusteModel::valor('saldo_ini'), $hoje);
$pdo->beginTransaction();
$pdo->prepare('INSERT INTO lancamentos (data, descricao, categoria_id, valor, usuario_id) VALUES (?, ?, ?, 700, ?)')->execute([$hoje, 'teste', (int) $catAplic['id'], $uid]);
MotorFinanceiro::limparCache();
$depoisInv = Investimentos::resumo($hoje);
$linhaDepois = MotorFinanceiro::linhaDoMes($mesHoje);
$saldoDepois = LancamentoModel::saldoCalculado(AjusteModel::valor('saldo_ini'), $hoje);
$htmlInv = $pagina(InvestimentosController::class, 'index', '/investimentos');
$pdo->rollBack();
MotorFinanceiro::limparCache();
$check('investimentos: o lançamento soma em outros investimentos e no total', abs($depoisInv['outros']['saldo'] - $antesInv['outros']['saldo'] - 700) < 0.01 && abs($depoisInv['total'] - $antesInv['total'] - 700) < 0.01 && abs($depoisInv['poupanca']['saldo'] - $antesInv['poupanca']['saldo']) < 0.01);
$check('investimentos: o total é a resposta e as posições ficam numa tabela, sem cartão', str_contains($htmlInv, 'class="faixa-grade inv-total"') && str_contains($htmlInv, 'class="livro inv-tabela"')
    && str_contains($htmlInv, 'class="total-final"') && !str_contains($htmlInv, 'class="saldo ') && !str_contains($htmlInv, 'card inv-parte'));
$check('investimentos: o dinheiro sai da conta', abs($saldoAntes - $saldoDepois - 700) < 0.01);
$check('investimentos: não muda entradas nem gastos do plano', abs($linhaDepois['entra'] - $linhaAntes['entra']) < 0.01 && abs($linhaDepois['sai'] - $linhaAntes['sai']) < 0.01);
$check('investimentos: o plano registra a aplicação', abs($linhaDepois['invest_liquido'] - $linhaAntes['invest_liquido'] - 700) < 0.01 && abs($linhaDepois['invest_saldo'] - $linhaAntes['invest_saldo'] - 700) < 0.01);
// o plano adianta menos o empréstimo quando há menos dinheiro, então a conferência é direta na fórmula do balanço
[, $saiuSem] = MotorFinanceiro::balancoTotal(['invest_liquido' => 0.0] + $linhaAntes);
[, $saiuCom] = MotorFinanceiro::balancoTotal(['invest_liquido' => 700.0] + $linhaAntes);
$check('investimentos: entra no balanço', abs($saiuCom - $saiuSem - 700) < 0.01);
$check('investimentos: a tela mostra as duas partes', str_contains($htmlInv, 'Outros investimentos') && str_contains($htmlInv, 'Guardado e investido') && str_contains($htmlInv, 'teste'));

// ---- 3f. meses sem fim, início em data real e recursos opcionais
$pdo->beginTransaction();
$longe = date('Y-m-d', strtotime($mesHoje . ' +10 year'));
$ultAntes = (string) MesModel::ultimo();
$catFixo = (int) $pdo->query("SELECT id FROM categorias WHERE grupo = 'Fixos' AND ativo = 1 ORDER BY id LIMIT 1")->fetchColumn();
$catAno = (int) $pdo->query("SELECT id FROM categorias WHERE grupo = 'Ano' AND ativo = 1 ORDER BY id LIMIT 1")->fetchColumn();
PrevisaoModel::setValor($catFixo, $ultAntes, 123.45);
$mesAnoT = date('Y-m-01', strtotime($ultAntes . ' -6 month'));
PrevisaoModel::setValor($catAno, $mesAnoT, 777.00);
$tempoIni = microtime(true);
Horizonte::garantir($longe);
$tempoHorizonte = microtime(true) - $tempoIni;
$alvoT = date('Y-m-01', strtotime(date('Y-m-01', strtotime($longe)) . ' +' . Horizonte::MESES_A_FRENTE . ' month'));
$check('meses: o horizonte anda sozinho e não deixa buracos', MesModel::ultimo() === $alvoT && (int) $pdo->query('SELECT COUNT(*) FROM meses')->fetchColumn() === (static function () use ($pdo): int {
    [$a, $b] = array_values($pdo->query('SELECT MIN(mes) AS a, MAX(mes) AS b FROM meses')->fetch());
    return ((int) substr((string) $b, 0, 4) - (int) substr((string) $a, 0, 4)) * 12 + (int) substr((string) $b, 5, 2) - (int) substr((string) $a, 5, 2) + 1;
})());
$check('meses: conta fixa continua nos meses novos', abs(PrevisaoModel::valor($catFixo, date('Y-m-01', strtotime($ultAntes . ' +1 month'))) - 123.45) < 0.01 && abs(PrevisaoModel::valor($catFixo, $alvoT) - 123.45) < 0.01);
$check('meses: conta pontual repete no mesmo mês do ano seguinte, e só nele', abs(PrevisaoModel::valor($catAno, date('Y-m-01', strtotime($mesAnoT . ' +1 year'))) - 777) < 0.01 && PrevisaoModel::valor($catAno, date('Y-m-01', strtotime($mesAnoT . ' +13 month'))) < 0.01);
Horizonte::garantir($longe);
$check('meses: rodar de novo não muda nada', MesModel::ultimo() === $alvoT);
MotorFinanceiro::limparCache();
$tempoIni = microtime(true);
$planoLongo = MotorFinanceiro::calcularPlano();
$tempoPlano = microtime(true) - $tempoIni;
$check('meses: o plano com mais de 10 anos continua rápido', $planoLongo !== [] && $tempoPlano < 1.0, sprintf('%.3f s em %d meses', $tempoPlano, count($planoLongo)));
$check('meses: lançamento num mês novo é aceito', LancamentosController::validarData(date('Y-m-d', strtotime($longe))) === null);
$pdo->rollBack();
MotorFinanceiro::limparCache();

$iniT = (string) AjusteModel::dataInicio();
$check('início: é uma data de verdade', $iniT !== '' && $iniT <= $hoje && MesModel::primeiroDia() === $iniT);
$check('início: lançamento antes dele é recusado', LancamentosController::validarData(date('Y-m-d', strtotime($iniT . ' -1 day'))) !== null);
$check('início: não pode ser no futuro', Horizonte::problemaNaData(date('Y-m-d', strtotime($hoje . ' +1 day'))) !== null);
$check('início: não passa por cima de lançamentos', Horizonte::problemaNaData($hoje) !== null || (string) $pdo->query('SELECT MIN(data) FROM lancamentos')->fetchColumn() === $hoje);
$dadosT = MotorFinanceiro::dados();
$mesMetaT = null;
foreach ($dadosT['meses'] as $m) {
    if ($m['situacao'] !== 'REAL' && $m['mes'] > $mesHoje) { $mesMetaT = $m['mes']; break; }
}
$pdo->beginTransaction();
$catMetaT = (int) $pdo->query("SELECT id FROM categorias WHERE grupo = 'Dia a dia' AND ativo = 1 ORDER BY id LIMIT 1")->fetchColumn();
$pdo->prepare('DELETE FROM previsao WHERE categoria_id = ?')->execute([$catMetaT]);
$pdo->prepare('REPLACE INTO metas (categoria_id, valor) VALUES (?, 300)')->execute([$catMetaT]);
AjusteModel::setValor('data_inicio', (float) date('Ymd', strtotime($mesMetaT . ' +14 day')));
MotorFinanceiro::limparCache();
$dadosParcial = MotorFinanceiro::dados();
$fracaoT = (diasNoMes($mesMetaT) - 15 + 1) / diasNoMes($mesMetaT);
$check('início no meio do mês: a meta conta só pelos dias que faltam', abs(($dadosParcial['prev'][$mesMetaT][$catMetaT] ?? 0) - round(300 * $fracaoT, 2)) < 0.01);
$check('início no dia 1: nada muda nos outros meses', abs(($dadosParcial['prev'][date('Y-m-01', strtotime($mesMetaT . ' +1 month'))][$catMetaT] ?? 0) - 300) < 0.01);
$pdo->rollBack();
MotorFinanceiro::limparCache();

$comParcela = $dadosT;
$comParcela['aj']['parcela_mensal'] = 200.0; $comParcela['aj']['n_parcelas'] = 12.0; $comParcela['aj']['juros_mes'] = 0.01; $comParcela['aj']['saldo_devedor'] = 0.0;
$comParcela['loanIni'] = AjusteModel::devedorPor(200.0, 12.0, 0.01);
$noSalario = $comParcela; $noSalario['aj']['parcela_modo'] = 1.0;
$aParte = $comParcela; $aParte['aj']['parcela_modo'] = 2.0;
$planoS = MotorFinanceiro::calcular($noSalario, ['objetivo' => 3]);
$planoP = MotorFinanceiro::calcular($aParte, ['objetivo' => 3]);
$difCaixa = 0.0;
foreach ($planoS as $m => $l) {
    $difCaixa = max($difCaixa, abs($l['fica_na_conta'] - $planoP[$m]['fica_na_conta']), abs($l['falta_pagar'] - $planoP[$m]['falta_pagar']));
}
$linhaP = $planoP[$mesMetaT]; $linhaS = $planoS[$mesMetaT];
$check('parcela à parte: a conta e a dívida ficam iguais, sem mexer no salário', $difCaixa < 0.01 && $linhaP['parcela_descontada'] == 0.0 && $linhaP['parcela_parte'] > 0 && abs($linhaP['entra'] - $linhaS['entra_bruto']) < 0.01 && abs(MotorFinanceiro::balancoTotal($linhaP)[2] - MotorFinanceiro::balancoTotal($linhaS)[2]) < 0.01);
$pdo->beginTransaction();
AjusteModel::setValor('usa_emprestimo', 0.0);
MotorFinanceiro::limparCache();
$semEmp = MotorFinanceiro::calcularPlano();
$check('sem empréstimo: nenhuma dívida nem parcela no plano', max(array_map(static fn($l) => abs($l['falta_pagar']) + $l['parcela_descontada'] + $l['parcela_parte'], $semEmp)) < 0.01);
$check('sem empréstimo: a aba sai do menu', !str_contains($pagina(MetaController::class, 'index', '/meta'), 'href="/emprestimo"'));
AjusteModel::setValor('usa_cartao', 0.0);
$check('sem cartão: a aba sai do menu e do Mais, e a barra do celular não muda', (static function (string $h): bool {
    preg_match('#<nav class="tabbar".*?<details#s', $h, $b);
    preg_match_all('#href="([^"?]+)#', $b[0] ?? '', $hb);
    return !str_contains($h, 'href="/cartao"') && $hb[1] === ['/', '/lancamentos', '/lancar', '/mes'];
})($pagina(MetaController::class, 'index', '/meta')));
$pdo->rollBack();
MotorFinanceiro::limparCache();

// ---- 3g. relatório: cenários, sem empréstimo, foto a tempo, a tela
$relHoje = RelatorioDados::mensal($mesHoje);
[$cBase, $cGasto, $cRenda] = $relHoje['previsao']['cenarios'];
$check('relatório: cenário pior nunca parece melhor', end($cGasto['linha']) <= end($cBase['linha']) + 0.01 && end($cRenda['linha']) <= end($cBase['linha']) + 0.01);
$pdo->beginTransaction();
AjusteModel::setValor('usa_emprestimo', 0.0);
MotorFinanceiro::limparCache();
$semD = RelatorioDados::mensal($mesHoje);
$check('relatório: sem empréstimo, nada de terminar de pagar', $semD !== null && ($semD['previsao']['cenarios'] === [] || $semD['previsao']['cenarios'][0]['quita'] === null) && $semD['previsao']['quita'] === null && $semD['divida'] === null);
$pdo->rollBack();
MotorFinanceiro::limparCache();
$pdo->beginTransaction();
$pdo->exec('DELETE FROM plano_fotos');
FotoPlano::garantir(substr($mesHoje, 0, 8) . '03');
$fotoCedo = FotoPlano::valida($mesHoje);
$check('foto do plano: a do começo do mês vale', $fotoCedo !== null && isset($fotoCedo['meses'][$mesHoje]['fica']));
$qtd = (int) $pdo->query('SELECT COUNT(*) FROM plano_fotos')->fetchColumn();
FotoPlano::garantir(substr($mesHoje, 0, 8) . '04');
$check('foto do plano: uma só por mês', (int) $pdo->query('SELECT COUNT(*) FROM plano_fotos')->fetchColumn() === $qtd);
$pdo->exec('DELETE FROM plano_fotos');
FotoPlano::garantir(substr($mesHoje, 0, 8) . '20');
$check('foto do plano: tirada tarde não entra no placar', FotoPlano::valida($mesHoje) === null);
$pdo->rollBack();
$nomesR = $pdo->query('SELECT nome, login FROM usuarios')->fetchAll(PDO::FETCH_ASSOC);
$jsonR = json_encode(RelatorioDados::mensal($mesHoje), JSON_UNESCAPED_UNICODE);
$vazou = array_filter($nomesR, static fn($u) => str_contains((string) $jsonR, ' ' . $u['nome'] . ' '));
$check('relatório: não traz nome de quem usa', $vazou === []);
$htmlR = $pagina(RelatorioController::class, 'index', '/relatorio');
// § 7.3 do redesenho: a frase do topo não chama de falta um mês em que a casa guardou dinheiro
$frGuardou = RelatorioDados::manchete(-195.94, true, 0.0, 500.0);
$frSobra = RelatorioDados::manchete(104.06, true, 0.0, 500.0);
$frFalta = RelatorioDados::manchete(-80.0, true, 0.0, 0.0);
$frFaltaGuardando = RelatorioDados::manchete(-700.0, true, 0.0, 500.0);
$check('relatório: guardar não vira "Faltaram" na frase do topo', !str_contains($frGuardou, 'Faltaram') && str_contains($frGuardou, 'Guardou ' . money(500.0))
    && str_contains($frGuardou, money(195.94)) && $frSobra === 'Guardou ' . money(500.0) . ' e ainda sobraram ' . money(104.06) . '.'
    && $frFalta === 'Faltaram ' . money(80.0) . '.' && str_contains($frFaltaGuardando, 'Faltaram ' . money(200.0)), json_encode([$frGuardou, $frSobra, $frFalta, $frFaltaGuardando], JSON_UNESCAPED_UNICODE));
$check('relatório: na cascata, o guardado é neutro e o gasto tem nome próprio ("Gastou")', str_contains($htmlR, 'is-guardado') === str_contains($htmlR, 'Guardou na poupança')
    && !str_contains($htmlR, '>Saiu<') && (str_contains($htmlR, '>Gastou<') || str_contains($htmlR, '>Gastou até agora<') || !str_contains($htmlR, 'rl-cascata')));
$check('relatório: a tela mostra a capa e o botão do PDF', str_contains($htmlR, 'rl-capa') && str_contains($htmlR, '/relatorio/pdf?') && str_contains($htmlR, 'Baixar PDF'));
$_GET = ['tipo' => 'anual'];
ob_start();
(new RelatorioController())->index();
$htmlRA = (string) ob_get_clean();
$_GET = [];
$check('relatório: a escolha anual funciona', str_contains($htmlRA, 'aria-current="page">Anual'));

// ---- 4. meta: definir uma meta pinta a barra no mês (verde/vermelho), sem sujar o banco
$mesMeta = null;
$catMeta = null;
$gastoMeta = 0.0;
foreach (MesModel::todos() as $m) {
    if ($m['situacao'] !== 'PREVISAO') {
        continue;
    }
    $fimM = date('Y-m-t', strtotime((string) $m['mes']));
    $st = $pdo->prepare(
        "SELECT l.categoria_id, SUM(l.valor) tot FROM lancamentos l JOIN categorias c ON c.id = l.categoria_id
         WHERE c.grupo = 'Dia a dia' AND c.sinal = -1 AND l.data >= :a AND l.data <= :b
         GROUP BY l.categoria_id ORDER BY tot DESC LIMIT 1"
    );
    $st->execute(['a' => (string) $m['mes'], 'b' => $fimM]);
    $row = $st->fetch();
    if ($row) {
        $mesMeta = (string) $m['mes'];
        $catMeta = (int) $row['categoria_id'];
        $gastoMeta = (float) $row['tot'];
        break;
    }
}
if ($mesMeta === null) {
    $check('meta pinta a barra no mês', false, 'a demonstração ficou sem gasto de dia a dia num mês aberto');
} else {
    $pdo->beginTransaction();
    MetaModel::definir($catMeta, round($gastoMeta / 2, 2));        // meta abaixo do gasto → fora da meta
    MotorFinanceiro::limparCache();
    $_SERVER['REQUEST_URI'] = '/mes?mes=' . $mesMeta;
    $_GET = ['mes' => $mesMeta];
    ob_start();
    try {
        (new MesController())->index();
        $html = (string) ob_get_clean();
        $check('meta pinta a barra no mês', str_contains($html, '>passou ') && str_contains($html, 'fora-meta') && str_contains($html, 'meta-barra passou'),
            'sem "passou" no HTML');
    } catch (\Throwable $e) {
        ob_end_clean();
        $check('meta pinta a barra no mês', false, get_class($e) . ': ' . $e->getMessage());
    }
    $pdo->rollBack();
    MotorFinanceiro::limparCache();
}

// ---- 5-9: previsão — a meta é a previsão padrão de todo mês; o valor do mês é exceção
$prevMeses = array_values(array_map(static fn($m) => (string) $m['mes'],
    array_filter(MesModel::todos(), static fn($m) => $m['situacao'] === 'PREVISAO')));
$realMes = null;
foreach (MesModel::todos() as $m) {
    if ($m['situacao'] === 'REAL') {
        $realMes = (string) $m['mes'];
        break;
    }
}
$catDia = (int) $pdo->query(
    "SELECT id FROM categorias WHERE grupo = 'Dia a dia' AND ativo = 1 AND operacional = 1 AND sinal = -1 ORDER BY id LIMIT 1"
)->fetchColumn();
$catMov = (int) $pdo->query('SELECT id FROM categorias WHERE operacional = 0 ORDER BY id LIMIT 1')->fetchColumn();
$linhaCrua = static function (int $c, string $m) use ($pdo): ?float {
    $st = $pdo->prepare('SELECT valor FROM previsao WHERE categoria_id = :c AND mes = :m');
    $st->execute(['c' => $c, 'm' => $m]);
    $v = $st->fetchColumn();
    return $v === false ? null : (float) $v;
};
$emTransacao = static function (string $nome, callable $corpo) use ($pdo, $check): void {
    $pdo->beginTransaction();
    MotorFinanceiro::limparCache();
    try {
        $corpo();
    } catch (\Throwable $e) {
        $check($nome, false, get_class($e) . ': ' . $e->getMessage() . ' em ' . basename($e->getFile()) . ':' . $e->getLine());
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        MotorFinanceiro::limparCache();
    }
};

if (count($prevMeses) < 2 || $catDia === 0) {
    $check('previsão efetiva', false, 'a demonstração ficou sem 2 meses em PREVISAO ou sem categoria de dia a dia');
} else {
    [$m1, $m2] = [$prevMeses[count($prevMeses) - 2], $prevMeses[count($prevMeses) - 1]];

    // 5. a meta vale onde o mês não tem linha; a linha do mês vence; categoria arquivada não herda meta
    $emTransacao('previsão efetiva', static function () use ($pdo, $check, $catDia, $m1, $m2): void {
        $pdo->prepare('DELETE FROM previsao WHERE categoria_id = :c AND mes IN (:a, :b)')->execute(['c' => $catDia, 'a' => $m1, 'b' => $m2]);
        MetaModel::definir($catDia, 123.45);
        MotorFinanceiro::limparCache();
        $d = MotorFinanceiro::dados();
        $check('meta vira a previsão do mês sem linha', abs(PrevisaoModel::valor($catDia, $m1) - 123.45) < 0.001
            && abs(($d['prev'][$m1][$catDia] ?? 0.0) - 123.45) < 0.001 && empty($d['excecao'][$m1][$catDia])
            && abs(($d['metas'][$catDia] ?? 0.0) - 123.45) < 0.001);
        PrevisaoModel::setValor($catDia, $m1, 50.0);
        MotorFinanceiro::limparCache();
        $d = MotorFinanceiro::dados();
        $check('valor do mês vence a meta (exceção)', abs(PrevisaoModel::valor($catDia, $m1) - 50.0) < 0.001
            && !empty($d['excecao'][$m1][$catDia]) && abs(PrevisaoModel::valor($catDia, $m2) - 123.45) < 0.001);
        $pdo->prepare('UPDATE categorias SET ativo = 0 WHERE id = :c')->execute(['c' => $catDia]);
        MotorFinanceiro::limparCache();
        $check('categoria arquivada não herda a meta', PrevisaoModel::valor($catDia, $m2) == 0.0
            && !isset(MotorFinanceiro::dados()['metas'][$catDia]));
    });

    // 6. PrevisaoLote: célula null apaga a linha (volta à meta) e o desfazer recria; nada muda → null; REAL recusa
    $emTransacao('PrevisaoLote com célula null', static function () use ($pdo, $check, $catDia, $m1, $m2, $uid, $realMes, $linhaCrua): void {
        $pdo->prepare('DELETE FROM previsao WHERE categoria_id = :c AND mes IN (:a, :b)')->execute(['c' => $catDia, 'a' => $m1, 'b' => $m2]);
        MetaModel::definir($catDia, 200.0);
        PrevisaoModel::setValor($catDia, $m1, 80.0);
        $lote = PrevisaoLote::gravar('manual', [[$catDia, $m1, null]], $uid);
        $st = $pdo->prepare('SELECT antes, depois FROM previsao_log WHERE lote = :l');
        $st->execute(['l' => (string) $lote]);
        $log = $st->fetch();
        $check('voltar à meta apaga a linha e loga depois NULL', $lote !== null && $linhaCrua($catDia, $m1) === null
            && abs(PrevisaoModel::valor($catDia, $m1) - 200.0) < 0.001 && $log && $log['depois'] === null && abs((float) $log['antes'] - 80.0) < 0.001);
        $r = PrevisaoLote::desfazer((string) $lote, $uid);
        $check('desfazer recria a linha do mês', $r['restauradas'] === 1 && abs((float) $linhaCrua($catDia, $m1) - 80.0) < 0.001);
        $check('mesmo valor: nada a gravar', PrevisaoLote::gravar('manual', [[$catDia, $m1, 80.0]], $uid) === null);
        $l2 = PrevisaoLote::gravar('manual', [[$catDia, $m2, 33.0]], $uid);
        $r2 = PrevisaoLote::desfazer((string) $l2, $uid);
        $check('desfazer de linha nova apaga a linha (volta à meta)', $r2['restauradas'] === 1 && $linhaCrua($catDia, $m2) === null);
        $check('sem linha e null: nada a gravar', PrevisaoLote::gravar('manual', [[$catDia, $m2, null]], $uid) === null);
        $recusou = false;
        try {
            PrevisaoLote::gravar('manual', [[$catDia, $realMes, 1.0]], $uid);
        } catch (\InvalidArgumentException) {
            $recusou = true;
        }
        $check('PrevisaoLote recusa mês fechado', $recusou);
    });

    // 7. PrevisaoEdicao::celulas (aritmética pura)
    $d = MotorFinanceiro::dados();
    $todos = PrevisaoEdicao::celulas($d, $catDia, $m1, 'salvar', 10.0, true);
    $mesesCel = array_map(static fn($c) => $c[1], $todos);
    $check('propagar: este mês e os PREVISAO seguintes, nunca fechado', $mesesCel === array_values(array_filter($prevMeses, static fn($m) => $m >= $m1))
        && array_sum(array_map(static fn($c) => (float) $c[2], $todos)) == 10.0 * count($todos));
    $check('sem propagar: só o mês', PrevisaoEdicao::celulas($d, $catDia, $m1, 'salvar', 10.0, false) === [[$catDia, $m1, 10.0]]);
    $dMeta = $d;
    $dMeta['metas'][$catDia] = 77.0;
    $check('salvar o valor da meta = seguir a meta (null)', PrevisaoEdicao::celulas($dMeta, $catDia, $m1, 'salvar', 77.0, false) === [[$catDia, $m1, null]]);
    $check('tirar = 0 no mês', PrevisaoEdicao::celulas($d, $catDia, $m1, 'tirar', null, false) === [[$catDia, $m1, 0.0]]);
    $erroDe = static function (callable $f): string {
        try {
            $f();
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }
        return '';
    };
    $dSemMeta = $d;
    unset($dSemMeta['metas'][$catDia]);
    $check('voltar à meta sem meta: recusa', $erroDe(static fn() => PrevisaoEdicao::celulas($dSemMeta, $catDia, $m1, 'meta', null, false)) === 'sem meta');
    $check('valor negativo ou vazio: recusa', $erroDe(static fn() => PrevisaoEdicao::celulas($d, $catDia, $m1, 'salvar', -1.0, false)) === PrevisaoEdicao::ERRO_VALOR
        && $erroDe(static fn() => PrevisaoEdicao::celulas($d, $catDia, $m1, 'salvar', null, false)) === PrevisaoEdicao::ERRO_VALOR);
    $check('mês fechado: recusa', str_contains($erroDe(static fn() => PrevisaoEdicao::celulas($d, $catDia, $realMes, 'salvar', 1.0, false)), 'fechado'));
    $check('empréstimo/poupança não se edita na Mês', $erroDe(static fn() => PrevisaoEdicao::celulas($d, $catMov, $m1, 'salvar', 1.0, false)) === 'Escolha uma categoria.');

    // 8. alerta de reserva/vermelho depois de uma mudança
    $pa = [$m1 => ['estado' => 'previsto', 'fica_na_conta' => 3500.0], $m2 => ['estado' => 'previsto', 'fica_na_conta' => 100.0]];
    $pb = [$m1 => ['estado' => 'previsto', 'fica_na_conta' => 2900.0], $m2 => ['estado' => 'previsto', 'fica_na_conta' => -5.0]];
    $check('piorou: primeiro mês que furou a reserva', PrevisaoEdicao::piorou($pa, $pb, $m1, 3000.0) === ['mes' => $m1, 'tipo' => 'reserva']
        && PrevisaoEdicao::piorou($pa, $pb, $m2, 3000.0) === ['mes' => $m2, 'tipo' => 'vermelho']
        && PrevisaoEdicao::piorou($pa, $pa, $m1, 3000.0) === null);
}

// ---- 9. Mês: editável em PREVISAO (form, fita, incluir); só leitura quando fechado; anual leva ao mês
$render = static function (string $cls, string $metodo, array $get) use ($check): string {
    $_SERVER['REQUEST_URI'] = '/x?' . http_build_query($get);
    $_GET = $get;
    ob_start();
    try {
        (new $cls())->$metodo();
        return (string) ob_get_clean();
    } catch (\Throwable $e) {
        ob_end_clean();
        $check("render {$cls}::{$metodo}", false, get_class($e) . ': ' . $e->getMessage());
        return '';
    }
};
$html = $render(MesController::class, 'index', ['mes' => end($prevMeses)]);
$check('último mês: linhas editáveis + seletor de meses, sem "meses seguintes"', str_contains($html, 'action="/mes/previsao"')
    && str_contains($html, 'class="mes-linha mes-ed"') && str_contains($html, 'data-mes-picker') && !str_contains($html, 'name="propagar"'));
$html = $render(MesController::class, 'index', ['mes' => $prevMeses[0]]);
$check('Mês aberto: editável com "também nos meses seguintes"', str_contains($html, 'action="/mes/previsao"') && str_contains($html, 'name="propagar"'));
$html = $render(MesController::class, 'index', ['mes' => $realMes]);
$check('Mês fechado: só leitura, com o planejado e a diferença', str_contains($html, 'class="livro mes-livro is-fechado"') && str_contains($html, '>Diferença<')
    && !str_contains($html, 'action="/mes/previsao"'));
$htmlFuM = $render(FuturoController::class, 'index', []);
$check('anual: cada mês leva à aba Mês, por um ícone com nome ("Ver e ajustar" não quebra em duas linhas)', str_contains($htmlFuM, 'class="sim-abrir" href="/mes?mes=')
    && preg_match('#class="sim-abrir" href="/mes\?mes=[\d-]+" aria-label="Ver e ajustar [^"]+" title=#', $htmlFuM) === 1);
// "Outras entradas sobe": o texto do leitor de tela não depende de o nome concordar com o verbo
$check('anual: o que mudou, sem "sobe"/"cai" colado no nome', !preg_match('#<span class="sr-only">[^<]* (sobe|cai) [^<]*</span>#u', $htmlFuM));
$check('meta: cada categoria abre o editor com o mês e até quando', str_contains($render(MetaController::class, 'index', []), 'name="ate"'));

// ---- 10. fechar congela as metas do mês; mês fechado não segue a meta; reabrir descongela
$mC = $prevMeses[0];
$emTransacao('congelar ao fechar', static function () use ($pdo, $check, $catDia, $mC, $uid, $linhaCrua): void {
    $pdo->prepare('DELETE FROM previsao WHERE categoria_id = :c AND mes = :m')->execute(['c' => $catDia, 'm' => $mC]);
    MetaModel::definir($catDia, 222.0);
    MotorFinanceiro::limparCache();
    // numa conta nova, o primeiro mês é parcial: a meta vale proporcional aos dias que restam
    $metaDoMes = (float) (MotorFinanceiro::dados()['prev'][$mC][$catDia] ?? 222.0);
    $lote = PrevisaoLote::congelar($mC, $uid);
    $check('congelar grava a meta como linha do mês', $lote !== null && abs((float) $linhaCrua($catDia, $mC) - $metaDoMes) < 0.001);
    $ult = PrevisaoLote::ultimoDesfazivel();
    $check('congelamento fica fora do "desfazer última alteração"', $ult === null || $ult['lote'] !== $lote);
    MesModel::setSituacao($mC, 'REAL');
    MetaModel::definir($catDia, 333.0);
    MotorFinanceiro::limparCache();
    $check('mês fechado não muda quando a meta muda', abs(PrevisaoModel::valor($catDia, $mC) - $metaDoMes) < 0.001);
    MesModel::setSituacao($mC, 'PREVISAO');
    PrevisaoLote::descongelar($mC, $uid);
    MotorFinanceiro::limparCache();
    $check('reabrir descongela: o mês volta a seguir a meta', $linhaCrua($catDia, $mC) === null
        && abs(PrevisaoModel::valor($catDia, $mC) - 333.0) < 0.001);
    MesModel::setSituacao($mC, 'REAL');
    MotorFinanceiro::limparCache();
    $check('mês fechado sem linha: a meta não vale', PrevisaoModel::valor($catDia, $mC) == 0.0);
});

// ---- 10b. Metas com recorrência: valor a partir de um mês, até quando, também em mês fechado; tudo num lote com desfazer
$realUlt = null;
foreach (MesModel::todos() as $m) {
    if ($m['situacao'] === 'REAL') {
        $realUlt = (string) $m['mes'];
    }
}
if (count($prevMeses) < 5 || $realUlt === null || $catDia === 0) {
    $check('metas com recorrência', false, 'a demonstração ficou sem 5 meses em PREVISAO, sem mês fechado ou sem categoria de dia a dia');
} else {
    [$mA, $mB, $mC2, $mD, $mE] = array_slice($prevMeses, 0, 5);   // nov, dez, jan, fev, mar
    $gravarPlano = static function (array $plano) use ($uid): ?string {
        return PrevisaoLote::gravar('propagar', $plano['celulas'], $uid, null, true, $plano['meta']);
    };
    $valorEm = static fn(int $c, string $m): float => round(PrevisaoModel::valor($c, $m), 2);
    // a meta 1.500 e os valores próprios de novembro (800) e dezembro (1.200), como o dono tinha
    $preparar = static function () use ($pdo, $catDia, $mA, $mB): void {
        $pdo->prepare('DELETE FROM previsao WHERE categoria_id = :c AND mes > :m')->execute(['c' => $catDia, 'm' => $mA]);
        MetaModel::definir($catDia, 1500.0);
        PrevisaoModel::setValor($catDia, $mA, 800.0);
        PrevisaoModel::setValor($catDia, $mB, 1200.0);
        MotorFinanceiro::limparCache();
    };

    $emTransacao('metas: sem fim a partir de novembro', static function () use ($check, $catDia, $mA, $mB, $mC2, $uid, $preparar, $gravarPlano, $valorEm, $linhaCrua): void {
        $preparar();
        $lote = $gravarPlano(MetaRecorrencia::plano(MotorFinanceiro::dados(), $catDia, 1000.0, $mA, null));
        MotorFinanceiro::limparCache();
        $check('metas: novembro com valor próprio e "sem fim a partir de novembro" muda novembro', $lote !== null
            && $valorEm($catDia, $mA) === 1000.0 && $linhaCrua($catDia, $mA) === null);
        $check('metas: sem fim vira a meta e apaga os valores próprios dos meses seguintes', $valorEm($catDia, $mB) === 1000.0
            && $valorEm($catDia, $mC2) === 1000.0 && abs((float) MetaModel::valor($catDia) - 1000.0) < 0.001);
        $r = PrevisaoLote::desfazer((string) $lote, $uid);
        MotorFinanceiro::limparCache();
        $check('metas: desfazer volta tudo, a meta junto', $r['restauradas'] === $r['total'] && $r['total'] === 3
            && $valorEm($catDia, $mA) === 800.0 && $valorEm($catDia, $mB) === 1200.0 && $valorEm($catDia, $mC2) === 1500.0
            && abs((float) MetaModel::valor($catDia) - 1500.0) < 0.001, json_encode($r));
        $check('metas: o desfazer não deixa a meta antiga guardada', \Financeiro\Models\AjusteModel::valor(PrevisaoLote::CHAVE_META . $lote, -1.0) === -1.0);
    });

    $emTransacao('metas: os meses antes não mudam', static function () use ($check, $pdo, $catDia, $mA, $mB, $mC2, $mD, $uid, $gravarPlano, $valorEm, $linhaCrua): void {
        $pdo->prepare('DELETE FROM previsao WHERE categoria_id = :c AND mes >= :m')->execute(['c' => $catDia, 'm' => $mA]);
        MetaModel::definir($catDia, 1500.0);
        PrevisaoModel::setValor($catDia, $mB, 1200.0);
        MotorFinanceiro::limparCache();
        $antesA = $valorEm($catDia, $mA);
        $lote = $gravarPlano(MetaRecorrencia::plano(MotorFinanceiro::dados(), $catDia, 1000.0, $mC2, null));
        MotorFinanceiro::limparCache();
        $check('metas: sem fim a partir de janeiro não muda novembro nem dezembro', $antesA === 1500.0 && $valorEm($catDia, $mA) === $antesA
            && $valorEm($catDia, $mB) === 1200.0 && $valorEm($catDia, $mC2) === 1000.0 && $valorEm($catDia, $mD) === 1000.0);
        $check('metas: o mês antes que seguia a meta guarda o valor de antes', $linhaCrua($catDia, $mA) === 1500.0);
        PrevisaoLote::desfazer((string) $lote, $uid);
        MotorFinanceiro::limparCache();
        $check('metas: desfazer devolve novembro à meta', $linhaCrua($catDia, $mA) === null && $valorEm($catDia, $mA) === 1500.0 && $valorEm($catDia, $mD) === 1500.0);
    });

    $emTransacao('metas: só este mês e intervalo', static function () use ($check, $catDia, $mA, $mB, $mC2, $mD, $mE, $uid, $preparar, $gravarPlano, $valorEm, $linhaCrua): void {
        $preparar();
        $lote = $gravarPlano(MetaRecorrencia::plano(MotorFinanceiro::dados(), $catDia, 700.0, $mC2, $mC2));
        MotorFinanceiro::limparCache();
        $check('metas: "só este mês" muda só janeiro e não mexe na meta', $valorEm($catDia, $mC2) === 700.0 && $valorEm($catDia, $mB) === 1200.0
            && $valorEm($catDia, $mD) === 1500.0 && abs((float) MetaModel::valor($catDia) - 1500.0) < 0.001);
        PrevisaoLote::desfazer((string) $lote, $uid);
        $gravarPlano(MetaRecorrencia::plano(MotorFinanceiro::dados(), $catDia, 600.0, $mB, $mD));
        MotorFinanceiro::limparCache();
        $check('metas: intervalo de dezembro a fevereiro', $valorEm($catDia, $mB) === 600.0 && $valorEm($catDia, $mC2) === 600.0 && $valorEm($catDia, $mD) === 600.0
            && $valorEm($catDia, $mA) === 800.0 && $valorEm($catDia, $mE) === 1500.0);
        $gravarPlano(MetaRecorrencia::plano(MotorFinanceiro::dados(), $catDia, 1500.0, $mB, $mB));
        $gravarPlano(MetaRecorrencia::plano(MotorFinanceiro::dados(), $catDia, null, $mC2, $mC2));
        MotorFinanceiro::limparCache();
        $check('metas: no intervalo, o valor da meta volta a seguir a meta e "sem meta" deixa zero', $linhaCrua($catDia, $mB) === null
            && $valorEm($catDia, $mB) === 1500.0 && $linhaCrua($catDia, $mC2) === 0.0);
    });

    $erroPlano = static function (callable $f): string {
        try {
            $f();
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }
        return '';
    };
    $dR = MotorFinanceiro::dados();
    $check('metas: recusa o fim antes do começo, mês que não existe e categoria que não é gasto',
        $erroPlano(static fn() => MetaRecorrencia::plano($dR, $catDia, 1.0, $mB, $mA)) !== ''
        && $erroPlano(static fn() => MetaRecorrencia::plano($dR, $catDia, 1.0, '1990-01-01', null)) !== ''
        && $erroPlano(static fn() => MetaRecorrencia::plano($dR, $catMov, 1.0, $mA, null)) === 'Escolha uma categoria.');

    $emTransacao('metas: mês fechado', static function () use ($check, $pdo, $catDia, $realUlt, $uid, $gravarPlano, $valorEm, $linhaCrua, $render): void {
        // o mês já estava fechado antes da mudança (com o relógio parado, a hora do fechamento seria a mesma)
        $pdo->prepare("UPDATE meses SET fechado_em = '2026-11-01 08:00:00' WHERE mes = :m")->execute(['m' => $realUlt]);
        $antes = $linhaCrua($catDia, $realUlt);
        $real = round((float) (MotorFinanceiro::dados()['real'][$realUlt][$catDia] ?? 0.0), 2);
        $nova = max(1.0, round($real / 2, 2));                    // abaixo do gasto: acima da meta
        $lote = $gravarPlano(MetaRecorrencia::plano(MotorFinanceiro::dados(), $catDia, $nova, $realUlt, $realUlt));
        MotorFinanceiro::limparCache();
        $check('metas: editar um mês fechado grava a meta dele', $lote !== null && $linhaCrua($catDia, $realUlt) === $nova && $valorEm($catDia, $realUlt) === $nova);
        $htmlR = $render(MesController::class, 'index', ['mes' => $realUlt]);
        preg_match('#<tr id="cat-' . $catDia . '".*?</tr>#s', $htmlR, $mR);
        $check('metas: o Mês fechado mostra a nova meta e a situação', str_contains($mR[0] ?? '', MENOS . num($nova))
            && ($real <= $nova || str_contains($mR[0] ?? '', e(t('passou {valor}', ['valor' => num($real - $nova)])))), num($nova));
        $rel = RelatorioDados::mensal($realUlt);
        $planejado = null;
        foreach ($rel['categorias']['linhas'] ?? [] as $lR) {
            if ((int) $lR['id'] === $catDia) {
                $planejado = (float) $lR['planejado'];
            }
        }
        $check('metas: o Relatório do mês fechado usa a nova meta', $planejado !== null && abs($planejado - $nova) < 0.001);
        $r = PrevisaoLote::desfazer((string) $lote, $uid);
        MotorFinanceiro::limparCache();
        $check('metas: desfazer devolve o mês fechado', $r['restauradas'] === 1 && $linhaCrua($catDia, $realUlt) === $antes);
        // mês fechado depois da mudança: o fechamento guardou o que valia, e o desfazer não mexe nele
        $mAberto = (string) $pdo->query("SELECT MIN(mes) FROM meses WHERE situacao = 'PREVISAO'")->fetchColumn();
        $l2 = $gravarPlano(MetaRecorrencia::plano(MotorFinanceiro::dados(), $catDia, 321.0, $mAberto, $mAberto));
        $pdo->prepare("UPDATE meses SET situacao = 'REAL', fechado_em = '2027-06-01 08:00:00' WHERE mes = :m")->execute(['m' => $mAberto]);
        $r2 = PrevisaoLote::desfazer((string) $l2, $uid);
        $check('metas: mês fechado depois da mudança não volta no desfazer', $r2['restauradas'] === 0 && $linhaCrua($catDia, $mAberto) === 321.0);
    });

    $emTransacao('metas: a tela', static function () use ($check, $pdo, $catDia, $mA, $mB, $render, $preparar): void {
        $preparar();
        $html = $render(MetaController::class, 'index', ['mes' => $mB]);
        $c = (string) (preg_match('#<details class="mt-linha[^"]*" id="c' . $catDia . '".*?</details>#s', $html, $mm) ? $mm[0] : '');
        $check('metas: o seletor de meses e o valor que vale no mês escolhido', str_contains($html, 'data-mes-picker') && str_contains($html, 'href="/meta?mes=' . $mA . '"')
            && str_contains($c, num(1200.0)) && str_contains($c, 'só em dezembro, a meta é ' . num(1500.0)));
        // § 7.4 do redesenho: "Para usar" desconta as contas fixas que ainda faltam pagar (as mesmas do Mês e do Início)
        $porPagarM = round(array_sum(array_column(\Financeiro\Services\ChecklistContas::aPagar($mB, \Financeiro\Relogio::hoje()), 'falta')), 2);
        preg_match('#data-renda="([\d.-]+)" data-gasto-total="([\d.-]+)" data-por-pagar="([\d.-]+)"#', $html, $mP);
        $check('metas: "Para usar" desconta as contas fixas que faltam pagar', $porPagarM > 0.004 && isset($mP[3]) && (float) $mP[3] === $porPagarM
            && str_contains($html, 'data-para-usar>' . valor(round((float) $mP[1] - (float) $mP[2] - $porPagarM, 2), 'neutro', ['moeda' => true]) . '<')
            && !str_contains($html, 'data-meta-aloc'), json_encode([$porPagarM, $mP]));
        $check('metas: o editor tem valor, a partir de e até', str_contains($c, 'action="/meta/editar"') && str_contains($c, 'name="desde"')
            && str_contains($c, '<option value="' . $mB . '" selected') && str_contains($c, 'Sem fim') && str_contains($c, 'Só este mês'));
        $check('metas: sem texto solto de aviso', !str_contains($html, 'meta-previsao') && !str_contains($html, 'Mude só ele na tela'));
        $check('metas: as contas fixas ficam em Metas', str_contains($html, 'id="contas-fixas"') && str_contains($html, 'action="/meta/contas"')
            && preg_match('#action="/meta/contas/\d+"#', $html) === 1 && preg_match('#action="/meta/contas/\d+/desativar"#', $html) === 1);
    });

    // conta fixa com valor em todo mês e sem meta (como a primeira configuração grava): sem fim a partir de dezembro
    $catConta = (int) $pdo->query("SELECT r.categoria_id FROM contas_recorrentes r JOIN categorias c ON c.id = r.categoria_id WHERE r.ativo = 1 AND c.grupo = 'Fixos' ORDER BY c.id LIMIT 1")->fetchColumn();
    $emTransacao('metas: conta fixa', static function () use ($check, $catConta, $mA, $mB, $mE, $uid, $gravarPlano, $valorEm): void {
        MetaModel::remover($catConta);
        MotorFinanceiro::limparCache();
        $antesA = $valorEm($catConta, $mA);
        $lote = $gravarPlano(MetaRecorrencia::plano(MotorFinanceiro::dados(), $catConta, $antesA + 100, $mB, null));
        MotorFinanceiro::limparCache();
        $check('metas: conta fixa muda de dezembro em diante, novembro fica', $catConta > 0 && $lote !== null && $valorEm($catConta, $mA) === $antesA
            && $valorEm($catConta, $mB) === $antesA + 100 && $valorEm($catConta, $mE) === $antesA + 100);
        PrevisaoLote::desfazer((string) $lote, $uid);
        MotorFinanceiro::limparCache();
        $check('metas: desfazer devolve a conta fixa', $valorEm($catConta, $mB) === $antesA && MetaModel::valor($catConta) === null);
    });
}

// ---- 11. extrato, ajustes e lançar
$htmlEx = $render(LancamentosController::class, 'index', []);
$htmlAjCat = $render(AjustesController::class, 'index', ['aberto' => 'categorias']);
// o extrato abre no mês do calendário, que na demonstração já tem lançamentos
$check('extrato: ícone em cada lançamento, sem o painel de categorias', str_contains($htmlEx, 'class="ci"') && !str_contains($htmlEx, 'action="/categorias"'));
$semEmoji = static fn(string $h): bool => !str_contains($h, 'icones.webp') && !str_contains($h, '--ic-sprite')
    && !str_contains($h, 'background-position') && preg_match('/<span class="ci[ "]/', $h) !== 1;
$check('ícones: extrato e ajustes desenham o traço do sprite, sem o emoji de antes', $semEmoji($htmlEx) && $semEmoji($htmlAjCat)
    && str_contains($htmlEx, 'categorias.svg?v=') && str_contains($htmlAjCat, '<svg class="ci" width="24" height="24"'));
$check('ajustes: categorias, com o ícone dobrado em "Escolher ícone" e cada uma levando ao editar', str_contains($htmlAjCat, 'id="categorias" open') && str_contains($htmlAjCat, 'action="/categorias"')
    && str_contains($htmlAjCat, 'class="ic-escolher"') && str_contains($htmlAjCat, 'data-ic-sugerir') && str_contains($htmlAjCat, 'class="aj-cat" href="/categorias/' . $catDia . '"')
    && !str_contains($htmlAjCat, '/icone?volta='));
$check('lançar: atalho para criar categoria', str_contains($render(LancamentosController::class, 'lancarForm', []), 'class="lf-criar-cat" href="/ajustes?aberto=categorias#categorias"'));
// os grupos, na ordem: dinheiro e planejamento, aparência e idioma, conta e segurança, sistema e sobre; o perigoso no fim de cada um
$ordemAj = ['ajs-g-plano', 'id="categorias"', 'id="parametros"', 'ajs-ir" href="/meta#contas-fixas"', 'id="recursos"', 'id="partida"', 'ajs-g-aparencia', 'ajs-ir" href="/temas"', 'id="idioma"',
    'id="regional"', 'id="icone"', 'ajs-g-conta', 'id="senha"', 'id="duasetapas"', 'id="pessoas"', 'id="aparelhos"', 'ajs-g-sistema', 'ajs-ir" href="/rede"', 'ajs-ir" href="/sistema"', 'ajs-g-sobre', 'ajs-ir" href="/privacidade"'];
$posAj = array_map(static fn(string $x) => strpos($htmlAjCat, $x), $ordemAj);
$check('ajustes: grupos por assunto, na ordem combinada', !in_array(false, $posAj, true) && $posAj === array_values(array_unique($posAj)) && (static function (array $p): bool { $q = $p; sort($q); return $q === $p; })($posAj),
    implode(',', array_map(static fn($x) => var_export($x, true), $posAj)));
$check('ajustes: contas fixas e essenciais saíram (contas em Metas, essencial em cada categoria)', !str_contains($htmlAjCat, '/ajustes/contas') && !str_contains($htmlAjCat, '/ajustes/essenciais')
    && !str_contains($htmlAjCat, 'id="essenciais"') && !str_contains($htmlAjCat, 'id="contas"'));
$check('ajustes: cada linha mostra o estado à direita', substr_count($htmlAjCat, 'class="ajs-val"') >= 10 && str_contains($htmlAjCat, '<span class="ajs-val">' . e(Idioma::LISTA['pt']['nome']) . '</span>'));
$check('ajustes: só abre o item pedido', substr_count($htmlAjCat, '" open>') === 1);
$check('ajustes: escolher a imagem do site num botão próprio', str_contains($htmlAjCat, 'class="btn btn-ghost aj-arquivo"') && str_contains($htmlAjCat, 'data-arquivo'));
$check('lançar: só o select de categoria, sem atalhos', !str_contains($render(LancamentosController::class, 'lancarForm', []), 'lf-atalho'));

// ---- 13. Anual: o que mudou acompanha a previsão; a tela
// o "o que mudou" sai da previsão gravada no banco a cada requisição: mudar um mês aparece nele e no seguinte
$mA = $prevMeses[count($prevMeses) - 3];
$mB = date('Y-m-01', strtotime($mA . ' +1 month'));
$emTransacao('o que mudou acompanha a previsão', static function () use ($check, $catDia, $mA, $mB): void {
    MotorFinanceiro::limparCache();
    PrevisaoModel::setValor($catDia, $mA, PrevisaoModel::valor($catDia, $mA) + 6543.0);
    MotorFinanceiro::limparCache();
    $mu = AnualResumo::mudancas(MotorFinanceiro::calcularPlano(), MotorFinanceiro::dados());
    $acha = static fn(array $l): int => count(array_filter($l, static fn($x) => abs(abs($x['valor'] - $x['antes']) - 6543.0) < 0.01));
    $check('anual: "o que mudou" acompanha a previsão gravada (sobe no mês, volta no seguinte)',
        $acha($mu[$mA] ?? []) === 1 && $acha($mu[$mB] ?? []) === 1 && ($mu[$mA][0]['efeito'] ?? 0) < 0 && ($mu[$mB][0]['efeito'] ?? 0) > 0,
        json_encode([$mu[$mA] ?? null, $mu[$mB] ?? null], JSON_UNESCAPED_UNICODE));
});
MotorFinanceiro::limparCache();
$htmlFu = $render(FuturoController::class, 'index', []);
$check('anual: o gráfico como peça principal, com a conclusão no título, a previsão tracejada e o total por ano', str_contains($htmlFu, 'class="sim-fim"')
    && str_contains($htmlFu, 'id="sim-graf-t">A conta ') && str_contains($htmlFu, 'class="sim-linha is-prev"')
    && str_contains($htmlFu, 'class="sim-cols sim-total num"') && strpos($htmlFu, 'sim-graf') < strpos($htmlFu, 'sim-caminho'));
// os rótulos do eixo não se tocam: com muitos meses, um a cada k (passo-k), contados do janeiro
$check('anual: o eixo mostra um rótulo a cada k meses quando são muitos', preg_match('#class="sim-eixo passo-(\d+) cel-(\d+)"#', $htmlFu, $mEixo) === 1
    && (substr_count($htmlFu, 'class="sim-mx ') <= 22 || (int) $mEixo[1] >= 2) && (int) $mEixo[2] >= (int) $mEixo[1]);

// ---- 14. "e se" do Anual: o motor soma o extra fora de mês fechado; a URL vira simulação; nada é gravado
MotorFinanceiro::limparCache();
$planoSemAd = MotorFinanceiro::calcular(MotorFinanceiro::dados(), ['objetivo' => 3]);
$mesesPrevE = array_values(array_filter(array_keys($planoSemAd), static fn($m) => $planoSemAd[$m]['estado'] === 'previsto'));
$mesesReal = array_values(array_filter(array_keys($planoSemAd), static fn($m) => $planoSemAd[$m]['estado'] === 'real'));
[$e1, $e2] = [$mesesPrevE[0], $mesesPrevE[1]];
$extraT = [$e1 => 500.0] + ($mesesReal !== [] ? [$mesesReal[0] => 9999.0] : []);
$comExtra = MotorFinanceiro::calcular(MotorFinanceiro::dados(), ['objetivo' => 3, 'extra' => $extraT]);
$check('e se: +500 num mês sobe a conta 500 nele e nos seguintes (sem adiantar)',
    abs($comExtra[$e1]['fica_na_conta'] - $planoSemAd[$e1]['fica_na_conta'] - 500.0) < 0.01
    && abs($comExtra[$e2]['fica_na_conta'] - $planoSemAd[$e2]['fica_na_conta'] - 500.0) < 0.01);
$check('e se: mês fechado não muda', abs($comExtra[$mesesReal[0]]['fica_na_conta'] - $planoSemAd[$mesesReal[0]]['fica_na_conta']) < 0.01);
$refE = MesModel::referencia($hoje);
$_GET = ['se' => '1', 'mensal' => '500', 'mensal_tipo' => 'economizar', 'mensal_de' => $e1,
         'uma' => '2.000,00', 'uma_tipo' => 'gasto', 'uma_mes' => $e2, 'adiantar' => '1'];
$simT = FuturoController::simulacao(MotorFinanceiro::calcularPlano(), $refE, true);
$check('e se: a URL vira o extra de cada mês (500 por mês, gasto de 2.000 num deles)', $simT['ativo']
    && abs(($simT['extra'][$e1] ?? 0.0) - 500.0) < 0.01 && abs(($simT['extra'][$e2] ?? 0.0) + 1500.0) < 0.01 && $simT['erros'] === []);
$_GET = ['se' => '1', 'mensal' => 'abc', 'adiantar' => '1'];
$simT2 = FuturoController::simulacao(MotorFinanceiro::calcularPlano(), $refE, true);
$check('e se: valor inválido é avisado e ignorado', !$simT2['ativo'] && count($simT2['erros']) === 1);
$htmlSe = $render(FuturoController::class, 'index', ['se' => '1', 'mensal' => '500', 'mensal_de' => $e1, 'adiantar' => '1']);
$check('e se: a página mostra o aviso, a comparação com o plano e a linha do plano', str_contains($htmlSe, 'class="faixa-grade sim-aviso"')
    && str_contains($htmlSe, 'class="sim-cmp num"') && str_contains($htmlSe, 'class="sim-base-l"') && str_contains($htmlSe, 'E se: +500'));
$check('e se: sem simulação, nada disso aparece', !str_contains($htmlFu, 'sim-aviso') && !str_contains($htmlFu, 'class="sim-cmp num"')
    && str_contains($htmlFu, 'id="e-se"'));
$_GET = [];

// ---- 15. cartão: compra, plano, Mês e a barra
$catFat = CartaoModel::categoriaFatura();
$catGasto = (int) $pdo->query("SELECT id FROM categorias WHERE grupo = 'Dia a dia' AND ativo = 1 AND sinal = -1 ORDER BY id LIMIT 1")->fetchColumn();
$emTransacao('cartão alimentando o plano', static function () use ($pdo, $check, $catFat, $catGasto, $render, $hoje): void {
    $pdo->exec('UPDATE cartoes SET ativo = 0');    // o cartão da demonstração sai de cena até o rollback
    $idC = CartaoModel::salvar(null, 'Cartão teste', 25, 5, null);
    $aberta = CartaoFatura::faturaDe($hoje, 25, 5);
    $antes = PrevisaoModel::valor($catFat, $aberta);
    $r = CartaoController::criarCompraDe(['valor' => '1.000,00', 'categoria_id' => (string) $catGasto, 'data' => $hoje,
                                          'parcelas' => '4', 'descricao' => 'TV teste'], $hoje);
    MotorFinanceiro::limparCache();
    $check('cartão: a compra entra no cartão e, desligado do plano, a previsão da fatura não muda', $r['erro'] === null && $r['id'] > 0
        && abs(PrevisaoModel::valor($catFat, $aberta) - $antes) < 0.01 && str_contains($r['aviso'], '4 × 250,00'), json_encode($r));
    $erroCat = CartaoController::criarCompraDe(['valor' => '10', 'categoria_id' => (string) $catFat, 'data' => $hoje, 'parcelas' => '1'], $hoje);
    $check('cartão: a categoria da compra não pode ser a própria fatura', $erroCat['erro'] !== null);
    CartaoModel::setAlimentaPlano($idC, true);
    $lote = CartaoFatura::sincronizarPlano((int) Auth::id(), $hoje);
    MotorFinanceiro::limparCache();
    $mesSeg = CartaoFatura::somaMeses($aberta, 3);
    $mesFim = CartaoFatura::somaMeses($aberta, 4);
    $sit = static fn(string $m): ?string => ($v = $pdo->query("SELECT situacao FROM meses WHERE mes = '" . $m . "'")->fetchColumn()) === false ? null : (string) $v;
    $okMeses = ($sit($aberta) !== 'PREVISAO' || abs(PrevisaoModel::valor($catFat, $aberta) - 250.0) < 0.01)
        && ($sit($mesSeg) !== 'PREVISAO' || abs(PrevisaoModel::valor($catFat, $mesSeg) - 250.0) < 0.01)
        && ($sit($mesFim) !== 'PREVISAO' || abs(PrevisaoModel::valor($catFat, $mesFim)) < 0.01);
    $origem = $lote !== null ? $pdo->query("SELECT origem FROM previsao_lotes WHERE lote = '" . $lote . "'")->fetchColumn() : null;
    $check('cartão: ligado ao plano, a Fatura do cartão de cada mês vira a soma das parcelas (lote cartao, fora do desfazer do Mês)',
        $lote !== null && $origem === 'cartao' && $okMeses && (PrevisaoLote::ultimoDesfazivel()['lote'] ?? '') !== $lote,
        json_encode([$lote, $origem, PrevisaoModel::valor($catFat, $aberta)]));
    $htmlMesC = $sit($mesSeg) === 'PREVISAO' ? $render(MesController::class, 'index', ['mes' => $mesSeg]) : 'mes-do-cartao';
    $check('cartão: no Mês, a Fatura do cartão vem do cartão (só leitura, leva à fatura)', str_contains($htmlMesC, 'mes-do-cartao'));
    $htmlCt = $render(CartaoController::class, 'index', []);
    $check('cartão: a aba mostra o cartão, as próximas faturas, a fatura e o parcelamento', str_contains($htmlCt, 'class="ct-plastico"')
        && str_contains($htmlCt, 'class="ct-barras"') && str_contains($htmlCt, 'TV teste') && str_contains($htmlCt, 'aria-labelledby="ct-parc-t"')
        && substr_count($htmlCt, 'class="ct-tab"') >= 2 && !str_contains($htmlCt, 'ct-conf-tab'));
    // o redesenho: o cartão liso (sem chip, sem pílula de situação) e a situação em frase, sem "A · B · C"
    $check('cartão: o desenho é liso e a situação vem em frase, sem "·"', !str_contains($htmlCt, 'ct-chip') && !str_contains($htmlCt, 'ct-status')
        && preg_match('#class="ct-aberta-sit">[^<]*·#u', $htmlCt) === 0 && preg_match('#class="ct-fat-sit">[^<]*·#u', $htmlCt) === 0);
    $antigas = Sql::tabelaExiste($pdo, 'cartao_compras_v1')
        ? (int) $pdo->query('SELECT COUNT(*) FROM cartao_compras_v1 v WHERE NOT EXISTS (SELECT 1 FROM cartao_compras n
                             WHERE n.descricao = v.descricao AND n.criado_em = v.criado_em)')->fetchColumn() : 0;   // as que ainda não vieram
    $vieram = CartaoModel::importarAntigas($idC);
    $deNovo = CartaoModel::importarAntigas($idC);
    $check('cartão: as compras da versão antiga do app vêm junto, uma vez só', $vieram === $antigas && $deNovo === 0, json_encode([$antigas, $vieram, $deNovo]));
    LancarForm::limparCache();    // os dados do + ficam em cache na requisição; o cartão de teste acabou de nascer
    $htmlLanc = $render(LancamentosController::class, 'lancarForm', ['pagou' => 'cartao']);
    $check('cartão: o + tem "Pagou com", as vezes e a prévia, já em Cartão', str_contains($htmlLanc, 'name="pagou" value="cartao" checked')
        && str_contains($htmlLanc, 'data-ct-bloco') && str_contains($htmlLanc, 'name="parcelas"'));
});
MotorFinanceiro::limparCache();
LancarForm::limparCache();
$emTransacao('sem cartão cadastrado', static function () use ($pdo, $render, $check): void {
    $pdo->exec('UPDATE cartoes SET ativo = 0');
    $check('cartão: sem cartão cadastrado, a aba pede o cadastro', CartaoModel::ativos() === [] && str_contains($render(CartaoController::class, 'index', []), 'Cadastre o cartão'));
});
// ---- 15b. gasto por categoria: nas Metas e no "Para onde foi", o cartão conta pela data da compra, parcela a parcela; o
// plano continua pela fatura. Os mesmos números nas duas passadas: as datas fixas caem no mês de hoje (nov/26).
$emTransacao('gasto por categoria', static function () use ($pdo, $check, $catFat, $render, $hoje, $mesHoje, $uid): void {
    // o cartão da demonstração e os pagamentos de fatura saem de cena até o rollback: os números ficam conhecidos
    $pdo->exec('DELETE FROM cartao_compras');
    $pdo->exec('DELETE FROM lancamentos WHERE categoria_id = ' . $catFat);
    $pdo->exec('UPDATE cartoes SET ativo = 0');
    $nova = static function (string $nome) use ($pdo): int {
        $pdo->prepare("INSERT INTO categorias (nome, grupo, sinal, essencial, ordem) VALUES (?, 'Dia a dia', -1, 0, 90)")->execute([$nome]);
        return (int) $pdo->lastInsertId();
    };
    [$catD, $catL, $catM] = [$nova('Teste delivery'), $nova('Teste lazer'), $nova('Teste parcelado')];
    MetaModel::definir($catD, 500.0);
    MetaModel::definir($catL, 150.0);
    $cA = CartaoModel::salvar(null, 'Cartão A teste', 25, 5, null);
    $cB = CartaoModel::salvar(null, 'Cartão B teste', 10, 20, null);
    $fNovA = '2026-11-01';
    $fNovB = CartaoFatura::faturaDe('2026-11-05', 10, 20);
    // pagamentos ligados: A paga 250 da fatura de nov (só 100 dela tem compra registrada); B paga a dele inteira
    LancamentoModel::criar('2026-11-04', 'Fatura do cartão', $catFat, 250.0, $uid, null, ['cartao_id' => $cA, 'fatura' => $fNovA]);
    LancamentoModel::criar('2026-11-18', 'Fatura do cartão', $catFat, 90.0, $uid, null, ['cartao_id' => $cB, 'fatura' => $fNovB]);
    MotorFinanceiro::limparCache();
    $planoAntes = MotorFinanceiro::calcularPlano();
    $real = MotorFinanceiro::dados()['real'];

    $compra = static fn(int $cartao, string $data, ?int $cat, float $v, int $n, string $primeira): int
        => CartaoModel::criarCompra($cartao, $data, 'teste', $cat, $v, $n, $primeira, $uid);
    $compra($cA, '2026-11-03', $catD, 120.0, 1, CartaoFatura::faturaDe('2026-11-03', 25, 5));                 // à vista
    $compra($cA, '2026-10-28', $catL, 300.0, 3, CartaoFatura::faturaDe('2026-10-28', 25, 5));                 // 3x: out, nov e dez
    $antiga = CartaoController::criarCompraDe(['valor' => '500', 'categoria_id' => (string) $catM, 'data' => $hoje, 'parcelas' => '5',
                                               'parcela_atual' => '3', 'descricao' => 'antiga'], $hoje, $cA);       // 3/5, lançada hoje
    $estorno = CartaoController::criarCompraDe(['valor' => '50', 'estorno' => '1', 'categoria_id' => (string) $catD, 'data' => $hoje, 'descricao' => 'estorno'], $hoje, $cB);
    $compra($cB, '2026-11-05', $catL, 90.0, 1, $fNovB);                                                      // outro cartão
    $semCat = $compra($cB, '2026-11-06', null, 40.0, 1, $fNovB);                                             // sem categoria
    MotorFinanceiro::limparCache();
    $planoDepois = MotorFinanceiro::calcularPlano();
    $check('gasto por categoria: o plano é o mesmo, com ou sem as compras no cartão (sem dupla contagem)', $planoDepois === $planoAntes);

    $g = GastoPorCategoria::doMes($mesHoje);
    $check('gasto por categoria: à vista no mês da compra; o estorno abate', abs(($g[$catD] ?? 0.0) - 70.0) < 0.001, json_encode($g));
    $check('gasto por categoria: 3x atravessando meses, uma parcela por mês a partir do da compra; outro cartão soma junto',
        abs((GastoPorCategoria::doMes('2026-10-01')[$catL] ?? 0.0) - 100.0) < 0.001 && abs(($g[$catL] ?? 0.0) - 190.0) < 0.001
        && abs((GastoPorCategoria::doMes('2026-12-01')[$catL] ?? 0.0) - 100.0) < 0.001 && !isset(GastoPorCategoria::doMes('2027-01-01')[$catL]));
    $ca = $antiga['erro'] === null ? CartaoModel::acharCompra($antiga['id']) : null;
    $base = $ca !== null ? min($mesHoje, $ca['primeira_fatura']) : '';
    $okAntiga = $ca !== null && $ca['primeira_fatura'] <= $mesHoje;    // a data (hoje) é depois da 1ª fatura, ou no mesmo mês
    for ($i = -1; $i <= 5; $i++) {
        $v = GastoPorCategoria::doMes(CartaoFatura::somaMeses($base, $i))[$catM] ?? 0.0;
        $okAntiga = $okAntiga && abs($v - ($i >= 0 && $i < 5 ? 100.0 : 0.0)) < 0.001;
    }
    $check('gasto por categoria: compra antiga (3/5) lançada hoje conta a partir do mês da 1ª fatura, uma parcela por mês',
        $okAntiga && abs(($g[$catM] ?? 0.0) - 100.0) < 0.001, json_encode([$ca, $base]));
    $check('gasto por categoria: sem categoria vira "Cartão sem categoria"', abs(($g[GastoPorCategoria::SEM_CATEGORIA] ?? 0.0) - 40.0) < 0.001
        && GastoPorCategoria::semCategoria($mesHoje)['compras'] === [$semCat]);
    $check('gasto por categoria: a Fatura do cartão fica só com o pago sem compra registrada; o pagamento todo detalhado sai',
        abs(($g[$catFat] ?? 0.0) - 150.0) < 0.001 && $estorno['erro'] === null, json_encode($g));
    $somaCaixa = 0.0;
    foreach ($real[$mesHoje] ?? [] as $c => $v) {
        $cat = MotorFinanceiro::dados()['cats'][$c] ?? null;
        $somaCaixa += $cat !== null && $cat['operacional'] === 1 && $cat['sinal'] === -1 ? $v : 0.0;
    }
    $check('gasto por categoria: no mês, a diferença para o extrato é só o cartão (compras − pagamentos detalhados)',
        abs(array_sum($g) - $somaCaixa - (70.0 + 190.0 + 100.0 + 40.0 + 150.0 - 340.0)) < 0.01, json_encode([array_sum($g), $somaCaixa]));

    // Metas: a meta estoura só por causa do cartão; "Resta para usar" usa a mesma conta
    $hMeta = $render(MetaController::class, 'index', []);
    $gastoTotal = 0.0;
    foreach (CategoriaModel::gastos() as $c) {
        $gastoTotal += max(0.0, $g[(int) $c['id']] ?? 0.0);
    }
    $gastoTotal += $g[GastoPorCategoria::SEM_CATEGORIA];
    $check('metas: a meta estoura só por causa do cartão', str_contains($hMeta, 'mt-linha is-over" id="c' . $catL . '"')
        && str_contains($hMeta, 'data-gasto="190.00"') && str_contains($hMeta, 'mt-linha is-ok" id="c' . $catD . '"'));
    $check('metas: "Resta para usar" conta o cartão pela data da compra, com o sem categoria', str_contains($hMeta, 'data-gasto-total="' . number_format($gastoTotal, 2, '.', '') . '"'), number_format($gastoTotal, 2, '.', ''));
    $check('metas: diz que o cartão conta pela data da compra e leva a categorizar', str_contains($hMeta, 'O cartão conta pela data da compra.')
        && str_contains($hMeta, 'href="/cartao/compras/' . $semCat . '/editar"'));

    // "Para onde foi": soma 100%, bate com a visão e é o mesmo no Início e no Relatório
    $positivo = round(array_sum(array_filter($g, static fn(float $v): bool => $v > 0.004)), 2);
    $painel = (new \ReflectionMethod(DashboardController::class, 'painel'))->invoke(null, GastosMensais::matriz()[$mesHoje] ?? null, $mesHoje, 'aberto', false);
    $pcts = array_sum(array_map(static fn(array $t): float => $t['valor'] / $painel['ondeTotal'] * 100, $painel['onde']));
    $rel = RelatorioDados::mensal($mesHoje);
    $relSoma = array_sum(array_column($rel['categorias']['linhas'], 'real')) + ($rel['categorias']['outros']['real'] ?? 0.0);
    $check('"Para onde foi": soma 100% e bate com o total gasto da visão, no Início e no Relatório', $painel['porCompra'] && abs($pcts - 100.0) < 0.001
        && abs($painel['ondeTotal'] - $positivo) < 0.01 && abs($rel['categorias']['totalReal'] - $positivo) < 0.01 && abs($relSoma - $positivo) < 0.01,
        json_encode([$pcts, $painel['ondeTotal'], $positivo, $rel['categorias']['totalReal']]));
    $nomes = array_column($painel['onde'], 'nome');
    $check('"Para onde foi": a fatura aberta nas categorias, com "Fatura sem detalhe" e "Cartão sem categoria"', in_array('Teste lazer', $nomes, true)
        && in_array('Fatura sem detalhe', $nomes, true) && in_array('Cartão sem categoria', $nomes, true) && !in_array('Fatura', $nomes, true), json_encode($nomes, JSON_UNESCAPED_UNICODE));
    $hIni = $render(DashboardController::class, 'index', []);
    $hRel = $render(RelatorioController::class, 'index', ['mes' => $mesHoje]);
    $hIniFat = $render(DashboardController::class, 'index', ['onde' => 'fatura']);
    $relFat = RelatorioDados::mensal($mesHoje, null, true);
    $hIniVolta = $render(DashboardController::class, 'index', ['onde' => 'compra']);
    unset($_COOKIE[DashboardController::COOKIE_ONDE]);
    $check('"Para onde foi": o Início mostra a escolha e o atalho; "ver por fatura" volta ao que era', str_contains($hIni, 'Ver por fatura')
        && str_contains($hIni, 'Compras no cartão sem categoria') && str_contains($hIni, 'href="/cartao/compras/' . $semCat . '/editar"')
        && str_contains($hIniFat, 'Ver pela data da compra') && !str_contains($hIniFat, 'Compras no cartão sem categoria') && str_contains($hIniVolta, 'Ver por fatura'));
    $check('"Para onde foi": por fatura, o Relatório soma o que saiu da conta, com a Fatura do cartão', !$relFat['porCompra']
        && abs($relFat['categorias']['totalReal'] - $somaCaixa) < 0.01 && in_array('Fatura', array_column($relFat['categorias']['linhas'], 'nome'), true));
    $check('"Para onde foi": o Relatório mostra a lista na tela, com a escolha, e o PDF sai', str_contains($hRel, 'class="faixa-grade rl-onde"') && str_contains($hRel, 'Ver por fatura')
        && str_starts_with((new \Financeiro\Pdf\RelatorioPdf($rel))->gerar(), '%PDF') && str_starts_with((new \Financeiro\Pdf\RelatorioPdf($relFat))->gerar(), '%PDF'));
    // o total diz que conta é, para não parecer o "Gastou" da escada (C-03): na tela e no PDF, pela data da compra ou pela fatura
    $textoPdf = static function (array $r): string {
        $pdf = new \Financeiro\Pdf\RelatorioPdf($r);
        $pdf->gerar();
        $doc = (new \ReflectionProperty($pdf, 'd'))->getValue($pdf);
        $mapa = [];
        foreach ((new \ReflectionProperty($doc, 'fontes'))->getValue($doc) as $f) {
            foreach ($f['usados'] as $gid => [$cp]) {
                $mapa[$f['n']][$gid] = mb_chr($cp);
            }
        }
        $txt = '';
        foreach ((new \ReflectionProperty($doc, 'paginas'))->getValue($doc) as $pag) {
            preg_match_all('#/F(\d+) .*? <([0-9A-F]*)> Tj#', $pag, $mm, PREG_SET_ORDER);
            foreach ($mm as [, $n, $hex]) {
                foreach (str_split($hex, 4) as $g) {
                    $txt .= $mapa[(int) $n][hexdec($g)] ?? '';
                }
                $txt .= "\n";
            }
        }
        return $txt;
    };
    $check('"Para onde foi": o total diz a conta, na tela e no PDF ("pela data da compra" ou "pela fatura paga"), e não "gastos"',
        str_contains($hRel, num($rel['categorias']['totalReal']) . ' pela data da compra') && !str_contains($hRel, ' gastos</p>')
        && str_contains($render(RelatorioController::class, 'index', ['mes' => $mesHoje, 'onde' => 'fatura']), num($relFat['categorias']['totalReal']) . ' pela fatura paga')
        && str_contains($textoPdf($rel), "Total pela data da compra\n") && str_contains($textoPdf($relFat), "Total pela fatura paga\n"));
    unset($_COOKIE[DashboardController::COOKIE_ONDE]);
});
// sem cartão (sem cartão ativo ou sem a aba), nada muda: a visão é o extrato, e as telas não mostram a escolha
$emTransacao('gasto por categoria sem cartão', static function () use ($pdo, $check, $render, $mesHoje): void {
    foreach (['sem cartão ativo' => 'UPDATE cartoes SET ativo = 0', 'sem a aba Cartão' => "UPDATE ajustes SET valor = 0 WHERE chave = 'usa_cartao'"] as $caso => $sql) {
        $pdo->exec($sql);
        MotorFinanceiro::limparCache();
        $d = MotorFinanceiro::dados();
        $igual = true;
        foreach ($d['real'] as $m => $porCat) {
            foreach ($porCat as $c => $v) {
                if ($d['cats'][$c]['operacional'] === 1 && $d['cats'][$c]['sinal'] === -1 && abs($v) >= 0.005) {
                    $igual = $igual && abs((GastoPorCategoria::doMes((string) $m)[$c] ?? 0.0) - round($v, 2)) < 0.001;
                }
            }
        }
        $hIni = $render(DashboardController::class, 'index', []);
        $_COOKIE[DashboardController::COOKIE_ONDE] = 'fatura';
        $hIniFat = $render(DashboardController::class, 'index', []);
        unset($_COOKIE[DashboardController::COOKIE_ONDE]);
        $hMeta = $render(MetaController::class, 'index', []);
        $check("gasto por categoria {$caso}: é o extrato, e Início e Metas não mudam", !GastoPorCategoria::ativo() && $igual && $hIni === $hIniFat
            && !str_contains($hIni, 'Ver por fatura') && !str_contains($hMeta, 'data da compra') && !RelatorioDados::mensal($mesHoje)['porCompra'],
            json_encode([GastoPorCategoria::ativo(), $igual, $hIni === $hIniFat]));
    }
});
// ---- 15b. o visual do cartão: estilo válido, recusa do inválido, sem estilo = Padrão (as cores do tema)
$check('visual do cartão: estilo da lista vale, vazio é o Padrão', CartaoVisual::validar('roxo') === [null, 'roxo']
    && CartaoVisual::validar('') === [null, null] && CartaoVisual::validar('grafite-dourado', '#123456') === [null, 'grafite-dourado']);
$check('visual do cartão: estilo fora da lista, lixo e cor inválida são recusados', CartaoVisual::validar('dourado-de-marca')[0] !== null
    && CartaoVisual::validar(['roxo'])[0] !== null && CartaoVisual::validar('propria', 'red')[0] !== null
    && CartaoVisual::validar('propria', '#12345')[0] !== null && CartaoVisual::validar('#123456')[0] !== null);
$check('visual do cartão: a cor própria fica como #rrggbb', CartaoVisual::validar('propria', ' #A1B2C3 ') === [null, '#a1b2c3']);
$emTransacao('visual do cartão', static function () use ($pdo, $check, $render): void {
    $pdo->exec('UPDATE cartoes SET ativo = 0');
    $idV = CartaoModel::salvar(null, 'Cartão visual', 25, 5, null, null, 'roxo');
    $htmlV = $render(CartaoController::class, 'index', []);
    $check('visual do cartão: o estilo salvo volta e pinta o cartão desenhado', CartaoModel::achar($idV)['visual'] === 'roxo'
        && str_contains($htmlV, 'class="ct-plastico" data-visual="roxo"') && str_contains($htmlV, 'name="visual" value="roxo" checked'));
    CartaoModel::salvar($idV, 'Cartão visual', 25, 5, null, null, null);
    $htmlP = $render(CartaoController::class, 'index', []);
    $check('visual do cartão: sem estilo, o cartão fica com as cores do tema', CartaoModel::achar($idV)['visual'] === null
        && preg_match('#class="ct-plastico"(?! data-visual)#', $htmlP) === 1 && str_contains($htmlP, 'name="visual" value="" checked'));
    CartaoModel::salvar($idV, 'Cartão visual', 25, 5, null, null, '#2f6fdf');
    $htmlC = $render(CartaoController::class, 'index', []);
    $check('visual do cartão: a cor própria pinta o cartão e marca "Cor própria"', str_contains($htmlC, 'class="ct-plastico" data-visual="propria" style="--ct-a:')
        && str_contains($htmlC, 'name="visual" value="propria" checked') && str_contains($htmlC, 'name="visual_cor" value="#2f6fdf"'));
    $pdo->prepare('UPDATE cartoes SET visual = ? WHERE id = ?')->execute(['estilo-velho', $idV]);
    $check('visual do cartão: um estilo que não existe mais vira o Padrão', CartaoModel::achar($idV)['visual'] === null);
});
// as paletas de cartao.css: toda chave tem a sua, e o texto tem contraste AA sobre as três cores, com e sem o brilho
$cssCt = (string) file_get_contents(__DIR__ . '/../public/assets/css/cartao.css');
$semPaleta = $fracos = [];
foreach (CartaoVisual::ESTILOS as $chaveV) {
    if (!preg_match('/^\[data-visual="' . preg_quote($chaveV, '/') . '"\]\s*\{([^}]*)\}/m', $cssCt, $mV)) {
        $semPaleta[] = $chaveV;
        continue;
    }
    preg_match_all('/--(ct-[a-z0-9-]+):\s*([^;]+);/', $mV[1], $vs);
    $vV = array_combine($vs[1], array_map('trim', $vs[2]));
    $brilho = isset($vV['ct-brilho']) ? (float) $vV['ct-brilho'] : CartaoVisual::BRILHO;
    foreach (['ct-a', 'ct-m', 'ct-b'] as $fundoK) {
        $fundo = CartaoVisual::rgb($vV[$fundoK] ?? '#000000');
        $comBrilho = CartaoVisual::hex(CartaoVisual::misturar($fundo, [255, 255, 255], $brilho));
        foreach (['ct-txt', 'ct-txt-2'] as $txtK) {
            $menor = min(CartaoVisual::contraste($vV[$txtK] ?? '#000000', CartaoVisual::hex($fundo)), CartaoVisual::contraste($vV[$txtK] ?? '#000000', $comBrilho));
            if ($menor < 4.5) {
                $fracos[] = $chaveV . ' ' . $txtK . '/' . $fundoK . ' ' . round($menor, 2);
            }
        }
    }
}
$check('visual do cartão: todo estilo tem a sua paleta em cartao.css', $semPaleta === [] && count(CartaoVisual::nomes()) === count(CartaoVisual::ESTILOS) + 1, implode(', ', $semPaleta));
$check('visual do cartão: o texto de todo estilo tem contraste AA, com e sem o brilho', $fracos === [], implode(' | ', $fracos));
$fracosP = [];
for ($cV = 0; $cV < 4096; $cV += 37) {
    $hexV = sprintf('#%x%x%x%x%x%x', $cV >> 8, $cV >> 8, ($cV >> 4) & 15, ($cV >> 4) & 15, $cV & 15, $cV & 15);
    $pV = CartaoVisual::corPropria($hexV);
    $menor = min(CartaoVisual::piorContraste($pV['txt'], CartaoVisual::rgb($pV['a'])), CartaoVisual::piorContraste($pV['txt'], CartaoVisual::rgb($pV['b'])));
    if ($menor < 4.5) {
        $fracosP[] = $hexV . ' ' . round($menor, 2);
    }
}
$check('visual do cartão: qualquer cor própria fica com texto legível (AA)', $fracosP === [], implode(' | ', array_slice($fracosP, 0, 5)));

$htmlBar = $render(MesController::class, 'index', []);
preg_match('#<nav class="tabbar".*?<details#s', $htmlBar, $mB);
$check('barra do celular: Início, Extrato, +, Mês e Mais; Cartão, Metas e Relatório no Mais', !str_contains($mB[0] ?? '', 'href="/cartao"')
    && !str_contains($mB[0] ?? '', 'href="/meta"') && str_contains($htmlBar, 'class="menu-item" href="/meta"') && str_contains($htmlBar, 'class="menu-item" href="/cartao"')
    && str_contains($htmlBar, 'class="lat-i" href="/cartao"') && str_contains($htmlBar, '<span>Mais</span>'));

// ---- 16. aba Empréstimo: a tela, os dados e o adiantamento
$antesEmp = (int) $pdo->query('SELECT COUNT(*) FROM lancamentos')->fetchColumn();
$htmlEmp = $render(EmprestimoController::class, 'index', []);
$check('empréstimo: a aba abre, com o menu do M e fora da barra', str_contains($htmlEmp, '<h1>Empréstimo</h1>') && str_contains($htmlEmp, 'class="menu-item is-active" href="/emprestimo"')
    && !preg_match('#<nav class="tabbar"(?:(?!<details).)*?href="/emprestimo"#s', $htmlEmp));
$check('empréstimo: a linha do tempo diz quantas parcelas faltam e dá nome a cada fim', preg_match('~Faltam \d+ parcelas~', $htmlEmp) === 1
    && str_contains($htmlEmp, 'Fim das parcelas') && (!str_contains($htmlEmp, 'emp-t-plano') || str_contains($htmlEmp, 'Quitação prevista pelo plano'))
    && !str_contains($htmlEmp, 'class="saldo '));
$htmlErro = $render(EmprestimoController::class, 'index', ['valor' => '12a', 'parcial' => '1']);
$check('empréstimo: valor inválido diz como corrigir', str_contains($htmlErro, 'Valor inválido') && !str_contains($htmlEmp, 'Empréstimo quitado'));
$check('empréstimo: o pedaço do resultado vem sem o layout', !str_contains($htmlErro, '<html'));
$htmlSimE = $render(EmprestimoController::class, 'index', ['valor' => '100', 'mes' => '']);
$check('empréstimo: simular não preenche o Valor pago', str_contains($htmlEmp, 'Simular quitar tudo') && !str_contains($htmlEmp, 'Quitar tudo')
    && preg_match('#id="emp-pago"[^>]*value=""#', $htmlSimE) === 1 && preg_match('#id="emp-consolidar"(?! open)#', $htmlSimE) === 1);
$htmlPagoE = $render(EmprestimoController::class, 'index', ['valor' => '100', 'pago' => '100,00']);
$check('empréstimo: só o "Já paguei" leva o valor', preg_match('#id="emp-pago"[^>]*value="100,00"#', $htmlPagoE) === 1 && str_contains($htmlPagoE, 'id="emp-consolidar" open'));
$htmlOk = $render(EmprestimoController::class, 'index', ['valor' => '100,00']);
$check('empréstimo: simular mostra o resultado e não grava nada', str_contains($htmlOk, 'Falta depois')
    && (int) $pdo->query('SELECT COUNT(*) FROM lancamentos')->fetchColumn() === $antesEmp);
$htmlAj = $render(AjustesController::class, 'index', []);
$check('dados do empréstimo: saíram de Ajustes', !str_contains($htmlAj, 'valores[juros_mes]') && !str_contains($htmlAj, 'valores[parcela_mensal]')
    && !str_contains($htmlAj, 'valores[n_parcelas]') && !str_contains($htmlAj, 'valores[consignado_inicio]') && !str_contains($htmlAj, 'valores[recebido_emprestimo]')
    && str_contains($htmlAj, 'valores[reserva_minima]') && str_contains($htmlAj, 'valores[saldo_ini]') && !str_contains($htmlAj, 'agora ficam'));
$check('dados do empréstimo: estão na aba, mesmo com a dívida quitada', str_contains($htmlEmp, 'action="/emprestimo/dados"') && str_contains($htmlEmp, 'valores[juros_mes]') && str_contains($htmlEmp, 'valores[saldo_devedor]') && str_contains($htmlEmp, 'valores[saldo_devedor_mes]')
    && str_contains($htmlEmp, 'valores[parcela_mensal]') && str_contains($htmlEmp, 'valores[n_parcelas]') && str_contains($htmlEmp, 'valores[consignado_inicio]') && str_contains($htmlEmp, 'valores[recebido_emprestimo]'));
$check('consolidar: valor inválido, data futura e data fora do plano são recusados; o resto passa',
    EmprestimoController::validarAdiantamento('12a', $hoje, $hoje)[0] !== null
    && EmprestimoController::validarAdiantamento('0', $hoje, $hoje)[0] !== null
    && str_contains((string) EmprestimoController::validarAdiantamento('100', date('Y-m-d', strtotime($hoje . ' +1 day')), $hoje)[0], 'depois de hoje')
    && str_contains((string) EmprestimoController::validarAdiantamento('100', '2000-01-01', $hoje)[0], 'A data é antes do início')
    // C-15: a frase da data é uma só, sem outra dentro de parênteses
    && EmprestimoController::validarAdiantamento('100', '2026-02-31', $hoje)[0] === 'Escolha uma data válida.'
    && EmprestimoController::validarAdiantamento('100', 'ontem', $hoje)[0] !== null
    && EmprestimoController::validarAdiantamento('1.250,50', $hoje, $hoje) === [null, 1250.5]);
$catAmortT = MotorFinanceiro::dados()['esp']['amort'];
$refT = MesModel::referencia($hoje);
$faltaAntesT = EmprestimoSimulacao::dividaNoMes(MotorFinanceiro::dados(), $refT, $hoje);
$pdo->beginTransaction();
try {
    $saldoAntesT = (float) MotorFinanceiro::calcular(MotorFinanceiro::dados(), ['v2' => true, 'objetivo' => 3])[$refT]['fica_na_conta'];
    LancamentoModel::criar($hoje, 'Adiantamento de teste', (int) $catAmortT, 100.0, (int) $pdo->query('SELECT MIN(id) FROM usuarios')->fetchColumn());
    MotorFinanceiro::limparCache();
    $dT = MotorFinanceiro::dados();
    $check('consolidar: o lançamento abate a dívida e sai da conta', abs(EmprestimoSimulacao::dividaNoMes($dT, $refT, $hoje) - $faltaAntesT + 100.0) < 0.01
        && abs((float) MotorFinanceiro::calcular($dT, ['v2' => true, 'objetivo' => 3])[$refT]['fica_na_conta'] - $saldoAntesT + 100.0) < 0.01);
    $htmlT = $render(EmprestimoController::class, 'index', []);
    $htmlEx = $render(LancamentosController::class, 'index', ['mes' => $refT, 'cat' => (string) $catAmortT]);
    $check('consolidar: aparece em Adiantamentos feitos e no Extrato', str_contains($htmlT, 'Adiantamentos feitos') && str_contains($htmlEx, 'Adiantamento de teste'));
    $check('consolidar: com adiantamento lançado, não compara o fim com o contrato', !str_contains($htmlT, 'Pelo contrato'));
} finally {
    $pdo->rollBack();
    MotorFinanceiro::limparCache();
}

// ---- 17. wishlist: itens em transação
$pngT = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
$fotoT = LinkProduto::fotoValida($pngT);
$pdo->beginTransaction();
try {
    $uT = (int) $pdo->query('SELECT MIN(id) FROM usuarios')->fetchColumn();
    // cotação fresca e conhecida: os testes não vão à rede
    $pdo->exec("REPLACE INTO cotacoes (moeda, valor, data, buscado_em) VALUES ('BRL', 1, " . Sql::hoje() . ', ' . Sql::agora() . "), ('USD', 5, '2030-01-02', " . Sql::agora() . ')');
    $pdo->exec("DELETE FROM cotacoes WHERE moeda NOT IN ('BRL', 'USD')");
    $ini = count(WishlistModel::ativos());
    $a = WishlistModel::criar('<b>Item A</b>', 'https://loja.exemplo/a', 1000.0, $uT);
    $b = WishlistModel::criar('Item B', null, null, $uT);
    $c = WishlistModel::criar('Item C', null, 50.0, $uT);
    WishlistModel::salvarFoto($a, $fotoT);
    $check('wishlist: foto volta igual do banco', WishlistModel::foto($a)['dados'] === $pngT && WishlistModel::foto($b) === null);
    WishlistModel::mover($c, 'primeiro');
    $check('wishlist: colocar em 1º', (int) WishlistModel::ativos()[0]['id'] === $c);
    $ordemWl = static fn(): array => array_map(static fn(array $i): int => (int) $i['id'], WishlistModel::ativos());
    WishlistModel::mover($c, 'descer');
    $check('wishlist: descer', array_search($c, $ordemWl(), true) === 1, json_encode($ordemWl()));
    $antesB = array_search($b, $ordemWl(), true);
    WishlistModel::mover($b, 'subir');
    $check('wishlist: subir', array_search($b, $ordemWl(), true) === $antesB - 1, json_encode($ordemWl()));
    WishlistModel::mover($a, 'primeiro');
    $htmlW = $render(WishlistController::class, 'index', []);
    $itemUsd = WishlistModel::criar('Item em dolar', null, 100.0, $uT, 'USD');
    $itemEur = WishlistModel::criar('Item em euro', null, 100.0, $uT, 'EUR');
    WishlistModel::mover($itemUsd, 'primeiro');
    $htmlUsd = $render(WishlistController::class, 'index', []);
    $check('wishlist: fora de real mostra o original, a conversão e a data', str_contains($htmlUsd, 'US$' . NBSP . '100,00') && str_contains($htmlUsd, money(500.0) . ', cotação de 02/01'));
    $check('wishlist: sem cotação avisa em vez de inventar', str_contains($htmlUsd, 'Sem cotação agora'));
    $check('wishlist: a previsão usa o valor em reais', str_contains($htmlUsd, 'O valor em reais muda com a cotação'));
    $check('wishlist: o formulário oferece as moedas', str_contains($htmlUsd, 'name="moeda"') && str_contains($htmlUsd, 'value="EUR"'));
    WishlistModel::mover($itemEur, 'primeiro');
    $check('wishlist: 1º sem cotação não tem previsão', str_contains($render(WishlistController::class, 'index', []), 'Sem cotação de EUR'));
    WishlistModel::excluir($itemUsd);
    WishlistModel::excluir($itemEur);
    WishlistModel::mover($a, 'primeiro');
    $check('wishlist: a aba abre, com o 1º em destaque, previsão e fora da barra', str_contains($htmlW, '<h1>Wishlist</h1>') && str_contains($htmlW, 'wl-vitrine') && str_contains($htmlW, 'Quando comprar')
        && str_contains($htmlW, 'class="menu-item is-active" href="/wishlist"') && !preg_match('#<nav class="tabbar"(?:(?!<details).)*?href="/wishlist"#s', $htmlW));
    $check('wishlist: os outros itens vêm numa grade, com foto quadrada, preço e o lugar na fila', str_contains($htmlW, 'class="wl-lista"') && str_contains($htmlW, 'Depois do 1º da lista')
        && !str_contains($htmlW, 'card wl-novo'));
    $ativosWl = array_map(static fn(array $i): int => (int) $i['id'], WishlistModel::ativos());
    $pdo->exec('UPDATE wishlist_itens SET comprado_em = ' . $pdo->quote($hoje) . ' WHERE comprado_em IS NULL');
    $htmlWv = $render(WishlistController::class, 'index', []);
    $pdo->exec('UPDATE wishlist_itens SET comprado_em = NULL WHERE id IN (' . implode(',', $ativosWl) . ')');
    $check('wishlist: vazia, pede o link com o campo ali mesmo', str_contains($htmlWv, 'Cole o link de algo que você quer comprar') && str_contains($htmlWv, 'data-wl-url'));
    $check('wishlist: texto da loja é escapado', str_contains($htmlW, '&lt;b&gt;Item A&lt;/b&gt;') && !str_contains($htmlW, '<b>Item A</b>'));
    $check('wishlist: foto não vem como link externo', !preg_match('#<img[^>]+src="https?://#', $htmlW));
    $check('wishlist: a previsão parcial vem sem layout', !str_contains($render(WishlistController::class, 'index', ['parcial' => '1', 'parcelas' => '3']), '<html'));
    WishlistModel::marcarComprado($a, $hoje);
    $check('wishlist: comprado sai da lista e vai para Compradas', count(WishlistModel::ativos()) === $ini + 2 && (int) WishlistModel::comprados()[0]['id'] === $a);
    WishlistModel::devolver($a);
    WishlistModel::excluir($b);
    $check('wishlist: excluído some, e desfazer traz de volta', WishlistModel::find($b) === null);
    WishlistModel::restaurar($b);
    $check('wishlist: restaurado volta com a foto preservada', WishlistModel::find($b) !== null && WishlistModel::foto($a) !== null);
    ob_start();
    (new WishlistController())->editar((string) $a);
    $htmlE = (string) ob_get_clean();
    $check('wishlist: editar abre preenchido', str_contains($htmlE, 'action="/wishlist/' . $a . '"') && str_contains($htmlE, 'loja.exemplo/a'));
} finally {
    $pdo->rollBack();
}

// ---- 21. fechar e Mês: confirmar, nada ligado sozinho, botão à vista
$htmlFe = $render(MesController::class, 'fecharForm', []);
$check('fechar: "Aplicar" vem desligado', preg_match('#name="aplicar" value="1"(?! checked)#', $htmlFe) === 1);
// dia 22, faltam 8 dias; no último dia, nenhum
$faltamFe = (int) date('t', strtotime($hoje)) - (int) date('j', strtotime($hoje));
// antes do último dia, o botão fica desligado e diz o motivo; no último dia, liga e pede confirmação
$check('fechar: abre com a frase; antes do fim do mês o botão fica desligado com o motivo, depois confirma', str_contains($htmlFe, 'fe-intro')
    && str_contains($htmlFe, 'data-confirm="Fechar ' . monthLabel($mesHoje) . '?')
    && ($faltamFe === 0
        ? preg_match('#<button type="submit" class="btn btn-principal fe-botao" data-fechar>#', $htmlFe) === 1
        : str_contains($htmlFe, 'disabled aria-describedby="fe-quando-t"') && str_contains($htmlFe, $faltamFe === 1 ? 'Ainda falta 1 dia' : "Ainda faltam {$faltamFe} dias")), (string) $faltamFe);
$htmlMesCal = $render(MesController::class, 'index', ['mes' => $mesHoje]);
$check('Mês: Fechar é um botão à vista, fora do menu "…"; antes do último dia, desligado e com o motivo', !str_contains($htmlMesCal, 'class="menu-item" href="/mes/fechar')
    && ($faltamFe === 0
        ? str_contains($htmlMesCal, 'class="btn btn-principal" href="/mes/fechar?mes=' . $mesHoje . '" data-fechar')
        : str_contains($htmlMesCal, 'aria-disabled="true" aria-describedby="mes-fechar-quando" data-fechar') && !str_contains($htmlMesCal, 'href="/mes/fechar?mes=' . $mesHoje . '"')));
$emTransacao('início com o mês fechado antes do fim', static function () use ($mesHoje, $render, $check): void {
    MesModel::setSituacao($mesHoje, 'REAL');
    MotorFinanceiro::limparCache();
    $h = $render(DashboardController::class, 'index', []);
    $check('Início: o mês do calendário fechado continua sendo o mês mostrado', str_contains($h, mb_convert_case(mesExtenso($mesHoje), MB_CASE_TITLE) . ' até hoje') && !str_contains($h, ' previsto</h2>'));
    MesModel::setSituacao($mesHoje, 'PREVISAO');
    MotorFinanceiro::limparCache();
});

// ---- 22. primeira configuração: o que usa antes das contas; ajuda do aviso sem login
$passoComecar = static function (int $passo) use ($hoje): string {
    ob_start();
    \Financeiro\View::renderAuth('comecar/index', ['passo' => $passo, 'total' => 4, 'hoje' => $hoje, 'inicio' => $hoje, 'contas' => [['id' => 1, 'nome' => 'Luz']], 'usos' => [], 'erro' => null]);
    return (string) ob_get_clean();
};
$check('começar: o 3º passo pergunta o que usa, e a parcela só com empréstimo', str_contains($passoComecar(3), 'name="usa_cartao"') && str_contains($passoComecar(3), 'com-grupo com-so-emp') && !str_contains($passoComecar(3), 'salário'));
$check('começar: o 4º passo pede as contas fixas', str_contains($passoComecar(4), 'name="valor[1]"') && !str_contains($passoComecar(4), 'name="usa_cartao"'));
$check('começar: o 4º passo deixa adicionar outra conta fixa e não mostra a regra interna do início',
    str_contains($passoComecar(4), 'name="nova_nome[]"') && str_contains($passoComecar(4), 'data-com-mais')
    && preg_match('~antes do dia \d~', $passoComecar(4) . $passoComecar(2)) === 0);
$check('começar: a prévia do mês fica ao lado da pergunta, e a sobra só aparece com o saldo informado',
    str_contains($passoComecar(1), 'data-previa') && str_contains($passoComecar(1), 'a responder')
    && preg_match('~data-previa-total><span class="com-falta">~', $passoComecar(1)) === 1);
ob_start();
(new \Financeiro\Controllers\AjudaController())->conexao();
$htmlCon = (string) ob_get_clean();
$check('ajuda: o aviso de conexão abre sem o layout', str_contains($htmlCon, 'href="/login"') && !str_contains($htmlCon, 'class="tabbar"'));
ob_start();
(new \Financeiro\Controllers\AjudaController())->privacidade();
$htmlPriv = (string) ob_get_clean();
$check('privacidade: abre sem o layout e diz o que sai do computador', !str_contains($htmlPriv, 'class="tabbar"')
    && str_contains($htmlPriv, 'economia.awesomeapi.com.br') && str_contains($htmlPriv, 'api.frankfurter.dev') && str_contains($htmlPriv, 'github.com')
    && str_contains($htmlPriv, 'AGPL-3.0') && str_contains($htmlPriv, '/issues') && str_contains($htmlPriv, 'SECURITY.md'));
$check('privacidade: os sites citados são os mesmos do SEGURANCA.md', (static function (): bool {
    $doc = (string) file_get_contents(__DIR__ . '/../docs/SEGURANCA.md');
    return str_contains($doc, 'economia.awesomeapi.com.br') && str_contains($doc, 'api.frankfurter.dev');
})());
$check('robots.txt tira o app dos buscadores', preg_match('~^User-agent: \*\R+Disallow: /$~m', (string) file_get_contents(__DIR__ . '/../public/robots.txt')) === 1);

// ---- 24. fuso: a conexão anda junto
$moedaAntes = Site::moeda();
$fusoAntes = Site::fuso();
// o relógio do banco, em minutos em relação ao UTC: a hora que ele mostra, contra o instante do relógio do app
$minutosDoFuso = static fn(): int => (int) round(((new DateTimeImmutable((string) $pdo->query('SELECT ' . Sql::agora())->fetchColumn(), new DateTimeZone('UTC')))->getTimestamp()
    - Relogio::agora()->getTimestamp()) / 60);
$pdo->beginTransaction();
$pdo->exec("DELETE FROM ajustes WHERE chave IN ('moeda', 'fuso')");
Site::carregar($pdo);
$check('sem os ajustes novos: real e Brasília', Site::moeda() === 'BRL' && Site::fuso() === 'America/Sao_Paulo' && date_default_timezone_get() === 'America/Sao_Paulo');
$check('sem os ajustes novos: a conexão fica em Brasília', $minutosDoFuso() === -180);
$check('sem os ajustes novos: o dinheiro sai igual', money(1234.56) === 'R$' . NBSP . '1.234,56');
foreach (['en', 'es'] as $idT) {
    Idioma::usar($idT);
    $check("conta em {$idT} sem os ajustes novos: real e Brasília", moedaSimbolo() === 'R$' && Site::fuso() === 'America/Sao_Paulo');
}
Idioma::usar('pt');
Site::salvarMoeda('EUR');
Site::salvarFuso('Asia/Kolkata');
Site::carregar($pdo);
$check('com os ajustes: moeda e fuso voltam do banco', Site::moeda() === 'EUR' && Site::fuso() === 'Asia/Kolkata' && date_default_timezone_get() === 'Asia/Kolkata');
$check('com os ajustes: a conexão anda junto', $minutosDoFuso() === 330);
Site::salvarFuso('America/New_York');
Site::carregar($pdo);
$check('com os ajustes: horário de verão na conexão', $minutosDoFuso() === (int) ((new DateTimeZone('America/New_York'))->getOffset(Relogio::agora()) / 60));
$pdo->rollBack();
Site::carregar($pdo);
Site::usarMoeda('BRL');
$check('a moeda e o fuso voltam ao que eram', Site::fuso() === $fusoAntes && $moedaAntes !== '');

// a tela em en e es não mostra categoria de fábrica em português (o formulário de lançar fica guardado desde o 1º uso nesta requisição; ele é conferido no navegador)
foreach (['en', 'es'] as $idT) {
    Idioma::usar($idT);
    foreach ([[DashboardController::class, '/'], [MesController::class, '/mes'], [MetaController::class, '/meta'], [AjustesController::class, '/ajustes']] as [$clsT, $pathT]) {
        $tela = strip_tags((string) preg_replace('/<(script|style|template|datalist|dialog)\b.*?<\/\1>/s', '', $pagina($clsT, 'index', $pathT)));
        $vistas = array_filter(['Aluguel e condomínio', 'Seguro do carro', 'Licenciamento do carro', 'Imposto de renda', 'Combustível', 'Mercado'], static fn(string $n): bool => str_contains($tela, $n));
        $check("telas em {$idT}: categorias traduzidas em {$pathT}", $vistas === [], $vistas === [] ? '' : mb_substr($tela, max(0, (int) mb_strpos($tela, (string) reset($vistas)) - 80), 200));
    }
}
Idioma::usar('pt');

// "Tenho carro" e a fatura fora das contas fixas
$pdo->beginTransaction();
$ativoDe = static fn(string $n): int => (int) (CategoriaModel::porNome($n)['ativo'] ?? -1);
$usoId = (int) $pdo->query("SELECT id FROM categorias WHERE nome = 'Combustível'")->fetchColumn();
$pdo->prepare('UPDATE categorias SET ativo = 1 WHERE id = ?')->execute([$usoId]);    // conta nova sem carro: está desligada
$pdo->prepare('INSERT INTO previsao (categoria_id, mes, valor) VALUES (?, ?, 1) ' . Sql::aoRepetir('categoria_id, mes', ['valor' => '1']))->execute([$usoId, $mesHoje]);
$idRev = (int) $pdo->query("SELECT id FROM categorias WHERE nome = 'Revisão do carro'")->fetchColumn();
$pdo->prepare('DELETE FROM previsao WHERE categoria_id = ?')->execute([$idRev]);
$pdo->prepare('DELETE FROM contas_recorrentes WHERE categoria_id = ?')->execute([$idRev]);
CategoriaModel::marcarCarro(false);
$check('sem carro: a categoria em uso fica', $ativoDe('Combustível') === 1);
$check('sem carro: a categoria sem uso sai das listas, sem apagar', $ativoDe('Revisão do carro') === 0);
CategoriaModel::marcarCarro(true);
$check('com carro: as categorias voltam', $ativoDe('Revisão do carro') === 1 && $ativoDe('Combustível') === 1);
AjusteModel::setValor('usa_cartao', 0.0);
$check('sem cartão: a fatura não é oferecida nas contas fixas', array_filter(ContaRecorrenteModel::categoriasElegiveis(), static fn(array $c): bool => $c['grupo'] === 'Cartao') === []);
$check('sem cartão: a fatura sai das contas fixas', array_filter(ContaRecorrenteModel::ativas(), static fn(array $c): bool => $c['grupo'] === 'Cartao') === []);
AjusteModel::setValor('usa_cartao', 1.0);
$check('com cartão: a fatura é oferecida', array_filter(ContaRecorrenteModel::categoriasElegiveis(), static fn(array $c): bool => $c['grupo'] === 'Cartao') !== []);
$pdo->rollBack();

// ---- os dois bancos se comportam igual: acento, data, dinheiro e chaves
$pdo->beginTransaction();
try {
    $catB = (int) $pdo->query("SELECT id FROM categorias WHERE grupo = 'Dia a dia' AND ativo = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $insB = $pdo->prepare('INSERT INTO lancamentos (data, descricao, categoria_id, valor, usuario_id) VALUES (?, ?, ?, ?, ?)');
    $recusa = static function (array $linha) use ($insB): bool {
        try {
            $insB->execute($linha);
            return false;
        } catch (\PDOException) {
            return true;
        }
    };
    $insB->execute([$hoje, 'Café da manhã 100%', $catB, 12.30, $uid]);
    $idB = (int) $pdo->lastInsertId();
    $achou = static fn(string $q): bool => in_array($idB, array_map(static fn(array $l): int => (int) $l['id'], LancamentoModel::listar($mesHoje, ['q' => $q])), true);
    $check('busca: sem acento acha com acento', $achou('cafe'));
    $check('busca: sem caixa', $achou('CAFÉ DA'));
    $check('busca: com acento acha igual', $achou('manha'));
    $check('busca: % é literal', $achou('100%') && !$achou('10%0'));
    $check('busca: palavra que não existe', !$achou('cafeteria'));
    $st = $pdo->prepare('SELECT COUNT(*) FROM lancamentos WHERE id = ? AND descricao = ?');
    $st->execute([$idB, 'CAFE DA MANHA 100%']);
    $check('coluna de texto: igual sem acento e sem caixa', (int) $st->fetchColumn() === 1);
    $check('data inválida é recusada', $recusa(['2026-02-31', 'x', $catB, 1, $uid]) && $recusa(['nao-data', 'x', $catB, 1, $uid]));
    $check('valor que não é número é recusado', $recusa([$hoje, 'x', $catB, 'abc', $uid]));
    $check('valor zero ou negativo é recusado', $recusa([$hoje, 'x', $catB, 0, $uid]) && $recusa([$hoje, 'x', $catB, -1, $uid]));
    $check('texto maior que a coluna é recusado', $recusa([$hoje, str_repeat('x', 256), $catB, 1, $uid]));
    $check('chave estrangeira vale', $recusa([$hoje, 'x', 99999999, 1, $uid]));
    $lido = $pdo->prepare('SELECT valor FROM lancamentos WHERE id = ?');
    $centavos = static function (float|string $v) use ($insB, $pdo, $hoje, $catB, $uid, $lido): string {
        $insB->execute([$hoje, 'arredonda', $catB, $v, $uid]);
        $lido->execute([(int) $pdo->lastInsertId()]);
        return number_format((float) $lido->fetchColumn(), 2, '.', '');
    };
    $check('dinheiro: arredonda como DECIMAL', $centavos('1.005') === '1.01' && $centavos('2.675') === '2.68' && $centavos('0.125') === '0.13' && $centavos('10.994') === '10.99' && $centavos('999.995') === '1000.00', $centavos('1.005'));
    $check('dinheiro: valor em float', $centavos(1.005) === '1.01' && $centavos(33.335) === '33.34');
    $check('dinheiro: valor muito pequeno vira zero e é recusado', $recusa([$hoje, 'x', $catB, '0.001', $uid]));
    $pdo->prepare('DELETE FROM lancamentos WHERE data = ? AND categoria_id = ?')->execute(['2031-05-10', $catB]);
    foreach (['0.10', '0.20', '0.70', '1000000.01', '0.02'] as $vB) {
        $insB->execute(['2031-05-10', 'soma', $catB, $vB, $uid]);
    }
    $somaB = $pdo->prepare('SELECT SUM(valor) FROM lancamentos WHERE data = ? AND categoria_id = ?');
    $somaB->execute(['2031-05-10', $catB]);
    $check('dinheiro: soma exata no centavo', (float) $somaB->fetchColumn() === 1000001.03);
    $mesFmt = $pdo->prepare('SELECT ' . Sql::inicioMes('data') . ' AS m, ' . Sql::diaDoMes('data') . ' AS d, ' . Sql::mesSeguinte('data') . ' AS s FROM lancamentos WHERE data = ? LIMIT 1');
    $mesFmt->execute(['2031-05-10']);
    $linhaFmt = $mesFmt->fetch();
    $check('datas no SQL: início do mês, dia e mês seguinte', $linhaFmt['m'] === '2031-05-01' && (int) $linhaFmt['d'] === 10 && $linhaFmt['s'] === '2031-06-10');
    $check('ORDER BY posição na lista', (int) $pdo->query('SELECT ' . Sql::posicao("'b'", ['a', 'b']) . ' AS p')->fetchColumn() === 2);
    $check('agora e agora mais um intervalo', (int) $pdo->query('SELECT ' . Sql::agoraMais(1, 'DAY') . ' > ' . Sql::agora())->fetchColumn() === 1 && (int) $pdo->query('SELECT ' . Sql::agoraMais(-1, 'DAY') . ' < ' . Sql::agora())->fetchColumn() === 1);
    $check('segundos até uma hora marcada', abs((int) $pdo->query('SELECT ' . Sql::segundosAte(Sql::agoraMais(90, 'SECOND')))->fetchColumn() - 90) <= 1);
    $check('maior e menor de dois', (int) $pdo->query('SELECT ' . Sql::maior('2', '5'))->fetchColumn() === 5 && (int) $pdo->query('SELECT ' . Sql::menor('2', '5'))->fetchColumn() === 2);
    $pdo->exec("UPDATE ajustes SET atualizado_em = '2000-01-01 00:00:00' WHERE chave = 'saldo_ini'");
    $pdo->exec("UPDATE ajustes SET label = 'Outro rótulo' WHERE chave = 'saldo_ini'");
    $check('atualizado_em anda quando a linha muda', (string) $pdo->query("SELECT atualizado_em FROM ajustes WHERE chave = 'saldo_ini'")->fetchColumn() !== '2000-01-01 00:00:00');
    $pdo->exec("UPDATE ajustes SET atualizado_em = '2000-01-01 00:00:00' WHERE chave = 'saldo_ini'");
    $pdo->exec("UPDATE ajustes SET label = 'Outro rótulo' WHERE chave = 'saldo_ini'");
    $check('atualizado_em fica quando nada muda', (string) $pdo->query("SELECT atualizado_em FROM ajustes WHERE chave = 'saldo_ini'")->fetchColumn() === '2000-01-01 00:00:00');
    $binario = "\xFF\xD8\x00\x01'\"\\ \xC3\x28" . random_bytes(32);
    SiteIcone::salvar('image/png', $binario);
    $check('imagem: os bytes voltam iguais, com NUL e tudo', SiteIcone::arquivo()['dados'] === $binario);
    $check('trava nomeada: pega e solta', Sql::travar($pdo, 'financeiro_teste', 1));
    Sql::soltar($pdo, 'financeiro_teste');
    $check('tabela existe', Sql::tabelaExiste($pdo, 'lancamentos') && !Sql::tabelaExiste($pdo, 'nao_existe'));
    foreach ($pdo->query('SELECT COUNT(*) AS n FROM categorias') as $linhaB) {
        $pdo->exec("UPDATE ajustes SET confirmado = confirmado WHERE chave = 'nenhuma'");
        $check('ler e gravar na mesma volta do laço', (int) $linhaB['n'] > 0);
    }
} finally {
    $pdo->rollBack();
}

// ---- mês sem ?mes= depois de fechar antes da hora, e reabrir com o ajuste do mês seguinte
$mesesT = array_column(MesModel::todos(), 'situacao', 'mes');
$proxT = date('Y-m-01', strtotime($mesHoje . ' +1 month'));
$pdo->beginTransaction();
try {
    MesModel::setSituacao($mesHoje, 'REAL');
    MotorFinanceiro::limparCache();
    $htmlFechadoCedo = $render(MesController::class, 'index', []);
    preg_match('#<title>(.*?)</title>#s', $htmlFechadoCedo, $tituloT);
    $check('Mês sem ?mes=: fica no mês do calendário, mesmo fechado', str_contains(html_entity_decode($tituloT[1] ?? ''), monthLabel($mesHoje)), $tituloT[1] ?? '');
    $catT = (int) $pdo->query("SELECT id FROM categorias WHERE grupo = 'Dia a dia' ORDER BY id LIMIT 1")->fetchColumn();
    $uidT = (int) $pdo->query('SELECT MIN(id) FROM usuarios')->fetchColumn();
    $stValorT = $pdo->prepare('SELECT valor FROM previsao WHERE categoria_id = :c AND mes = :m');
    $stValorT->execute(['c' => $catT, 'm' => $proxT]);
    $antesT = $stValorT->fetchColumn();
    PrevisaoLote::gravar('fechar', [[$catT, $proxT, 1234.56]], $uidT);
    MesModel::setSituacao($mesHoje, 'PREVISAO');
    $rT = PrevisaoLote::desfazerAjusteDoFechamento($mesHoje, $uidT);
    $stValorT->execute(['c' => $catT, 'm' => $proxT]);
    $check('reabrir: desfaz o ajuste do mês seguinte', $rT !== null && $rT['mes'] === $proxT && $rT['restauradas'] === 1 && $stValorT->fetchColumn() === $antesT);
    $check('reabrir de novo: nada mais a desfazer', PrevisaoLote::desfazerAjusteDoFechamento($mesHoje, $uidT) === null);
} finally {
    $pdo->rollBack();
    MotorFinanceiro::limparCache();
}

// ---- 9c. Contas do mês: o botão Pago das contas fixas, no Início e no Extrato
$catPago = (int) $pdo->query("SELECT id FROM categorias WHERE grupo = 'Fixos' AND ativo = 1 ORDER BY id LIMIT 1")->fetchColumn();
$emTransacao('contas do mês: Pago', static function () use ($pdo, $check, $render, $catPago, $catDia, $mesHoje, $hoje, $uid): void {
    $noMes = static function (int $cat) use ($pdo, $mesHoje): array {
        $st = $pdo->prepare('SELECT id, data, valor FROM lancamentos WHERE categoria_id = ? AND data >= ? AND data <= ?');
        $st->execute([$cat, $mesHoje, date('Y-m-t', strtotime($mesHoje))]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    };
    $naLista = static function (int $cat): ?array {
        MotorFinanceiro::limparCache();
        foreach (ChecklistContas::fixasAPagar() as $c) {
            if ($c['categoria_id'] === $cat) {
                return $c;
            }
        }
        return null;
    };
    // o selo da conta no Mês (pago, venceu…): o Mês e as Contas do mês nunca discordam
    $seloMes = static function (int $cat) use ($render, $mesHoje): string {
        MotorFinanceiro::limparCache();
        $html = $render(MesController::class, 'index', ['mes' => $mesHoje]);
        return preg_match('/id="cat-' . $cat . '".*?mes-status-(\w+)/s', $html, $m) ? $m[1] : '';
    };
    // uma conta fixa sem nada lançado no mês, com previsão e vencimento no dia 5 (vencida no dia 22 e no dia 30)
    $pdo->prepare('DELETE FROM lancamentos WHERE categoria_id = ? AND data >= ?')->execute([$catPago, $mesHoje]);
    PrevisaoModel::setValor($catPago, $mesHoje, 321.09);
    ContaRecorrenteModel::upsert($catPago, 5);
    $item = $naLista($catPago);
    $check('contas do mês: a conta fixa sem pagamento aparece, com o previsto e o vencimento',
        $item !== null && abs($item['previsto'] - 321.09) < 0.001 && $item['vencimento'] === substr($mesHoje, 0, 8) . '05' && $item['atrasado'], json_encode($item));
    $check('contas do mês: no Mês, a mesma conta está como não paga', $seloMes($catPago) === 'venceu');
    $recorrentes = [];
    foreach (ChecklistContas::statusPorMes($mesHoje) as $st) {
        if ($st['grupo'] === 'Fixos' && in_array($st['status'], ['pendente', 'atrasado'], true)) {
            $recorrentes[] = $st['categoria_id'];
        }
    }
    $daLista = array_column(array_filter(ChecklistContas::fixasAPagar(), static fn(array $c): bool => $c['vencimento'] !== null), 'categoria_id');
    sort($recorrentes);
    sort($daLista);
    $check('contas do mês: as mesmas contas que o Mês diz que faltam pagar', $daLista === $recorrentes, json_encode([$daLista, $recorrentes]));
    MotorFinanceiro::limparCache();
    $htmlIni = $render(DashboardController::class, 'index', []);
    MotorFinanceiro::limparCache();
    $htmlExt = $render(LancamentosController::class, 'index', ['mes' => $mesHoje]);
    $botao = 'name="categoria_id" value="' . $catPago . '"';
    // o Extrato não repete a lista: só a linha discreta da conta vencida, que leva ao Mês
    $check('contas do mês: no Início, com o botão Pago e o outro valor; no Extrato, só a vencida', str_contains($htmlIni, 'id="contas-mes"') && !str_contains($htmlExt, 'id="contas-mes"')
        && str_contains($htmlExt, 'class="ext-vencida') && !str_contains($htmlExt, $botao)
        && str_contains($htmlIni, $botao) && str_contains($htmlIni, 'action="/lancamentos/pago"')
        && str_contains($htmlIni, 'data-categoria="' . $catPago . '"') && str_contains($htmlIni, 'venceu dia 5'));
    MotorFinanceiro::limparCache();
    $check('contas do mês: o Extrato com filtro não mostra o bloco', !str_contains($render(LancamentosController::class, 'index', ['mes' => $mesHoje, 'q' => 'x']), 'id="contas-mes"'));

    $r = LancamentosController::pagarConta($catPago, $uid);
    $lanc = $noMes($catPago);
    $check('Pago: lança o valor previsto na data de hoje', $r['erro'] === null && count($lanc) === 1 && $lanc[0]['data'] === $hoje
        && abs((float) $lanc[0]['valor'] - 321.09) < 0.001 && (int) $lanc[0]['id'] === $r['id'], json_encode([$r['erro'], $lanc]));
    $r2 = LancamentosController::pagarConta($catPago, $uid);
    $check('Pago: o segundo clique não lança de novo', $r2['erro'] !== null && count($noMes($catPago)) === 1);
    $check('Pago: a conta paga sai da lista', $naLista($catPago) === null);
    $check('Pago: no Mês, a conta aparece paga', $seloMes($catPago) === 'pago');
    LancamentoModel::excluir($r['id']);
    $check('Pago: desfazer apaga o lançamento e a conta volta à lista', $noMes($catPago) === [] && $naLista($catPago) !== null);

    // só conta fixa ativa, com previsão no mês e nada lançado
    $antesDia = count($noMes($catDia));
    $check('Pago: categoria que não é conta fixa é recusada', LancamentosController::pagarConta($catDia, $uid)['erro'] !== null && count($noMes($catDia)) === $antesDia);
    PrevisaoModel::setValor($catPago, $mesHoje, 0.0);
    MotorFinanceiro::limparCache();
    $check('Pago: conta sem valor previsto no mês é recusada', LancamentosController::pagarConta($catPago, $uid)['erro'] !== null && $noMes($catPago) === []);
    PrevisaoModel::setValor($catPago, $mesHoje, 321.09);
    $pdo->prepare('UPDATE categorias SET ativo = 0 WHERE id = ?')->execute([$catPago]);
    MotorFinanceiro::limparCache();
    $check('Pago: conta arquivada é recusada', LancamentosController::pagarConta($catPago, $uid)['erro'] !== null && $noMes($catPago) === []);
    $pdo->prepare('UPDATE categorias SET ativo = 1 WHERE id = ?')->execute([$catPago]);
    $pdo->prepare('INSERT INTO lancamentos (data, descricao, categoria_id, valor, usuario_id) VALUES (?, ?, ?, 100, ?)')->execute([$hoje, 'parte', $catPago, $uid]);
    MotorFinanceiro::limparCache();
    $check('Pago: conta com parte já paga fica de fora (o Mês pergunta se foi o valor final)', $naLista($catPago) === null && LancamentosController::pagarConta($catPago, $uid)['erro'] !== null && count($noMes($catPago)) === 1);
});

// ---- 9e. Fechar o mês só a partir do último dia (B-02): a tela e o POST usam MesModel::podeFechar
$check('fechar: só a partir do último dia do mês, o mesmo dia que a tela mostra', MesModel::podeFechar($mesHoje, $hoje) === ($hoje === date('Y-m-t', strtotime($hoje)))
    && MesModel::podeFechar(date('Y-m-01', strtotime($mesHoje . ' -1 month')), $hoje) && !MesModel::podeFechar(date('Y-m-01', strtotime($mesHoje . ' +1 month')), $hoje)
    && MesModel::fechaAPartirDe($mesHoje) === date('Y-m-t', strtotime($mesHoje)));
$htmlMesHoje = $render(MesController::class, 'index', ['mes' => $mesHoje]);
$check('fechar: o botão do Mês segue a mesma regra', str_contains($htmlMesHoje, 'href="/mes/fechar?mes=' . $mesHoje . '"') === MesModel::podeFechar($mesHoje, $hoje));

// ---- 9f. "Precisa de atenção" e Metas dizem o mesmo (B-03); onde o cartão muda o número, o rótulo diz (B-04)
$emTransacao('atenção e metas', static function () use ($check, $render, $mesHoje, $hoje): void {
    $passaram = static function () use ($render, $hoje): array {
        MotorFinanceiro::limparCache();
        preg_match_all('/<details class="mt-linha is-over" id="c(\d+)"/', $render(MetaController::class, 'index', []), $m);
        $metas = array_map('intval', $m[1]);
        $aten = [];
        foreach (\Financeiro\Services\Atencao::avisos($hoje) as $a) {
            if ($a['tipo'] === 'meta') {
                $aten[] = (int) substr((string) $a['href'], strlen('/meta#c'));
            }
        }
        sort($metas);
        sort($aten);
        return [$metas, $aten];
    };
    [$metas, $aten] = $passaram();
    $check('atenção: as categorias acima da meta são as mesmas das Metas, com o cartão contado igual', $metas !== [] && $metas === $aten, json_encode([$metas, $aten]));
    // a meta do mês (um valor só deste mês, como o editor de recorrência grava) vale nos dois
    PrevisaoModel::setValor($metas[0], $mesHoje, 99999.0);
    [$metas2, $aten2] = $passaram();
    $check('atenção: a meta do mês vale, e o aviso some junto com o vermelho das Metas', !in_array($metas[0], $aten2, true) && $metas2 === $aten2, json_encode([$metas2, $aten2]));

    // os números que contam o cartão dizem isso; o aviso do Lançar usa o número da tela para onde a pessoa volta
    $g = MetaController::gastosDoMes($mesHoje);
    $comCartao = array_keys(array_filter($g, static fn(array $x): bool => $x['cartao']));
    $htmlM = $render(MetaController::class, 'index', []);
    if (GastoPorCategoria::ativo() && $comCartao !== []) {
        $cat = CategoriaModel::find($comCartao[0]);
        PrevisaoModel::setValor($comCartao[0], $mesHoje, 99999.0);
        MotorFinanceiro::limparCache();
        $aviso = new \ReflectionMethod(LancamentosController::class, 'avisoLancado');
        $naMeta = (string) $aviso->invoke(null, $cat, 10.0, $hoje, '/meta?mes=' . $mesHoje);
        $noInicio = (string) $aviso->invoke(null, $cat, 10.0, $hoje, '/');
        $check('metas: com o cartão, o gasto e o que falta dizem "com o cartão"', str_contains($htmlM, 'Gastou, com as compras no cartão') && str_contains($htmlM, 'Gasto<small class="mt-cartao">com o cartão</small>')
            && preg_match('/id="c' . $comCartao[0] . '".*?<small class="mt-cartao">com o cartão<\/small><\/span>/s', $htmlM) === 1);
        $check('lançar: o aviso usa o número da tela para onde volta', str_contains($naMeta, num(round(99999.0 - $g[$comCartao[0]]['gasto'], 2)) . ' da meta, com o cartão')
            && str_contains($noInicio, num(round(99999.0 - LancamentoModel::somaPorCategoria($comCartao[0], $mesHoje), 2)) . ' da meta.'), $naMeta . ' | ' . $noInicio);
    } else {
        $check('metas: a demonstração tem compra no cartão contada nas Metas', false);
    }
});

// ---- 9g. Conferência com o banco: com a segunda conta, a de antes continua no Início, igual ao Relatório (B-06)
$emTransacao('conferência com várias contas', static function () use ($pdo, $check, $mesHoje, $hoje, $uid): void {
    $pdo->exec('DELETE FROM saldo_conferencias');
    $saldoP = ContaModel::saldo(ContaModel::PRINCIPAL, $hoje);
    \Financeiro\Models\SaldoConferenciaModel::registrar($hoje, round($saldoP + 11.06, 2), $uid);     // a do total, com uma conta só
    $antes = DashboardController::conferenciaAtual($hoje);
    ContaModel::salvar(null, 'Carteira B06', 'dinheiro', 300.0);
    MotorFinanceiro::limparCache();
    $depois = DashboardController::conferenciaAtual($hoje);
    $rel = (new \ReflectionMethod(RelatorioDados::class, 'conferencia'))->invoke(null, MotorFinanceiro::dados(), $mesHoje, $hoje);
    $check('conferência: com a segunda conta, a de antes continua, como a da conta principal', $antes !== null && $depois !== null && $depois['data'] === $hoje
        && abs($depois['dif'] - 11.06) < 0.005 && !$depois['bate'] && abs($antes['dif'] - $depois['dif']) < 0.005, json_encode([$antes, $depois]));
    $check('conferência: o Relatório diz o mesmo que o Início', $rel['tipo'] === 'dif' && str_contains($rel['texto'], money(11.06)), json_encode($rel));
    $check('conferência: e o "Precisa de atenção" também', in_array('conferencia', array_column(\Financeiro\Services\Atencao::avisos($hoje, $depois), 'tipo'), true));
});
ContaModel::limparCache();
LancarForm::limparCache();

// ---- 9h. Lançamento agendado no mês (B-07) e a soma do dia inteiro no Início (B-09)
$emTransacao('agendado e soma do dia', static function () use ($pdo, $check, $render, $catDia, $mesHoje, $hoje, $uid): void {
    $fecha = static function () use ($mesHoje, $hoje): array {
        MotorFinanceiro::limparCache();
        $linha = MotorFinanceiro::calcularPlano()[$mesHoje];
        $s = \Financeiro\Services\Sobra::ateHoje($mesHoje);
        $emConta = LancamentoModel::saldoCalculado(AjusteModel::valor('saldo_ini'), $hoje);
        $destino = \Financeiro\Services\Destino::doMes(MotorFinanceiro::dados(), $linha, $mesHoje);
        return ['saidas' => $s['saidas'], 'saiu' => $destino['saiu'], 'gastar' => $destino['gastar'],
                'fecha' => abs($linha['sobrou_mes_anterior'] + $s['sobra'] - $emConta) < 0.005];
    };
    $antes = $fecha();
    $check('agendado: as somas do Início fecham (em conta hoje = mês anterior + entrou − saiu)', $antes['fecha']);
    if ($hoje < date('Y-m-t', strtotime($hoje))) {
        $amanha = date('Y-m-d', strtotime($hoje . ' +1 day'));
        LancamentoModel::criar($amanha, 'agendado teste', $catDia, 21.0, $uid);
        $depois = $fecha();
        $tot = LancamentoModel::totais($mesHoje, []);
        $check('agendado: fica fora do Gastou e do Já saiu, e as somas continuam fechando', abs($depois['saidas'] - $antes['saidas']) < 0.005
            && abs($depois['saiu'] - $antes['saiu']) < 0.005 && $depois['fecha'], json_encode([$antes, $depois]));
        $check('agendado: o Extrato soma à parte', abs($tot['gasto_ag'] - 21.0) < 0.005);
        $htmlIni = $render(DashboardController::class, 'index', []);
        $check('agendado: no Início, com a marca, como no Extrato', str_contains($htmlIni, '(agendado)') && str_contains($htmlIni, '· agendado'));
    }
    // sete lançamentos hoje: o Início mostra 6, e a soma do dia é a dos sete
    $pdo->prepare('DELETE FROM lancamentos WHERE data = ?')->execute([$hoje]);
    for ($i = 1; $i <= 7; $i++) {
        LancamentoModel::criar($hoje, 'dia ' . $i, $catDia, 10.0 * $i, $uid);
    }
    MotorFinanceiro::limparCache();
    $htmlIni = $render(DashboardController::class, 'index', []);
    $check('Início: a soma de hoje é a do dia inteiro, não só a dos lançamentos à vista', preg_match('#<span>Hoje</span><span class="num">' . MENOS . '280,00</span>#u', $htmlIni) === 1);
});

// ---- 9h2. Agendado em toda conta: fora de todo realizado (Metas, "Para onde foi", Mês, Relatório, atenção, aviso), no
// previsto; as somas fecham e o mesmo nome dá o mesmo número no Início, nas Metas e no Relatório
$emTransacao('agendado em toda conta', static function () use ($pdo, $check, $render, $catDia, $mesHoje, $hoje, $uid): void {
    $foto = static function () use ($render, $mesHoje, $hoje): array {
        MotorFinanceiro::limparCache();
        $rel = RelatorioDados::mensal($mesHoje, $hoje);
        $ponte = array_column($rel['ponte'], 'valor', 'rotulo');
        $painel = (new \ReflectionMethod(DashboardController::class, 'painel'))->invoke(null, GastosMensais::matriz()[$mesHoje], $mesHoje, 'aberto', false, $hoje);
        return [
            'metas' => array_map(static fn(array $x): float => $x['gasto'], MetaController::gastosDoMes($mesHoje)),
            'metasProx' => array_map(static fn(array $x): float => $x['gasto'], MetaController::gastosDoMes(date('Y-m-01', strtotime($mesHoje . ' +1 month')))),
            'onde' => GastoPorCategoria::doMes($mesHoje),
            'mesReal' => preg_match_all('#<tr id="cat-(\d+)"[^>]*>.*?<td class="c-real">(.*?)</td>#s', $render(MesController::class, 'index', ['mes' => $mesHoje]), $mm) ? array_combine($mm[1], $mm[2]) : [],
            'relCats' => array_column($rel['categorias']['linhas'], 'real', 'id'), 'relTotal' => $rel['categorias']['totalReal'],
            'relGastou' => -(float) ($ponte['Gastou até agora'] ?? 0.0), 'relPonte' => $rel['ponte'],
            'inicioGastou' => \Financeiro\Services\Sobra::ateHoje($mesHoje)['saidas'],
            'inicioOnde' => array_column($painel['onde'], 'valor', 'id'),
            'metasAviso' => array_values(array_filter(array_map(static fn(array $a): string => $a['tipo'] === 'meta' ? $a['detalhe'] : '', \Financeiro\Services\Atencao::avisos($hoje)))),
            'fica' => (float) MotorFinanceiro::calcularPlano()[$mesHoje]['fica_na_conta'],
        ];
    };
    // uma conta fixa por pagar, para o agendado (sem nenhuma por pagar, a primeira com conta recorrente fica sem o pagamento do mês)
    if (\Financeiro\Services\ChecklistContas::fixasAPagar($hoje) === []) {
        foreach (\Financeiro\Models\ContaRecorrenteModel::ativas() as $cr) {
            if ((string) $cr['grupo'] === 'Fixos' && PrevisaoModel::valor((int) $cr['categoria_id'], $mesHoje) > 0) {
                $pdo->prepare('DELETE FROM lancamentos WHERE categoria_id = ? AND data >= ?')->execute([(int) $cr['categoria_id'], $mesHoje]);
                break;
            }
        }
        MotorFinanceiro::limparCache();
    }
    $antes = $foto();
    // o mesmo nome, o mesmo número: o "Gastou" do Início e do Relatório; o "Para onde foi" do Início, do Relatório e das Metas
    $mesmoOnde = true;
    foreach ($antes['relCats'] as $id => $v) {
        $mesmoOnde = $mesmoOnde && abs($v - (float) ($antes['inicioOnde'][$id] ?? 0.0)) < 0.005
            && ($id === GastoPorCategoria::SEM_CATEGORIA || $id === GastoPorCategoria::categoriaFatura() || abs($v - (float) ($antes['metas'][$id] ?? 0.0)) < 0.005);
    }
    $check('agendado: "Gastou" é o mesmo no Início e no Relatório, e o "Para onde foi" é o mesmo no Início, no Relatório e nas Metas',
        abs($antes['inicioGastou'] - $antes['relGastou']) < 0.005 && $mesmoOnde, json_encode([$antes['inicioGastou'], $antes['relGastou'], $antes['relCats'], $antes['inicioOnde']]));
    $check('agendado: a escada do Relatório fecha no fim do mês do plano', abs(array_sum(array_map(static fn(array $p): float => $p['tipo'] === 'fim' ? 0.0 : (float) $p['valor'], $antes['relPonte']))
        - $antes['fica']) < 0.01 && abs(end($antes['relPonte'])['valor'] - $antes['fica']) < 0.01);
    if ($hoje >= date('Y-m-t', strtotime($hoje))) {
        return;                                            // no último dia do mês, amanhã é o mês seguinte: o resto não se aplica
    }
    $amanha = date('Y-m-d', strtotime($hoje . ' +1 day'));
    $prox = date('Y-m-d', strtotime($mesHoje . ' +1 month +4 day'));
    LancamentoModel::criar($amanha, 'agendado D', $catDia, 7.0, $uid);
    LancamentoModel::criar($prox, 'agendado D mês seguinte', $catDia, 9.0, $uid);
    // a conta fixa ainda por pagar, paga com data de amanhã
    $fixa = \Financeiro\Services\ChecklistContas::fixasAPagar($hoje)[0] ?? null;
    $check('agendado: há uma conta fixa por pagar para o teste', $fixa !== null);
    if ($fixa !== null) {
        LancamentoModel::criar($amanha, 'conta agendada', (int) $fixa['categoria_id'], (float) $fixa['previsto'], $uid);
    }
    // a compra no cartão com data de amanhã
    $compra = GastoPorCategoria::ativo() ? CartaoController::criarCompraDe(['valor' => '13', 'categoria_id' => (string) $catDia, 'data' => $amanha, 'parcelas' => '1'], $hoje) : null;
    $depois = $foto();
    $check('agendado: fica fora do gasto das Metas (no mês e no seguinte) e do "Para onde foi", com a compra no cartão', $antes['metas'] == $depois['metas']
        && $antes['metasProx'] == $depois['metasProx'] && $antes['onde'] == $depois['onde'] && ($compra === null || $compra['erro'] === null), json_encode([$antes['onde'], $depois['onde'], $compra]));
    $check('agendado: fica fora da coluna Realizado do Mês', $antes['mesReal'] === $depois['mesReal'], json_encode(array_diff_assoc($depois['mesReal'], $antes['mesReal'])));
    $check('agendado: fica fora do Relatório ("Para onde foi" e "Gastou até agora") e do "Precisa de atenção"', $antes['relCats'] == $depois['relCats']
        && abs($antes['relTotal'] - $depois['relTotal']) < 0.005 && abs($antes['relGastou'] - $depois['relGastou']) < 0.005 && $antes['metasAviso'] === $depois['metasAviso']);
    $check('agendado: continua no previsto, e as somas fecham', abs($depois['inicioGastou'] - $depois['relGastou']) < 0.005
        && abs(array_sum(array_map(static fn(array $p): float => $p['tipo'] === 'fim' ? 0.0 : (float) $p['valor'], $depois['relPonte'])) - $depois['fica']) < 0.01
        && $depois['fica'] <= $antes['fica'] + 0.005);
    if ($fixa !== null) {
        $st = array_column(\Financeiro\Services\ChecklistContas::statusPorMes($mesHoje, $hoje), null, 'categoria_id')[(int) $fixa['categoria_id']] ?? [];
        $aPagar = array_column(\Financeiro\Services\ChecklistContas::aPagar($mesHoje, $hoje), 'falta', 'categoria_id');
        $avisos = array_column(\Financeiro\Services\Atencao::avisos($hoje), 'href');
        $htmlMes = $render(MesController::class, 'index', ['mes' => $mesHoje]);
        $linha = preg_match('#<tr id="cat-' . (int) $fixa['categoria_id'] . '".*?</tr>#s', $htmlMes, $mL) ? $mL[0] : '';
        $check('agendado: a conta paga com data de amanhã é "agendado", não "pago", e não pede pagamento', ($st['status'] ?? '') === 'agendado' && abs((float) $st['pago']) < 0.005
            && abs((float) ($aPagar[(int) $fixa['categoria_id']] ?? 0) - (float) $fixa['previsto']) < 0.005
            && !in_array('/lancar?' . http_build_query(['categoria' => (int) $fixa['categoria_id'], 'valor' => numInput((float) $fixa['previsto']), 'volta' => '/']), $avisos, true)
            && str_contains($linha, 'agendado dia ' . (int) substr($amanha, 8, 2)) && !str_contains($linha, 'btn-pagar') && !str_contains($linha, 'carimbo'), $linha);
    }
    // o aviso depois de lançar diz que é agendado, e o número da meta não muda
    $cat = CategoriaModel::find($catDia);
    $aviso = (string) (new \ReflectionMethod(LancamentosController::class, 'avisoLancado'))->invoke(null, $cat, 7.0, $amanha, '/');
    $prevDia = PrevisaoModel::valor($catDia, $mesHoje);
    $check('agendado: o aviso depois de lançar diz "agendado" e conta só o realizado', str_contains($aviso, 'agendado para ' . diaMes($amanha))
        && ($prevDia <= 0 || str_contains($aviso, num(abs(round($prevDia - (float) (MotorFinanceiro::realizado()['real'][$mesHoje][$catDia] ?? 0.0), 2))))), $aviso);
});

// a fatura paga com data depois de hoje ainda não pagou: a aba Cartão não libera o limite antes do dia (o agendado de toda conta)
$emTransacao('fatura com pagamento agendado', static function () use ($pdo, $check, $render, $hoje, $uid): void {
    $cartao = CartaoModel::ativos()[0] ?? null;
    $cat = CartaoModel::categoriaFatura();
    if ($cartao === null || $cat === null) {
        return;
    }
    $pdo->prepare('UPDATE cartoes SET limite = 20000 WHERE id = ?')->execute([$cartao['id']]);
    $livre = static function () use ($render): string {
        MotorFinanceiro::limparCache();
        return preg_match('#Livre <b>([^<]+)</b>#', $render(CartaoController::class, 'index', []), $m) ? $m[1] : '';
    };
    $antes = $livre();
    $id = LancamentoModel::criar(date('Y-m-d', strtotime($hoje . ' +3 day')), 'fatura agendada', $cat, 900.0, $uid);
    $aberta = CartaoFatura::faturaDe($hoje, $cartao['fecha'], $cartao['vence']);
    $pdo->prepare('UPDATE lancamentos SET cartao_id = ?, fatura = ? WHERE id = ?')->execute([$cartao['id'], $aberta, $id]);
    $depois = $livre();
    $check('fatura com pagamento agendado: a aba Cartão não libera o limite antes do dia', $antes !== '' && $depois === $antes, $antes . ' → ' . $depois);
});

// ---- 9h3. Acerto de saldo com o banco (s0070): a diferença lançada de uma vez, fora das categorias
$emTransacao('acerto de saldo', static function () use ($pdo, $check, $render, $mesHoje, $hoje, $uid): void {
    $pdo->exec('DELETE FROM saldo_conferencias');
    $saldoIni = AjusteModel::valor('saldo_ini');
    $conta = ContaModel::padrao();
    $plano0 = MotorFinanceiro::calcularPlano();
    $emConta0 = LancamentoModel::saldoCalculado($saldoIni, $hoje);
    $tot0 = LancamentoModel::totais($mesHoje, []);

    // uma diferença de centavos "bate" (até 0,50), mas a conferência oferece o acerto
    \Financeiro\Models\SaldoConferenciaModel::registrar($hoje, round($emConta0 - 0.37, 2), $uid);
    $conf = DashboardController::conferenciaAtual($hoje);
    $check('acerto: diferença de centavos bate, mas a conferência oferece o acerto', $conf !== null && $conf['bate']
        && abs((float) ($conf['acertos'][$conta]['valor'] ?? 0) + 0.37) < 0.005 && ($conf['acertos'][$conta]['data'] ?? '') === $hoje, json_encode($conf));
    $previa = AcertoController::previa([$conta => round($emConta0 - 0.37, 2)], $hoje);
    $check('acerto: a prévia do Extrato faz a mesma conta', abs((float) $previa[$conta]['acerto'] + 0.37) < 0.005 && $previa[$conta]['entra'] === $hoje, json_encode($previa));
    $check('acerto: sem diferença, nada a acertar', AcertoController::sugestao(0.004) === null && AcertoController::sugestao(-0.37) === -0.37
        && AcertoController::sugestao(25.0, 25.0) === null && AcertoController::sugestao(25.37, 25.0) === 0.37);

    $id = \Financeiro\Models\AcertoModel::criar(AcertoController::dataPara($hoje), $hoje, $conta, -0.37, '', $uid);
    MotorFinanceiro::limparCache();
    $plano1 = MotorFinanceiro::calcularPlano();
    $l0 = $plano0[$mesHoje];
    $l1 = $plano1[$mesHoje];
    [$e0, $s0] = MotorFinanceiro::balancoTotal($l0);
    [$e1, $s1] = MotorFinanceiro::balancoTotal($l1);
    $check('acerto: o saldo de hoje e o da conta mudam o valor do acerto', abs(LancamentoModel::saldoCalculado($saldoIni, $hoje) - $emConta0 + 0.37) < 0.005
        && abs(array_sum(ContaModel::saldos($hoje)) - LancamentoModel::saldoCalculado($saldoIni, $hoje)) < 0.005);
    // sem o adiantamento automático do empréstimo, que o plano ajusta para o mês fechar na reserva
    $semAuto = static fn(array $l): float => (float) $l['fica_na_conta'] + (float) $l['amortizacao_auto'];
    $check('acerto: o plano conta o acerto no mês, no balanço e no fim do mês', abs($l1['acerto'] + 0.37) < 0.005 && abs($semAuto($l1) - $semAuto($l0) + 0.37) < 0.005
        && abs(($s1 - $l1['amortizacao_auto']) - ($s0 - $l0['amortizacao_auto']) - 0.37) < 0.005 && abs($e1 - $e0) < 0.005
        && abs($l1['sobrou_mes_anterior'] - $l0['sobrou_mes_anterior']) < 0.005,
        json_encode([$l0['fica_na_conta'], $l1['fica_na_conta'], $l0['amortizacao_auto'], $l1['amortizacao_auto'], $s0, $s1]));
    $tot1 = LancamentoModel::totais($mesHoje, []);
    $check('acerto: não é renda nem gasto (Entrou, Gastou e o plano das categorias iguais)', abs($tot1['entrada'] - $tot0['entrada']) < 0.005 && abs($tot1['gasto'] - $tot0['gasto']) < 0.005
        && abs($l1['entra'] - $l0['entra']) < 0.005 && abs($l1['sai'] - $l0['sai']) < 0.005);
    $s = \Financeiro\Services\Sobra::ateHoje($mesHoje);
    $check('acerto: as somas do Início continuam fechando', abs($l1['sobrou_mes_anterior'] + $s['sobra'] - LancamentoModel::saldoCalculado($saldoIni, $hoje)) < 0.005,
        json_encode([$l1['sobrou_mes_anterior'], $s['sobra']]));
    $conf = DashboardController::conferenciaAtual($hoje);
    $check('acerto: depois dele, a conferência bate e não pede outro', $conf !== null && abs($conf['dif']) < 0.005 && $conf['acertos'] === [], json_encode($conf));

    $htmlE = $render(LancamentosController::class, 'index', ['mes' => $mesHoje]);
    $check('acerto: o Extrato mostra o acerto e o saldo que veio do mês anterior, sem coluna de acertos no resumo', str_contains($htmlE, 'href="/acertos/' . $id . '?')
        && str_contains($htmlE, 'Acerto com o banco') && !str_contains($htmlE, 'data-ex-total="acertos"') && str_contains($htmlE, 'data-ex-abertura')
        && str_contains($htmlE, 'data-ex-total="abertura"'));
    $vespera = date('Y-m-d', strtotime($mesHoje . ' -1 day'));
    $check('acerto: o saldo que veio do mês anterior é o da véspera', str_contains($htmlE, e(num(LancamentoModel::saldoCalculado($saldoIni, $vespera), 0))) || str_contains($htmlE, e(num(LancamentoModel::saldoCalculado($saldoIni, $vespera)))));
    $htmlF = $render(LancamentosController::class, 'index', ['mes' => $mesHoje, 'q' => 'zzz']);
    $check('acerto: com busca, o Extrato não mostra o saldo do mês anterior', !str_contains($htmlF, 'data-ex-abertura'));
    // o "Saldo" do resumo é o da conta (o que veio do mês anterior, mais o que entrou, menos o que saiu, com o acerto)
    $check('Extrato: o "Saldo" do mês de hoje é o saldo da conta hoje, com o acerto', str_contains($htmlE, '<dt>Saldo</dt><dd data-ex-total="saldo">'
        . valor(round(LancamentoModel::saldoCalculado($saldoIni, $hoje), 2)) . '<') && !str_contains($htmlE, 'Saldo do período'));
    $mesPassado = date('Y-m-01', strtotime($mesHoje . ' -1 month'));
    $htmlP = $render(LancamentosController::class, 'index', ['mes' => $mesPassado]);
    $check('Extrato: num mês que terminou, o "Saldo" é o do último dia', str_contains($htmlP, 'data-ex-total="saldo">'
        . valor(round(LancamentoModel::saldoCalculado($saldoIni, date('Y-m-t', strtotime($mesPassado))), 2)) . '<'));
    $check('Extrato: com busca, fica o "Saldo do período" (o que entrou menos o gasto)', !str_contains($htmlF, 'data-ex-total="saldo"')
        && (str_contains($htmlF, 'data-ex-total="soma"') || !str_contains($htmlF, 'ext-resumo')));
    $htmlM = $render(MesController::class, 'index', ['mes' => $mesHoje]);
    $check('acerto: o Mês mostra a linha do acerto e o livro fecha no fim do mês', str_contains($htmlM, 'data-acerto-saldo')
        && preg_match('/data-livro-fim="(-?[\d.]+)"/', $htmlM, $mm) === 1 && abs((float) $mm[1] - $l1['fica_na_conta']) < 0.01, $mm[1] ?? '');
    $ponte = (new \ReflectionMethod(RelatorioDados::class, 'ponteAcerto'))->invoke(null, $l1);
    $check('acerto: o Relatório põe o acerto na escada do mês', count($ponte) === 1 && abs($ponte[0]['valor'] + 0.37) < 0.005);
    $_SERVER['REQUEST_URI'] = '/acertos/' . $id;
    $_GET = [];
    ob_start();
    (new AcertoController())->ver((string) $id);
    $htmlV = (string) ob_get_clean();
    $check('acerto: abre para ler, com excluir no mês aberto', str_contains($htmlV, 'Acerto com o banco') && str_contains($htmlV, '/acertos/' . $id . '/excluir'));
    $check('acerto: a conta com acerto não pode ser apagada de vez', ContaModel::temUso($conta));
    \Financeiro\Models\AcertoModel::excluir($id);
    MotorFinanceiro::limparCache();
    $check('acerto: excluir devolve o saldo e a diferença', abs(LancamentoModel::saldoCalculado($saldoIni, $hoje) - $emConta0) < 0.005
        && abs((float) (DashboardController::conferenciaAtual($hoje)['acertos'][$conta]['valor'] ?? 0) + 0.37) < 0.005);

    // mês fechado não muda: o acerto do saldo de um dia dele entra no 1º dia do mês aberto, e a conferência conta com ele
    $mesAnt = date('Y-m-01', strtotime($mesHoje . ' -1 month'));
    if (MesModel::situacao($mesAnt) !== null) {
        $pdo->exec('DELETE FROM saldo_conferencias');
        $fimAnt = date('Y-m-t', strtotime($mesAnt));
        MesModel::setSituacao($mesAnt, 'REAL');
        MotorFinanceiro::limparCache();
        $fica0 = MotorFinanceiro::calcularPlano()[$mesAnt]['fica_na_conta'];
        $appAnt = LancamentoModel::saldoCalculado($saldoIni, $fimAnt);
        \Financeiro\Models\SaldoConferenciaModel::registrar($fimAnt, round($appAnt + 1.20, 2), $uid);
        $conf = DashboardController::conferenciaAtual($hoje);
        $check('acerto em mês fechado: entra no 1º dia do mês aberto', AcertoController::dataPara($fimAnt) === $mesHoje
            && abs((float) ($conf['acertos'][$conta]['valor'] ?? 0) - 1.20) < 0.005, json_encode($conf));
        $idF = \Financeiro\Models\AcertoModel::criar(AcertoController::dataPara($fimAnt), $fimAnt, $conta, 1.20, '', $uid);
        MotorFinanceiro::limparCache();
        $plano2 = MotorFinanceiro::calcularPlano();
        $conf = DashboardController::conferenciaAtual($hoje);
        $check('acerto em mês fechado: o mês fechado não muda, o aberto recebe o acerto', abs($plano2[$mesAnt]['fica_na_conta'] - $fica0) < 0.005
            && abs(LancamentoModel::saldoCalculado($saldoIni, $fimAnt) - $appAnt) < 0.005 && abs($plano2[$mesHoje]['acerto'] - 1.20) < 0.005);
        $check('acerto em mês fechado: a conferência daquele dia passa a bater, sem pedir de novo', $conf !== null && abs($conf['dif']) < 0.005 && $conf['acertos'] === [], json_encode($conf));
        $check('acerto em mês fechado: o nome diz de que mês é o saldo', AcertoController::rotulo(\Financeiro\Models\AcertoModel::find($idF)) === t('Acerto do saldo de {mes}', ['mes' => mesExtenso($mesAnt)]));
        $check('acerto em mês fechado: excluir num mês fechado é recusado', LancamentosController::erroMesFechado($fimAnt) !== null && LancamentosController::erroMesFechado($mesHoje) === null);
    } else {
        $check('acerto: a demonstração tem o mês anterior no plano', false);
    }

    // várias contas: o acerto fica na conta dele, e a soma das contas continua sendo o saldo
    $c2 = ContaModel::salvar(null, 'Carteira AC', 'dinheiro', 50.0);
    MotorFinanceiro::limparCache();
    \Financeiro\Models\AcertoModel::criar($hoje, $hoje, $c2, -2.5, 'troco', $uid);
    MotorFinanceiro::limparCache();
    $check('acerto com várias contas: muda só a conta dele, e a soma das contas é o saldo', abs(ContaModel::saldo($c2, $hoje) - 47.5) < 0.005
        && abs(array_sum(ContaModel::saldos($hoje)) - LancamentoModel::saldoCalculado(AjusteModel::valor('saldo_ini'), $hoje)) < 0.005);
});
ContaModel::limparCache();
LancarForm::limparCache();

// ---- 9i. As contas fecham: categoria tirada do mês (B-08), a barra no vermelho (B-12), o "por dia" (B-19), o ordinal (B-18)
$emTransacao('as contas fecham', static function () use ($pdo, $check, $render, $mesHoje, $hoje, $uid): void {
    $comb = (int) $pdo->query("SELECT id FROM categorias WHERE nome = 'Combustível'")->fetchColumn();
    PrevisaoModel::setValor($comb, $mesHoje, 0.0);                     // "Tirar deste mês", com gasto no mês
    MotorFinanceiro::limparCache();
    $htmlMes = $render(MesController::class, 'index', ['mes' => $mesHoje]);
    $linhaMes = preg_match('#<tr id="cat-' . $comb . '".*?</tr>#s', $htmlMes, $m) ? $m[0] : '';
    $htmlMeta = $render(MetaController::class, 'index', []);
    $linhaMeta = preg_match('#id="c' . $comb . '".*?</summary>#s', $htmlMeta, $m) ? $m[0] : '';
    $check('tirada do mês: o Mês diz "sem previsão", sem "passou"; as Metas, "sem meta", sem "falta 0,00"', $comb > 0 && str_contains($linhaMes, 'sem previsão')
        && !str_contains($linhaMes, 'passou') && str_contains($linhaMeta, 'sem meta') && !str_contains($linhaMeta, 'falta ' . num(0)), $linhaMes . ' | ' . $linhaMeta);

    // no vermelho, a legenda soma o total (a falta entra com "−")
    $catLazer = (int) $pdo->query("SELECT id FROM categorias WHERE grupo = 'Dia a dia' AND essencial = 0 AND sinal = -1 AND ativo = 1 ORDER BY id LIMIT 1")->fetchColumn();
    LancamentoModel::criar($hoje, 'estouro', $catLazer, 30000.0, $uid);
    MotorFinanceiro::limparCache();
    $d = \Financeiro\Services\Destino::doMes(MotorFinanceiro::dados(), MotorFinanceiro::calcularPlano()[$mesHoje], $mesHoje);
    $segs = \Financeiro\Services\Destino::segmentos($d);
    $check('no vermelho: a falta aparece na legenda, e as partes somam o total', $d['falta'] > 0
        && abs(array_sum(array_column($segs, 'valor')) - $d['total']) < 0.01 && in_array(-$d['falta'], array_column($segs, 'valor'), true), json_encode([$d, $segs]));
});
$htmlIniD = $render(DashboardController::class, 'index', []);
$diasT = (int) date('t', strtotime($hoje)) - (int) date('j', strtotime($hoje)) + 1;
$check('por dia: conta hoje, e no último dia o valor é para hoje', $diasT > 1
    ? str_contains($htmlIniD, 'nos ' . $diasT . ' dias que faltam, contando hoje') : str_contains($htmlIniD, 'Hoje é o último dia do mês:'));
Idioma::usar('en');
$check('inglês: o dia do mês é ordinal depois de "the"', t('Dá para gastar até dia {dia}', ['dia' => 30]) === 'You can spend until the 30th'
    && implode(',', array_map('ordinalEn', [1, 2, 3, 4, 11, 12, 13, 21, 22, 23, 31])) === '1st,2nd,3rd,4th,11th,12th,13th,21st,22nd,23rd,31st'
    && t('Cerca de {valor} por dia nos {n} dias que faltam, contando hoje.', ['valor' => 'x', 'n' => 9]) === 'About x a day for the 9 days left, counting today.');
Idioma::usar('es');
$check('espanhol: o dia continua "el día 30"', t('Dá para gastar até dia {dia}', ['dia' => 30]) === 'Puede gastar hasta el día 30');
Idioma::usar('pt');

// ---- 9j. Valor recusado diz por quê (B-10)
$validarL = new \ReflectionMethod(LancamentosController::class, 'validar');
$check('valor: acima do máximo diz o máximo', ($validarL->invoke(null, ['valor' => '999999999', 'categoria_id' => '1'])[0] ?? '') === 'O valor máximo é ' . money(valorMaximo()) . '.'
    && (TransferenciaController::validar(['valor' => '999999999'])[0] ?? '') === 'O valor máximo é ' . money(valorMaximo()) . '.');
$check('valor: vírgula e ponto trocados dizem como escrever', motivoValor('1,500') === 'Use vírgula só para os centavos: 1.500,00 ou 1,50.'
    && motivoValor('1.5') === 'Use vírgula para os centavos: 1,50.' && motivoValor('45') === null && motivoValor('abc') === null
    && ($validarL->invoke(null, ['valor' => '1.5', 'categoria_id' => '1'])[0] ?? '') === 'Use vírgula para os centavos: 1,50.');

// ---- 9k. Extrato: o mês no meio da frase em minúscula, e o filtro de valor negativo recusado (B-11)
$htmlExtF = $render(LancamentosController::class, 'index', ['mes' => $mesHoje, 'min' => '999999']);
$htmlExtN = $render(LancamentosController::class, 'index', ['mes' => $mesHoje, 'max' => '-5']);
$check('extrato: "em novembro de 2026" no meio da frase', str_contains($htmlExtF, 'Nada encontrado com esses filtros em ' . mesAno($mesHoje) . '.') && !str_contains($htmlExtF, ' De ' . substr($mesHoje, 0, 4)));
$check('extrato: valor negativo no filtro é recusado', str_contains($htmlExtN, 'Digite o valor como 45 ou 45,90.') && !str_contains($htmlExtN, 'name="max" value="-5"'));

// ---- 9l. Transferência que deixa a conta negativa pede confirmação (B-14)
$emTransacao('transferência negativa', static function () use ($pdo, $check, $hoje): void {
    $c2 = ContaModel::salvar(null, 'Carteira B14', 'dinheiro', 0.0);
    TransferenciaController::criarDe(['valor' => '400', 'de_conta' => '1', 'para_conta' => (string) $c2, 'data' => $hoje]);
    $n = static fn(): int => (int) $pdo->query('SELECT COUNT(*) FROM transferencias')->fetchColumn();
    $antes = $n();
    $r = TransferenciaController::criarDe(['valor' => '5000', 'de_conta' => (string) $c2, 'para_conta' => '1', 'data' => $hoje]);
    $check('transferência: deixar a conta negativa pede confirmação e não grava', ($r['negativo']['valor'] ?? null) === -4600.0 && $r['id'] === 0 && $n() === $antes, json_encode($r));
    $r2 = TransferenciaController::criarDe(['valor' => '5000', 'de_conta' => (string) $c2, 'para_conta' => '1', 'data' => $hoje, 'confirmar_negativo' => '1']);
    $check('transferência: confirmada, grava', $r2['erro'] === null && $r2['id'] > 0 && $n() === $antes + 1 && abs(ContaModel::saldo($c2, $hoje) + 4600) < 0.005);
    $r3 = TransferenciaController::criarDe(['valor' => '100', 'de_conta' => '1', 'para_conta' => (string) $c2, 'data' => $hoje]);
    $check('transferência: sem ficar negativa, grava direto', $r3['id'] > 0 && !isset($r3['negativo']));

    // editar com a mesma pergunta: a conta tem 100 agora (400 + 100 − 5000 + a de 5000 desfeita abaixo)
    $pdo->prepare('DELETE FROM transferencias WHERE id = ?')->execute([$r2['id']]);
    $t = \Financeiro\Models\TransferenciaModel::find($r3['id']);                       // 100 da principal para a carteira
    $e1 = TransferenciaController::editarDe($t, ['valor' => '900', 'de_conta' => (string) $c2, 'para_conta' => '1', 'data' => $hoje]);
    $t1 = \Financeiro\Models\TransferenciaModel::find($r3['id']);
    $check('transferência: editar e deixar a conta negativa pede a mesma confirmação e não grava', ($e1['negativo']['valor'] ?? null) === -500.0
        && $e1['erro'] === null && abs((float) $t1['valor'] - 100) < 0.005 && (int) $t1['de_conta_id'] === 1, json_encode($e1));
    $e2 = TransferenciaController::editarDe($t, ['valor' => '900', 'de_conta' => (string) $c2, 'para_conta' => '1', 'data' => $hoje, 'confirmar_negativo' => '1']);
    $check('transferência: editar, confirmado, grava', $e2['erro'] === null && !isset($e2['negativo']) && abs(ContaModel::saldo($c2, $hoje) + 500) < 0.005);
    $t2 = \Financeiro\Models\TransferenciaModel::find($r3['id']);
    $e3 = TransferenciaController::editarDe($t2, ['valor' => '900', 'de_conta' => (string) $c2, 'para_conta' => '1', 'data' => $hoje, 'descricao' => 'só o nome']);
    $e4 = TransferenciaController::editarDe($t2, ['valor' => '350', 'de_conta' => (string) $c2, 'para_conta' => '1', 'data' => $hoje]);
    $check('transferência: editar só a descrição, ou sem ficar negativa, grava direto (a transferência de antes sai da conta)', $e3['erro'] === null && !isset($e3['negativo'])
        && $e4['erro'] === null && !isset($e4['negativo']) && abs(ContaModel::saldo($c2, $hoje) - 50) < 0.005, json_encode([$e3, $e4]));
});
ContaModel::limparCache();
LancarForm::limparCache();

// ---- 9d. Mês fechado não muda (B-01): lançar, editar, excluir, transferir, pagar e a compra cuja fatura é de mês fechado
$emTransacao('mês fechado não muda', static function () use ($pdo, $check, $render, $catDia, $mesHoje, $hoje, $uid): void {
    $fechado = (string) $pdo->query("SELECT MAX(mes) FROM meses WHERE situacao = 'REAL'")->fetchColumn();
    $diaFechado = substr($fechado, 0, 8) . '15';
    $msg = LancamentosController::erroMesFechado($diaFechado);
    $check('mês fechado: a mensagem diz o mês e como reabrir', $msg === mb_strtoupper(mb_substr(mesAno($fechado), 0, 1)) . mb_substr(mesAno($fechado), 1) . ' está fechado. Para mudar, reabra o mês no Mês.', (string) $msg);
    $check('mês fechado: o mês de hoje está aberto', LancamentosController::erroMesFechado($hoje) === null);
    $fimAntes = MotorFinanceiro::calcularPlano()[$fechado]['fica_na_conta'];

    // transferência com data num mês fechado (com duas contas)
    $c2 = ContaModel::salvar(null, 'Carteira teste', 'dinheiro', 300.0);
    MotorFinanceiro::limparCache();
    [$erroT] = TransferenciaController::validar(['valor' => '10', 'de_conta' => '1', 'para_conta' => (string) $c2, 'data' => $diaFechado]);
    $check('mês fechado: transferência com data nele é recusada', $erroT === $msg, (string) $erroT);
    [$erroT2] = TransferenciaController::validar(['valor' => '10', 'de_conta' => '1', 'para_conta' => (string) $c2, 'data' => $hoje]);
    $check('mês fechado: transferência no mês aberto entra', $erroT2 === null, (string) $erroT2);

    // a conta nova com saldo inicial: o saldo vale do 1º dia do mês aberto, e os meses fechados não mudam
    $abre = date('Y-m-01', strtotime($fechado . ' +1 month'));
    $c2Linha = ContaModel::achar($c2);
    $plano = MotorFinanceiro::calcularPlano();
    $check('mês fechado: o saldo inicial da conta nova conta a partir do 1º mês aberto', $c2Linha['saldo_desde'] === $abre
        && abs($plano[$fechado]['fica_na_conta'] - $fimAntes) < 0.005 && abs($plano[$abre]['sobrou_mes_anterior'] - $fimAntes - 300) < 0.005,
        json_encode([$c2Linha['saldo_desde'], $plano[$fechado]['fica_na_conta'], $fimAntes, $plano[$abre]['sobrou_mes_anterior']]));
    $check('mês fechado: o saldo da conta nova só aparece dali em diante', abs(ContaModel::saldo($c2, $diaFechado)) < 0.005 && abs(ContaModel::saldo($c2, $hoje) - 300) < 0.005
        && abs(LancamentoModel::saldoCalculado(AjusteModel::valor('saldo_ini'), $hoje) - array_sum(ContaModel::saldos($hoje))) < 0.005);

    // compra no cartão: só a fatura conta (a data pode ser de um mês fechado)
    $pdo->exec("INSERT INTO cartoes (nome, dia_fechamento, dia_vencimento) VALUES ('Cartão B01', 1, 10)");
    $cartaoB = (int) $pdo->lastInsertId();
    $catGastoB = (int) $pdo->query("SELECT id FROM categorias WHERE grupo = 'Dia a dia' AND sinal = -1 AND operacional = 1 AND ativo = 1 ORDER BY id DESC LIMIT 1")->fetchColumn();
    $diaAntes = date('Y-m-d', strtotime($fechado . ' -1 month +10 day'));     // fecha no dia 1: cai na fatura do mês fechado
    $r1 = CartaoController::criarCompraDe(['valor' => '60', 'categoria_id' => (string) $catGastoB, 'data' => $diaAntes, 'parcelas' => '1'], $hoje, $cartaoB);
    $r2 = CartaoController::criarCompraDe(['valor' => '60', 'categoria_id' => (string) $catGastoB, 'data' => $diaFechado, 'parcelas' => '1'], $hoje, $cartaoB);
    $check('mês fechado: compra no cartão cuja fatura é de mês fechado é recusada; com fatura num mês aberto, entra', $r1['erro'] !== null && str_contains($r1['erro'], 'fechado') && $r2['erro'] === null, json_encode([$r1['erro'], $r2['erro']]));

    // adiantamento do empréstimo e Pagar
    [$erroAd] = EmprestimoController::validarAdiantamento('100', $diaFechado, $hoje);
    $check('mês fechado: adiantamento do empréstimo com data nele é recusado', $erroAd === $msg, (string) $erroAd);

    // a tela: o Lançar sabe os meses fechados, e o editar de um lançamento fechado não tem Salvar nem Excluir
    $check('mês fechado: o Lançar recebe os meses fechados com a mensagem', (LancarForm::dados()['fechados'][substr($fechado, 0, 7)] ?? null) === $msg);
    $idF = (int) $pdo->query("SELECT id FROM lancamentos WHERE data >= '{$fechado}' AND data < '{$abre}' ORDER BY id LIMIT 1")->fetchColumn();
    $_SERVER['REQUEST_URI'] = '/lancamentos/' . $idF . '/editar';
    $_GET = [];
    ob_start();
    (new LancamentosController())->editForm((string) $idF);
    $htmlEd = (string) ob_get_clean();
    $check('mês fechado: o lançamento abre só para ler', str_contains($htmlEd, e($msg)) && !str_contains($htmlEd, '/excluir"') && preg_match('/data-lf-enviar disabled/', $htmlEd) === 1);
});
ContaModel::limparCache();
LancarForm::limparCache();

// ==== no último dia do mês, só a primeira parte, mais a conferência do site sobre a demonstração
if ($borda) {
    conferencia($check);
    echo "\n{$ok} ok, {$falhas} falha(s)\n";
    exit($falhas > 0 ? 1 : 0);
}

// ==== segunda parte: o que não muda com o dia (contas com dados de mentira, números, textos e o próprio código)

// ---- 3c. idiomas: o português é a chave
Idioma::usar('pt');
$check('idiomas: português é o texto original', t('Em conta hoje') === 'Em conta hoje' && t('Ver {n}', ['n' => 2]) === 'Ver 2' && tn(1, '1 mês', '{n} meses') === '1 mês' && tn(3, '1 mês', '{n} meses') === '3 meses');
$check('idiomas: texto sem tradução continua em português', (static function (): bool { Idioma::usar('en'); $r = t('texto que não existe'); Idioma::usar('pt'); return $r === 'texto que não existe'; })());
$check('idiomas: o navegador escolhe só o que existe', Idioma::doNavegador('en-US,en;q=0.9') === 'en' && Idioma::doNavegador('fr-FR,es;q=0.8') === 'es' && Idioma::doNavegador('fr') === 'pt' && Idioma::doNavegador('') === 'pt');

// ---- 3e. ícone do site: padrão neutro, opção em Ajustes, nada individual no código
$pdo->beginTransaction();
SiteIcone::remover();
$check('ícone: sem escolha, vale a imagem padrão', str_contains(SiteIcone::url(), SiteIcone::PADRAO) && is_file(__DIR__ . '/../public' . SiteIcone::PADRAO));
$htmlAjI = $pagina(AjustesController::class, 'index', '/ajustes');
$check('ícone: Ajustes oferece a troca', str_contains($htmlAjI, 'action="/ajustes/icone"') && str_contains($htmlAjI, 'type="file" name="icone"') && !str_contains($htmlAjI, '/ajustes/icone/remover'));
$pngT = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M/wHwAEBgIApD5fRAAAAABJRU5ErkJggg==');
SiteIcone::salvar('image/png', $pngT);
$check('ícone: o enviado passa a valer no endereço e pode ser desfeito', SiteIcone::url() === '/icone?v=' . sha1($pngT) && (SiteIcone::arquivo()['dados'] ?? '') === $pngT
    && str_contains($pagina(AjustesController::class, 'index', '/ajustes'), '/ajustes/icone/remover'));
SiteIcone::remover();
$pdo->rollBack();
$check('ícone: arquivo que não é imagem é recusado', LinkProduto::fotoValida('<?php echo 1;') === null);
$imagensApp = array_values(array_diff(scandir(__DIR__ . '/../public/assets/img') ?: [], ['.', '..']));
$check('ícone: nenhuma imagem individual no código, só as do app', $imagensApp === ['categorias.svg', 'icone-180.png', 'icone-32.png', 'icone-64.png', 'linhas-cartao.svg', 'marca.svg'], implode(', ', $imagensApp));

// ---- 3g. relatório: o PDF
$docT = new Documento('Título ação', 'pt-BR', '20261122153000');
$docT->novaPagina();
$docT->texto(40, 60, 'Ação ñ € −1,00 🍕 日本', 'manrope-500', 12);
$docT->texto(40, 80, 'Sora', 'sora-600', 12, [0, 0, 0], 'd');
$docT->retangulo(40, 90, 100, 20, [0.5, 0.5, 0.5], [0, 0, 0], 4);
$docT->hachura(40, 120, 50, 20, [0, 0, 0]);
$pngA = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M/wHwAEBgIApD5fRAAAAABJRU5ErkJggg==');
$check('pdf: PNG com transparência entra sem GD', $docT->imagem($pngA, 40, 150, 10, 10));
$check('pdf: formato que não dá para embutir fica de fora', !$docT->imagem('nao e imagem', 40, 150, 10, 10));
$bytesT = $docT->saida();
$xrefOk = false;
if (preg_match('/startxref\n(\d+)\n%%EOF\n$/', $bytesT, $mx) && substr($bytesT, (int) $mx[1], 4) === 'xref') {
    $xrefOk = true;
    $linhasX = explode("\n", substr($bytesT, (int) $mx[1]));
    [, $totalX] = explode(' ', $linhasX[1]);
    for ($iX = 1; $iX < (int) $totalX; $iX++) {
        $xrefOk = $xrefOk && substr($bytesT, (int) substr($linhasX[2 + $iX], 0, 10), strlen($iX . ' 0 obj')) === $iX . ' 0 obj';
    }
}
$check('pdf: cabeçalho, xref e objetos nos lugares certos', str_starts_with($bytesT, '%PDF-1.5') && $xrefOk);
$check('pdf: título e idioma declarados', str_contains($bytesT, '/Title <FEFF') && str_contains($bytesT, '/Lang <FEFF') && str_contains($bytesT, '/DisplayDocTitle true'));
$check('pdf: fontes embutidas com ToUnicode', substr_count($bytesT, '/FontFile2') === 2 && substr_count($bytesT, '/ToUnicode') === 2);
$check('pdf: quebra de linha respeita a largura', max(array_map(static fn($l) => $docT->medir($l, 'manrope-500', 10), $docT->quebrar('um texto bem longo com umapalavraenormequenaocabedeformanenhumanalinha e mais', 'manrope-500', 10, 80))) <= 80.01);

// ---- 11. ícones de categoria: catálogo, sugestão pelo nome, escolher e trocar
$todosExistem = true;
foreach (Icones::REGRAS as [$k]) {
    $todosExistem = $todosExistem && Icones::existe($k);
}
foreach (Icones::POR_GRUPO as $k) {
    $todosExistem = $todosExistem && Icones::existe($k);
}
$check('ícones: toda regra e todo grupo apontam para um ícone do catálogo', $todosExistem);
$lic = Icones::lista();
$spriteCat = (string) file_get_contents(__DIR__ . '/../public/assets/img/categorias.svg');
preg_match_all('/<symbol id="cat-([a-z]+)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1\\.5"/', $spriteCat, $mSimb);
$check('ícones: toda chave da lista tem o seu símbolo no sprite, de traço e sem sobra', $mSimb[1] === array_keys($lic['icones'])
    && Icones::existe($lic['padrao']) && @simplexml_load_string($spriteCat) !== false, implode(', ', array_diff(array_keys($lic['icones']), $mSimb[1])));
$check('ícones: o sprite é só traço, sem preenchimento, estilo, imagem nem script',
    !preg_match('/fill="(?!none)|style=|<image|<script|href=|on[a-z]+=/i', $spriteCat));
$esperado = ['Farmácia e saúde' => 'pilula', 'Mercado' => 'prato', 'Combustível' => 'combustivel',
             'Imposto de renda' => 'leao', 'Gastos mesada filho' => 'nota', 'Seguro do carro' => 'carro',
             'Licenciamento do carro' => 'documento', 'Ração do pet' => 'cachorro', 'Barbeiro' => 'barbearia'];
$erradas = [];
foreach ($esperado as $nomeCat => $k) {
    if (Icones::sugerir($nomeCat, 'Dia a dia') !== $k) {
        $erradas[] = $nomeCat . ' → ' . Icones::sugerir($nomeCat, 'Dia a dia');
    }
}
$check('ícones: sugestão pelo nome', $erradas === [], implode('; ', $erradas));
$check('ícones: sem palavra conhecida, vale o ícone do grupo', Icones::sugerir('Xyzw', 'Entradas') === 'moeda'
    && Icones::sugerir('Xyzw', 'Dia a dia') === 'sacola' && Icones::sugerir('Xyzw', '') === 'sacola');
$htmlIc = Icones::html('pilula', 18, 'mes-ic');
$check('ícones: o HTML usa o símbolo do sprite no tamanho pedido, decorativo', preg_match('#^<svg class="ci mes-ic" width="18" height="18" aria-hidden="true" focusable="false"><use href="/assets/img/categorias\\.svg\\?v=\\d+\\#cat-pilula"/></svg>$#', $htmlIc) === 1, $htmlIc);
$check('ícones: chave inválida desenha o ícone padrão', str_contains(Icones::html('chave-que-nao-existe', 20), '#cat-' . $lic['padrao'] . '"')
    && str_contains(Icones::html('"><script>', 20), '#cat-' . $lic['padrao'] . '"') && !str_contains(Icones::html('"><script>', 20), '<script'));
$emTransacao('categoria com ícone', static function () use ($check): void {
    $id = CategoriaModel::criar('Teste ícone ' . bin2hex(random_bytes(3)), 'saida', false, 'gato');
    Icones::limpar();
    $check('categoria nova guarda o ícone escolhido', $id !== null && Icones::daCategoria((int) $id) === 'gato');
    CategoriaModel::setIcone((int) $id, 'cachorro');
    Icones::limpar();
    $check('trocar o ícone grava', Icones::daCategoria((int) $id) === 'cachorro');
    $id2 = CategoriaModel::criar('Farmácia teste ' . bin2hex(random_bytes(3)), 'saida', true);
    Icones::limpar();
    $check('sem escolha, a categoria nova usa a sugestão pelo nome', $id2 !== null
        && Icones::daCategoria((int) $id2, 'Farmácia teste', 'Dia a dia') === 'pilula');
});
Icones::limpar();
$_SERVER['REQUEST_URI'] = '/categorias/' . $catDia;
$_GET = [];
ob_start();
try {
    (new CategoriaController())->editarForm((string) $catDia);
    $htmlIc = (string) ob_get_clean();
} catch (\Throwable $e) {
    ob_end_clean();
    $htmlIc = '';
}
$check('editar categoria: a página abre com o ícone, o tipo e a situação marcados', str_contains($htmlIc, 'ic-picker') && str_contains($htmlIc, ' checked>')
    && str_contains($htmlIc, 'action="/categorias/' . $catDia . '"') && str_contains($htmlIc, 'name="apelido"') && str_contains($htmlIc, 'name="ativo" value="1" checked')
    && str_contains($htmlIc, 'href="/meta?add=' . $catDia . '#c' . $catDia . '"'));

// ---- 11b. editar tudo numa categoria: nome, apelido, tipo, fixa ou varia, vencimento, essencial, ativa
$emTransacao('editar categoria', static function () use ($check, $pdo, $uid, $hoje): void {
    $base = ['apelido' => '', 'icone' => '', 'tipo' => 'saida', 'valor_tipo' => 'varia', 'dia' => '', 'essencial' => false, 'ativo' => true];
    $id = (int) CategoriaModel::criar('Teste editar ' . bin2hex(random_bytes(3)), 'saida', false);
    $novoNome = 'Academia teste ' . bin2hex(random_bytes(3));
    $erros = CategoriaModel::editar($id, ['nome' => $novoNome, 'apelido' => 'Academia', 'icone' => 'gato', 'valor_tipo' => 'fixo', 'dia' => '7', 'essencial' => true] + $base);
    $c = CategoriaModel::find($id);
    $conta = ContaRecorrenteModel::daCategoria($id);
    $check('editar: nome, apelido, ícone, fixa com vencimento e essencial', $erros === [] && $c['nome'] === $novoNome && $c['apelido'] === 'Academia' && $c['icone'] === 'gato'
        && $c['grupo'] === 'Fixos' && (int) $c['sinal'] === -1 && (int) $c['essencial'] === 1 && $conta !== null && (int) $conta['dia_vencimento'] === 7 && (int) $conta['ativo'] === 1,
        json_encode($erros));
    $erros = CategoriaModel::editar($id, ['nome' => $novoNome, 'valor_tipo' => 'varia', 'dia' => '7'] + $base);
    $c = CategoriaModel::find($id);
    $check('editar: voltar a variar tira o vencimento e o essencial', $erros === [] && $c['grupo'] === 'Dia a dia' && (int) $c['essencial'] === 0 && $c['apelido'] === null
        && (int) ContaRecorrenteModel::daCategoria($id)['ativo'] === 0 && $c['icone'] === 'gato');
    $erros = CategoriaModel::editar($id, ['nome' => $novoNome, 'valor_tipo' => 'pontual', 'dia' => '32'] + $base);
    $check('editar: dia fora de 1 a 31 é recusado, junto do campo', $erros === ['dia' => t('O dia do vencimento vai de 1 a 31.')] && CategoriaModel::find($id)['grupo'] === 'Dia a dia');
    $erros = CategoriaModel::editar($id, ['nome' => $novoNome, 'tipo' => 'entrada', 'essencial' => true, 'dia' => '5'] + $base);
    $c = CategoriaModel::find($id);
    $check('editar: sem lançamentos, gasto vira entrada (grupo Entradas, nunca essencial)', $erros === [] && $c['grupo'] === 'Entradas' && (int) $c['sinal'] === 1 && (int) $c['essencial'] === 0);
    $erros = CategoriaModel::editar($id, ['nome' => 'Mercado'] + $base);
    $check('editar: o nome continua único', isset($erros['nome']) && CategoriaModel::find($id)['nome'] === $novoNome);
    $erros = CategoriaModel::editar($id, ['nome' => '  ', 'apelido' => str_repeat('a', 25), 'tipo' => 'x'] + $base);
    $check('editar: nome vazio, apelido longo e tipo inválido, cada erro no seu campo', isset($erros['nome'], $erros['apelido'], $erros['tipo']));
    $erros = CategoriaModel::editar($id, ['nome' => $novoNome, 'ativo' => false] + $base);
    $check('editar: arquivar tira das listas e reativar devolve', $erros === [] && (int) CategoriaModel::find($id)['ativo'] === 0
        && in_array($id, array_map('intval', array_column(CategoriaModel::arquivadas(), 'id')), true)
        && CategoriaModel::editar($id, ['nome' => $novoNome] + $base) === [] && (int) CategoriaModel::find($id)['ativo'] === 1);

    // com lançamento, entrada e gasto não se trocam: inverteria o histórico
    $pdo->prepare('INSERT INTO lancamentos (data, descricao, categoria_id, valor, usuario_id) VALUES (?, ?, ?, 50, ?)')->execute([$hoje, 'teste', $id, $uid]);
    $sinalAntes = (int) CategoriaModel::find($id)['sinal'];
    $erros = CategoriaModel::editar($id, ['nome' => $novoNome, 'tipo' => $sinalAntes === 1 ? 'saida' : 'entrada'] + $base);
    $check('editar: com lançamentos, o tipo não troca', isset($erros['tipo']) && (int) CategoriaModel::find($id)['sinal'] === $sinalAntes && CategoriaModel::temHistorico($id));

    // essencial: só gasto de verdade, como em setEssencial
    $gastoId = (int) $pdo->query("SELECT id FROM categorias WHERE grupo = 'Dia a dia' AND ativo = 1 AND essencial = 0 ORDER BY id LIMIT 1")->fetchColumn();
    $g = CategoriaModel::find($gastoId);
    $erros = CategoriaModel::editar($gastoId, ['nome' => $g['nome'], 'apelido' => (string) $g['apelido'], 'essencial' => true] + $base);
    $check('editar: marcar essencial na própria categoria', $erros === [] && (int) CategoriaModel::find($gastoId)['essencial'] === 1);

    // as que o app acha pela chave (categorias.chave) não mudam de tipo nem de grupo; o nome muda
    $sal = CategoriaModel::porChave(MotorFinanceiro::CAT_SALARIO);
    $erros = CategoriaModel::editar((int) $sal['id'], ['nome' => 'Renda teste', 'tipo' => 'saida'] + $base);
    $check('editar: o Salário não muda de tipo', isset($erros['tipo']) && !isset($erros['nome']) && CategoriaModel::find((int) $sal['id'])['nome'] === $sal['nome']);
    $erros = CategoriaModel::editar((int) $sal['id'], ['nome' => 'Renda teste', 'apelido' => 'Pagamento', 'tipo' => 'entrada', 'essencial' => true] + $base);
    $sal2 = CategoriaModel::find((int) $sal['id']);
    $check('editar: o Salário muda de nome e de apelido, continua nas Entradas e o app o acha pela chave', $erros === [] && $sal2['nome'] === 'Renda teste'
        && $sal2['apelido'] === 'Pagamento' && $sal2['grupo'] === 'Entradas' && (int) $sal2['essencial'] === 0
        && (int) CategoriaModel::porChave(MotorFinanceiro::CAT_SALARIO)['id'] === (int) $sal['id']);
    $guardar = CategoriaModel::porChave(MotorFinanceiro::CAT_GUARDAR);
    $erros = CategoriaModel::editar((int) $guardar['id'], ['nome' => (string) $guardar['nome'], 'valor_tipo' => 'fixo', 'essencial' => true] + $base);
    $guardar2 = CategoriaModel::find((int) $guardar['id']);
    $check('editar: movimentação fica no grupo dela e nunca é essencial', $erros === [] && $guardar2['grupo'] === 'Poupanca' && (int) $guardar2['essencial'] === 0 && (int) $guardar2['operacional'] === 0);
    $fatura = CategoriaModel::porChave(MotorFinanceiro::CAT_FATURA);
    $erros = CategoriaModel::editar((int) $fatura['id'], ['nome' => (string) $fatura['nome'], 'tipo' => 'entrada'] + $base);
    $check('editar: a fatura do cartão não vira entrada', isset($erros['tipo']) && CategoriaModel::find((int) $fatura['id'])['grupo'] === 'Cartao');
    $erros = CategoriaModel::editar((int) $fatura['id'], ['nome' => (string) $fatura['nome'], 'valor_tipo' => 'varia', 'dia' => '9', 'essencial' => true] + $base);
    $check('editar: a fatura aceita o vencimento e continua no Cartão', $erros === [] && CategoriaModel::find((int) $fatura['id'])['grupo'] === 'Cartao'
        && (int) ContaRecorrenteModel::daCategoria((int) $fatura['id'])['dia_vencimento'] === 9);
    $erros = CategoriaModel::editar($id, ['nome' => (string) $sal['nome'], 'tipo' => (int) CategoriaModel::find($id)['sinal'] === 1 ? 'entrada' : 'saida'] + $base);
    $check('editar: o nome de fábrica de uma especial fica livre para outra, e a chave continua na especial', $erros === []
        && (int) CategoriaModel::porChave(MotorFinanceiro::CAT_SALARIO)['id'] === (int) $sal['id'] && CategoriaModel::porNome((string) $sal['nome'])['id'] == $id);
    MotorFinanceiro::limparCache();
    $check('editar: o motor continua achando as categorias dele', MotorFinanceiro::dados()['esp']['guard'] === (int) $guardar['id']);
});

// ---- 12. barra do celular: o "+" no meio, entre Início e Extrato e Mês e Mais; o Anual abre pelo seletor de meses do Mês
$htmlMes = $render(MesController::class, 'index', []);
preg_match('#<nav class="tabbar".*?<details#s', $htmlMes, $m);
$barra = $m[0] ?? '';
preg_match_all('#<a class="(tab[^" ]*)#', $barra, $itens);
$check('barra do celular: 2 abas, o +, o Mês e o Mais (sem o Relatório)', $itens[1] === ['tab', 'tab', 'tab-add', 'tab']
    && str_contains($htmlMes, '<details class="menu menu-geral tab-mais">') && !str_contains($barra, 'href="/relatorio"') && str_contains($htmlMes, 'class="lat-i" href="/relatorio"'), implode(',', $itens[1]));
$check('seletor de meses do Mês leva ao Relatório e ao simulador; o do Extrato não', str_contains($htmlMes, 'class="mes-picker-anual" href="/relatorio"') && str_contains($htmlMes, 'class="mes-picker-anual" href="/futuro"')
    && !str_contains($htmlEx, 'mes-picker-anual'));
$check('botão Mais (celular), sem o círculo da inicial, leva ao Relatório e ao Simular; a aba Anual saiu', str_contains($htmlMes, 'class="tab menu-botao"') && str_contains($htmlMes, 'class="menu-item" href="/relatorio"')
    && str_contains($htmlMes, 'class="menu-item" href="/futuro"') && !str_contains($htmlMes, 'menu-avatar') && !str_contains($htmlMes, '>Anual<'));
$htmlFuAtivo = $render(FuturoController::class, 'index', []);
$check('Simular: fica aceso ele mesmo, no menu lateral e no Mais', str_contains($htmlFuAtivo, 'class="lat-i is-active" href="/futuro"') && str_contains($htmlFuAtivo, 'class="menu-item is-active" href="/futuro"')
    && str_contains($htmlFuAtivo, 'class="tab menu-botao is-active"') && !str_contains($htmlFuAtivo, 'class="tab is-active"') && !str_contains($htmlFuAtivo, 'class="lat-i is-active" href="/relatorio"'));

// ---- 12b. ordem do menu: cada conta organiza as abas; o servidor aplica, ignora chave estranha e põe aba nova no fim
$check('menu: sem ordem guardada, a padrão', Menu::ordenar(null) === Menu::CHAVES && Menu::ordenar(Menu::ler('isto não é JSON')) === Menu::CHAVES && Menu::ler('{"a":1}') === null);
$check('menu: a ordem guardada vale, e o que falta segue na ordem padrão', Menu::ordenar(['meta', 'ajustes'])
    === ['meta', 'ajustes', 'inicio', 'extrato', 'mes', 'cartao', 'relatorio', 'simular', 'emprestimo', 'investimentos', 'wishlist']);
$check('menu: a ordem padrão vem em 3 grupos seguidos, e Temas e Ajuda saíram (abrem por Ajustes)', array_values(array_map(static fn(string $c): int => Menu::GRUPOS[$c], Menu::CHAVES)) === [0, 0, 0, 0, 1, 1, 1, 2, 2, 2, 2]
    && array_keys(Menu::abas()) === Menu::CHAVES && Menu::ordenar(['temas', 'ajuda', 'meta'])[0] === 'meta');
$check('menu: chave desconhecida ou repetida é ignorada', Menu::ordenar(['xyz', 'meta', 7, 'meta', null, 'inicio']) === array_merge(['meta', 'inicio'], array_values(array_diff(Menu::CHAVES, ['meta', 'inicio']))));
$semNovas = array_reverse(array_values(array_diff(Menu::CHAVES, ['investimentos', 'wishlist'])));
$check('menu: aba nova (fora da lista guardada) entra no fim, na ordem padrão', Menu::ordenar($semNovas) === [...$semNovas, 'investimentos', 'wishlist']);
$check('menu: a lista do navegador só passa com chaves conhecidas e sem repetir', Menu::validar(['mes', 'inicio']) === ['mes', 'inicio']
    && Menu::validar(['mes', 'xyz']) === null && Menu::validar(['mes', 'mes']) === null && Menu::validar([]) === null && Menu::validar('mes') === null
    && Menu::validar(['a' => 'mes']) === null && Menu::validar([['mes']]) === null);
$check('menu: reorganizar parte das abas deixa as outras no lugar (a escondida guarda a posição)',
    Menu::mesclar(['inicio', 'cartao', 'mes', 'meta'], ['meta', 'inicio', 'mes']) === ['meta', 'cartao', 'inicio', 'mes']);
$ordemDe = static function (string $html, string $onde): array {
    preg_match('#' . $onde . '.*?<ul class="org-lista" data-org-lista>(.*?)</ul>#s', $html, $m);
    preg_match_all('#data-aba="([a-z]+)"#', $m[1] ?? '', $k);
    return $k[1];
};
$menuAntes = UsuarioModel::menuOrdem($uid);
try {
    UsuarioModel::salvarMenuOrdem($uid, json_encode(['meta', 'ajustes', 'xyz', 'cartao']));
    $htmlMenu = $render(MetaController::class, 'index', []);
    $lat = $ordemDe($htmlMenu, '<aside class="lateral"');
    $cel = $ordemDe($htmlMenu, '<details class="menu menu-geral tab-mais">');
    preg_match('#<nav class="tabbar".*?<details#s', $htmlMenu, $mb);
    preg_match_all('#href="([^"?]+)#', $mb[0] ?? '', $hb);
    $check('menu: a página aplica a ordem da conta no menu lateral e no botão Menu, sem a chave estranha', array_slice($lat, 0, 5) === ['meta', 'ajustes', 'cartao', 'inicio', 'extrato']
        && count($lat) === count(Menu::CHAVES) && !in_array('xyz', $lat, true) && array_slice($cel, 0, 4) === ['meta', 'ajustes', 'cartao', 'relatorio'] && !in_array('inicio', $cel, true), implode(',', $lat) . ' | ' . implode(',', $cel));
    $check('menu: o Lançar fica no topo e a barra de baixo não muda', strpos($htmlMenu, 'class="lat-lancar btn btn-principal"') < strpos($htmlMenu, 'data-org-lista') && $hb[1] === ['/', '/lancamentos', '/lancar', '/mes'], implode(',', $hb[1]));
    $pdo->beginTransaction();
    AjusteModel::setValor('usa_cartao', 0.0);
    $semCartao = $ordemDe($render(MetaController::class, 'index', []), '<aside class="lateral"');
    $pdo->rollBack();
    $check('menu: aba escondida em Ajustes some do menu, mas a ordem das outras fica', array_slice($semCartao, 0, 3) === ['meta', 'ajustes', 'inicio'] && !in_array('cartao', $semCartao, true));

    // o POST: só a lista à vista vem do navegador; as outras guardam o lugar
    $postarMenu = static fn(array $post): int => MenuController::gravar($uid, $post);
    UsuarioModel::salvarMenuOrdem($uid, null);
    $cod = $postarMenu(['ordem' => ['ajustes', 'relatorio', 'simular', 'emprestimo', 'investimentos', 'wishlist', 'meta']]);
    $check('menu: POST com o token grava; as abas que não estavam na lista ficam no lugar', $cod === 204
        && Menu::daConta($uid) === ['inicio', 'extrato', 'mes', 'cartao', 'ajustes', 'relatorio', 'simular', 'emprestimo', 'investimentos', 'wishlist', 'meta'], $cod . ' ' . implode(',', Menu::daConta($uid)));
    $gravada = UsuarioModel::menuOrdem($uid);
    $cod = $postarMenu(['ordem' => ['ajustes', 'xyz']]);
    $check('menu: POST com chave desconhecida é recusado e nada muda', $cod === 422 && UsuarioModel::menuOrdem($uid) === $gravada);
    $cod = $postarMenu(['padrao' => '1']);
    $check('menu: voltar à ordem padrão apaga a escolha', $cod === 204 && UsuarioModel::menuOrdem($uid) === null);
    $postarMenu(['ordem' => Menu::CHAVES]);
    $check('menu: a ordem igual à padrão não fica guardada', UsuarioModel::menuOrdem($uid) === null);

    // sem o token, a página de erro sai antes de gravar (ela encerra o processo, por isso roda num processo à parte)
    $filhoMenu = (string) getenv('FIN_REGRAS_PASTA') . '/menu-sem-token.php';
    file_put_contents($filhoMenu, '<?php
$_SERVER["REQUEST_URI"] = "/menu/ordem"; $_SERVER["REQUEST_METHOD"] = "POST"; $_SERVER["HTTP_HOST"] = "localhost"; $_SERVER["HTTPS"] = "on";
require ' . var_export(dirname(__DIR__) . '/src/bootstrap.php', true) . ';
$_SESSION["usuario_id"] = ' . $uid . '; $_SESSION["usuario_nome"] = "teste"; $_SESSION["sessao_versao"] = ' . (int) UsuarioModel::sessaoVersao($uid) . ';
$_SESSION["csrf_token"] = str_repeat("c", 64);
$_POST = ["ordem" => ["ajustes", "inicio"]] + (($argv[1] ?? "") === "errado" ? ["csrf_token" => str_repeat("d", 64)] : []);
(new Financeiro\Controllers\MenuController())->salvarOrdem();
echo "GRAVOU";
');
    foreach (['sem token' => 'nenhum', 'token errado' => 'errado'] as $caso => $arg) {
        $proc = proc_open([...\Financeiro\Atualizacao::comandoPhp(), $filhoMenu, $arg],
            [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $tubos, \Financeiro\Atualizacao::pastaDoProcesso(dirname(__DIR__)), array_filter(getenv(), 'is_string'));
        $saidaMenu = is_resource($proc) ? (string) stream_get_contents($tubos[1]) : '';
        if (is_resource($proc)) {
            fclose($tubos[1]);
            proc_close($proc);
        }
        $check("menu: POST {$caso} é recusado e nada muda", str_contains($saidaMenu, '<title>403') && !str_contains($saidaMenu, 'GRAVOU') && UsuarioModel::menuOrdem($uid) === null, substr(trim(strip_tags($saidaMenu)), 0, 200));
    }
    @unlink($filhoMenu);
} catch (\Throwable $e) {
    $check('menu: ordem das abas', false, get_class($e) . ': ' . $e->getMessage() . ' em ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_POST = [];
    UsuarioModel::salvarMenuOrdem($uid, $menuAntes);
}

// ---- 13. Anual: marcos do plano e o que mudou em cada mês (AnualResumo, dados de mentira)
$pl = static fn(string $m, float $fica, bool $quit = false, float $parc = 0.0): array => ['mes' => $m, 'estado' => 'previsto',
    'fica_na_conta' => $fica, 'quitado' => $quit, 'bateu_meta' => false, 'parcela_descontada' => $parc];
$planoT = [];
foreach ([['2027-01-01', 5000], ['2027-02-01', 2000], ['2027-03-01', -100], ['2027-04-01', -50], ['2027-05-01', 2500],
          ['2027-06-01', 3500, true], ['2027-07-01', 4000, true]] as $r) {
    $planoT[$r[0]] = $pl($r[0], (float) $r[1], $r[2] ?? false);
}
$mk = AnualResumo::marcos($planoT, 3000.0, '2027-02-01');
$check('anual: mês mais apertado e faixas no vermelho / abaixo da reserva', ($mk['apertado']['mes'] ?? '') === '2027-03-01'
    && array_map(static fn($f) => $f['tipo'] . ' ' . substr($f['ini'], 0, 7) . '..' . substr($f['fim'], 0, 7), $mk['faixas'])
       === ['warn 2027-02..2027-02', 'neg 2027-03..2027-04', 'warn 2027-05..2027-05'], json_encode($mk['faixas']));
$check('anual: volta à reserva, quitação e fim contados da referência', $mk['volta'] === '2027-06-01' && $mk['quita'] === '2027-06-01'
    && $mk['quitaAntes'] === false && ($mk['fim']['mes'] ?? '') === '2027-07-01' && abs($mk['fim']['desde'] - 2000.0) < 0.01);
$dadosT = ['real' => [], 'cats' => [
    1 => ['id' => 1, 'nome' => 'Salário', 'apelido' => null, 'grupo' => 'Entradas', 'sinal' => 1, 'operacional' => 1],
    2 => ['id' => 2, 'nome' => 'IPVA (carro)', 'apelido' => null, 'grupo' => 'Ano', 'sinal' => -1, 'operacional' => 1],
], 'prev' => ['2027-01-01' => [1 => 10000.0], '2027-02-01' => [1 => 10000.0, 2 => 1200.0], '2027-03-01' => [1 => 15000.0, 2 => 100.0]]];
$planoM = ['2027-01-01' => $pl('2027-01-01', 0.0), '2027-02-01' => $pl('2027-02-01', 0.0), '2027-03-01' => $pl('2027-03-01', 0.0, false, 300.0)];
$mu = AnualResumo::mudancas($planoM, $dadosT);
$lin = static fn(string $m, float $div, float $j, float $am, bool $q): array => ['mes' => $m, 'estado' => 'previsto',
    'falta_pagar' => $div, 'juros' => $j, 'amortizacao_extra' => $am, 'quitado' => $q];
$comT = ['2027-01-01' => $lin('2027-01-01', 600.0, 10.0, 400.0, false), '2027-02-01' => $lin('2027-02-01', 0.0, 6.0, 606.0, true)];
$semT = ['2027-01-01' => $lin('2027-01-01', 1000.0, 10.0, 0.0, false), '2027-02-01' => $lin('2027-02-01', 910.0, 10.0, 0.0, false)];
$gT = AnualResumo::ganho($comT, $semT, 100.0, 0.01);
$check('anual: adiantar o empréstimo — quando acabaria só com as parcelas e os juros que se evitam', $gT !== null
    && $gT['quitaCom'] === '2027-02-01' && $gT['quitaSem'] === '2027-12-01' && $gT['meses'] === 10 && $gT['juros'] > 40 && $gT['juros'] < 60,
    json_encode($gT));
$check('anual: sem adiantamento no plano, não há ganho a mostrar', AnualResumo::ganho($semT, $semT, 100.0, 0.01) === null);
$check('anual: o que mudou no mês, do maior efeito para o menor', $mu['2027-01-01'] === []
    && ($mu['2027-02-01'][0]['nome'] ?? '') === 'IPVA' && abs($mu['2027-02-01'][0]['efeito'] + 1200.0) < 0.01
    && array_map(static fn($x) => $x['nome'] . ' ' . (int) $x['efeito'], $mu['2027-03-01'])
       === ['Salário 5000', 'IPVA 1100', 'Parcela do empréstimo -300'], json_encode($mu['2027-03-01']));

// ---- 15. cartão: fatura de uma compra, parcelas, compra antiga e o painel
$check('cartão: compra antes, no dia e depois do fechamento (fecha 25, vence 5)',
    CartaoFatura::faturaDe('2026-10-16', 25, 5) === '2026-11-01' && CartaoFatura::faturaDe('2026-10-25', 25, 5) === '2026-12-01'
    && CartaoFatura::faturaDe('2026-10-26', 25, 5) === '2026-12-01' && CartaoFatura::faturaDe('2026-12-31', 25, 5) === '2027-02-01');
$check('cartão: vencimento depois do fechamento no mesmo mês (fecha 3, vence 10) e mês curto',
    CartaoFatura::faturaDe('2026-10-02', 3, 10) === '2026-10-01' && CartaoFatura::faturaDe('2026-10-03', 3, 10) === '2026-11-01'
    && CartaoFatura::faturaDe('2027-02-28', 31, 8) === '2027-04-01'
    && CartaoFatura::fechamento('2026-11-01', 25, 5) === '2026-10-25' && CartaoFatura::vencimento('2026-11-01', 5) === '2026-11-05');
$pT = CartaoFatura::parcelas(100.0, 3, '2026-11-01');
$check('cartão: os centavos que sobram vão na 1ª parcela', array_map(static fn($x) => $x[0] . ' ' . $x[1] . ' ' . number_format($x[2], 2), $pT)
    === ['2026-11-01 1 33.34', '2026-12-01 2 33.33', '2027-01-01 3 33.33'], json_encode($pT));
$check('cartão: compra antiga (parcela 4 na fatura aberta de nov/26) começou em ago/26', CartaoFatura::primeiraDaAntiga('2026-11-01', 4) === '2026-08-01'
    && CartaoFatura::primeiraDaAntiga('2026-11-01', 1) === '2026-11-01');
$compT = [
    ['id' => 1, 'valor_total' => 3200.0, 'parcelas' => 10, 'primeira_fatura' => '2026-11-01', 'descricao' => 'Máquina', 'data' => '2026-10-16'],
    ['id' => 2, 'valor_total' => 600.0, 'parcelas' => 6, 'primeira_fatura' => '2026-08-01', 'descricao' => 'Fone', 'data' => '2026-07-20'],
    ['id' => 3, 'valor_total' => 50.0, 'parcelas' => 1, 'primeira_fatura' => '2026-11-01', 'descricao' => 'Farmácia', 'data' => '2026-10-10'],
];
$pnT = CartaoFatura::painel(['fecha' => 25, 'vence' => 5, 'limite' => 10000.0], $compT, '2026-10-16', ['2026-10-01' => 5751.52]);
$fNov = $pnT['faturas'][1];
$check('cartão: painel — fatura aberta, a anterior paga, o total e o limite livre', $pnT['aberta'] === '2026-11-01'
    && $pnT['faturas'][0]['status'] === 'paga' && $fNov['status'] === 'aberta' && abs($fNov['total'] - 470.0) < 0.01
    && abs($pnT['comprometido'] - (3200.0 + 300.0 + 50.0)) < 0.01 && abs($pnT['livre'] - (10000.0 - 3550.0)) < 0.01, json_encode([$fNov['total'], $pnT['comprometido']]));
$check('cartão: parcelamentos do que acaba antes para o que acaba depois', array_map(static fn($x) => $x['compra']['id'] . ':' . $x['agora'] . '/' . $x['n'], $pnT['parcelamentos'])
    === ['2:4/6', '1:1/10'], json_encode(array_map(static fn($x) => [$x['compra']['id'], $x['agora']], $pnT['parcelamentos'])));

// ---- 16. aba Empréstimo: dados de mentira, para não depender da dívida de verdade
$dadosEmp = [
    'meses' => [['mes' => '2030-01-01', 'situacao' => 'PREVISAO'], ['mes' => '2030-02-01', 'situacao' => 'PREVISAO'], ['mes' => '2030-03-01', 'situacao' => 'PREVISAO']],
    'aj' => ['parcela_mensal' => 100.0, 'juros_mes' => 0.01, 'n_parcelas' => 12.0, 'reserva_minima' => 500.0, 'saldo_ini' => 2000.0, 'poupanca_ini' => 0.0, 'objetivo' => 1.0, 'meta_final' => 0.0],
    'loanIni' => 1000.0, 'cats' => [], 'prev' => [], 'excecao' => [], 'metas' => [], 'real' => [], 'n' => [], 'ult' => [],
    'esp' => ['cred' => null, 'amort' => 99, 'guard' => null, 'resg' => null],
];
$hojeEmp = '2029-12-15';
$sitEmp = EmprestimoSimulacao::situacao($dadosEmp, '2030-01-01', $hojeEmp);
$check('empréstimo: falta = saldo + juros − parcela no fim do mês', abs($sitEmp['falta'] - 910.0) < 0.005, (string) $sitEmp['falta']);
$check('empréstimo: sem o mês da 1ª parcela, não há fim pelo contrato', $sitEmp['contrato'] === null);
$simEmp = EmprestimoSimulacao::simular($dadosEmp, '2030-01-01', '2030-01-01', 200.0, $hojeEmp);
$check('empréstimo: o adiantamento abate a dívida e sai da conta', abs($simEmp['falta'] - 710.0) < 0.005 && abs($simEmp['contaAntes'] - $simEmp['contaDepois'] - 200.0) < 0.005, json_encode($simEmp));
$saldoT = 710.0;
$k = 0;
$jurosT = 0.0;
while ($saldoT > 0.01) {
    $jT = $saldoT * 0.01;
    $jurosT += $jT;
    $saldoT = max(0.0, $saldoT + $jT - 100.0);
    $k++;
}
$saldoS = 910.0;
$kS = 0;
$jurosS = 0.0;
while ($saldoS > 0.01) {
    $jS = $saldoS * 0.01;
    $jurosS += $jS;
    $saldoS = max(0.0, $saldoS + $jS - 100.0);
    $kS++;
}
$check('empréstimo: meses e juros a menos batem com a conta à mão', $simEmp['mesesAntes'] === $kS - $k && abs($simEmp['jurosMenos'] - ($jurosS - $jurosT)) < 0.01, json_encode([$simEmp['mesesAntes'], $kS - $k, $simEmp['jurosMenos'], $jurosS - $jurosT]));
$demais = EmprestimoSimulacao::simular($dadosEmp, '2030-01-01', '2030-02-01', 50000.0, $hojeEmp);
$check('empréstimo: valor acima da dívida quita e só tira da conta o que devia', $demais['ajustado'] && $demais['falta'] <= 0.01 && abs($demais['aplicado'] - $demais['teto']) < 0.005
    && abs($demais['contaAntes'] - $demais['contaDepois'] - $demais['aplicado']) < 0.005 && $demais['quita'] === '2030-02-01', json_encode($demais['aplicado']));
$alerta = EmprestimoSimulacao::simular($dadosEmp, '2030-01-01', '2030-01-01', 900.0, $hojeEmp);
$check('empréstimo: avisa quando a conta cai abaixo da reserva, e sem aviso se não cai', $alerta['alerta'] === null && $simEmp['alerta'] === null
    && EmprestimoSimulacao::simular(array_replace_recursive($dadosEmp, ['aj' => ['saldo_ini' => 900.0]]), '2030-01-01', '2030-01-01', 350.0, $hojeEmp)['alerta'] === 'warn'
    && EmprestimoSimulacao::simular(array_replace_recursive($dadosEmp, ['aj' => ['saldo_ini' => 600.0]]), '2030-01-01', '2030-01-01', 500.0, $hojeEmp)['alerta'] === 'neg');
$check('empréstimo: com a parcela menor, o prazo fica e os juros caem menos que encurtando', $simEmp['menorParcela']['quita'] === $simEmp['quitaAntes'] && $simEmp['menorParcela']['parcela'] < 100.0
    && $simEmp['menorParcela']['jurosMenos'] < $simEmp['jurosMenos'], json_encode($simEmp['menorParcela']));
$check('empréstimo: mês real não recebe adiantamento simulado', abs(MotorFinanceiro::calcular(array_replace($dadosEmp, ['meses' => [['mes' => '2030-01-01', 'situacao' => 'REAL']]]),
    ['adiantar' => ['2030-01-01' => 200.0], 'v2' => true, 'objetivo' => 3, 'hoje' => $hojeEmp])['2030-01-01']['amortizacao_extra']) < 0.005);
$check('empréstimo: sem a opção, o motor segue igual', json_encode(MotorFinanceiro::calcular($dadosEmp, ['hoje' => $hojeEmp])) === json_encode(MotorFinanceiro::calcular($dadosEmp, ['hoje' => $hojeEmp, 'adiantar' => []])));
$lido = AjustesController::lerValores(['juros_mes' => '1,5000', 'parcela_mensal' => '300,00', 'n_parcelas' => '60', 'consignado_inicio' => '2026-11', 'reserva_minima' => '1,00'], AjusteModel::DO_EMPRESTIMO);
$check('dados do empréstimo: lê os campos e ignora o que não é do empréstimo', $lido['erro'] === null && abs($lido['valores']['juros_mes'] - 0.015) < 1e-9
    && $lido['valores']['consignado_inicio'] === 202611.0 && !isset($lido['valores']['reserva_minima']), json_encode($lido));
$check('dados do empréstimo: recusa parcela zero, número de parcelas quebrado e mês inválido',
    AjustesController::lerValores(['parcela_mensal' => '0'], AjusteModel::DO_EMPRESTIMO)['erro'] === 'parcela_mensal'
    && AjustesController::lerValores(['n_parcelas' => '1,5'], AjusteModel::DO_EMPRESTIMO)['erro'] === 'n_parcelas'
    && AjustesController::lerValores(['consignado_inicio' => '2026-13'], AjusteModel::DO_EMPRESTIMO)['erro'] === 'consignado_inicio');
$check('dados do empréstimo: Ajustes não grava mais esses campos', array_diff(AjusteModel::DO_EMPRESTIMO, AjusteModel::EDITAVEIS) === []
    && AjustesController::lerValores(['juros_mes' => '5'], array_diff(AjusteModel::EDITAVEIS, AjusteModel::DO_EMPRESTIMO))['valores'] === []);
$dadosSaldo = array_replace_recursive($dadosEmp, ['aj' => ['saldo_devedor' => 500.0, 'saldo_devedor_mes' => 203002.0]]);
$pSaldo = MotorFinanceiro::calcular($dadosSaldo, ['v2' => true, 'objetivo' => 3, 'hoje' => $hojeEmp]);
$check('saldo informado: a dívida parte dele no mês informado, antes da parcela', abs($pSaldo['2030-01-01']['falta_pagar'] - 910.0) < 0.005
    && abs($pSaldo['2030-02-01']['falta_pagar'] - 405.0) < 0.005 && abs($pSaldo['2030-03-01']['falta_pagar'] - 309.05) < 0.005, json_encode(array_column($pSaldo, 'falta_pagar')));
$pSaldoAuto = MotorFinanceiro::calcular($dadosSaldo, ['v2' => true, 'objetivo' => 1, 'hoje' => $hojeEmp]);
$check('saldo informado: sem adiantamento automático antes dele, e com adiantamento depois', abs($pSaldoAuto['2030-01-01']['amortizacao_extra']) < 0.005 && $pSaldoAuto['2030-02-01']['amortizacao_extra'] > 0.0
    && MotorFinanceiro::calcular($dadosEmp, ['v2' => true, 'objetivo' => 1, 'hoje' => $hojeEmp])['2030-01-01']['amortizacao_extra'] > 0.0);
$check('saldo informado: juros menores não aumentam a dívida', MotorFinanceiro::calcular(array_replace_recursive($dadosSaldo, ['aj' => ['juros_mes' => 0.005]]), ['v2' => true, 'objetivo' => 3, 'hoje' => $hojeEmp])['2030-02-01']['falta_pagar'] < $pSaldo['2030-02-01']['falta_pagar']);
$check('saldo informado: em branco ou sem mês, vale o cálculo de antes', MotorFinanceiro::saldoInformado($dadosEmp['aj']) === null
    && MotorFinanceiro::saldoInformado(['saldo_devedor' => 500.0, 'saldo_devedor_mes' => 0.0]) === null && MotorFinanceiro::saldoInformado(['saldo_devedor' => 0.0, 'saldo_devedor_mes' => 203002.0]) === null);
$simSaldo = EmprestimoSimulacao::simular($dadosSaldo, '2030-02-01', '2030-02-01', 100.0, $hojeEmp);
$check('saldo informado: a simulação parte dele', abs($simSaldo['faltaAntes'] - 405.0) < 0.005 && abs($simSaldo['falta'] - 305.0) < 0.005, json_encode([$simSaldo['faltaAntes'], $simSaldo['falta']]));
$confere = static fn(array $v): ?string => EmprestimoController::conferirSaldo($v, '2026-11-15', '2026-09-01', '2027-12-01')[0];
$check('saldo informado: valor e mês juntos, em mês do plano que já começou', $confere(['saldo_devedor' => 0.0, 'saldo_devedor_mes' => 0.0]) === null
    && $confere(['saldo_devedor' => 100.0, 'saldo_devedor_mes' => 202611.0]) === null && $confere(['saldo_devedor' => 100.0, 'saldo_devedor_mes' => 0.0]) === 'saldo_devedor_mes'
    && $confere(['saldo_devedor' => 0.0, 'saldo_devedor_mes' => 202611.0]) === 'saldo_devedor' && $confere(['saldo_devedor' => 100.0, 'saldo_devedor_mes' => 202612.0]) === 'saldo_devedor_mes'
    && $confere(['saldo_devedor' => 100.0, 'saldo_devedor_mes' => 202608.0]) === 'saldo_devedor_mes');
$check('saldo informado: o campo em branco lê como zero', AjustesController::lerValores(['saldo_devedor' => ' ', 'saldo_devedor_mes' => ''], AjusteModel::DO_EMPRESTIMO) === ['valores' => ['saldo_devedor' => 0.0, 'saldo_devedor_mes' => 0.0], 'erro' => null]);

// ---- 17. wishlist: previsão com dados de mentira, leitura de link sem rede
$mesesWl = array_map(static fn(string $m): array => ['mes' => $m, 'situacao' => 'PREVISAO'], ['2030-01-01', '2030-02-01', '2030-03-01', '2030-04-01']);
$catSal = ['id' => 1, 'nome' => 'Renda', 'apelido' => null, 'grupo' => 'Entradas', 'sinal' => 1, 'operacional' => 1, 'essencial' => 0, 'ativo' => 1, 'ordem' => 0];
$dadosWl = ['meses' => $mesesWl, 'aj' => ['parcela_mensal' => 0.0, 'juros_mes' => 0.0, 'n_parcelas' => 0.0, 'reserva_minima' => 500.0, 'saldo_ini' => 1000.0, 'poupanca_ini' => 0.0, 'objetivo' => 1.0, 'meta_final' => 0.0],
    'loanIni' => 0.0, 'cats' => [1 => $catSal], 'prev' => array_fill_keys(['2030-01-01', '2030-02-01', '2030-03-01', '2030-04-01'], [1 => 500.0]),
    'excecao' => [], 'metas' => [], 'real' => [], 'n' => [], 'ult' => [], 'esp' => ['cred' => null, 'amort' => null, 'guard' => null, 'resg' => null]];
$hojeWl = '2029-12-20';
$pW = WishlistPrevisao::calcular($dadosWl, '2030-01-01', 1800.0, 1, $hojeWl);   // conta: 1500, 2000, 2500, 3000
$check('wishlist: à vista, cabe no 1º mês em que nada fica abaixo da reserva', $pW['cedo'] === '2030-03-01' && $pW['meses']['2030-01-01']['estado'] === 'neg'
    && $pW['meses']['2030-02-01']['estado'] === 'warn' && $pW['meses']['2030-03-01']['estado'] === 'ok' && !$pW['temDivida'], json_encode($pW['meses']));
$check('wishlist: mês bom mostra o ponto mais apertado depois da compra', abs($pW['meses']['2030-03-01']['pior']['fica'] - 700.0) < 0.005 && $pW['meses']['2030-03-01']['pior']['mes'] === '2030-03-01');
$pP = WishlistPrevisao::calcular($dadosWl, '2030-01-01', 1800.0, 2, $hojeWl);   // 900 em fev e 900 em mar
$check('wishlist: parcelado sai da fatura do mês seguinte e pode caber antes', $pP['cedo'] === '2030-01-01' && abs($pP['meses']['2030-01-01']['pior']['fica'] - 700.0) < 0.005, json_encode($pP['meses']['2030-01-01']));
$pF = WishlistPrevisao::calcular($dadosWl, '2030-01-01', 1200.0, 3, $hojeWl);
$check('wishlist: parcela depois do fim do plano não é avaliada', $pF['meses']['2030-02-01']['estado'] === 'fora' && $pF['meses']['2030-01-01']['estado'] !== 'fora');
$pN = WishlistPrevisao::calcular($dadosWl, '2030-01-01', 9000.0, 1, $hojeWl);
$check('wishlist: não cabe em mês nenhum e diz quanto falta no mais perto', $pN['cedo'] === null && $pN['menorFalta']['mes'] === '2030-04-01' && abs($pN['menorFalta']['valor'] - 6500.0) < 0.01, json_encode($pN['menorFalta']));
$pV = WishlistPrevisao::calcular(array_replace_recursive($dadosWl, ['aj' => ['saldo_ini' => -200.0]]), '2030-01-01', 50.0, 1, $hojeWl);   // sem a compra, jan fica em 300
$check('wishlist: mês já abaixo da reserva sem a compra só não pode piorar', $pV['meses']['2030-01-01']['estado'] === 'warn' && $pV['meses']['2030-02-01']['estado'] === 'ok' && $pV['cedo'] === '2030-02-01', json_encode(array_column($pV['meses'], 'estado')));
$mesesWl8 = [];
for ($i = 1; $i <= 8; $i++) {
    $mesesWl8[] = sprintf('2030-%02d-01', $i);
}
$dadosWlD = array_replace_recursive($dadosWl, ['aj' => ['saldo_ini' => 600.0, 'parcela_mensal' => 100.0, 'juros_mes' => 0.02, 'n_parcelas' => 12.0]]);
$dadosWlD['loanIni'] = 1000.0;
$dadosWlD['meses'] = array_map(static fn(string $m): array => ['mes' => $m, 'situacao' => 'PREVISAO'], $mesesWl8);
$dadosWlD['prev'] = array_fill_keys($mesesWl8, [1 => 400.0]);
$pD = WishlistPrevisao::calcular($dadosWlD, '2030-01-01', 300.0, 1, $hojeWl);
$check('wishlist: comprar antes de quitar custa juros; depois, não', $pD['temDivida'] && $pD['cedo'] === '2030-01-01' && $pD['meses']['2030-01-01']['custo'] > 0.0
    && $pD['semCusto'] !== null && $pD['semCusto'] > $pD['cedo'] && $pD['meses'][$pD['semCusto']]['custo'] < 1.0 && $pD['melhor'] === $pD['semCusto'], json_encode([$pD['cedo'], $pD['semCusto'], array_column($pD['meses'], 'custo')]));
$check('wishlist: a simulação não mexe nos dados', MotorFinanceiro::calcular($dadosWl, ['hoje' => $hojeWl])['2030-04-01']['fica_na_conta'] == 3000.0);

$check('link: aceita o texto do compartilhar e endereço sem https', LinkProduto::normalizar('Olha isso: https://loja.exemplo/p/1?a=b') === 'https://loja.exemplo/p/1?a=b' && LinkProduto::normalizar('loja.exemplo/p/1') === 'https://loja.exemplo/p/1');
$check('link: recusa esquema, porta, usuário e formatos ambíguos', LinkProduto::normalizar('ftp://loja.exemplo/a') === null && LinkProduto::normalizar('file:///etc/passwd') === null && LinkProduto::normalizar('javascript:alert(1)') === null
    && LinkProduto::normalizar('http://loja.exemplo:8080/a') === null && LinkProduto::normalizar('http://x:y@loja.exemplo/') === null && LinkProduto::normalizar('http://loja.exemplo\\@interno/') === null);
foreach (['127.0.0.1', '10.0.0.1', '172.16.0.1', '192.168.1.10', '169.254.169.254', '100.64.0.1', '0.0.0.0', '224.0.0.1', '198.18.0.1', '::1', 'fc00::1'] as $ipRuim) {
    $check("link: IP fora da internet recusado ({$ipRuim})", !LinkProduto::ipPublico($ipRuim));
}
$check('link: IP público aceito', LinkProduto::ipPublico('8.8.8.8'));
foreach (['http://127.0.0.1/', 'http://169.254.169.254/latest/meta-data/', 'http://192.168.1.10/', 'http://2130706433/', 'http://0x7f.0.0.1/', 'http://localhost/'] as $urlRuim) {
    $check("link: não conecta em endereço interno ({$urlRuim})", LinkProduto::buscar($urlRuim, 1024) === null);
}
$htmlP = '<html><head><title>Título ruim</title><meta property="og:title" content="Nome do OG"><meta property="og:image" content="/img/og.jpg"><script type="application/ld+json">{"@graph":[{"@type":"WebSite"},{"@type":"Product","name":"Sofá &amp; Cia","image":["https://cdn.exemplo/a.jpg"],"offers":{"@type":"Offer","price":"1299.90","priceCurrency":"BRL"}}]}</script></head></html>';
$mP = LinkProduto::metadados($htmlP, 'https://loja.exemplo/p/1');
$check('link: lê nome, preço e foto dos dados do produto', $mP['nome'] === 'Sofá & Cia' && $mP['valor'] === 1299.9 && $mP['imagem'] === 'https://cdn.exemplo/a.jpg', json_encode($mP));
$mO = LinkProduto::metadados('<head><title>T</title><meta property="og:title" content="Só OG"><meta property="og:image" content="/img/og.jpg"><meta property="product:price:amount" content="89,90"></head>', 'https://loja.exemplo/p/1');
$check('link: sem dados de produto, usa as etiquetas de compartilhamento', $mO['nome'] === 'Só OG' && $mO['valor'] === 89.9 && $mO['imagem'] === 'https://loja.exemplo/img/og.jpg', json_encode($mO));
$mG = LinkProduto::metadados('<script type="application/ld+json">[{"@type":"ProductGroup","name":"Grupo","hasVariant":[{"@type":"Product","offers":{"price":899,"priceCurrency":"BRL"}}]}]</script>', 'https://loja.exemplo/');
$check('link: grupo de produtos usa o preço da 1ª variação', $mG['nome'] === 'Grupo' && $mG['valor'] === 899.0, json_encode($mG));
$mC = LinkProduto::metadados('<div id="corePrice_feature_div"><span class="a-price"><span class="a-offscreen">R$455,05</span></span></div><img id="x" data-old-hires="https://cdn.exemplo/g.jpg">', 'https://loja.exemplo/');
$check('link: sem dados estruturados, tenta a marcação usual', $mC['valor'] === 455.05 && $mC['imagem'] === 'https://cdn.exemplo/g.jpg', json_encode($mC));
$mU = LinkProduto::metadados('<script type="application/ld+json">{"@type":"Product","name":"X","offers":{"price":"10","priceCurrency":"USD"}}</script>', 'https://loja.exemplo/');
$check('link: preço em dólar vem com a moeda', $mU['valor'] === 10.0 && $mU['moeda'] === 'USD');
$mX = LinkProduto::metadados('<script type="application/ld+json">{"@type":"Product","name":"X","offers":{"price":"10","priceCurrency":"KPW"}}</script>', 'https://loja.exemplo/');
$check('link: moeda que o app não tem não entra', $mX['valor'] === null && $mX['moeda'] === 'BRL' && $mX['nome'] === 'X');
$check('cotação: lê a fonte principal e ignora o resto', Cotacao::lerPrincipal('{"USDBRL":{"code":"USD","codein":"BRL","bid":"5.4397","create_date":"2026-10-30 19:00:08"},"XXXBRL":{"code":"XXX","codein":"BRL","bid":"1","create_date":"2026-10-30 19:00:08"},"EURBRL":{"code":"EUR","codein":"BRL","bid":"zero","create_date":"2026-10-30 19:00:08"}}') === ['USD' => ['valor' => 5.4397, 'data' => '2026-10-30']]);
$check('cotação: a fonte reserva vem invertida', Cotacao::lerReserva('{"base":"BRL","date":"2026-10-30","rates":{"USD":0.2,"XXX":1}}') === ['USD' => ['valor' => 5.0, 'data' => '2026-10-30']]);
$check('cotação: resposta inválida não vira cotação', Cotacao::lerPrincipal('<html>erro</html>') === [] && Cotacao::lerReserva('{"base":"USD","date":"2026-10-30","rates":{"BRL":5}}') === [] && Cotacao::lerPrincipal('{"USDBRL":{"code":"USD","codein":"BRL","bid":"5","create_date":"ontem"}}') === []);
$taxasT = ['USD' => ['valor' => 5.4397, 'data' => '2026-10-30']];
$check('cotação: converte para a moeda do site; a mesma moeda fica igual; sem cotação é null', Cotacao::naMoedaDoSite(100.0, 'USD', $taxasT) === 543.97 && Cotacao::naMoedaDoSite(100.0, 'BRL', []) === 100.0 && Cotacao::naMoedaDoSite(100.0, 'EUR', $taxasT) === null);
$check('cotação: formata na moeda', Cotacao::formatar(1299.0, 'USD') === 'US$' . NBSP . '1.299,00' && Cotacao::formatar(10.0, 'BRL') === money(10.0));
$check('link: imagem data: não é baixada', LinkProduto::metadados('<meta property="og:image" content="data:image/png;base64,AAAA">', 'https://loja.exemplo/')['imagem'] === null);
$check('link: leitura de preços', LinkProduto::preco('1299.90') === 1299.9 && LinkProduto::preco('R$ 1.299,90') === 1299.9 && LinkProduto::preco(50) === 50.0 && LinkProduto::preco('grátis') === null && LinkProduto::preco('0') === null);
$pngT = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
$fotoT = LinkProduto::fotoValida($pngT);
$check('foto: vale pelo conteúdo, não pelo nome', $fotoT !== null && $fotoT['tipo'] === 'image/png' && LinkProduto::fotoValida('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>') === null
    && LinkProduto::fotoValida('<?php echo 1;') === null && LinkProduto::fotoValida(str_repeat('a', LinkProduto::MAX_FOTO + 1)) === null);
$lc = WishlistController::lerCampos(['nome' => "  Sofá\n novo ", 'url' => 'loja.exemplo/a', 'valor' => '1.250,50']);
$check('wishlist: lê o formulário', $lc['nome'] === 'Sofá  novo' || $lc['nome'] === 'Sofá novo', $lc['nome']);
$check('wishlist: moeda inválida vira real; iene aceita valor alto', WishlistController::lerCampos(['moeda' => 'XXX'])['moeda'] === 'BRL' && WishlistController::lerCampos(['moeda' => 'JPY', 'valor' => '2000000'])['valor'] === 2000000.0
    && WishlistController::lerCampos(['moeda' => 'BRL', 'valor' => '2000000'])['erro'] !== null);
$check('wishlist: recusa link e valor inválidos', WishlistController::lerCampos(['url' => 'javascript:alert(1)'])['erro'] !== null && WishlistController::lerCampos(['valor' => '12a'])['erro'] !== null && WishlistController::lerCampos(['valor' => '0'])['erro'] !== null
    && WishlistController::lerCampos(['valor' => ''])['valor'] === null);

// ---- 18. temas: a lista gerada, o contraste de cada um e o tema da conta
$lumT = static function (string $h): float {
    $f = static function (string $x): float {
        $c = hexdec($x) / 255;
        return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    };
    return 0.2126 * $f(substr($h, 1, 2)) + 0.7152 * $f(substr($h, 3, 2)) + 0.0722 * $f(substr($h, 5, 2));
};
$contrasteT = static function (string $a, string $b) use ($lumT): float {
    [$x, $y] = [$lumT($a), $lumT($b)];
    return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
};
$cssTemas = (string) file_get_contents(__DIR__ . '/../public/assets/css/temas.css');
$ruins = [];
$semCss = [];
foreach (Temas::todos() as $idT => $t) {
    foreach (['claro', 'escuro'] as $ladoT) {
        $c = $t[$ladoT];
        foreach ([['tinta', 'fundo'], ['tinta', 'superficie'], ['tinta2', 'fundo'], ['tinta2', 'superficie'], ['azul', 'fundo'], ['sobre', 'azul'], ['entrada', 'superficie'], ['saida', 'superficie'], ['entrada', 'fundo'], ['saida', 'fundo']] as [$fg, $fundo]) {
            if ($contrasteT((string) $c[$fg], (string) $c[$fundo]) < 4.5) {
                $ruins[] = "{$idT} ({$ladoT}): {$fg}/{$fundo}";
            }
        }
    }
    if ($t['paleta'] !== 'padrao' && !str_contains($cssTemas, ':root[data-tema="' . $idT . '"]')) {
        $semCss[] = $idT;
    }
}
$paletasT = Temas::paletas();
$casaT = array_keys(array_filter($paletasT, static fn(array $p): bool => $p['grupo'] === 'Da casa'));
$check('temas: 7 da casa (Claro e Escuro, Calçadão, Azulejo e mais 3), cada paleta em claro, escuro e automático, e cada uma com CSS',
    count($casaT) === 6 && in_array('calcadao', $casaT, true) && in_array('azulejo', $casaT, true) && $semCss === []
    && count(array_filter($paletasT, static fn(array $p): bool => $p['ids']['light'] !== null && $p['ids']['dark'] !== null && ($p['ids']['auto'] !== null || $p['nome'] === 'Padrão'))) === count($paletasT)
    && Temas::todos()['claro']['modo'] === 'light' && Temas::todos()['escuro']['modo'] === 'dark', implode(', ', $semCss));
$check('temas: alto contraste e daltônico ficam no grupo Acessibilidade', array_keys(array_filter($paletasT, static fn(array $p): bool => $p['grupo'] === 'Acessibilidade')) === ['contraste', 'daltonico']);
$check('temas: todo tema passa no contraste, nos dois lados', $ruins === [], implode(', ', $ruins));
$check('temas: um tema só muda fundo, superfície, lateral, realce, fios, tintas e azul; a cor com função só muda na acessibilidade',
    !preg_match('/--(?:f-ui|f-num|t-[a-z]+|e-\d|raio-[a-z]+|lat|linha|curva|seg-[a-z]+|sombra-[a-z]+):/', $cssTemas)
    && !preg_match('/:root\[data-tema="(?:calcadao|azulejo|papel|ameixa|petroleo)[a-z]*"\][^{]*\{[^}]*--(?:entrada|saida|atencao|alerta):/', $cssTemas)
    && str_contains($cssTemas, '--entrada:'));
$check('temas: os lados da paleta e o nome para a pessoa', Temas::lados(null) === ['claro', 'escuro'] && Temas::lados('azulejoauto') === ['azulejo', 'azulejoescuro']
    && Temas::lados('tema-que-saiu') === null && Temas::nome(null) === 'Automático' && Temas::nome('escuro') === 'Escuro' && Temas::nome('calcadaoescuro') === 'Calçadão, escuro');
$pdo->beginTransaction();
try {
    $uTema = (int) Auth::id();
    UsuarioModel::salvarTema($uTema, null);
    $htmlT0 = $render(TemaController::class, 'index', []);
    $check('temas: a tela mostra as paletas e o automático marcado', substr_count($htmlT0, 'class="tm-pal"') === count($paletasT) && str_contains($htmlT0, 'data-tm-auto')
        && preg_match('/<button class="tm is-on"[^>]*data-tm-auto/', $htmlT0) === 1 && !preg_match('/<html[^>]*data-tema=/', $htmlT0) && str_contains($htmlT0, ' data-tema-auto'));
    UsuarioModel::salvarTema($uTema, 'azulejo');
    $htmlT1 = $render(TemaController::class, 'index', []);
    $check('temas: o tema da conta vem no HTML, sem piscar', str_contains($htmlT1, 'data-theme="light" data-tema="azulejo"') && str_contains($htmlT1, 'temas.css'));
    UsuarioModel::salvarTema($uTema, 'papelauto');
    $htmlT2 = $render(TemaController::class, 'index', []);
    $check('temas: o automático de uma paleta só põe a paleta, e o lado segue o aparelho', preg_match('/<html[^>]*data-tema="papelauto"/', $htmlT2) === 1 && !preg_match('/<html[^>]*data-theme=/', $htmlT2)
        && substr_count($htmlT2, 'name="theme-color"') === 2);
    $check('temas: sem atalho de tema na casca, e Temas abre por Ajustes', !str_contains($htmlT1, 'data-theme-toggle') && !str_contains($htmlT1, 'class="tema-seg"')
        && !preg_match('#<nav class="tabbar"(?:(?!<details).)*?href="/temas"#s', $htmlT1) && str_contains($render(AjustesController::class, 'index', []), 'href="/temas"'));
    UsuarioModel::salvarTema($uTema, 'gruvd');
    $check('temas: um tema que saiu da lista vira automático', Temas::doUsuario() === null && str_contains($render(TemaController::class, 'index', []), ' data-tema-auto'));
} finally {
    $pdo->rollBack();
}

// ---- 18b. a base do redesenho: o valor pela regra de cor, o carimbo, a barra do dinheiro do mês e o topo
$check('valor: o que já saiu é vermelho com "−", o que já entrou é verde com "+", com os centavos menores',
    valor(-408.98, 'auto') === '<span class="num v-saida">' . MENOS . '408<span class="cent">,98</span></span>'
    && valor(7710.10, 'auto') === '<span class="num v-entrada">+7.710<span class="cent">,10</span></span>'
    && valor(408.98, 'saida') === valor(-408.98, 'auto') && valor(-5.0, 'entrada') === '<span class="num v-entrada">+5<span class="cent">,00</span></span>');
$check('valor: o previsto fica sem cor; zero não ganha sinal nem cor; a moeda só quando pedida',
    valor(-2350.0, 'previsto') === '<span class="num v-previsto">' . MENOS . '2.350<span class="cent">,00</span></span>'
    && valor(0.0, 'auto') === '<span class="num">0<span class="cent">,00</span></span>' && valor(0.004, 'entrada') === valor(0.0, 'auto')
    && valor(2398.66, 'neutro', ['moeda' => true, 'classe' => 'resposta-num']) === '<span class="num resposta-num"><span class="moeda">R$</span>2.398<span class="cent">,66</span></span>'
    && valor(12.5, 'neutro', ['centavos' => false]) === '<span class="num">12,50</span>');
$check('carimbo: "pago" com o dia', str_contains(carimbo('2026-11-22'), 'pago 22/11') && str_contains(carimbo('2026-11-22', true), 'class="carimbo entra"'));
ob_start();
\Financeiro\View::partial('partials/destino', ['titulo' => 'Para onde vai', 'segmentos' => [
    ['rotulo' => 'Já saiu', 'valor' => 908.98, 'classe' => 's-saiu'],
    ['rotulo' => 'Contas a pagar', 'valor' => 0.0, 'classe' => 's-contas'],
    ['rotulo' => 'Dá para gastar', 'valor' => 2398.66, 'classe' => 's-gastar', 'destaque' => true],
]]);
$destinoT = (string) ob_get_clean();
$check('destino: a barra tem um segmento por valor, a legenda tem todos e o leitor de tela ouve a conta',
    substr_count($destinoT, 'style="--v:') === 2 && str_contains($destinoT, 'style="--v:908.98"') && substr_count($destinoT, '<li') === 3
    && str_contains($destinoT, 'class="destaque"') && str_contains($destinoT, 'role="img" aria-label="De 3.307,64: já saiu 908,98; contas a pagar 0,00; dá para gastar 2.398,66."'));
ob_start();
\Financeiro\View::partial('partials/topo', ['mes' => '2026-11-01', 'meses' => [['mes' => '2026-10-01', 'situacao' => 'REAL'], ['mes' => '2026-11-01', 'situacao' => 'PREVISAO'], ['mes' => '2026-12-01', 'situacao' => 'PREVISAO']],
    'href' => '/mes?mes=', 'apoio' => 'Domingo, dia 22 de 30']);
$topoT = (string) ob_get_clean();
$check('topo: o título é o mês, a linha de apoio embaixo e o seletor com as setas para os vizinhos',
    str_contains($topoT, '<h1>Novembro de 2026</h1>') && str_contains($topoT, '<p class="topo-sub">Domingo, dia 22 de 30</p>')
    && str_contains($topoT, 'href="/mes?mes=2026-10-01" rel="prev" aria-label="Outubro de 2026"') && str_contains($topoT, 'href="/mes?mes=2026-12-01" rel="next"')
    && str_contains($topoT, 'class="mes-picker-m estado-') && !str_contains($topoT, 'Boa'));

// ---- 18a. valor digitado: número sem separador é inteiro; o que é ambíguo é recusado, nunca adivinhado
$valoresPt = ['45' => 45.0, '45,9' => 45.9, '45,90' => 45.9, '1.500' => 1500.0, '1.500,50' => 1500.5, '1,500' => null, '12.50' => null, 'R$ 7' => 7.0];
foreach ($valoresPt as $txt => $esperado) {
    $check("valor em pt: {$txt}", parseMoedaBr((string) $txt) === $esperado);
}
Financeiro\Idioma::usar('en');
foreach (['45' => 45.0, '12.50' => 12.5, '1,500' => 1500.0, '1,500.50' => 1500.5, '1.500' => null] as $txt => $esperado) {
    $check("valor em en: {$txt}", parseMoedaBr((string) $txt) === $esperado);
}
Financeiro\Idioma::usar('es');
$check('valor em es: 1,500 é recusado', parseMoedaBr('1,500') === null);
$check('valor em es: 12.50 é decimal', parseMoedaBr('12.50') === 12.5);
Financeiro\Idioma::usar('pt');
$check('juros: 1,655 continua decimal', parseMoedaBr('1,655', false) === 1.655);

// ---- 18a2. Ajuda: respostas únicas e os "?" das telas apontam para uma pergunta que existe
$idsAjuda = array_column(array_merge(...array_column(Financeiro\Ajuda::grupos(), 'itens')), 'id');
$check('ajuda: cada pergunta tem um id só', count($idsAjuda) === count(array_unique($idsAjuda)));
$usoDica = '';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../src/Views', FilesystemIterator::SKIP_DOTS)) as $fDica) {
    $usoDica .= (string) file_get_contents($fDica->getPathname());
}
preg_match_all("/dica\\('([a-z-]+)'/", $usoDica, $mDica);
$check('ajuda: todo "?" tem resposta', array_diff($mDica[1], $idsAjuda) === [], implode(',', array_diff($mDica[1], $idsAjuda)));

// ---- 18b. conta nova: as regras da primeira conta e de Ajustes
$check('conta nova: nome vazio não passa', UsuarioModel::erroNovaConta('', 'alguem', 'senha-longa') !== null);
$check('conta nova: login vazio não passa', UsuarioModel::erroNovaConta('Alguém', '', 'senha-longa') !== null);
$check('conta nova: senha curta não passa', UsuarioModel::erroNovaConta('Alguém', 'alguem', 'curta') !== null);
$loginT = (string) $pdo->query('SELECT login FROM usuarios ORDER BY id LIMIT 1')->fetchColumn();
$check('conta nova: login repetido não passa', UsuarioModel::erroNovaConta('Alguém', $loginT, 'senha-longa') !== null);
$check('conta nova: dados certos passam', UsuarioModel::erroNovaConta('Alguém', 'login-livre-' . bin2hex(random_bytes(3)), 'senha-longa') === null);
$check('conta nova: com conta no banco, a tela de criar some', !UsuarioModel::nenhum());
$check('senha: a mesma regra em todo lugar, 8 caracteres ou mais (A-07)', UsuarioModel::erroSenha('abcdef') !== null && UsuarioModel::erroSenha('abcdefg') !== null
    && UsuarioModel::erroSenha('abcdefgh') === null && UsuarioModel::erroSenha('çãéíõúàü') === null);
$check('senha: só espaços não serve, nem de outro tipo (A-08)', UsuarioModel::erroSenha(str_repeat(' ', 8)) !== null
    && UsuarioModel::erroSenha(str_repeat("\u{00A0}", 8)) !== null && UsuarioModel::erroSenha("\t\t\t\t\t\t\t\t") !== null
    && UsuarioModel::erroSenha('  senha  ') === null);
$check('conta nova: senha só de espaços não passa (A-08)', UsuarioModel::erroNovaConta('Alguém', 'alguem-' . bin2hex(random_bytes(3)), str_repeat(' ', 8)) !== null);

// ---- 18b2. duas pessoas em Ajustes (A-04): grava só o que a pessoa mudou; o campo que as duas mudaram não é gravado
$mud = AjustesController::mudancas(['reserva_minima' => 1500.0, 'meta_final' => 30000.0], ['reserva_minima' => 1500.0, 'meta_final' => 25000.0], ['reserva_minima' => 2000.0, 'meta_final' => 25000.0]);
$check('ajustes: quem mudou só a meta não desfaz a reserva que a outra pessoa salvou', $mud === ['gravar' => ['meta_final' => 30000.0], 'conflito' => []], json_encode($mud));
$mud = AjustesController::mudancas(['reserva_minima' => 1800.0], ['reserva_minima' => 1500.0], ['reserva_minima' => 2000.0]);
$check('ajustes: o mesmo campo mudado pelas duas pessoas não é gravado e avisa', $mud === ['gravar' => [], 'conflito' => ['reserva_minima']], json_encode($mud));
$check('ajustes: o mesmo valor que já está gravado não é conflito (duplo clique)', AjustesController::mudancas(['reserva_minima' => 2000.0], ['reserva_minima' => 1500.0], ['reserva_minima' => 2000.0])['conflito'] === []);
$check('ajustes: diferença menor que meio centavo é o mesmo valor', AjustesController::mudancas(['reserva_minima' => 1500.0], ['reserva_minima' => 1500.0], ['reserva_minima' => 1500.0012])['gravar'] === []);
$check('ajustes: sem o original, o campo conta como mudado', AjustesController::mudancas(['reserva_minima' => 10.0], [], ['reserva_minima' => 20.0]) === ['gravar' => ['reserva_minima' => 10.0], 'conflito' => []]);
$mud = AjustesController::mudancas(['usa_carro' => 1.0, 'usa_emprestimo' => 0.0], ['usa_carro' => 1.0, 'usa_emprestimo' => 1.0], ['usa_carro' => 0.0, 'usa_emprestimo' => 1.0]);
$check('ajustes: em "O que você usa", desligar o empréstimo não liga de novo o carro que a outra pessoa desligou', $mud === ['gravar' => ['usa_emprestimo' => 0.0], 'conflito' => []], json_encode($mud));
$check('ajustes: moeda e fuso também', AjustesController::mudancas(['moeda' => 'EUR', 'fuso' => 'America/Sao_Paulo'], ['moeda' => 'BRL', 'fuso' => 'America/Sao_Paulo'], ['moeda' => 'BRL', 'fuso' => 'Europe/Lisbon'])
    === ['gravar' => ['moeda' => 'EUR'], 'conflito' => []]);
$htmlAj = $pagina(AjustesController::class, 'index', '/ajustes');
$check('ajustes: cada formulário manda o valor que mostrou ao abrir', str_contains($htmlAj, 'name="orig[reserva_minima]" value="' . numInput(AjusteModel::valor('reserva_minima')) . '"')
    && str_contains($htmlAj, 'name="orig[meta_mes]"') && str_contains($htmlAj, 'name="orig[usa_carro]"') && str_contains($htmlAj, 'name="orig[moeda]"'));

// ---- 18b3. a vitrine da tela de entrar no formato do idioma (A-11)
$vitrine = static function (string $idioma): string {
    Idioma::usar($idioma);
    ob_start();
    \Financeiro\View::renderAuth('auth/login', ['erro' => null, 'expirou' => false, 'login' => '', 'lembrar' => false]);
    $html = (string) ob_get_clean();
    Idioma::usar('pt');
    return $html;
};
$vitEn = $vitrine('en');
$vitPt = $vitrine('pt');
$check('tela de entrar: a vitrine usa o formato de número do idioma', str_contains($vitEn, '2,398<span class="cent">.66</span>') && str_contains($vitEn, '1,646<span class="cent">.70</span>')
    && str_contains($vitEn, "\u{2212}2,350<span class=\"cent\">.00</span>") && !str_contains($vitEn, '2.398') && str_contains($vitPt, '2.398<span class="cent">,66</span>'));

// ---- 18b4. categorias que o app usa nos cálculos não saem nem arquivam (A-12)
$protegidasOk = true;
foreach ([MotorFinanceiro::CAT_SALARIO, MotorFinanceiro::CAT_PARCELA, MotorFinanceiro::CAT_FATURA, MotorFinanceiro::CAT_GUARDAR, MotorFinanceiro::CAT_APLICAR] as $chaveP) {
    $catP = CategoriaModel::porChave($chaveP);
    $protegidasOk = $protegidasOk && $catP !== null && CategoriaModel::protegida($catP) && CategoriaModel::remover((int) $catP['id']) === 'protegida'
        && (int) (CategoriaModel::find((int) $catP['id'])['ativo'] ?? 0) === 1;
}
$check('categorias do app: remover não apaga nem arquiva', $protegidasOk);
$salP = CategoriaModel::porChave(MotorFinanceiro::CAT_SALARIO);
$errosP = CategoriaModel::editar((int) $salP['id'], ['nome' => (string) $salP['nome'], 'apelido' => (string) ($salP['apelido'] ?? ''), 'icone' => '', 'tipo' => 'entrada',
    'valor_tipo' => '', 'dia' => '', 'essencial' => false, 'ativo' => false]);
$check('categorias do app: editar não arquiva', isset($errosP['ativo']) && (int) CategoriaModel::find((int) $salP['id'])['ativo'] === 1);
$check('categorias do app: a comum continua saindo', !CategoriaModel::protegida(CategoriaModel::porNome('Mercado') ?? ['chave' => 'x']));
ob_start();
(new CategoriaController())->editarForm((string) $salP['id']);
$htmlSal = (string) ob_get_clean();
$check('categorias do app: a tela não oferece remover e diz o porquê', !str_contains($htmlSal, '/remover"') && str_contains($htmlSal, 'não pode ser removida nem arquivada')
    && !str_contains($htmlSal, 'name="ativo" value="0"'));

// ---- 18b5. Pessoas: remover o acesso (A-15). A pessoa não entra mais, o que ela lançou fica, e o site nunca fica sem ninguém
$loginRA = 'remover-' . bin2hex(random_bytes(3));
$idRA = UsuarioModel::criar('Teste remover', $loginRA, 'senha-remover');
$idRB = UsuarioModel::criar('Teste fica', $loginRA . '-b', 'senha-remover');
$hashesRA = $pdo->query('SELECT id, senha_hash FROM usuarios')->fetchAll(PDO::FETCH_KEY_PAIR);
$sessaoRA = $_SESSION;
try {
    $versaoRA = (int) UsuarioModel::sessaoVersao($idRA);
    Auth::lembrar($idRA);
    $check('pessoas: ninguém remove o próprio acesso', UsuarioModel::removerAcesso($idRB, $idRB) === 'proprio');
    $rRA = UsuarioModel::removerAcesso($idRA, $idRB);
    Auth::sairDeTodos($idRA);
    $_SESSION = [];
    $check('pessoas: removido, não entra mais, nem pelo aparelho lembrado', $rRA === 'ok' && Auth::primeiroPasso($loginRA, 'senha-remover', false) === 'errada'
        && !Auth::tentarLembrado() && (int) UsuarioModel::sessaoVersao($idRA) > $versaoRA);
    $listaRA = array_column(UsuarioModel::todos(), 'acesso', 'id');
    $check('pessoas: a pessoa continua no banco, sem acesso, e o login fica reservado', ($listaRA[$idRA] ?? null) === 0 && ($listaRA[$idRB] ?? null) === 1
        && UsuarioModel::loginExiste($loginRA) && UsuarioModel::removerAcesso($idRA, $idRB) === 'inexistente');
    UsuarioModel::trocarSenha($idRA, 'senha-de-volta');
    $_SESSION = [];
    $check('pessoas: uma senha nova devolve o acesso', Auth::primeiroPasso($loginRA, 'senha-de-volta', false) === 'entrou');
    // só uma pessoa com acesso: ela não sai, e é ela quem administra
    $pdo->prepare('UPDATE usuarios SET senha_hash = ? WHERE id <> ?')->execute([UsuarioModel::SEM_ACESSO, $idRB]);
    $check('pessoas: a última pessoa com acesso não sai', UsuarioModel::removerAcesso($idRB, $idRA) === 'ultimo' && UsuarioModel::administradorId() === $idRB);
} catch (\Throwable $e) {
    $check('pessoas: remover o acesso', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    $stRA = $pdo->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ?');
    foreach ($hashesRA as $idH => $hashH) {
        $stRA->execute([$hashH, $idH]);
    }
    $pdo->prepare('DELETE FROM sessoes_lembradas WHERE usuario_id IN (?, ?)')->execute([$idRA, $idRB]);
    $pdo->prepare('DELETE FROM usuarios WHERE id IN (?, ?)')->execute([$idRA, $idRB]);
    unset($_COOKIE[Auth::COOKIE_LEMBRAR]);
    $_SESSION = $sessaoRA;
}
$check('pessoas: a conta mais antiga com acesso administra', UsuarioModel::administradorId() === $uid);

// ---- 18b6. fuso com acento só no nome mostrado (A-16)
$check('fuso: "São Paulo" com acento, e o identificador continua o mesmo', Site::nomeFuso('America/Sao_Paulo') === 'America/São Paulo'
    && Site::cidadeDoFuso('America/Sao_Paulo') === 'São Paulo' && Site::nomeFuso('America/Argentina/Buenos_Aires') === 'America/Argentina/Buenos Aires'
    && str_starts_with(Site::opcoesFuso()['America/Sao_Paulo'], 'America/São Paulo (UTC') && Site::nomeFuso('UTC') === 'UTC');
$htmlFuso = $pagina(AjustesController::class, 'index', '/ajustes');
$check('fuso: a linha de Ajustes e a lista mostram "São Paulo"', str_contains($htmlFuso, e(moedaSimbolo()) . ', São Paulo</span>')
    && str_contains($htmlFuso, '<option value="America/Sao_Paulo" selected>America/São Paulo (UTC') && !str_contains($htmlFuso, 'Sao Paulo'));

// ---- 18c. verificação em duas etapas: TOTP da RFC 6238, janela, reuso, recuperação, login, limite, desligar e o
// comando que redefine a senha. Numa conta só do teste, com um instante fixo (o código não usa Relogio).
$rfc = '12345678901234567890';      // a semente SHA1 dos vetores da RFC 6238, apêndice B
$vetoresOk = true;
foreach ([59 => '94287082', 1111111109 => '07081804', 1111111111 => '14050471', 1234567890 => '89005924', 2000000000 => '69279037', 20000000000 => '65353130'] as $instanteV => $esperado) {
    $vetoresOk = $vetoresOk && DuasEtapas::codigo($rfc, DuasEtapas::passo($instanteV), 8) === $esperado;
}
$check('duas etapas: os vetores SHA1 da RFC 6238 batem', $vetoresOk);
$check('duas etapas: Base32 da RFC 4648, ida e volta', DuasEtapas::base32('foobar') === 'MZXW6YTBOI' && DuasEtapas::deBase32('mzxw 6ytb oi======') === 'foobar'
    && DuasEtapas::deBase32('M1') === null && DuasEtapas::deBase32(DuasEtapas::base32($rfc)) === $rfc);
$pT = DuasEtapas::passo(1_800_000_000);
$cod = static fn(int $p): string => DuasEtapas::codigo($rfc, $p);
$check('duas etapas: o passo de agora e um de cada lado valem', DuasEtapas::conferirCodigo($rfc, $cod($pT), $pT, null) === $pT
    && DuasEtapas::conferirCodigo($rfc, $cod($pT - 1), $pT, null) === $pT - 1 && DuasEtapas::conferirCodigo($rfc, $cod($pT + 1), $pT, null) === $pT + 1);
$check('duas etapas: dois passos longe não vale', DuasEtapas::conferirCodigo($rfc, $cod($pT - 2), $pT, null) === null && DuasEtapas::conferirCodigo($rfc, $cod($pT + 2), $pT, null) === null);
$check('duas etapas: código já usado, ou mais velho que o usado, não vale de novo', DuasEtapas::conferirCodigo($rfc, $cod($pT), $pT, $pT) === null
    && DuasEtapas::conferirCodigo($rfc, $cod($pT - 1), $pT, $pT) === null && DuasEtapas::conferirCodigo($rfc, $cod($pT + 1), $pT, $pT) === $pT + 1);
$check('duas etapas: só 6 dígitos', DuasEtapas::conferirCodigo($rfc, 'abcdef', $pT, null) === null && DuasEtapas::conferirCodigo($rfc, substr($cod($pT), 0, 5), $pT, null) === null);
$recT = DuasEtapas::novosCodigosRecuperacao();
$check('duas etapas: 10 códigos de recuperação diferentes, sem letra que confunde', count(array_unique($recT)) === 10
    && array_filter($recT, static fn(string $c): bool => !preg_match('/^[A-HJ-NP-Z2-9]{5}-[A-HJ-NP-Z2-9]{5}$/D', $c)) === []);

$sessaoAntes = $_SESSION;
$loginDE = 'duas-etapas-' . bin2hex(random_bytes(3));
$idDE = UsuarioModel::criar('Teste das duas etapas', $loginDE, 'senha-do-teste');
$instDE = $pT * 30;                  // o instante dos códigos da conta: passo $pT
$tentativasDE = static function () use ($pdo, $loginDE): int {
    $st = $pdo->prepare('SELECT tentativas FROM login_tentativas WHERE login = ?');
    $st->execute([$loginDE]);
    return (int) $st->fetchColumn();
};
$sairDE = static function (): void {
    $_SESSION = [];
    unset($_COOKIE[Auth::COOKIE_LEMBRAR]);
};
$zerarDE = static fn() => $pdo->prepare('DELETE FROM login_tentativas WHERE login IN (?, ?)')->execute([$loginDE, Auth::chaveDaAcao($idDE)]);
// os erros de quem já está logado (desligar, trocar a senha) contam à parte do login (A-13)
$tentativasLogado = static function () use ($pdo, $idDE): int {
    $st = $pdo->prepare('SELECT tentativas FROM login_tentativas WHERE login = ?');
    $st->execute([Auth::chaveDaAcao($idDE)]);
    return (int) $st->fetchColumn();
};
try {
    $sairDE();
    $check('duas etapas: desligada, a senha basta', Auth::primeiroPasso($loginDE, 'senha-do-teste', false) === 'entrou' && Auth::id() === $idDE
        && str_contains($pagina(AjustesController::class, 'index', '/ajustes'), 'id="duasetapas"'));
    $_GET = [];
    ob_start();
    (new \Financeiro\Controllers\DuasEtapasController())->index();
    $htmlLigar = (string) ob_get_clean();
    $chaveTela = DuasEtapas::deBase32((string) ($_SESSION['duas_etapas_novo'] ?? ''));
    $check('duas etapas: ligar mostra o QR e a chave para digitar', str_contains($htmlLigar, '<svg') && $chaveTela !== null
        && str_contains($htmlLigar, e(DuasEtapas::chaveParaDigitar($chaveTela))) && str_contains($htmlLigar, 'autocomplete="one-time-code"'));

    $recDE = DuasEtapas::ligar($idDE, $rfc, $pT - 5);
    $guardado = (string) $pdo->query('SELECT duas_etapas_segredo FROM usuarios WHERE id = ' . $idDE)->fetchColumn();
    $hashesDE = $pdo->query('SELECT codigo_hash FROM duas_etapas_recuperacao WHERE usuario_id = ' . $idDE)->fetchAll(PDO::FETCH_COLUMN);
    $check('duas etapas: o segredo fica cifrado, com a chave no config.env', DuasEtapas::ligada($idDE) && str_starts_with($guardado, 'v1:')
        && !str_contains($guardado, DuasEtapas::base32($rfc)) && DuasEtapas::decifrar($guardado) === $rfc
        && str_contains((string) file_get_contents(\Financeiro\Paths::config()), 'CHAVE_DUAS_ETAPAS='));
    $check('duas etapas: os códigos de recuperação ficam só como hash', count($hashesDE) === 10
        && array_filter($hashesDE, static fn($h): bool => in_array(str_replace('-', '', (string) $h), array_map(static fn($c) => str_replace('-', '', $c), $recDE), true)) === []);

    // login com a verificação: a senha sozinha não entra
    $sairDE();
    $passoDE = Auth::primeiroPasso($loginDE, 'senha-do-teste', true);
    $pend = Auth::esperandoCodigo();
    $check('duas etapas: com a verificação, a senha leva ao código', $passoDE === 'codigo' && !Auth::check() && ($pend['id'] ?? 0) === $idDE && ($pend['lembrar'] ?? false) === true);
    $erradoDE = '000000';
    foreach ([$pT - 1, $pT, $pT + 1] as $pp) {
        if ($cod($pp) === $erradoDE) {
            $erradoDE = '999999';
        }
    }
    $check('duas etapas: código errado não entra', Auth::segundoPasso($erradoDE, $instDE) === 'errado' && !Auth::check() && Auth::esperandoCodigo() !== null);
    $check('duas etapas: código certo entra e zera as tentativas', Auth::segundoPasso($cod($pT), $instDE) === 'entrou' && Auth::id() === $idDE
        && Auth::esperandoCodigo() === null && $tentativasDE() === 0);
    $sairDE();
    Auth::primeiroPasso($loginDE, 'senha-do-teste', false);
    $check('duas etapas: o mesmo código não vale de novo, nem um mais velho', Auth::segundoPasso($cod($pT), $instDE) === 'errado' && Auth::segundoPasso($cod($pT - 1), $instDE) === 'errado');
    $check('duas etapas: o código seguinte vale', Auth::segundoPasso($cod($pT + 1), $instDE) === 'entrou' && Auth::id() === $idDE);
    $zerarDE();

    // recuperação: uso único, sem diferença de caixa e espaço
    $sairDE();
    Auth::primeiroPasso($loginDE, 'senha-do-teste', false);
    $digitadoRec = strtolower(str_replace('-', ' ', $recDE[3]));
    $check('duas etapas: código de recuperação entra', Auth::segundoPasso($digitadoRec, $instDE) === 'entrou' && DuasEtapas::codigosRestantes($idDE) === 9);
    $sairDE();
    Auth::primeiroPasso($loginDE, 'senha-do-teste', false);
    $check('duas etapas: código de recuperação vale uma vez só', Auth::segundoPasso($recDE[3], $instDE) === 'errado' && DuasEtapas::codigosRestantes($idDE) === 9);
    $zerarDE();

    // sem a chave do config.env (perdida, ou de outro computador): o app não vale, a recuperação vale
    $chaveCerta = (string) Config::get('CHAVE_DUAS_ETAPAS');
    Config::definir('CHAVE_DUAS_ETAPAS', base64_encode(random_bytes(32)));
    $semChaveApp = DuasEtapas::conferirDaConta($idDE, $cod($pT + 2), $instDE + 60);
    $semChaveRec = DuasEtapas::conferirDaConta($idDE, $recDE[4], $instDE);
    Config::definir('CHAVE_DUAS_ETAPAS', $chaveCerta);
    $check('duas etapas: sem a chave, só a recuperação abre', !$semChaveApp && $semChaveRec);

    // aparelho lembrado continua entrando direto
    Auth::lembrar($idDE);
    $_SESSION = [];
    $check('duas etapas: aparelho lembrado entra sem o código', Auth::tentarLembrado() && Auth::id() === $idDE);

    // limite: o código conta junto com a senha, e a senha certa não zera
    $sairDE();
    $zerarDE();
    Auth::primeiroPasso($loginDE, 'senha-do-teste', false);
    Auth::segundoPasso($erradoDE, $instDE);
    Auth::segundoPasso($erradoDE, $instDE);
    Auth::primeiroPasso($loginDE, 'senha-do-teste', false);
    $check('duas etapas: acertar a senha de novo não zera as tentativas do código', $tentativasDE() === 2);
    $res = [Auth::segundoPasso($erradoDE, $instDE), Auth::segundoPasso($erradoDE, $instDE), Auth::segundoPasso($erradoDE, $instDE)];
    $check('duas etapas: na 5ª tentativa errada, bloqueia', $res === ['errado', 'errado', 'bloqueado'] && Auth::segundosBloqueado($loginDE) > 0 && Auth::esperandoCodigo() === null, implode(',', $res));
    Auth::primeiroPasso($loginDE, 'senha-do-teste', false);
    $check('duas etapas: bloqueado, nem o código certo entra', Auth::segundoPasso($cod($pT + 2), $instDE + 60) === 'bloqueado' && !Auth::check());
    $zerarDE();

    // desligar: senha e código, contados juntos, à parte do login
    $sairDE();
    $_SESSION['usuario_id'] = $idDE;
    $check('duas etapas: desligar com a senha errada não passa nem gasta código', Auth::conferirSenhaECodigo($idDE, 'outra-senha', $recDE[5]) === 'errada' && DuasEtapas::codigosRestantes($idDE) === 8);
    $check('duas etapas: desligar com o código errado não passa', Auth::conferirSenhaECodigo($idDE, 'senha-do-teste', $erradoDE, $instDE + 60) === 'errada'
        && $tentativasLogado() === 2 && $tentativasDE() === 0);
    $check('duas etapas: senha e código certos conferem e zeram', Auth::conferirSenhaECodigo($idDE, 'senha-do-teste', $cod($pT + 2), $instDE + 60) === 'ok' && $tentativasLogado() === 0);

    // A-13: o bloqueio da tela de entrar não vale para quem já está logado, e o de quem está logado não vale para entrar
    for ($i = 0; $i < 5; $i++) {
        Auth::primeiroPasso($loginDE, 'chute-errado', false);
    }
    $check('duas etapas: os chutes na tela de entrar não bloqueiam quem já está logado', Auth::segundosBloqueado($loginDE) > 0
        && Auth::conferirSenhaECodigo($idDE, 'senha-do-teste', $cod($pT + 3), $instDE + 90) === 'ok' && Auth::conferirSenhaAtual($idDE, 'senha-do-teste') === 'ok');
    $zerarDE();
    $resLogado = [];
    for ($i = 0; $i < 5; $i++) {
        $resLogado[] = Auth::conferirSenhaAtual($idDE, 'chute-errado');
    }
    $check('duas etapas: 5 erros de quem está logado bloqueiam só essas ações, por alguns minutos', $resLogado === ['errada', 'errada', 'errada', 'errada', 'bloqueado']
        && Auth::conferirSenhaAtual($idDE, 'senha-do-teste') === 'bloqueado' && Auth::conferirSenhaECodigo($idDE, 'senha-do-teste', $cod($pT + 4), $instDE + 120) === 'bloqueado'
        && Auth::minutosBloqueadoLogado($idDE) === 5 && Auth::segundosBloqueado($loginDE) === 0, implode(',', $resLogado));
    $sessaoLogado = $_SESSION;
    $check('duas etapas: o bloqueio de quem está logado não impede de entrar', Auth::primeiroPasso($loginDE, 'senha-do-teste', false) === 'codigo');
    $_SESSION = $sessaoLogado;
    $zerarDE();
    DuasEtapas::desligar($idDE);
    $sairDE();
    $check('duas etapas: desligada, apaga o segredo e os códigos, e a senha volta a bastar', !DuasEtapas::ligada($idDE) && DuasEtapas::codigosRestantes($idDE) === 0
        && Auth::primeiroPasso($loginDE, 'senha-do-teste', false) === 'entrou');

    // o comando que redefine a senha também desliga, com aviso
    DuasEtapas::ligar($idDE, $rfc, $pT);
    $proc = proc_open([...\Financeiro\Atualizacao::comandoPhp(), __DIR__ . '/../tools/redefinir-senha.php', $loginDE],
        [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $tubos, \Financeiro\Atualizacao::pastaDoProcesso(dirname(__DIR__)), array_filter(getenv(), 'is_string'));
    $saidaSenha = is_resource($proc) ? (string) stream_get_contents($tubos[1]) : '';
    if (is_resource($proc)) {
        fclose($tubos[1]);
        proc_close($proc);
    }
    $check('duas etapas: redefinir a senha desliga a verificação e avisa', !DuasEtapas::ligada($idDE) && DuasEtapas::codigosRestantes($idDE) === 0
        && str_contains($saidaSenha, 'Senha nova de') && str_contains($saidaSenha, 'verificação em duas etapas desta conta foi desligada'), trim($saidaSenha));
} catch (\Throwable $e) {
    $check('duas etapas', false, get_class($e) . ': ' . $e->getMessage() . ' em ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $zerarDE();
    $pdo->prepare('DELETE FROM sessoes_lembradas WHERE usuario_id = ?')->execute([$idDE]);
    $pdo->prepare('DELETE FROM duas_etapas_recuperacao WHERE usuario_id = ?')->execute([$idDE]);
    $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$idDE]);
    unset($_COOKIE[Auth::COOKIE_LEMBRAR]);
    $_SESSION = $sessaoAntes;
}

// ---- 19. nome de instituição financeira não entra no projeto. Só os SHA-256 ficam aqui, para este arquivo não
// citar nenhum nome; para acrescentar um: printf %s 'palavra em minúsculas' | sha256sum
$proibidas = [
    'de5ce798a166af199765b83b7e6fe89d3f251093e2d1721a4ab38a99b92c12e9',
    'd7412e52cbe5b676ecdc0383c60539c604d0a4089209ab508edbc8d008de1a5b',
];
$temProibida = static function (string $texto, array $resumos): bool {
    foreach (array_unique(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($texto), -1, PREG_SPLIT_NO_EMPTY) ?: []) as $palavra) {
        if (in_array(hash('sha256', $palavra), $resumos, true)) {
            return true;
        }
    }
    return false;
};
$check('nomes proibidos: compara palavra a palavra, sem diferenciar maiúsculas', $temProibida('Pago com a EXEMPLO, hoje.', [hash('sha256', 'exemplo')])
    && !$temProibida('sem exemplos aqui', [hash('sha256', 'exemplo')]));
$raiz = dirname(__DIR__);
$achados = [];
$arquivos = array_merge(glob($raiz . '/*.md') ?: [], [$raiz . '/instalar.sh']);
foreach (['src', 'public/assets/js', 'public/assets/css', 'docs', 'database', 'tools', 'tests', '.github'] as $pasta) {
    if (is_dir($raiz . '/' . $pasta)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz . '/' . $pasta, FilesystemIterator::SKIP_DOTS)) as $f) {
            // database/data é do site, fora do repositório: o atualizador preserva e o teste não confere. Os pacotes
            // do npm e os resultados dos testes de navegador também ficam fora do repositório
            $foraDoRepo = '#/(database/data|tests/navegador/(node_modules|test-results|playwright-report|blob-report))/#';
            if (preg_match('/\.(php|md|sql|js|css|sh|py|json|yml)$/', $f->getFilename()) && !preg_match($foraDoRepo, str_replace('\\', '/', $f->getPathname()))) {
                $arquivos[] = $f->getPathname();
            }
        }
    }
}
foreach ($arquivos as $arq) {
    if (is_file($arq) && $temProibida((string) file_get_contents($arq), $proibidas)) {
        $achados[] = substr($arq, strlen($raiz) + 1);
    }
}
$check('nenhum arquivo cita instituição financeira', $achados === [], implode(', ', $achados));

// ---- 20. o selo do README acompanha o VERSION
$arqVersao = __DIR__ . '/../VERSION';
$arqReadme = __DIR__ . '/../README.md';
if (is_file($arqVersao) && is_file($arqReadme)) {
    $versao = trim((string) file_get_contents($arqVersao));
    $readme = (string) file_get_contents($arqReadme);
    $check('versão: o selo do README diz o mesmo que o VERSION', str_contains($readme, "[![Versão {$versao}](") && str_contains($readme, "-{$versao}-blue)"), $versao);
}

// ---- 23. mensagens em frase, tamanho mínimo e nada de texto escrito no CSS
$telegrafico = [];
foreach (array_merge(glob(__DIR__ . '/../src/Controllers/*.php'), glob(__DIR__ . '/../src/Services/*.php')) as $arq) {
    if (preg_match_all("/\\bt\\('[^' ]{1,20}\\?'/u", (string) file_get_contents($arq), $mT) > 0) {
        array_push($telegrafico, ...$mT[0]);
    }
}
$check('mensagens: nenhuma do tipo "valor?"', $telegrafico === [], implode(', ', $telegrafico));
$fonteMiuda = [];
$textoCss = [];
foreach (glob(__DIR__ . '/../public/assets/css/*.css') as $arq) {
    $css = (string) file_get_contents($arq);
    if (preg_match_all('/font-size:\s*(\d+(?:\.\d+)?)px/', $css, $mF) > 0) {
        foreach ($mF[1] as $tam) {
            if ((float) $tam < 12) {
                $fonteMiuda[] = basename($arq) . ':' . $tam;
            }
        }
    }
    if (preg_match('/content:\s*"[^"]*\p{L}{3}/u', $css) === 1) {
        $textoCss[] = basename($arq);
    }
}
$check('CSS: nenhum texto menor que 12px', $fonteMiuda === [], implode(', ', $fonteMiuda));
$check('CSS: nenhuma palavra escrita no CSS, fora do alcance da tradução', $textoCss === [], implode(', ', $textoCss));

// ---- 24. moeda, número e fuso por site; categorias de fábrica no idioma de quem vê
$valoresT = [0.01, 0.5, 1.0, 12.5, 45.9, 999.99, 1000.0, 1500.0, 1234.56, 1000000.0, 12345678.9, -8233.11];
foreach (['pt', 'en', 'es'] as $idT) {
    Idioma::usar($idT);
    foreach (['BRL', 'USD', 'EUR', 'CLP', 'PEN'] as $moedaT) {
        Site::usarMoeda($moedaT);
        $ruins = [];
        foreach ($valoresT as $v) {
            foreach (['numInput' => numInput($v), 'num' => num($v), 'money' => money($v)] as $fn => $txt) {
                if (parseMoedaBr($txt) !== $v) {
                    $ruins[] = "{$fn}({$v}) = {$txt}";
                }
            }
        }
        $check("ida e volta do valor em {$idT}, {$moedaT}", $ruins === [], implode('; ', $ruins));
    }
    Site::usarMoeda('BRL');
    $check("ida e volta com 4 casas em {$idT}", parseMoedaBr(numInput(20000.0, 4)) === 20000.0 && parseMoedaBr(numInput(12.5, 4)) === 12.5 && parseMoedaBr(numInput(1234.5678, 4)) === 1234.5678);
    $check("ida e volta sem casas em {$idT}", parseMoedaBr(numInput(1500.0, 0)) === 1500.0 && parseMoedaBr(numInput(72.0, 0)) === 72.0);
    $check("ida e volta da taxa de juros em {$idT}", parseMoedaBr(numInput(1.65), false) === 1.65 && parseMoedaBr(numInput(0.0165, 4), false) === 0.0165);
    $check("o exemplo vazio dos campos é lido em {$idT}", parseMoedaBr(numInput(0)) === 0.0);
}
Idioma::usar('en');
$check('número em en: 1,234.56', num(1234.56) === '1,234.56' && numInput(-1234.56) === '-1,234.56' && money(1234.56) === 'R$' . NBSP . '1,234.56');
$check('número em en: 1.500 continua recusado no dinheiro, e é 1,5 numa taxa', parseMoedaBr('1.500') === null && parseMoedaBr('1.500', false) === 1.5);
$check('número em en: resumido', moneyCompact(16800.0) === '16.8' . NBSP . t('mil') && moneyCompact(10000.0) === '10' . NBSP . t('mil'));
$check('data em en: Nov 22', diaMes('2026-11-22') === 'Nov 22' && diaMes('2026-11-02') === 'Nov 2' && diaMesHora('2026-11-22 14:02:00') === 'Nov 22 14:02' && diaMesAno('2026-11-22') === 'Nov 22, 2026' && diaMesCurto('2026-11-22') === 'Nov 22');
Idioma::usar('es');
$check('número em es: 1.234,56', num(1234.56) === '1.234,56' && money(1234.56) === 'R$' . NBSP . '1.234,56');
$check('data em es: 22/11', diaMes('2026-11-22') === '22/11' && diaMesAno('2026-11-22') === '22/11/2026' && diaMesCurto('2026-11-22') === '22 nov');
Idioma::usar('pt');
$check('número em pt: como sempre', money(-1234.56) === MENOS . 'R$' . NBSP . '1.234,56' && moneySigned(1.0) === '+R$' . NBSP . '1,00' && num(1500.0, 0) === '1.500' && moneyCompact(16800.0) === '16,8' . NBSP . 'mil');
$check('data em pt: como sempre', diaMes('2026-11-22') === '22/11' && diaMesHora('2026-11-22 14:02:00') === '22/11 14:02' && diaMesCurto('2026-11-22') === '22 nov');
$check('moeda: nada de R$ no dinheiro de outra moeda', (static function (): bool { Site::usarMoeda('EUR'); $r = money(10.0) === '€' . NBSP . '10,00' && moedaSimbolo() === '€'; Site::usarMoeda('BRL'); return $r; })());
$check('moeda: toda moeda do site tem símbolo e nome', array_diff_key(Site::MOEDAS, Cotacao::MOEDAS) === [] && count(Site::opcoesMoeda()) === count(Site::MOEDAS));

// a cotação vem em reais: as outras se cruzam pelo real
$taxasC = ['USD' => ['valor' => 5.0, 'data' => '2030-01-02'], 'EUR' => ['valor' => 6.0, 'data' => '2030-01-03']];
Site::usarMoeda('USD');
$check('cotação: real vira a moeda do site', Cotacao::naMoedaDoSite(100.0, 'BRL', $taxasC) === 20.0 && Cotacao::dataDe('BRL', $taxasC) === '2030-01-02');
$check('cotação: cruza pelo real', Cotacao::naMoedaDoSite(100.0, 'EUR', $taxasC) === 120.0 && Cotacao::naMoedaDoSite(100.0, 'USD', $taxasC) === 100.0 && Cotacao::naMoedaDoSite(100.0, 'GBP', $taxasC) === null);
Site::usarMoeda('COP');
$check('cotação: sem cotação da moeda do site não inventa', Cotacao::naMoedaDoSite(100.0, 'USD', $taxasC) === null && Cotacao::naMoedaDoSite(100.0, 'COP', $taxasC) === 100.0);
$check('moeda de valor baixo aceita valores altos', valorMaximo() === 1000000000.0);
Site::usarMoeda('BRL');
$check('real: o limite de sempre', valorMaximo() === 1000000.0);

// fuso: o deslocamento sai do PHP, com o horário de verão, e vale também na conexão
$quandoF = static fn(string $d): DateTimeImmutable => new DateTimeImmutable($d . ' 12:00:00 UTC');
$check('fuso: Brasília', Site::deslocamento('America/Sao_Paulo', $quandoF('2026-01-15')) === '-03:00' && Site::deslocamento('America/Sao_Paulo', $quandoF('2026-07-15')) === '-03:00');
$check('fuso: horário de verão', Site::deslocamento('America/New_York', $quandoF('2026-01-15')) === '-05:00' && Site::deslocamento('America/New_York', $quandoF('2026-07-15')) === '-04:00' && Site::deslocamento('Europe/Madrid', $quandoF('2026-07-15')) === '+02:00');
$check('fuso: minutos e extremos', Site::deslocamento('Asia/Kolkata', $quandoF('2026-01-15')) === '+05:30' && Site::deslocamento('Asia/Kathmandu', $quandoF('2026-01-15')) === '+05:45' && Site::deslocamento('Pacific/Kiritimati', $quandoF('2026-01-15')) === '+14:00' && Site::deslocamento('Pacific/Pago_Pago', $quandoF('2026-01-15')) === '-11:00');
$nomesF = DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC);
$check('fuso: o número guardado identifica um nome só', count(array_unique(array_map('crc32', $nomesF))) === count($nomesF));
$check('fuso: o número volta ao nome; o desconhecido vale Brasília', Site::fusoDoNumero(crc32('America/Mexico_City')) === 'America/Mexico_City' && Site::fusoDoNumero(1) === Site::FUSO_PADRAO && Site::fusoDoNumero(null) === Site::FUSO_PADRAO);
$check('fuso: inválido não passa', !Site::fusoValido('Marte/Olympus') && !Site::fusoValido("x' OR 1") && Site::fusoValido('America/Mexico_City') && !Site::moedaValida('XXX'));

// as categorias de fábrica são as de src/Preparo.php
preg_match_all("/^ +\['([^']+)', (?:'([^']+)'|null), '[A-Za-z ]+', -?1, [01]\],$/mu", (string) file_get_contents(__DIR__ . '/../src/Preparo.php'), $mCat, PREG_SET_ORDER);
$check('categorias: a lista de Preparo.php foi lida', count($mCat) >= 26, (string) count($mCat));
$foraDaLista = [];
foreach ($mCat as $m) {
    foreach (array_filter([$m[1], $m[2] ?? '']) as $nome) {
        if (!in_array($nome, CATEGORIAS_FABRICA, true)) {
            $foraDaLista[] = $nome;
        }
    }
}
$check('categorias: toda categoria de fábrica e apelido é traduzida ao mostrar', $foraDaLista === [], implode(', ', $foraDaLista));
$semTraducao = [];
foreach (['en', 'es'] as $idT) {
    Idioma::usar($idT);
    foreach (CATEGORIAS_FABRICA as $nome) {
        if (t($nome) === $nome && !in_array($nome, ['Internet', 'Delivery', 'Celular'], true)) {
            $semTraducao[] = "{$idT}: {$nome}";
        }
    }
}
$check('categorias: nenhuma fica em português em en/es', $semTraducao === [], implode(', ', $semTraducao));
Idioma::usar('en');
$check('categorias em en', nomeCategoria('Mercado') === 'Groceries' && nomeCategoria('IPVA') === 'Vehicle tax' && nomeCategoria('Licenciamento do carro') === 'Vehicle registration' && nomeCurto(['nome' => 'Aluguel e condomínio', 'apelido' => 'Aluguel']) === 'Rent');
$check('categorias em en: criada ou renomeada fica como está', nomeCategoria('Padaria da esquina') === 'Padaria da esquina' && nomeCurto(['nome' => 'Mercado', 'apelido' => 'Feira']) === 'Feira' && nomeCategoria('Salvar') === 'Salvar');
$check('categorias em en: a descrição automática acompanha; a escrita fica', descricaoTela('Mercado', ['nome' => 'Mercado', 'apelido' => null]) === 'Groceries' && descricaoTela('Pão', ['nome' => 'Mercado', 'apelido' => null]) === 'Pão' && descricaoTela('Mercado', ['nome' => 'Feira', 'apelido' => null]) === 'Mercado');
$check('categorias em en: o nome para gravar segue em português', nomeCurtoBanco(['nome' => 'Mercado', 'apelido' => null]) === 'Mercado');
$check('categorias em en: o motor acha pelo nome do banco', CategoriaModel::porNome('Salário') !== null && CategoriaModel::porNome(t('Salário')) === null);
$check('categorias em en: a busca acha pelo nome traduzido', in_array('Mercado', categoriasPorTraducao('grocer'), true) && categoriasPorTraducao('xyzxyz') === []);
Idioma::usar('es');
$check('categorias em es', nomeCategoria('Mercado') === 'Supermercado' && nomeCategoria('IPVA') === 'Impuesto del vehículo' && nomeCategoria('Licenciamento do carro') === 'Matrícula del vehículo');
$check('categorias em es: criada fica como está', nomeCategoria('Padaria da esquina') === 'Padaria da esquina');
Idioma::usar('pt');
$check('categorias em pt: sem nenhuma troca', array_filter(CATEGORIAS_FABRICA, static fn(string $n): bool => nomeCategoria($n) !== $n) === []);

// ---- comparação de texto, sem banco
$ordenados = ['-x', '1a', 'água', 'Bola', 'çã', 'Élan', 'zebra'];
$embaralhados = ['zebra', 'Élan', '1a', 'çã', 'Bola', '-x', 'água'];
usort($embaralhados, [\Financeiro\Colacao::class, 'comparar']);
$check('colação: símbolos, dígitos, letras', $embaralhados === $ordenados, implode(' ', $embaralhados));
$check('colação: acento e caixa são iguais', \Financeiro\Colacao::comparar('ÁGUA', 'agua') === 0 && \Financeiro\Colacao::comparar('Coração', 'coracao') === 0);
$check('colação: espaço no fim conta', \Financeiro\Colacao::comparar('a ', 'a') !== 0);
$check('like: curinga e escape', \Financeiro\Colacao::like('%CAFÉ%', 'um cafe bom') && \Financeiro\Colacao::like('a_c', 'açc') && !\Financeiro\Colacao::like('a!%', 'ab', '!') && \Financeiro\Colacao::like('a!%', 'a%', '!'));
$check('DECIMAL: meio para longe do zero', \Financeiro\Colacao::decimal(1.005, 2) === 1.01 && \Financeiro\Colacao::decimal('-2.675', 2) === -2.68 && \Financeiro\Colacao::decimal(0.00004, 4) === 0.0 && \Financeiro\Colacao::decimal(0.00005, 4) === 0.0001 && \Financeiro\Colacao::decimal(5.123456785, 8) === 5.12345679 && \Financeiro\Colacao::decimal(null, 2) === null);

// ---- primeira execução, Host e pasta de dados, sem rede
$h = [\Financeiro\Rede::class, 'hostAceito'];
$check('Host: no computador da pessoa, só o local', $h('localhost:8484', 'instalador', false, '', 'pc') && $h('127.0.0.1:8484', 'instalador', false, '', 'pc') && $h('[::1]:8484', 'instalador', false, '', 'pc'));
$check('Host: página de fora é recusada', !$h('atacante.example', 'instalador', false, '', 'pc') && !$h('atacante.example', 'instalador', true, '', 'pc') && !$h('', 'instalador', false, '', 'pc'));
$check('Host: IP da rede só com o acesso ligado', !$h('192.168.0.9:8484', 'instalador', false, '', 'pc') && $h('192.168.0.9:8484', 'instalador', true, '', 'pc') && $h('pc.local', 'instalador', true, '', 'pc'));
$check('Host: IP público é recusado mesmo com a rede ligada', !$h('8.8.8.8', 'instalador', true, '', 'pc'));
$check('Host: nome permitido', $h('financeiro.casa', 'instalador', false, 'financeiro.casa, outro', 'pc'));
$check('Host: modo servidor não muda sem lista', $h('qualquer.example', 'servidor', false, '', 'pc') && !$h('qualquer.example', 'servidor', false, 'financeiro.casa', 'pc') && $h('financeiro.casa', 'servidor', false, 'financeiro.casa', 'pc'));
$check('endereço local', \Financeiro\Rede::ehLocal('127.0.0.1') && \Financeiro\Rede::ehLocal('::1') && \Financeiro\Rede::ehLocal('::ffff:127.0.0.1') && !\Financeiro\Rede::ehLocal('192.168.0.9') && !\Financeiro\Rede::ehLocal(''));
$httpsAntes = $_SERVER['HTTPS'] ?? null;
unset($_SERVER['HTTPS']);
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
$check('X-Forwarded-Proto só vale se permitido', Config::get('CONFIAR_PROXY', '0') === '1' || !\Financeiro\Rede::https());
unset($_SERVER['HTTP_X_FORWARDED_PROTO']);
$_SERVER['HTTPS'] = $httpsAntes;
$tudo = ['mbstring' => true, 'session' => true, 'zlib' => true, 'json' => true, 'ctype' => true, 'pdo' => true, 'pdo_sqlite' => true, 'pdo_mysql' => false];
$check('diagnóstico: com tudo, nada a dizer', \Financeiro\Preflight::faltando(80400, $tudo, 'sqlite') === []);
$check('diagnóstico: PHP antigo, extensão e banco', count(\Financeiro\Preflight::faltando(80300, $tudo, 'sqlite')) === 1 && count(\Financeiro\Preflight::faltando(80400, ['mbstring' => false] + $tudo, 'sqlite')) === 1 && count(\Financeiro\Preflight::faltando(80400, $tudo, 'mysql')) === 1 && count(\Financeiro\Preflight::faltando(80400, $tudo, 'oracle')) === 1);
$check('diagnóstico: a 2.0 recusa o MySQL, mesmo com a extensão', \Financeiro\Preflight::faltando(80400, ['pdo_mysql' => true] + $tudo, 'mysql') === [t('Esta versão só funciona com SQLite. Volte para a 1.14, migre o banco para SQLite e atualize de novo.')]);
$check('caminho absoluto', \Financeiro\Paths::absoluto('/a') && \Financeiro\Paths::absoluto('C:\\a') && \Financeiro\Paths::absoluto('C:/a') && !\Financeiro\Paths::absoluto('a/b'));
$remoteAntes = $_SERVER['REMOTE_ADDR'] ?? null;
$_SERVER['REMOTE_ADDR'] = '192.168.0.9';
$check('primeira conta: endereço de fora, mas a linha de comando é local', \Financeiro\Rede::requisicaoLocal());
$_SERVER['REMOTE_ADDR'] = $remoteAntes;

// ---- o endereço do repositório, num lugar só para o código; instalador e documentos têm de bater com ele
$repoT = \Financeiro\Atualizacao::repositorio();
$check('repositório: o arquivo diz dono/nome', (bool) preg_match('#^[\w.-]+/[\w.-]+$#', $repoT));
foreach (['instalar.sh', 'README.md', 'README.en.md', 'docs/INSTALACAO.md', '.github/ISSUE_TEMPLATE/config.yml'] as $arqT) {
    $caminhoT = __DIR__ . '/../' . $arqT;
    if (is_file($caminhoT)) {
        preg_match_all('#github\.com/([\w.-]+/[\w.-]+?)(?:\.git|/|\)|\s|")#', (string) file_get_contents($caminhoT), $mT);
        $outrosT = array_diff(array_unique($mT[1]), [$repoT]);
        $outrosT = array_filter($outrosT, static fn(string $o): bool => !preg_match('#^(actions|shivammathur|dunglas|php)/#', $o));
        $check('repositório: ' . $arqT . ' usa o mesmo endereço', $outrosT === [], implode(', ', $outrosT));
    }
}

// ---- o programa do pacote: o manifesto recomenda o FrankenPHP e o PHP, e /sistema avisa quando o instalado é mais velho
$prT = static fn(string $tipo, ?string $fp, string $php, ?array $rec, string $atual = '1.10.0'): ?array
    => \Financeiro\Atualizacao::programa($tipo, ['frankenphp' => $fp, 'php' => $php], $rec, $atual);
$recT = ['versao' => '1.11.0', 'frankenphp' => '1.13.4', 'php' => '8.5.12'];
$check('programa: igual ao recomendado, sem aviso', ($prT('pacote', '1.13.4', '8.5.12', $recT) ?? ['instalar' => 'x'])['instalar'] === null);
$check('programa: mais novo que o recomendado, sem aviso', ($prT('pacote', '1.14.0', '8.6.0', $recT) ?? ['instalar' => 'x'])['instalar'] === null);
$check('programa: PHP mais velho avisa, e 8.5.9 é mais velho que 8.5.12', ($prT('pacote', '1.13.4', '8.5.9', $recT)['instalar'] ?? null) === '1.11.0');
$check('programa: FrankenPHP mais velho avisa, mesmo com o PHP em dia', ($prT('pacote', '1.9.10', '8.5.12', $recT)['instalar'] ?? null) === '1.11.0');
$check('programa: pacote de antes do programa.json compara só o PHP', ($prT('pacote', null, '8.5.12', $recT) ?? ['instalar' => 'x'])['instalar'] === null
    && ($prT('pacote', null, '8.4.14', $recT)['instalar'] ?? null) === '1.11.0');
$check('programa: sem recomendação (manifesto antigo ou sem consulta), sem aviso', ($prT('pacote', '1.0.0', '8.4.0', null) ?? ['instalar' => 'x'])['instalar'] === null);
$check('programa: o instalador indicado nunca é mais velho que o site', ($prT('pacote', '1.0.0', '8.4.0', $recT, '1.12.0')['instalar'] ?? null) === '1.12.0');
$check('programa: servidor, Docker e pasta de desenvolvimento não mostram nada',
    $prT('servidor', '1.0.0', '8.4.0', $recT) === null && $prT('docker', '1.0.0', '8.4.0', $recT) === null && $prT('dev', '1.0.0', '8.4.0', $recT) === null);
$check('programa: na Microsoft Store, só a linha: quem troca o programa é a loja', ($prT('loja', '1.0.0', '8.4.0', $recT) ?? ['instalar' => 'x'])['instalar'] === null);
$instT = \Financeiro\Atualizacao::programaInstalado();
$check('programa: o PHP instalado é o que roda agora', $instT['php'] === PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION
    && ($instT['frankenphp'] === null || preg_match('/^\d+\.\d+\.\d+$/', $instT['frankenphp']) === 1));

// a recomendação guardada na pasta de dados, que o manifesto sem o campo não apaga
\Financeiro\Atualizacao::guardarRecomendado(['versao' => '1.11.0', 'programa' => null]);
$check('programa: sem consulta, nada recomendado', \Financeiro\Atualizacao::programaRecomendado() === null);
\Financeiro\Atualizacao::guardarRecomendado(['versao' => '1.11.0', 'programa' => ['frankenphp' => '1.13.4', 'php' => '8.5.12']]);
\Financeiro\Atualizacao::guardarRecomendado(['versao' => '1.11.1', 'programa' => null]);
$check('programa: guarda o recomendado, e manifesto sem o campo não apaga', \Financeiro\Atualizacao::programaRecomendado() === $recT);

// a tela: o aviso aparece com o programa velho e some depois do pacote novo; fora do pacote, nada
$telaT = static function (string $tipo, ?array $programa): string {
    ob_start();
    \Financeiro\View::partial('sistema/index', ['pode' => true, 'administrador' => true, 'nomeAdministrador' => 'teste', 'tipo' => $tipo, 'versao' => '1.10.0',
        'estado' => null, 'consulta' => null, 'programa' => $programa, 'versoes' => 'https://github.com/dono/nome/releases',
        'backupDisponivel' => true, 'backups' => [], 'pastaBackups' => null, 'limiteEnvio' => '8 MB']);
    return (string) ob_get_clean();
};
$recGuardT = \Financeiro\Atualizacao::programaRecomendado();
$velhoT = $telaT('pacote', \Financeiro\Atualizacao::programa('pacote', ['frankenphp' => '1.12.0', 'php' => '8.4.14'], $recGuardT, '1.10.0'));
$check('programa: com o programa velho, /sistema avisa e indica a versão e a página de versões',
    str_contains($velhoT, 'id="sis-programa-velho"') && str_contains($velhoT, 'baixe o instalador da versão 1.11.0 e instale por cima. Os seus dados ficam.')
    && str_contains($velhoT, 'href="https://github.com/dono/nome/releases"') && str_contains($velhoT, 'FrankenPHP 1.12.0, com PHP 8.4.14'));
$novoT = $telaT('pacote', \Financeiro\Atualizacao::programa('pacote', ['frankenphp' => '1.13.4', 'php' => '8.5.12'], $recGuardT, '1.11.0'));
$check('programa: depois do pacote novo, o aviso some e a linha fica', !str_contains($novoT, 'sis-programa-velho') && str_contains($novoT, 'Programa que roda o site: FrankenPHP 1.13.4, com PHP 8.5.12.'));
$servT = $telaT('servidor', \Financeiro\Atualizacao::programa('servidor', ['frankenphp' => null, 'php' => '8.1.0'], $recGuardT, '1.10.0'));
$check('programa: no servidor, /sistema não fala do programa', !str_contains($servT, 'Programa que roda o site') && !str_contains($servT, 'sis-programa-velho') && str_contains($servT, 'sudo financeiro-atualizar'));
Idioma::usar('en');
$enT = $telaT('pacote', \Financeiro\Atualizacao::programa('pacote', ['frankenphp' => '1.12.0', 'php' => '8.4.14'], $recGuardT, '1.10.0'));
Idioma::usar('pt');
$check('programa: o aviso sai traduzido', str_contains($enT, 'download the installer for version 1.11.0') && str_contains($enT, 'Open the versions page'));
$sisT = $pagina(\Financeiro\Controllers\SistemaController::class, 'index', '/sistema');
// aqui é a pasta de quem desenvolve; depois de uma atualização pela tela, é o próprio pacote
$check('programa: a tela de verdade só fala do programa num pacote', str_contains($sisT, 'id="versao"')
    && in_array(\Financeiro\Atualizacao::tipo(), ['pacote', 'loja'], true) === str_contains($sisT, 'Programa que roda o site'));
@unlink(\Financeiro\Atualizacao::pasta() . '/recomendado.json');

// o manifesto do assinador de verdade, com e sem o campo; a assinatura continua conferindo
if (function_exists('sodium_crypto_sign_keypair') && function_exists('proc_open')) {
    $parT = sodium_crypto_sign_keypair();
    $pubT = base64_encode(sodium_crypto_sign_publickey($parT));
    $dirT = (string) getenv('FIN_REGRAS_PASTA') . '/assinar';
    @mkdir($dirT, 0700);
    file_put_contents($dirT . '/financeiro-app-9.9.9.tar.gz', 'conteúdo qualquer');
    $assinarT = static function (array $extra) use ($dirT, $parT): array {
        @unlink($dirT . '/atualizacao.json');
        $env = array_filter(getenv(), 'is_string');
        $env['FIN_CHAVE_ATUALIZACAO'] = base64_encode(sodium_crypto_sign_secretkey($parT));
        $proc = proc_open([...\Financeiro\Atualizacao::comandoPhp(), __DIR__ . '/../tools/assinar-atualizacao.php', '--pacote=' . $dirT . '/financeiro-app-9.9.9.tar.gz', '--versao=9.9.9', ...$extra],
            [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $tubos, \Financeiro\Atualizacao::pastaDoProcesso(dirname(__DIR__)), $env);
        $saida = is_resource($proc) ? (string) stream_get_contents($tubos[1]) : '';
        $fim = is_resource($proc) ? proc_close($proc) : -1;
        return [$fim, $saida, (string) @file_get_contents($dirT . '/atualizacao.json'), (string) @file_get_contents($dirT . '/atualizacao.json.sig')];
    };
    [$fimT, , $jsonT, $sigT] = $assinarT(['--frankenphp=1.13.4', '--php=8.5.12']);
    $mT = $fimT === 0 ? \Financeiro\Atualizacao::lerManifesto($jsonT, $sigT, $pubT) : [];
    $check('manifesto: com o campo, a assinatura confere e o programa vem junto', ($mT['programa'] ?? null) === ['frankenphp' => '1.13.4', 'php' => '8.5.12'] && ($mT['versao'] ?? '') === '9.9.9', "saída {$fimT}");
    $mudadoT = str_replace('"8.5.12"', '"8.5.11"', $jsonT);
    $erroT = '';
    try {
        \Financeiro\Atualizacao::lerManifesto($mudadoT, $sigT, $pubT);
    } catch (\RuntimeException $e) {
        $erroT = $e->getMessage();
    }
    $check('manifesto: mexer no programa recomendado quebra a assinatura', $mudadoT !== $jsonT && str_contains($erroT, 'assinatura'));
    [$fimT, , $jsonT, $sigT] = $assinarT([]);
    $mT = $fimT === 0 ? \Financeiro\Atualizacao::lerManifesto($jsonT, $sigT, $pubT) : [];
    $check('manifesto: sem o campo, como os antigos, continua valendo', $fimT === 0 && !str_contains($jsonT, 'programa') && array_key_exists('programa', $mT) && $mT['programa'] === null);
    [$fimT] = $assinarT(['--frankenphp=1.13']);
    $check('manifesto: o assinador recusa programa pela metade', $fimT === 2);
    $estranhoT = json_encode(['versao' => '9.9.9', 'pacote' => 'financeiro-app-9.9.9.tar.gz', 'sha256' => str_repeat('a', 64), 'tamanho' => 10, 'programa' => ['frankenphp' => 'última', 'php' => 8]]);
    $sigEstranhoT = base64_encode(sodium_crypto_sign_detached((string) $estranhoT, sodium_crypto_sign_secretkey($parT)));
    $check('manifesto: campo fora do formato é ignorado, sem barrar a atualização', \Financeiro\Atualizacao::lerManifesto((string) $estranhoT, $sigEstranhoT, $pubT)['programa'] === null);
}

// a montagem: as versões num lugar só, conferidas no binário, gravadas em programa.json e passadas ao assinador
$envProgT = __DIR__ . '/../packaging/programa.env';
if (is_file($envProgT) && is_file(__DIR__ . '/../packaging/empacotar.sh')) {
    $textoEnvT = (string) file_get_contents($envProgT);
    $check('montagem: programa.env tem o FrankenPHP e o PHP dele', (bool) preg_match('/^FRANKENPHP=\d+\.\d+\.\d+$/m', $textoEnvT) && (bool) preg_match('/^PHP_DO_FRANKENPHP=\d+\.\d+\.\d+$/m', $textoEnvT));
    foreach (['packaging/empacotar.sh', 'packaging/windows/montar.sh', 'packaging/macos/montar.sh'] as $arqT) {
        $sT = (string) @file_get_contents(__DIR__ . '/../' . $arqT);
        $check('montagem: ' . $arqT . ' lê programa.env, confere o PHP e grava programa.json',
            (str_contains($sT, 'programa.env"') || str_contains($sT, '. packaging/programa.env'))
            && !preg_match('/^FRANKENPHP=/m', $sT) && str_contains($sT, 'X-Powered-By: PHP/$PHP_DO_FRANKENPHP') && str_contains($sT, 'programa.json'));
    }
    $check('montagem: o atualizacao.json recebe o programa', str_contains((string) file_get_contents(__DIR__ . '/../packaging/empacotar.sh'), '--frankenphp="$FRANKENPHP" --php="$PHP_DO_FRANKENPHP"'));
}

// ---- pagamento de fatura na fatura certa e conta que veio mais barata (dados de mentira; o que grava é desfeito)
// cada pagamento vai para a fatura mais recente que já tinha fechado na data dele; se ela está toda paga, para a seguinte
$pgT = static fn(string $data, float $valor): array => ['data' => $data, 'valor' => $valor];
$check('fatura: paga no dia 31, a que vence no dia 2 fica com o pagamento (não a do mês da data)',
    CartaoFatura::atribuir([$pgT('2026-10-31', 1250.0)], 24, 2, ['2026-11-01' => 1250.0]) === ['2026-11-01']);
$check('fatura: paga atrasada continua na mesma fatura; depois do fechamento seguinte, vai para a que acabou de fechar',
    CartaoFatura::atribuir([$pgT('2026-11-05', 1250.0)], 24, 2, []) === ['2026-11-01']
    && CartaoFatura::atribuir([$pgT('2026-11-24', 900.0)], 24, 2, []) === ['2026-12-01']);
$check('fatura: com a fechada toda paga, o pagamento seguinte adianta a aberta',
    CartaoFatura::atribuir([$pgT('2026-10-31', 1250.0), $pgT('2026-11-10', 300.0)], 24, 2, ['2026-11-01' => 1250.0, '2026-12-01' => 900.0])
    === ['2026-11-01', '2026-12-01']);
$check('fatura: dois pagamentos parciais somam na mesma fatura, em ordem de data; completa, o seguinte adianta a aberta',
    CartaoFatura::atribuir(['b' => $pgT('2026-11-01', 550.0), 'a' => $pgT('2026-10-31', 700.0), 'c' => $pgT('2026-11-03', 100.0)], 24, 2, ['2026-11-01' => 1250.0])
    === ['b' => '2026-11-01', 'a' => '2026-11-01', 'c' => '2026-12-01']
    && CartaoFatura::atribuir(['x' => $pgT('2026-10-31', 1000.0), 'y' => $pgT('2026-10-30', 200.0)], 24, 2, ['2026-11-01' => 1000.0])
    === ['x' => '2026-11-01', 'y' => '2026-11-01']);
$check('fatura: centavos de diferença contam como toda paga; dez centavos não',
    CartaoFatura::atribuir([$pgT('2026-10-31', 1249.97), $pgT('2026-11-05', 100.0)], 24, 2, ['2026-11-01' => 1250.0]) === ['2026-11-01', '2026-12-01']
    && CartaoFatura::atribuir([$pgT('2026-10-31', 1249.90), $pgT('2026-11-05', 100.0)], 24, 2, ['2026-11-01' => 1250.0]) === ['2026-11-01', '2026-11-01']);
$check('fatura: fechamento no dia 31 em fevereiro fecha no último dia do mês (28 ou 29)',
    CartaoFatura::atribuir([$pgT('2027-02-27', 1.0), $pgT('2027-02-28', 1.0), $pgT('2028-02-28', 1.0), $pgT('2028-02-29', 1.0)], 31, 8, [])
    === ['2027-02-01', '2027-03-01', '2028-02-01', '2028-03-01']);
$check('fatura: paga no próprio dia do fechamento, vai para a fatura que fechou nesse dia',
    CartaoFatura::atribuir([$pgT('2026-10-24', 500.0)], 24, 2, []) === ['2026-11-01']
    && CartaoFatura::atribuir([$pgT('2026-10-23', 500.0)], 24, 2, []) === ['2026-10-01']);
$pagasT = CartaoFatura::porFaturaPaga([$pgT('2026-10-31', 1250.0)], 24, 2, ['2026-11-01' => 1250.0]);
$compR1 = [['id' => 9, 'valor_total' => 1250.0, 'parcelas' => 1, 'primeira_fatura' => '2026-11-01', 'descricao' => 'TV', 'data' => '2026-10-10']];
$cartR1 = ['fecha' => 24, 'vence' => 2, 'limite' => null];
$pnR1 = CartaoFatura::painel($cartR1, $compR1, '2026-11-03', array_map(static fn($f) => $f['pago'], $pagasT), [], array_map(static fn($f) => $f['ultimo'], $pagasT));
$check('fatura: no Cartão, a paga no dia 31 aparece paga, com a data, e não vencida', $pnR1['aberta'] === '2026-12-01'
    && $pnR1['faturas'][0]['mes'] === '2026-11-01' && $pnR1['faturas'][0]['status'] === 'paga' && $pnR1['faturas'][0]['pagaEm'] === '2026-10-31', json_encode($pnR1['faturas'][0]['status']));
// vence 02/11/2026, Finados: só fica vencida depois de 03/11, o próximo dia útil
$pnParc = CartaoFatura::painel($cartR1, $compR1, '2026-11-04', ['2026-11-01' => 700.0]);
$check('fatura: vencimento no feriado só vence no dia útil seguinte', CartaoFatura::painel($cartR1, $compR1, '2026-11-03', ['2026-11-01' => 700.0])['faturas'][0]['status'] === 'fechada'
    && $pnParc['faturas'][0]['pagaAte'] === '2026-11-03');
$check('fatura: paga em parte, diz quanto falta', $pnParc['faturas'][0]['status'] === 'vencida' && abs((float) $pnParc['faturas'][0]['falta'] - 550.0) < 0.001
    && CartaoFatura::painel($cartR1, $compR1, '2026-11-01', ['2026-11-01' => 700.0])['faturas'][0]['status'] === 'fechada');

// o plano: a fatura de fevereiro foi paga em 31/01 e a de março em 28/02; nenhuma conta duas vezes
$catR = ['apelido' => null, 'essencial' => 1, 'ativo' => 1, 'ordem' => 0];
$dadosFat = [
    'meses' => [['mes' => '2030-01-01', 'situacao' => 'REAL'], ['mes' => '2030-02-01', 'situacao' => 'PREVISAO'],
                ['mes' => '2030-03-01', 'situacao' => 'PREVISAO'], ['mes' => '2030-04-01', 'situacao' => 'PREVISAO']],
    'aj' => ['saldo_ini' => 5000.0, 'poupanca_ini' => 0.0, 'reserva_minima' => 0.0, 'objetivo' => 3.0, 'meta_final' => 0.0],
    'loanIni' => 0.0, 'excecao' => [], 'metas' => [], 'n' => [], 'ult' => [],
    'cats' => [70 => ['id' => 70, 'nome' => 'Fatura do cartão', 'grupo' => 'Cartao', 'sinal' => -1, 'operacional' => 1] + $catR],
    'prev' => ['2030-02-01' => [70 => 1000.0], '2030-03-01' => [70 => 1200.0], '2030-04-01' => [70 => 900.0]],
    'real' => ['2030-01-01' => [70 => 1000.0], '2030-02-01' => [70 => 1200.0]],
    'esp' => ['cred' => null, 'amort' => null, 'guard' => null, 'resg' => null],
    'fatura' => ['cat' => 70, 'vence' => 2, 'faturas' => CartaoFatura::porFaturaPaga([$pgT('2030-01-31', 1000.0), $pgT('2030-02-28', 1200.0)], 24, 2,
                 ['2030-02-01' => 1000.0, '2030-03-01' => 1200.0])],
];
$pF = MotorFinanceiro::calcular($dadosFat, ['hoje' => '2030-02-28']);
$pSem = MotorFinanceiro::calcular(array_replace($dadosFat, ['fatura' => ['cat' => null, 'vence' => 0, 'faturas' => []]]), ['hoje' => '2030-02-28']);
$check('fatura: no plano, a paga num mês anterior não entra de novo no mês do vencimento (sem cartão, segue como antes)',
    abs($pF['2030-02-01']['sai'] - 1200.0) < 0.005 && abs($pF['2030-03-01']['sai']) < 0.005 && abs($pF['2030-04-01']['sai'] - 900.0) < 0.005
    && abs($pSem['2030-03-01']['sai'] - 1200.0) < 0.005, json_encode([array_column($pF, 'sai'), array_column($pSem, 'sai')]));
$dadosAtraso = array_replace($dadosFat, ['fatura' => ['cat' => 70, 'vence' => 2, 'faturas' => CartaoFatura::porFaturaPaga(
    [$pgT('2030-01-31', 1000.0), $pgT('2030-02-05', 300.0), $pgT('2030-03-28', 900.0)], 24, 2, ['2030-02-01' => 1000.0, '2030-03-01' => 1200.0])]]);
$pA = MotorFinanceiro::calcular(array_replace_recursive($dadosAtraso, ['real' => ['2030-02-01' => [70 => 300.0]]]), ['hoje' => '2030-02-10']);
$check('fatura: no plano, o adiantamento sai no mês em que foi pago e desconta da fatura seguinte; pagamento com data futura não desconta',
    abs($pA['2030-02-01']['sai'] - 300.0) < 0.005 && abs($pA['2030-03-01']['sai'] - 900.0) < 0.005 && abs($pA['2030-04-01']['sai'] - 900.0) < 0.005,
    json_encode(array_column($pA, 'sai')));

// "veio menos": o valor final de uma conta e de uma entrada (o salário chega sem a parcela); dia a dia e fatura paga não perguntam
$dAm = [
    'cats' => [1 => ['id' => 1, 'nome' => 'Salário', 'chave' => MotorFinanceiro::CAT_SALARIO, 'grupo' => 'Entradas', 'sinal' => 1, 'operacional' => 1] + $catR,
               5 => ['id' => 5, 'nome' => 'Luz', 'grupo' => 'Fixos', 'sinal' => -1, 'operacional' => 1] + $catR,
               10 => ['id' => 10, 'nome' => 'Mercado', 'grupo' => 'Dia a dia', 'sinal' => -1, 'operacional' => 1] + $catR,
               15 => ['id' => 15, 'nome' => 'Fatura do cartão', 'grupo' => 'Cartao', 'sinal' => -1, 'operacional' => 1] + $catR],
    'prev' => ['2030-01-01' => [1 => 8000.0, 5 => 180.0, 10 => 1500.0, 15 => 1250.0]],
    'real' => ['2030-01-01' => [1 => 7310.10, 5 => 150.0, 10 => 600.0, 15 => 2000.0]],
    'fatura' => ['cat' => 15, 'vence' => 2, 'faturas' => ['2030-01-01' => ['valor' => 1250.0, 'pago' => 1250.0, 'primeiro' => '2029-12-31', 'ultimo' => '2029-12-31', 'meses' => []]]],
];
$check('valor final: conta e entrada que vieram menos dão o valor final; dia a dia e fatura paga não perguntam',
    PrevisaoEdicao::aMenos($dAm, 5, '2030-01-01') === ['veio' => 150.0, 'previsto' => 180.0, 'final' => 150.0, 'parcela' => false]
    && PrevisaoEdicao::aMenos($dAm, 1, '2030-01-01', 289.90) === ['veio' => 7310.1, 'previsto' => 7710.1, 'final' => 7600.0, 'parcela' => true]
    && PrevisaoEdicao::aMenos($dAm, 10, '2030-01-01') === null && PrevisaoEdicao::aMenos($dAm, 15, '2030-01-01') === null
    && PrevisaoEdicao::veioMenos(170.0, 180.0) && !PrevisaoEdicao::veioMenos(179.97, 180.0) && !PrevisaoEdicao::veioMenos(0.0, 180.0));

$mesAtualT = \Financeiro\Relogio::mes();
$sitAtualT = array_column(MesModel::todos(), 'situacao', 'mes');
$catFatDb = CartaoModel::categoriaFatura();
if ($catFatDb !== null && ($sitAtualT[$mesAtualT] ?? '') === 'PREVISAO') {
    $emTransacao('fatura paga no fim do mês anterior', static function () use ($pdo, $check, $catFatDb, $mesAtualT, $uid, $render): void {
        $pdo->exec('UPDATE cartoes SET ativo = 0');                  // o cartão de verdade sai de cena até o rollback
        $pdo->prepare('DELETE FROM lancamentos WHERE categoria_id = ?')->execute([$catFatDb]);   // só o pagamento de teste conta
        AjusteModel::setValor('usa_cartao', 1.0);
        CartaoModel::salvar(null, 'Cartão teste', 28, 1, null);       // a fatura de M fecha no dia 28 do mês anterior e vence dia 1
        ContaRecorrenteModel::upsert($catFatDb, 1);
        PrevisaoModel::setValor($catFatDb, $mesAtualT, 800.0);
        $fimAnterior = date('Y-m-d', strtotime($mesAtualT . ' -1 day'));
        LancamentoModel::criar($fimAnterior, 'Fatura teste', $catFatDb, 800.0, $uid);
        MotorFinanceiro::limparCache();
        $st = array_values(array_filter(\Financeiro\Services\ChecklistContas::statusPorMes($mesAtualT), static fn($s) => (int) $s['categoria_id'] === $catFatDb))[0] ?? null;
        $check('fatura: no Mês, a paga no fim do mês anterior está paga, e não atrasada nem pendente', $st !== null
            && in_array($st['status'], ['pago', 'pago_atrasado'], true) && abs($st['pago'] - 800.0) < 0.01 && $st['data_pagamento'] === $fimAnterior, json_encode($st));
        $d = MotorFinanceiro::dados();
        $com = MotorFinanceiro::calcular($d)[$mesAtualT];
        $sem = MotorFinanceiro::calcular(array_replace($d, ['fatura' => ['cat' => null, 'vence' => 0, 'faturas' => []]]))[$mesAtualT];
        $check('fatura: no plano, a paga no mês anterior não entra de novo no mês do vencimento', abs($sem['sai'] - $com['sai'] - 800.0) < 0.01, json_encode([$sem['sai'], $com['sai']]));
        $html = $render(MesController::class, 'index', ['mes' => $mesAtualT]);
        preg_match('#<tr id="cat-' . $catFatDb . '".*?</tr>#s', $html, $mL);
        $check('fatura: a linha do Mês diz quando foi paga (o carimbo) e não pesa no mês', str_contains($mL[0] ?? '', 'mes-status-pago')
            && str_contains($mL[0] ?? '', 'pago ' . diaMes($fimAnterior)) && !str_contains($mL[0] ?? '', 'venc'), substr($mL[0] ?? '', -300));
    });

    $emTransacao('valor final', static function () use ($pdo, $check, $mesAtualT, $uid, $render): void {
        $pdo->exec("INSERT INTO categorias (nome, grupo, sinal, ativo, ordem, essencial) VALUES ('Conta teste do valor final', 'Fixos', -1, 1, 999, 1)");
        $catC = (int) $pdo->lastInsertId();
        $catE = (int) CategoriaModel::criar('Entrada teste do valor final', 'entrada', false);
        ContaRecorrenteModel::upsert($catC, 28);
        $seg = null;
        foreach (MesModel::todos() as $m) {
            if ($seg === null && $m['mes'] > $mesAtualT && $m['situacao'] === 'PREVISAO') {
                $seg = (string) $m['mes'];
            }
        }
        foreach (array_filter([$mesAtualT, $seg]) as $m) {
            PrevisaoModel::setValor($catC, $m, 180.0);
            PrevisaoModel::setValor($catE, $m, 1000.0);
        }
        LancamentoModel::criar(\Financeiro\Relogio::hoje(), 'Conta teste', $catC, 150.0, $uid);
        LancamentoModel::criar(\Financeiro\Relogio::hoje(), 'Entrada teste', $catE, 900.0, $uid);
        MotorFinanceiro::limparCache();
        $html = $render(MesController::class, 'index', ['mes' => $mesAtualT]);
        preg_match('#<tr id="cat-' . $catC . '".*?</tr>#s', $html, $mC);
        preg_match('#<tr id="cat-' . $catE . '".*?</tr>#s', $html, $mE);
        $check('valor final: conta e entrada que vieram menos dizem quanto veio e perguntam se foi o valor final',
            str_contains($mC[0] ?? '', 'Veio 150,00 de 180,00 previstos') && str_contains($mC[0] ?? '', 'value="final"') && str_contains($mC[0] ?? '', 'Ainda falta pagar')
            && str_contains($mE[0] ?? '', 'Veio 900,00 de 1.000,00 previstos') && str_contains($mE[0] ?? '', 'Ainda falta receber'));
        $d = MotorFinanceiro::dados();
        $falta = MesController::valorFinal($d, $catC, $mesAtualT, 'falta', \Financeiro\Relogio::hoje());
        $check('valor final: "ainda falta pagar" não grava nada e diz o que o plano conta', $falta !== null && $falta['celulas'] === [] && str_contains($falta['aviso'], '30'));
        $antes = MotorFinanceiro::calcularPlano()[$mesAtualT];
        foreach ([[$catC, 150.0, 180.0, 'sai', 30.0], [$catE, 900.0, 1000.0, 'entra_bruto', 100.0]] as [$cat, $veio, $prevT, $campo, $dif]) {
            $fin = MesController::valorFinal(MotorFinanceiro::dados(), $cat, $mesAtualT, 'final', \Financeiro\Relogio::hoje());
            $lote = $fin !== null ? PrevisaoLote::gravar('manual', $fin['celulas'], $uid) : null;
            MotorFinanceiro::limparCache();
            $depois = MotorFinanceiro::calcularPlano()[$mesAtualT];
            $check("valor final ({$campo}): a previsão do mês vira o que veio, o plano fica exato e o mês seguinte não muda", $lote !== null
                && abs(PrevisaoModel::valor($cat, $mesAtualT) - $veio) < 0.001 && abs((float) $antes[$campo] - (float) $depois[$campo] - $dif) < 0.01
                && ($seg === null || abs(PrevisaoModel::valor($cat, $seg) - $prevT) < 0.001), json_encode([$antes[$campo], $depois[$campo]]));
            if ($cat === $catC) {
                $st = array_values(array_filter(\Financeiro\Services\ChecklistContas::statusPorMes($mesAtualT), static fn($s) => (int) $s['categoria_id'] === $catC))[0] ?? null;
                $check('valor final: a conta deixa de ser parcial', ($st['status'] ?? '') === 'pago' || ($st['status'] ?? '') === 'pago_atrasado', json_encode($st));
            }
            PrevisaoLote::desfazer((string) $lote, $uid);
            MotorFinanceiro::limparCache();
            $check("valor final ({$campo}): desfazer volta à previsão anterior", abs(PrevisaoModel::valor($cat, $mesAtualT) - $prevT) < 0.001);
        }
    });
}

// ---- dia útil: Páscoa, feriados e vencimento no sábado, no domingo e no feriado (dados de mentira)
$pascoasT = ['2024-03-31', '2025-04-20', '2026-04-05', '2027-03-28', '2028-04-16', '2029-04-01', '2030-04-21', '2031-04-13',
             '2032-03-28', '2033-04-17', '2034-04-09', '2035-03-25'];
$check('dia útil: a Páscoa de 2024 a 2035 bate com o calendário', array_map([\Financeiro\DiaUtil::class, 'pascoa'], range(2024, 2035)) === $pascoasT,
    json_encode(array_map([\Financeiro\DiaUtil::class, 'pascoa'], range(2024, 2035))));
$check('dia útil: os feriados de 2026, fixos e móveis (Carnaval, Sexta-feira Santa, Corpus Christi)', \Financeiro\DiaUtil::feriados(2026) === [
    '2026-01-01', '2026-02-16', '2026-02-17', '2026-04-03', '2026-04-21', '2026-05-01', '2026-06-04', '2026-09-07', '2026-10-12',
    '2026-11-02', '2026-11-15', '2026-11-20', '2026-12-25'], json_encode(\Financeiro\DiaUtil::feriados(2026)));
$check('dia útil: os móveis de outros anos saem da Páscoa', \Financeiro\DiaUtil::feriado('2025-03-03') && \Financeiro\DiaUtil::feriado('2025-03-04')
    && \Financeiro\DiaUtil::feriado('2025-04-18') && \Financeiro\DiaUtil::feriado('2025-06-19') && \Financeiro\DiaUtil::feriado('2035-02-05')
    && \Financeiro\DiaUtil::feriado('2035-03-23') && \Financeiro\DiaUtil::feriado('2035-05-24') && !\Financeiro\DiaUtil::feriado('2026-02-18'));
$check('dia útil: sábado, domingo e feriado vão para o próximo dia útil; dia útil fica', \Financeiro\DiaUtil::proximoUtil('2026-12-05') === '2026-12-07'
    && \Financeiro\DiaUtil::proximoUtil('2027-02-07') === '2027-02-10'          // domingo antes do Carnaval
    && \Financeiro\DiaUtil::proximoUtil('2026-11-20') === '2026-11-23'          // feriado numa sexta
    && \Financeiro\DiaUtil::proximoUtil('2026-11-15') === '2026-11-16'          // feriado num domingo
    && \Financeiro\DiaUtil::proximoUtil('2026-12-25') === '2026-12-28'
    && \Financeiro\DiaUtil::proximoUtil('2026-11-03') === '2026-11-03' && \Financeiro\DiaUtil::util('2026-11-03') && !\Financeiro\DiaUtil::util('2026-11-02'));

// "Precisa de atenção": cada aviso aparece e some; a conta do teste vence 05/12/2026, um sábado
$sitT = array_column(MesModel::todos(), 'situacao', 'mes');
$catAtT = (int) $pdo->query("SELECT id FROM categorias WHERE grupo = 'Fixos' AND ativo = 1 AND chave IS NULL ORDER BY id LIMIT 1")->fetchColumn();
$catDiaT = (int) $pdo->query("SELECT id FROM categorias WHERE grupo = 'Dia a dia' AND ativo = 1 AND chave IS NULL ORDER BY id LIMIT 1")->fetchColumn();
if (($sitT['2026-11-01'] ?? '') !== 'PREVISAO' || ($sitT['2026-12-01'] ?? '') !== 'PREVISAO' || $catAtT === 0 || $catDiaT === 0) {
    $check('atenção', false, 'a demonstração ficou sem novembro e dezembro de 2026 abertos ou sem as categorias');
} else {
    $emTransacao('atenção', static function () use ($pdo, $check, $catAtT, $catDiaT, $uid, $render): void {
        $tipos = static function (string $hoje, ?array $conf = null): array {
            \Financeiro\Services\GastosMensais::limparCache();
            return array_column(\Financeiro\Services\Atencao::avisos($hoje, $conf), 'tipo');
        };
        // só o que o teste cria: sem as contas, metas, conferências e cartões da demonstração
        $pdo->exec('UPDATE contas_recorrentes SET ativo = 0');
        $pdo->exec('DELETE FROM metas');
        $pdo->exec('DELETE FROM saldo_conferencias');
        $pdo->exec('UPDATE cartoes SET ativo = 0');
        $pdo->prepare("DELETE FROM lancamentos WHERE categoria_id IN (?, ?) AND data >= '2026-12-01'")->execute([$catAtT, $catDiaT]);
        ContaRecorrenteModel::upsert($catAtT, 5);
        PrevisaoModel::setValor($catAtT, '2026-12-01', 100.0);
        MotorFinanceiro::limparCache();
        $st = static fn(string $hoje): array => array_values(array_filter(\Financeiro\Services\ChecklistContas::statusPorMes('2026-12-01', $hoje),
            static fn(array $s): bool => $s['categoria_id'] === $catAtT))[0] ?? [];

        $check('atenção: tudo em dia, nada aparece', $tipos('2026-12-01') === [], json_encode($tipos('2026-12-01')));
        $av = \Financeiro\Services\Atencao::avisos('2026-12-04');
        $check('atenção: conta que vence em até 3 dias, com a data efetiva e a ação de lançar', array_column($av, 'tipo') === ['conta_vence']
            && str_contains($av[0]['titulo'], 'vence 05/12 · paga até 07/12') && str_contains($av[0]['href'], '/lancar?categoria=' . $catAtT . '&'), json_encode($av));
        $check('vencimento no sábado: na segunda ainda não está atrasada; na terça, sim', $st('2026-12-07')['status'] === 'pendente'
            && $st('2026-12-07')['paga_ate'] === '2026-12-07' && $st('2026-12-08')['status'] === 'atrasado' && in_array('conta_vence', $tipos('2026-12-07'), true) && !in_array('conta_atrasada', $tipos('2026-12-07'), true));
        $check('atenção: conta atrasada aparece primeiro', ($tipos('2026-12-08')[0] ?? '') === 'conta_atrasada', json_encode($tipos('2026-12-08')));
        PrevisaoModel::setValor($catAtT, '2027-02-01', 100.0);
        ContaRecorrenteModel::upsert($catAtT, 7);                           // 07/02/2027, domingo antes do Carnaval
        $stF = static fn(string $hoje): string => (string) (array_values(array_filter(\Financeiro\Services\ChecklistContas::statusPorMes('2027-02-01', $hoje),
            static fn(array $s): bool => $s['categoria_id'] === $catAtT))[0]['status'] ?? '');
        $check('vencimento no domingo antes do Carnaval: atrasa só depois da quarta-feira', $stF('2027-02-10') === 'pendente' && $stF('2027-02-11') === 'atrasado');
        ContaRecorrenteModel::upsert($catAtT, 25);                          // 25/12/2026, Natal, numa sexta
        PrevisaoModel::setValor($catAtT, '2026-12-01', 100.0);
        $check('vencimento no feriado: paga até a segunda seguinte', $st('2026-12-28')['status'] === 'pendente' && $st('2026-12-29')['status'] === 'atrasado');
        ContaRecorrenteModel::upsert($catAtT, 5);
        LancamentoModel::criar('2026-12-07', 'teste', $catAtT, 100.0, $uid);
        MotorFinanceiro::limparCache();
        $check('atenção: paga no dia útil depois do sábado, a conta some e não conta como paga com atraso', !in_array('conta_atrasada', $tipos('2026-12-08'), true)
            && $st('2026-12-08')['status'] === 'pago');

        // mês anterior aberto: só depois do dia 5; fechado, some
        $check('atenção: mês anterior aberto só depois do dia 5', !in_array('mes_aberto', $tipos('2026-12-05'), true) && in_array('mes_aberto', $tipos('2026-12-06'), true));
        $pdo->exec("UPDATE meses SET situacao = 'REAL' WHERE mes < '2026-12-01'");
        MotorFinanceiro::limparCache();
        $check('atenção: mês fechado, o aviso some', !in_array('mes_aberto', $tipos('2026-12-06'), true));

        // conferência que não bateu
        $confRuim = ['data' => '2026-12-01', 'saldo' => 10.0, 'dif' => -12.5, 'agendado' => 0.0, 'bate' => false, 'idade' => 2];
        $check('atenção: conferência que não bateu aparece; a que bate, não', in_array('conferencia', $tipos('2026-12-03', $confRuim), true)
            && !in_array('conferencia', $tipos('2026-12-03', ['bate' => true] + $confRuim), true));
        if (!ContaModel::varias()) {
            \Financeiro\Models\SaldoConferenciaModel::registrar('2026-11-22', 1.0, $uid);
            $check('atenção: a conferência do Início que não bateu vira aviso', in_array('conferencia', $tipos('2026-12-03', DashboardController::conferenciaAtual('2026-12-03')), true));
        }

        // meta estourada no mês
        LancamentoModel::criar('2026-12-02', 'teste', $catDiaT, 50.0, $uid);
        MetaModel::definir($catDiaT, 40.0);
        MotorFinanceiro::limparCache();
        $check('atenção: categoria acima da meta aparece', in_array('meta', $tipos('2026-12-03'), true));
        MetaModel::definir($catDiaT, 60.0);
        MotorFinanceiro::limparCache();
        $check('atenção: dentro da meta, some', !in_array('meta', $tipos('2026-12-03'), true));

        // fatura: fechada, vencendo no sábado, vencida na terça; paga, some. A conta "Fatura do cartão" não repete o aviso
        AjusteModel::setValor('usa_cartao', 1.0);
        $catFatT = CartaoModel::categoriaFatura();
        $ct = CartaoModel::salvar(null, 'Cartão atenção', 25, 5, null);
        CartaoModel::criarCompra($ct, '2026-11-10', 'teste', $catDiaT, 300.0, 1, '2026-12-01', $uid);
        if ($catFatT !== null) {
            $pdo->prepare('DELETE FROM lancamentos WHERE categoria_id = ?')->execute([$catFatT]);
            ContaRecorrenteModel::upsert($catFatT, 5);
            PrevisaoModel::setValor($catFatT, '2026-12-01', 300.0);
        }
        MotorFinanceiro::limparCache();
        $fat = static fn(string $hoje): array => array_values(array_filter($tipos($hoje), static fn(string $t): bool => str_starts_with($t, 'fatura') || $t === 'conta_atrasada'));
        $check('atenção: fatura fechada, vencendo e vencida, sem repetir a conta da fatura', $fat('2026-11-28') === ['fatura_fechada'] && $fat('2026-12-04') === ['fatura_vence']
            && $fat('2026-12-07') === ['fatura_vence'] && $fat('2026-12-08') === ['fatura_vencida'], json_encode([$fat('2026-11-28'), $fat('2026-12-04'), $fat('2026-12-07'), $fat('2026-12-08')]));
        $avF = array_values(array_filter(\Financeiro\Services\Atencao::avisos('2026-12-08'), static fn(array $a): bool => $a['tipo'] === 'fatura_vencida'))[0] ?? [];
        $check('atenção: a ação da fatura leva à fatura no Cartão', str_starts_with((string) ($avF['href'] ?? ''), '/cartao?') && str_contains((string) $avF['href'], 'fatura=2026-12-01'), json_encode($avF));
        if ($catFatT !== null) {
            LancamentoModel::criar('2026-12-07', 'teste', $catFatT, 300.0, $uid, null, ['cartao_id' => $ct, 'fatura' => '2026-12-01']);
            MotorFinanceiro::limparCache();
            $check('atenção: fatura paga, o aviso some', $fat('2026-12-08') === [], json_encode($fat('2026-12-08')));
        }

        // no máximo 3, e "e mais N" leva à lista
        $cinco = array_map(static fn(int $i): array => ['tipo' => 'meta', 'nivel' => 5, 'data' => (string) $i, 'titulo' => 'Aviso ' . $i, 'detalhe' => 'x',
            'acao' => 'Ver', 'href' => '/meta'], range(1, 5));
        $faixa = static function (array $avisos, ?int $max): string {
            ob_start();
            \Financeiro\View::partial('partials/atencao', ['avisos' => $avisos, 'max' => $max]);
            return (string) ob_get_clean();
        };
        $h5 = $faixa($cinco, \Financeiro\Services\Atencao::MAX);
        $h3 = $faixa(array_slice($cinco, 0, 3), \Financeiro\Services\Atencao::MAX);
        $check('atenção: no máximo 3 na faixa; o resto em "e mais N", que leva à lista', substr_count($h5, 'data-aviso=') === 3 && str_contains($h5, 'e mais 2') && str_contains($h5, 'href="/atencao"')
            && substr_count($h3, 'data-aviso=') === 3 && !str_contains($h3, 'e mais') && substr_count($faixa($cinco, null), 'data-aviso=') === 5);

        // no Início, de hoje: a conta atrasada aparece na faixa, logo abaixo do saldo, com a ação de lançar
        $mesH = Relogio::mes();
        $pdo->prepare("UPDATE meses SET situacao = 'PREVISAO' WHERE mes = ?")->execute([$mesH]);
        ContaRecorrenteModel::upsert($catAtT, 2);
        PrevisaoModel::setValor($catAtT, $mesH, 100.0);
        $pdo->prepare('DELETE FROM lancamentos WHERE categoria_id = ? AND data >= ?')->execute([$catAtT, $mesH]);
        MotorFinanceiro::limparCache();
        $ini = $render(DashboardController::class, 'index', []);
        preg_match('#<section id="contas-mes".*?</section>#s', $ini, $mCm);
        $check('atenção: no Início, a conta fixa atrasada fica só nas contas do mês, com o Pagar, e não repete em "Precisa de atenção"',
            !str_contains($ini, 'data-aviso="conta_atrasada"') && str_contains($mCm[0] ?? '', 'href="/lancar?categoria=' . $catAtT . '&amp;')
            && str_contains($mCm[0] ?? '', 'name="categoria_id" value="' . $catAtT . '"') && str_contains($mCm[0] ?? '', 'venceu dia 2'));
        $avIni = \Financeiro\Services\Atencao::avisos(Relogio::hoje(), DashboardController::conferenciaAtual(Relogio::hoje()));
        $outros = array_filter($avIni, static fn(array $a): bool => !in_array($a['tipo'], ['conta_atrasada', 'conta_vence'], true));
        $check('atenção: os outros avisos continuam na faixa, logo abaixo da resposta', $outros === [] || (str_contains($ini, 'data-atencao')
            && strpos($ini, 'id="ini-resposta-t"') < strpos($ini, 'data-atencao') && strpos($ini, 'data-atencao') < strpos($ini, 'id="ini-contas-t"')));
        $lista = $render(DashboardController::class, 'atencao', []);
        $check('atenção: a lista mostra todos os avisos', str_contains($lista, 'data-aviso="conta_atrasada"') && substr_count($lista, 'data-aviso=') === count(\Financeiro\Services\Atencao::avisos(Relogio::hoje(), DashboardController::conferenciaAtual(Relogio::hoje()))));
    });
    // site sem dados: sem meses, nem faixa nem aviso
    $emTransacao('atenção sem dados', static function () use ($pdo, $check, $render): void {
        $pdo->exec('DELETE FROM previsao');
        $pdo->exec('DELETE FROM previsao_log');
        $pdo->exec('DELETE FROM meses');
        MotorFinanceiro::limparCache();
        $check('atenção: site sem dados não mostra nada', \Financeiro\Services\Atencao::avisos(Relogio::hoje()) === [] && !str_contains($render(DashboardController::class, 'index', []), 'data-atencao'));
    });
}

// ---- sobra com o nome da base, frase do saldo, categoria em um toque, busca em período e planilha (dados de mentira)
$sbA = \Financeiro\Services\Sobra::de(['entra' => 7710.10, 'parcela_descontada' => 289.90, 'parcela_parte' => 0.0, 'sai' => 7688.20]);
$sbB = \Financeiro\Services\Sobra::de(['entra' => 8000.0, 'parcela_descontada' => 0.0, 'parcela_parte' => 289.90, 'sai' => 7688.20]);
$check('sobra: descontada do salário, a renda usada é a líquida e a ponte mostra o bruto',
    $sbA['bruto'] === 8000.0 && $sbA['parcela'] === 289.9 && $sbA['renda'] === 7710.1 && abs($sbA['sobra'] - 21.9) < 0.001
    && \Financeiro\Services\Sobra::ponte($sbA) === "8.000,00 \u{2212} 289,90 de parcela", json_encode($sbA));
$check('sobra: paga à parte, o salário inteiro entrou e a parcela saiu: a mesma sobra, sem ponte', $sbB['renda'] === 8000.0
    && abs($sbB['mov'] + 289.9) < 0.001 && abs($sbB['sobra'] - $sbA['sobra']) < 0.001 && \Financeiro\Services\Sobra::ponte($sbB) === null, json_encode($sbB));
$sbC = \Financeiro\Services\Sobra::de(['entra' => 5000.0, 'sai' => 4000.0, 'credito_emprestimo' => 2000.0, 'poupanca_liquido' => 500.0, 'amortizacao_extra' => 300.0]);
$check('sobra: balanço é balanço — crédito entra; poupança e adiantamento saem', abs($sbC['renda'] - 5000.0) < 0.001 && abs($sbC['mov'] - 1200.0) < 0.001 && abs($sbC['sobra'] - 2200.0) < 0.001, json_encode($sbC));
$check('sobra: sem parcela, sem ponte', \Financeiro\Services\Sobra::ponte(\Financeiro\Services\Sobra::de(['entra' => 100.0, 'sai' => 40.0])) === null);
$check('sobra: um nome para cada base', \Financeiro\Services\Sobra::rotulo('hoje') === 'Sobra até hoje' && \Financeiro\Services\Sobra::rotulo('prevista') === 'Sobra prevista' && \Financeiro\Services\Sobra::rotulo('mes') === 'Sobra do mês');

$mesSb = \Financeiro\Relogio::mes();
$catSb = (int) $pdo->query("SELECT id FROM categorias WHERE operacional = 1 AND sinal = -1 AND ativo = 1 AND grupo = 'Dia a dia' ORDER BY id LIMIT 1")->fetchColumn();
$resgSb = (int) $pdo->query("SELECT id FROM categorias WHERE operacional = 0 AND sinal = 1 ORDER BY id LIMIT 1")->fetchColumn();
if (MotorFinanceiro::linhaDoMes($mesSb) === null || $catSb === 0) {
    $check('sobra: o mesmo nome e o mesmo número nas telas', true, 'mês de hoje fora do plano ou sem categoria do dia a dia');
} else {
    $emTransacao('sobra nas telas', static function () use ($pdo, $check, $render, $catSb, $resgSb, $mesSb, $uid): void {
        $ins = $pdo->prepare('INSERT INTO lancamentos (data, descricao, categoria_id, valor, usuario_id) VALUES (?, ?, ?, ?, ?)');
        $ins->execute([\Financeiro\Relogio::hoje(), 'teste da sobra', $catSb, 123.45, $uid]);
        if ($resgSb > 0) {
            $ins->execute([\Financeiro\Relogio::hoje(), 'teste do resgate', $resgSb, 5000, $uid]);   // movimentação: entra no balanço, como o dono pediu
        }
        MotorFinanceiro::limparCache();
        \Financeiro\Services\GastosMensais::limparCache();
        $hojeSb = \Financeiro\Services\Sobra::ateHoje($mesSb);
        $prevSb = \Financeiro\Services\Sobra::prevista(MotorFinanceiro::linhaDoMes($mesSb));
        $acha = static function (string $html): array {
            preg_match_all('/data-sobra="(\w+)">([^<]+)</u', $html, $m, PREG_SET_ORDER);
            return array_map(static fn(array $x): array => [$x[1], trim(html_entity_decode($x[2]))], $m);
        };
        $ini = $acha($render(DashboardController::class, 'index', []));
        $mesH = $render(MesController::class, 'index', ['mes' => $mesSb]);
        $mesV = $acha($mesH);
        $fe = $acha($render(MesController::class, 'fecharForm', ['mes' => $mesSb]));
        $rl = RelatorioDados::mensal($mesSb);
        $iniH = $render(DashboardController::class, 'index', []);
        $check('sobra: o Início mostra o que entrou e o gasto até hoje, da mesma conta',
            str_contains($iniH, 'data-entrou>' . valor($hojeSb['renda'], 'entrada')) && str_contains($iniH, 'data-gasto>' . valor($hojeSb['saidas'], 'saida')), json_encode($hojeSb));
        $sinalSb = ($hojeSb['sobra'] > 0.004 ? '+' : '') . num($hojeSb['sobra']);
        $check('sobra: Fechar termina a escada no balanço até hoje (com o resgate: balanço é balanço)', in_array([$hojeSb['base'], $sinalSb], $fe, true), json_encode($fe));
        $linhaSb = MotorFinanceiro::linhaDoMes($mesSb);
        $check('Mês: o livro termina no fim do mês, o mesmo número da previsão do plano', str_contains($mesH, 'data-previsao-fim="' . number_format((float) $linhaSb['fica_na_conta'], 2, '.', '') . '"')
            && str_contains($mesH, 'data-livro-fim="' . number_format((float) $linhaSb['fica_na_conta'], 2, '.', '') . '"'));
        $check('Mês: o saldo do mês anterior é uma linha das entradas e entra no total', str_contains($mesH, 'data-saldo-anterior')
            && str_contains($mesH, num((float) $linhaSb['sobrou_mes_anterior'])));
        $check('sobra: o Relatório usa as mesmas', $rl !== null && ($rl['encerrado']
            ? abs($rl['sobrou'] - $prevSb['sobra']) < 0.005
            : abs($rl['sobras'][0]['sobra'] - $hojeSb['sobra']) < 0.005 && abs($rl['sobras'][1]['sobra'] - $prevSb['sobra']) < 0.005));
        $check('sobra: o nome antigo "Diferença" saiu do Mês', !str_contains($mesH, '>Diferença<'));
    });
}
$check('ajuda: a resposta dos números diz as bases', str_contains(implode(' ', array_column(array_merge(...array_column(\Financeiro\Ajuda::grupos(), 'itens')), 'resposta')), 'A sobra até hoje é o que entrou menos o que saiu até agora'));
$check('centavos: dinheiro com centavos no Mês e no Início', !preg_match('/class="num[^"]*">' . MENOS . '?\+?\d{1,3}(\.\d{3})*<(?!span class="cent")/', $render(MesController::class, 'index', []) . $render(DashboardController::class, 'index', [])));

// a frase do saldo: (previsão com o plano, sem adiantar, reserva)
$fr = static fn(?float $p, ?float $s, float $r): ?array => DashboardController::resposta($p, $s, $r);
$check('frase: acima da reserva, dá para gastar a folga', $fr(3000.0, 3000.0, 1500.0) === ['tipo' => 'gastar', 'valor' => 1500.0, 'adianta' => 0.0]);
$check('frase: abaixo da reserva, quanto falta', $fr(1200.0, 1200.0, 1500.0) === ['tipo' => 'abaixo', 'valor' => 300.0, 'adianta' => 0.0]);
$check('frase: no vermelho, quanto falta para zerar', $fr(-200.0, -200.0, 1500.0) === ['tipo' => 'vermelho', 'valor' => 200.0, 'adianta' => 0.0]);
$check('frase: o fim do mês igual à reserva porque a sobra adianta o empréstimo', $fr(1500.0, 4398.66, 1500.0) === ['tipo' => 'gastar', 'valor' => 2898.66, 'adianta' => 2898.66]);
$check('frase: o empréstimo leva só parte da folga', $fr(2000.0, 4000.0, 1500.0) === ['tipo' => 'gastar', 'valor' => 2500.0, 'adianta' => 2000.0]);
$check('frase: sem previsão, sem frase', $fr(null, null, 1500.0) === null);
$htmlFr = $render(DashboardController::class, 'index', []);
$check('frase: o topo do Início é só o rótulo, o número e uma linha de apoio', preg_match('#<div class="painel ini-painel" data-resposta="\w+">\s*<h2 class="rot-resposta"[^>]*>[^<]+<a class="dica"[^>]*>\?</a></h2>\s*<p class="resposta-num">.*?</p>\s*(<p class="por-dia">.*?</p>\s*)?<div class="ini-destino"#s', $htmlFr) === 1);

// o campo da categoria: a lista inteira, agrupada, num <select> (sem JS) e no combobox do lancar.js
LancarForm::limparCache();
$lf = LancarForm::dados();
$chavesLf = array_keys($lf['grupos']);
$check('categorias: a lista começa pelos gastos', array_search('entradas', $chavesLf, true) === false || array_search('entradas', $chavesLf, true) > 0);
$htmlLf = $render(LancamentosController::class, 'lancarForm', []);
$nCats = (int) $pdo->query('SELECT COUNT(*) FROM categorias WHERE ativo = 1')->fetchColumn();
$check('categorias: um campo só, com a lista inteira; os atalhos são só as 3 usadas por último',
    str_contains($htmlLf, '<select id="pg-cat" name="categoria_id"') && substr_count($htmlLf, 'role="option"') === $nCats
    && preg_match('/role="combobox"[^>]*aria-expanded="false"[^>]*aria-controls="pg-cat-lista"/', $htmlLf) === 1
    && str_contains($htmlLf, 'role="listbox"') && str_contains($htmlLf, 'class="lf-combo-op" role="option"')
    && !str_contains($htmlLf, 'lf-chip') && !str_contains($htmlLf, 'data-lf-mais') && !str_contains($htmlLf, 'type="radio" name="categoria_id"')
    && substr_count($htmlLf, 'data-lf-recente=') === count($lf['recentes']) && count($lf['recentes']) <= 3);
$check('categorias: a lista tem os grupos e o ícone de cada uma', substr_count($htmlLf, 'class="lf-combo-g" role="group" aria-label="') === count($lf['grupos'])
    && preg_match('/role="option"[^>]*>\s*<svg class="ci/', $htmlLf) === 1);
// as usadas por último, o valor previsto das contas fixas e o erro no próprio campo
$emTransacao('lançar: recentes, sugestão e erro no campo', static function () use ($pdo, $check, $render, $uid): void {
    $mesL = \Financeiro\Relogio::mes();
    $cats = $pdo->query("SELECT id FROM categorias WHERE ativo = 1 AND grupo = 'Dia a dia' ORDER BY id LIMIT 4")->fetchAll(PDO::FETCH_COLUMN);
    $ins = $pdo->prepare("INSERT INTO lancamentos (data, descricao, categoria_id, valor, usuario_id, criado_em) VALUES (?, 'teste recente', ?, 1, ?, ?)");
    foreach (array_map('intval', $cats) as $i => $c) {
        $ins->execute([\Financeiro\Relogio::hoje(), $c, $uid, '2099-01-01 00:00:0' . $i]);   // a última criada é a mais recente
    }
    LancarForm::limparCache();
    $rec = LancarForm::dados()['recentes'];
    $check('lançar: as 3 categorias usadas por último, da mais recente para a mais antiga', $rec === array_reverse(array_slice(array_map('intval', $cats), 1)), json_encode($rec));
    $fixa = (int) $pdo->query("SELECT id FROM categorias WHERE ativo = 1 AND grupo = 'Fixos' ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->prepare('DELETE FROM lancamentos WHERE categoria_id = ? AND data >= ?')->execute([$fixa, $mesL]);
    PrevisaoModel::setValor($fixa, $mesL, 321.09);
    LancarForm::limparCache();
    $sug = LancarForm::dados()['sugestoes'];
    $check('lançar: a conta fixa sem pagamento sugere o previsto do mês', abs(($sug[$fixa] ?? 0.0) - 321.09) < 0.001, json_encode($sug));
    $h = $render(LancamentosController::class, 'lancarForm', ['categoria' => (string) $fixa]);
    $check('lançar: sem JS, a conta fixa pelo endereço já vem com o valor sugerido', str_contains($h, 'value="321,09" data-lf-valor data-lf-sugerido')
        && str_contains($h, 'data-lf-previsto="321,09"'));
    LancamentoModel::criar(\Financeiro\Relogio::hoje(), 'parte', $fixa, 300.0, $uid);
    LancarForm::limparCache();
    $check('lançar: paga em parte, sugere o que falta; paga, não sugere', abs((LancarForm::dados()['sugestoes'][$fixa] ?? 0.0) - 21.09) < 0.001);
    LancamentoModel::criar(\Financeiro\Relogio::hoje(), 'resto', $fixa, 21.09, $uid);
    LancarForm::limparCache();
    $check('lançar: paga, não sugere', !isset(LancarForm::dados()['sugestoes'][$fixa]));
    // o erro do servidor volta no campo dele
    $_SESSION['lanc_antigo'] = ['valor' => '', 'categoria_id' => 0, 'erro' => t('Digite um valor.')];
    $hErr = $render(LancamentosController::class, 'lancarForm', []);
    $_SESSION['lanc_antigo'] = ['valor' => '10', 'categoria_id' => 0, 'erro' => t('Escolha uma categoria.')];
    $hErr2 = $render(LancamentosController::class, 'lancarForm', []);
    $check('lançar: o erro aparece no próprio campo, não no aviso de baixo', preg_match('#data-lf-valor[^>]*aria-invalid="true" aria-describedby="pg-valor-erro"#', $hErr) === 1
        && preg_match('#id="pg-valor-erro" data-lf-erro-de="valor" role="alert">Digite um valor\.</p>#', $hErr) === 1 && !str_contains($hErr, 'aviso aviso-error lf-aviso" role="alert">Digite')
        && str_contains($hErr2, 'aria-invalid="true" aria-describedby="pg-cat-erro"') && preg_match('#id="pg-cat-erro" data-lf-erro-de="categoria" role="alert">Escolha uma categoria\.</p>#', $hErr2) === 1);
    unset($_SESSION['lanc_antigo']);
    LancarForm::limparCache();
});
LancarForm::limparCache();
$poucoUsada = (int) $pdo->query("SELECT id FROM categorias WHERE ativo = 1 AND operacional = 1 AND sinal = -1 AND grupo <> 'Dia a dia' ORDER BY ordem DESC, id DESC LIMIT 1")->fetchColumn();
if ($poucoUsada > 0) {
    $emTransacao('categorias: as mais usadas', static function () use ($pdo, $check, $poucoUsada, $uid): void {
        $ins = $pdo->prepare('INSERT INTO lancamentos (data, descricao, categoria_id, valor, usuario_id) VALUES (?, ?, ?, 1, ?)');
        for ($i = 0; $i < 40; $i++) {
            $ins->execute([\Financeiro\Relogio::hoje(), 'teste freq', $poucoUsada, $uid]);
        }
        LancarForm::limparCache();
        // no grupo dela, a mais usada vem logo depois do dia a dia
        $primeira = null;
        foreach (LancarForm::dados()['grupos'] as $itens) {
            foreach ($itens as $c) {
                if ($c['grupo'] !== 'Dia a dia' && in_array($poucoUsada, array_column($itens, 'id'), true)) {
                    $primeira ??= (int) $c['id'];
                }
            }
        }
        $check('categorias: a mais usada sobe na lista do grupo', $primeira === $poucoUsada, (string) $primeira);
    });
    LancarForm::limparCache();
}

// busca em período, com total, e a planilha
$catBusca = (int) $pdo->query('SELECT id FROM categorias WHERE operacional = 1 AND sinal = -1 ORDER BY id LIMIT 1')->fetchColumn();
$emTransacao('busca em período', static function () use ($pdo, $check, $render, $catBusca, $uid): void {
    $ins = $pdo->prepare('INSERT INTO lancamentos (data, descricao, categoria_id, valor, usuario_id) VALUES (?, ?, ?, ?, ?)');
    $ins->execute(['2001-03-05', 'zzbusca; "aspas"', $catBusca, 10.50, $uid]);
    $ins->execute(['2001-07-09', 'zzbusca =SOMA(1)', $catBusca, 20.25, $uid]);
    $ins->execute(['2002-01-01', 'zzbusca outra', $catBusca, 30.00, $uid]);
    $ins->execute(['2001-07-09', '=zzbusca fórmula', $catBusca, 1.00, $uid]);
    $mesB = \Financeiro\Relogio::mes();
    $t2001 = LancamentoModel::totais($mesB, ['q' => 'zzbusca', 'periodo' => ['de' => '2001-01-01', 'ate' => '2001-12-31']]);
    $check('busca: num período, com o total', $t2001['n'] === 3 && abs($t2001['gasto'] - 31.75) < 0.001, json_encode($t2001));
    $check('busca: em todos os meses', LancamentoModel::totais($mesB, ['q' => 'zzbusca', 'periodo' => ['de' => null, 'ate' => null]])['n'] === 4);
    $check('busca: o mês continua o padrão', LancamentoModel::totais($mesB, ['q' => 'zzbusca'])['n'] === 0);
    $check('busca: valor mínimo e máximo', LancamentoModel::totais($mesB, ['q' => 'zzbusca', 'periodo' => ['de' => null, 'ate' => null], 'min' => 15.0])['n'] === 2
        && LancamentoModel::totais($mesB, ['q' => 'zzbusca', 'periodo' => ['de' => null, 'ate' => null], 'min' => 10.5, 'max' => 20.25])['n'] === 2);
    $check('busca: os filtros de antes continuam (categoria, quem, ordem)', count(LancamentoModel::listar($mesB, ['q' => 'zzbusca', 'categoria_id' => $catBusca, 'usuario_id' => $uid, 'ordem' => 'valor-desc', 'periodo' => ['de' => null, 'ate' => null], 'limite' => 2])) === 2
        && (float) LancamentoModel::listar($mesB, ['q' => 'zzbusca', 'ordem' => 'valor-desc', 'periodo' => ['de' => null, 'ate' => null], 'limite' => 1])[0]['valor'] === 30.0);
    $lotes = array_map(static fn(array $r): string => $r['data'] . '#' . number_format((float) $r['valor'], 2, '.', ''), iterator_to_array(LancamentoModel::emLotes($mesB, ['q' => 'zzbusca', 'periodo' => ['de' => null, 'ate' => null]], 1), false));
    $check('busca: a planilha lê em lotes, do mais antigo ao mais novo, sem repetir', count($lotes) === 4 && count(array_unique($lotes)) === 4 && $lotes[0] === '2001-03-05#10.50' && $lotes[3] === '2002-01-01#30.00', json_encode($lotes));
    $htmlB = $render(LancamentosController::class, 'index', ['periodo' => 'ano', 'mes' => '2001-01-01', 'q' => 'zzbusca']);
    $htmlT = $render(LancamentosController::class, 'index', ['periodo' => 'tudo', 'q' => 'zzbusca']);
    $check('busca: a tela responde com o total no topo', str_contains($htmlT, 'data-ex-total="gasto">' . valor(-61.75, 'saida') . '<') && str_contains($htmlT, 'href="/lancamentos/planilha?'));
    $check('busca: o período pedido vale (datas)', str_contains($render(LancamentosController::class, 'index', ['periodo' => 'datas', 'de' => '2001-07-01', 'ate' => '2001-07-31', 'q' => 'zzbusca']), 'data-ex-total="gasto">' . valor(-21.25, 'saida') . '<'));
    // o período do filtro por extenso, com a maiúscula só no mês ("Novembro de 2026", nunca "Novembro De 2026")
    $check('extrato: o período do filtro vem inteiro e com a maiúscula só no mês', str_contains($htmlT, '>' . mb_convert_case(mesExtenso($mesB), MB_CASE_TITLE) . ' de ' . substr($mesB, 0, 4) . '</option>')
        && preg_match('/>\p{Lu}\p{Ll}+ De \d{4}</u', $htmlT) === 0);
    // o Extrato não tem mais o formulário de lançar na tela: o Lançar é o diálogo do layout
    $check('extrato: sem o formulário de lançar na tela, a busca com a tecla /', !str_contains($htmlT, 'data-lf-modo="pagina"') && str_contains($htmlT, 'aria-keyshortcuts="/"'));
    $check('busca: valor que não dá para ler é avisado', str_contains($render(LancamentosController::class, 'index', ['periodo' => 'tudo', 'min' => 'abc']), 'id="ex-erro-valor"'));
    unset($htmlB);

    // a planilha: BOM, ";" e vírgula decimal, aspas em descrição com ";", fórmula desarmada
    $h = fopen('php://memory', 'w+b');
    $n = \Financeiro\Services\Planilha::escrever(LancamentoModel::emLotes($mesB, ['q' => 'zzbusca', 'periodo' => ['de' => null, 'ate' => null]]), $h);
    rewind($h);
    $csv = (string) stream_get_contents($h);
    fclose($h);
    $check('planilha: UTF-8 com BOM e cabeçalho com ";"', str_starts_with($csv, "\u{FEFF}Data;Descrição;Categoria;Valor;\"Quem lançou\"\r\n") || str_starts_with($csv, "\u{FEFF}Data;Descrição;Categoria;Valor;Quem lançou\r\n"), substr($csv, 0, 80));
    $check('planilha: valor com sinal e vírgula decimal', $n === 4 && str_contains($csv, '2001-03-05;') && str_contains($csv, ';-10,50;') && str_contains($csv, ';-20,25;'));
    $check('planilha: descrição com ";" vai entre aspas', str_contains($csv, ';"zzbusca; ""aspas""";'));
    $check('planilha: texto que começa com "=" não vira fórmula', str_contains($csv, ";\"'=zzbusca fórmula\";"));
    $check('planilha: o nome do arquivo diz o período', \Financeiro\Services\Planilha::nomeArquivo('ano', '2026-11-01', null, null) === 'lancamentos-2026.csv'
        && \Financeiro\Services\Planilha::nomeArquivo('', '2026-11-01', null, null) === 'lancamentos-2026-11.csv'
        && \Financeiro\Services\Planilha::nomeArquivo('datas', '2026-11-01', '2026-01-01', '2026-03-31') === 'lancamentos-2026-01-01-a-2026-03-31.csv'
        && \Financeiro\Services\Planilha::nomeArquivo('tudo', '2026-11-01', null, null) === 'lancamentos-todos-os-meses.csv');
    $_GET = ['periodo' => 'tudo', 'q' => 'zzbusca'];
    ob_start();
    (new LancamentosController())->planilha();
    $csvRota = (string) ob_get_clean();
    $_GET = [];
    $check('planilha: a rota entrega o resultado filtrado', str_starts_with($csvRota, "\u{FEFF}") && substr_count($csvRota, "\r\n") === 5);
});

// ---- 2.0: a migração de um banco da 1.10, contas, transferências, vários cartões, pagamento ligado à fatura, estorno e
// chaves das categorias. Só SQLite: a 2.0 deixa o MySQL. O que grava é desfeito.
if (Sql::sqlite()) {
    // a estrutura de um banco: colunas na ordem (a restauração de backup compara), chaves estrangeiras, índices e gatilhos
    $estrutura = static function (\PDO $p): array {
        $out = [];
        foreach ($p->query("SELECT type, name, tbl_name FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name")->fetchAll() as $o) {
            $chave = $o['type'] . ' ' . $o['name'];
            if ($o['type'] === 'table') {
                $out[$chave] = array_map(static fn(array $c): string => implode('|', [$c['name'], $c['type'], $c['notnull'], (string) $c['dflt_value'], $c['pk'], $c['hidden']]),
                    $p->query('PRAGMA table_xinfo("' . $o['name'] . '")')->fetchAll());
                $out[$chave . ' fk'] = array_map(static fn(array $f): string => $f['from'] . '>' . $f['table'] . '.' . $f['to'],
                    $p->query('PRAGMA foreign_key_list("' . $o['name'] . '")')->fetchAll());
            } else {
                $out[$chave] = $o['tbl_name'];
            }
        }
        return $out;
    };

    // ---- a migração s0001 num banco da 1.10 com dados
    $arq110 = sys_get_temp_dir() . '/financeiro-110-' . bin2hex(random_bytes(4)) . '.sqlite';
    $arqNovo = $arq110 . '.novo';
    try {
        $v = new \Financeiro\SqliteConexao($arq110);
        $v->exec((string) file_get_contents(__DIR__ . '/dados/schema-1.10.sqlite.sql'));
        $v->exec("INSERT INTO usuarios (nome, login, senha_hash) VALUES ('Teste', 'teste', 'x')");
        $catsV = [['Salário', 'Entradas', 1, 1], ['Fatura velha', 'Cartao', -1, 0], ['Fatura do cartão', 'Cartao', -1, 1], ['Mercado', 'Dia a dia', -1, 1],
                  ['Guardar na poupança', 'Poupanca', -1, 1], ['Resgate da poupança', 'Poupanca', 1, 1], ['Crédito do empréstimo', 'Emprestimo', 1, 1],
                  ['Amortização extra do empréstimo', 'Emprestimo', -1, 1], ['Parcela do empréstimo', 'Emprestimo', -1, 1],
                  ['Aplicar em investimentos', 'Investimento', -1, 1], ['Resgate de investimentos', 'Investimento', 1, 1]];
        $stV = $v->prepare('INSERT INTO categorias (nome, grupo, sinal, ativo, ordem) VALUES (?, ?, ?, ?, ?)');
        foreach ($catsV as $i => [$n, $g, $s, $a]) {
            $stV->execute([$n, $g, $s, $a, $i]);
        }
        $idV = static fn(string $nome): int => (int) $v->query('SELECT id FROM categorias WHERE nome = ' . $v->quote($nome))->fetchColumn();
        $v->exec("INSERT INTO ajustes (chave, label, valor) VALUES ('saldo_ini', 'Saldo', 1234.56), ('data_inicio', 'Início', 20300101), ('usa_cartao', 'Cartão', 1)");
        foreach (['2030-01-01', '2030-02-01', '2030-03-01', '2030-04-01', '2030-05-01'] as $m) {
            $v->exec("INSERT INTO meses (mes, situacao) VALUES ('{$m}', 'PREVISAO')");
        }
        $v->exec("INSERT INTO cartoes (nome, dia_fechamento, dia_vencimento) VALUES ('Cartão da 1.10', 24, 2)");
        $v->exec("INSERT INTO cartao_compras (cartao_id, data, descricao, categoria_id, valor_total, parcelas, primeira_fatura, usuario_id)
                  VALUES (1, '2030-01-10', 'TV', {$idV('Mercado')}, 1250, 1, '2030-02-01', 1), (1, '2030-02-10', 'Sofá', {$idV('Mercado')}, 1200, 3, '2030-03-01', 1)");
        $v->exec("INSERT INTO previsao (categoria_id, mes, valor) VALUES ({$idV('Fatura do cartão')}, '2030-04-01', 900)");
        // pagamentos: a de fevereiro paga no dia 31 de janeiro; a de março em duas vezes; o seguinte, com março toda paga, adianta abril
        $pagV = [['2030-01-31', 1250.0], ['2030-02-27', 300.0], ['2030-03-01', 100.0], ['2030-03-05', 50.0]];
        $stL = $v->prepare('INSERT INTO lancamentos (data, descricao, categoria_id, valor, usuario_id) VALUES (?, ?, ?, ?, 1)');
        foreach ($pagV as [$d, $val]) {
            $stL->execute([$d, 'Fatura', $idV('Fatura do cartão'), $val]);
        }
        $stL->execute(['2030-01-05', 'Salário', $idV('Salário'), 5000.0]);
        $stL->execute(['2030-01-06', 'apagado', $idV('Mercado'), 10.0]);
        $v->exec("DELETE FROM lancamentos WHERE descricao = 'apagado'");
        $v->exec("INSERT INTO saldo_conferencias (data, saldo_informado, usuario_id) VALUES ('2030-01-31', 3000, 1)");
        $seqAntes = (int) $v->query("SELECT seq FROM sqlite_sequence WHERE name = 'lancamentos'")->fetchColumn();
        // o que a 1.10 calculava na hora: atribuir() com a soma das parcelas e a previsão da fatura
        $valoresV = CartaoFatura::totais([['valor_total' => 1250.0, 'parcelas' => 1, 'primeira_fatura' => '2030-02-01'], ['valor_total' => 1200.0, 'parcelas' => 3, 'primeira_fatura' => '2030-03-01']]);
        $valoresV['2030-04-01'] = max($valoresV['2030-04-01'] ?? 0.0, 900.0);
        $esperado = CartaoFatura::atribuir(array_map(static fn(array $p): array => ['data' => $p[0], 'valor' => $p[1]], $pagV), 24, 2, $valoresV);

        $feitas = \Financeiro\Migrador::aplicar($v, \Financeiro\Migrador::pasta());
        $check('migração 2.0: roda num banco da 1.10, o .sql antes do .php', array_slice($feitas, 0, 2) === ['s0001_contas.sql', 's0001_contas.php'], json_encode($feitas));
        $contasV = $v->query('SELECT id, nome, tipo, saldo_inicial, ativa FROM contas')->fetchAll();
        $check('migração 2.0: a Conta principal nasce com o saldo inicial de antes', count($contasV) === 1 && (int) $contasV[0]['id'] === 1
            && $contasV[0]['nome'] === 'Conta principal' && abs((float) $contasV[0]['saldo_inicial'] - 1234.56) < 0.001 && (int) $contasV[0]['ativa'] === 1, json_encode($contasV));
        $check('migração 2.0: todo lançamento fica na Conta principal, com o mesmo id e o mesmo valor',
            (int) $v->query('SELECT COUNT(*) FROM lancamentos WHERE conta_id <> 1')->fetchColumn() === 0
            && (int) $v->query('SELECT COUNT(*) FROM lancamentos')->fetchColumn() === 5
            && abs((float) $v->query('SELECT SUM(valor) FROM lancamentos')->fetchColumn() - 6700.0) < 0.001);
        $vinc = $v->query('SELECT cartao_id, fatura FROM lancamentos WHERE categoria_id = ' . $idV('Fatura do cartão') . ' ORDER BY data, id')->fetchAll();
        $check('migração 2.0: cada pagamento antigo fica na fatura que a regra da 1.10 dava',
            array_column($vinc, 'fatura') === array_values($esperado) && array_values($esperado) === ['2030-02-01', '2030-03-01', '2030-03-01', '2030-04-01']
            && array_unique(array_column($vinc, 'cartao_id')) === [1], json_encode([$vinc, $esperado]));
        $check('migração 2.0: só o pagamento de fatura ganha cartão', (int) $v->query('SELECT COUNT(*) FROM lancamentos WHERE cartao_id IS NOT NULL')->fetchColumn() === 4);
        $chavesV = $v->query('SELECT chave, nome FROM categorias WHERE chave IS NOT NULL')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $check('migração 2.0: as categorias especiais ganham a chave; a da fatura é a ativa, como antes', count($chavesV) === 9
            && $chavesV['fatura'] === 'Fatura do cartão' && $chavesV['salario'] === 'Salário' && $chavesV['guardar'] === 'Guardar na poupança'
            && $chavesV['resgate_investimento'] === 'Resgate de investimentos', json_encode($chavesV, JSON_UNESCAPED_UNICODE));
        $check('migração 2.0: o cartão é pago pela Conta principal; a conferência antiga é do total',
            (int) $v->query('SELECT conta_id FROM cartoes')->fetchColumn() === 1 && $v->query('SELECT conta_id FROM saldo_conferencias')->fetchColumn() === null);
        $check('migração 2.0: o contador de ids continua de onde estava', (int) $v->query("SELECT seq FROM sqlite_sequence WHERE name = 'lancamentos'")->fetchColumn() === $seqAntes);
        $v->exec("INSERT INTO cartao_compras (cartao_id, data, descricao, valor_total, parcelas, primeira_fatura, usuario_id) VALUES (1, '2030-02-11', 'devolução', -80, 1, '2030-03-01', 1)");
        $estornoParcelado = '';
        try {
            $v->exec("INSERT INTO cartao_compras (cartao_id, data, descricao, valor_total, parcelas, primeira_fatura, usuario_id) VALUES (1, '2030-02-11', 'x', -80, 2, '2030-03-01', 1)");
        } catch (\PDOException $e) {
            $estornoParcelado = $e->getMessage();
        }
        $check('migração 2.0: o cartão aceita estorno (negativo), só em 1 parcela', $estornoParcelado !== '');
        $check('migração 2.0: as chaves estrangeiras continuam certas', $v->query('PRAGMA foreign_key_check')->fetch() === false);
        $novo = new \Financeiro\SqliteConexao($arqNovo);
        $novo->exec((string) file_get_contents(dirname(__DIR__) . '/database/schema.sqlite.sql'));
        $eV = $estrutura($v);
        $eN = $estrutura($novo);
        $check('migração 2.0: o banco migrado tem a mesma estrutura de um banco novo (colunas na mesma ordem, índices e gatilhos)', $eV === $eN,
            json_encode(array_keys(array_diff_assoc(array_map('json_encode', $eV), array_map('json_encode', $eN)) + array_diff_assoc(array_map('json_encode', $eN), array_map('json_encode', $eV)))));
        $check('migração 2.0: rodar de novo não faz nada', \Financeiro\Migrador::aplicar($v, \Financeiro\Migrador::pasta()) === []);
    } catch (\Throwable $e) {
        $check('migração 2.0', false, get_class($e) . ': ' . $e->getMessage() . ' em ' . basename($e->getFile()) . ':' . $e->getLine());
    } finally {
        $v = $novo = null;
        foreach ([$arq110, $arqNovo] as $a) {
            foreach (['', '-wal', '-shm'] as $s) {
                @unlink($a . $s);
            }
        }
    }

    // ---- s0040: um site da 1.14 já tem a s0002, a s0003 e a s0004 quando chegam a s0001 e o resto; um banco novo e um
    // mais antigo (acima) rodam tudo na ordem do nome. Os dois caminhos dão o schema novo, e o visual do cartão fica.
    $arq114 = sys_get_temp_dir() . '/financeiro-114-' . bin2hex(random_bytes(4)) . '.sqlite';
    $arqNovo = $arq114 . '.novo';
    $pasta114 = $arq114 . '.migracoes';
    try {
        $v = new \Financeiro\SqliteConexao($arq114);
        $v->exec((string) file_get_contents(__DIR__ . '/dados/schema-1.10.sqlite.sql'));
        $v->exec("INSERT INTO usuarios (nome, login, senha_hash) VALUES ('Teste', 'teste', 'x')");
        $v->exec("INSERT INTO categorias (nome, grupo, sinal, ativo, ordem) VALUES ('Fatura do cartão', 'Cartao', -1, 1, 1)");
        $v->exec("INSERT INTO cartoes (nome, dia_fechamento, dia_vencimento) VALUES ('Cartão da 1.14', 24, 2), ('Outro da 1.14', 10, 20)");
        mkdir($pasta114);
        foreach (['s0002_duas_etapas.sql', 's0003_menu_ordem.sql', 's0004_cartao_visual.sql'] as $a) {
            copy(\Financeiro\Migrador::pasta() . '/' . $a, $pasta114 . '/' . $a);
        }
        $da114 = \Financeiro\Migrador::aplicar($v, $pasta114);
        $v->exec("UPDATE cartoes SET visual = CASE id WHEN 1 THEN 'roxo' ELSE '#2f6fdf' END");
        $colunas114 = array_column($v->query('PRAGMA table_info(cartoes)')->fetchAll(), 'name');
        $feitas = \Financeiro\Migrador::aplicar($v, \Financeiro\Migrador::pasta());
        $check('s0040: num site da 1.14, a 2.0 roda depois da s0004 e termina na s0040', count($da114) === 3 && end($colunas114) === 'visual'
            && $feitas[0] === 's0001_contas.sql' && (int) array_search('s0040_gatilho_cartoes.sql', $feitas, true) > 0, json_encode([$da114, $feitas]));
        $novo = new \Financeiro\SqliteConexao($arqNovo);
        $novo->exec((string) file_get_contents(dirname(__DIR__) . '/database/schema.sqlite.sql'));
        $e114 = $estrutura($v);
        $eN = $estrutura($novo);
        $check('s0040: o banco da 1.14 migrado tem a estrutura de um banco novo (o visual no fim do cartão)', $e114 === $eN,
            json_encode(array_keys(array_diff_assoc(array_map('json_encode', $e114), array_map('json_encode', $eN)) + array_diff_assoc(array_map('json_encode', $eN), array_map('json_encode', $e114)))));
        $check('s0040: o visual de cada cartão continua o mesmo', $v->query('SELECT visual FROM cartoes ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN) === ['roxo', '#2f6fdf']
            && (int) $v->query('SELECT conta_id FROM cartoes WHERE id = 2')->fetchColumn() === 1);
        $mudou = static function (string $set) use ($v): bool {
            $v->exec("UPDATE cartoes SET atualizado_em = '2000-01-01 00:00:00' WHERE id = 1");
            $v->exec('UPDATE cartoes SET ' . $set . ' WHERE id = 1');
            return $v->query('SELECT atualizado_em FROM cartoes WHERE id = 1')->fetchColumn() !== '2000-01-01 00:00:00';
        };
        $check('s0040: o gatilho de atualizado_em olha o visual, a conta e a ordem', $mudou("visual = 'grafite'") && $mudou('ordem = 7') && $mudou('conta_id = NULL'));
        $check('s0040: as chaves estrangeiras continuam certas; rodar de novo não faz nada', $v->query('PRAGMA foreign_key_check')->fetch() === false
            && \Financeiro\Migrador::aplicar($v, \Financeiro\Migrador::pasta()) === []);
    } catch (\Throwable $e) {
        $check('s0040', false, get_class($e) . ': ' . $e->getMessage() . ' em ' . basename($e->getFile()) . ':' . $e->getLine());
    } finally {
        $v = $novo = null;
        foreach ([$arq114, $arqNovo] as $a) {
            foreach (['', '-wal', '-shm'] as $s) {
                @unlink($a . $s);
            }
        }
        foreach ((array) glob($pasta114 . '/*') as $a) {
            @unlink((string) $a);
        }
        @rmdir($pasta114);
    }

    // ---- uma conta e um cartão: as telas são as de sempre
    LancarForm::limparCache();
    MotorFinanceiro::limparCache();
    $check('uma conta: o banco da demonstração tem a Conta principal com o saldo inicial do plano', count(ContaModel::todas()) === 1 && !ContaModel::varias()
        && abs(ContaModel::todas()[0]['saldo_inicial'] - AjusteModel::valor('saldo_ini')) < 0.001);
    $h1Lanc = $render(LancamentosController::class, 'lancarForm', []);
    $h1Ini = $render(DashboardController::class, 'index', []);
    $h1Ext = $render(LancamentosController::class, 'index', []);
    $h1Fe = $render(MesController::class, 'fecharForm', []);
    $h1Aj = $render(AjustesController::class, 'index', []);
    $h1Ct = $render(CartaoController::class, 'index', []);
    $check('uma conta: o lançar não mostra conta, Transferir nem "Recebeu em"', !str_contains($h1Lanc, 'name="modo"') && !str_contains($h1Lanc, 'is-varias')
        && !str_contains($h1Lanc, 'data-lf-entra') && str_contains($h1Lanc, 'name="pagou" value="conta" checked') && str_contains($h1Lanc, 'name="pagou" value="cartao"'));
    $check('uma conta: o Início, o Extrato e o Fechar são os de sempre', !str_contains($h1Ini, 'saldo-contas') && !str_contains($h1Ini, 'saldo_conta[')
        && str_contains($h1Ini, 'name="saldo_informado"') && !str_contains($h1Ext, 'name="conta"') && !str_contains($h1Ext, 'ex-transf')
        && str_contains($h1Ext, 'Mais filtros: valor e quem lançou') && str_contains($h1Fe, 'name="saldo"') && !str_contains($h1Fe, 'saldo_conta['));
    $check('uma conta: Ajustes tem Contas e o saldo do início continua no ponto de partida', str_contains($h1Aj, 'id="carteira"') && str_contains($h1Aj, 'valores[saldo_ini]'));
    $check('um cartão: sem seletor de cartões; "Novo cartão" fica no menu', !str_contains($h1Ct, 'ct-cartoes"') && str_contains($h1Ct, 'class="menu-item" href="/cartao?novo=1"'));

    // ---- várias contas: o saldo inicial do plano, transferências que não mudam o plano, o saldo de cada conta e as telas
    $emTransacao('contas e transferências', static function () use ($pdo, $check, $render, $hoje, $mesHoje, $uid, $catGasto): void {
        $iniAntes = AjusteModel::valor('saldo_ini');
        $saldo1 = ContaModel::saldo(ContaModel::PRINCIPAL, $hoje);
        $id2 = ContaModel::salvar(null, 'Carteira teste', 'dinheiro', 150.0);
        MotorFinanceiro::limparCache();
        LancarForm::limparCache();
        // com meses fechados, o saldo da conta nova entra no 1º mês aberto (aportes), fora de ajustes.saldo_ini
        $check('contas: o saldo inicial do plano é a soma dos saldos iniciais', abs(AjusteModel::valor('saldo_ini') + array_sum(ContaModel::aportes()) - $iniAntes - 150.0) < 0.001 && ContaModel::varias());
        $planoAntes = MotorFinanceiro::calcularPlano();
        $r = TransferenciaController::criarDe(['valor' => '80,00', 'de_conta' => (string) ContaModel::PRINCIPAL, 'para_conta' => (string) $id2, 'quando' => 'hoje', 'descricao' => 'saque teste']);
        MotorFinanceiro::limparCache();
        $planoDepois = MotorFinanceiro::calcularPlano();
        $saldos = ContaModel::saldos($hoje);
        $check('transferência: grava e não muda o plano em nenhum mês', $r['erro'] === null && $r['id'] > 0 && $planoDepois === $planoAntes, (string) $r['erro']);
        $check('transferência: sai de uma conta e entra na outra; o total continua o do motor', abs($saldos[ContaModel::PRINCIPAL] - ($saldo1 - 80.0)) < 0.001
            && abs($saldos[$id2] - 230.0) < 0.001 && abs(array_sum($saldos) - LancamentoModel::saldoCalculado(AjusteModel::valor('saldo_ini'), $hoje)) < 0.001, json_encode($saldos));
        $check('transferência: de uma conta para ela mesma ou sem valor é recusada',
            TransferenciaController::criarDe(['valor' => '10', 'de_conta' => (string) $id2, 'para_conta' => (string) $id2, 'quando' => 'hoje'])['erro'] !== null
            && TransferenciaController::criarDe(['valor' => '', 'de_conta' => '1', 'para_conta' => (string) $id2, 'quando' => 'hoje'])['erro'] !== null);
        $idL = LancamentoModel::criar($hoje, 'lanche teste', $catGasto, 30.0, $uid, $id2);
        MotorFinanceiro::limparCache();
        $check('conta: o gasto sai da conta escolhida; o filtro do extrato acha só ele', abs(ContaModel::saldo($id2, $hoje) - 200.0) < 0.001
            && (int) LancamentoModel::totais($mesHoje, ['conta_id' => $id2])['n'] === 1 && (int) LancamentoModel::find($idL)['conta_id'] === $id2);
        LancamentoModel::atualizar($idL, $hoje, 'lanche teste', $catGasto, 35.0);
        $check('conta: editar sem dizer a conta mantém a conta', (int) LancamentoModel::find($idL)['conta_id'] === $id2);
        $hLanc = $render(LancamentosController::class, 'lancarForm', []);
        $check('várias contas: o lançar tem as contas, os cartões, "Recebeu em" e o modo Transferir', str_contains($hLanc, 'name="modo" value="transferir"')
            && str_contains($hLanc, 'value="conta:' . $id2 . '"') && str_contains($hLanc, 'data-lf-entra') && str_contains($hLanc, 'data-entrada="Recebeu em"')
            && str_contains($hLanc, 'name="de_conta"') && str_contains($hLanc, 'data-so-gasto'));
        $check('várias contas: o padrão do "Pagou com" é o da última vez', str_contains($hLanc, 'value="conta:' . $id2 . '" checked data-lf-padrao'));
        $hIni = $render(DashboardController::class, 'index', []);
        $check('várias contas: o Início mostra o total e, nos detalhes, cada conta; conferir pede cada uma', str_contains($hIni, 'class="saldo-contas num"')
            && str_contains($hIni, 'Carteira teste') && str_contains($hIni, 'name="saldo_conta[' . $id2 . ']"') && !str_contains($hIni, 'name="saldo_informado"'));
        $hExt = $render(LancamentosController::class, 'index', ['conta' => (string) $id2]);
        $check('várias contas: o Extrato filtra pela conta, com o saldo dela e a transferência, fora dos totais', str_contains($hExt, 'class="ext-saldo-conta"')
            && str_contains($hExt, valor(195.0)) && str_contains($hExt, 'saque teste') && str_contains($hExt, 'ext-transf') && str_contains($hExt, '↔ +80,00')
            && str_contains($hExt, 'data-ex-total="gasto">' . valor(-35.0, 'saida') . '<'));
        $hFe = $render(MesController::class, 'fecharForm', []);
        $check('várias contas: fechar o mês pede o saldo do banco de cada conta', str_contains($hFe, 'name="saldo_conta[' . ContaModel::PRINCIPAL . ']"')
            && str_contains($hFe, 'name="saldo_conta[' . $id2 . ']"') && str_contains($hFe, 'data-conf-app="195.00"'));
        $hAj = $render(AjustesController::class, 'index', []);
        $check('várias contas: o saldo do início de cada conta fica em Contas', str_contains($hAj, 'Carteira teste') && !str_contains($hAj, 'valores[saldo_ini]'));
        \Financeiro\Models\SaldoConferenciaModel::registrar($hoje, 200.0, $uid, $id2);
        \Financeiro\Models\SaldoConferenciaModel::registrar($hoje, 999.0, $uid, $id2);    // de novo no mesmo dia: substitui
        $ultimas = \Financeiro\Models\SaldoConferenciaModel::ultimasPorConta();
        $check('conferência: uma por conta e por dia; a do total fica de fora', count($ultimas) === 1 && (float) $ultimas[$id2]['saldo_informado'] === 999.0
            && \Financeiro\Models\SaldoConferenciaModel::ultima()['conta_id'] === null);
        // arquivar: só zerada; o saldo inicial dela continua na soma, porque os lançamentos dela continuam no motor
        TransferenciaController::criarDe(['valor' => '195', 'de_conta' => (string) $id2, 'para_conta' => '1', 'quando' => 'hoje']);
        $check('conta: zerada para arquivar', abs(ContaModel::saldo($id2, '9999-12-31')) < 0.001);
        ContaModel::setAtiva($id2, false);
        MotorFinanceiro::limparCache();
        $check('conta arquivada: sai das telas; a soma das contas continua a do motor', !ContaModel::varias()
            && abs(array_sum(ContaModel::saldos($hoje)) - LancamentoModel::saldoCalculado(AjusteModel::valor('saldo_ini'), $hoje)) < 0.001
            && abs(AjusteModel::valor('saldo_ini') + array_sum(ContaModel::aportes()) - $iniAntes - 150.0) < 0.001);
    });
    $emTransacao('saldo do início com uma conta', static function () use ($check): void {
        $check('uma conta: o saldo do início digitado vai para a conta e para o plano', ContaModel::saldoInicialDaUnica(777.77)
            && abs(ContaModel::todas()[0]['saldo_inicial'] - 777.77) < 0.001 && abs(AjusteModel::valor('saldo_ini') - 777.77) < 0.001);
    });

    // ---- vários cartões, o pagamento ligado à fatura e o estorno
    $emTransacao('vários cartões', static function () use ($pdo, $check, $render, $hoje, $uid, $catGasto, $catFat): void {
        $c1 = CartaoModel::padrao();
        $c2 = CartaoModel::salvar(null, 'Cartão dois', 10, 20, null);
        $r = CartaoController::criarCompraDe(['valor' => '300', 'categoria_id' => (string) $catGasto, 'data' => $hoje, 'parcelas' => '3', 'descricao' => 'compra dois'], $hoje, $c2);
        $check('vários cartões: a compra vai para o cartão escolhido', $r['erro'] === null && $r['cartao'] === $c2 && CartaoModel::acharCompra($r['id'])['cartao_id'] === $c2);
        $f2 = CartaoFatura::faturaDe($hoje, 10, 20);
        $f1 = CartaoFatura::faturaDe($hoje, $c1['fecha'], $c1['vence']);
        CartaoModel::setAlimentaPlano($c2, true);
        CartaoFatura::sincronizarPlano($uid, $hoje);
        MotorFinanceiro::limparCache();
        $tot1 = CartaoFatura::totais(CartaoModel::compras($c1['id']));
        $tot2 = CartaoFatura::totais(CartaoModel::compras($c2));
        $mesL = max($f1, $f2);
        $sitL = (string) $pdo->query("SELECT situacao FROM meses WHERE mes = '" . $mesL . "'")->fetchColumn();
        $check('vários cartões: ligados ao plano, a Fatura do cartão é a soma das faturas de todos', $sitL !== 'PREVISAO'
            || abs(PrevisaoModel::valor($catFat, $mesL) - (($tot1[$mesL] ?? 0.0) + ($tot2[$mesL] ?? 0.0))) < 0.01, json_encode([$mesL, PrevisaoModel::valor($catFat, $mesL)]));
        $fPaga = CartaoFatura::somaMeses($f2, -1);
        $idPg = LancamentoModel::criar($hoje, 'Fatura do cartão', $catFat, 123.45, $uid, null, ['cartao_id' => $c2, 'fatura' => $fPaga]);
        MotorFinanceiro::limparCache();
        $fp = CartaoFatura::faturasPagas(MotorFinanceiro::dados()['prev'], true);
        $check('pagar fatura: o vínculo escolhido fica gravado; cada cartão tem as suas faturas pagas, e o plano soma', LancamentoModel::find($idPg)['fatura'] === $fPaga
            && (int) LancamentoModel::find($idPg)['cartao_id'] === $c2 && abs($fp['porCartao'][$c2][$fPaga]['pago'] - 123.45) < 0.001
            && abs($fp['faturas'][$fPaga]['pago'] - ($fp['porCartao'][$c1['id']][$fPaga]['pago'] ?? 0.0) - 123.45) < 0.001);
        LancamentoModel::atualizar($idPg, $hoje, 'Fatura do cartão', $catFat, 200.0);
        $check('pagar fatura: editar o valor mantém o cartão e a fatura', LancamentoModel::find($idPg)['fatura'] === $fPaga && (int) LancamentoModel::find($idPg)['cartao_id'] === $c2);
        LancamentoModel::atualizar($idPg, $hoje, 'virou mercado', $catGasto, 200.0);
        $check('pagar fatura: fora da categoria da fatura, o vínculo sai', LancamentoModel::find($idPg)['cartao_id'] === null && LancamentoModel::find($idPg)['fatura'] === null);
        $hPg = $render(CartaoController::class, 'index', ['cartao' => (string) $c2, 'fatura' => $fPaga]);
        $check('pagar fatura: a fatura fechada e ainda não paga do cartão escolhido tem o "Pagar fatura"', str_contains($hPg, 'action="/cartao/pagar"')
            && str_contains($hPg, 'name="fatura" value="' . $fPaga . '"') && str_contains($hPg, 'name="cartao" value="' . $c2 . '"'));
        $auto = LancamentoModel::criar($hoje, 'Fatura do cartão', $catFat, 10.0, $uid);
        $vAuto = LancamentoModel::find($auto);
        $check('pagar fatura: sem cartão escolhido, vai para o cartão com a fatura fechada em aberto', (int) $vAuto['cartao_id'] === $c2 && $vAuto['fatura'] === $fPaga, json_encode([$vAuto['cartao_id'], $vAuto['fatura']]));
        $e = CartaoController::criarCompraDe(['valor' => '50', 'estorno' => '1', 'parcelas' => '3', 'parcela_atual' => '2', 'categoria_id' => (string) $catGasto, 'data' => $hoje, 'descricao' => 'devolução'], $hoje, $c2);
        $ce = $e['erro'] === null ? CartaoModel::acharCompra($e['id']) : null;
        $check('estorno: negativo, em 1 parcela, na fatura da data dele', $ce !== null && $ce['valor_total'] === -50.0 && $ce['parcelas'] === 1 && $ce['primeira_fatura'] === $f2
            && str_contains($e['aviso'], 'Estorno'), json_encode([$e, $ce]));
        $check('estorno: abate da fatura', abs((CartaoFatura::totais(CartaoModel::compras($c2))[$f2] ?? 0.0) - (($tot2[$f2] ?? 0.0) - 50.0)) < 0.001);
        LancarForm::limparCache();
        $hCt = $render(CartaoController::class, 'index', ['cartao' => (string) $c2]);
        $check('vários cartões: a aba tem o seletor, mostra o cartão escolhido e "Novo cartão"', str_contains($hCt, 'class="faixa-grade ct-cartoes"')
            && str_contains($hCt, 'href="/cartao?cartao=' . $c2 . '" aria-current="page"') && str_contains($hCt, 'Cartão dois: fecha dia 10, vence dia 20')
            && substr_count($hCt, 'fecha dia 10') === 1 && str_contains($hCt, 'class="btn-link ct-novo" href="/cartao?novo=1"'));
        $hLc = $render(LancamentosController::class, 'lancarForm', ['pagou' => 'cartao:' . $c2]);
        $check('vários cartões: no lançar, um botão para cada cartão, já no escolhido', str_contains($hLc, 'value="cartao:' . $c2 . '" checked')
            && str_contains($hLc, 'value="cartao:' . $c1['id'] . '"') && str_contains($hLc, '&quot;cartoes&quot;'));
        CartaoModel::arquivar($c2);
        $check('vários cartões: arquivar tira o cartão da aba e do plano', count(CartaoModel::ativos()) === 1 && !CartaoModel::achar($c2)['alimenta_plano']);
    });

    // ---- as categorias especiais pela chave: o nome pode mudar
    $emTransacao('chaves das categorias', static function () use ($pdo, $check, $catFat): void {
        $pdo->exec("UPDATE categorias SET nome = 'Poupança do mês' WHERE chave = 'guardar'");
        $pdo->exec("UPDATE categorias SET nome = 'Meu cartão de crédito' WHERE chave = 'fatura'");
        $pdo->exec("UPDATE categorias SET nome = 'Pagamento' WHERE chave = 'salario'");
        MotorFinanceiro::limparCache();
        $d = MotorFinanceiro::dados();
        $guard = (int) $pdo->query("SELECT id FROM categorias WHERE chave = 'guardar'")->fetchColumn();
        $check('chaves: renomeadas, o motor e o Cartão continuam achando as categorias', $d['esp']['guard'] === $guard && CartaoModel::categoriaFatura() === $catFat
            && CategoriaModel::porChave(MotorFinanceiro::CAT_SALARIO)['nome'] === 'Pagamento'
            && Investimentos::resumo(Relogio::hoje())['poupanca']['idEntra'] === $guard);
    });

    // ---- importar extrato: OFX (SGML e XML) e CSV, números e datas, charset, identidade das linhas, cartão, regras,
    // memória, repetidas, gravar e desfazer, limites e arquivos maliciosos. Os exemplos ficam em tests/dados/extratos.
    $extratos = __DIR__ . '/dados/extratos/';
    $lerOk = static function (string $bruto): array {
        try {
            return \Financeiro\Services\ExtratoArquivo::ler($bruto);
        } catch (\InvalidArgumentException $e) {
            return ['erro' => $e->getMessage()];
        }
    };
    $sgml = $lerOk((string) file_get_contents($extratos . 'conta-sgml.ofx'));
    $check('importar: OFX SGML em Windows-1252, com o charset do cabeçalho', ($sgml['formato'] ?? '') === 'ofx' && count($sgml['linhas']) === 6
        && $sgml['linhas'][2]['descricao'] === 'AÇOUGUE SÃO JOÃO' && $sgml['linhas'][0]['valor'] === -123.45 && $sgml['linhas'][1]['valor'] === 3500.0
        && $sgml['linhas'][0]['data'] === '2026-11-03' && $sgml['linhas'][0]['descricao'] === 'COMPRA NO DEBITO SUPERMERCADO BOM PRECO', json_encode($sgml, JSON_UNESCAPED_UNICODE));
    $xml = $lerOk((string) file_get_contents($extratos . 'conta-xml.ofx'));
    $check('importar: OFX XML, com entidade e NAME + MEMO', ($xml['formato'] ?? '') === 'ofx' && count($xml['linhas']) === 3 && $xml['linhas'][0]['valor'] === -1234.56
        && $xml['linhas'][1]['descricao'] === 'RESTAURANTE SABOR & ARTE - Almoço de domingo' && $xml['linhas'][2]['fitid'] === 'X-2003', json_encode($xml, JSON_UNESCAPED_UNICODE));
    $doMapa = static function (array $lido): array {
        return \Financeiro\Services\ExtratoArquivo::aplicarMapa($lido['linhas'], \Financeiro\Services\ExtratoArquivo::mapaSugerido($lido['linhas']), $lido['sep']);
    };
    $csvBr = $lerOk((string) file_get_contents($extratos . 'conta.csv'));
    $mapaBr = \Financeiro\Services\ExtratoArquivo::mapaSugerido($csvBr['linhas'] ?? []);
    $apBr = $doMapa($csvBr);
    $check('importar: CSV com ";" e vírgula decimal; "1.234,56" vira 1234,56; o saldo não é o valor', ($csvBr['sep'] ?? '') === ';'
        && $mapaBr['data'] === 0 && $mapaBr['descricao'] === 1 && $mapaBr['valor'] === 2 && $mapaBr['cabecalho'] === true
        && array_column($apBr['linhas'], 'valor') === [-1234.56, 3500.0, -42.5] && $apBr['linhas'][2]['descricao'] === 'Farmácia; perto de casa', json_encode([$mapaBr, $apBr], JSON_UNESCAPED_UNICODE));
    $apUs = $doMapa($lerOk("Date,Description,Amount\n2026-11-03,\"Coffee, shop\",-4.50\n2026-11-04,Refund,\"1,234.56\"\n"));
    $check('importar: CSV com "," e ponto decimal, data AAAA-MM-DD', array_column($apUs['linhas'], 'valor') === [-4.5, 1234.56]
        && $apUs['linhas'][0]['descricao'] === 'Coffee, shop' && $apUs['linhas'][1]['data'] === '2026-11-04', json_encode($apUs));
    $dc = $lerOk("Data;Descrição;Débito;Crédito;Saldo\n03/11/2026;Mercado;150,00;;1.000,00\n05/11/2026;Salário;;3.000,00;4.000,00\n");
    $mapaDc = \Financeiro\Services\ExtratoArquivo::mapaSugerido($dc['linhas']);
    $apDc = $doMapa($dc);
    $check('importar: CSV com débito e crédito em colunas separadas', $mapaDc['valor'] === -1 && $mapaDc['debito'] === 2 && $mapaDc['credito'] === 3
        && array_column($apDc['linhas'], 'valor') === [-150.0, 3000.0], json_encode([$mapaDc, $apDc]));
    $num = [\Financeiro\Services\ExtratoArquivo::class, 'numero'];
    $check('importar: números de extrato', $num('1.234,56', ',') === 1234.56 && $num('1.234,56', null) === 1234.56 && $num('1,234.56', '.') === 1234.56
        && $num('(12,00)', ',') === -12.0 && $num('12,00-', ',') === -12.0 && $num('R$ -7,50', ',') === -7.5 && $num('1.234', ',') === 1234.0
        && $num('45,90 D', ',') === -45.9 && $num('-1234.5', null) === -1234.5 && $num('abc', ',') === null && $num('', ',') === null && $num('12,', ',') === null);
    $dt = [\Financeiro\Services\ExtratoArquivo::class, 'data'];
    $check('importar: datas dd/mm/aaaa, dd/mm/aa, AAAA-MM-DD e com hora', $dt('03/11/2026') === '2026-11-03' && $dt('03/11/26') === '2026-11-03'
        && $dt('2026-11-03') === '2026-11-03' && $dt('2026-11-03 14:30:00') === '2026-11-03' && $dt('11/03/2026', 'mdy') === '2026-11-03'
        && $dt('31/02/2026') === null && $dt('ontem') === null
        && \Financeiro\Services\ExtratoArquivo::ordemDatas(['11/03/2026', '12/25/2026']) === 'mdy' && \Financeiro\Services\ExtratoArquivo::ordemDatas(['25/12/2026']) === 'dmy');
    $w1252 = $lerOk((string) mb_convert_encoding("Data;Descrição;Valor\n03/11/2026;Açougue São João;-10,00\n", 'Windows-1252', 'UTF-8'));
    $check('importar: CSV em Windows-1252 vira UTF-8', ($w1252['linhas'][1][1] ?? '') === 'Açougue São João' && ($w1252['linhas'][0][1] ?? '') === 'Descrição', json_encode($w1252, JSON_UNESCAPED_UNICODE));
    $check('importar: CSV em UTF-16 com BOM', ($lerOk("\xFF\xFE" . mb_convert_encoding("Data;Valor\n03/11/2026;-1,00\n", 'UTF-16LE', 'UTF-8'))['linhas'][1][1] ?? '') === '-1,00');
    $erroDe = static fn(array $r): string => (string) ($r['erro'] ?? '');
    $check('importar: arquivo grande demais é recusado antes de ler', str_contains($erroDe($lerOk(str_repeat('a;b;1,00' . "\n", 300000))), '2 MB'));
    $muitas = "OFXHEADER:100\n<OFX><BANKTRANLIST>" . str_repeat("<STMTTRN><DTPOSTED>20261103<TRNAMT>-1.00<FITID>1</STMTTRN>", \Financeiro\Services\ExtratoArquivo::MAX_LINHAS + 1) . '</BANKTRANLIST></OFX>';
    $check('importar: arquivo com linhas demais é recusado', str_contains($erroDe($lerOk($muitas)), 'mais de')
        && str_contains($erroDe($lerOk(str_repeat("03/11/2026;x;-1,00\n", \Financeiro\Services\ExtratoArquivo::MAX_LINHAS + 60))), 'mais de'));
    $xxe = '<?xml version="1.0"?><!DOCTYPE OFX [<!ENTITY x SYSTEM "file:///etc/passwd">]><OFX><BANKTRANLIST><STMTTRN><DTPOSTED>20261103</DTPOSTED><TRNAMT>-1.00</TRNAMT><NAME>&x;</NAME></STMTTRN></BANKTRANLIST></OFX>';
    $check('importar: OFX com entidade externa (XXE) é recusado', $erroDe($lerOk($xxe)) !== '' && !str_contains((string) json_encode($lerOk($xxe)), 'root:'));
    $check('importar: binário, planilha do Excel e PDF são recusados com a frase certa', $erroDe($lerOk("\x00\x01\x02binario\x00" . random_bytes(64))) !== ''
        && str_contains($erroDe($lerOk("PK\x03\x04" . random_bytes(32))), 'Excel') && str_contains($erroDe($lerOk("%PDF-1.7\n")), 'PDF') && $erroDe($lerOk("  \n ")) !== '');
    $check('importar: parcela no fim da descrição', \Financeiro\Services\Importacao::parcela('LOJA 03/10') === ['k' => 3, 'n' => 10, 'base' => 'LOJA']
        && \Financeiro\Services\Importacao::parcela('LOJA PARC 2/6')['base'] === 'LOJA' && \Financeiro\Services\Importacao::parcela('CINEMA 15/10') === null
        && \Financeiro\Services\Importacao::parcela('MERCADO 05/11', '2026-11-05') === null && \Financeiro\Services\Importacao::parcela('MERCADO') === null);

    $emTransacao('importar extrato numa conta', static function () use ($pdo, $check, $render, $uid, $extratos, $catGasto): void {
        $conta = ['tipo' => 'conta', 'id' => ContaModel::PRINCIPAL];
        $bruto = (string) file_get_contents($extratos . 'conta-sgml.ofx');
        $check('importar: o arquivo vai para a sessão, sem ser gravado', \Financeiro\Controllers\ImportacaoController::lerArquivo($bruto, 'conta-sgml.ofx', $conta) === null
            && count($_SESSION['importar']['linhas']) === 6);
        $est = $_SESSION['importar'];
        $rev = \Financeiro\Services\Importacao::revisao($est['linhas'], $conta, 'ofx');
        $check('importar: FITID repetido não junta linhas; duas iguais no mesmo dia são duas', count(array_unique(array_column($rev, 'chave'))) === 6
            && array_column($rev, 'ordem') === [1, 1, 1, 1, 2, 1] && count(array_filter($rev, static fn($r) => $r['marcada'])) === 6, json_encode(array_column($rev, 'ordem')));
        $check('importar: o sinal diz entrada ou gasto', array_column($rev, 'tipo') === ['gasto', 'entrada', 'gasto', 'gasto', 'gasto', 'gasto']);
        // sem categoria não grava nada
        $semCat = array_map(static fn(array $r): array => ['categoria_id' => null] + $r, $rev);
        $g0 = \Financeiro\Services\Importacao::gravar($semCat, $conta, $est, $uid);
        $check('importar: linha marcada sem categoria não deixa gravar nada', $g0['erro'] !== null && str_contains($g0['erro'], 'categoria')
            && (int) $pdo->query('SELECT COUNT(*) FROM importacoes')->fetchColumn() === 0);
        // categorias: gasto para as de saída, entrada para a de entrada
        $catEntrada = (int) array_key_first(\Financeiro\Services\Importacao::permitidas('entrada'));
        $escolhas = [];
        foreach ($rev as $r) {
            $escolhas[$r['i']] = ['m' => 1, 'c' => $r['tipo'] === 'entrada' ? $catEntrada : $catGasto];
        }
        $rev = \Financeiro\Services\Importacao::revisao($est['linhas'], $conta, 'ofx', $escolhas);
        $g = \Financeiro\Services\Importacao::gravar($rev, $conta, $est, $uid);
        $linhas = $pdo->query('SELECT conta_id, valor, descricao, categoria_id, importacao_chave FROM lancamentos WHERE importacao_id = ' . (int) $g['id'] . ' ORDER BY id')->fetchAll();
        $check('importar: "Lançar todos" grava um lote, cada lançamento com importacao_id e a identidade', $g['erro'] === null && $g['n'] === 6 && count($linhas) === 6
            && array_unique(array_map('intval', array_column($linhas, 'conta_id'))) === [ContaModel::PRINCIPAL] && (float) $linhas[0]['valor'] === 123.45
            && (int) $linhas[1]['categoria_id'] === $catEntrada && $linhas[2]['descricao'] === 'AÇOUGUE SÃO JOÃO'
            && (int) $pdo->query('SELECT linhas FROM importacoes WHERE id = ' . (int) $g['id'])->fetchColumn() === 6, json_encode([$g, $linhas], JSON_UNESCAPED_UNICODE));
        $de = \Financeiro\Services\Importacao::revisao($est['linhas'], $conta, 'ofx');
        $check('importar: o mesmo arquivo de novo vem todo como já importado, desmarcado', array_values(array_unique(array_column($de, 'dup'))) === ['importada']
            && array_filter(array_column($de, 'marcada')) === [] && \Financeiro\Models\ImportacaoModel::jaImportado($est['hash']) !== null);
        // um extrato que cobre parte do anterior, com uma linha nova: só ela vem marcada
        $parte = array_slice($est['linhas'], 3);
        $parte[] = ['data' => '2026-11-09', 'valor' => -7.5, 'descricao' => 'PADARIA DA ESQUINA 2', 'fitid' => '1009', 'categoria' => ''];
        $rp = \Financeiro\Services\Importacao::revisao($parte, $conta, 'ofx');
        $check('importar: extrato que repete parte do anterior: só o novo vem marcado, com a categoria da memória', array_column($rp, 'marcada') === [false, false, false, true]
            && $rp[3]['sugestao'] === $catGasto && $rp[3]['origem'] === 'memoria', json_encode(array_map(static fn($r) => [$r['dup'], $r['ordem'], $r['sugestao'], $r['origem']], $rp)));
        // lançado à mão: mesma data, valor e conta
        LancamentoModel::criar('2026-11-11', 'almoço', $catGasto, 89.9, $uid, ContaModel::PRINCIPAL);
        $linhasX = \Financeiro\Services\ExtratoArquivo::ler((string) file_get_contents($extratos . 'conta-xml.ofx'))['linhas'];
        $rx = \Financeiro\Services\Importacao::revisao($linhasX, $conta, 'ofx');
        $check('importar: o que já foi lançado à mão (mesma data, valor e conta) vem desmarcado, com aviso', $rx[1]['dup'] === 'parecida' && $rx[1]['parecida'] === 'almoço'
            && !$rx[1]['marcada'] && $rx[0]['marcada'] && $rx[2]['marcada'] && $rx[0]['dup'] === null);
        // regras: criadas na revisão, mudam as linhas que casam e valem nas próximas
        $_SESSION['importar'] = $est;
        $catOutra = (int) array_keys(\Financeiro\Services\Importacao::permitidas('gasto'))[1];
        $r = \Financeiro\Controllers\ImportacaoController::criarRegra(['regra_texto' => 'Padaria', 'regra_categoria' => (string) $catOutra], $est, []);
        $check('importar: "Sempre que tiver PADARIA, usar…" muda as linhas da revisão', $r['erro'] === null && ($r['escolhas'][3]['c'] ?? 0) === $catOutra
            && ($r['escolhas'][4]['c'] ?? 0) === $catOutra && !isset($r['escolhas'][0]), json_encode($r));
        $rr = \Financeiro\Services\Importacao::revisao($parte, $conta, 'ofx');
        $check('importar: a regra vale nas próximas e ganha da memória', $rr[3]['sugestao'] === $catOutra && $rr[3]['origem'] === 'regra' && $rr[3]['regra'] === 'Padaria');
        $check('importar: regra curta demais ou sem categoria é recusada', \Financeiro\Controllers\ImportacaoController::criarRegra(['regra_texto' => '12', 'regra_categoria' => (string) $catOutra], $est, [])['erro'] !== null
            && \Financeiro\Controllers\ImportacaoController::criarRegra(['regra_texto' => 'mercado', 'regra_categoria' => ''], $est, [])['erro'] !== null);
        $hRev = $render(\Financeiro\Controllers\ImportacaoController::class, 'revisarForm', []);
        $check('importar: a tela de revisão tem as linhas, a categoria, a regra e "Lançar todos"', str_contains($hRev, 'name="marcar[0]"') && str_contains($hRev, 'name="cat[5]"')
            && str_contains($hRev, 'Já foi importada antes.') && str_contains($hRev, 'name="regra_texto"') && str_contains($hRev, 'value="lancar"') && str_contains($hRev, 'Lançar todos')
            && str_contains($hRev, 'name="token" value="' . $est['token'] . '"'));
        $hIni = $render(\Financeiro\Controllers\ImportacaoController::class, 'index', []);
        $check('importar: a tela inicial tem o destino, o arquivo, o modelo, as últimas importações e as regras', str_contains($hIni, 'action="/importar"') && str_contains($hIni, 'enctype="multipart/form-data"')
            && str_contains($hIni, 'value="conta:' . ContaModel::PRINCIPAL . '"') && str_contains($hIni, 'href="/importar/modelo"') && str_contains($hIni, 'conta-sgml.ofx')
            && str_contains($hIni, 'action="/importar/' . (int) $g['id'] . '/desfazer"') && str_contains($hIni, 'data-confirm=') && str_contains($hIni, 'Tem “Padaria”'));
        $hExt = $render(LancamentosController::class, 'index', ['mes' => '2026-11-01']);
        $check('importar: o Extrato tem "Importar extrato"', str_contains($hExt, 'href="/importar"') && str_contains($hExt, 'Importar extrato'));
        // desfazer com uma linha num mês fechado: recusado, com a frase do mês fechado (B-01); reaberto, sai
        $mesesLote = \Financeiro\Models\ImportacaoModel::meses((int) $g['id']);
        MesModel::setSituacao($mesesLote[0], 'REAL');
        [$erroDf, $fraseDf] = [\Financeiro\Services\Importacao::erroDesfazer((int) $g['id']), LancamentosController::erroMesFechado($mesesLote[0])];
        MesModel::setSituacao($mesesLote[0], 'PREVISAO');
        $check('importar: "Desfazer importação" com uma linha num mês fechado é recusado, com a frase do mês fechado', $mesesLote !== []
            && $erroDf === $fraseDf && $erroDf !== null
            && \Financeiro\Services\Importacao::erroDesfazer((int) $g['id']) === null, json_encode([$mesesLote, $erroDf]));
        // desfazer: o lote inteiro sai, e o arquivo pode entrar de novo
        $antes = (int) $pdo->query('SELECT COUNT(*) FROM lancamentos')->fetchColumn();
        $n = \Financeiro\Services\Importacao::desfazer((int) $g['id'], $uid);
        $check('importar: "Desfazer importação" apaga o lote inteiro e só ele', $n === 6 && (int) $pdo->query('SELECT COUNT(*) FROM lancamentos')->fetchColumn() === $antes - 6
            && $pdo->query('SELECT desfeita_em FROM importacoes WHERE id = ' . (int) $g['id'])->fetchColumn() !== null
            && \Financeiro\Models\ImportacaoModel::jaImportado($est['hash']) === null && \Financeiro\Services\Importacao::desfazer((int) $g['id'], $uid) === 0);
        $check('importar: depois de desfeito, as linhas voltam a vir marcadas', array_filter(array_column(\Financeiro\Services\Importacao::revisao($est['linhas'], $conta, 'ofx'), 'dup')) === []);
        unset($_SESSION['importar']);
    });

    $emTransacao('importar fatura de cartão', static function () use ($pdo, $check, $uid, $extratos, $catGasto, $catFat): void {
        $cartao = CartaoModel::padrao();
        $destino = ['tipo' => 'cartao', 'id' => $cartao['id']];
        $linhas = \Financeiro\Services\ExtratoArquivo::ler((string) file_get_contents($extratos . 'cartao.ofx'))['linhas'];
        $rev = \Financeiro\Services\Importacao::revisao($linhas, $destino, 'ofx');
        $fatura = CartaoFatura::faturaDe('2026-11-10', $cartao['fecha'], $cartao['vence']);
        $check('importar cartão: compra, parcela k/n, pagamento e estorno', array_column($rev, 'tipo') === ['parcela', 'compra', 'pagamento', 'estorno'], json_encode(array_column($rev, 'tipo')));
        $check('importar cartão: "03/10" vira 10 parcelas, a 1ª duas faturas antes da fatura da data', $rev[0]['k'] === 3 && $rev[0]['n'] === 10 && $rev[0]['fatura'] === $fatura
            && $rev[0]['primeira'] === CartaoFatura::somaMeses($fatura, -2) && $rev[0]['total'] === 3000.0 && $rev[0]['gravar'] === 'LOJA DE MÓVEIS');
        $check('importar cartão: o pagamento fica de fora, desmarcado; o estorno entra', !$rev[2]['pode'] && !$rev[2]['marcada'] && $rev[3]['pode'] && $rev[3]['marcada']
            && !isset(\Financeiro\Services\Importacao::permitidas('compra')[$catFat]));
        $escolhas = [];
        foreach ($rev as $r) {
            $escolhas[$r['i']] = ['m' => $r['pode'] ? 1 : 0, 'c' => $catGasto];
        }
        $g = \Financeiro\Services\Importacao::gravar(\Financeiro\Services\Importacao::revisao($linhas, $destino, 'ofx', $escolhas), $destino,
            ['nome' => 'cartao.ofx', 'formato' => 'ofx', 'hash' => hash('sha256', 'cartao')], $uid);
        $compras = $pdo->query('SELECT descricao, valor_total, parcelas, primeira_fatura FROM cartao_compras WHERE importacao_id = ' . (int) $g['id'] . ' ORDER BY id')->fetchAll();
        $check('importar cartão: grava a compra parcelada, a compra e o estorno negativo; o pagamento não vira compra', $g['erro'] === null && count($compras) === 3
            && $compras[0]['descricao'] === 'LOJA DE MÓVEIS' && (float) $compras[0]['valor_total'] === 3000.0 && (int) $compras[0]['parcelas'] === 10
            && $compras[0]['primeira_fatura'] === CartaoFatura::somaMeses($fatura, -2) && (float) $compras[2]['valor_total'] === -59.9 && (int) $compras[2]['parcelas'] === 1
            && (int) $pdo->query('SELECT COUNT(*) FROM lancamentos WHERE importacao_id = ' . (int) $g['id'])->fetchColumn() === 0, json_encode([$g, $compras], JSON_UNESCAPED_UNICODE));
        // a fatura seguinte traz a mesma compra como "04/10", um mês depois: é a mesma compra
        $seguinte = [['data' => CartaoFatura::somaMeses('2026-11-01', 1) === '2026-12-01' ? '2026-12-10' : '', 'valor' => -300.0, 'descricao' => 'LOJA DE MÓVEIS 04/10', 'fitid' => 'C-9', 'categoria' => '']];
        $rs = \Financeiro\Services\Importacao::revisao($seguinte, $destino, 'ofx');
        $check('importar cartão: a parcela seguinte da mesma compra, na fatura seguinte, vem como já importada', $rs[0]['dup'] === 'importada' && !$rs[0]['marcada'], json_encode($rs[0]));
        $rd = \Financeiro\Services\Importacao::revisao($linhas, $destino, 'ofx');
        $check('importar cartão: o mesmo arquivo de novo vem desmarcado', array_column($rd, 'marcada') === [false, false, false, false]);
        // compra lançada à mão no cartão, na mesma data e valor
        CartaoModel::criarCompra($cartao['id'], '2026-11-20', 'pizza', $catGasto, 45.0, 1, CartaoFatura::faturaDe('2026-11-20', $cartao['fecha'], $cartao['vence']), $uid);
        $rm = \Financeiro\Services\Importacao::revisao([['data' => '2026-11-20', 'valor' => -45.0, 'descricao' => 'PIZZARIA', 'fitid' => '', 'categoria' => '']], $destino, 'ofx');
        $check('importar cartão: compra já lançada à mão vem desmarcada', $rm[0]['dup'] === 'parecida' && $rm[0]['parecida'] === 'pizza');
        // num CSV de cartão as compras costumam vir positivas: o sinal da maioria decide
        $rc = \Financeiro\Services\Importacao::revisao([['data' => '2026-11-03', 'valor' => 20.0, 'descricao' => 'A', 'fitid' => '', 'categoria' => ''],
            ['data' => '2026-11-04', 'valor' => 30.0, 'descricao' => 'B', 'fitid' => '', 'categoria' => ''], ['data' => '2026-11-05', 'valor' => -500.0, 'descricao' => 'PAGAMENTO EFETUADO', 'fitid' => '', 'categoria' => '']], $destino, 'csv');
        $check('importar cartão: com compras positivas, o negativo é o pagamento', array_column($rc, 'tipo') === ['compra', 'compra', 'pagamento']);
        $check('importar cartão: o desfazer olha a 1ª fatura de cada compra, como o Excluir do Cartão', in_array($rev[0]['primeira'], \Financeiro\Models\ImportacaoModel::meses((int) $g['id']), true));
        $n = \Financeiro\Services\Importacao::desfazer((int) $g['id'], $uid);
        $check('importar cartão: desfazer apaga as compras do lote', $n === 3 && (int) $pdo->query('SELECT COUNT(*) FROM cartao_compras WHERE importacao_id = ' . (int) $g['id'])->fetchColumn() === 0);
    });

    $emTransacao('importar CSV, colunas e modelo', static function () use ($check, $render, $extratos): void {
        $conta = ['tipo' => 'conta', 'id' => ContaModel::PRINCIPAL];
        $bruto = (string) file_get_contents($extratos . 'conta.csv');
        $check('importar CSV: sem as colunas lembradas, vai para "Ligar as colunas"', \Financeiro\Controllers\ImportacaoController::lerArquivo($bruto, 'conta.csv', $conta) === null
            && $_SESSION['importar']['linhas'] === null && $_SESSION['importar']['csv']['sep'] === ';');
        $hCol = $render(\Financeiro\Controllers\ImportacaoController::class, 'colunasForm', []);
        $check('importar CSV: a prévia e as colunas sugeridas', str_contains($hCol, 'name="data"') && str_contains($hCol, '<option value="2" selected>') && str_contains($hCol, 'Mercado do bairro')
            && str_contains($hCol, 'name="debito"') && str_contains($hCol, 'name="cabecalho" value="1" checked'));
        $lido = \Financeiro\Services\ExtratoArquivo::ler($bruto);
        \Financeiro\Models\ImportacaoModel::salvarLayout(\Financeiro\Services\ExtratoArquivo::layout($lido['linhas'], ';', true), \Financeiro\Services\ExtratoArquivo::mapaSugerido($lido['linhas']));
        \Financeiro\Controllers\ImportacaoController::lerArquivo($bruto, 'conta.csv', $conta);
        $check('importar CSV: com o cabeçalho conhecido, pula as colunas', count((array) $_SESSION['importar']['linhas']) === 3);
        unset($_SESSION['importar']);
        // o modelo para quem vem do caderno: baixa, preenche e volta pela importação, com a categoria da planilha
        ob_start();
        (new \Financeiro\Controllers\ImportacaoController())->modelo();
        $modelo = (string) ob_get_clean();
        $check('importar: o modelo é um CSV com BOM, ";" e vírgula decimal', str_starts_with($modelo, "\u{FEFF}Data;Descrição;Valor;Categoria\r\n") && str_contains($modelo, ';-235,90;'), substr($modelo, 0, 80));
        $lm = \Financeiro\Services\ExtratoArquivo::ler($modelo);
        $am = \Financeiro\Services\ExtratoArquivo::aplicarMapa($lm['linhas'], \Financeiro\Services\ExtratoArquivo::mapaSugerido($lm['linhas']), $lm['sep']);
        $rm = \Financeiro\Services\Importacao::revisao($am['linhas'], $conta, 'csv');
        $salario = CategoriaModel::porChave(MotorFinanceiro::CAT_SALARIO);
        $check('importar: o modelo preenchido entra, e a coluna de categoria escolhe a categoria', count($rm) === 3 && $rm[0]['sugestao'] === (int) $salario['id'] && $rm[0]['origem'] === 'csv'
            && $rm[1]['origem'] === 'csv' && $rm[2]['origem'] !== 'csv', json_encode(array_map(static fn($r) => [$r['sugestao'], $r['origem']], $rm)));
    });
}
// ---- Mês: com valor próprio no mês, a barra compara com ele, não com a meta de todo mês
// ---- Mês: com valor próprio no mês, a situação (abaixo, na meta, acima) compara com ele, não com a meta de todo mês
$emTransacao('barra com o valor do mês', static function () use ($pdo, $check, $render): void {
    $mesB = \Financeiro\Relogio::mes();
    $pdo->exec("INSERT INTO categorias (nome, grupo, sinal, ativo, ordem, essencial) VALUES ('Teste barra do mês', 'Dia a dia', -1, 1, 998, 1)");
    $catB = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO categorias (nome, grupo, sinal, ativo, ordem, essencial) VALUES ('Teste barra zerada', 'Dia a dia', -1, 1, 999, 0)");
    $catZ = (int) $pdo->lastInsertId();
    \Financeiro\Models\MetaModel::definir($catB, 1000.0);
    \Financeiro\Models\MetaModel::definir($catZ, 100.0);
    PrevisaoModel::setValor($catB, $mesB, 800.0);
    PrevisaoModel::setValor($catZ, $mesB, 0.0);
    MotorFinanceiro::limparCache();
    $html = $render(MesController::class, 'index', ['mes' => $mesB]);
    preg_match('#<tr id="cat-' . $catB . '".*?</tr>#s', $html, $mB);
    preg_match('#<tr id="cat-' . $catZ . '".*?</tr>#s', $html, $mZ);
    $check('Mês: com valor próprio e nada gasto, falta o valor do mês inteiro', str_contains($mB[0] ?? '', 'data-meta="800.00"') && str_contains($mB[0] ?? '', 'falta 800,00'), substr($mB[0] ?? '', 0, 400));
    LancamentoModel::criar(\Financeiro\Relogio::hoje(), 'teste barra', $catB, 750.0, (int) $pdo->query('SELECT MIN(id) FROM usuarios')->fetchColumn());
    MotorFinanceiro::limparCache();
    preg_match('#<tr id="cat-' . $catB . '".*?</tr>#s', $render(MesController::class, 'index', ['mes' => $mesB]), $mB2);
    $check('Mês: 750 de 800 (o valor do mês, não a meta de 1.000): falta 50, com a barra quase cheia', str_contains($mB2[0] ?? '', 'falta 50,00')
        && str_contains($mB2[0] ?? '', '--p:0.938'), substr($mB2[0] ?? '', 0, 400));
    $check('Mês: sem previsão no mês e sem gasto, a categoria sai da lista e fica em "Incluir"', ($mZ[0] ?? '') === ''
        && preg_match('#<details class="mes-incluir".*?value="' . $catZ . '"#s', $html) === 1);
});

// ---- categoria fixa (conta) ou que varia (com meta); ordenar a fatura e os parcelamentos
$emTransacao('categoria fixa e ordem do cartão', static function () use ($pdo, $check, $render): void {
    $idF = CategoriaModel::criar('Teste conta fixa', 'saida', true, null, true);
    $idV = CategoriaModel::criar('Teste gasto que varia', 'saida', false, null, false);
    $grupoDe = static fn(?int $id): string => (string) $pdo->query('SELECT grupo FROM categorias WHERE id = ' . (int) $id)->fetchColumn();
    $check('categoria: fixa vira conta (Fixos), a que varia fica no dia a dia', $grupoDe($idF) === 'Fixos' && $grupoDe($idV) === 'Dia a dia');
    $html = $render(CartaoController::class, 'index', ['ordem' => 'alfa']);
    if (preg_match('#<div class="ct-fat" id="[^"]*">(.*?)</div>\s*(?:<div class="ct-fat"|</section>)#s', $html, $mF) === 1) {
        preg_match_all('#<span class="ct-nome-txt"><a [^>]*>([^<]+)</a>#', $mF[1], $nm);
        $nomes = array_map(static fn(string $n): string => mb_strtolower(html_entity_decode($n)), $nm[1]);
        $ord = $nomes;
        sort($ord, SORT_LOCALE_STRING);
        $check('cartão: "De A a Z" ordena a fatura', count($nomes) < 2 || $nomes === $ord, json_encode($nomes));
    } else {
        $check('cartão: "De A a Z" ordena a fatura', !str_contains($html, 'ct-linha-n'), 'fatura não achada');
    }
    $check('cartão: os seletores de ordem estão na tela', !str_contains($html, 'ct-fat') || (str_contains($html, 'name="ordem"') && str_contains($html, 'De A a Z')));
});

// ---- o Mês fecha a conta: começou com + sobra − o adiantamento automático = termina com
$desvioPonte = [];
foreach (MotorFinanceiro::calcularPlano() as $mP => $lP) {
    if ($lP['situacao'] === 'REAL') {
        continue;
    }
    $sP = \Financeiro\Services\Sobra::prevista($lP);
    $contaP = (float) $lP['sobrou_mes_anterior'] + (float) $sP['sobra'] - (float) ($lP['amortizacao_auto'] ?? 0.0);
    if (abs($contaP - (float) $lP['fica_na_conta']) > 0.01) {
        $desvioPonte[] = $mP . ': ' . round($contaP, 2) . ' ≠ ' . $lP['fica_na_conta'];
    }
}
$check('Mês: começou com + sobra − adiantamento automático = termina com, em todo mês do plano', $desvioPonte === [], implode(' | ', array_slice($desvioPonte, 0, 3)));

// ---- a barra "para onde vai o dinheiro do mês" (Início e Mês) e o livro do Mês: as partes somam o total
$dDest = MotorFinanceiro::dados();
$desvioDest = $desvioLivro = [];
foreach (MotorFinanceiro::calcularPlano() as $mP => $lP) {
    $x = \Financeiro\Services\Destino::doMes($dDest, $lP, \Financeiro\Relogio::mes());
    if ($x !== null) {
        $soma = $x['saiu'] + $x['contas'] + $x['metas'] + $x['gastar'] + $x['reserva'] - $x['falta'];
        if (abs($soma - $x['total']) > 0.005 || abs($x['livre'] - (float) $lP['fica_na_conta'] - (float) $lP['amortizacao_auto']) > 0.01 || min($x) < -0.004 && $x['livre'] >= 0) {
            $desvioDest[] = $mP . ': ' . json_encode($x);
        }
    }
    $hL = $render(MesController::class, 'index', ['mes' => $mP]);
    if (!preg_match('/data-livro-fim="(-?[\d.]+)"/', $hL, $mLv) || abs((float) $mLv[1] - (float) $lP['fica_na_conta']) > 0.01) {
        $desvioLivro[] = $mP . ': ' . ($mLv[1] ?? '?') . ' ≠ ' . round((float) $lP['fica_na_conta'], 2);
    }
}
$check('destino: já saiu, contas a pagar, metas que faltam, dá para gastar e reserva somam o dinheiro do mês, em todo mês aberto', $desvioDest === [], implode(' | ', array_slice($desvioDest, 0, 2)));
$check('Mês: as linhas do livro (entradas, contas, dia a dia, guardado e empréstimo) fecham no fim do mês do plano, em todo mês', $desvioLivro === [], implode(' | ', array_slice($desvioLivro, 0, 3)));
$xHoje = \Financeiro\Services\Destino::doMes($dDest, MotorFinanceiro::calcularPlano()[\Financeiro\Relogio::mes()], \Financeiro\Relogio::mes());
$hIniD = $render(DashboardController::class, 'index', []);
$check('destino: no Início, "Dá para gastar" da barra é o número grande do topo', $xHoje === null || ($xHoje['gastar'] < 0.005 && !str_contains($hIniD, 'data-resposta="gastar"'))
    || (str_contains($hIniD, 'data-gastar="' . number_format($xHoje['gastar'], 2, '.', '') . '"')
        && preg_match('#<p class="resposta-num">' . preg_quote(valor($xHoje['gastar'], 'neutro', ['moeda' => true]), '#') . '</p>#', $hIniD) === 1));
// o Fechar: a escada das entradas e gastos até o balanço soma o que ela lista (antes, a poupança e o investimento ficavam fora)
$hFeP = $render(MesController::class, 'fecharForm', []);
$lerV = static fn(string $t): float => (float) str_replace([MENOS, '+', '.', ','], ['-', '', '', '.'], html_entity_decode(strip_tags($t)));
if (preg_match('#<table class="livro fe-ponte">.*?<tbody>(.*?)</tbody>.*?data-sobra="\w+">([^<]+)<#s', $hFeP, $mP2)) {
    preg_match_all('#<td class="c-real"[^>]*>([^<]+)</td>#', $mP2[1], $vs);
    $check('fechar: o balanço é a soma da escada que a tela lista', count($vs[1]) >= 1 && abs(array_sum(array_map($lerV, $vs[1])) - $lerV($mP2[2])) < 0.005, json_encode([$vs[1], $mP2[2]]));
} else {
    $check('fechar: o balanço é a soma da escada que a tela lista', false, 'escada não achada');
}

// ---- empréstimo consignado: o Salário aparece líquido no Mês, sem aviso solto; a escolha fica na aba Empréstimo
$mesC = \Financeiro\Relogio::mes();
$linhaC = MotorFinanceiro::linhaDoMes($mesC);
$catSal = (int) $pdo->query("SELECT id FROM categorias WHERE nome = 'Salário'")->fetchColumn();
if ($linhaC !== null && $linhaC['situacao'] !== 'REAL' && (float) $linhaC['parcela_descontada'] > 0.004 && $catSal > 0) {
    $htmlC = $render(MesController::class, 'index', ['mes' => $mesC]);
    preg_match('#<tr id="cat-' . $catSal . '".*?</tr>#s', $htmlC, $mS);
    $brutoC = (float) (MotorFinanceiro::dados()['prev'][$mesC][$catSal] ?? 0.0);
    $liqC = num(max(0.0, $brutoC - (float) $linhaC['parcela_descontada']));
    $check('consignado: o Salário aparece líquido no Mês', str_contains($mS[0] ?? '', $liqC) && !str_contains($mS[0] ?? '', num($brutoC)), $liqC . ' / ' . num($brutoC));
    $check('consignado: sem "vão para a parcela", sem "Valor bruto" e sem a ponte do bruto', !str_contains($htmlC, 'vão para a parcela') && !str_contains($htmlC, 'Valor bruto') && !str_contains($htmlC, 'de parcela'));
} else {
    $check('consignado: o Salário aparece líquido no Mês', true, 'demonstração sem parcela descontada no mês');
}
$htmlAj = $render(AjustesController::class, 'index', []);
$htmlEm = $render(EmprestimoController::class, 'index', []);
$check('consignado: a escolha de como a parcela é paga saiu de Ajustes e está no Empréstimo', !str_contains($htmlAj, 'name="parcela_modo"') && str_contains($htmlEm, 'name="parcela_modo"'));

// ---- QA de 2026-11 (área C): cartão, relatório, importar, investimentos, wishlist, empréstimo e simular
// C-01: o limite livre desconta a fatura fechada que falta pagar (no todo ou o que falta), a aberta e as seguintes
$cartL = ['fecha' => 10, 'vence' => 20, 'limite' => 5000.50];
$compL = [['id' => 1, 'valor_total' => 100.0, 'parcelas' => 1, 'primeira_fatura' => '2026-11-01', 'descricao' => 'Antes de fechar', 'data' => '2026-11-09'],
          ['id' => 2, 'valor_total' => 1570.0, 'parcelas' => 2, 'primeira_fatura' => '2026-12-01', 'descricao' => 'Depois fecha', 'data' => '2026-11-11']];
$livreL = static fn(array $pagas, array $compras = []): ?float => CartaoFatura::painel($cartL, $compras ?: $compL, '2026-11-22', $pagas)['livre'];
$antigaL = [['id' => 3, 'valor_total' => 600.0, 'parcelas' => 6, 'primeira_fatura' => '2026-08-01', 'descricao' => 'Compra antiga', 'data' => '2026-11-01']];
$check('C-01 cartão: o limite livre desconta a fatura fechada por pagar, e pagar a fatura libera o limite',
    abs($livreL([]) - 3330.50) < 0.005 && abs($livreL(['2026-11-01' => 40.0]) - 3370.50) < 0.005 && abs($livreL(['2026-11-01' => 100.0]) - 3430.50) < 0.005
    && abs($livreL(['2026-11-01' => 100.0, '2026-12-01' => 785.0]) - 4215.50) < 0.005
    // a compra antiga não ocupa o limite com as parcelas de antes da última fechada (pagas antes de usar o app)
    && abs($livreL([], $antigaL) - (5000.50 - 300.0)) < 0.005,
    json_encode([$livreL([]), $livreL(['2026-11-01' => 40.0]), $livreL(['2026-11-01' => 100.0]), $livreL([], $antigaL)]));

// C-02: a data nova leva a compra para a fatura dela, salvo se a fatura também foi trocada no mesmo envio
$compraE = ['data' => '2026-11-11', 'primeira_fatura' => '2026-12-01'];
$daDataE = CartaoFatura::faturaDe('2026-11-09', 10, 20);
$check('C-02 cartão: editar a data recalcula a fatura; a fatura escolhida à mão no mesmo envio vale',
    $daDataE === '2026-11-01' && CartaoController::primeiraNaEdicao($compraE, '2026-11-09', $daDataE, '2026-12-01') === '2026-11-01'
    && CartaoController::primeiraNaEdicao($compraE, '2026-11-09', $daDataE, '2027-01-01') === '2027-01-01'
    && CartaoController::primeiraNaEdicao($compraE, '2026-11-11', CartaoFatura::faturaDe('2026-11-11', 10, 20), '2027-01-01') === '2027-01-01'
    && CartaoController::primeiraNaEdicao($compraE, '2026-11-11', '2026-12-01', '2026-12-01') === '2026-12-01');

// C-03: "O que foi bom" e "Para onde foi" dão o mesmo número para a mesma categoria (no mês e no ano)
$contradiz = static function (?array $r): array {
    $erros = [];
    foreach ($r['categorias']['linhas'] ?? [] as $c) {
        foreach (['bom' => 'abaixo do planejado', 'ruim' => 'acima do planejado'] as $lado => $frase) {
            foreach ($r['achados'][$lado] ?? [] as $txt) {
                if (str_starts_with($txt, $c['nome'] . ':') && str_contains($txt, 'planejado')
                    && (!str_contains($txt, $frase) || !str_contains($txt, money(abs($c['dif']))))) {
                    $erros[] = $txt . ' / ' . $c['nome'] . ' ' . $c['dif'];
                }
            }
        }
    }
    return $erros;
};
$relOut = RelatorioDados::mensal('2026-10-01', $hoje);
$relAno = RelatorioDados::anual(2026, $hoje);
$check('C-03 relatório: o que foi bom ou ruim usa a mesma conta do "Para onde foi"', $relOut !== null && $relOut['encerrado'] && $relOut['porCompra']
    && $contradiz($relOut) === [] && $contradiz($relAno) === [], json_encode([$contradiz($relOut), $contradiz($relAno)], JSON_UNESCAPED_UNICODE));

// C-04: com dois cartões, "O que tinha na fatura" só tem as compras do cartão que a fatura pagou, e o "sem detalhe" bate
$emTransacao('C-04 relatório com dois cartões', static function () use ($check, $hoje, $uid, $catGasto): void {
    $c2 = CartaoModel::salvar(null, 'Cartão dois', 10, 20, null);
    CartaoModel::criarCompra($c2, '2026-09-15', 'delivery no outro cartão', $catGasto, 100.0, 1, '2026-10-01', $uid);
    MotorFinanceiro::limparCache();
    $r = RelatorioDados::mensal('2026-10-01', $hoje);
    $f = $r['fatura'] ?? null;
    $semDet = 0.0;
    foreach ($r['categorias']['linhas'] ?? [] as $c) {
        if (!empty($c['semPlano']) && $c['id'] === GastoPorCategoria::categoriaFatura()) {
            $semDet = $c['real'];
        }
    }
    $doPrimeiro = CartaoFatura::totais(CartaoModel::compras(CartaoModel::padrao()['id']))['2026-10-01'] ?? 0.0;
    $check('C-04 relatório: a fatura paga mostra só as compras do cartão dela, e "Fatura sem detalhe" é a mesma nos dois quadros',
        $f !== null && $f['paga'] && abs($f['soma'] - $doPrimeiro) < 0.005 && abs($f['semDetalhe'] - $semDet) < 0.005
        && abs($f['soma'] + $f['semDetalhe'] - $f['aMais'] - $f['pago']) < 0.005, json_encode([$f, $semDet, $doPrimeiro]));
});

// C-05: valor fora do padrão da coluna não é adivinhado: a linha vem desmarcada, com "Confira o valor"
$lidoM = \Financeiro\Services\ExtratoArquivo::ler("Data;Descricao;Valor\n10/11/2026;A;1.234,56\n11/11/2026;B;1234.56\n12/11/2026;C;-45,9\n");
$apM = \Financeiro\Services\ExtratoArquivo::aplicarMapa($lidoM['linhas'], \Financeiro\Services\ExtratoArquivo::mapaSugerido($lidoM['linhas']), $lidoM['sep']);
$revM = \Financeiro\Services\Importacao::revisao($apM['linhas'], ['tipo' => 'conta', 'id' => ContaModel::PRINCIPAL], 'csv');
$noPadrao = static fn(string $v, string $d): bool => \Financeiro\Services\ExtratoArquivo::noPadrao($v, $d);
$check('C-05 importar: "1234.56" numa coluna de vírgula decimal vem desmarcado, para conferir',
    array_column($revM, 'duvida') === [false, true, false] && array_column($revM, 'marcada') === [true, false, true]
    && $noPadrao('1.234,56', ',') && $noPadrao('-45,9', ',') && $noPadrao('R$ 7,50', ',') && $noPadrao('(12,00)', ',') && $noPadrao('1234', ',') && $noPadrao('1.234.567,89', ',')
    && !$noPadrao('1234.56', ',') && !$noPadrao('1,234', ',') && !$noPadrao('1,234.56', ',') && !$noPadrao('12,345', ',')
    && $noPadrao('1,234.56', '.') && $noPadrao('-4.5', '.') && !$noPadrao('1.234,56', '.'), json_encode(array_map(static fn($r) => [$r['valor'], $r['duvida'], $r['marcada']], $revM)));

// C-06: o resgate não passa do que está guardado
$emTransacao('C-06 resgate maior que o saldo', static function () use ($check, $hoje, $uid): void {
    $resg = (int) CategoriaModel::porChave(MotorFinanceiro::CAT_RESGATE)['id'];
    $saldoP = (float) Investimentos::resumo($hoje)['poupanca']['saldo'];
    $erroP = Investimentos::erroResgate($resg, $saldoP + 99999.0, $hoje);
    $idR = LancamentoModel::criar($hoje, 'resgate', $resg, max(0.01, $saldoP), $uid, ContaModel::PRINCIPAL);
    $check('C-06 investimentos: resgate maior que o saldo é recusado; até o saldo passa, e o editado não conta duas vezes',
        $saldoP > 0 && $erroP === t('O resgate passa do saldo de {valor}.', ['valor' => money($saldoP)])
        && Investimentos::erroResgate($resg, max(0.01, $saldoP), $hoje, $idR) === null && Investimentos::erroResgate($resg, 1.0, $hoje) !== null
        && Investimentos::erroResgate((int) CategoriaModel::porChave(MotorFinanceiro::CAT_SALARIO)['id'], 99999.0, $hoje) === null, (string) $erroP);
});

// C-06, efeito cascata: mudar ou excluir um guardar (ou incluir um resgate antes) não deixa um resgate de depois maior que o saldo
$emTransacao('C-06 efeito cascata', static function () use ($check, $hoje, $uid, $catDia): void {
    $guard = (int) CategoriaModel::porChave(MotorFinanceiro::CAT_GUARDAR)['id'];
    $resg = (int) CategoriaModel::porChave(MotorFinanceiro::CAT_RESGATE)['id'];
    $antes = date('Y-m-d', strtotime($hoje . ' -2 day'));
    $saldoP = (float) Investimentos::resumo($hoje)['poupanca']['saldo'];
    $idG = LancamentoModel::criar($antes, 'guardar', $guard, 300.0, $uid, ContaModel::PRINCIPAL);
    LancamentoModel::criar($hoje, 'resgate tudo', $resg, round($saldoP + 300.0, 2), $uid, ContaModel::PRINCIPAL);
    $frase = t('Assim, o resgate de {data} passa do saldo guardado. Mude esse resgate antes.', ['data' => diaMes($hoje)]);
    $validar = new \ReflectionMethod(LancamentosController::class, 'validar');
    [$erroEd] = $validar->invoke(null, ['valor' => '100', 'categoria_id' => (string) $guard, 'quando' => 'outro', 'data' => $antes], $idG);
    [$erroOk] = $validar->invoke(null, ['valor' => '400', 'categoria_id' => (string) $guard, 'quando' => 'outro', 'data' => $antes], $idG);
    $check('C-06 cascata: excluir o guardar, ou baixar o valor dele, é recusado com a frase do resgate', Investimentos::erroCascata([$idG]) === $frase
        && $erroEd === $frase && Investimentos::erroCascata([$idG], [['categoria_id' => $guard, 'valor' => 300.0, 'data' => date('Y-m-d', strtotime($hoje . ' +1 day'))]]) === $frase, (string) $erroEd);
    $check('C-06 cascata: um resgate novo antes do outro também; subir o guardar ou lançar outra coisa passa', $erroOk === null
        && Investimentos::erroCascata([], [['categoria_id' => $resg, 'valor' => 50.0, 'data' => $antes]]) === $frase
        && Investimentos::erroCascata([], [['categoria_id' => $catDia, 'valor' => 50.0, 'data' => $antes]]) === null, (string) $erroOk);
});

// C-07: no lançar, só as categorias que o servidor aceita numa compra no cartão vêm marcadas para o cartão
LancarForm::limparCache();
$okC07 = true;
$cartaoC07 = CartaoModel::padrao();
foreach (LancarForm::dados($hoje)['grupos'] as $itens) {
    foreach ($itens as $c) {
        $aceita = CartaoController::validarCompra(['valor' => '10', 'categoria_id' => (string) $c['id'], 'data' => $hoje, 'parcelas' => '1'], $cartaoC07, $hoje)[0] === null;
        $okC07 = $okC07 && $aceita === $c['cartao'];
    }
}
$htmlC07 = $render(LancamentosController::class, 'lancarForm', ['pagou' => 'cartao']);
$check('C-07 cartão: a lista e os atalhos do lançar marcam as categorias de compra no cartão com a mesma regra do servidor', $okC07
    && preg_match('#<option value="' . (int) CartaoModel::categoriaFatura() . '"(?![^>]*data-lf-cartao)[^>]*>#', $htmlC07) === 1
    && preg_match('#<option value="' . $catGasto . '"[^>]*data-lf-cartao#', $htmlC07) === 1 && str_contains($htmlC07, 'data-value="' . $catGasto . '" data-lf-cartao'));

// C-08: as parcelas pagas contam o mês de referência, e pagas + faltam = o contrato
$htmlC08 = $render(EmprestimoController::class, 'index', []);
preg_match('#(\d+) de (\d+) pagas#', $htmlC08, $mPag);
preg_match('#Faltam (\d+) parcelas#', $htmlC08, $mFal);
$check('C-08 empréstimo: "3 de 72 pagas", com a parcela do mês de referência entre as pagas', isset($mPag[2], $mFal[1])
    && (int) $mPag[2] === (int) round(AjusteModel::valor('n_parcelas')) && (int) $mPag[1] + (int) $mFal[1] === (int) $mPag[2], json_encode([$mPag, $mFal]));

// C-09: Simular com um gasto enorme: na tabela, de 100.000 para cima sem centavos; o rótulo "Dívida" longe do eixo
$htmlC09 = $render(FuturoController::class, 'index', ['se' => '1', 'uma' => '1000000', 'uma_tipo' => 'gasto']);
preg_match_all('#<span class="sim-v[^"]*">(?:<span class="sr-only">[^<]*</span>)?(?:<svg.*?</svg>)?([^<]*)#s', $htmlC09, $mCel);
$largos = array_filter($mCel[1], static fn(string $v): bool => preg_match('/\d{3}\.\d{3}/', $v) === 1);
$comCentavos = array_filter($largos, static fn(string $v): bool => str_contains($v, ','));
preg_match('#class="sim-serie" style="left:([\d.]+)%#', $htmlC09, $mSerie);
$check('C-09 simular: valores enormes sem centavos na tabela e o rótulo "Dívida" fora da faixa do eixo', $largos !== [] && $comCentavos === []
    && (!str_contains($htmlC09, 'sim-serie') || (isset($mSerie[1]) && (float) $mSerie[1] >= 10.0)), json_encode([array_values($comCentavos), $mSerie[1] ?? null]));

// C-10: desfazer "comprei" e "excluir" devolve o item ao lugar dele
$emTransacao('C-10 wishlist: o lugar na fila', static function () use ($check, $uid): void {
    $ids = [];
    foreach (['um', 'dois', 'três', 'quatro'] as $nome) {
        $ids[] = WishlistModel::criar('C10 ' . $nome, null, 10.0, $uid);
    }
    WishlistModel::mover($ids[3], 'primeiro');
    $ordem = static fn(): array => array_values(array_filter(array_map(static fn(array $i): int => (int) $i['id'], WishlistModel::ativos()), static fn(int $i): bool => in_array($i, $ids, true)));
    $antes = $ordem();
    WishlistModel::marcarComprado($ids[1], '2026-11-22');
    WishlistModel::devolver($ids[1]);
    $depoisComprei = $ordem();
    WishlistModel::excluir($ids[2]);
    WishlistModel::restaurar($ids[2]);
    $check('C-10 wishlist: desfazer "comprei" e "excluir" devolve o item ao lugar dele', $depoisComprei === $antes && $ordem() === $antes, json_encode([$antes, $depoisComprei, $ordem()]));
});

// C-11: o total da Wishlist soma só o que tem valor e diz quantos ficaram de fora
$resumoW = WishlistController::resumo([['valor' => 100.0, 'moeda' => 'BRL', 'valor_site' => 100.0], ['valor' => 179.0, 'moeda' => 'USD', 'valor_site' => null],
    ['valor' => null, 'moeda' => 'BRL', 'valor_site' => null], ['valor' => 50.5, 'moeda' => 'BRL', 'valor_site' => 50.5]]);
$check('C-11 wishlist: "4 itens, ≈ R$ 150,50, 2 sem preço"', $resumoW === '4 itens, ≈' . NBSP . money(150.5) . ', 2 sem preço', $resumoW);

// C-12: OFX com lançamentos ilegíveis avisa quantos ficaram de fora
$ofxRuim = "OFXHEADER:100\n<OFX><BANKTRANLIST>"
    . "<STMTTRN><DTPOSTED>20261110<TRNAMT>-10.00<FITID>1<NAME>BOM</STMTTRN>"
    . "<STMTTRN><DTPOSTED>20261131<TRNAMT>-11.00<FITID>2<NAME>DATA RUIM</STMTTRN>"
    . "<STMTTRN><DTPOSTED>20261112<TRNAMT>abc<FITID>3<NAME>VALOR RUIM</STMTTRN></BANKTRANLIST></OFX>";
$lidoO = \Financeiro\Services\ExtratoArquivo::ler($ofxRuim);
$okO = \Financeiro\Controllers\ImportacaoController::lerArquivo($ofxRuim, 'ruim.ofx', ['tipo' => 'conta', 'id' => ContaModel::PRINCIPAL]) === null
    && ($_SESSION['importar']['ignoradas'] ?? null) === 2;
unset($_SESSION['importar']);
$check('C-12 importar: OFX com 2 lançamentos ilegíveis avisa as 2 linhas de fora', count($lidoO['linhas']) === 1 && $lidoO['ignoradas'] === 2 && $okO);

// C-13: "parece já lançada" só com a descrição parecida; com outra bem diferente, a linha vem marcada, com a nota
$emTransacao('C-13 importar: duplicado pela descrição', static function () use ($check, $uid, $catGasto, $render): void {
    LancamentoModel::criar('2026-11-22', 'POSTO CENTRAL', $catGasto, 1234.56, $uid, ContaModel::PRINCIPAL);
    $conta = ['tipo' => 'conta', 'id' => ContaModel::PRINCIPAL];
    $outra = \Financeiro\Services\Importacao::revisao([['data' => '2026-11-22', 'valor' => -1234.56, 'descricao' => 'Loja de móveis', 'fitid' => '', 'categoria' => '']], $conta, 'csv');
    $mesma = \Financeiro\Services\Importacao::revisao([['data' => '2026-11-22', 'valor' => -1234.56, 'descricao' => 'POSTO CENTRAL 0042', 'fitid' => '', 'categoria' => '']], $conta, 'csv');
    $check('C-13 importar: mesma data e valor com descrição bem diferente vem marcada, com "Mesmo valor e data de…"',
        $outra[0]['dup'] === null && $outra[0]['marcada'] && $outra[0]['mesmo'] === 'POSTO CENTRAL' && $mesma[0]['dup'] === 'parecida' && !$mesma[0]['marcada']
        && \Financeiro\Services\Importacao::descricaoParecida('RESTAURANTE SABOR - Almoço', 'almoço') && \Financeiro\Services\Importacao::descricaoParecida('', 'X')
        && \Financeiro\Services\Importacao::descricaoParecida('IFOOD 123', 'Alimentação', ['Alimentação', ''])
        && !\Financeiro\Services\Importacao::descricaoParecida('COMPRA LOJA DE MOVEIS', 'COMPRA POSTO CENTRAL'),
        json_encode([$outra[0]['dup'], $outra[0]['mesmo'], $mesma[0]['dup']]));
});

// C-14: o dia do cartão é inteiro de 1 a 31; os dados do empréstimo têm limites de contrato de verdade
$lerE = static fn(array $v): ?string => AjustesController::lerValores($v, AjusteModel::DO_EMPRESTIMO)['erro'];
$check('C-14 validação: "10.5" não vira dia 10; juros até 15% ao mês, de 1 a 480 parcelas e parcela positiva, com a frase de cada um',
    CartaoController::dia('10.5') === 0 && CartaoController::dia('1e1') === 0 && CartaoController::dia('ab') === 0 && CartaoController::dia('10') === 10 && CartaoController::dia(' 31 ') === 31
    && $lerE(['juros_mes' => '99']) === 'juros_mes' && $lerE(['juros_mes' => '15']) === null && $lerE(['juros_mes' => '1,99']) === null
    && $lerE(['n_parcelas' => '500']) === 'n_parcelas' && $lerE(['n_parcelas' => '480']) === null && $lerE(['n_parcelas' => '12.5']) === 'n_parcelas'
    && $lerE(['parcela_mensal' => '0']) === 'parcela_mensal' && $lerE(['recebido_emprestimo' => '-5']) === 'recebido_emprestimo'
    && str_contains(AjustesController::erroDoCampo('juros_mes'), '15%') && str_contains(AjustesController::erroDoCampo('n_parcelas'), '480'));

// C-15: o "sobrou" do anual é o mesmo da manchete; a fatura paga em partes mostra o dia do último pagamento
$sobraAno = $relAno !== null ? round($relAno['sobrou'] - max(0.0, $relAno['guardou']), 2) : 0.0;
$sobrouBom = array_values(array_filter($relAno['achados']['bom'] ?? [], static fn(string $t): bool => str_starts_with($t, 'Sobraram')));
$check('C-15 relatório anual: "Sobraram" diz o mesmo valor da manchete', $relAno !== null && $relAno['guardou'] > 0.004 && $sobraAno > 0.004
    && str_contains($relAno['manchete'], money($sobraAno)) && !str_contains(implode(' ', $relAno['achados']['bom']), money(round($relAno['sobrou'], 2)))
    && array_filter($sobrouBom, static fn(string $t): bool => !str_contains($t, money($sobraAno))) === [], json_encode([$relAno['manchete'] ?? null, $sobrouBom], JSON_UNESCAPED_UNICODE));
$emTransacao('C-15 Mês: o último pagamento da fatura', static function () use ($check, $hoje, $uid): void {
    $cartao = CartaoModel::padrao();
    $fat = (int) CartaoModel::categoriaFatura();
    $mesF = substr($hoje, 0, 8) . '01';
    LancamentoModel::criar($hoje, 'Fatura do cartão', $fat, 10.0, $uid, ContaModel::PRINCIPAL, ['cartao_id' => $cartao['id'], 'fatura' => $mesF]);
    MotorFinanceiro::limparCache();
    $linha = array_values(array_filter(ChecklistContas::statusPorMes($mesF, $hoje), static fn(array $c): bool => $c['categoria_id'] === $fat));
    $check('C-15 Mês: a fatura paga em partes mostra o dia do último pagamento', isset($linha[0]) && $linha[0]['data_pagamento'] === $hoje, json_encode($linha));
});

echo "\n{$ok} ok, {$falhas} falha(s)\n";
exit($falhas > 0 ? 1 : 0);
