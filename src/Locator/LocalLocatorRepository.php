<?php

declare(strict_types=1);

namespace FurkiCycle\Locator;

use FurkiCycle\Config\Env;
use FurkiCycle\Database\MySqlConnection;
use PDO;

/**
 * Busca SOMENTE LEITURA de um localizador (PNR) no banco local do Furk Cycle
 * (tabelas já importadas: furkapi_bilhetes_aereos + furkapi_bilhetes_segmentos).
 *
 * A conexão é aberta sob demanda (a Consulta Localizador continua funcionando pela API da Furk
 * mesmo que o banco local esteja indisponível).
 */
final class LocalLocatorRepository
{
    private ?PDO $connection = null;

    public function __construct(private readonly Env $env)
    {
    }

    /**
     * Bilhetes cujo localizador (ou número de bilhete, que em parte dos registros da Furk é o próprio
     * localizador) é igual ao informado, do mais recente para o mais antigo, com seus trechos.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByLocator(string $locator, int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));
        $pdo = $this->connect();

        $statement = $pdo->prepare(
            'SELECT airline_code, ticket_number, locator_code, ndc_order_id, transaction_type,
                    issue_date, ticket_status, updated_at
             FROM furkapi_bilhetes_aereos
             WHERE locator_code = :locator OR ticket_number = :ticket
             ORDER BY issue_date DESC, id DESC
             LIMIT ' . $limit,
        );
        $statement->execute(['locator' => $locator, 'ticket' => $locator]);
        $tickets = $statement->fetchAll();
        if (!is_array($tickets) || $tickets === []) {
            return [];
        }

        $numbers = array_values(array_unique(array_map(
            static fn (array $row): string => (string) $row['ticket_number'],
            $tickets,
        )));
        $placeholders = implode(',', array_fill(0, count($numbers), '?'));
        $segmentStatement = $pdo->prepare(
            "SELECT ticket_number, segment_order, origin, destination, flight_airline_code, flight_number,
                    departure_date, arrival_date, flight_status, fare_class, fare_basis
             FROM furkapi_bilhetes_segmentos
             WHERE ticket_number IN ({$placeholders})
             ORDER BY ticket_number, segment_order",
        );
        $segmentStatement->execute($numbers);

        $byTicket = [];
        foreach ($segmentStatement->fetchAll() ?: [] as $segment) {
            $byTicket[(string) $segment['ticket_number']][] = $segment;
        }

        foreach ($tickets as &$ticket) {
            $ticket['segments'] = $byTicket[(string) $ticket['ticket_number']] ?? [];
        }
        unset($ticket);

        return $tickets;
    }

    private function connect(): PDO
    {
        if ($this->connection === null) {
            $this->connection = MySqlConnection::create($this->env);
            $this->connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        }

        return $this->connection;
    }
}
