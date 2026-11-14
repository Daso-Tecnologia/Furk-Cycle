<?php

declare(strict_types=1);

namespace FurkiCycle\Locator;

use FurkiCycle\Config\Env;
use FurkiCycle\Furk\DynamicStatusClient;
use RuntimeException;

final class LocatorLookupService
{
    public function __construct(
        private readonly Env $env,
        private readonly DynamicStatusClient $client,
    ) {
    }

    /**
     * @return array{
     *     status: 'ENCONTRADO'|'NAO_ENCONTRADO'|'INCONCLUSIVO',
     *     message: string,
     *     matches: array<int, array<string, string|null>>,
     *     total_pages: int,
     *     total_elements: int,
     *     records_scanned: int
     * }
     */
    public function lookup(string $initialIssueDate, string $finalIssueDate, string $locator): array
    {
        $this->validateRange($initialIssueDate, $finalIssueDate);
        $this->validateLocator($locator);

        $size = $this->env->int('FURK_DYNAMIC_STATUS_PAGE_SIZE', 500);
        $maxRetries = $this->env->int('FURK_DYNAMIC_STATUS_MAX_RETRIES', 3);

        $needle = mb_strtoupper(trim($locator));
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
            ];
        }

        if ($totalPages > 1) {
            return [
                'status' => 'INCONCLUSIVO',
                'message' => 'O localizador não apareceu na primeira página e existem outras páginas sem revisar.',
                'matches' => [],
                'total_pages' => $totalPages,
                'total_elements' => $totalElements,
                'records_scanned' => $recordsScanned,
            ];
        }

        return [
            'status' => 'NAO_ENCONTRADO',
            'message' => 'Nenhum registro correspondente foi encontrado na API.',
            'matches' => [],
            'total_pages' => $totalPages,
            'total_elements' => $totalElements,
            'records_scanned' => $recordsScanned,
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
     * @return array<string, string|null>
     */
    private function mapMatch(string $needle, string $matched, array $item, array $ticketId): array
    {
        return [
            'searched' => $needle,
            'matched' => $matched,
            'airline_code' => $this->string($ticketId['airlineCode'] ?? null),
            'locator_code' => $this->string($ticketId['locatorCode'] ?? null),
            'ndc_order_id' => $this->string($ticketId['ndcOrderId'] ?? null),
            'transaction_type' => $this->string($ticketId['transactionType'] ?? null),
            'issue_date' => $this->string($ticketId['issueDate'] ?? null),
            'status' => $this->string($item['ticketStatus'] ?? $item['status'] ?? null),
            'updated_at' => $this->string($item['updatedAt'] ?? null),
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