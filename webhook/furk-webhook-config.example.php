<?php

declare(strict_types=1);

/*
 * ============================================================
 * Furki Cycle — Exemplo de configuração do Callback Furk
 * ============================================================
 * Copie para C:/xampp/secure/furk-webhook-config.php NO SERVIDOR
 * e substitua os valores ilustrativos pelos reais.
 *
 * NUNCA versione o arquivo real (furk-webhook-config.php).
 * NUNCA coloque este arquivo em pasta pública / htdocs.
 * Salve o real em UTF-8 SEM BOM, sem espaços dentro dos valores.
 * ============================================================
 */

return [
    'mysql' => [
        'host' => 'host-do-mysql',
        'port' => 3306,
        'database' => 'banco_de_controle',
        'username' => 'usuario_mysql',
        'password' => 'senha_mysql',
        'charset' => 'utf8mb4',
    ],
    'furk' => [
        'client_id' => 'preencher_no_servidor',
        'client_secret' => 'preencher_no_servidor',
        'api_key' => 'preencher_no_servidor',
        'webhook_token' => 'preencher_no_servidor',
        'token_url' => 'https://auth-api.furk.tech/oauth2/token',
        'rebooking_url' => 'https://rebooking-api.furk.tech/v1/rebooking',
        'scope' => 'furkcycle-api-modules/rebooking',
    ],
];
