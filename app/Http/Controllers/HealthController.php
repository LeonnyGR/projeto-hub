<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/** Health check do ALB. Nao consulta banco nem Redis de proposito. */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }
}
