@props(['active'])

@php
    $tabs = [
        'users' => ['label' => 'Users', 'route' => 'system.users'],
        'plans' => ['label' => 'Plans', 'route' => 'system.billing.plans'],
    ];
@endphp

<div class="flex items-center gap-1 mb-4 border-b ui-border">
    @foreach ($tabs as $key => $tab)
        <a
            href="{{ route($tab['route']) }}"
            class="px-4 py-2 text-sm font-medium border-b-2 transition-colors -mb-px"
            @if ($active === $key)
                style="color: rgb(var(--ui-primary)); border-color: rgb(var(--ui-primary))"
            @else
                style="color: rgb(var(--ui-text-muted)); border-color: transparent"
            @endif
        >
            {{ $tab['label'] }}
        </a>
    @endforeach
</div>
