<?php

declare(strict_types=1);

// Catalogo de modulos e aplicacoes do portal (igual para todos os clientes).
// A fonte da verdade e este arquivo; ele vai para o banco com `php artisan permissoes:sincronizar`
// (a migration ja roda isso na primeira vez). Depois de mexer aqui, rode o comando.
//
//  - 'caminho' e a rota da pagina no front: tem que ser IDENTICO ao path que o front implementa;
//  - 'acoes' sao as acoes que a aplicacao aceita (o admin marca quais cada operador pode fazer).
//    Vocabulario: ver, criar, editar, excluir, etiqueta, despachar, cancelar.
return [
    'modulos' => [
        'COT' => ['nome' => 'Cotação', 'icone' => 'bi bi-calculator', 'aplicacoes' => [
            'COT_01' => ['nome' => 'Cotação de frete', 'caminho' => 'cotacao/cotar', 'acoes' => ['criar']],
            'COT_02' => ['nome' => 'Lista de Cotações', 'caminho' => 'cotacao/listar', 'acoes' => ['ver']],
        ]],
        'OPE' => ['nome' => 'Operação', 'icone' => 'bi bi-box-seam', 'aplicacoes' => [
            'OPE_01' => ['nome' => 'Nova Remessa', 'caminho' => 'operacao/remessa/criar', 'acoes' => ['criar']],
            'OPE_02' => ['nome' => 'Listar Remessas', 'caminho' => 'operacao/remessa/listar', 'acoes' => ['ver', 'etiqueta', 'despachar', 'cancelar']],
        ]],
        'REV' => ['nome' => 'Reversa', 'icone' => 'bi bi-arrow-return-left', 'aplicacoes' => [
            'REV_01' => ['nome' => 'Listar Devoluções', 'caminho' => 'reversa/devolucao/listar', 'acoes' => ['ver', 'etiqueta']],
            'REV_02' => ['nome' => 'Criar Devolução', 'caminho' => 'reversa/devolucao/criar', 'acoes' => ['criar']],
        ]],
        'TRA' => ['nome' => 'Transportadoras', 'icone' => 'bi bi-truck', 'aplicacoes' => [
            'TRA_01' => ['nome' => 'Minhas transportadoras', 'caminho' => 'transportadoras/listar', 'acoes' => ['ver', 'editar']],
        ]],
        'ADM' => ['nome' => 'Administração', 'icone' => 'bi bi-gear', 'aplicacoes' => [
            'ADM_01' => ['nome' => 'Chaves de API', 'caminho' => 'administracao/chaves', 'acoes' => ['ver', 'criar', 'excluir']],
            'ADM_02' => ['nome' => 'Usuários', 'caminho' => 'administracao/usuarios', 'acoes' => ['ver', 'criar', 'editar']],
        ]],
    ],
];
