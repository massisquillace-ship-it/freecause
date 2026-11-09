@php
    $isAuthedNav = auth()->check();
    $showOrgNav = \App\Models\Organization::directoryReady();
    $accountLabelNav = $isAuthedNav ? auth()->user()->display_name : ($navbarContent['nav_login'] ?? __('auth.login'));
    $mobileItems = [
        ['label' => __('nav.home'), 'href' => lroute('home'), 'icon' => 'house', 'active' => request()->routeIs('home', 'root')],
    ];
    $mobileItems[] = ['label' => __('home.organizations_label'), 'href' => lroute('organizations.explore'), 'icon' => 'building', 'active' => request()->routeIs('organizations', 'organizations.explore')];
    if ($showOrgNav) {
        $mobileItems[] = ['label' => __('home.mobile_claim'), 'ariaLabel' => __('organizations.claim_organization'), 'href' => lroute('organizations.claim-search'), 'icon' => 'flag', 'active' => request()->routeIs('organizations.claim-search')];
    }
    $mobileItems[] = ['label' => __('home.mobile_start'), 'ariaLabel' => __('home.start_petition'), 'href' => lroute('petition.create'), 'icon' => 'plus', 'active' => request()->routeIs('petition.create*')];
    $mobileItems[] = ['label' => $accountLabelNav, 'href' => $isAuthedNav ? lroute('profile') : lroute('login'), 'icon' => 'circle-user-round', 'active' => request()->routeIs('profile', 'login', 'register', 'account.*')];
    $mobileItems[] = ['label' => __('nav.menu'), 'icon' => 'menu', 'sheet' => 'mobile-menu', 'active' => false];
@endphp
<x-ui.bottom-nav :items="$mobileItems" />
