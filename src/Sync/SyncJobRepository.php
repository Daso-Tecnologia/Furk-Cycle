<?php

declare(strict_types=1);

namespace FurkiCycle\Sync;

use PDO;

final class SyncJobRepository
{
    public const TABLE = 'furk_sync_job';

    public function __construct(private readonly PDO $connection)
    {
    }

    public function create(string $initialDate, string $finalDate): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO ' . self::TABLE . ' (initial_date, final_date, estado) VALUES (:i, :f, \'QUEUED\')'
        );
        $statement->execute(['i' => $initialDate, 'f' => $finalDate]);

        return (int) $this->connection->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function latest(): ?array
    {
        $row = $this->connection->query('SELECT * FROM ' . self::TABLE . ' ORDER BY id DESC LIMIT 1')->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function findPending(): ?array
    {
        $row = $this->connection->query(
            'SELECT * FROM ' . self::TABLE . " WHERE estado IN ('QUEUED','RUNNING') ORDER BY id ASC LIMIT 1"
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
            'UPDATE ' . self::TABLE . ' SET ' . implode(', ', $assignments) . ' WHERE id = :id'
        );
        $statement->execute($parameters);
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
