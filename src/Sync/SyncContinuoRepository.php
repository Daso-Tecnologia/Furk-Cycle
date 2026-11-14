<?php

declare(strict_types=1);

namespace FurkiCycle\Sync;

use PDO;

/**
 * Persistência do job da Sincronização Consolidada Contínua e do seu log de
 * evolução. Também concentra o snapshot/pausa/restauração das rotinas
 * contínuas (Sales e Status Dinâmicos), que ficam paradas durante a varredura.
 */
final class SyncContinuoRepository
{
    public const TABLE_JOB = 'furk_sync_continuo_job';
    public const TABLE_LOG = 'furk_sync_continuo_log';

    /** Rotinas contínuas que devem ser pausadas durante a varredura. */
    private const ROUTINE_TABLES = ['furk_dynamic_status_job', 'furk_sales_job'];

    public function __construct(private readonly PDO $connection)
    {
    }

    public function create(string $initialDate, string $finalDate, int $blockDays): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO ' . self::TABLE_JOB . ' (initial_date, final_date, cursor_date, block_days, estado)'
            . " VALUES (:i, :f, :c, :b, 'QUEUED')"
        );
        $statement->execute([
            'i' => $initialDate,
            'f' => $finalDate,
            'c' => $initialDate,
            'b' => $blockDays,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM ' . self::TABLE_JOB . ' WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function latest(): ?array
    {
        $row = $this->connection->query('SELECT * FROM ' . self::TABLE_JOB . ' ORDER BY id DESC LIMIT 1')->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function findActive(): ?array
    {
        // STOP_REQUESTED também é "ativo": evita que um novo job seja criado
        // enquanto há uma parada pendente (evita órfãos e rotinas pausadas sem fim).
        $row = $this->connection->query(
            'SELECT * FROM ' . self::TABLE_JOB . " WHERE estado IN ('QUEUED','RUNNING','STOP_REQUESTED') ORDER BY id ASC LIMIT 1"
        )->fetch();

        return is_array($row) ? $row : null;
    }

    /** Job a processar pelo worker: ativo, com parada pendente ou "reprocessar falhos". */
    /** @return array<string, mixed>|null */
    public function findWorkable(): ?array
    {
        $row = $this->connection->query(
            'SELECT * FROM ' . self::TABLE_JOB
            . " WHERE estado IN ('QUEUED','RUNNING','STOP_REQUESTED') OR retentar = 1 ORDER BY id DESC LIMIT 1"
        )->fetch();

        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $fields */
    public function update(int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }

        $assignments = [];
        $parameters = ['id' => $id];
        foreach ($fields as $column => $value) {
            $assignments[] = "{$column} = :{$column}";
            $parameters[$column] = $value;
        }

        $statement = $this->connection->prepare(
            'UPDATE ' . self::TABLE_JOB . ' SET ' . implode(', ', $assignments) . ' WHERE id = :id'
        );
        $statement->execute($parameters);
    }

    /** @param array<string, mixed>|null $resumo */
    public function addLog(int $jobId, string $blocoInicio, string $blocoFim, string $tabela, string $status, ?array $resumo, ?string $erro, float $duracao): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO ' . self::TABLE_LOG
            . ' (job_id, bloco_inicio, bloco_fim, tabela, status, resumo, erro, duracao_s)'
            . ' VALUES (:j, :bi, :bf, :t, :s, :r, :e, :d)'
        );
        $statement->execute([
            'j' => $jobId,
            'bi' => $blocoInicio,
            'bf' => $blocoFim,
            't' => $tabela,
            's' => $status,
            'r' => $resumo === null ? null : json_encode($resumo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'e' => $erro,
            'd' => $duracao,
        ]);
    }

    /** @param array<string, mixed> $fields */
    public function updateLog(int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $assignments = [];
        $parameters = ['id' => $id];
        foreach ($fields as $column => $value) {
            $assignments[] = "{$column} = :{$column}";
            $parameters[$column] = $value;
        }
        $statement = $this->connection->prepare(
            'UPDATE ' . self::TABLE_LOG . ' SET ' . implode(', ', $assignments) . ' WHERE id = :id'
        );
        $statement->execute($parameters);
    }

    /** @return array<int, array<string, mixed>> */
    public function recentLogs(int $jobId, int $limit = 50): array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM ' . self::TABLE_LOG . ' WHERE job_id = :j ORDER BY id DESC LIMIT ' . max(1, $limit)
        );
        $statement->execute(['j' => $jobId]);

        return $statement->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function failedLogs(int $jobId): array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM ' . self::TABLE_LOG . " WHERE job_id = :j AND status = 'ERRO' ORDER BY id ASC"
        );
        $statement->execute(['j' => $jobId]);

        return $statement->fetchAll();
    }

    /**
     * Pausa as rotinas contínuas e devolve o snapshot (estado anterior) para
     * restauração posterior.
     *
     * @return array<string, array{id: int, estado: string}>
     */
    public function pauseRoutines(): array
    {
        $snapshot = [];
        foreach (self::ROUTINE_TABLES as $table) {
            $row = $this->connection->query('SELECT id, estado FROM ' . $table . ' ORDER BY id DESC LIMIT 1')->fetch();
            if (!is_array($row)) {
                continue;
            }
            $snapshot[$table] = ['id' => (int) $row['id'], 'estado' => (string) $row['estado']];

            if ((string) $row['estado'] === 'RUNNING') {
                $statement = $this->connection->prepare('UPDATE ' . $table . " SET estado = 'STOP_REQUESTED' WHERE id = :id");
                $statement->execute(['id' => (int) $row['id']]);
            }
        }

        return $snapshot;
    }

    /** @param array<string, mixed> $snapshot */
    public function restoreRoutines(array $snapshot): void
    {
        foreach (self::ROUTINE_TABLES as $table) {
            if (!isset($snapshot[$table]) || !is_array($snapshot[$table])) {
                continue;
            }
            $id = (int) ($snapshot[$table]['id'] ?? 0);
            $estado = (string) ($snapshot[$table]['estado'] ?? '');
            if ($id <= 0 || $estado === '' || !in_array($table, self::ROUTINE_TABLES, true)) {
                continue;
            }
            $statement = $this->connection->prepare('UPDATE ' . $table . ' SET estado = :e WHERE id = :id');
            $statement->execute(['e' => $estado, 'id' => $id]);
        }
    }

    public function acquireLock(string $name): int
    {
        $statement = $this->connection->prepare('SELECT GET_LOCK(:name, 0)');
        $statement->execute(['name' => $name]);

        return (int) $statement->fetchColumn();
    }

    public function releaseLock(string $name): void
    {
        $this->connection->prepare('SELECT RELEASE_LOCK(:name)')->execute(['name' => $name]);
    }
}
