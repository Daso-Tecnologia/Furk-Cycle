<?php

declare(strict_types=1);

namespace FurkiCycle\DynamicStatus;

use PDO;
use RuntimeException;

final class DynamicStatusJobRepository
{
    public const TABLE = 'furk_dynamic_status_job';

    public function __construct(private readonly PDO $connection)
    {
    }

    /** @return array<string, mixed>|null */
    public function findJob(): ?array
    {
        $statement = $this->connection->query(
            'SELECT * FROM ' . self::TABLE . ' ORDER BY id DESC LIMIT 1'
        );
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function createDefault(): void
    {
        $this->connection->exec(
            'INSERT INTO ' . self::TABLE . ' (estado) VALUES (\'IDLE\')'
        );
    }

    public function ensureJob(): array
    {
        $job = $this->findJob();
        if ($job !== null) {
            return $job;
        }
        $this->createDefault();
        $job = $this->findJob();
        if ($job === null) {
            throw new RuntimeException('Não foi possível criar o registro de controle da rotina.');
        }
        return $job;
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

    /** @return array<string, mixed> */
    public function acquireLock(string $name): array
    {
        $job = $this->ensureJob();

        $lockStatement = $this->connection->prepare('SELECT GET_LOCK(:name, 0)');
        $lockStatement->execute(['name' => $name]);
        $acquired = (int) $lockStatement->fetchColumn();

        if ($acquired !== 1) {
            throw new RuntimeException('A rotina contínua já está em execução em outra instância.');
        }

        return $job;
    }

    public function releaseLock(string $name): void
    {
        $this->connection->prepare('SELECT RELEASE_LOCK(:name)')->execute(['name' => $name]);
    }
}