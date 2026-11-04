<?php

declare(strict_types=1);

namespace FurkiCycle\Rebooking;

use FurkiCycle\Config\Env;
use FurkiCycle\Furk\RebookingClient;
use PDOException;
use RuntimeException;
use Throwable;

final class RebookingSendService
{
    private const POST_DELAY_MS = 600;

    public function __construct(
        private readonly Env $env,
        private readonly RebookingGateRepository $gate,
        private readonly RebookingControlRepository $control,
        private readonly RebookingClient $client,
    ) {
    }

    public function environment(): string
    {
        return $this->env->optional('FURK_ENVIRONMENT', 'production') ?? 'production';
    }

    /** @return array<string, mixed> */
    public function preview(string $nota): array
    {
        $row = $this->findRow($nota);
        if ($row === null) {
            throw new RuntimeException("A nota {$nota} não foi encontrada no Gate.");
        }
        $payload = $this->buildPayload($row);
        return ['nota' => $nota, 'action' => 'PREVIEW_READY', 'payload' => $payload['payload']];
    }

    /** @return array<string, mixed> */
    public function process(array $notas, bool $send): array
    {
        $summary = ['read' => 0, 'skippedAlreadyControlled' => 0, 'previewed' => 0, 'sent' => 0, 'rejected' => 0, 'networkError' => 0, 'dataError' => 0];
        $results = [];
        $token = null;
        $posts = 0;

        foreach ($notas as $nota) {
            $summary['read']++;

            // Já existe controle para esta nota? Cobre linhas que saíram da vista do
            // GATE e evita POST/erro desnecessário ao "marcar todos".
            $controlled = $this->control->findByNota($this->environment(), $nota);
            if ($controlled !== null) {
                $summary['skippedAlreadyControlled']++;
                $results[] = [
                    'nota' => $nota,
                    'action' => 'SKIPPED_ALREADY_CONTROLLED',
                    'controlId' => (int) $controlled['id'],
                    'status' => $controlled['processing_status'],
                ];
                continue;
            }

            $row = $this->findRow($nota);
            if ($row === null) {
                $summary['dataError']++;
                $results[] = ['nota' => $nota, 'action' => 'NOT_FOUND_IN_GATE'];
                continue;
            }

            $key = $this->idempotencyKey($row);
            $existing = $this->control->existingControl($this->environment(), $key, $nota);
            if ($existing !== null) {
                $summary['skippedAlreadyControlled']++;
                $results[] = [
                    'nota' => $nota,
                    'action' => 'SKIPPED_ALREADY_CONTROLLED',
                    'controlId' => (int) $existing['id'],
                    'status' => $existing['processing_status'],
                ];
                continue;
            }

            $controlId = null;
            try {
                $built = $this->buildPayload($row);
                $payload = $built['payload'];
                if (!$send) {
                    $summary['previewed']++;
                    $results[] = ['nota' => $nota, 'action' => 'PREVIEW_READY', 'segments' => $built['segments'], 'payload' => $payload];
                    continue;
                }

                $token ??= $this->client->accessToken();
                $controlId = $this->createPending($row, $payload, $built['segments']);
                if ($posts > 0) {
                    usleep(self::POST_DELAY_MS * 1000);
                }
                $posts++;

                $response = $this->client->create($payload);
                $body = $this->decodeBody($response['body']);
                $rebooking = is_array($body['data']['rebooking'] ?? null) ? $body['data']['rebooking'] : [];
                $requested = is_array($body['data']['requestedItem'] ?? null) ? $body['data']['requestedItem'] : [];
                $accepted = $response['status'] >= 200 && $response['status'] < 300;

                $this->control->updateAfterPost([
                    'furk_rebooking_id' => $body['uid'] ?? $rebooking['id'] ?? null,
                    'furk_status' => $body['status'] ?? $rebooking['status'] ?? null,
                    'issue_channel_returned' => $requested['issueChannel'] ?? null,
                    'http_status' => $response['status'],
                    'processing_status' => $accepted ? 'SENT' : 'REJECTED',
                    'error_message' => $accepted ? null : 'Furk retornou HTTP ' . $response['status'],
                    'response_payload' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ], $controlId);

                $summary[$accepted ? 'sent' : 'rejected']++;
                $results[] = [
                    'nota' => $nota,
                    'controlId' => $controlId,
                    'action' => $accepted ? 'SENT' : 'REJECTED',
                    'httpStatus' => $response['status'],
                    'furkRebookingId' => $body['uid'] ?? $rebooking['id'] ?? null,
                    'furkStatus' => $body['status'] ?? $rebooking['status'] ?? null,
                ];
            } catch (PDOException $error) {
                if ($error->getCode() === '23000') {
                    $summary['skippedAlreadyControlled']++;
                    $results[] = ['nota' => $nota, 'action' => 'SKIPPED_CONCURRENT_CONTROL'];
                    continue;
                }
                // O detalhe do banco fica no log e no registro de controle; o navegador recebe
                // apenas uma mensagem genérica.
                error_log('rebooking-send nota=' . $nota . ': ' . $error->getMessage());
                $this->markFailed($controlId, $nota, $error->getMessage(), $summary, $results, 'Falha ao gravar o controle do envio. Consulte os logs do servidor.');
            } catch (Throwable $error) {
                $this->markFailed($controlId, $nota, $error->getMessage(), $summary, $results);
            }
        }

        return [
            'success' => $summary['networkError'] === 0 && $summary['dataError'] === 0,
            'mode' => $send ? 'SEND' : 'PREVIEW',
            'summary' => $summary,
            'results' => $results,
        ];
    }

    private function findRow(string $nota): ?array
    {
        return $this->gate->findByNota($nota);
    }

    /**
     * Registra a falha de um envio. Se a linha PENDING já foi criada, atualiza-a
     * para ERROR com o motivo, para que a operação seja rastreável (o legado fazia
     * o mesmo). Não remove a linha: ela continua bloqueando reenvio automático.
     *
     * @param array<string, mixed> $summary
     * @param array<int, array<string, mixed>> $results
     */
    private function markFailed(?int $controlId, string $nota, string $message, array &$summary, array &$results, ?string $clientMessage = null): void
    {
        $summary['dataError']++;
        $shown = $clientMessage ?? $message;
        if ($controlId !== null) {
            try {
                $this->control->markError(
                    $controlId,
                    'Falha no envio: ' . $message . ' — não reenviar automaticamente; conciliar com a Furk.',
                );
            } catch (Throwable) {
                // não deixa a falha de gravação esconder o erro original
            }
            $results[] = ['nota' => $nota, 'controlId' => $controlId, 'action' => 'ERROR_SEND', 'error' => $shown];
            return;
        }
        $results[] = ['nota' => $nota, 'action' => 'SKIPPED_DATA_ERROR', 'error' => $shown];
    }

    private function createPending(array $row, array $payload, int $segments): int
    {
        return $this->control->createPending([
            'environment' => $this->environment(),
            'fil_agt' => $this->value($row, 'fil_agt'),
            'nota' => $this->value($row, 'nota'),
            'num_file' => $this->value($row, 'num_file'),
            'localizador' => $this->value($row, 'localizador'),
            'tkt_num' => $this->value($row, 'tkt_num'),
            'airline_code' => $this->value($row, 'cod_for'),
            'iata_code' => $this->env->required('FURK_IATA_CODE'),
            'issue_channel_sent' => $this->env->required('FURK_ISSUE_CHANNEL'),
            'request_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'idempotency_key' => $this->idempotencyKey($row),
            'field_sources' => json_encode(['gate' => 'dbo.Rebooking', 'journeys' => 'wgitiner', 'issueChannel' => 'configuração Scriptcase'], JSON_THROW_ON_ERROR),
            'validation_notes' => json_encode(['control_message' => 'PENDING criado antes do POST.', 'batch_mode' => true, 'segments_found' => $segments], JSON_THROW_ON_ERROR),
        ]);
    }

    /** @return array{payload: array, segments: int} */
    private function buildPayload(array $row): array
    {
        $filAgt = $this->value($row, 'fil_agt');
        $nota = $this->value($row, 'nota');
        $numFile = $this->value($row, 'num_file');
        $itineraryRows = $this->gate->itinerary($filAgt ?? '', $nota ?? '', $numFile);

        $segments = [];
        $metadata = [];
        $seen = [];
        foreach ($itineraryRows as $itineraryRow) {
            if ($metadata === []) {
                $metadata = [
                    'fareBasis' => rtrim((string) ($this->value($itineraryRow, 'btar_iti') ?? ''), ';'),
                    'fareClass' => $this->value($itineraryRow, 'cl_iti'),
                ];
            }
            $number = $this->value($itineraryRow, 'num_trecho');
            if ($number === null || isset($seen[$number])) {
                continue;
            }
            $segment = [
                'segmentNumber' => (int) $number,
                'origin' => $this->value($itineraryRow, 'ori_iti'),
                'destination' => $this->value($itineraryRow, 'dest_iti'),
                'departureTime' => $this->dateTimeUtc($this->value($itineraryRow, 'data'), $this->value($itineraryRow, 'part_iti')),
                'arrivalTime' => $this->dateTimeUtc($this->value($itineraryRow, 'dt_cheg'), $this->value($itineraryRow, 'cheg_iti')),
                'operatingAirlineCode' => $this->value($itineraryRow, 'cia_iti'),
                'flightNumber' => $this->value($itineraryRow, 'voo_iti'),
            ];
            if (!in_array(null, $segment, true)) {
                $segments[] = $segment;
                $seen[$number] = true;
            }
        }

        if ($segments === []) {
            throw new RuntimeException('Nenhum segmento válido foi encontrado em wgitiner.');
        }

        [$firstName, $lastName] = $this->passengerName($this->value($row, 'nom_pax'));
        if ($firstName === '' || $lastName === '') {
            throw new RuntimeException('O nome do passageiro não está completo.');
        }

        $ticket = $this->value($row, 'tkt_num');
        $passenger = ['index' => 1, 'firstName' => $firstName, 'lastName' => $lastName];
        if ($ticket !== null) {
            $passenger['tickets'] = [['ticketNumber' => $ticket]];
        }

        $journeys = [];
        $currentDate = null;
        $journey = null;
        foreach ($segments as $segment) {
            $departureDate = substr($segment['departureTime'], 0, 10);
            if ($departureDate !== $currentDate) {
                if ($journey !== null) {
                    $journeys[] = $journey;
                }
                $journey = [
                    'index' => count($journeys) + 1,
                    'fareBasis' => $metadata['fareBasis'] ?? null,
                    'fareClass' => $metadata['fareClass'] ?? null,
                    'origin' => $segment['origin'],
                    'destination' => $segment['destination'],
                    'segments' => [],
                ];
                $currentDate = $departureDate;
            }
            $segment['segmentNumber'] = count($journey['segments']) + 1;
            $journey['segments'][] = $segment;
            $journey['destination'] = $segment['destination'];
        }
        if ($journey !== null) {
            $journeys[] = $journey;
        }

        $tax = 0.0;
        foreach (['taxa_1', 'taxa_2', 'taxa_serv_br', 'room_tax'] as $field) {
            $tax += $this->decimal($row, $field) ?? 0.0;
        }

        $payload = [
            'airlineCode' => $this->value($row, 'cod_for'),
            'locatorCode' => $this->value($row, 'localizador'),
            'issueChannel' => $this->env->required('FURK_ISSUE_CHANNEL'),
            'iataCode' => $this->env->required('FURK_IATA_CODE'),
            'passengers' => [$passenger],
            'journeys' => $journeys,
            'totalFareValue' => $this->decimal($row, 'tarifa_br'),
            'totalTaxValue' => $tax,
            'totalTaxDuValue' => $this->decimal($row, 'trans_fee'),
            'totalValue' => $this->decimal($row, 'Total'),
            'saleValue' => $this->decimal($row, 'receb_cli'),
            'issueDatetime' => $this->dateTimeUtc($this->value($row, 'data_nota')),
        ];

        $payload = array_filter($payload, static fn (mixed $item): bool => $item !== null && $item !== '');
        return ['payload' => $payload, 'segments' => count($segments)];
    }

    private function idempotencyKey(array $row): string
    {
        return implode(':', [
            $this->environment(),
            $this->value($row, 'fil_agt') ?? '',
            $this->value($row, 'nota') ?? '',
            $this->value($row, 'num_file') ?? '',
            $this->value($row, 'tkt_num') ?? '',
        ]);
    }

    /** @return array<string, mixed> */
    private function decodeBody(string $body): array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['raw_response' => $body];
        }
        return is_array($decoded) ? $decoded : ['raw_response' => $body];
    }

    private function value(array $row, string $field): ?string
    {
        $item = $row[$field] ?? null;
        if (!is_string($item) && !is_numeric($item)) {
            return null;
        }
        $text = trim((string) $item);
        return $text === '' || $text === "\0" ? null : $text;
    }

    private function decimal(array $row, string $field): ?float
    {
        $item = $this->value($row, $field);
        if ($item === null) {
            return null;
        }
        if (str_contains($item, ',')) {
            $item = str_replace('.', '', $item);
            $item = str_replace(',', '.', $item);
        }
        return is_numeric($item) ? (float) $item : null;
    }

    private function dateTimeUtc(?string $date, ?string $time = null): ?string
    {
        if ($date === null) {
            return null;
        }
        $source = trim($date);
        if ($time !== null && trim($time) !== '') {
            $source = substr($source, 0, 10) . ' ' . trim($time);
        }
        try {
            return (new \DateTimeImmutable($source, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{string, string} */
    private function passengerName(?string $name): array
    {
        $name = trim((string) $name);
        if ($name === '') {
            return ['', ''];
        }
        if (str_contains($name, '/')) {
            [$lastName, $firstName] = explode('/', $name, 2);
            return [trim($firstName), trim($lastName)];
        }
        $parts = preg_split('/\s+/', $name) ?: [];
        $firstName = array_shift($parts) ?? '';
        return [$firstName, implode(' ', $parts)];
    }
}