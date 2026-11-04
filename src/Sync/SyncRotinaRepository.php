<?php

declare(strict_types=1);

namespace FurkiCycle\Sync;

use PDO;
use RuntimeException;

/**
 * Persistência do job da Sincronização Consolidada Contínua Recursiva.
 *
 * Guarda a configuração (data_base sempre a data atual, frequencia_horas,
 * dias_recuo), o estado (IDLE/RUNNING/STOP_REQUESTED/ERROR) e os indicadores
 * de onde o processo está atuando (tabela_atual, janela_inicial, janela_final).
 */
final class SyncRotinaRepository
{
    public const TABLE = 'furk_sync_rotina';

    public function __construct(private readonly PDO $connection)
    {
    }

    /** @return array<string, mixed>|null */
    public function findJob(): ?array
    {
        $statement = $this->connection->query('SELECT * FROM ' . self::TABLE . ' ORDER BY id DESC LIMIT 1');
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function createDefault(): void
    {
        $this->connection->exec('INSERT INTO ' . self::TABLE . ' (estado) VALUES (\'IDLE\')');
    }

    /** @return array<string, mixed> */
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
        $job = $this->tryAcquireLock($name);

        if ($job === null) {
            throw new RuntimeException('A rotina já está em execução em outra instância.');
        }

        return $job;
    }

    /**
     * Tenta adquirir o lock sem lançar exceção quando outra instância já está
     * processando. Retorna null nesse caso (o worker apenas ignora o disparo).
     *
     * @return array<string, mixed>|null
     */
    public function tryAcquireLock(string $name): ?array
    {
        $job = $this->ensureJob();

        $lockStatement = $this->connection->prepare('SELECT GET_LOCK(:name, 0)');
        $lockStatement->execute(['name' => $name]);
        $acquired = (int) $lockStatement->fetchColumn();

        return $acquired === 1 ? $job : null;
    }

    public function releaseLock(string $name): void
    {
        $this->connection->prepare('SELECT RELEASE_LOCK(:name)')->execute(['name' => $name]);
    }
}
