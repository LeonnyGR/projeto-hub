<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SellerRequest;
use App\Http\Resources\SellerResource;
use App\Services\SellerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SellerController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return SellerResource::collection($this->cliente($request)->sellers()->orderBy('nome')->paginate(50));
    }

    public function store(SellerRequest $request, SellerService $service): JsonResponse
    {
        $seller = $service->criar($this->cliente($request), $request->validated());

        return (new SellerResource($seller))->response()->setStatusCode(201);
    }

    public function show(Request $request): SellerResource
    {
        return new SellerResource($this->seller($request));
    }

    public function update(SellerRequest $request, SellerService $service): SellerResource
    {
        return new SellerResource($service->atualizar($this->seller($request), $request->validated()));
    }
}
