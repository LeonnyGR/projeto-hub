<?php

declare(strict_types=1);

namespace App\Http\Requests;

class LoginRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string', 'max:200'],
        ];
    }
}
