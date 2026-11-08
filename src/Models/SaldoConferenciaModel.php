<?php
namespace Financeiro\Models;

use Financeiro\Database;

/**
 * Conferências do saldo com o banco. conta_id NULL = o total, como as de antes da 2.0 e as de quem tem uma conta só;
 * com várias contas, cada conta é conferida com o extrato dela.
 */
final class SaldoConferenciaModel
{
    /** A conferência do total mais recente (maior data; empate = a última registrada) entre $desde e $ate, ou null. */
    public static function ultima(?string $ate = null, ?string $desde = null): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT sc.id, sc.data, sc.saldo_informado, sc.criado_em, sc.conta_id, u.nome AS usuario_nome
             FROM saldo_conferencias sc
             JOIN usuarios u ON u.id = sc.usuario_id
             WHERE sc.conta_id IS NULL AND sc.data <= :ate AND sc.data >= :desde
             ORDER BY sc.data DESC, sc.id DESC
             LIMIT 1'
        );
        $stmt->execute(['ate' => $ate ?? '9999-12-31', 'desde' => $desde ?? '0000-01-01']);
        return $stmt->fetch() ?: null;
    }

    /**
     * A conferência mais recente de cada conta, entre $desde e $ate. Com $semConta, a do total (conta_id NULL, feita
     * quando havia uma conta só) vale como a dessa conta: assim a conferência de antes continua à vista depois de
     * criar a segunda conta (DashboardController::conferenciaAtual).
     * @return array<int, array> conta_id => conferência
     */
    public static function ultimasPorConta(?string $ate = null, ?string $desde = null, ?int $semConta = null): array
    {
        $out = [];
        $st = Database::connection()->prepare(
            'SELECT sc.id, sc.data, sc.saldo_informado, sc.criado_em, sc.conta_id
             FROM saldo_conferencias sc WHERE (sc.conta_id IS NOT NULL OR :com = 1) AND sc.data <= :ate AND sc.data >= :desde
             ORDER BY sc.data DESC, sc.id DESC'
        );
        $st->execute(['com' => $semConta !== null ? 1 : 0, 'ate' => $ate ?? '9999-12-31', 'desde' => $desde ?? '0000-01-01']);
        foreach ($st->fetchAll() as $r) {
            $conta = $r['conta_id'] !== null ? (int) $r['conta_id'] : (int) $semConta;
            $out[$conta] ??= ['conta_id' => $conta] + $r;
        }
        return $out;
    }

    /** Uma conferência por dia (e por conta): registrar de novo no mesmo dia substitui a anterior. */
    public static function registrar(string $data, float $saldoInformado, int $usuarioId, ?int $contaId = null): void
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM saldo_conferencias WHERE data = :data AND conta_id IS :conta')->execute(['data' => $data, 'conta' => $contaId]);
        $stmt = $pdo->prepare(
            'INSERT INTO saldo_conferencias (data, saldo_informado, usuario_id, conta_id)
             VALUES (:data, :saldo, :usuario_id, :conta)'
        );
        $stmt->execute(['data' => $data, 'saldo' => $saldoInformado, 'usuario_id' => $usuarioId, 'conta' => $contaId]);
    }

    /**
     * "Agendado" da conferência (D10, invariante 16): Σ sinal·valor dos lançamentos com data
     * DEPOIS da conferência que JÁ estavam registrados quando ela foi feita — o débito
     * agendado que o banco pode (ou não) já ter descontado do saldo mostrado. Numa conferência de
     * uma conta, só os lançamentos dela.
     */
    public static function agendadoNa(array $conferencia): float
    {
        $data = (string) ($conferencia['data'] ?? '');
        $criado = (string) ($conferencia['criado_em'] ?? '');
        // Saldo de uma data já passada quando foi informado (ex.: Fechar mês em 05/11 com o "saldo
        // no banco em 31/10", ou "outro dia" na conferência): o extrato daquele dia não tem débito
        // agendado nenhum, e o que foi lançado depois dele já aconteceu — não é agendado.
        if ($data === '' || $criado === '' || $data < substr($criado, 0, 10)) {
            return 0.0;
        }
        $conta = isset($conferencia['conta_id']) ? (int) $conferencia['conta_id'] : null;
        $stmt = Database::connection()->prepare(
            'SELECT COALESCE(SUM(l.valor * c.sinal), 0)
             FROM lancamentos l
             JOIN categorias c ON c.id = l.categoria_id
             WHERE l.data > :data AND l.criado_em <= :criado' . ($conta !== null ? ' AND l.conta_id = :conta' : '')
        );
        $stmt->execute(['data' => $data, 'criado' => $criado] + ($conta !== null ? ['conta' => $conta] : []));
        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * "Depois, no mesmo dia": Σ sinal·valor dos lançamentos com a data DA conferência registrados DEPOIS dela (e, numa
     * conferência de uma conta, as transferências dela). O banco pode não ter tido esses valores na hora da consulta: a
     * compra das 19:21 lançada depois de conferir às 19:03 fazia a conferência daquele dia "não bater" por 25,00, e o
     * acerto sugerido desfaria a compra. Só vale para a conferência do próprio dia; a de um dia passado é o saldo do fim
     * daquele dia, que já tem tudo (como em agendadoNa).
     */
    public static function posterioresNoDia(array $conferencia): float
    {
        $data = (string) ($conferencia['data'] ?? '');
        $criado = (string) ($conferencia['criado_em'] ?? '');
        if ($data === '' || $criado === '' || $data !== substr($criado, 0, 10)) {
            return 0.0;
        }
        $conta = isset($conferencia['conta_id']) ? (int) $conferencia['conta_id'] : null;
        $pdo = Database::connection();
        $st = $pdo->prepare(
            'SELECT COALESCE(SUM(l.valor * c.sinal), 0)
             FROM lancamentos l
             JOIN categorias c ON c.id = l.categoria_id
             WHERE l.data = :data AND l.criado_em > :criado' . ($conta !== null ? ' AND l.conta_id = :conta' : '')
        );
        $st->execute(['data' => $data, 'criado' => $criado] + ($conta !== null ? ['conta' => $conta] : []));
        $soma = (float) $st->fetchColumn();
        if ($conta !== null) {
            $st = $pdo->prepare(
                'SELECT COALESCE(SUM(CASE WHEN para_conta_id = :c1 THEN valor ELSE -valor END), 0) FROM transferencias
                 WHERE data = :data AND criado_em > :criado AND (para_conta_id = :c2 OR de_conta_id = :c3)'
            );
            $st->execute(['c1' => $conta, 'c2' => $conta, 'c3' => $conta, 'data' => $data, 'criado' => $criado]);
            $soma += (float) $st->fetchColumn();
        }
        return round($soma, 2);
    }
}
