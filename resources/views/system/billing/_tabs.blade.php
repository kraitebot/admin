<nav class="flex gap-2" aria-label="Billing administration">
    @foreach([
        'users' => ['label' => 'Users', 'route' => 'system.users'],
        'plans' => ['label' => 'Plans', 'route' => 'system.billing.plans'],
        'coins' => ['label' => 'Coins', 'route' => 'system.billing.coins'],
    ] as $key => $tab)
        <a href="{{ route($tab['route']) }}"
           @class([
               $button,
               'no-underline',
               'bg-transparent text-fg-2 border border-line' => $active !== $key,
           ])>
            {{ $tab['label'] }}
        </a>
    @endforeach
</nav>
