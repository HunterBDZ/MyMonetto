<?php
namespace Financeiro\Controllers;

use Financeiro\Auth;
use Financeiro\Database;
use Financeiro\Models\AcertoModel;
use Financeiro\Models\ContaModel;
use Financeiro\Models\MesModel;
use Financeiro\Models\SaldoConferenciaModel;
use Financeiro\Relogio;
use Financeiro\Services\MotorFinanceiro;
use Financeiro\View;

/**
 * Acerto de saldo: quando o app e o banco não batem por uma diferença que não vale procurar, o acerto lança a
 * diferença de uma vez, e o saldo volta a bater. O caminho é o "Acertar saldo com o banco", no Extrato: pede o saldo
 * do banco numa data, mostra a conta e só lança depois de confirmar. Mês fechado não muda: o acerto de um dia de mês fechado
 * entra no 1º dia do primeiro mês aberto depois dele (dataPara).
 */
final class AcertoController
{
    /**
     * O acerto que faz a conferência bater: a menor entre a diferença, a diferença sem o agendado (o banco pode já ter
     * descontado o débito agendado) e a diferença com o que foi lançado depois da conferência, no mesmo dia (o banco
     * pode ainda não ter esse valor), como na regra do "bate". Null quando não há o que acertar.
     */
    public static function sugestao(float $dif, float $agendado = 0.0, float $posterior = 0.0): ?float
    {
        $v = $dif;
        foreach ([abs($agendado) >= 0.005 ? $dif - $agendado : null, abs($posterior) >= 0.005 ? $dif + $posterior : null] as $outra) {
            if ($outra !== null && abs($outra) < abs($v)) {
                $v = $outra;
            }
        }
        $v = round($v, 2);
        return abs($v) >= 0.005 ? $v : null;
    }

    /** O dia em que o acerto do saldo de $referente entra: o próprio dia ou, num mês fechado, o 1º dia do mês aberto seguinte. */
    public static function dataPara(string $referente): string
    {
        $d = $referente;
        for ($i = 0; $i < 600 && LancamentosController::erroMesFechado($d) !== null; $i++) {
            $d = date('Y-m-01', strtotime(substr($d, 0, 8) . '01 +1 month'));
        }
        return $d;
    }

    /** O nome do acerto na tela: a descrição digitada ou, sem ela, de onde ele veio. */
    public static function rotulo(array $a): string
    {
        $d = trim((string) ($a['descricao'] ?? ''));
        if ($d !== '') {
            return $d;
        }
        return (string) $a['data'] !== (string) $a['referente']
            ? t('Acerto do saldo de {mes}', ['mes' => mesExtenso(substr((string) $a['referente'], 0, 8) . '01')])
            : t('Acerto com o banco');
    }

    /**
     * Grava os acertos, cada um na sua conta, numa transação.
     * @param array<int, array{valor:float, data:string}> $acertos conta_id => o valor e o dia do saldo do banco
     * @return list<array{conta:int, valor:float, data:string, referente:string, id:int}>
     */
    private static function gravar(array $acertos, string $descricao = ''): array
    {
        $pdo = Database::connection();
        $feitos = [];
        $pdo->beginTransaction();
        try {
            foreach ($acertos as $conta => $a) {
                $data = self::dataPara($a['data']);
                MesModel::ensureExists(substr($data, 0, 8) . '01');
                $id = AcertoModel::criar($data, $a['data'], (int) $conta, (float) $a['valor'], $descricao, (int) Auth::id());
                $feitos[] = ['conta' => (int) $conta, 'valor' => (float) $a['valor'], 'data' => $data, 'referente' => $a['data'], 'id' => $id];
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        MotorFinanceiro::limparCache();
        return $feitos;
    }

    /** "Acerto de −0,37 em 1º de nov" (ou, com várias, "2 acertos: −0,37 e +1,20") */
    private static function aviso(array $feitos): string
    {
        if (count($feitos) === 1) {
            return t('Saldo acertado: {valor} em {data}', ['valor' => moneySigned($feitos[0]['valor']), 'data' => diaMes($feitos[0]['data'])]);
        }
        return t('Saldo acertado em {n} contas: {valores}', ['n' => count($feitos), 'valores' => implode(', ', array_map(static fn(array $f): string => moneySigned($f['valor']), $feitos))]);
    }

    /** GET /acertos/novo — o saldo do banco numa data, de cada conta; o app mostra a conta antes de lançar. */
    public function novo(): void
    {
        Auth::requireLogin();
        $erro = $_SESSION['acerto_erro'] ?? null;
        unset($_SESSION['acerto_erro']);
        $volta = voltaSegura(is_string($_GET['volta'] ?? null) ? $_GET['volta'] : null, '/lancamentos');
        self::tela(is_array($erro) ? $erro : null, null, $volta);
    }

    /**
     * POST /acertos — sem confirmar=1, mostra a conta (o banco, o app e o acerto de cada conta) sem gravar nada; com
     * confirmar=1, grava a conferência e os acertos, com a conta feita de novo no servidor.
     */
    public function criar(): void
    {
        Auth::requireLogin();
        csrfCheck();
        $volta = voltaSegura(is_string($_POST['volta'] ?? null) ? $_POST['volta'] : null, '/lancamentos');
        $hoje = Relogio::hoje();
        [$saldos, $brutos, $erro] = DashboardController::lerSaldosContas(is_array($_POST['saldo_conta'] ?? null) ? $_POST['saldo_conta'] : []);
        $dataPost = is_string($_POST['data'] ?? null) ? trim($_POST['data']) : '';
        $quando = is_string($_POST['quando'] ?? null) ? $_POST['quando'] : 'hoje';
        $data = $quando === 'outro' ? $dataPost : $hoje;
        $descricao = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', is_string($_POST['descricao'] ?? null) ? $_POST['descricao'] : ''));
        if ($erro === null && $saldos === []) {
            $erro = ContaModel::varias() ? t('Digite o saldo de pelo menos uma conta.') : t('Digite o saldo como aparece no banco, por exemplo 1.200,50.');
        } elseif ($erro === null && (!in_array($quando, ['hoje', 'outro'], true) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $data)
            || date('Y-m-d', (int) strtotime($data)) !== $data || $data > $hoje || $data < self::dataMinima($hoje))) {
            $erro = t('Escolha uma data válida.');
        } elseif ($erro === null && mb_strlen($descricao) > 255) {
            $erro = t('Descrição muito longa. Use até 255 letras.');
        }
        if ($erro !== null) {
            $_SESSION['acerto_erro'] = ['msg' => $erro, 'contas' => $brutos, 'quando' => $quando === 'outro' ? 'outro' : 'hoje', 'data' => $dataPost !== '' ? $dataPost : $hoje, 'descricao' => mb_substr($descricao, 0, 255)];
            redirecionar('/acertos/novo?volta=' . rawurlencode($volta), 303);
        }

        $previa = self::previa($saldos, $data);
        if (($_POST['confirmar'] ?? '') !== '1') {
            self::tela(['contas' => $brutos, 'quando' => $quando, 'data' => $data, 'descricao' => $descricao], $previa, $volta);
            return;
        }

        // a conferência fica registrada, como a do Início: com uma conta só, a do total
        $varias = ContaModel::varias();
        foreach ($saldos as $id => $v) {
            SaldoConferenciaModel::registrar($data, $v, (int) Auth::id(), $varias ? $id : null);
        }
        $acertos = [];
        foreach ($previa as $id => $p) {
            if ($p['acerto'] !== null) {
                $acertos[$id] = ['valor' => $p['acerto'], 'data' => $data];
            }
        }
        if ($acertos === []) {
            flash('success', t('O saldo bate com o banco em {data}. Não há nada para acertar.', ['data' => diaMes($data)]));
            redirecionar($volta, 303);
        }
        self::avisoComDesfazer(self::gravar($acertos, $descricao), $volta);
        redirecionar($volta, 303);
    }

    /**
     * A conta de cada conta digitada: o banco, o app no fim do dia (com o acerto de mês fechado que já conta para ele),
     * a diferença, o acerto e o dia em que ele entra.
     * @param array<int, float> $saldos
     * @return array<int, array{conta:array, banco:float, app:float, dif:float, acerto:?float, entra:string}>
     */
    public static function previa(array $saldos, string $data): array
    {
        $agora = date('Y-m-d H:i:s');
        $out = [];
        foreach ($saldos as $id => $banco) {
            $conta = ContaModel::achar((int) $id);
            if ($conta === null) {
                continue;
            }
            $app = round(ContaModel::saldo((int) $id, $data) + AcertoModel::pendentesNa($data, (int) $id), 2);
            $dif = round($banco - $app, 2);
            $agendado = SaldoConferenciaModel::agendadoNa(['data' => $data, 'criado_em' => $agora, 'conta_id' => (int) $id]);
            $out[(int) $id] = ['conta' => $conta, 'banco' => $banco, 'app' => $app, 'dif' => $dif, 'acerto' => self::sugestao($dif, $agendado), 'entra' => self::dataPara($data)];
        }
        return $out;
    }

    /** GET /acertos/{id} — o acerto, só para ler, com excluir enquanto o mês dele estiver aberto. */
    public function ver(string $id): void
    {
        Auth::requireLogin();
        $a = AcertoModel::find((int) $id);
        if ($a === null) {
            renderErro(404, t('Acerto não encontrado'), t('Pode ter sido excluído.'));
        }
        View::render('acertos/ver', [
            'pageTitle' => t('acerto de saldo · MyMonetto'), 'acerto' => $a, 'conta' => ContaModel::achar((int) $a['conta_id']),
            'varias' => ContaModel::varias(), 'fechado' => LancamentosController::erroMesFechado((string) $a['data']),
            'volta' => voltaSegura(is_string($_GET['volta'] ?? null) ? $_GET['volta'] : null, '/lancamentos?mes=' . substr((string) $a['data'], 0, 8) . '01'),
        ], 'extrato');
    }

    /** POST /acertos/{id}/excluir (também o "desfazer" do aviso de acerto feito) */
    public function excluir(string $id): void
    {
        Auth::requireLogin();
        csrfCheck();
        $volta = voltaSegura(is_string($_POST['volta'] ?? null) ? $_POST['volta'] : null, '/lancamentos');
        if (preg_match('#^/acertos/' . (int) $id . '(\?|$)#', $volta)) {
            $volta = '/lancamentos';
        }
        $ids = array_values(array_filter(array_map('intval', array_merge([(int) $id], is_array($_POST['junto'] ?? null) ? $_POST['junto'] : [])), static fn(int $i): bool => $i > 0));
        $linhas = array_values(array_filter(array_map(static fn(int $i): ?array => AcertoModel::find($i), array_unique($ids))));
        if ($linhas === []) {
            flash('warn', t('Esse acerto já tinha sido excluído.'));
            redirecionar($volta);
        }
        foreach ($linhas as $a) {
            if (($fechado = LancamentosController::erroMesFechado((string) $a['data'])) !== null) {
                flash('error', $fechado);
                redirecionar($volta);
            }
        }
        foreach ($linhas as $a) {
            AcertoModel::excluir((int) $a['id']);
        }
        MotorFinanceiro::limparCache();
        $token = bin2hex(random_bytes(12));
        $_SESSION['acerto_desfazer'] = ['token' => $token, 'linhas' => $linhas];
        flash('success', tn(count($linhas), 'Acerto excluído · {valor}', '{n} acertos excluídos', ['valor' => moneySigned((float) $linhas[0]['valor'])]), [
            'url' => '/acertos/desfazer', 'label' => t('desfazer'), 'campos' => ['volta' => $volta, 'token' => $token],
        ]);
        redirecionar($volta);
    }

    /** POST /acertos/desfazer: o acerto excluído volta, com o mesmo id. */
    public function desfazer(): void
    {
        Auth::requireLogin();
        csrfCheck();
        $volta = voltaSegura(is_string($_POST['volta'] ?? null) ? $_POST['volta'] : null, '/lancamentos');
        $d = $_SESSION['acerto_desfazer'] ?? null;
        $token = is_string($_POST['token'] ?? null) ? $_POST['token'] : '';
        if (!is_array($d) || !is_string($d['token'] ?? null) || !hash_equals($d['token'], $token) || !is_array($d['linhas'] ?? null)) {
            flash('warn', t('Não há nada para desfazer.'));
            redirecionar($volta);
        }
        unset($_SESSION['acerto_desfazer']);
        foreach ($d['linhas'] as $a) {
            if (($fechado = LancamentosController::erroMesFechado((string) $a['data'])) !== null) {
                flash('error', $fechado);
                redirecionar($volta);
            }
        }
        foreach ($d['linhas'] as $a) {
            if (AcertoModel::find((int) $a['id']) === null) {
                AcertoModel::restaurar($a);
            }
        }
        MotorFinanceiro::limparCache();
        flash('success', tn(count($d['linhas']), 'Acerto de volta · {valor}', '{n} acertos de volta', ['valor' => moneySigned((float) $d['linhas'][0]['valor'])]));
        redirecionar($volta);
    }

    /** O aviso de acerto feito, com o desfazer (que exclui o que acabou de ser lançado). */
    private static function avisoComDesfazer(array $feitos, string $volta): void
    {
        $ids = array_column($feitos, 'id');
        flash('success', self::aviso($feitos), [
            'url' => '/acertos/' . (int) $ids[0] . '/excluir', 'label' => t('desfazer'),
            'campos' => ['volta' => $volta] + array_combine(array_map(static fn(int $i): string => 'junto[' . $i . ']', array_keys($ids)), array_map('strval', $ids)),
        ]);
    }

    /** O limite da data do saldo do banco: o mesmo da conferência (60 dias, nunca antes da véspera do 1º mês). */
    private static function dataMinima(string $hoje): string
    {
        $minimo = date('Y-m-d', strtotime('-60 days', strtotime($hoje)));
        $primeiro = MesModel::primeiroDia();
        if ($primeiro !== null) {
            $minimo = max($minimo, date('Y-m-d', strtotime('-1 day', strtotime($primeiro))));
        }
        return $minimo;
    }

    /** A tela do Acertar saldo: o formulário ou, com $previa, a conta para confirmar. */
    private static function tela(?array $antes, ?array $previa, string $volta): void
    {
        $hoje = Relogio::hoje();
        $saldos = ContaModel::saldos($hoje);
        $contas = [];
        foreach (ContaModel::ativas() as $c) {
            $contas[] = $c + ['saldo' => round((float) ($saldos[$c['id']] ?? 0.0), 2)];
        }
        View::render('acertos/novo', [
            'pageTitle' => t('acertar saldo · MyMonetto'), 'contas' => $contas, 'varias' => ContaModel::varias(),
            'antes' => $antes, 'previa' => $previa, 'volta' => $volta, 'hoje' => $hoje, 'minimo' => self::dataMinima($hoje),
        ], 'extrato');
    }
}
