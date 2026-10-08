<?php

declare(strict_types=1);

// As URLs das transportadoras vem SOMENTE daqui (nunca de dados enviados pelo cliente).
return [
    // mock = respostas simuladas, sem chamar ninguem (proibido com APP_ENV=production)
    // real = chama as transportadoras de verdade
    'modo' => env('TRANSPORTADORAS_MODO', 'mock'),

    // Tempo maximo de cada chamada, em segundos.
    'timeout_segundos' => 3,
    'conexao_timeout_segundos' => 2,

    // Divisor usado no peso cubado (comprimento x largura x altura em cm / divisor = kg).
    // Nao esta nos PDFs: confirmar com a Jadlog.
    'divisor_cubagem' => 6000,

    // Consulta de CEP (servico publico, sem conta nem credencial).
    'viacep' => [
        'url' => env('VIACEP_URL', 'https://viacep.com.br'),
        // real = consulta o ViaCEP de verdade (padrao, ate em desenvolvimento: nao custa nada nem tem conta).
        // mock = endereco de exemplo fixo; so para os testes automaticos (sem internet).
        'modo' => env('VIACEP_MODO', 'real'),
        // Endereco de um CEP quase nunca muda: guardamos a resposta para nao consultar de novo.
        'cache_dias' => 7,
    ],

    'jadlog' => [
        'url_embarcador' => env('JADLOG_URL_EMBARCADOR', 'https://www.jadlog.com.br/embarcador/api'),
        'url_rastreio' => env('JADLOG_URL_RASTREIO', 'https://prd-traffic.jadlogtech.com.br/embarcador/api'),
        'url_pontos' => env('JADLOG_URL_PONTOS', 'https://www.jadlog.com.br/pickup/address'),
    ],

    'correios' => [
        // Homologacao: https://apihom.correios.com.br | Producao: https://api.correios.com.br
        'url' => env('CORREIOS_URL', 'https://apihom.correios.com.br'),
    ],
];
