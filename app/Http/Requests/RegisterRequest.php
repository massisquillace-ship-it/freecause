<?php

namespace App\Http\Requests;

use App\Rules\MaxBytes;
use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => strtolower(trim((string) $this->email))]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'surname' => ['required', 'string', 'max:60'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', new MaxBytes(72)],
            'nickname' => ['nullable', 'regex:/^[A-Za-z0-9]{3,32}$/', 'unique:users,nickname'],
            'city' => ['nullable', 'string', 'max:80'],
            'agree_terms' => ['accepted'],
        ];
    }
}
