<?php

declare(strict_types=1);

/*
 * ============================================================
 * Furki Cycle — Exemplo de configuração do Callback Furk
 * ============================================================
 * Copie para CAMINHO_SEGURO_FORA_DO_WEBROOT/furk-webhook-config.php NO SERVIDOR
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
        'token_url' => 'https://URL-DE-TOKEN-FORNECIDA-PELA-FURK',
        'rebooking_url' => 'https://URL-DE-REBOOKING-FORNECIDA-PELA-FURK',
        'scope' => 'ESCOPO_FORNECIDO_PELA_FURK',
    ],
];
