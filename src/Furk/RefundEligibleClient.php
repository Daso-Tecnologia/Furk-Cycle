<?php

declare(strict_types=1);

namespace FurkiCycle\Furk;

use FurkiCycle\Config\Env;
use RuntimeException;

final class RefundEligibleClient
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
            'client_id' => $this->env->required('FURK_REFUND_CLIENT_ID'),
            'client_secret' => $this->env->required('FURK_REFUND_CLIENT_SECRET'),
            'scope' => $this->env->required('FURK_REFUND_SCOPE'),
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
            throw new RuntimeException('A Furk não retornou um access token de reembolso válido.');
        }

        $expiresIn = $decoded['expires_in'] ?? 3600;
        if (!is_int($expiresIn) && !is_numeric($expiresIn)) {
            $expiresIn = 3600;
        }

        $this->token = $accessToken;
        $this->tokenExpiresAt = time() + max(60, (int) floor(((int) $expiresIn) * 0.85));

        return $accessToken;
    }

    /** @return array<string, mixed> */
    public function list(string $initialIssueDate, string $finalIssueDate, int $page, int $size): array
    {
        $query = http_build_query([
            'initialIssueDate' => $initialIssueDate,
            'finalIssueDate' => $finalIssueDate,
            'page' => $page,
            'size' => $size,
        ]);

        $response = $this->request(
            url: $this->env->required('FURK_REFUND_URL') . '?' . $query,
            method: 'GET',
            headers: [
                'Accept: application/json',
                'Authorization: Bearer ' . $this->accessToken(),
                'x-api-key: ' . $this->env->required('FURK_REFUND_API_KEY'),
            ],
        );

        $decoded = $this->decodeJson($response['body'], 'Furk');

        if (($decoded['success'] ?? null) === 'NOK') {
            throw new RuntimeException('A Furk informou uma falha ao consultar os reembolsos elegíveis. Tente novamente.');
        }

        return $decoded;
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

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException($this->friendlyError($status));
        }

        return ['status' => $status, 'body' => $result];
    }

    private function friendlyError(int $status): string
    {
        return match ($status) {
            400 => "A Furk rejeitou a solicitação (HTTP 400). Verifique se os dados enviados estão corretos e se o escopo da integração está habilitado.",
            401 => 'Falha de autenticação com a Furk (HTTP 401). As credenciais de integração podem estar incorretas ou expiradas.',
            403 => 'Acesso negado pela Furk (HTTP 403). Verifique se a integração tem permissão para esta operação.',
            404 => 'Recurso não encontrado na Furk (HTTP 404). Verifique se o endereço da API está correto.',
            408 => 'A Furk demorou para responder (HTTP 408). Tente novamente em alguns instantes.',
            429 => 'A Furk limitou o número de solicitações (HTTP 429). Aguarde alguns instantes e tente novamente.',
            500 => 'A Furk apresentou um erro interno (HTTP 500). Tente novamente mais tarde.',
            502 => 'A Furk está temporariamente indisponível (HTTP 502). Tente novamente em alguns instantes.',
            503 => 'A Furk está em manutenção (HTTP 503). Tente novamente mais tarde.',
            504 => 'A Furk demorou para responder (HTTP 504). Tente novamente em alguns instantes.',
            default => "Não foi possível concluir a comunicação com a Furk (HTTP {$status}). Tente novamente.",
        };
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
