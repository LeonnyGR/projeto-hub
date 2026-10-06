<?php

declare(strict_types=1);

return [
    'cotacao' => [
        'validade_minutos' => 15,
        'timeout_segundos' => 3,
    ],

    // Requisicoes por minuto, por seller (cotar pode ser bem mais frequente que criar remessa).
    // CEP e cadastros nao tem seller: contam por cliente da API.
    'limites' => [
        'cotacoes' => (int) env('LIMITE_COTACOES_MIN', 120),
        'remessas' => (int) env('LIMITE_REMESSAS_MIN', 30),
        'consultas' => (int) env('LIMITE_CONSULTAS_MIN', 300),
        'cadastros' => (int) env('LIMITE_CADASTROS_MIN', 60),
        // Tentativas de login com chave errada, por IP, dentro de uma janela (segundos).
        'auth_falhas_ip' => (int) env('LIMITE_AUTH_FALHAS_IP', 10),
        'auth_falhas_janela' => 300,
    ],

    'api_key' => [
        'max_ativas' => 2,
        'tolerancia_horas' => 24,
    ],

    // Limites dos dados enviados pelo seller.
    'entrada' => [
        'max_volumes' => 10,
        'max_peso_gramas' => 30000,
        'max_dimensao_cm' => 105,
        'max_valor_declarado_centavos' => 10_000_000,
    ],

    // Disco onde ficam os PDFs das etiquetas (config/filesystems.php).
    'etiquetas_disco' => env('ETIQUETAS_DISK', 'etiquetas'),

    // Dias que guardamos o historico de chamadas as transportadoras.
    'retencao_chamadas_dias' => (int) env('RETENCAO_CHAMADAS_DIAS', 30),
];
