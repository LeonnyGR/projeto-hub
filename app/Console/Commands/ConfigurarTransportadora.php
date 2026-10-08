<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TransportadoraService;
use App\Support\Pessoa;
use App\Transportadoras\Transportadoras;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;

/**
 * Cadastra as credenciais e a configuracao de um cliente com uma transportadora (token, cartao de postagem, tomador...).
 * Mesmo cadastro da API (PUT /transportadoras/{t}), para quem prefere o terminal.
 * O que se digita em campo secreto nao aparece na tela nem fica no historico do terminal.
 */
class ConfigurarTransportadora extends ComandoDeCliente
{
    protected $signature = 'transportadora:configurar {transportadora : Codigo da transportadora, o mesmo usado na API} {cliente? : id do cliente (opcional se so existir um)}';

    protected $description = 'Cadastra (ou substitui) as credenciais de um cliente com uma transportadora';

    public function handle(TransportadoraService $service): int
    {
        $codigo = (string) $this->argument('transportadora');
        $classe = Transportadoras::LISTA[$codigo] ?? null;

        if ($classe === null) {
            $this->error('Transportadora desconhecida. Use: '.implode(', ', Transportadoras::codigos()));

            return self::FAILURE;
        }

        $cliente = $this->cliente();
        if ($cliente === null) {
            return self::FAILURE;
        }

        if ($cliente->credenciais()->where('transportadora', $codigo)->exists()
            && ! $this->confirm("O cliente '{$cliente->nome}' ja tem '{$codigo}' cadastrada. Substituir tudo?", false)) {
            $this->line('Nada foi alterado.');

            return self::SUCCESS;
        }

        $dados = [];
        foreach ($classe::perguntas() as $pergunta) {
            $resposta = ($pergunta['secreto'] ?? false)
                ? $this->secret($pergunta['rotulo'])
                : $this->ask($pergunta['rotulo'], $pergunta['padrao'] ?? null);

            $resposta = trim((string) $resposta);
            $valor = $resposta === '' ? null : $resposta;

            if (($pergunta['tipo'] ?? null) === 'servicos' && $valor !== null) {
                $valor = Transportadoras::lerServicosDigitados($valor);
            }

            Arr::set($dados, $pergunta['campo'], $valor);
        }

        // Documento, telefone e CEP do tomador podem ser digitados com mascara.
        if (isset($dados['configuracao']['tomador'])) {
            $dados['configuracao']['tomador'] = Pessoa::normalizar($dados['configuracao']['tomador']);
        }

        $validador = Validator::make($dados, $classe::regras());

        if ($validador->fails()) {
            $this->error('Dados invalidos. Nada foi salvo. Rode o comando de novo:');
            foreach ($validador->errors()->all() as $mensagem) {
                $this->line(' - '.$mensagem);
            }

            return self::FAILURE;
        }

        $service->configurar($cliente, $codigo, $validador->validated());

        $this->info("Transportadora '{$codigo}' configurada para '{$cliente->nome}'. As cotacoes e remessas dele passam a usar estes dados.");

        return self::SUCCESS;
    }
}
