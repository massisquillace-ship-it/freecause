@php
    $isAuthed = auth()->check();
    $c = $navbarContent ?? [];
    $explore = request()->routeIs('home') ? __('home.petitions_label') : ($c['nav_explore'] ?? __('nav.explore'));
    if (preg_match('~^(?:https?://|/)~i', trim($explore))) { $explore = __('nav.explore'); }
    $organizationRoute = request()->routeIs('organizations', 'organizations.*');
    $showOrganizationNav = \App\Models\Organization::directoryReady();
    $help = $c['nav_help'] ?? __('nav.help');
    $loginLabel = $c['nav_login'] ?? __('auth.login');
    $accountLabel = $isAuthed ? auth()->user()->display_name : $loginLabel;
    $startFree = $showOrganizationNav ? __('organizations.claim_organization') : __('home.start_petition');
    $startTarget = $showOrganizationNav ? lroute('organizations.claim-search') : lroute('petition.create');
@endphp
<nav class="navbar navbar-expand-lg bg-white shadow-elev-1 {{ request()->routeIs('home') ? 'fc-home-nav' : '' }}">
    <div class="{{ request()->routeIs('home') ? 'mx-auto w-full px-3 max-w-[540px] md:max-w-[720px] lg:max-w-[960px] xl:max-w-[1140px] 2xl:max-w-[1320px] flex items-center justify-between' : 'container' }}">
        <a class="navbar-brand" href="{{ lroute('home') }}">
            <img src="{{ asset('legacy/images/logo7.png') }}" class="img-fluid" width="130" height="21" alt="FreeCause">
        </a>

        <div class="flex items-center gap-2 lg:hidden ms-auto">
            <a href="{{ lroute('petitions.index') }}" class="fcui-icon-btn" aria-label="{{ __('list.search') }}">
                <x-lucide-search aria-hidden="true" />
            </a>

            @include('partials.language_switch', ['mobile' => true])

            <button class="fcui-icon-btn" type="button" data-sheet-open="mobile-menu" aria-label="{{ __('nav.menu') }}" aria-haspopup="dialog">
                <x-lucide-menu aria-hidden="true" />
            </button>
        </div>

        <div class="collapse navbar-collapse" id="fcNav">
            <ul class="navbar-nav ms-auto mb-2 lg:mb-0 lg:items-center">
                @if($showOrganizationNav)<li class="nav-item"><a class="nav-link" href="{{ lroute('organizations.explore') }}">{{ __('home.organizations_label') }}</a></li>@endif
                <li class="nav-item">
                    <a class="nav-link" href="{{ lroute('petitions.index') }}">
                        {{ $explore }}
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="{{ url('/magazine') }}">
                        {{ $c['nav_magazine'] ?? __('nav.magazine') }}
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="{{ lroute('faqs') }}">
                        {{ $help }}
                    </a>
                </li>
                @if (!$isAuthed)
                    <li class="nav-item">
                        <a class="nav-link" href="{{ lroute('login') }}">
                            {{ $loginLabel }}
                        </a>
                    </li>
                @else
                    <li class="nav-item">
                        <a class="nav-link" href="{{ lroute('profile') }}">{{ auth()->user()->display_name }}</a>
                    </li>
                    <li class="nav-item lg:ms-2 lg:me-3">
                        <form method="POST" action="{{ lroute('logout') }}" class="d-inline">
                            @csrf
                            <button type="submit" class="nav-link">
                                {{ $c['nav_logout'] ?? __('nav.logout') }}
                            </button>
                        </form>
                    </li>
                @endif

                @if($showOrganizationNav)<li class="nav-item mt-2 lg:mt-0 lg:ms-2"><a class="fcui-btn fcui-btn--md fcui-btn--ghost border-border text-ink" href="{{ lroute('petition.create') }}">{{ __('home.start_petition') }}</a></li>@endif

                <li class="nav-item mt-2 lg:mt-0 lg:ms-3">
                    <a class="fcui-btn fcui-btn--md fcui-btn--primary w-100 w-lg-auto" href="{{ $startTarget }}">
                        {{ $startFree }}
                    </a>
                </li>

                <li class="nav-item lg:ms-4 hidden lg:block">
                    @include('partials.language_switch')
                </li>
            </ul>
        </div>
    </div>
</nav>

<x-ui.bottom-sheet id="mobile-menu" :close-label="__('common.close')">
    <div class="fcui-sheet-list -mx-4 -mb-4 mt-0">
        <a class="fcui-sheet-list__item" href="{{ lroute('petitions.index') }}">
            <x-lucide-list aria-hidden="true" />
            <span>{{ $explore }}</span>
        </a>
        <a class="fcui-sheet-list__item" href="{{ lroute('page.show', ['slug' => 'ethical-code']) }}">
            <x-lucide-flag aria-hidden="true" />
            <span>{{ __('pages.ethical_code') }}</span>
        </a>
        <a class="fcui-sheet-list__item" href="{{ lroute('faqs') }}">
            <x-lucide-circle-help aria-hidden="true" />
            <span>{{ $help }}</span>
        </a>
        @if($showOrganizationNav)<a class="fcui-sheet-list__item" href="{{ lroute('organizations.explore') }}"><x-lucide-building aria-hidden="true" /><span>{{ __('home.organizations_label') }}</span></a>@endif
        <a class="fcui-sheet-list__item" href="{{ url('/magazine') }}">
            <x-lucide-newspaper aria-hidden="true" />
            <span>{{ $c['nav_magazine'] ?? __('nav.magazine') }}</span>
        </a>

        @if (!$isAuthed)
            <a class="fcui-sheet-list__item" href="{{ lroute('login') }}">
                <x-lucide-log-in aria-hidden="true" />
                <span>{{ $loginLabel }}</span>
            </a>
            @unless($organizationRoute)
            <a class="fcui-sheet-list__item" href="{{ lroute('register') }}">
                <x-lucide-user-plus aria-hidden="true" />
                <span>{{ __('auth.create_account') }}</span>
            </a>
            @endunless
        @else
            <a class="fcui-sheet-list__item" href="{{ lroute('profile') }}">
                <x-lucide-circle-user-round aria-hidden="true" />
                <span>{{ auth()->user()->display_name }}</span>
            </a>
            <a class="fcui-sheet-list__item" href="{{ lroute('account.petitions') }}">
                <x-lucide-file-text aria-hidden="true" />
                <span>{{ __('nav.my_petitions') }}</span>
            </a>
            <form method="POST" action="{{ lroute('logout') }}" class="contents">
                @csrf
                <button type="submit" class="fcui-sheet-list__item w-full">
                    <x-lucide-log-out aria-hidden="true" />
                    <span>{{ $c['nav_logout'] ?? __('nav.logout') }}</span>
                </button>
            </form>
        @endif

        @if($showOrganizationNav)<a class="fcui-sheet-list__item" href="{{ lroute('petition.create') }}"><x-lucide-plus aria-hidden="true" /><span>{{ __('home.start_petition') }}</span></a>@endif
        <a class="fcui-sheet-list__item" href="{{ $startTarget }}">
            <x-lucide-megaphone aria-hidden="true" />
            <span>{{ $startFree }}</span>
        </a>
    </div>
</x-ui.bottom-sheet>


