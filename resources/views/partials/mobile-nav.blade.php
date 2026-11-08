@php
    $isAuthedNav = auth()->check();
    $organizationNavRoute = request()->routeIs('organizations', 'organizations.*') && \App\Models\Organization::directoryReady();
    $startFreeNav = $organizationNavRoute ? __('organizations.claim_organization') : ($navbarContent['nav_startfree'] ?? __('nav.startfree'));
    $startTargetNav = $organizationNavRoute ? lroute('organizations.claim-search') : lroute('petition.create');
    $accountLabelNav = $isAuthedNav ? auth()->user()->display_name : ($navbarContent['nav_login'] ?? __('auth.login'));
@endphp
<x-ui.bottom-nav :items="[
    ['label' => __('nav.home'), 'href' => lroute('home'), 'icon' => 'house', 'active' => request()->routeIs('home', 'root')],
    ['label' => __('list.petitions'), 'href' => lroute('petitions.index'), 'icon' => 'search', 'active' => request()->routeIs('petitions.*', 'petition.show', 'petition.signatures')],
    ['label' => $startFreeNav, 'href' => $startTargetNav, 'icon' => 'plus', 'active' => request()->routeIs('petition.create*')],
    ['label' => $accountLabelNav, 'href' => $isAuthedNav ? lroute('profile') : lroute('login'), 'icon' => 'circle-user-round', 'active' => request()->routeIs('profile', 'login', 'register', 'account.*')],
    ['label' => __('nav.menu'), 'icon' => 'menu', 'sheet' => 'mobile-menu', 'active' => false],
]" />
