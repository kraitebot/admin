<x-app-layout :activeSection="'system'" :activeHighlight="'lifecycle'" :flush="false">
    <div class="px-4 sm:px-6 lg:px-12 py-6">
        <div class="flex items-end justify-between gap-4 flex-wrap mb-6">
            <div>
                <h1 class="text-2xl font-semibold ui-text">Lifecycle</h1>
                <p class="text-sm ui-text-muted mt-1">
                    Position-lifecycle configurator. Walk a portfolio of up to 6 ladders frame-by-frame, manually
                    advancing time to study cascades, kill-switches, and rescue exits.
                </p>
            </div>
            <a href="{{ route('system.lifecycle.create') }}"
               wire:navigate
               class="ui-btn ui-btn-primary ui-btn-sm">
                New scenario
            </a>
        </div>

        @if($scenarios->isEmpty())
            <div class="ui-card p-10 text-center">
                <p class="text-sm ui-text-muted">No scenarios yet. Create the first one to start walking a cascade.</p>
            </div>
        @else
            <div class="ui-card p-0 overflow-hidden">
                <table class="ui-table ui-data-table w-full">
                    <thead>
                        <tr>
                            <th class="text-left">Name</th>
                            <th class="text-left">Side</th>
                            <th class="text-right">Tokens</th>
                            <th class="text-right">Frames</th>
                            <th class="text-left">Lineage</th>
                            <th class="text-left">Updated</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($scenarios as $scenario)
                            <tr>
                                <td class="font-medium ui-text">
                                    <a href="{{ route('system.lifecycle.show', $scenario) }}"
                                       wire:navigate
                                       class="hover:ui-text-primary transition-colors">
                                        {{ $scenario->name }}
                                    </a>
                                </td>
                                <td>
                                    <x-hub-ui::badge :type="$scenario->side === 'LONG' ? 'success' : 'danger'">
                                        {{ $scenario->side }}
                                    </x-hub-ui::badge>
                                </td>
                                <td class="text-right ui-tabular">{{ $scenario->tokens_count }}</td>
                                <td class="text-right ui-tabular">{{ $scenario->frames_count }}</td>
                                <td class="ui-text-muted text-xs">
                                    @if($scenario->parent_scenario_id)
                                        Branched from
                                        <a href="{{ route('system.lifecycle.show', $scenario->parent_scenario_id) }}"
                                           class="hover:ui-text-primary"
                                           wire:navigate>
                                            {{ $scenario->parent->name ?? '#'.$scenario->parent_scenario_id }}
                                        </a>
                                        @ T{{ $scenario->branched_from_t_index }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="ui-text-muted text-xs">{{ $scenario->updated_at?->diffForHumans() }}</td>
                                <td class="text-right">
                                    <a href="{{ route('system.lifecycle.show', $scenario) }}"
                                       wire:navigate
                                       class="ui-btn ui-btn-ghost ui-btn-sm">
                                        Open
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-app-layout>
