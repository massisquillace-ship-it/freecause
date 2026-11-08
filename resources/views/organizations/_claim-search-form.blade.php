<form method="get" action="{{ lroute('organizations.claim-search') }}" class="space-y-3 mb-6">
    <label class="block font-semibold" for="organization-search">{{ __('organizations.find_organization') }}</label>
    <div class="flex flex-col sm:flex-row gap-3">
        <input id="organization-search" type="search" name="q" value="{{ $q ?? request('q', '') }}" placeholder="{{ __('organizations.organization_name') }}" maxlength="120" class="w-full min-w-0 border border-border rounded-sm p-3">
        <x-ui.button type="submit">{{ __('organizations.find_organization') }}</x-ui.button>
    </div>
</form>
