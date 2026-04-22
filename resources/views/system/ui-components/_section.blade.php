@props(['title', 'description' => null])

<div class="pt-2">
    <div class="flex items-baseline justify-between flex-wrap gap-2 mb-3">
        <h2 class="text-sm font-semibold ui-text tracking-tight">{{ $title }}</h2>
        @if($description)
            <span class="text-[11px] ui-text-subtle">{{ $description }}</span>
        @endif
    </div>
</div>
