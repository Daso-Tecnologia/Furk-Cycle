<?php

declare(strict_types=1);

namespace FurkiCycle\Refunds;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

final class RefundEligibleRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    /**
     * @param array<string, mixed> $item
     * @return 'inserted'|'updated'|'unchanged'
     */
    public function upsert(array $item, ?string $estimatedCredit = null): string
    {
        $record = $this->map($item, $estimatedCredit);

        $sql = <<<'SQL'
INSERT INTO furkapi_refunds_elegiveis (
    airline_code, ticket_number, locator_code, ndc_order_id, transaction_type,
    issue_date, estimated_refund_value, estimated_credit, refund_paid_value,
    refund_origin, refund_request_status, refund_payment_type,
    refund_billing_reference, refund_requested_date, refund_billing_date,
    updated_at, last_import
) VALUES (
    :airline_code, :ticket_number, :locator_code, :ndc_order_id, :transaction_type,
    :issue_date, :estimated_refund_value, :estimated_credit, :refund_paid_value,
    :refund_origin, :refund_request_status, :refund_payment_type,
    :refund_billing_reference, :refund_requested_date, :refund_billing_date,
    :updated_at, CURRENT_TIMESTAMP
)
ON DUPLICATE KEY UPDATE
    airline_code = VALUES(airline_code),
    locator_code = VALUES(locator_code),
    ndc_order_id = VALUES(ndc_order_id),
    transaction_type = VALUES(transaction_type),
    issue_date = VALUES(issue_date),
    estimated_refund_value = VALUES(estimated_refund_value),
    estimated_credit = VALUES(estimated_credit),
    refund_paid_value = VALUES(refund_paid_value),
    refund_origin = VALUES(refund_origin),
    refund_request_status = VALUES(refund_request_status),
    refund_payment_type = VALUES(refund_payment_type),
    refund_billing_reference = VALUES(refund_billing_reference),
    refund_requested_date = VALUES(refund_requested_date),
    refund_billing_date = VALUES(refund_billing_date),
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

    /** @param array<string, mixed> $item @return array<string, string|null> */
    private function map(array $item, ?string $estimatedCredit): array
    {
        $ticketId = $this->array($item['ticketID'] ?? ($item['ticket']['ticketID'] ?? null));

        $ticketNumber = $this->string($ticketId['ticketNumber'] ?? null);
        if ($ticketNumber === null) {
            throw new RuntimeException('A Furk retornou um reembolso sem número de bilhete; o registro não foi gravado.');
        }

        return [
            'airline_code' => $this->string($ticketId['airlineCode'] ?? null),
            'ticket_number' => $ticketNumber,
            'locator_code' => $this->string($ticketId['locatorCode'] ?? null),
            'ndc_order_id' => $this->string($ticketId['ndcOrderId'] ?? null),
            'transaction_type' => $this->string($ticketId['transactionType'] ?? null),
            'issue_date' => $this->date($ticketId['issueDate'] ?? null),
            'estimated_refund_value' => $this->string($item['estimatedRefundValue'] ?? null),
            'estimated_credit' => $this->string($estimatedCredit),
            'refund_paid_value' => $this->string($item['refundPaidValue'] ?? null),
            'refund_origin' => $this->string($item['refundOrigin'] ?? null),
            'refund_request_status' => $this->string($item['refundRequestStatus'] ?? null),
            'refund_payment_type' => $this->string($item['refundPaymentType'] ?? null),
            'refund_billing_reference' => $this->string($item['refundBillingReference'] ?? null),
            'refund_requested_date' => $this->date($item['refundRequestedDate'] ?? null),
            'refund_billing_date' => $this->date($item['refundBillingDate'] ?? null),
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
        if ($value === null) {
            return null;
        }

        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
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
}
