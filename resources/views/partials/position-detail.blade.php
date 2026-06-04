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
        <div class="rounded-control border border-line-soft bg-surface overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full border-collapse min-w-[620px]">
                    <thead>
                        <tr style="background: var(--fg-1); color: var(--bg-elev-1);">
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
                        @foreach($d['orders'] as $o)
                            @php $stColor = $orderStatusColors[$o['status']] ?? $orderStatusColors['NEW']; @endphp
                            <tr class="last:[&>td]:border-0">
                                <td class="{{ $oc }}">
                                    <span class="inline-flex font-mono text-[9.5px] font-bold tracking-[0.06em] rounded-chip py-[3px] px-2 {{ $orderTypeClasses[$o['type']] ?? $orderTypeClasses['LIMIT'] }}">{{ $o['type'] }}</span>
                                </td>
                                <td class="{{ $oc }} font-semibold {{ $o['side'] === 'BUY' ? 'text-pnlup' : 'text-pnldown' }}">{{ $o['side'] }}</td>
                                <td class="{{ $oc }}">
                                    <span class="inline-flex items-center gap-[6px] text-[10.5px] font-semibold tracking-[0.04em]" style="color: {{ $stColor }};">
                                        <span class="w-1.5 h-1.5 rounded-chip" style="background: {{ $stColor }};"></span>{{ $o['status'] }}
                                    </span>
                                </td>
                                <td class="{{ $oc }} text-fg-2">{{ $o['qty'] }}</td>
                                <td class="{{ $oc }}">{{ $o['price'] }}</td>
                                <td class="{{ $oc }} text-fg-3 text-[10.5px]">{{ $fmtTime($o['opened']) }}</td>
                                <td class="{{ $oc }} text-[10.5px] {{ $o['filled'] ? 'text-fg-3' : 'text-fg-mute' }}">{{ $o['filled'] ? $fmtTime($o['filled']) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
