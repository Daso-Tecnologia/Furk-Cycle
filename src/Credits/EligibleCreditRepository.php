<?php

declare(strict_types=1);

namespace FurkiCycle\Credits;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

final class EligibleCreditRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function deleteByIssueDateRange(string $initialIssueDate, string $finalIssueDate): int
    {
        $statement = $this->connection->prepare(
            'DELETE FROM furkapi_creditos_elegiveis WHERE issue_date BETWEEN :initial AND :final'
        );
        $statement->execute(['initial' => $initialIssueDate, 'final' => $finalIssueDate]);

        return $statement->rowCount();
    }

    /** @param array<string, mixed> $item @return 'inserted'|'updated'|'unchanged' */
    public function upsert(array $item): string
    {
        $record = $this->map($item);

        $sql = <<<'SQL'
INSERT INTO furkapi_creditos_elegiveis (
    airline_code, ticket_number, locator_code, ndc_order_id, transaction_type,
    issue_date, passenger_name, passenger_first_name, passenger_last_name,
    passenger_type, fare_value, boarding_fee_value, tax_du_value, taxes_value,
    incentive_value, commission_value, total_value, estimated_credit,
    estimated_change_fee, applied_credit, paid_amount, applied_change_fee,
    expiration_date, reissued_ticket, updated_at, last_import
) VALUES (
    :airline_code, :ticket_number, :locator_code, :ndc_order_id, :transaction_type,
    :issue_date, :passenger_name, :passenger_first_name, :passenger_last_name,
    :passenger_type, :fare_value, :boarding_fee_value, :tax_du_value, :taxes_value,
    :incentive_value, :commission_value, :total_value, :estimated_credit,
    :estimated_change_fee, :applied_credit, :paid_amount, :applied_change_fee,
    :expiration_date, :reissued_ticket, :updated_at, CURRENT_TIMESTAMP
)
ON DUPLICATE KEY UPDATE
    airline_code = VALUES(airline_code),
    locator_code = VALUES(locator_code),
    ndc_order_id = VALUES(ndc_order_id),
    transaction_type = VALUES(transaction_type),
    issue_date = VALUES(issue_date),
    passenger_name = VALUES(passenger_name),
    passenger_first_name = VALUES(passenger_first_name),
    passenger_last_name = VALUES(passenger_last_name),
    passenger_type = VALUES(passenger_type),
    fare_value = VALUES(fare_value),
    boarding_fee_value = VALUES(boarding_fee_value),
    tax_du_value = VALUES(tax_du_value),
    taxes_value = VALUES(taxes_value),
    incentive_value = VALUES(incentive_value),
    commission_value = VALUES(commission_value),
    total_value = VALUES(total_value),
    estimated_credit = VALUES(estimated_credit),
    estimated_change_fee = VALUES(estimated_change_fee),
    applied_credit = VALUES(applied_credit),
    paid_amount = VALUES(paid_amount),
    applied_change_fee = VALUES(applied_change_fee),
    expiration_date = VALUES(expiration_date),
    reissued_ticket = VALUES(reissued_ticket),
    updated_at = VALUES(updated_at),
    last_import = CURRENT_TIMESTAMP
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

    /** @param array<string, mixed> $item @return array<string, string|float|null> */
    private function map(array $item): array
    {
        $ticket = $this->array($item['ticket'] ?? null);
        $ticketId = $this->array($ticket['ticketID'] ?? null);
        $passenger = $this->array($ticket['passenger'] ?? null);

        $ticketNumber = $this->string($ticketId['ticketNumber'] ?? null);
        if ($ticketNumber === null) {
            throw new RuntimeException('A Furk retornou um crédito sem número de bilhete; o registro não foi gravado.');
        }

        $firstName = $this->string($passenger['passengerFirstName'] ?? null);
        $lastName = $this->string($passenger['passengerLastName'] ?? null);
        $reissuedTicket = array_key_exists('reissuedTicket', $item) && $item['reissuedTicket'] !== null
            ? json_encode($item['reissuedTicket'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
            : null;

        return [
            'airline_code' => $this->string($ticketId['airlineCode'] ?? null),
            'ticket_number' => $ticketNumber,
            'locator_code' => $this->string($ticketId['locatorCode'] ?? null),
            'ndc_order_id' => $this->string($ticketId['ndcOrderId'] ?? null),
            'transaction_type' => $this->string($ticketId['transactionType'] ?? null),
            'issue_date' => $this->date($ticketId['issueDate'] ?? null),
            'passenger_name' => $this->fullName($firstName, $lastName),
            'passenger_first_name' => $firstName,
            'passenger_last_name' => $lastName,
            'passenger_type' => $this->string($passenger['passengerType'] ?? null),
            'fare_value' => $this->decimal($ticket['fareValue'] ?? null),
            'boarding_fee_value' => $this->decimal($ticket['boardingFeeValue'] ?? null),
            'tax_du_value' => $this->decimal($ticket['taxDuValue'] ?? null),
            'taxes_value' => $this->decimal($ticket['taxesValue'] ?? null),
            'incentive_value' => $this->decimal($ticket['incentiveValue'] ?? null),
            'commission_value' => $this->decimal($ticket['commissionValue'] ?? null),
            'total_value' => $this->decimal($ticket['totalValue'] ?? null),
            'estimated_credit' => $this->decimal($item['estimatedCredit'] ?? null),
            'estimated_change_fee' => $this->decimal($item['estimatedChangeFee'] ?? null),
            'applied_credit' => $this->decimal($item['appliedCredit'] ?? null),
            'paid_amount' => $this->decimal($item['paidAmount'] ?? null),
            'applied_change_fee' => $this->decimal($item['appliedChangeFee'] ?? null),
            'expiration_date' => $this->date($item['expirationDate'] ?? null),
            'reissued_ticket' => $reissuedTicket,
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
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function decimal(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function date(mixed $value): ?string
    {
        $dateTime = $this->parseDate($value);
        return $dateTime?->format('Y-m-d');
    }

    private function dateTime(mixed $value): ?string
    {
        $dateTime = $this->parseDate($value);
        return $dateTime?->format('Y-m-d H:i:s');
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
            throw new RuntimeException("A Furk retornou uma data inválida: {$text}");
        }
    }

    private function fullName(?string $firstName, ?string $lastName): ?string
    {
        $name = trim(implode(' ', array_filter([$firstName, $lastName])));
        return $name === '' ? null : $name;
    }
}
