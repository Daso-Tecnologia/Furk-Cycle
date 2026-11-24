<?php

declare(strict_types=1);

namespace FurkiCycle\Locator;

use FurkiCycle\Config\Env;
use FurkiCycle\Furk\DynamicStatusClient;
use RuntimeException;
use Throwable;

final class LocatorLookupService
{
    public function __construct(
        private readonly Env $env,
        private readonly DynamicStatusClient $client,
        private readonly ?LocalLocatorRepository $local = null,
    ) {
    }

    /**
     * Procura primeiro no banco local (dados já importados; instantâneo e sem gastar a cota da Furk) e,
     * se não achar, consulta a primeira página da API da Furk no período informado.
     *
     * @return array{
     *     status: 'ENCONTRADO'|'NAO_ENCONTRADO'|'INCONCLUSIVO',
     *     message: string,
     *     matches: array<int, array<string, mixed>>,
     *     total_pages: int,
     *     total_elements: int,
     *     records_scanned: int,
     *     source: 'BANCO_LOCAL'|'API_FURK'
     * }
     */
    public function lookup(string $initialIssueDate, string $finalIssueDate, string $locator): array
    {
        $this->validateRange($initialIssueDate, $finalIssueDate);
        $this->validateLocator($locator);

        $size = $this->env->int('FURK_DYNAMIC_STATUS_PAGE_SIZE', 500);
        $maxRetries = $this->env->int('FURK_DYNAMIC_STATUS_MAX_RETRIES', 3);

        $needle = mb_strtoupper(trim($locator));

        // 1) Banco local (já importado): cobre todo o período lido, mesmo fora do intervalo informado.
        $localResult = $this->lookupLocal($needle, $initialIssueDate, $finalIssueDate);
        if ($localResult !== null) {
            return $localResult;
        }

        // 2) API da Furk (somente a primeira página do período informado).
        $response = $this->fetchPage($initialIssueDate, $finalIssueDate, 1, $size, $maxRetries);

        $totalElements = $this->positiveInt($response['totalElements'] ?? 0);
        $totalPages = $this->positiveInt($response['totalPages'] ?? 1);
        $items = $response['data'] ?? [];
        if (!is_array($items)) {
            throw new RuntimeException('A Furk retornou dados em um formato inesperado. Tente novamente.');
        }

        $matches = [];
        $recordsScanned = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new RuntimeException('A Furk retornou um registro inválido.');
            }
            $recordsScanned++;
            $ticketId = $this->array($item['ticketID'] ?? null);
            $fieldValue = $this->string($ticketId['locatorCode'] ?? null);

            if ($fieldValue !== null && mb_strtoupper(trim($fieldValue)) === $needle) {
                $matches[] = $this->mapMatch($needle, $fieldValue, $item, $ticketId);
            }
        }

        if ($matches !== []) {
            return [
                'status' => 'ENCONTRADO',
                'message' => 'Registro localizado na API Furk.',
                'matches' => $matches,
                'total_pages' => $totalPages,
                'total_elements' => $totalElements,
                'records_scanned' => $recordsScanned,
                'source' => 'API_FURK',
            ];
        }

        if ($totalPages > 1) {
            return [
                'status' => 'INCONCLUSIVO',
                'message' => 'Não consta no banco local e não apareceu na primeira página da Furk; existem outras páginas sem revisar. '
                    . 'Informe um período mais curto (de preferência o dia da emissão) para a Furk ser consultada com mais precisão.',
                'matches' => [],
                'total_pages' => $totalPages,
                'total_elements' => $totalElements,
                'records_scanned' => $recordsScanned,
                'source' => 'API_FURK',
            ];
        }

        return [
            'status' => 'NAO_ENCONTRADO',
            'message' => 'Nenhum registro correspondente foi encontrado no banco local nem na API da Furk.',
            'matches' => [],
            'total_pages' => $totalPages,
            'total_elements' => $totalElements,
            'records_scanned' => $recordsScanned,
            'source' => 'API_FURK',
        ];
    }

    /**
     * Procura o localizador no banco local. Falha do banco local NÃO derruba a consulta (segue para a API).
     *
     * @return ?array<string, mixed>
     */
    private function lookupLocal(string $needle, string $initialIssueDate, string $finalIssueDate): ?array
    {
        if ($this->local === null) {
            return null;
        }
        try {
            $rows = $this->local->findByLocator($needle);
        } catch (Throwable $error) {
            error_log('locator-lookup: banco local indisponivel, usando a API: ' . $error->getMessage());
            return null;
        }
        if ($rows === []) {
            return null;
        }

        $matches = [];
        $inPeriod = 0;
        foreach ($rows as $row) {
            $issue = $this->string($row['issue_date'] ?? null);
            $within = $issue !== null && $issue >= $initialIssueDate && $issue <= $finalIssueDate;
            $inPeriod += $within ? 1 : 0;

            $segments = [];
            foreach ((array) ($row['segments'] ?? []) as $segment) {
                $segments[] = [
                    'order' => $this->string($segment['segment_order'] ?? null),
                    'origin' => $this->string($segment['origin'] ?? null),
                    'destination' => $this->string($segment['destination'] ?? null),
                    'airline_code' => $this->string($segment['flight_airline_code'] ?? null),
                    'flight_number' => $this->string($segment['flight_number'] ?? null),
                    'departure' => $this->string($segment['departure_date'] ?? null),
                    'arrival' => $this->string($segment['arrival_date'] ?? null),
                    'flight_status' => $this->string($segment['flight_status'] ?? null),
                    'fare_class' => $this->string($segment['fare_class'] ?? null),
                    'fare_basis' => $this->string($segment['fare_basis'] ?? null),
                ];
            }

            $matches[] = [
                'searched' => $needle,
                'matched' => $this->string($row['locator_code'] ?? null) ?? $this->string($row['ticket_number'] ?? null),
                'airline_code' => $this->string($row['airline_code'] ?? null),
                'ticket_number' => $this->string($row['ticket_number'] ?? null),
                'locator_code' => $this->string($row['locator_code'] ?? null),
                'ndc_order_id' => $this->string($row['ndc_order_id'] ?? null),
                'transaction_type' => $this->string($row['transaction_type'] ?? null),
                'issue_date' => $issue,
                'status' => $this->string($row['ticket_status'] ?? null),
                'updated_at' => $this->string($row['updated_at'] ?? null),
                'in_period' => $within,
                'segments' => $segments,
            ];
        }

        return [
            'status' => 'ENCONTRADO',
            'message' => $inPeriod > 0
                ? 'Registro localizado no banco local do Furk Cycle (dados já importados da Furk).'
                : 'Registro localizado no banco local, mas com emissão FORA do período informado. Veja a data de emissão abaixo.',
            'matches' => $matches,
            'total_pages' => 0,
            'total_elements' => count($matches),
            'records_scanned' => 0,
            'source' => 'BANCO_LOCAL',
        ];
    }

    /** @return array<string, mixed> */
    private function fetchPage(string $initialIssueDate, string $finalIssueDate, int $page, int $size, int $maxRetries): array
    {
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                return $this->client->list($initialIssueDate, $finalIssueDate, $page, $size);
            } catch (RuntimeException $error) {
                if ($attempt === $maxRetries) {
                    throw new RuntimeException("Falha ao consultar a Furk (tentativa {$attempt}): {$error->getMessage()}");
                }
                sleep($attempt);
            }
        }

        throw new RuntimeException('Não foi possível concluir a consulta à Furk. Tente novamente.');
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $ticketId
     * @return array<string, mixed>
     */
    private function mapMatch(string $needle, string $matched, array $item, array $ticketId): array
    {
        return [
            'searched' => $needle,
            'matched' => $matched,
            'airline_code' => $this->string($ticketId['airlineCode'] ?? null),
            'ticket_number' => $this->string($ticketId['ticketNumber'] ?? null),
            'locator_code' => $this->string($ticketId['locatorCode'] ?? null),
            'ndc_order_id' => $this->string($ticketId['ndcOrderId'] ?? null),
            'transaction_type' => $this->string($ticketId['transactionType'] ?? null),
            'issue_date' => $this->string($ticketId['issueDate'] ?? null),
            'status' => $this->string($item['ticketStatus'] ?? $item['status'] ?? null),
            'updated_at' => $this->string($item['updatedAt'] ?? null),
            'in_period' => true,
            'segments' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function string(mixed $value): ?string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function positiveInt(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    private function validateRange(string $initialIssueDate, string $finalIssueDate): void
    {
        $initial = \DateTimeImmutable::createFromFormat('!Y-m-d', $initialIssueDate);
        $final = \DateTimeImmutable::createFromFormat('!Y-m-d', $finalIssueDate);
        if ($initial === false || $final === false || $initial->format('Y-m-d') !== $initialIssueDate || $final->format('Y-m-d') !== $finalIssueDate) {
            throw new RuntimeException('Informe as datas no formato AAAA-MM-DD.');
        }
        if ($initial > $final) {
            throw new RuntimeException('A data inicial não pode ser posterior à data final.');
        }
    }

    private function validateLocator(string $locator): void
    {
        if (trim($locator) === '') {
            throw new RuntimeException('Informe o localizador a consultar.');
        }
    }
}