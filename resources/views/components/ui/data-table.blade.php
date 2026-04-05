@props([
    'columns' => [],
    'rows' => [],
    'empty' => 'No results.',
])

<div {{ $attributes->merge(['class' => 'overflow-x-auto rounded-lg border border-white/10']) }}>
    <table class="w-full text-sm text-left">
        @if(count($columns))
            <thead class="text-xs uppercase tracking-wider text-zinc-400 bg-white/5 border-b border-white/10">
                <tr>
                    @foreach($columns as $col)
                        <th class="px-4 py-3 font-medium whitespace-nowrap">{{ $col }}</th>
                    @endforeach
                </tr>
            </thead>
        @endif
        <tbody class="divide-y divide-white/5">
            @forelse($rows as $row)
                <tr class="hover:bg-white/5 transition-colors">
                    @foreach($columns as $col)
                        <td class="px-4 py-2.5 text-zinc-300 whitespace-nowrap max-w-xs truncate" title="{{ $row[$col] ?? '' }}">
                            {{ $row[$col] ?? '' }}
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($columns) ?: 1 }}" class="px-4 py-8 text-center text-zinc-500">
                        {{ $empty }}
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if(count($rows))
    <p class="text-xs text-zinc-500 mt-2">{{ count($rows) }} {{ Str::plural('row', count($rows)) }}</p>
@endif
