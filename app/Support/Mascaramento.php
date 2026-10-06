<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Esconde dados sensiveis antes de gravar em log ou em chamadas_transportadoras.
 * Cobre token, senha, Authorization, CPF/CNPJ, telefone e e-mail:
 *  - pelo NOME do campo (arrays e JSON);
 *  - pelo FORMATO do valor, em texto livre (XML, mensagens, corpos que nao sao JSON).
 */
class Mascaramento
{
    public const OCULTO = '[OCULTO]';

    // Se o nome do campo contem um destes trechos, o valor e totalmente ocultado.
    private const CAMPOS_SECRETOS = [
        'authorization', 'token', 'senha', 'password', 'secret', 'segredo', 'apikey',
        'cookie', 'assinatura', 'signature', 'codigoacesso', 'credencial',
    ];

    // Campos de dado pessoal: mostramos so os 2 ultimos digitos (ou a 1a letra do e-mail).
    private const CAMPOS_DOCUMENTO = ['cnpjcpf', 'cpfcnpj', 'cpf', 'cnpj', 'documento'];
    private const CAMPOS_TELEFONE = ['fone', 'telefone', 'celular', 'cel', 'phone'];
    private const CAMPOS_EMAIL = ['email', 'emailresposta'];

    /** Mascara um array (em qualquer profundidade). */
    public static function array(array $dados): array
    {
        $saida = [];
        foreach ($dados as $chave => $valor) {
            $saida[$chave] = self::valor((string) $chave, $valor);
        }

        return $saida;
    }

    /** Mascara um corpo de requisicao/resposta (JSON, XML ou texto). */
    public static function texto(?string $corpo): ?string
    {
        if ($corpo === null || $corpo === '') {
            return $corpo;
        }

        $inicio = ltrim($corpo)[0] ?? '';
        if ($inicio === '{' || $inicio === '[') {
            $json = json_decode($corpo, true);
            if (is_array($json)) {
                return (string) json_encode(self::array($json), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        return self::padroes($corpo);
    }

    public static function cabecalhos(array $cabecalhos): array
    {
        $saida = [];
        foreach ($cabecalhos as $nome => $valor) {
            $texto = is_array($valor) ? implode(', ', $valor) : (string) $valor;
            $saida[(string) $nome] = self::ehSecreto((string) $nome) ? self::OCULTO : self::padroes($texto);
        }

        return $saida;
    }

    /** Tira a query string da URL (ela pode ter CEP, documento ou token). */
    public static function url(string $url): string
    {
        return explode('#', explode('?', $url, 2)[0], 2)[0];
    }

    private static function valor(string $chave, mixed $valor): mixed
    {
        if (is_array($valor)) {
            return self::ehSecreto($chave) ? self::OCULTO : self::array($valor);
        }

        if ($valor === null || $valor === '') {
            return $valor;
        }

        if (! is_scalar($valor) || self::ehSecreto($chave)) {
            return self::OCULTO;
        }

        $nome = self::simplifica($chave);
        $texto = (string) $valor;

        if (in_array($nome, self::CAMPOS_DOCUMENTO, true)) {
            return self::mostrarFinal($texto);
        }
        if (in_array($nome, self::CAMPOS_TELEFONE, true)) {
            return self::mostrarFinal($texto);
        }
        if (in_array($nome, self::CAMPOS_EMAIL, true)) {
            return self::email($texto);
        }

        return is_string($valor) ? self::padroes($texto) : $valor;
    }

    private static function ehSecreto(string $chave): bool
    {
        $nome = self::simplifica($chave);
        foreach (self::CAMPOS_SECRETOS as $trecho) {
            if (str_contains($nome, $trecho)) {
                return true;
            }
        }

        return false;
    }

    /** "api_key" e "apiKey" viram "apikey". */
    private static function simplifica(string $chave): string
    {
        return strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', $chave));
    }

    /** Procura dados sensiveis pelo formato, em texto livre. */
    private static function padroes(string $texto): string
    {
        // "Bearer xxx" e "Basic xxx"
        $texto = (string) preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/i', '$1 '.self::OCULTO, $texto);

        // pares com nome secreto: "token":"x"  senha=x  <token>x</token>
        $nomes = 'token|senha|password|secret|segredo|authorization|api[-_]?key|assinatura|signature';
        $texto = (string) preg_replace('/("(?:[^"\\\\]*(?:'.$nomes.')[^"\\\\]*)"\s*:\s*)"[^"]*"/i', '$1"'.self::OCULTO.'"', $texto);
        $texto = (string) preg_replace('/((?:'.$nomes.')[A-Za-z_-]*\s*=\s*)[^\s&;"\']+/i', '$1'.self::OCULTO, $texto);
        $texto = (string) preg_replace('/(<(?:[A-Za-z0-9_:-]*(?:'.$nomes.')[A-Za-z0-9_-]*)>)[^<]*(<\/)/i', '$1'.self::OCULTO.'$2', $texto);

        // e-mail, CNPJ (antes do CPF) e telefone
        $texto = (string) preg_replace_callback('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', fn ($m) => self::email($m[0]), $texto);
        $texto = (string) preg_replace_callback('/\b\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}\b/', fn ($m) => self::mostrarFinal($m[0]), $texto);
        $texto = (string) preg_replace_callback('/\b\d{3}\.?\d{3}\.?\d{3}-?\d{2}\b/', fn ($m) => self::mostrarFinal($m[0]), $texto);
        $texto = (string) preg_replace_callback('/(?<!\d)\(?\d{2}\)?[\s-]?9?\d{4}[\s-]?\d{4}(?!\d)/', fn ($m) => self::mostrarFinal($m[0]), $texto);

        return $texto;
    }

    /** "12345678909" => "*********09" (serve para CPF, CNPJ e telefone). */
    private static function mostrarFinal(string $valor): string
    {
        $digitos = (string) preg_replace('/\D/', '', $valor);

        return $digitos === '' ? self::OCULTO : str_repeat('*', max(strlen($digitos) - 2, 0)).substr($digitos, -2);
    }

    /** "maria@x.com" => "m***@x.com" */
    private static function email(string $valor): string
    {
        $partes = explode('@', $valor, 2);

        return count($partes) === 2 ? substr($partes[0], 0, 1).'***@'.$partes[1] : self::OCULTO;
    }
}
