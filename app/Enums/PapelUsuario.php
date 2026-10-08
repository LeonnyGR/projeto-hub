<?php

declare(strict_types=1);

namespace App\Enums;

/** O que um usuario do portal pode fazer. */
enum PapelUsuario: string
{
    /** Dono do cliente: faz tudo (usuarios, chaves de API, transportadoras) e usa o dia a dia. */
    case AdmTenant = 'adm_tenant';

    /** Funcionario: usa o dia a dia (cotar, remessas, etiquetas), mas nao mexe em usuarios, chaves nem transportadoras. */
    case OperadorTenant = 'operador_tenant';
}
