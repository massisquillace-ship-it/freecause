<?php

namespace App\Http\Requests;

use App\Models\Cause;
use App\Models\Organization;
use App\Rules\OrganizationUrl;
use App\Support\Countries;
use App\Support\Locale;
use Database\Seeders\OrganizationCauseSeeder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrganizationProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! $this->user('web')) {
            return false;
        }
        $slug = $this->route('slug');
        if (! $slug) {
            return true;
        }
        $org = Organization::where('slug', $slug)->firstOrFail();

        return (int) $org->owner_user_id === (int) $this->user('web')->id && $org->status !== 'suspended';
    }

    public function rules(): array
    {
        $org = $this->route('slug') ? Organization::where('slug', $this->route('slug'))->first() : null;
        $languages = implode(',', Locale::supported());
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'slug' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::notIn(Organization::RESERVED_SLUGS), Rule::unique('organizations')->ignore($org?->id)],
            'type' => ['required', Rule::in(Organization::TYPES)],
            'country' => ['required', Rule::in(Countries::codes())],
            'territory' => ['nullable', 'string', 'max:160'],
            'tax_code' => ['nullable', 'string', 'max:16', 'regex:/^[A-Z0-9]+$/'],
            'verification_reference' => ['required', 'string', 'max:1000'],
            'website' => ['nullable', 'string', 'max:2048', new OrganizationUrl],
            'donation_url' => ['nullable', 'string', 'max:2048', new OrganizationUrl(external: true)],
            'email_public' => ['nullable', 'email:rfc', 'max:254'],
            'social_links' => ['nullable', 'array:'.implode(',', array_keys(OrganizationUrl::SOCIAL_HOSTS))],
            'tagline' => ['nullable', 'array:'.$languages], 'tagline.*' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'array:'.$languages], 'description.*' => ['nullable', 'string', 'max:5000'],
            'causes' => ['required', 'array', 'min:1', 'max:3'],
            'causes.*' => ['required', 'integer', 'distinct', Rule::in(Cause::whereIn('slug', array_keys(OrganizationCauseSeeder::CAUSES))->pluck('id')->all())],
            'logo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=10000,max_height=10000'],
            'cover' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072', 'dimensions:max_width=10000,max_height=10000'],
            'cover_focal_point' => ['required', Rule::in(['top', 'center', 'bottom'])],
            'remove_logo' => ['sometimes', 'boolean'], 'remove_cover' => ['sometimes', 'boolean'],
        ];
        foreach (OrganizationUrl::SOCIAL_HOSTS as $key => $hosts) {
            $rules['social_links.'.$key] = ['nullable', 'string', 'max:2048', new OrganizationUrl($hosts)];
        }
        if (! $org) {
            $rules['representation_confirmed'] = ['accepted'];
            $rules['terms_accepted'] = ['accepted'];
        }
        if ($org?->verified_at) {
            foreach (Organization::IDENTITY_FIELDS as $field) {
                // Disabled form fields are omitted. Submitted identity must match exactly.
                $rules[$field] = ['sometimes', 'nullable', function ($attribute, $value, $fail) use ($org) {
                    if ($value !== $org->getAttribute($attribute)) {
                        $fail(__('organizations.identity_locked'));
                    }
                }];
            }
        }

        return $rules;
    }
}
