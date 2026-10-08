<?php

declare(strict_types=1);

// API server-to-server: nenhuma origem de navegador e permitida por padrao.
// Para liberar uma origem (ex.: painel interno) informe CORS_ALLOWED_ORIGINS=https://painel.exemplo.com
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Authorization', 'Content-Type', 'Idempotency-Key', 'Accept'],
    'exposed_headers' => ['X-Request-Id', 'Retry-After'],
    'max_age' => 600,
    // true = o navegador pode enviar o cookie de sessao do portal (precisa de CORS_ALLOWED_ORIGINS com a origem exata do portal).
    'supports_credentials' => (bool) env('CORS_SUPPORTS_CREDENTIALS', false),
];
