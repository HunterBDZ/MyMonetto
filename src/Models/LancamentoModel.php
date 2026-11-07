<?php
namespace Financeiro\Models;

use Financeiro\Database;
use Financeiro\Relogio;
use Financeiro\Services\CartaoFatura;
use Financeiro\Sql;
use PDO;
use PDOException;

/**
 * Lançamentos. Assinaturas CONGELADAS (outros pacotes dependem): somaPorCategoriaNome,
 * primeiraDataPorCategoriaNome, saldoCalculado, somaOperacional.
 */
final class LancamentoModel
{
    /** Primeiro dia do mês seguinte a $mes (Y-m-01). */
    private static function fimDoMes(string $mes): string
    {
        return date('Y-m-01', strtotime('+1 month', strtotime($mes)));
    }

    /**
     * Agendado: o lançamento com data depois de hoje ainda não aconteceu. Este é o critério SQL do realizado, um só para
     * toda soma do que já entrou ou saiu: a data até hoje. A previsão continua contando o agendado (MotorFinanceiro::dados);
     * o realizado, não (MotorFinanceiro::realizado), até o dia dele. Ex.: realizado('l.data') → "l.data <= '2026-11-22'".
     * @param ?string $hoje Y-m-d (padrão: hoje)
     */
    public static function realizado(string $coluna, ?string $hoje = null): string
    {
        $hoje ??= Relogio::hoje();
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $hoje)) {
            throw new \InvalidArgumentException('Data inválida: ' . $hoje);
        }
        return $coluna . " <= '" . $hoje . "'";
    }

    /**
     * WHERE comum do extrato. $f: grupo (string[]), usuario_id (int), categoria_id (int), q (string),
     * periodo (array{de:?string, ate:?string}, AAAA-MM-DD inclusivos, null = sem limite; sem ele, vale o mês),
     * min e max (float: o valor, sem sinal). As datas usam idx_data e idx_categoria_data.
     * @return array{0:string,1:array<string,mixed>}
     */
    private static function filtro(string $mes, array $f): array
    {
        $w = [];
        $p = [];
        if (isset($f['periodo']) && is_array($f['periodo'])) {
            if (($f['periodo']['de'] ?? null) !== null) {
                $w[] = 'l.data >= :inicio';
                $p['inicio'] = (string) $f['periodo']['de'];
            }
            if (($f['periodo']['ate'] ?? null) !== null) {
                $w[] = 'l.data <= :fim';
                $p['fim'] = (string) $f['periodo']['ate'];
            }
        } else {
            $w = ['l.data >= :inicio', 'l.data < :fim'];
            $p = ['inicio' => $mes, 'fim' => self::fimDoMes($mes)];
        }
        if (isset($f['min'])) {
            $w[] = 'l.valor >= :vmin';
            $p['vmin'] = round((float) $f['min'], 2);
        }
        if (isset($f['max'])) {
            $w[] = 'l.valor <= :vmax';
            $p['vmax'] = round((float) $f['max'], 2);
        }

        $grupos = array_values(array_filter((array) ($f['grupo'] ?? []), 'is_string'));
        if ($grupos !== []) {
            $ph = [];
            foreach ($grupos as $i => $g) {
                $ph[] = ':g' . $i;
                $p['g' . $i] = $g;
            }
            $w[] = 'c.grupo IN (' . implode(', ', $ph) . ')';
        }
        // classe: entradas / essenciais / não essenciais / movimentações (⇄)
        $classe = (string) ($f['classe'] ?? '');
        if ($classe === 'entradas') {
            $w[] = 'c.operacional = 1 AND c.sinal = 1';
        } elseif ($classe === 'essenciais') {
            $w[] = 'c.operacional = 1 AND c.sinal = -1 AND c.essencial = 1';
        } elseif ($classe === 'naoess') {
            $w[] = 'c.operacional = 1 AND c.sinal = -1 AND c.essencial = 0';
        } elseif ($classe === 'mov') {
            $w[] = 'c.operacional = 0';
        }
        if (!empty($f['usuario_id'])) {
            $w[] = 'l.usuario_id = :uid';
            $p['uid'] = (int) $f['usuario_id'];
        }
        if (!empty($f['categoria_id'])) {
            $w[] = 'l.categoria_id = :cat';
            $p['cat'] = (int) $f['categoria_id'];
        }
        if (!empty($f['conta_id'])) {
            $w[] = 'l.conta_id = :conta';
            $p['conta'] = (int) $f['conta_id'];
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            // '!' como escape explícito: funciona igual com ou sem NO_BACKSLASH_ESCAPES
            $like = '%' . strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            // fora do português, a categoria também é achada pelo nome traduzido
            $traduzidas = [];
            foreach (categoriasPorTraducao($q) as $i => $nome) {
                $traduzidas[] = ':qc' . $i;
                $p['qc' . $i] = $nome;
            }
            $ouTraduzida = $traduzidas !== [] ? ' OR c.nome IN (' . implode(', ', $traduzidas) . ') OR c.apelido IN (' . implode(', ', array_map(static fn(string $k): string => $k . 'a', $traduzidas)) . ')' : '';
            foreach ($traduzidas as $k) {
                $p[substr($k, 1) . 'a'] = $p[substr($k, 1)];
            }
            $w[] = "(l.descricao LIKE :q1 ESCAPE '!' OR c.nome LIKE :q2 ESCAPE '!' OR c.apelido LIKE :q3 ESCAPE '!'" . $ouTraduzida . ')';
            $p['q1'] = $p['q2'] = $p['q3'] = $like;
        }
        return [$w === [] ? '1 = 1' : implode(' AND ', $w), $p];
    }

    /**
     * Extrato: lançamentos de um mês (Y-m-01) ou de um período ($f['periodo']), mais novos primeiro.
     * $f: os de filtro(), ordem e limite (int; 0 = todos).
     */
    public static function listar(string $mes, array $f): array
    {
        [$where, $params] = self::filtro($mes, $f);
        $orderBy = match ((string) ($f['ordem'] ?? '')) {
            'dia-asc'    => 'l.data ASC, l.id ASC',
            'valor-desc' => 'l.valor DESC, l.data DESC, l.id DESC',
            'valor-asc'  => 'l.valor ASC, l.data DESC, l.id DESC',
            'alfa'       => 'c.nome ASC, l.data DESC, l.id DESC',
            default      => 'l.data DESC, l.id DESC',
        };
        $sql = 'SELECT l.id, l.data, l.descricao, l.valor, l.categoria_id, l.usuario_id, l.criado_em, l.conta_id,
                       c.nome AS categoria_nome, c.apelido, c.grupo, c.sinal, c.operacional, c.essencial,
                       u.nome AS usuario_nome
                FROM lancamentos l
                JOIN categorias c ON c.id = l.categoria_id
                JOIN usuarios u ON u.id = l.usuario_id
                WHERE ' . $where . '
                ORDER BY ' . $orderBy;
        $limite = (int) ($f['limite'] ?? 0);
        if ($limite > 0) {
            $sql .= ' LIMIT ' . $limite;
        }
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Totais do extrato com os mesmos filtros de listar() (sobre TODAS as linhas, não só as exibidas), numa linha só
     * (em qualquer período): n, soma (Σ sinal·valor só das operacionais — invariante 2), n_op, mov (Σ valor de
     * crédito, amortização e poupança, mostrado só como ⇄), gasto (Σ gastos operacionais), entrada (Σ entradas operacionais).
     */
    public static function totais(string $mes, array $f): array
    {
        [$where, $params] = self::filtro($mes, $f);
        // o agendado (data depois de hoje) ainda não aconteceu: fica fora do que entrou e do gasto, e é somado à parte
        $feito = self::realizado('l.data');
        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*) AS n,
                    COALESCE(SUM(CASE WHEN c.operacional = 1 AND {$feito} THEN c.sinal * l.valor ELSE 0 END), 0) AS soma,
                    COALESCE(SUM(CASE WHEN c.operacional = 1 THEN 1 ELSE 0 END), 0) AS n_op,
                    COALESCE(SUM(CASE WHEN c.operacional = 1 THEN 0 ELSE l.valor END), 0) AS mov,
                    COALESCE(SUM(CASE WHEN c.operacional = 1 AND c.sinal = -1 AND {$feito} THEN l.valor ELSE 0 END), 0) AS gasto,
                    COALESCE(SUM(CASE WHEN c.operacional = 1 AND c.sinal = 1 AND {$feito} THEN l.valor ELSE 0 END), 0) AS entrada,
                    COALESCE(SUM(CASE WHEN c.operacional = 1 AND c.sinal = -1 AND NOT ({$feito}) THEN l.valor ELSE 0 END), 0) AS gasto_ag,
                    COALESCE(SUM(CASE WHEN c.operacional = 1 AND c.sinal = 1 AND NOT ({$feito}) THEN l.valor ELSE 0 END), 0) AS entrada_ag
             FROM lancamentos l
             JOIN categorias c ON c.id = l.categoria_id
             WHERE {$where}"
        );
        $stmt->execute($params);
        $r = $stmt->fetch() ?: [];
        return [
            'n' => (int) ($r['n'] ?? 0), 'n_op' => (int) ($r['n_op'] ?? 0), 'soma' => round((float) ($r['soma'] ?? 0), 2),
            'mov' => round((float) ($r['mov'] ?? 0), 2), 'gasto' => round((float) ($r['gasto'] ?? 0), 2), 'entrada' => round((float) ($r['entrada'] ?? 0), 2),
            'gasto_ag' => round((float) ($r['gasto_ag'] ?? 0), 2), 'entrada_ag' => round((float) ($r['entrada_ag'] ?? 0), 2),
        ];
    }

    /**
     * Para a planilha: os lançamentos dos mesmos filtros, do mais antigo ao mais novo, em lotes de $lote linhas
     * (paginação pela chave data + id, sem OFFSET): a memória fica do tamanho de um lote, mesmo com anos de dados.
     * @return \Generator<int, array{id:int,data:string,descricao:string,valor:float,categoria_nome:string,apelido:?string,sinal:int,usuario_nome:string}>
     */
    public static function emLotes(string $mes, array $f, int $lote = 1000): \Generator
    {
        [$where, $params] = self::filtro($mes, $f);
        $lote = max(1, $lote);
        $depois = null;
        do {
            $w = $where;
            $p = $params;
            if ($depois !== null) {
                $w .= ' AND (l.data > :kd1 OR (l.data = :kd2 AND l.id > :kid))';
                $p += ['kd1' => $depois[0], 'kd2' => $depois[0], 'kid' => $depois[1]];
            }
            $stmt = Database::connection()->prepare(
                'SELECT l.id, l.data, l.descricao, l.valor, c.nome AS categoria_nome, c.apelido, c.sinal, u.nome AS usuario_nome
                 FROM lancamentos l
                 JOIN categorias c ON c.id = l.categoria_id
                 JOIN usuarios u ON u.id = l.usuario_id
                 WHERE ' . $w . '
                 ORDER BY l.data, l.id
                 LIMIT ' . $lote
            );
            $stmt->execute($p);
            $linhas = $stmt->fetchAll();
            foreach ($linhas as $r) {
                yield $r;
            }
            if ($linhas !== []) {
                $ult = $linhas[count($linhas) - 1];
                $depois = [(string) $ult['data'], (int) $ult['id']];
            }
        } while (count($linhas) === $lote);
    }

    /** Um lançamento, com categoria e quem lançou. */
    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT l.*, c.nome AS categoria_nome, c.apelido, c.grupo, c.sinal, c.operacional,
                    u.nome AS usuario_nome
             FROM lancamentos l
             JOIN categorias c ON c.id = l.categoria_id
             JOIN usuarios u ON u.id = l.usuario_id
             WHERE l.id = :id'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * $contaId null = a conta padrão. Um pagamento de fatura (a categoria da fatura) sem $vinculo ganha o cartão e a
     * fatura pela regra de CartaoFatura::atribuir (vinculoAutomatico); com $vinculo, vale o que veio.
     * @param ?array{cartao_id:int, fatura:string} $vinculo
     */
    public static function criar(string $data, string $descricao, int $categoriaId, float $valor, int $usuarioId,
                                 ?int $contaId = null, ?array $vinculo = null): int
    {
        $pdo = Database::connection();
        $vinculo = self::vinculo($categoriaId, $data, $vinculo);
        $stmt = $pdo->prepare(
            'INSERT INTO lancamentos (data, descricao, categoria_id, valor, usuario_id, conta_id, cartao_id, fatura)
             VALUES (:data, :descricao, :categoria_id, :valor, :usuario_id, :conta, :cartao, :fatura)'
        );
        $stmt->execute([
            'data'         => $data,
            'descricao'    => $descricao,
            'categoria_id' => $categoriaId,
            'valor'        => $valor,
            'usuario_id'   => $usuarioId,
            'conta'        => $contaId ?? ContaModel::padrao(),
            'cartao'       => $vinculo['cartao_id'] ?? null,
            'fatura'       => $vinculo['fatura'] ?? null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * O vínculo com a fatura de um lançamento da categoria $categoriaId: o que veio, ou o automático se for a categoria
     * da fatura; null nas outras categorias (e sem cartão).
     * @return ?array{cartao_id:int, fatura:string}
     */
    private static function vinculo(int $categoriaId, string $data, ?array $vinculo, ?int $ignorar = null): ?array
    {
        if ($categoriaId !== CartaoModel::categoriaFatura()) {
            return null;
        }
        if ($vinculo !== null && isset($vinculo['cartao_id'], $vinculo['fatura'])) {
            return ['cartao_id' => (int) $vinculo['cartao_id'], 'fatura' => (string) $vinculo['fatura']];
        }
        return CartaoFatura::vinculoAutomatico($data, isset($vinculo['cartao_id']) ? (int) $vinculo['cartao_id'] : null, $ignorar);
    }

    /**
     * Desfaz uma exclusão: recria a linha com o mesmo id e o mesmo criado_em (a conferência do
     * banco usa criado_em para o "agendado", D10). Se o id já foi reusado, cria com id novo.
     */
    public static function restaurar(array $l): int
    {
        $pdo = Database::connection();
        $dados = [
            'data'         => (string) $l['data'],
            'descricao'    => (string) $l['descricao'],
            'categoria_id' => (int) $l['categoria_id'],
            'valor'        => (float) $l['valor'],
            'usuario_id'   => (int) $l['usuario_id'],
            'criado_em'    => (string) $l['criado_em'],
            'conta'        => (int) ($l['conta_id'] ?? ContaModel::padrao()),
            'cartao'       => isset($l['cartao_id']) ? (int) $l['cartao_id'] : null,
            'fatura'       => isset($l['cartao_id'], $l['fatura']) ? (string) $l['fatura'] : null,
        ];
        try {
            $pdo->prepare(
                'INSERT INTO lancamentos (id, data, descricao, categoria_id, valor, usuario_id, criado_em, conta_id, cartao_id, fatura)
                 VALUES (:id, :data, :descricao, :categoria_id, :valor, :usuario_id, :criado_em, :conta, :cartao, :fatura)'
            )->execute(['id' => (int) $l['id']] + $dados);
            return (int) $l['id'];
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
        }
        $pdo->prepare(
            'INSERT INTO lancamentos (data, descricao, categoria_id, valor, usuario_id, criado_em, conta_id, cartao_id, fatura)
             VALUES (:data, :descricao, :categoria_id, :valor, :usuario_id, :criado_em, :conta, :cartao, :fatura)'
        )->execute($dados);
        return (int) $pdo->lastInsertId();
    }

    /**
     * $contaId null = a conta fica como está. Um pagamento de fatura mantém o cartão e a fatura que tinha, a não ser
     * que a data mude ou que $vinculo diga outro; vindo de outra categoria, ganha o automático. Fora da categoria da
     * fatura, o vínculo sai.
     * @param ?array{cartao_id:int, fatura?:string} $vinculo
     */
    public static function atualizar(int $id, string $data, string $descricao, int $categoriaId, float $valor,
                                     ?int $contaId = null, ?array $vinculo = null): void
    {
        $pdo = Database::connection();
        $antes = self::find($id);
        if ($vinculo === null && $antes !== null && $antes['cartao_id'] !== null && (int) $antes['categoria_id'] === $categoriaId
            && (string) $antes['data'] === $data) {
            $vinculo = ['cartao_id' => (int) $antes['cartao_id'], 'fatura' => (string) $antes['fatura']];
        } elseif ($vinculo === null && $antes !== null && $antes['cartao_id'] !== null) {
            $vinculo = ['cartao_id' => (int) $antes['cartao_id']];       // a data mudou: o mesmo cartão, a fatura de novo
        }
        $vinculo = self::vinculo($categoriaId, $data, $vinculo, $id);
        $stmt = $pdo->prepare(
            'UPDATE lancamentos SET data = :data, descricao = :descricao, categoria_id = :categoria_id, valor = :valor,
                    conta_id = COALESCE(:conta, conta_id), cartao_id = :cartao, fatura = :fatura
             WHERE id = :id'
        );
        $stmt->execute([
            'data'         => $data,
            'descricao'    => $descricao,
            'categoria_id' => $categoriaId,
            'valor'        => $valor,
            'conta'        => $contaId,
            'cartao'       => $vinculo['cartao_id'] ?? null,
            'fatura'       => $vinculo['fatura'] ?? null,
            'id'           => $id,
        ]);
    }

    public static function excluir(int $id): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('DELETE FROM lancamentos WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /** Soma operacional (Entradas/Fixos/Dia a dia/Cartão/Ano) de um mês, filtrando por sinal (+1 ou -1). */
    public static function somaOperacional(string $mes, int $sinal): float
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(l.valor), 0) AS total
             FROM lancamentos l
             JOIN categorias c ON c.id = l.categoria_id
             WHERE c.operacional = 1 AND c.sinal = :sinal
               AND l.data >= :inicio AND l.data < :fim'
        );
        $stmt->execute([
            'sinal'  => $sinal,
            'inicio' => $mes,
            'fim'    => self::fimDoMes($mes),
        ]);
        return (float) $stmt->fetchColumn();
    }

    /**
     * Entradas (sinal +1, qualquer grupo) de um mês: previsto e real por categoria. O real é o realizado: o agendado
     * fica de fora até o dia dele (realizado()).
     * @return list<array{id:int,nome:string,apelido:?string,grupo:string,operacional:bool,previsto:float,real:float}>
     */
    public static function entradasDoMes(string $mes, ?string $hoje = null): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT c.id, c.nome, c.apelido, c.grupo, c.operacional,
                    COALESCE((SELECT MAX(p.valor) FROM previsao_efetiva p WHERE p.categoria_id = c.id AND p.mes = :mes1), 0) AS previsto,
                    COALESCE((SELECT SUM(l.valor) FROM lancamentos l
                              WHERE l.categoria_id = c.id AND l.data >= :mes2 AND l.data < :fim AND ' . self::realizado('l.data', $hoje) . '), 0) AS realizado
             FROM categorias c
             WHERE c.sinal = 1
             ORDER BY c.ordem, c.id'
        );
        $stmt->execute(['mes1' => $mes, 'mes2' => $mes, 'fim' => self::fimDoMes($mes)]);
        $out = [];
        foreach ($stmt as $r) {
            $out[] = [
                'id' => (int) $r['id'], 'nome' => (string) $r['nome'],
                'apelido' => $r['apelido'] !== null ? (string) $r['apelido'] : null,
                'grupo' => (string) $r['grupo'], 'operacional' => (int) $r['operacional'] === 1,
                'previsto' => (float) $r['previsto'], 'real' => (float) $r['realizado'],
            ];
        }
        return $out;
    }

    /** Soma dos lançamentos de uma categoria (pelo id) num mês. */
    public static function somaPorCategoria(int $categoriaId, string $mes): float
    {
        $stmt = Database::connection()->prepare(
            'SELECT COALESCE(SUM(valor), 0) FROM lancamentos
             WHERE categoria_id = :c AND data >= :inicio AND data < :fim'
        );
        $stmt->execute(['c' => $categoriaId, 'inicio' => $mes, 'fim' => self::fimDoMes($mes)]);
        return (float) $stmt->fetchColumn();
    }

    /**
     * A soma de cada dia entre $de e $ate: o que entrou menos o que saiu, sem as movimentações (como a soma do dia do
     * Extrato). O Início mostra só os últimos lançamentos, mas a soma do dia é a do dia inteiro.
     * @return array<string, float> data => soma
     */
    public static function somasPorDia(string $de, string $ate): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT l.data, SUM(c.sinal * l.valor) AS soma FROM lancamentos l JOIN categorias c ON c.id = l.categoria_id
             WHERE l.data >= :de AND l.data <= :ate AND c.operacional = 1 GROUP BY l.data'
        );
        $stmt->execute(['de' => $de, 'ate' => $ate]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(string) $r['data']] = round((float) $r['soma'], 2);
        }
        return $out;
    }

    /**
     * Data do primeiro lançamento de uma categoria num mês (pra saber se foi pago antes/depois do vencimento).
     * Com $agendado: só o agendado, depois de $hoje (o dia em que a conta agendada vai ser paga).
     */
    public static function primeiraDataPorCategoriaNome(string $categoriaNome, string $mes, bool $agendado = false, ?string $hoje = null): ?string
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT MIN(l.data) AS primeira
             FROM lancamentos l
             JOIN categorias c ON c.id = l.categoria_id
             WHERE c.nome = :nome AND l.data >= :inicio AND l.data < :fim' . ($agendado ? ' AND NOT (' . self::realizado('l.data', $hoje) . ')' : '')
        );
        $stmt->execute([
            'nome'   => $categoriaNome,
            'inicio' => $mes,
            'fim'    => self::fimDoMes($mes),
        ]);
        $data = $stmt->fetchColumn();
        return $data ?: null;
    }

    /** Soma lançamentos de uma categoria específica (pelo nome) num mês. Com $hoje: só o realizado até ele (realizado()). */
    public static function somaPorCategoriaNome(string $categoriaNome, string $mes, ?string $hoje = null): float
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(l.valor), 0) AS total
             FROM lancamentos l
             JOIN categorias c ON c.id = l.categoria_id
             WHERE c.nome = :nome AND l.data >= :inicio AND l.data < :fim' . ($hoje !== null ? ' AND ' . self::realizado('l.data', $hoje) : '')
        );
        $stmt->execute([
            'nome'   => $categoriaNome,
            'inicio' => $mes,
            'fim'    => self::fimDoMes($mes),
        ]);
        return (float) $stmt->fetchColumn();
    }

    /** Saldo calculado na conta em uma data, a partir do saldo inicial + todos os lançamentos e acertos até lá. */
    public static function saldoCalculado(float $saldoInicial, string $ateData): float
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(l.valor * c.sinal), 0) AS total
             FROM lancamentos l
             JOIN categorias c ON c.id = l.categoria_id
             WHERE l.data <= :ate'
        );
        $stmt->execute(['ate' => $ateData]);
        // o saldo inicial de uma conta criada com meses fechados vale a partir da data dele (ContaModel)
        // e os acertos de saldo do banco (AcertoModel), a partir do dia de cada um
        return $saldoInicial + ContaModel::saldoInicialDatado($ateData) + (float) $stmt->fetchColumn() + AcertoModel::somaAte($ateData);
    }

    /**
     * Existe lançamento idêntico (mesma data, categoria e valor)? Usado pro aviso de duplicidade.
     * @return array{descricao:string,usuario_nome:string,data:string}|null
     */
    public static function existeIgual(string $data, int $categoriaId, float $valor): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT l.descricao, l.data, u.nome AS usuario_nome
             FROM lancamentos l JOIN usuarios u ON u.id = l.usuario_id
             WHERE l.data = :data AND l.categoria_id = :cat AND l.valor = :valor
             ORDER BY l.id DESC
             LIMIT 1'
        );
        $stmt->execute(['data' => $data, 'cat' => $categoriaId, 'valor' => $valor]);
        return $stmt->fetch() ?: null;
    }

    /** Os lançamentos de uma categoria, do mais recente para o mais antigo. */
    public static function porCategoria(int $categoriaId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, data, descricao, valor FROM lancamentos WHERE categoria_id = :cat ORDER BY data DESC, id DESC'
        );
        $stmt->execute(['cat' => $categoriaId]);
        return $stmt->fetchAll();
    }

    /**
     * Quantas vezes cada categoria foi lançada desde $desde: pelo usuário ('meu') e pela casa ('casa').
     * @return array<int, array{meu:int, casa:int}>
     */
    public static function frequenciaCategorias(int $usuarioId, string $desde): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT categoria_id, SUM(CASE WHEN usuario_id = :u THEN 1 ELSE 0 END) AS meu, COUNT(*) AS casa
             FROM lancamentos
             WHERE data >= :desde
             GROUP BY categoria_id'
        );
        $stmt->execute(['u' => $usuarioId, 'desde' => $desde]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['categoria_id']] = ['meu' => (int) $r['meu'], 'casa' => (int) $r['casa']];
        }
        return $out;
    }

    /**
     * As descrições mais usadas desde $desde (para o <datalist>), sem as que são só o nome da
     * categoria (descrição vazia já vira o nome).
     * @return string[]
     */
    public static function descricoesFrequentes(string $desde, int $limite = 30): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ' . Sql::menorTextoExato('l.descricao') . ' AS descricao
             FROM lancamentos l
             JOIN categorias c ON c.id = l.categoria_id
             WHERE l.data >= :desde AND l.descricao <> c.nome
             GROUP BY l.descricao
             ORDER BY COUNT(*) DESC, MAX(l.data) DESC, l.descricao
             LIMIT ' . max(1, min(100, $limite))
        );
        $stmt->execute(['desde' => $desde]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
