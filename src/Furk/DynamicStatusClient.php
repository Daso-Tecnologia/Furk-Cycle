<?php

declare(strict_types=1);

namespace FurkiCycle\Furk;

use FurkiCycle\Config\Env;
use RuntimeException;

final class DynamicStatusClient
{
    public function __construct(
        private readonly Env $env,
        private readonly OAuthClient $oauth,
    ) {
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

        $response = $this->oauth->request(
            url: $this->env->required('FURK_DYNAMIC_STATUS_URL') . '?' . $query,
            method: 'GET',
            headers: [
                'Accept: application/json',
                'Authorization: Bearer ' . $this->oauth->accessToken(),
                'x-api-key: ' . $this->env->required('FURK_API_KEY'),
            ],
        );

        try {
            $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('A Furk retornou uma resposta em formato inesperado. Tente novamente.');
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('A Furk retornou dados em um formato inesperado. Tente novamente.');
        }

        if (($decoded['success'] ?? null) === 'NOK') {
            throw new RuntimeException('A Furk informou uma falha ao consultar o status dinâmico. Tente novamente.');
        }

        return $decoded;
    }
}