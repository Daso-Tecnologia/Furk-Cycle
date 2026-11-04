<?php

declare(strict_types=1);

namespace FurkiCycle\Sales;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

final class SalesRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    /**
     * @param array<string, mixed> $item
     * @return 'inserted'|'updated'|'unchanged'
     */
    public function upsert(array $item): string
    {
        $record = $this->map($item);

        $sql = <<<'SQL'
INSERT INTO furkapi_sales (
    airline_code, ticket_number, original_ticket_number, locator_code, ndc_order_id,
    transaction_type, issue_date, ticket_status, issue_channel, iata_code,
    operational_unit, issuer_user, tour_code, passenger_first_name, passenger_last_name,
    passenger_type, total_fare_value, total_boarding_fee_value, total_tax_du_value,
    total_taxes_value, total_incentive_value, total_commission_value, total_value,
    updated_at, last_synced_at
) VALUES (
    :airline_code, :ticket_number, :original_ticket_number, :locator_code, :ndc_order_id,
    :transaction_type, :issue_date, :ticket_status, :issue_channel, :iata_code,
    :operational_unit, :issuer_user, :tour_code, :passenger_first_name, :passenger_last_name,
    :passenger_type, :total_fare_value, :total_boarding_fee_value, :total_tax_du_value,
    :total_taxes_value, :total_incentive_value, :total_commission_value, :total_value,
    :updated_at, CURRENT_TIMESTAMP
)
ON DUPLICATE KEY UPDATE
    original_ticket_number = VALUES(original_ticket_number),
    locator_code = VALUES(locator_code),
    ndc_order_id = VALUES(ndc_order_id),
    transaction_type = VALUES(transaction_type),
    issue_date = VALUES(issue_date),
    ticket_status = VALUES(ticket_status),
    issue_channel = VALUES(issue_channel),
    iata_code = VALUES(iata_code),
    operational_unit = VALUES(operational_unit),
    issuer_user = VALUES(issuer_user),
    tour_code = VALUES(tour_code),
    passenger_first_name = VALUES(passenger_first_name),
    passenger_last_name = VALUES(passenger_last_name),
    passenger_type = VALUES(passenger_type),
    total_fare_value = VALUES(total_fare_value),
    total_boarding_fee_value = VALUES(total_boarding_fee_value),
    total_tax_du_value = VALUES(total_tax_du_value),
    total_taxes_value = VALUES(total_taxes_value),
    total_incentive_value = VALUES(total_incentive_value),
    total_commission_value = VALUES(total_commission_value),
    total_value = VALUES(total_value),
    updated_at = VALUES(updated_at),
    last_synced_at = CURRENT_TIMESTAMP
SQL;

        $statement = $this->connection->prepare($sql);
        $statement->execute($record);
        $affectedRows = $statement->rowCount();

        return match ($affectedRows) {
            1 => 'inserted',
            2 => 'updated',
            default => 'unchanged',
        };
    }

    /** @param array<string, mixed> $item @return array<string, string|null> */
    private function map(array $item): array
    {
        $ticketId = $this->array($item['ticketID'] ?? ($item['ticket']['ticketID'] ?? null));
        $passenger = $this->array($item['passenger'] ?? ($item['ticket']['passenger'] ?? null));

        $airlineCode = $this->string($ticketId['airlineCode'] ?? null);
        $ticketNumber = $this->string($ticketId['ticketNumber'] ?? null);

        if ($airlineCode === null || $ticketNumber === null) {
            throw new RuntimeException('A Furk retornou uma venda sem airlineCode ou ticketNumber; o registro não foi gravado.');
        }

        return [
            'airline_code' => $airlineCode,
            'ticket_number' => $ticketNumber,
            'original_ticket_number' => $this->string($item['originalTicketNumber'] ?? null),
            'locator_code' => $this->string($ticketId['locatorCode'] ?? null),
            'ndc_order_id' => $this->string($ticketId['ndcOrderId'] ?? null),
            'transaction_type' => $this->string($ticketId['transactionType'] ?? null),
            'issue_date' => $this->date($ticketId['issueDate'] ?? null),
            'ticket_status' => $this->string($item['ticketStatus'] ?? null),
            'issue_channel' => $this->string($item['issueChannel'] ?? null),
            'iata_code' => $this->string($item['iataCode'] ?? null),
            'operational_unit' => $this->string($item['operationalUnit'] ?? null),
            'issuer_user' => $this->string($item['issuerUser'] ?? null),
            'tour_code' => $this->string($item['tourCode'] ?? null),
            'passenger_first_name' => $this->string($passenger['passengerFirstName'] ?? null),
            'passenger_last_name' => $this->string($passenger['passengerLastName'] ?? null),
            'passenger_type' => $this->string($passenger['passengerType'] ?? null),
            'total_fare_value' => $this->decimal($item['totalFareValue'] ?? null),
            'total_boarding_fee_value' => $this->decimal($item['totalBoardingFeeValue'] ?? null),
            'total_tax_du_value' => $this->decimal($item['totalTaxDuValue'] ?? null),
            'total_taxes_value' => $this->decimal($item['totalTaxesValue'] ?? null),
            'total_incentive_value' => $this->decimal($item['totalIncentiveValue'] ?? null),
            'total_commission_value' => $this->decimal($item['totalCommissionValue'] ?? null),
            'total_value' => $this->decimal($item['totalValue'] ?? null),
            'updated_at' => $this->dateTime($item['updatedAt'] ?? null),
        ];
    }

    /** @return array<string, mixed> */
    private function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function string(mixed $value): ?string
    {
        if ($value === null || (!is_string($value) && !is_numeric($value))) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function decimal(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $text = trim($value);
        if ($text === '') {
            return null;
        }

        if (is_numeric($text)) {
            return $text;
        }

        $normalized = str_replace(['.', ','], ['', '.'], $text);

        return is_numeric($normalized) ? $normalized : null;
    }

    private function date(mixed $value): ?string
    {
        return $this->parseDate($value)?->format('Y-m-d');
    }

    private function dateTime(mixed $value): ?string
    {
        return $this->parseDate($value)?->format('Y-m-d H:i:s');
    }

    private function parseDate(mixed $value): ?DateTimeImmutable
    {
        $text = $this->string($value);
        if ($text === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($text, new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
