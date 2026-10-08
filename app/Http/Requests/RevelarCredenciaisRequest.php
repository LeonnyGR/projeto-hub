<?php

declare(strict_types=1);

namespace App\Http\Requests;

/** O dono confirma a propria senha para ver as credenciais de uma transportadora. */
class RevelarCredenciaisRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return ['password' => ['required', 'string', 'max:200']];
    }
}
