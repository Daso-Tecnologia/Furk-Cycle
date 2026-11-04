<?php

declare(strict_types=1);

namespace FurkiCycle\Rebooking;

use FurkiCycle\Config\Env;
use FurkiCycle\Database\MySqlConnection;
use PDO;
use RuntimeException;

final class RebookingControlRepository
{
    private ?PDO $connection = null;

    public function __construct(
        private readonly Env $env,
    ) {
    }

    public function connect(): PDO
    {
        return $this->connection ??= MySqlConnection::create($this->env);
    }

    /** @return array<string> */
    public function controlledNotas(string $environment): array
    {
        $statement = $this->connect()->prepare(
            'SELECT DISTINCT nota FROM furk_rebooking_control WHERE environment = :environment AND nota IS NOT NULL',
        );
        $statement->execute(['environment' => $environment]);
        $notas = [];
        foreach ($statement as $row) {
            $nota = trim((string) ($row['nota'] ?? ''));
            if ($nota !== '') {
                $notas[] = $nota;
            }
        }
        return $notas;
    }

    /** @return array<int, array<string, mixed>> */
    public function byNotas(string $environment, array $notas): array
    {
        if ($notas === []) {
            return [];
        }
        $placeholders = [];
        $params = ['environment' => $environment];
        foreach ($notas as $index => $nota) {
            $name = "nota{$index}";
            $placeholders[] = ":{$name}";
            $params[$name] = $nota;
        }
        $statement = $this->connect()->prepare(
            'SELECT id, environment, fil_agt, nota, num_file, localizador, tkt_num, airline_code, '
            . 'furk_rebooking_id, furk_status, http_status, processing_status, furk_request_datetime, '
            . 'created_at, updated_at '
            . 'FROM furk_rebooking_control '
            . "WHERE environment = :environment AND nota IN (" . implode(', ', $placeholders) . ')',
        );
        $statement->execute($params);
        $byNota = [];
        foreach ($statement as $row) {
            $nota = (string) ($row['nota'] ?? '');
            $byNota[$nota] = $row;
        }
        return $byNota;
    }

    /** @return array{rows: array, total: int} */
    public function page(string $environment, int $page, int $size, string $search = '', ?string $nota = null): array
    {
        $conditions = ['environment = :environment'];
        $params = ['environment' => $environment];

        $search = trim($search ?? '');
        if ($search !== '') {
            $like = '%' . $search . '%';
            $columns = ['nota', 'num_file', 'fil_agt', 'localizador', 'tkt_num', 'airline_code', 'furk_rebooking_id', 'furk_status', 'processing_status'];
            $parts = [];
            foreach ($columns as $index => $column) {
                $name = "search{$index}";
                $parts[] = "{$column} LIKE :{$name}";
                $params[$name] = $like;
            }
            $conditions[] = '(' . implode(' OR ', $parts) . ')';
        }
        if ($nota !== null && $nota !== '') {
            $conditions[] = 'nota = :nota';
            $params['nota'] = $nota;
        }

        $where = ' WHERE ' . implode(' AND ', $conditions);
        $countStatement = $this->connect()->prepare("SELECT COUNT(*) AS total FROM furk_rebooking_control{$where}");
        $countStatement->execute($params);
        $total = (int) $countStatement->fetchColumn();

        $params['offset'] = max(0, ($page - 1) * $size);
        $params['limit'] = $size;

        $statement = $this->connect()->prepare(
            'SELECT id, environment, fil_agt, nota, num_file, localizador, tkt_num, airline_code, iata_code, '
            . 'issue_channel_sent, issue_channel_returned, furk_rebooking_id, furk_status, furk_request_datetime, '
            . 'http_status, processing_status, error_message, last_checked_at, next_check_at, created_at, updated_at '
            . "FROM furk_rebooking_control{$where} ORDER BY id DESC LIMIT :limit OFFSET :offset",
        );
        $statement->execute($params);

        return ['rows' => $statement->fetchAll(), 'total' => $total];
    }

    /** @return ?array<string, mixed> */
    public function findByNota(string $environment, string $nota): ?array
    {
        $statement = $this->connect()->prepare(
            'SELECT id, nota, processing_status, furk_status, furk_rebooking_id '
            . 'FROM furk_rebooking_control '
            . 'WHERE environment = :environment AND nota = :nota LIMIT 1',
        );
        $statement->execute(['environment' => $environment, 'nota' => $nota]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return ?array<string, mixed> */
    public function existingControl(string $environment, string $key, string $nota): ?array
    {
        $statement = $this->connect()->prepare(
            'SELECT id, processing_status FROM furk_rebooking_control '
            . 'WHERE environment = :environment AND (idempotency_key = :key OR nota = :nota) LIMIT 1',
        );
        $statement->execute(['environment' => $environment, 'key' => $key, 'nota' => $nota]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function createPending(array $values): int
    {
        $connection = $this->connect();
        $statement = $connection->prepare(<<<'SQL'
INSERT INTO furk_rebooking_control (
    environment, fil_agt, nota, num_file, localizador, tkt_num, airline_code,
    iata_code, issue_channel_sent, processing_status, request_payload,
    idempotency_key, attempt_count, field_sources, validation_notes
) VALUES (
    :environment, :fil_agt, :nota, :num_file, :localizador, :tkt_num, :airline_code,
    :iata_code, :issue_channel_sent, 'PENDING', :request_payload,
    :idempotency_key, 1, :field_sources, :validation_notes
)
SQL);
        $statement->execute($values);
        return (int) $connection->lastInsertId();
    }

    public function updateAfterPost(array $fields, int $id): void
    {
        $statement = $this->connect()->prepare(<<<'SQL'
UPDATE furk_rebooking_control SET
    furk_rebooking_id = :furk_rebooking_id,
    furk_status = :furk_status,
    issue_channel_returned = :issue_channel_returned,
    furk_request_datetime = NOW(6),
    http_status = :http_status,
    processing_status = :processing_status,
    error_message = :error_message,
    response_payload = :response_payload
WHERE id = :id
SQL);
        $statement->execute($fields + ['id' => $id]);
    }

    public function markError(int $id, string $errorMessage, ?string $responsePayload = null): void
    {
        $statement = $this->connect()->prepare(
            'UPDATE furk_rebooking_control SET '
            . 'processing_status = :processing_status, '
            . 'error_message = :error_message, '
            . 'response_payload = COALESCE(:response_payload, response_payload) '
            . 'WHERE id = :id',
        );
        $statement->execute([
            'processing_status' => 'ERROR',
            'error_message' => $errorMessage,
            'response_payload' => $responsePayload,
            'id' => $id,
        ]);
    }

    /** @return array{rows: array, total: int} */
    public function webhookEvents(string $environment, int $page, int $size, string $search = ''): array
    {
        $conditions = ['environment = :environment'];
        $params = ['environment' => $environment];

        $search = trim($search ?? '');
        if ($search !== '') {
            $like = '%' . $search . '%';
            $columns = ['nota', 'furk_rebooking_id', 'furk_status', 'processing_status', 'last_checked_at'];
            $parts = [];
            foreach ($columns as $index => $column) {
                $name = "s{$index}";
                $parts[] = "{$column} LIKE :{$name}";
                $params[$name] = $like;
            }
            $conditions[] = '(' . implode(' OR ', $parts) . ')';
        }

        $where = ' WHERE ' . implode(' AND ', $conditions);
        $countStatement = $this->connect()->prepare("SELECT COUNT(*) AS total FROM furk_rebooking_control{$where}");
        $countStatement->execute($params);
        $total = (int) $countStatement->fetchColumn();

        $params['offset'] = max(0, ($page - 1) * $size);
        $params['limit'] = $size;

        $statement = $this->connect()->prepare(
            'SELECT id, nota, furk_rebooking_id, furk_status, http_status, processing_status, '
            . 'last_checked_at, next_check_at, validation_notes, updated_at '
            . "FROM furk_rebooking_control{$where} ORDER BY id DESC LIMIT :limit OFFSET :offset",
        );
        $statement->execute($params);

        $rows = [];
        foreach ($statement as $row) {
            $notes = json_decode((string) ($row['validation_notes'] ?? ''), true);
            $notes = is_array($notes) ? $notes : [];
            $row['last_webhook_status'] = $notes['last_webhook_status'] ?? null;
            $row['last_webhook_datetime'] = $notes['last_webhook_datetime'] ?? null;
            unset($row['validation_notes']);
            $rows[] = $row;
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array<int, array<string, mixed>> */
    public function webhookEventsAll(string $environment, string $search = ''): array
    {
        $conditions = ['environment = :environment'];
        $params = ['environment' => $environment];

        $search = trim($search ?? '');
        if ($search !== '') {
            $like = '%' . $search . '%';
            $columns = ['nota', 'furk_rebooking_id', 'furk_status', 'processing_status', 'last_checked_at'];
            $parts = [];
            foreach ($columns as $index => $column) {
                $name = "s{$index}";
                $parts[] = "{$column} LIKE :{$name}";
                $params[$name] = $like;
            }
            $conditions[] = '(' . implode(' OR ', $parts) . ')';
        }

        $where = ' WHERE ' . implode(' AND ', $conditions);
        $statement = $this->connect()->prepare(
            'SELECT id, nota, furk_rebooking_id, furk_status, http_status, processing_status, '
            . 'last_checked_at, next_check_at, validation_notes, updated_at '
            . "FROM furk_rebooking_control{$where} ORDER BY id DESC",
        );
        $statement->execute($params);

        $rows = [];
        foreach ($statement as $row) {
            $notes = json_decode((string) ($row['validation_notes'] ?? ''), true);
            $notes = is_array($notes) ? $notes : [];
            $row['last_webhook_status'] = $notes['last_webhook_status'] ?? null;
            $row['last_webhook_datetime'] = $notes['last_webhook_datetime'] ?? null;
            unset($row['validation_notes']);
            $rows[] = $row;
        }

        return $rows;
    }
}