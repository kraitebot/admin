{{-- Expandable position record: four summary groups + the orders table.
     Slides open under a position row (open or closed — same record shape).
     Expects from parent scope: $p (row), $d (detail from $buildDetail),
     $reasonMeta, $fmtTime, $usd0, $usdSigned. --}}
@php
    $long = $p['side'] === 'long';
    $tint = $long ? 'var(--pnl-up-fg)' : 'var(--pnl-down-fg)';
    $rMeta = $d['closed'] ? ($reasonMeta[$d['reason']] ?? $reasonMeta['manual']) : null;

    $orderTypeClasses = [
        'PROFIT'        => 'text-pnlup bg-pnlup-bg',
        'MARKET'        => 'text-fg-2 bg-surface-3',
        'LIMIT'         => 'text-fg-2 bg-surface-3',
        'CANCEL-MARKET' => 'text-pnldown bg-pnldown-bg',
    ];
    $orderStatusColors = [
        'FILLED'    => 'var(--pnl-up-fg)',
        'NEW'       => 'var(--warn)',
        'CANCELLED' => 'var(--fg-mute)',
    ];

    $oh = 'font-mono text-[9px] font-bold tracking-[0.1em] uppercase py-[7px] px-2.5 text-center whitespace-nowrap';
    $oc = 'py-[9px] px-2.5 border-b border-line-soft text-center whitespace-nowrap font-mono text-[11.5px] tabular-nums text-fg-1';
    $groupBand = 'font-mono text-[9.5px] font-bold tracking-[0.12em] uppercase flex items-center justify-center gap-[7px] py-[7px] px-3';
    $kvRow = 'flex items-baseline justify-between gap-3 py-[5px] border-b border-line-soft last:border-0';
    $kvLabel = 'text-[11px] text-fg-mute whitespace-nowrap';
    $kvValue = 'text-[12.5px] font-semibold text-right';
@endphp
<div class="border-t border-line-soft px-5 py-5" style="background: color-mix(in srgb, {{ $tint }} 5%, var(--bg-elev-3));">

    {{-- Live reconcile banner — populated by the 5-min DB↔exchange check
         (global $store.reconcile, keyed by position id). Open positions only. --}}
    @isset($p['rowId'])
        <div x-show="$store.reconcile && $store.reconcile.drift[{{ $p['rowId'] }}]" x-cloak
             class="flex items-start gap-2.5 mb-4 py-2.5 px-3.5 rounded-control text-[12px] leading-[1.45] border"
             style="background: color-mix(in srgb, var(--warn) 10%, transparent); border-color: color-mix(in srgb, var(--warn) 38%, transparent); color: var(--warn);">
            <x-feathericon-alert-triangle class="w-[15px] h-[15px] flex-shrink-0 mt-0.5" stroke-width="1.75"/>
            <span>
                <strong class="font-bold">Out of sync with the exchange.</strong>
                <span x-show="($store.reconcile?.drift[{{ $p['rowId'] }}]?.posFields || []).length" x-text="' Position differs on: ' + ($store.reconcile?.drift[{{ $p['rowId'] }}]?.posFields || []).join(', ') + '.'"></span>
                <span x-show="$store.reconcile?.drift[{{ $p['rowId'] }}]?.orderDrift" x-text="' ' + ($store.reconcile?.drift[{{ $p['rowId'] }}]?.orderDrift || 0) + ' order(s) drifting.'"></span>
                <span class="text-fg-3"> Re-checked every 5 min while this page is open.</span>
            </span>
        </div>
    @endisset

    {{-- Summary groups --}}
    <div class="grid grid-cols-4 gap-3 max-[1100px]:grid-cols-2 max-[620px]:grid-cols-1">

        <div class="rounded-control border border-line-soft bg-surface overflow-hidden">
            <div class="{{ $groupBand }}" style="background: var(--fg-1); color: var(--bg-elev-1);">
                <x-feathericon-shield class="w-3 h-3" stroke-width="1.75"/>Summary
            </div>
            <div class="px-3.5 py-2.5">
                <div class="{{ $kvRow }}"><span class="{{ $kvLabel }}">Account</span><span class="font-sans {{ $kvValue }} text-fg-1">{{ $d['account'] }}</span></div>
                <div class="{{ $kvRow }}"><span class="{{ $kvLabel }}">Exchange</span><span class="font-sans {{ $kvValue }} text-fg-1">{{ $d['exch'] }}</span></div>
                <div class="{{ $kvRow }}"><span class="{{ $kvLabel }}">Direction</span><span class="font-mono tabular-nums {{ $kvValue }} {{ $long ? 'text-pnlup' : 'text-pnldown' }}">{{ $long ? 'LONG' : 'SHORT' }}</span></div>
                <div class="{{ $kvRow }}"><span class="{{ $kvLabel }}">Status</span>
                    @if($d['closed'])
                        <span class="font-mono tabular-nums {{ $kvValue }}" style="color: {{ $rMeta['color'] }};">CLOSED · {{ $rMeta['label'] }}</span>
                    @else
                        <span class="font-mono tabular-nums {{ $kvValue }} text-pnlup">ACTIVE</span>
                    @endif
                </div>
            </div>
        </div>

        <div class="rounded-control border border-line-soft bg-surface overflow-hidden">
            <div class="{{ $groupBand }}" style="background: var(--fg-1); color: var(--bg-elev-1);">
                <x-feathericon-clock class="w-3 h-3" stroke-width="1.75"/>Timing
            </div>
            <div class="px-3.5 py-2.5">
                <div class="{{ $kvRow }}"><span class="{{ $kvLabel }}">Opened</span><span class="font-mono tabular-nums {{ $kvValue }} text-fg-1">{{ $fmtTime($d['openedAt']) }}</span></div>
                <div class="{{ $kvRow }}"><span class="{{ $kvLabel }}">Closed</span><span class="font-mono tabular-nums {{ $kvValue }} {{ $d['closed'] ? 'text-fg-1' : 'text-fg-mute' }}">{{ $d['closed'] ? $fmtTime($d['closedAt']) : '—' }}</span></div>
                <div class="{{ $kvRow }}"><span class="{{ $kvLabel }}">Duration</span><span class="font-mono tabular-nums {{ $kvValue }} text-fg-1">{{ $d['duration'] }}</span></div>
                <div class="{{ $kvRow }}"><span class="{{ $kvLabel }}">Timeframe</span><span class="font-mono tabular-nums {{ $kvValue }} text-fg-1">{{ $d['tf'] }}</span></div>
            </div>
        </div>

        <div class="rounded-control border border-line-soft bg-surface overflow-hidden">
            <div class="{{ $groupBand }}" style="background: var(--fg-1); color: var(--bg-elev-1);">
                <x-feathericon-database class="w-3 h-3" stroke-width="1.75"/>Sizing
            </div>
            <div class="px-3.5 py-2.5">
                <div class="{{ $kvRow }}"><span class="{{ $kvLabel }}">Leverage</span><span class="font-mono tabular-nums {{ $kvValue }} text-fg-1">{{ $d['leverage'] }}</span></div>
                <div class="{{ $kvRow }}"><span class="{{ $kvLabel }}">Margin</span><span class="font-mono tabular-nums {{ $kvValue }} text-fg-1">{{ $usd0($d['margin']) }}</span></div>
                <div class="{{ $kvRow }}"><span class="{{ $kvLabel }}">Quantity</span><span class="font-mono tabular-nums {{ $kvValue }} text-fg-1">{{ $d['qty'] }}</span></div>
                <div class="{{ $kvRow }}"><span class="{{ $kvLabel }}">Limit orders</span><span class="font-mono tabular-nums {{ $kvValue }} text-fg-1">{{ $d['total'] }}</span></div>
            </div>
        </div>

        <div class="rounded-control border border-line-soft bg-surface overflow-hidden">
            <div class="{{ $groupBand }}" style="background: var(--fg-1); color: var(--bg-elev-1);">
                <x-feathericon-activity class="w-3 h-3" stroke-width="1.75"/>Performance
            </div>
            <div class="px-3.5 py-2.5">
                <div class="{{ $kvRow }}"><span class="{{ $kvLabel }}">Opening price</span><span class="font-mono tabular-nums {{ $kvValue }} text-fg-1">{{ $d['openPrice'] }}</span></div>
                <div class="{{ $kvRow }}"><span class="{{ $kvLabel }}">{{ $d['closed'] ? 'Closing price' : 'Mark price' }}</span><span class="font-mono tabular-nums {{ $kvValue }} text-fg-1">{{ $d['markPrice'] }}</span></div>
                <div class="{{ $kvRow }}"><span class="{{ $kvLabel }}">{{ $d['closed'] ? 'Realized P&L' : 'Net P&L' }}</span><span class="font-mono tabular-nums {{ $kvValue }} {{ $d['pnl'] >= 0 ? 'text-pnlup' : 'text-pnldown' }}">{{ $usdSigned($d['pnl']) }}</span></div>
                <div class="{{ $kvRow }}"><span class="{{ $kvLabel }}">Profit target</span><span class="font-mono tabular-nums {{ $kvValue }} text-pnlup">+{{ number_format($d['tpPct'], 1) }}%</span></div>
                <div class="{{ $kvRow }}"><span class="{{ $kvLabel }}">Stop-loss</span><span class="font-mono tabular-nums {{ $kvValue }} text-pnldown">−{{ $d['slPct'] }}%</span></div>
                <div class="{{ $kvRow }}"><span class="{{ $kvLabel }}">First profit price</span><span class="font-mono tabular-nums {{ $kvValue }} text-fg-1">{{ $d['firstProfit'] }}</span></div>
            </div>
        </div>
    </div>

    {{-- Orders --}}
    <div class="mt-4">
        <div class="font-mono text-[9.5px] font-semibold tracking-[0.12em] uppercase text-fg-3 flex items-center gap-[7px] mb-2">
            <x-feathericon-layers class="w-3 h-3 text-fg-mute" stroke-width="1.75"/>Orders <span class="text-fg-mute">· {{ count($d['orders']) }}</span>
        </div>
        <div class="rounded-control border border-line-soft bg-surface overflow-hidden"
             x-data="{
                openSync: {},
                // warn/mute cell style for the EXCHANGE ghost row, per drift field
                exCell(oid, field) {
                    return ($store.reconcile?.orderDrift?.[oid]?.fields || []).includes(field)
                        ? 'color: var(--warn); font-weight: 600; background: color-mix(in srgb, var(--warn) 12%, transparent)'
                        : 'color: var(--fg-mute)';
                },
                // dotted-underline marker for a DB cell that disagrees with the exchange
                dbMark(oid, field) {
                    return ($store.reconcile?.orderDrift?.[oid]?.fields || []).includes(field)
                        ? 'text-decoration: underline dotted; text-decoration-color: var(--warn); text-underline-offset: 3px;'
                        : '';
                },
             }">
            @php
                // Fixed column template shared by the orders table AND the nested
                // reconcile-panel table, so the EXCHANGE row lines up exactly.
                $oColW = ['36px', '16%', '8%', '13%', '10%', '13%', '20%', '20%'];
            @endphp
            <div class="overflow-x-auto">
                <table class="w-full border-collapse table-fixed min-w-[700px]">
                    <colgroup>
                        @foreach($oColW as $w)<col style="width: {{ $w }};">@endforeach
                    </colgroup>
                    <thead>
                        <tr style="background: var(--fg-1); color: var(--bg-elev-1);">
                            <th class="{{ $oh }} w-[36px]" aria-label="Sync"></th>
                            <th class="{{ $oh }}">Type</th>
                            <th class="{{ $oh }}">Side</th>
                            <th class="{{ $oh }}">Status</th>
                            <th class="{{ $oh }}">Qty</th>
                            <th class="{{ $oh }}">Price</th>
                            <th class="{{ $oh }}">Opened</th>
                            <th class="{{ $oh }}">Filled</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($d['orders'] as $oi => $o)
                            @php
                                $stColor = $orderStatusColors[$o['status']] ?? $orderStatusColors['NEW'];
                                $oid = $o['id'] ?? null;
                                $drift = $oid ? "\$store.reconcile?.orderDrift?.[{$oid}]" : 'null';
                            @endphp
                            {{-- DB row. Live reconcile (5-min) marks the specific drifting
                                 order via the global store; the row becomes clickable and
                                 expands the EXCHANGE ghost row below it. --}}
                            <tr @if($oid) @click="{{ $drift }} && (openSync[{{ $oid }}] = !openSync[{{ $oid }}])"
                                :class="{{ $drift }} ? 'cursor-pointer transition-colors duration-fast ease-out' : ''"
                                :style="{{ $drift }} ? `background: color-mix(in srgb, var(--warn) ${openSync[{{ $oid }}] ? 11 : 6}%, transparent)` : ''" @endif>
                                <td class="{{ $oc }} px-1.5">
                                    @if($oid)
                                        <template x-if="{{ $drift }}">
                                            <button type="button" @click.stop="openSync[{{ $oid }}] = !openSync[{{ $oid }}]" title="Out of sync with the exchange — expand to compare"
                                                    class="appearance-none cursor-pointer bg-transparent border-0 inline-flex items-center gap-0.5 p-0.5 rounded-[6px] transition-colors duration-fast">
                                                <span class="text-warn"><x-feathericon-alert-triangle class="w-3.5 h-3.5" stroke-width="1.75"/></span>
                                                <span class="text-warn transition-transform duration-[200ms]" :class="openSync[{{ $oid }}] ? 'rotate-180' : ''"><x-feathericon-chevron-down class="w-[11px] h-[11px]" stroke-width="2"/></span>
                                            </button>
                                        </template>
                                    @endif
                                </td>
                                <td class="{{ $oc }}">
                                    <span class="inline-flex font-mono text-[9.5px] font-bold tracking-[0.06em] rounded-chip py-[3px] px-2 {{ $orderTypeClasses[$o['type']] ?? $orderTypeClasses['LIMIT'] }}">{{ $o['type'] }}</span>
                                </td>
                                <td class="{{ $oc }} font-semibold {{ $o['side'] === 'BUY' ? 'text-pnlup' : 'text-pnldown' }}" @if($oid) :style="dbMark({{ $oid }}, 'side')" @endif>{{ $o['side'] }}</td>
                                <td class="{{ $oc }}">
                                    <span class="inline-flex items-center gap-[6px] text-[10.5px] font-semibold tracking-[0.04em]" style="color: {{ $stColor }};" @if($oid) :style="dbMark({{ $oid }}, 'status')" @endif>
                                        <span class="w-1.5 h-1.5 rounded-chip" style="background: {{ $stColor }};"></span>{{ $o['status'] }}
                                    </span>
                                </td>
                                <td class="{{ $oc }} text-fg-2" @if($oid) :style="dbMark({{ $oid }}, 'quantity')" @endif>{{ $o['qty'] }}</td>
                                <td class="{{ $oc }}" @if($oid) :style="dbMark({{ $oid }}, 'price')" @endif>{{ $o['price'] }}</td>
                                <td class="{{ $oc }} text-fg-3 text-[10.5px]">{{ $fmtTime($o['opened']) }}</td>
                                <td class="{{ $oc }} text-[10.5px] {{ $o['filled'] ? 'text-fg-3' : 'text-fg-mute' }}">{{ $o['filled'] ? $fmtTime($o['filled']) : '—' }}</td>
                            </tr>
                            @if($oid)
                                {{-- EXCHANGE ghost row — slides open when the order drifts and
                                     the user expands it. Values come live from the store. --}}
                                <tr :aria-hidden="!(openSync[{{ $oid }}] && {{ $drift }})">
                                    <td colspan="8" class="p-0 border-0">
                                        <div x-show="openSync[{{ $oid }}] && {{ $drift }}" x-collapse.duration.360ms x-cloak>
                                            <div style="background: color-mix(in srgb, var(--warn) 6%, transparent);">
                                                <table class="w-full border-collapse table-fixed">
                                                    <colgroup>
                                                        @foreach($oColW as $w)<col style="width: {{ $w }};">@endforeach
                                                    </colgroup>
                                                    <tbody>
                                                        <tr>
                                                            <td class="{{ $oc }} px-1.5"><span class="font-mono text-[13px] leading-none" style="color: var(--fg-faint);">↳</span></td>
                                                            <td class="{{ $oc }}">
                                                                <span class="inline-flex items-center gap-1 font-mono text-[9px] font-bold tracking-[0.06em] rounded-chip py-[3px] px-2" style="color: var(--warn); background: color-mix(in srgb, var(--warn) 14%, transparent);">
                                                                    <x-feathericon-server class="w-[10px] h-[10px]" stroke-width="1.75"/>EXCHANGE
                                                                </span>
                                                            </td>
                                                            <td class="{{ $oc }}" :style="exCell({{ $oid }}, 'side')" x-text="{{ $drift }}?.exchange?.side ?? '—'"></td>
                                                            <td class="{{ $oc }}">
                                                                <span class="inline-flex items-center gap-[6px] text-[10.5px] font-semibold tracking-[0.04em]" :style="exCell({{ $oid }}, 'status')">
                                                                    <span class="w-1.5 h-1.5 rounded-chip" style="background: var(--warn);"></span><span x-text="{{ $drift }}?.exchange?.status ?? '—'"></span>
                                                                </span>
                                                            </td>
                                                            <td class="{{ $oc }}" :style="exCell({{ $oid }}, 'quantity')" x-text="{{ $drift }}?.exchange?.quantity ?? '—'"></td>
                                                            <td class="{{ $oc }}" :style="exCell({{ $oid }}, 'price')" x-text="{{ $drift }}?.exchange?.price ?? '—'"></td>
                                                            <td class="{{ $oc }} text-fg-mute text-[10.5px]">—</td>
                                                            <td class="{{ $oc }} text-fg-mute text-[10.5px]">—</td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                                <div class="px-3 py-2.5 border-b border-line-soft flex items-center gap-3 flex-wrap">
                                                    <span class="inline-flex items-center gap-2 text-[11.5px] text-fg-2 leading-snug">
                                                        <span class="flex-shrink-0 text-warn"><x-feathericon-alert-triangle class="w-[13px] h-[13px]" stroke-width="1.75"/></span>
                                                        <span>Out of sync with <span class="font-semibold text-fg-1 whitespace-nowrap">{{ $d['exch'] }}</span> · differs on <span class="font-semibold text-warn" x-text="({{ $drift }}?.fields || []).join(' · ')"></span>. Kraite's record is on top; the exchange values are highlighted.</span>
                                                    </span>
                                                    <span class="flex-1"></span>
                                                    <button type="button" @click.stop="$dispatch('positions-reconcile')" :disabled="$store.reconcile?.checking"
                                                            class="appearance-none cursor-pointer inline-flex items-center gap-1.5 whitespace-nowrap rounded-control border border-line bg-surface-3 text-fg-1 font-sans text-[11.5px] font-semibold py-[6px] px-3 transition-colors duration-fast ease-out hover:border-line-strong disabled:opacity-60 disabled:cursor-default">
                                                        <template x-if="$store.reconcile?.checking">
                                                            <span class="inline-flex items-center gap-1.5"><span class="w-3 h-3 rounded-full border-2 border-line-strong border-t-fg-1 animate-spin"></span>Re-checking…</span>
                                                        </template>
                                                        <template x-if="!$store.reconcile?.checking">
                                                            <span class="inline-flex items-center gap-1.5"><x-feathericon-refresh-cw class="w-[13px] h-[13px]" stroke-width="1.75"/>Re-check</span>
                                                        </template>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
