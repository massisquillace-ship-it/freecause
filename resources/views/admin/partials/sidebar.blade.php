@php
    $groups = [
        [
            'label' => 'overview',
            'links' => [
                ['route' => 'admin.dashboard', 'label' => 'dashboard', 'is' => 'admin.dashboard', 'module' => null],
            ],
        ],
        [
            'label' => 'site',
            'links' => [
                ['route' => 'admin.options.global', 'label' => 'site settings', 'is' => 'admin.options.global', 'module' => 'options'],
                ['route' => 'admin.options.language', 'label' => 'localized content', 'is' => 'admin.options.language', 'module' => 'options'],
                ['route' => 'admin.ads', 'label' => 'ads.txt', 'is' => 'admin.ads', 'module' => 'options'],
                ['route' => 'admin.users', 'label' => 'users', 'is' => 'admin.users', 'module' => 'users'],
                ['route' => 'admin.categories', 'label' => 'categories', 'is' => 'admin.categories', 'module' => 'categories'],
                ['route' => 'admin.petitions', 'label' => 'petitions', 'is' => 'admin.petitions', 'module' => 'petitions'],
                ['route' => 'admin.signatures', 'label' => 'signatures', 'is' => 'admin.signatures', 'module' => 'signatures'],
                ['route' => 'admin.pages', 'label' => 'pages', 'is' => 'admin.pages', 'module' => 'pages'],
                ['route' => 'admin.spam', 'label' => 'spam', 'is' => 'admin.spam', 'module' => 'spam'],
                ['route' => 'admin.keywords', 'label' => 'review keywords', 'is' => 'admin.keywords', 'module' => 'spam'],
                ['route' => 'admin.reports', 'label' => 'reports', 'is' => 'admin.reports', 'module' => 'reports'],
                ['route' => 'admin.organizations', 'label' => __('organizations.title'), 'is' => 'admin.organizations*', 'module' => 'organizations'],
                ['route' => 'admin.stats', 'label' => 'statistics', 'is' => 'admin.stats', 'module' => 'stats'],
                ['route' => 'admin.logs', 'label' => 'logs', 'is' => 'admin.logs', 'module' => 'logs'],
                ['route' => 'admin.languages.index', 'label' => 'languages', 'is' => 'admin.languages.index', 'module' => 'languages'],
            ],
        ],
    ];

    $systemLinks = [
        ['route' => 'admin.system.user_info', 'label' => 'user information', 'is' => 'admin.system.user_info', 'module' => null],
        ['route' => 'admin.system.user_levels', 'label' => 'user levels', 'is' => 'admin.system.user_levels', 'module' => null],
        ['route' => 'admin.system.permissions', 'label' => 'permissions', 'is' => 'admin.system.permissions', 'module' => null],
    ];
@endphp

<x-blatui.sidebar variant="inset">
    <x-blatui.sidebar-header>
        <a href="{{ route('admin.dashboard') }}" class="flex items-baseline gap-1.5 rounded-md px-2 py-1 no-underline">
            <span class="text-sm font-semibold text-ink">FreeCause</span>
            <span class="text-xs text-muted">admin</span>
        </a>
    </x-blatui.sidebar-header>

    <x-blatui.sidebar-content>
        @foreach ($groups as $group)
            <x-blatui.sidebar-group>
                <x-blatui.collapsible
                    :open="true"
                    x-init="(() => { try { if (localStorage.getItem('fc-group-{{ $group['label'] }}') === '1') open = false } catch (e) {} })(); $watch('open', v => { try { localStorage.setItem('fc-group-{{ $group['label'] }}', v ? '0' : '1') } catch (e) {} })"
                >
                    <x-blatui.collapsible-trigger class="group/trigger w-full">
                        <x-blatui.sidebar-group-label class="w-full cursor-pointer justify-between text-ink font-semibold">
                            <span>{{ $group['label'] }}</span>
                            <x-lucide-chevron-down class="size-3.5 transition-transform group-data-[state=closed]/trigger:-rotate-90" />
                        </x-blatui.sidebar-group-label>
                    </x-blatui.collapsible-trigger>
                    <x-blatui.collapsible-content>
                        <x-blatui.sidebar-menu>
                            @foreach ($group['links'] as $link)
                                @if ($link['module'] === null || admin_can($link['module'], 'view'))
                                    <x-blatui.sidebar-menu-item>
                                        <x-blatui.sidebar-menu-button :href="route($link['route'])" :is-active="request()->routeIs($link['is'])">
                                            <span>{{ $link['label'] }}</span>
                                        </x-blatui.sidebar-menu-button>
                                    </x-blatui.sidebar-menu-item>
                                @endif
                            @endforeach
                        </x-blatui.sidebar-menu>
                    </x-blatui.collapsible-content>
                </x-blatui.collapsible>
            </x-blatui.sidebar-group>
        @endforeach

        @if (admin_can('system', 'manage'))
            <x-blatui.sidebar-group>
                <x-blatui.collapsible
                    :open="true"
                    x-init="(() => { try { if (localStorage.getItem('fc-group-system') === '1') open = false } catch (e) {} })(); $watch('open', v => { try { localStorage.setItem('fc-group-system', v ? '0' : '1') } catch (e) {} })"
                >
                    <x-blatui.collapsible-trigger class="group/trigger w-full">
                        <x-blatui.sidebar-group-label class="w-full cursor-pointer justify-between text-ink font-semibold">
                            <span>system</span>
                            <x-lucide-chevron-down class="size-3.5 transition-transform group-data-[state=closed]/trigger:-rotate-90" />
                        </x-blatui.sidebar-group-label>
                    </x-blatui.collapsible-trigger>
                    <x-blatui.collapsible-content>
                        <x-blatui.sidebar-menu>
                            @foreach ($systemLinks as $link)
                                <x-blatui.sidebar-menu-item>
                                    <x-blatui.sidebar-menu-button :href="route($link['route'])" :is-active="request()->routeIs($link['is'])">
                                        <span>{{ $link['label'] }}</span>
                                    </x-blatui.sidebar-menu-button>
                                </x-blatui.sidebar-menu-item>
                            @endforeach
                        </x-blatui.sidebar-menu>
                    </x-blatui.collapsible-content>
                </x-blatui.collapsible>
            </x-blatui.sidebar-group>
        @endif
    </x-blatui.sidebar-content>

    <x-blatui.sidebar-footer class="md:hidden">
        <div class="flex items-center justify-between gap-2 px-1">
            <strong class="text-sm">{{ admin_user()?->name ?? 'admin' }}</strong>
            <form method="POST" action="{{ route('admin.logout') }}">
                @csrf
                <x-blatui.button variant="outline" size="sm" type="submit">exit</x-blatui.button>
            </form>
        </div>
    </x-blatui.sidebar-footer>
</x-blatui.sidebar>
