<?php
namespace Financeiro\Models;

use Financeiro\Database;

/**
 * Acertos de saldo (tabela `acertos_saldo`): a diferença entre o banco e o app, lançada de uma vez. Fica fora das
 * categorias: muda o saldo da conta a partir de `data`, e o motor a soma no mês dela, mas não é renda nem gasto (não
 * entra nas Metas, no "Para onde foi", no Entrou nem no Gastou). `referente` é o dia do saldo do banco que ele acerta:
 * quando esse dia é de um mês fechado, o acerto entra no 1º dia do primeiro mês aberto depois dele, e a conferência
 * daquele dia passa a contar com ele (pendentesNa).
 */
final class AcertoModel
{
    public static function criar(string $data, string $referente, int $contaId, float $valor, string $descricao, int $usuarioId): int
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO acertos_saldo (data, referente, conta_id, valor, descricao, usuario_id) VALUES (:d, :r, :c, :v, :ds, :u)')
            ->execute(['d' => $data, 'r' => $referente, 'c' => $contaId, 'v' => round($valor, 2), 'ds' => $descricao, 'u' => $usuarioId]);
        return (int) $pdo->lastInsertId();
    }

    public static function find(int $id): ?array
    {
        $st = Database::connection()->prepare(
            'SELECT a.*, u.nome AS usuario_nome FROM acertos_saldo a JOIN usuarios u ON u.id = a.usuario_id WHERE a.id = :id'
        );
        $st->execute(['id' => $id]);
        $r = $st->fetch();
        return $r ?: null;
    }

    public static function excluir(int $id): void
    {
        Database::connection()->prepare('DELETE FROM acertos_saldo WHERE id = :id')->execute(['id' => $id]);
    }

    /** Desfazer a exclusão: a mesma linha, com o mesmo id. */
    public static function restaurar(array $a): void
    {
        Database::connection()->prepare(
            'INSERT INTO acertos_saldo (id, data, referente, conta_id, valor, descricao, usuario_id, criado_em)
             VALUES (:id, :d, :r, :c, :v, :ds, :u, :cr)'
        )->execute(['id' => (int) $a['id'], 'd' => $a['data'], 'r' => $a['referente'], 'c' => (int) $a['conta_id'], 'v' => (float) $a['valor'],
                    'ds' => (string) $a['descricao'], 'u' => (int) $a['usuario_id'], 'cr' => $a['criado_em']]);
    }

    /**
     * Os acertos de um período (datas inclusivas; null = sem limite), dos mais novos para os mais antigos.
     * $conta: só os dela. $q: a descrição contém.
     * @return list<array>
     */
    public static function listar(?string $de, ?string $ate, ?int $conta = null, string $q = ''): array
    {
        $w = ['1 = 1'];
        $p = [];
        if ($de !== null) {
            $w[] = 'a.data >= :de';
            $p['de'] = $de;
        }
        if ($ate !== null) {
            $w[] = 'a.data <= :ate';
            $p['ate'] = $ate;
        }
        if ($conta !== null) {
            $w[] = 'a.conta_id = :c';
            $p['c'] = $conta;
        }
        if ($q !== '') {
            $w[] = "a.descricao LIKE :q ESCAPE '!'";
            $p['q'] = '%' . strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        }
        $st = Database::connection()->prepare(
            'SELECT a.*, u.nome AS usuario_nome FROM acertos_saldo a JOIN usuarios u ON u.id = a.usuario_id
             WHERE ' . implode(' AND ', $w) . ' ORDER BY a.data DESC, a.id DESC'
        );
        $st->execute($p);
        return $st->fetchAll();
    }

    /** A soma dos acertos de cada mês (o mês de `data`), para o motor. @return array<string, float> Y-m-01 => soma */
    public static function porMes(): array
    {
        $out = [];
        foreach (Database::connection()->query('SELECT data, valor FROM acertos_saldo') as $r) {
            $mes = substr((string) $r['data'], 0, 8) . '01';
            $out[$mes] = round(($out[$mes] ?? 0.0) + (float) $r['valor'], 2);
        }
        return $out;
    }

    /** A soma dos acertos que já valem no fim do dia $ate, de todas as contas ou de uma. */
    public static function somaAte(string $ate, ?int $conta = null): float
    {
        $st = Database::connection()->prepare('SELECT COALESCE(SUM(valor), 0) FROM acertos_saldo WHERE data <= :ate' . ($conta !== null ? ' AND conta_id = :c' : ''));
        $st->execute(['ate' => $ate] + ($conta !== null ? ['c' => $conta] : []));
        return round((float) $st->fetchColumn(), 2);
    }

    /** A soma dos acertos de cada conta no fim do dia $ate. @return array<int, float> */
    public static function somaPorConta(string $ate): array
    {
        $st = Database::connection()->prepare('SELECT conta_id, COALESCE(SUM(valor), 0) AS total FROM acertos_saldo WHERE data <= :ate GROUP BY conta_id');
        $st->execute(['ate' => $ate]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[(int) $r['conta_id']] = round((float) $r['total'], 2);
        }
        return $out;
    }

    /**
     * Os acertos que acertam o saldo do dia $dia mas só entram no saldo depois dele (referente ≤ $dia < data): o do mês
     * fechado, que foi para o 1º dia do mês aberto. A conferência de $dia conta com eles, para não pedir o mesmo acerto
     * de novo. De todas as contas ou de uma.
     */
    public static function pendentesNa(string $dia, ?int $conta = null): float
    {
        $st = Database::connection()->prepare(
            'SELECT COALESCE(SUM(valor), 0) FROM acertos_saldo WHERE referente <= :d1 AND data > :d2' . ($conta !== null ? ' AND conta_id = :c' : '')
        );
        $st->execute(['d1' => $dia, 'd2' => $dia] + ($conta !== null ? ['c' => $conta] : []));
        return round((float) $st->fetchColumn(), 2);
    }
}
