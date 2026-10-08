<?php

declare(strict_types=1);

return [
    'cotacao' => [
        'validade_minutos' => 15,
        'timeout_segundos' => 3,
    ],

    // Requisicoes por minuto, por cliente (cotar pode ser bem mais frequente que criar remessa).
    'limites' => [
        'cotacoes' => (int) env('LIMITE_COTACOES_MIN', 120),
        'remessas' => (int) env('LIMITE_REMESSAS_MIN', 30),
        'consultas' => (int) env('LIMITE_CONSULTAS_MIN', 300),
        'cadastros' => (int) env('LIMITE_CADASTROS_MIN', 60),
        // Vezes por minuto que um usuario pode ver credenciais de transportadora por inteiro.
        'revelar' => (int) env('LIMITE_REVELAR_MIN', 5),
        // Tentativas de login no portal (por e-mail + IP).
        'login' => (int) env('LIMITE_LOGIN_MIN', 5),
        // Tentativas de login com senha errada, por IP, dentro de uma janela (segundos).
        'auth_falhas_ip' => (int) env('LIMITE_AUTH_FALHAS_IP', 10),
        'auth_falhas_janela' => 300,
    ],

    // Limites dos dados enviados pelo cliente.
    'entrada' => [
        'max_volumes' => 10,
        'max_peso_gramas' => 30000,
        'max_dimensao_cm' => 105,
        'max_valor_declarado_centavos' => 10_000_000,
    ],

    // Token das rotas de administracao (/admin/clientes...), usadas por nos para cadastrar clientes.
    // Vazio ou com menos de 32 caracteres = administracao desligada. Gere com: openssl rand -hex 32
    'admin_token' => env('ADMIN_API_TOKEN'),

    // Disco onde ficam os PDFs das etiquetas (config/filesystems.php).
    'etiquetas_disco' => env('ETIQUETAS_DISK', 'etiquetas'),

    // Dias que guardamos o historico de chamadas as transportadoras.
    'retencao_chamadas_dias' => (int) env('RETENCAO_CHAMADAS_DIAS', 30),
];
