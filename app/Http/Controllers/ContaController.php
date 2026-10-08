<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\ClienteResource;
use Illuminate\Http\Request;

/** A conta do cliente que esta chamando a API. */
class ContaController extends Controller
{
    public function show(Request $request): ClienteResource
    {
        return new ClienteResource($this->cliente($request));
    }
}
