<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClienteRequest;
use App\Http\Resources\ClienteResource;
use App\Http\Resources\UsuarioResource;
use App\Models\Cliente;
use App\Services\ClienteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Administracao dos clientes (so para nos, com o token de admin). */
class ClienteController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ClienteResource::collection(Cliente::orderBy('nome')->paginate(50));
    }

    /**
     * Cria o cliente e o usuario dono dele ("dono": nome, email e password).
     * O dono entra no portal, cria as chaves de API e os operadores.
     */
    public function store(ClienteRequest $request, ClienteService $service): JsonResponse
    {
        [$cliente, $dono] = $service->criar($request->validated('nome'), $request->validated('cnpj'), $request->validated('dono'));

        return response()->json(['data' => (new ClienteResource($cliente))->toArray($request) + ['dono' => (new UsuarioResource($dono))->toArray($request)]], 201);
    }

    public function show(string $id): ClienteResource
    {
        return new ClienteResource(Cliente::findOrFail($id));
    }

    /** Muda o nome, o CNPJ ou liga/desliga o cliente (desligado: ninguem dele entra no portal nem usa a API). */
    public function update(ClienteRequest $request, string $id): ClienteResource
    {
        $cliente = Cliente::findOrFail($id);
        $cliente->update($request->validated());

        return new ClienteResource($cliente);
    }
}
