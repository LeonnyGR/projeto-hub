<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ErroDeNegocio;
use App\Models\Usuario;
use App\Models\UsuarioAplicacao;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Quem pode o que no portal. O dono (adm_tenant) pode tudo; o operador, so o que o dono (ou alguem com ADM_02)
 * marcou para ele, aplicacao por aplicacao ("OPE_02" => ["ver", "etiqueta"]).
 */
class PermissaoService
{
    public function __construct(private readonly CatalogoDePermissoes $catalogo)
    {
    }

    /** ['OPE_02' => ['ver', 'etiqueta'], ...] */
    public function efetivas(Usuario $usuario): array
    {
        if ($usuario->ehDono()) {
            return collect($this->catalogo->arvore())->pluck('aplicacoes')->flatten(1)->pluck('acoes', 'codigo')->all();
        }

        return UsuarioAplicacao::where('usuario_id', $usuario->id)->pluck('acoes', 'aplicacao_codigo')->all();
    }

    public function pode(Usuario $usuario, string $aplicacao, string $acao): bool
    {
        return in_array($acao, $this->efetivas($usuario)[$aplicacao] ?? [], true);
    }

    /** Menu do usuario: so os modulos e aplicacoes em que ele tem alguma acao, ja com as acoes. */
    public function menu(Usuario $usuario): array
    {
        $efetivas = $this->efetivas($usuario);
        $menu = [];

        foreach ($this->catalogo->arvore() as $modulo) {
            $apps = [];
            foreach ($modulo['aplicacoes'] as $app) {
                if (! empty($efetivas[$app['codigo']])) {
                    $apps[] = ['codigo' => $app['codigo'], 'nome' => $app['nome'], 'caminho' => $app['caminho'], 'acoes' => array_values($efetivas[$app['codigo']])];
                }
            }
            if ($apps !== []) {
                $menu[] = ['codigo' => $modulo['codigo'], 'nome' => $modulo['nome'], 'icone' => $modulo['icone'], 'aplicacoes' => $apps];
            }
        }

        return $menu;
    }

    /** Permissoes gravadas de um operador, no formato da API: [['aplicacao' => 'OPE_02', 'acoes' => [...]]]. */
    public function doOperador(Usuario $operador): array
    {
        return UsuarioAplicacao::where('usuario_id', $operador->id)->orderBy('aplicacao_codigo')->get()
            ->map(fn (UsuarioAplicacao $p) => ['aplicacao' => $p->aplicacao_codigo, 'acoes' => $p->acoes])->all();
    }

    /**
     * Troca TODAS as permissoes do operador pelas informadas (o que nao vier, ele perde).
     * Regras: a aplicacao e a acao tem que existir no catalogo, e quem concede so concede o que ele mesmo tem.
     *
     * @param  array  $permissoes  [['aplicacao' => 'OPE_02', 'acoes' => ['ver']], ...]
     * @throws ErroDeNegocio permissao_invalida (422) | permissao_acima_do_proprio (403)
     */
    public function definir(Usuario $operador, array $permissoes, Usuario $quemConcede): void
    {
        $validas = collect($this->catalogo->arvore())->pluck('aplicacoes')->flatten(1)->pluck('acoes', 'codigo')->all();
        $queConcedeTem = $this->efetivas($quemConcede);

        $novas = [];
        foreach ($permissoes as $item) {
            $codigo = (string) $item['aplicacao'];
            $acoes = array_values(array_unique($item['acoes']));

            if (! isset($validas[$codigo]) || array_diff($acoes, $validas[$codigo]) !== []) {
                throw new ErroDeNegocio('permissao_invalida', 'Aplicacao ou acao desconhecida.', 422, ['aplicacao' => $codigo]);
            }
            if (array_diff($acoes, $queConcedeTem[$codigo] ?? []) !== []) {
                throw new ErroDeNegocio('permissao_acima_do_proprio', 'Voce nao pode conceder o que voce mesmo nao tem.', 403, ['aplicacao' => $codigo]);
            }
            if ($acoes !== []) {
                $novas[$codigo] = $acoes;
            }
        }

        DB::transaction(function () use ($operador, $novas) {
            UsuarioAplicacao::where('usuario_id', $operador->id)->delete();
            foreach ($novas as $codigo => $acoes) {
                UsuarioAplicacao::create(['usuario_id' => $operador->id, 'aplicacao_codigo' => $codigo, 'acoes' => $acoes]);
            }
        });

        Log::info('Permissoes definidas', ['usuario_id' => $operador->id, 'por' => $quemConcede->id, 'aplicacoes' => array_keys($novas)]);
    }
}
