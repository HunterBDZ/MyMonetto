<?php
namespace Financeiro\Models;

use Financeiro\Database;

/**
 * Onde o dinheiro está: banco, banco digital, dinheiro vivo (tabela `contas`). O plano continua sobre as
 * categorias; a conta só diz de onde saiu ou onde entrou cada lançamento.
 *
 * Saldo de uma conta = saldo inicial + lançamentos com o sinal da categoria + transferências recebidas − enviadas.
 * A soma de todas é o saldo do motor, porque as transferências se anulam e ajustes.saldo_ini é mantido igual à soma
 * dos saldos iniciais (sincronizarSaldoIni). Entram as arquivadas também: elas só arquivam zeradas, e os lançamentos
 * delas continuam no motor, então o saldo inicial delas tem de continuar na conta.
 * Mês fechado não muda: a conta criada quando já há mês fechado tem o saldo inicial a partir do 1º dia do primeiro mês
 * aberto (saldo_inicial_em). Ele fica fora de ajustes.saldo_ini e entra nesse dia: no motor, no começo do mês
 * (aportes()); nos saldos por data, a partir dele (saldoInicialDatado()).
 */
final class ContaModel
{
    /** A conta que já existia antes da 2.0 (e a de toda instalação nova): lancamentos.conta_id tem 1 como padrão. */
    public const PRINCIPAL = 1;
    /** O nome dela no banco; na tela, no idioma de quem vê (nomeConta()). */
    public const NOME_PRINCIPAL = 'Conta principal';
    /** tipo => ícone */
    public const TIPOS = ['corrente' => '🏦', 'digital' => '📱', 'dinheiro' => '💵', 'outra' => '👛'];

    private static ?array $cache = null;

    public static function limparCache(): void
    {
        self::$cache = null;
    }

    /** @return list<array> todas as contas, as ativas primeiro, na ordem da tela */
    public static function todas(): array
    {
        return self::$cache ??= array_map([self::class, 'conta'],
            Database::connection()->query('SELECT * FROM contas ORDER BY ativa DESC, ordem, id')->fetchAll());
    }

    /** @return list<array> as contas ativas, na ordem da tela; a primeira é a padrão */
    public static function ativas(): array
    {
        return array_values(array_filter(self::todas(), static fn(array $c): bool => $c['ativa']));
    }

    /** Mais de uma conta ativa: só então as telas mostram a escolha da conta. Com uma, nada muda. */
    public static function varias(): bool
    {
        return count(self::ativas()) > 1;
    }

    public static function achar(int $id): ?array
    {
        foreach (self::todas() as $c) {
            if ($c['id'] === $id) {
                return $c;
            }
        }
        return null;
    }

    /** A conta usada quando nenhuma é escolhida: a primeira ativa. */
    public static function padrao(): int
    {
        return self::ativas()[0]['id'] ?? self::PRINCIPAL;
    }

    /** Cria (id null) ou atualiza; devolve o id. O saldo inicial do motor acompanha. */
    public static function salvar(?int $id, string $nome, string $tipo, float $saldoInicial): int
    {
        $pdo = Database::connection();
        $tipo = isset(self::TIPOS[$tipo]) ? $tipo : 'outra';
        $dados = ['n' => $nome, 't' => $tipo, 's' => round($saldoInicial, 2)];
        if ($id === null) {
            $pdo->prepare('INSERT INTO contas (nome, tipo, saldo_inicial, ordem, saldo_inicial_em) VALUES (:n, :t, :s, (SELECT COALESCE(MAX(ordem), 0) + 1 FROM contas), :em)')
                ->execute($dados + ['em' => self::primeiroDiaAberto()]);
            $id = (int) $pdo->lastInsertId();
        } else {
            $pdo->prepare('UPDATE contas SET nome = :n, tipo = :t, saldo_inicial = :s WHERE id = :id')->execute($dados + ['id' => $id]);
        }
        self::limparCache();
        self::sincronizarSaldoIni();
        return $id;
    }

    /**
     * Com algum mês fechado, o 1º dia do mês depois do último fechado: o saldo inicial de uma conta nova conta dali,
     * para os meses fechados não mudarem. Sem mês fechado, null (desde o início do plano).
     */
    public static function primeiroDiaAberto(): ?string
    {
        $ult = Database::connection()->query("SELECT MAX(mes) FROM meses WHERE situacao = 'REAL'")->fetchColumn();
        return $ult ? date('Y-m-01', strtotime((string) $ult . ' +1 month')) : null;
    }

    /** Os saldos iniciais com data (saldo_inicial_em), somados por mês: o motor os põe no começo de cada mês. @return array<string, float> */
    public static function aportes(): array
    {
        $out = [];
        foreach (Database::connection()->query('SELECT saldo_inicial_em AS d, saldo_inicial AS v FROM contas WHERE saldo_inicial_em IS NOT NULL') as $r) {
            $mes = substr((string) $r['d'], 0, 8) . '01';
            $out[$mes] = round(($out[$mes] ?? 0.0) + (float) $r['v'], 2);
        }
        return $out;
    }

    /** A soma dos saldos iniciais com data que já valem no fim do dia $ate (os sem data estão em ajustes.saldo_ini). */
    public static function saldoInicialDatado(string $ate): float
    {
        $st = Database::connection()->prepare('SELECT COALESCE(SUM(saldo_inicial), 0) FROM contas WHERE saldo_inicial_em IS NOT NULL AND saldo_inicial_em <= :ate');
        $st->execute(['ate' => $ate]);
        return round((float) $st->fetchColumn(), 2);
    }

    /** O saldo inicial de uma conta, sem mexer no resto (o ponto de partida em Ajustes, com uma conta só). */
    public static function setSaldoInicial(int $id, float $valor): void
    {
        Database::connection()->prepare('UPDATE contas SET saldo_inicial = :s WHERE id = :id')->execute(['s' => round($valor, 2), 'id' => $id]);
        self::limparCache();
        self::sincronizarSaldoIni();
    }

    /**
     * "Saldo da conta no início" digitado no ponto de partida (Ajustes, primeira configuração): com uma conta só,
     * é o saldo inicial dela. Com mais de uma, cada conta tem o seu em Ajustes → Contas, e o valor é ignorado.
     * @return bool se gravou
     */
    public static function saldoInicialDaUnica(float $valor): bool
    {
        $todas = self::todas();
        if (count($todas) !== 1) {
            return false;
        }
        self::setSaldoInicial($todas[0]['id'], $valor);
        return true;
    }

    /** Arquivar (ativa 0) ou trazer de volta. Quem chama confere que ela está zerada e que sobra outra ativa. */
    public static function setAtiva(int $id, bool $ativa): void
    {
        Database::connection()->prepare('UPDATE contas SET ativa = :a WHERE id = :id')->execute(['a' => $ativa ? 1 : 0, 'id' => $id]);
        self::limparCache();
        self::sincronizarSaldoIni();
    }

    /** Sobe (-1) ou desce (+1) a conta na lista das ativas, trocando de lugar com a vizinha. */
    public static function mover(int $id, int $direcao): void
    {
        $lista = array_column(self::ativas(), 'id');
        $i = array_search($id, $lista, true);
        $j = $i === false ? false : $i + ($direcao < 0 ? -1 : 1);
        if ($i === false || !isset($lista[$j])) {
            return;
        }
        [$lista[$i], $lista[$j]] = [$lista[$j], $lista[$i]];
        $st = Database::connection()->prepare('UPDATE contas SET ordem = :o WHERE id = :id');
        foreach ($lista as $pos => $c) {
            $st->execute(['o' => $pos, 'id' => $c]);
        }
        self::limparCache();
    }

    /** Já existe outra conta com esse nome (sem acento e sem caixa)? */
    public static function nomeUsado(string $nome, ?int $fora = null): bool
    {
        $st = Database::connection()->prepare('SELECT 1 FROM contas WHERE nome = :n AND id <> :id');
        $st->execute(['n' => $nome, 'id' => $fora ?? 0]);
        return $st->fetchColumn() !== false;
    }

    /** ajustes.saldo_ini = a soma dos saldos iniciais sem data. Os com data entram no motor como aportes(). */
    public static function sincronizarSaldoIni(): void
    {
        $soma = (float) Database::connection()->query('SELECT COALESCE(SUM(saldo_inicial), 0) FROM contas WHERE saldo_inicial_em IS NULL')->fetchColumn();
        AjusteModel::setValor('saldo_ini', round($soma, 2));
    }

    /**
     * O saldo de cada conta no fim do dia $ate (inclusive): saldo inicial + lançamentos + transferências + acertos.
     * @return array<int, float> conta_id => saldo
     */
    public static function saldos(string $ate): array
    {
        $pdo = Database::connection();
        $out = [];
        foreach (self::todas() as $c) {
            $out[$c['id']] = $c['saldo_desde'] === null || $c['saldo_desde'] <= $ate ? $c['saldo_inicial'] : 0.0;
        }
        $st = $pdo->prepare(
            'SELECT l.conta_id, COALESCE(SUM(l.valor * c.sinal), 0) AS total
             FROM lancamentos l JOIN categorias c ON c.id = l.categoria_id
             WHERE l.data <= :ate GROUP BY l.conta_id'
        );
        $st->execute(['ate' => $ate]);
        foreach ($st->fetchAll() as $r) {
            $out[(int) $r['conta_id']] = ($out[(int) $r['conta_id']] ?? 0.0) + (float) $r['total'];
        }
        $st = $pdo->prepare(
            'SELECT conta, COALESCE(SUM(v), 0) AS total FROM (
                 SELECT para_conta_id AS conta, valor AS v FROM transferencias WHERE data <= :a1
                 UNION ALL
                 SELECT de_conta_id AS conta, -valor AS v FROM transferencias WHERE data <= :a2
             ) t GROUP BY conta'
        );
        $st->execute(['a1' => $ate, 'a2' => $ate]);
        foreach ($st->fetchAll() as $r) {
            $out[(int) $r['conta']] = ($out[(int) $r['conta']] ?? 0.0) + (float) $r['total'];
        }
        foreach (AcertoModel::somaPorConta($ate) as $conta => $v) {
            $out[$conta] = ($out[$conta] ?? 0.0) + $v;
        }
        return array_map(static fn(float $v): float => round($v, 2), $out);
    }

    /** O saldo de uma conta no fim do dia $ate (o saldo inicial com data só a partir dela). */
    public static function saldo(int $id, string $ate): float
    {
        return self::saldos($ate)[$id] ?? 0.0;
    }

    /** A conta tem algum lançamento, transferência, cartão, conferência ou acerto? (sem nada, pode ser apagada de vez) */
    public static function temUso(int $id): bool
    {
        $pdo = Database::connection();
        foreach (['SELECT 1 FROM lancamentos WHERE conta_id = :id', 'SELECT 1 FROM transferencias WHERE de_conta_id = :id OR para_conta_id = :id',
                  'SELECT 1 FROM cartoes WHERE conta_id = :id', 'SELECT 1 FROM saldo_conferencias WHERE conta_id = :id',
                  'SELECT 1 FROM acertos_saldo WHERE conta_id = :id'] as $sql) {
            $st = $pdo->prepare($sql . ' LIMIT 1');
            $st->execute(['id' => $id]);
            if ($st->fetchColumn() !== false) {
                return true;
            }
        }
        return false;
    }

    private static function conta(array $r): array
    {
        return [
            'id' => (int) $r['id'], 'nome' => (string) $r['nome'], 'tipo' => (string) $r['tipo'],
            'saldo_inicial' => (float) $r['saldo_inicial'], 'ativa' => (int) $r['ativa'] === 1, 'ordem' => (int) $r['ordem'],
            'saldo_desde' => $r['saldo_inicial_em'] !== null ? (string) $r['saldo_inicial_em'] : null,
        ];
    }
}
