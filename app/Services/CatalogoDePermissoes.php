<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Aplicacao;
use App\Models\Modulo;
use Illuminate\Support\Facades\DB;

/** Leva o catalogo de config/permissoes.php para o banco e o entrega como arvore (modulo => aplicacoes). */
class CatalogoDePermissoes
{
    /** Grava o que esta no config e apaga do banco o que saiu dele (as permissoes dessas aplicacoes vao junto). */
    public function sincronizar(): void
    {
        DB::transaction(function () {
            $modulos = [];
            $aplicacoes = [];
            $ordemModulo = 0;

            foreach (config('permissoes.modulos') as $codigoModulo => $modulo) {
                $modulos[] = $codigoModulo;
                Modulo::updateOrCreate(['codigo' => $codigoModulo], [
                    'nome' => $modulo['nome'], 'icone' => $modulo['icone'], 'ordem' => ++$ordemModulo,
                ]);

                $ordemApp = 0;
                foreach ($modulo['aplicacoes'] as $codigoApp => $app) {
                    $aplicacoes[] = $codigoApp;
                    Aplicacao::updateOrCreate(['codigo' => $codigoApp], [
                        'modulo_codigo' => $codigoModulo, 'nome' => $app['nome'], 'caminho' => $app['caminho'],
                        'acoes' => $app['acoes'], 'ordem' => ++$ordemApp,
                    ]);
                }
            }

            Aplicacao::whereNotIn('codigo', $aplicacoes)->delete();
            Modulo::whereNotIn('codigo', $modulos)->delete();
        });
    }

    /** [['codigo', 'nome', 'icone', 'aplicacoes' => [['codigo', 'nome', 'caminho', 'acoes']]]], na ordem de exibicao. */
    public function arvore(): array
    {
        return Modulo::with('aplicacoes')->orderBy('ordem')->get()->map(fn (Modulo $m) => [
            'codigo' => $m->codigo,
            'nome' => $m->nome,
            'icone' => $m->icone,
            'aplicacoes' => $m->aplicacoes->map(fn (Aplicacao $a) => [
                'codigo' => $a->codigo, 'nome' => $a->nome, 'caminho' => $a->caminho, 'acoes' => $a->acoes,
            ])->all(),
        ])->all();
    }
}
