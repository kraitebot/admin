{{-- Scheduler Tile Component --}}
@props(['schedule' => []])

<div class="scheduler-tile">
    <div class="scheduler-tile__header">
        <x-feathericon-clock class="w-4 h-4" />
        <span class="scheduler-tile__title">SCHEDULER</span>
        <span class="scheduler-tile__count" x-text="schedule.length + ' tasks'"></span>
    </div>
    <div class="scheduler-tile__list">
        <template x-for="task in schedule" :key="task.command + task.arguments">
            <div class="scheduler-tile__item">
                <div class="scheduler-tile__icon">
                    <x-feathericon-terminal class="w-4 h-4" />
                </div>
                <div class="scheduler-tile__content">
                    <div class="scheduler-tile__cmd">
                        <span class="scheduler-tile__name" x-text="task.command"></span>
                        <span x-show="task.arguments" class="scheduler-tile__args" x-text="task.arguments"></span>
                    </div>
                    <div class="scheduler-tile__meta">
                        <span class="scheduler-tile__freq">
                            <x-feathericon-repeat class="w-3 h-3" />
                            <span x-text="task.frequency"></span>
                        </span>
                        <span class="scheduler-tile__next">
                            <x-feathericon-play class="w-3 h-3" />
                            <span x-text="task.next_due"></span>
                        </span>
                    </div>
                </div>
            </div>
        </template>
    </div>
</div>

@once
<style>
    /* Scheduler Tile — Mobile First */
    .scheduler-tile {
        background: rgb(var(--ui-bg-card));
        border: 1px solid rgb(var(--ui-border));
        border-radius: 10px;
        overflow: hidden;
    }
    .scheduler-tile__header {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 10px 14px;
        border-bottom: 1px solid rgb(var(--ui-border) / 0.5);
        color: rgb(var(--ui-primary));
    }
    .scheduler-tile__title {
        flex: 1;
        font-size: 11px;
        font-weight: 600;
        letter-spacing: 0.08em;
        color: rgb(var(--ui-text-muted));
    }
    .scheduler-tile__count {
        font-size: 11px;
        color: rgb(var(--ui-text-subtle));
    }
    .scheduler-tile__list {
        display: flex;
        flex-direction: column;
        padding: 6px 0;
    }
    .scheduler-tile__item {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        padding: 10px 14px;
        border-bottom: 1px solid rgb(var(--ui-border) / 0.15);
        transition: background 0.15s;
    }
    .scheduler-tile__item:hover {
        background: rgb(var(--ui-bg-elevated) / 0.3);
    }
    .scheduler-tile__item:last-child {
        border-bottom: none;
    }
    .scheduler-tile__icon {
        flex-shrink: 0;
        width: 28px;
        height: 28px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 6px;
        background: rgb(var(--ui-bg-elevated));
        color: rgb(var(--ui-text-muted));
    }
    .scheduler-tile__content {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 4px;
    }
    .scheduler-tile__cmd {
        display: flex;
        flex-direction: column;
        gap: 1px;
    }
    .scheduler-tile__name {
        font-family: 'JetBrains Mono', monospace;
        font-size: 12px;
        font-weight: 600;
        color: rgb(var(--ui-text));
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .scheduler-tile__args {
        font-family: 'JetBrains Mono', monospace;
        font-size: 10px;
        color: rgb(var(--ui-text-subtle));
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .scheduler-tile__meta {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 8px;
        margin-top: 2px;
    }
    .scheduler-tile__freq,
    .scheduler-tile__next {
        display: flex;
        align-items: center;
        gap: 5px;
        font-size: 11px;
        color: rgb(var(--ui-text-subtle));
    }
    .scheduler-tile__freq svg,
    .scheduler-tile__next svg {
        opacity: 0.6;
    }
    .scheduler-tile__next {
        color: rgb(var(--ui-success));
    }
    .scheduler-tile__next svg {
        opacity: 0.8;
    }

    /* Tablet+ */
    @media (min-width: 640px) {
        .scheduler-tile__meta {
            gap: 14px;
        }
    }
</style>
@endonce
