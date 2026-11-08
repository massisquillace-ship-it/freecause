<?php

namespace App\Http\Requests;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrganizationClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web') !== null;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(Organization::TYPES)],
            'verification_reference' => ['required', 'string', 'max:1000'],
            'representation_confirmed' => ['accepted'],
            'terms_accepted' => ['accepted'],
        ];
    }
}
