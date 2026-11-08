<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Support\Countries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['new_email', 'new_email_confirmation'] as $key) {
            if (is_string($this->input($key)) && filled($this->input($key))) {
                $this->merge([$key => strtolower(trim((string) $this->input($key)))]);
            }
        }
    }

    public function rules(): array
    {
        $user = $this->user();

        return [
            'first_name' => ['required', 'string', 'max:60'],
            'last_name' => ['required', 'string', 'max:60'],
            'nickname' => ['nullable', 'regex:/^[A-Za-z0-9]{3,32}$/', Rule::unique('users', 'nickname')->ignore($user->id), 'required_if:identify_mode,nick'],
            'city' => ['nullable', 'string', 'max:80'],
            'country' => ['required', Rule::in(Countries::codes())],
            'gender' => ['required', Rule::in(User::GENDERS)],
            'locale' => [
                'required',
                Rule::exists('languages', 'code')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'identify_mode' => ['nullable', Rule::in(['full', 'name', 'nick'])],
            'new_email' => ['nullable', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user?->id),
                Rule::unique('users', 'pending_email')->ignore($user?->id)->where(fn ($q) => $q->where('verification_token_sent_at', '>', now()->subHours(48))),
            ],
            'new_email_confirmation' => ['required_with:new_email', 'same:new_email'],
            'current_password' => [Rule::requiredIf(fn () => (filled($this->input('new_password')) || $this->changesEmail()) && (bool) $user?->hasLocalPassword()), 'nullable', 'current_password:web'],
            'new_password' => ['nullable', 'string', 'min:8', 'max:72', 'confirmed'],
        ];
    }

    private function changesEmail(): bool
    {
        return filled($this->input('new_email')) && $this->input('new_email') !== strtolower((string) $this->user()?->email);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($this->changesEmail() && ! $this->user()?->hasLocalPassword()) {
                $v->errors()->add('new_email', __('profile.email_needs_password'));
            }
        });
    }

    public function messages(): array
    {
        return [
            'nickname.required_if' => __('profile.nick_required'),
            'new_email.unique' => __('profile.email_taken'),
            'current_password.current_password' => __('profile.current_password_incorrect'),
        ];
    }
}
