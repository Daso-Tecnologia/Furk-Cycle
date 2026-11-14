<?php

declare(strict_types=1);

namespace FurkiCycle\Furk;

use FurkiCycle\Config\Env;
use RuntimeException;

final class RebookingClient
{
    private ?string $token = null;
    private int $tokenExpiresAt = 0;

    public function __construct(
        private readonly Env $env,
    ) {
    }

    public function accessToken(): string
    {
        if ($this->token !== null && time() < $this->tokenExpiresAt) {
            return $this->token;
        }

        $body = http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $this->env->required('FURK_REBOOKING_CLIENT_ID'),
            'client_secret' => $this->env->required('FURK_REBOOKING_CLIENT_SECRET'),
            'scope' => $this->env->required('FURK_REBOOKING_SCOPE'),
        ]);

        $response = $this->request(
            url: $this->env->required('FURK_TOKEN_URL'),
            method: 'POST',
            headers: ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
            body: $body,
        );

        $decoded = $this->decodeJson($response['body'], 'OAuth da Furk');
        $accessToken = $decoded['access_token'] ?? null;
        if (!is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException('A Furk não retornou um access token de rebooking válido.');
        }

        $expiresIn = $decoded['expires_in'] ?? 3600;
        if (!is_int($expiresIn) && !is_numeric($expiresIn)) {
            $expiresIn = 3600;
        }

        $this->token = $accessToken;
        $this->tokenExpiresAt = time() + max(60, (int) floor(((int) $expiresIn) * 0.85));
        return $accessToken;
    }

    /** @return array{status: int, body: string} */
    public function create(array $payload): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return $this->request(
            url: $this->env->required('FURK_REBOOKING_URL'),
            method: 'POST',
            headers: [
                'Authorization: Bearer ' . $this->accessToken(),
                'Content-Type: application/json',
                'Accept: application/json',
                'x-api-key: ' . $this->env->required('FURK_REBOOKING_API_KEY'),
            ],
            body: $json,
        );
    }

    /** @return array<string, mixed> */
    public function webhookStatus(): array
    {
        $url = rtrim($this->env->required('FURK_REBOOKING_URL'), '/') . '/webhook';
        $response = $this->request(
            url: $url,
            method: 'GET',
            headers: [
                'Authorization: Bearer ' . $this->accessToken(),
                'Accept: application/json',
                'x-api-key: ' . $this->env->required('FURK_REBOOKING_API_KEY'),
            ],
        );

        return $this->decodeJson($response['body'], 'Consulta do webhook da Furk');
    }

    /** @return array{status: int, body: string} */
    private function request(string $url, string $method, array $headers, ?string $body = null): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Não foi possível iniciar a conexão com a Furk.');
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => $this->env->int('FURK_CONNECT_TIMEOUT_SECONDS', 15),
            CURLOPT_TIMEOUT => $this->env->int('FURK_REQUEST_TIMEOUT_SECONDS', 60),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        $method = strtoupper($method);
        if ($method === 'GET') {
            $options[CURLOPT_HTTPGET] = true;
        } else {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
            if ($body !== null) {
                $options[CURLOPT_POSTFIELDS] = $body;
            }
        }

        curl_setopt_array($handle, $options);

        $result = curl_exec($handle);
        $error = curl_error($handle);
        $errorNumber = curl_errno($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        if ($result === false) {
            throw new RuntimeException(
                'Falha de conexão com a Furk (cURL ' . $errorNumber . ': ' . ($error !== '' ? $error : 'sem detalhe') . '). Tente novamente; se persistir, concilie com a Furk.',
            );
        }

        return ['status' => $status, 'body' => $result];
    }

    /** @return array<string, mixed> */
    private function decodeJson(string $body, string $context): array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException("{$context} retornou uma resposta que não é JSON.");
        }

        if (!is_array($decoded)) {
            throw new RuntimeException("{$context} retornou um JSON inesperado.");
        }

        return $decoded;
    }
}