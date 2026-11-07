@php($organizationConsent = \App\Services\OrganizationConsent::presentation($petition, app()->getLocale(), $previousOrganizationConsent ?? []))
@if($organizationConsent)
    <div class="border border-border rounded-sm p-4 mb-4 text-sm">
        <input type="hidden" name="organization_consent_proof" value="{{ $organizationConsent['proof'] }}">
        <label class="flex items-start gap-2"><input class="shrink-0 mt-1" type="checkbox" name="organization_optin" value="1" @checked($organizationConsent['selected'])> <span>{{ __('organizations.optin_2026_11', ['name'=>$organizationConsent['organization']->name]) }}</span></label>
        <p class="mt-3">{{ __('organizations.optin_privacy_2026_11', ['name'=>$organizationConsent['organization']->name]) }} <a class="underline" href="{{ lroute('page.show', ['slug'=>'privacy-policy']) }}">{{ __('organizations.privacy_notice') }}</a></p>
    </div>
@endif
