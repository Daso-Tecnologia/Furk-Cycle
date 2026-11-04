<?php

declare(strict_types=1);

namespace FurkiCycle\DynamicStatus;

use DateTimeImmutable;
use PDO;
use RuntimeException;

final class DynamicStatusRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    /** @return array{tickets: int, segments: int} */
    public function deleteByIssueDateRange(string $initialIssueDate, string $finalIssueDate): array
    {
        $segments = $this->connection->prepare(
            'DELETE s FROM furkapi_bilhetes_segmentos s
             INNER JOIN furkapi_bilhetes_aereos a ON a.ticket_number = s.ticket_number
             WHERE a.issue_date BETWEEN :initial AND :final'
        );
        $segments->execute(['initial' => $initialIssueDate, 'final' => $finalIssueDate]);
        $deletedSegments = $segments->rowCount();

        $tickets = $this->connection->prepare(
            'DELETE FROM furkapi_bilhetes_aereos WHERE issue_date BETWEEN :initial AND :final'
        );
        $tickets->execute(['initial' => $initialIssueDate, 'final' => $finalIssueDate]);
        $deletedTickets = $tickets->rowCount();

        return ['tickets' => $deletedTickets, 'segments' => $deletedSegments];
    }

    /** @param array<int, array<string, mixed>> $tickets */
    public function insertTickets(array $tickets): int
    {
        if ($tickets === []) {
            return 0;
        }

        $columns = ['airline_code', 'ticket_number', 'locator_code', 'ndc_order_id', 'transaction_type', 'ticket_status', 'issue_date', 'updated_at'];
        $rows = [];
        $values = [];

        foreach ($tickets as $ticket) {
            $id = $this->ticketId($ticket);
            $number = $this->string($id['ticketNumber'] ?? null);
            if ($number === null) {
                throw new RuntimeException('A Furk retornou um bilhete sem número durante a gravação.');
            }
            $rows[] = [
                $this->string($id['airlineCode'] ?? null),
                $number,
                $this->string($id['locatorCode'] ?? null),
                $this->string($id['ndcOrderId'] ?? null),
                $this->string($id['transactionType'] ?? null),
                $this->string($ticket['ticketStatus'] ?? null),
                $this->date($id['issueDate'] ?? null),
                $this->dateTime($ticket['updatedAt'] ?? null),
            ];
            $values[] = '(?, ?, ?, ?, ?, ?, ?, ?)';
        }

        $sql = 'INSERT IGNORE INTO furkapi_bilhetes_aereos (' . implode(', ', $columns) . ') VALUES ' . implode(', ', $values);
        $statement = $this->connection->prepare($sql);
        $parameters = [];
        foreach ($rows as $row) {
            foreach ($row as $value) {
                $parameters[] = $value;
            }
        }
        $statement->execute($parameters);

        return count($rows);
    }

    /** @param array<int, array<string, mixed>> $tickets */
    public function insertSegmentsBatch(array $tickets): int
    {
        $columns = ['ticket_number', 'segment_order', 'fare_basis', 'fare_class', 'departure_date', 'arrival_date', 'origin', 'destination', 'flight_number', 'flight_airline_code', 'flight_status'];
        $rows = [];
        $values = [];

        foreach ($tickets as $ticket) {
            $id = $this->ticketId($ticket);
            $number = $this->string($id['ticketNumber'] ?? null);
            $segments = $ticket['segments'] ?? [];
            if ($number === null || !is_array($segments)) {
                continue;
            }
            foreach ($segments as $order => $segment) {
                if (!is_array($segment)) {
                    continue;
                }
                $rows[] = [
                    $number,
                    (int) $order,
                    $this->string($segment['fareBasis'] ?? null),
                    $this->string($segment['fareClass'] ?? null),
                    $this->dateTime($segment['departureDate'] ?? null),
                    $this->dateTime($segment['arrivalDate'] ?? null),
                    $this->string($segment['origin'] ?? null),
                    $this->string($segment['destination'] ?? null),
                    $this->string($segment['flightNumber'] ?? null),
                    $this->string($segment['flightAirlineCode'] ?? null),
                    $this->string($segment['flightStatus'] ?? null),
                ];
                $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            }
        }

        if ($rows === []) {
            return 0;
        }

        $sql = 'INSERT IGNORE INTO furkapi_bilhetes_segmentos (' . implode(', ', $columns) . ') VALUES ' . implode(', ', $values);
        $statement = $this->connection->prepare($sql);
        $parameters = [];
        foreach ($rows as $row) {
            foreach ($row as $value) {
                $parameters[] = $value;
            }
        }
        $statement->execute($parameters);

        return count($rows);
    }

    /** @param array<string, mixed> $ticket */
    public function insertTicket(array $ticket): void
    {
        $id = $this->ticketId($ticket);
        $number = $this->string($id['ticketNumber'] ?? null);
        if ($number === null) {
            throw new RuntimeException('A Furk retornou um bilhete sem número durante a gravação.');
        }

        $statement = $this->connection->prepare(
            'INSERT IGNORE INTO furkapi_bilhetes_aereos (
                airline_code, ticket_number, locator_code, ndc_order_id,
                transaction_type, ticket_status, issue_date, updated_at
            ) VALUES (
                :airline_code, :ticket_number, :locator_code, :ndc_order_id,
                :transaction_type, :ticket_status, :issue_date, :updated_at
            )'
        );
        $statement->execute([
            'airline_code' => $this->string($id['airlineCode'] ?? null),
            'ticket_number' => $number,
            'locator_code' => $this->string($id['locatorCode'] ?? null),
            'ndc_order_id' => $this->string($id['ndcOrderId'] ?? null),
            'transaction_type' => $this->string($id['transactionType'] ?? null),
            'ticket_status' => $this->string($ticket['ticketStatus'] ?? null),
            'issue_date' => $this->date($id['issueDate'] ?? null),
            'updated_at' => $this->dateTime($ticket['updatedAt'] ?? null),
        ]);
    }

    /** @param array<string, mixed> $ticket */
    public function insertSegments(array $ticket): int
    {
        $id = $this->ticketId($ticket);
        $number = $this->string($id['ticketNumber'] ?? null);
        $segments = $ticket['segments'] ?? [];
        if ($number === null || !is_array($segments)) {
            return 0;
        }

        $statement = $this->connection->prepare(
            'INSERT IGNORE INTO furkapi_bilhetes_segmentos (
                ticket_number, segment_order, fare_basis, fare_class,
                departure_date, arrival_date, origin, destination,
                flight_number, flight_airline_code, flight_status
            ) VALUES (
                :ticket_number, :segment_order, :fare_basis, :fare_class,
                :departure_date, :arrival_date, :origin, :destination,
                :flight_number, :flight_airline_code, :flight_status
            )'
        );

        $total = 0;
        foreach ($segments as $order => $segment) {
            if (!is_array($segment)) {
                continue;
            }
            $statement->execute([
                'ticket_number' => $number,
                'segment_order' => (int) $order,
                'fare_basis' => $this->string($segment['fareBasis'] ?? null),
                'fare_class' => $this->string($segment['fareClass'] ?? null),
                'departure_date' => $this->dateTime($segment['departureDate'] ?? null),
                'arrival_date' => $this->dateTime($segment['arrivalDate'] ?? null),
                'origin' => $this->string($segment['origin'] ?? null),
                'destination' => $this->string($segment['destination'] ?? null),
                'flight_number' => $this->string($segment['flightNumber'] ?? null),
                'flight_airline_code' => $this->string($segment['flightAirlineCode'] ?? null),
                'flight_status' => $this->string($segment['flightStatus'] ?? null),
            ]);
            $total++;
        }

        return $total;
    }

    /** @param array<string, mixed> $ticket */
    public function validate(array $ticket): void
    {
        $id = $this->ticketId($ticket);
        if ($this->string($id['ticketNumber'] ?? null) === null) {
            throw new RuntimeException('A Furk retornou um bilhete sem número. Nenhum dado local foi apagado.');
        }
        $this->date($id['issueDate'] ?? null);
        $this->dateTime($ticket['updatedAt'] ?? null);
        if (isset($ticket['segments']) && !is_array($ticket['segments'])) {
            throw new RuntimeException('A Furk retornou segmentos em formato inválido. Nenhum dado local foi apagado.');
        }
    }

    /** @return array<string, mixed> */
    private function ticketId(array $ticket): array
    {
        return is_array($ticket['ticketID'] ?? null) ? $ticket['ticketID'] : $ticket;
    }

    private function string(mixed $value): ?string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);
        return $value === '' || $value === "\0" ? null : $value;
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
            return new DateTimeImmutable($text);
        } catch (\Exception) {
            throw new RuntimeException("A Furk retornou uma data inválida: {$text}");
        }
    }
}