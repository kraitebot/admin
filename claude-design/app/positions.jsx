// Kraite admin — Positions page.
// Dense sortable table of open positions with an expandable inline detail row
// (position record: summary groups + live orders table), plus a separate
// closed/historical section below. Reuses dashboard tokens + atoms.
// Directional semantics throughout: green = long/buy, red = short/sell.

// ---------- small formatters ----------
const num = (s) => parseFloat(String(s).replace(/,/g, '')) || 0;
const usd0 = (n) => '$' + Math.round(n).toLocaleString('en-US');
const usdSigned = (n) => (n >= 0 ? '+$' : '−$') + Math.abs(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const pctSigned = (n) => (n >= 0 ? '+' : '−') + Math.abs(n).toFixed(2) + '%';
const fmtAge = (h) => {
  if (h < 1) return Math.round(h * 60) + 'm';
  if (h < 24) return Math.round(h) + 'h';
  const d = Math.floor(h / 24), r = Math.round(h % 24);
  return d + 'd' + (r ? ' ' + r + 'h' : '');
};

// closed-position close-reason metadata
const REASON = {
  tp:     { label: 'TP HIT', color: 'var(--pnl-up-fg)' },
  stop:   { label: 'STOP',   color: 'var(--pnl-down-fg)' },
  manual: { label: 'MANUAL', color: 'var(--fg-mute)' },
  regime: { label: 'REGIME', color: 'var(--bsi-blackswan)' },
};

// ---------- coin glyph (CMC icon → mono fallback) ----------
const Coin = ({ p, size = 26 }) => {
  const [ok, setOk] = React.useState(true);
  return (
    <span className="rounded-full flex items-center justify-center font-mono font-bold text-white flex-shrink-0 overflow-hidden"
      style={{ width: size, height: size, fontSize: size * 0.42, background: ok ? 'transparent' : p.color }}>
      {ok
        ? <img src={`https://s2.coinmarketcap.com/static/img/coins/64x64/${p.cmcId}.png`} alt={p.sym} onError={() => setOk(false)} className="block w-full h-full object-cover"/>
        : p.sym[0]}
    </span>
  );
};

// ---------- side tag (green long / red short) ----------
const SideTag = ({ side, lev }) => {
  const long = side === 'long';
  return (
    <span className={"inline-flex items-center gap-1 font-mono text-[10px] font-bold tracking-[0.07em] uppercase rounded-chip py-[3px] px-2 whitespace-nowrap " + (long ? "bg-pnlup-bg text-pnlup" : "bg-pnldown-bg text-pnldown")}>
      <UIcon name={long ? 'arrowUp' : 'arrowDown'} size={10} style={{ width: 10, height: 10 }}/>{side} {lev}
    </span>
  );
};

// ---------- sortable header cell ----------
const Th = ({ id, label, align = 'right', sort, setSort, w }) => {
  const active = sort.key === id;
  const onClick = () => setSort(s => ({ key: id, dir: s.key === id ? (s.dir === 'asc' ? 'desc' : 'asc') : 'desc' }));
  return (
    <th style={w ? { width: w } : undefined}
      className="font-mono text-[9.5px] font-semibold tracking-[0.1em] uppercase bg-accent text-accent-on py-[11px] px-3 whitespace-nowrap select-none cursor-pointer text-center first:pl-5 last:pr-5 transition-colors duration-fast ease-out hover:bg-accent-hover"
      onClick={onClick}>
      <span className="inline-flex items-center justify-center gap-1">
        {label}
        <span className={"inline-flex transition-opacity duration-fast text-accent-on " + (active ? 'opacity-100' : 'opacity-0')}>
          <UIcon name={active && sort.dir === 'asc' ? 'chevronUp' : 'chevronDown'} size={12}/>
        </span>
      </span>
    </th>
  );
};

// ---------- per-position record (derived deterministically from the row) ----------
// In production these fields resolve from exchange_symbol_id / account_id and the
// order log; here they're synthesised from the position so the panel reads real.
const NOW = new Date('2026-06-02T14:30:00Z');
const EXCHANGES = ['Binance Futures', 'Bybit', 'OKX'];
const ACCTS = ['Kraite-Main', 'Kraite-Alpha', 'Hedge-01', 'Scout-02'];
const MON = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

const decimalsOf = (s) => { const m = String(s).split('.'); return m[1] ? m[1].length : 0; };
const fmtPrice = (n, d) => n.toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d });
const fmtTime = (date) => `${MON[date.getUTCMonth()]} ${date.getUTCDate()}, ${String(date.getUTCHours()).padStart(2,'0')}:${String(date.getUTCMinutes()).padStart(2,'0')} UTC`;
const parseAgo = (s) => {
  let h = 0;
  const d = /(\d+)\s*d/.exec(s); if (d) h += +d[1] * 24;
  const hr = /(\d+)\s*h/.exec(s); if (hr) h += +hr[1];
  const m = /(\d+)\s*m/.exec(s); if (m) h += +m[1] / 60;
  return h || 1;
};

// Works for OPEN rows (p.open/p.tp/p.mark/p.ageH/p.filled) and CLOSED rows
// (p.entry/p.exit/p.durH/p.closedAgo/p.reason), producing the identical record.
const buildDetail = (p) => {
  const closed = p.exit != null;
  let seed = 0; for (const c of p.sym) seed = (seed * 31 + c.charCodeAt(0)) >>> 0;
  const rng = () => { seed = (seed * 1103515245 + 12345) & 0x7fffffff; return seed / 0x7fffffff; };
  const long = p.side === 'long';
  const openStr = closed ? p.entry : p.open;
  const dec = decimalsOf(openStr);
  const openN = num(openStr);
  const qtyDec = decimalsOf(p.size);
  const qtyN = num(p.size);
  const fmtQty = (frac) => (qtyN * frac).toLocaleString('en-US', { minimumFractionDigits: qtyDec, maximumFractionDigits: qtyDec });

  const durH = closed ? p.durH : p.ageH;
  const closedAt = closed ? new Date(NOW.getTime() - parseAgo(p.closedAgo) * 3600 * 1000) : null;
  const openedAt = new Date((closed ? closedAt.getTime() : NOW.getTime()) - durH * 3600 * 1000);

  const levN = parseInt(p.lev, 10) || 2;
  const notional = closed ? Math.round(openN * qtyN) : p.notional;
  const margin = closed ? Math.round(notional / levN) : p.margin;

  const tpPct = closed ? (4 + rng() * 3) : Math.abs((num(p.tp) - openN) / openN) * 100;
  const slPct = 6 + Math.round(rng() * 5);                 // frozen at open
  const tf = ['5m', '15m', '1h', '4h'][Math.floor(rng() * 4)];
  const firstProfit = closed ? fmtPrice(openN * (1 + (long ? 1 : -1) * tpPct / 100), dec) : p.tp;

  const total = closed ? 4 : (parseInt(p.filled.split('/')[1], 10) || 4);
  const filledN = closed ? (1 + Math.floor(rng() * 3)) : (parseInt(p.filled, 10) || 0);

  // orders — entry market, limit ladder (avg-down), then the close (open: live
  // profit target; closed: the realized close + cancelled remaining ladder)
  const entrySide = long ? 'BUY' : 'SELL';
  const closeSide = long ? 'SELL' : 'BUY';
  const orders = [];
  orders.push({
    type: 'MARKET', side: entrySide, status: 'FILLED',
    qty: fmtQty(0.40), price: fmtPrice(openN, dec), opened: openedAt, filled: openedAt,
  });
  // demo: BTC's entry order is OUT OF SYNC with the exchange — the exchange
  // reports a slightly different fill qty/price and a later fill timestamp than
  // what's on record in Kraite's DB. Drives the expandable reconcile sub-row.
  if (!closed && p.sym === 'BTC') {
    orders[0].sync = {
      exchange: {
        type: 'MARKET', side: entrySide, status: 'FILLED',
        qty: (qtyN * 0.40 + 0.002).toLocaleString('en-US', { minimumFractionDigits: qtyDec, maximumFractionDigits: qtyDec }),
        price: fmtPrice(openN - 1.5, dec),
        opened: openedAt,
        filled: new Date(openedAt.getTime() + 60 * 1000),
      },
    };
  }
  for (let i = 0; i < total; i++) {
    const done = i < filledN;
    const px = openN * (1 + (long ? -1 : 1) * 0.012 * (i + 1));
    const t = new Date(openedAt.getTime() + (i + 1) * 22 * 60 * 1000);
    orders.push({
      type: 'LIMIT', side: entrySide,
      status: done ? 'FILLED' : (closed ? 'CANCELLED' : 'NEW'),
      qty: fmtQty(0.15), price: fmtPrice(px, dec),
      opened: openedAt, filled: done ? t : null,
    });
  }
  if (closed) {
    const tp = p.reason === 'tp';
    orders.push({
      type: tp ? 'PROFIT' : 'MARKET', side: closeSide, status: 'FILLED',
      qty: fmtQty(1.0), price: p.exit,
      opened: tp ? openedAt : closedAt, filled: closedAt,
    });
  } else {
    if (p.status === 'waped') orders.push({
      type: 'CANCEL-MARKET', side: closeSide, status: 'CANCELLED',
      qty: fmtQty(0.15), price: fmtPrice(openN * (1 + (long ? -1 : 1) * 0.05), dec),
      opened: new Date(openedAt.getTime() + 90 * 60 * 1000), filled: null,
    });
    orders.push({
      type: 'PROFIT', side: closeSide, status: 'NEW',
      qty: fmtQty(1.0), price: p.tp, opened: openedAt, filled: null,
    });
  }

  return {
    closed, reason: p.reason,
    exch: EXCHANGES[seed % EXCHANGES.length],
    account: ACCTS[(seed >> 3) % ACCTS.length],
    openedAt, closedAt, duration: fmtAge(durH),
    leverage: p.lev, margin, qty: p.size, total,
    openPrice: openStr, markPrice: closed ? p.exit : p.mark, pnl: p.pnl,
    tpPct, slPct, firstProfit, tf,
    orders,
  };
};

// ---------- summary group + key/value row ----------
const KV = ({ label, value, accent, mono = true }) => (
  <div className="flex items-baseline justify-between gap-3 py-[5px] border-b border-line-soft last:border-0">
    <span className="text-[11px] text-fg-mute whitespace-nowrap">{label}</span>
    <span className={(mono ? "font-mono tabular-nums " : "font-sans ") + "text-[12.5px] font-semibold text-right " + (accent || 'text-fg-1')}>{value}</span>
  </div>
);

const Group = ({ icon, title, children }) => (
  <div className="rounded-control border border-line-soft bg-surface overflow-hidden">
    <div className="font-mono text-[9.5px] font-bold tracking-[0.12em] uppercase flex items-center justify-center gap-[7px] py-[7px] px-3"
      style={{ background: 'var(--fg-1)', color: 'var(--bg-elev-1)' }}>
      <UIcon name={icon} size={12}/>{title}
    </div>
    <div className="px-3.5 py-2.5">
      {children}
    </div>
  </div>
);

// ---------- orders-table badges ----------
const OTYPE = {
  'PROFIT':        'text-pnlup bg-pnlup-bg',
  'MARKET':        'text-fg-2 bg-surface-3',
  'LIMIT':         'text-fg-2 bg-surface-3',
  'CANCEL-MARKET': 'text-pnldown bg-pnldown-bg',
};
const OSTATUS = {
  FILLED:    { c: 'var(--pnl-up-fg)' },
  NEW:       { c: 'var(--warn)' },
  CANCELLED: { c: 'var(--fg-mute)' },
};

// Shared FIXED column template so the sync reconcile panel (a nested table that
// slides open inside a colSpan cell) lines up exactly with the orders columns.
const O_COLW = ['36px', '16%', '8%', '13%', '10%', '13%', '20%', '20%'];
const OColGroup = () => (<colgroup>{O_COLW.map((w, i) => <col key={i} style={{ width: w }}/>)}</colgroup>);
// exchange-cell styling: highlight a value that DISAGREES with the DB (amber),
// mute one that matches so the eye lands on the diffs.
const oCmp = (diff) => diff
  ? { color: 'var(--warn)', fontWeight: 600, background: 'color-mix(in srgb, var(--warn) 12%, transparent)' }
  : { color: 'var(--fg-mute)' };
const oDbMark = (diff) => diff ? { textDecoration: 'underline dotted', textDecorationColor: 'var(--warn)', textUnderlineOffset: '3px' } : undefined;

// Slide-animated reconcile panel for an out-of-sync order. Rendered as ONE
// colSpan row whose inner content height animates open/closed via useSlide
// (same slide the position detail uses), so it stays mounted to animate BOTH
// directions. The nested table reuses O_COLW to stay column-aligned.
const OrderSyncPanel = ({ isOpen, oc, ex, exchange, diffNames, dq, dp, df, dood, dsd, dst, exSt, syncing, onResync }) => {
  const { wrapRef, contentRef } = useSlide(isOpen);
  return (
    <tr aria-hidden={!isOpen}>
      <td colSpan={8} className="p-0 border-0">
        <div ref={wrapRef} style={{ overflow: 'hidden' }}>
          <div ref={contentRef} style={{ background: 'color-mix(in srgb, var(--warn) 6%, transparent)' }}>
            <table className="w-full border-collapse table-fixed"><OColGroup/>
              <tbody>
                <tr>
                  <td className={oc + " px-1.5"}><span className="font-mono text-[13px] leading-none" style={{ color: 'var(--fg-faint)' }}>↳</span></td>
                  <td className={oc}>
                    <span className="inline-flex items-center gap-1 font-mono text-[9px] font-bold tracking-[0.06em] rounded-chip py-[3px] px-2" style={{ color: 'var(--warn)', background: 'color-mix(in srgb, var(--warn) 14%, transparent)' }}>
                      <UIcon name="server" size={10} style={{ width: 10, height: 10 }}/>EXCHANGE
                    </span>
                  </td>
                  <td className={oc} style={oCmp(dsd)}>{ex.side}</td>
                  <td className={oc}>
                    <span className="inline-flex items-center gap-[6px] text-[10.5px] font-semibold tracking-[0.04em]" style={dst ? oCmp(true) : { color: 'var(--fg-mute)' }}>
                      <span className="w-1.5 h-1.5 rounded-chip" style={{ background: dst ? 'var(--warn)' : exSt.c }}/>{ex.status}
                    </span>
                  </td>
                  <td className={oc} style={oCmp(dq)}>{ex.qty}</td>
                  <td className={oc} style={oCmp(dp)}>{ex.price}</td>
                  <td className={oc + " text-[10.5px]"} style={oCmp(dood)}>{fmtTime(ex.opened)}</td>
                  <td className={oc + " text-[10.5px]"} style={oCmp(df)}>{fmtTime(ex.filled)}</td>
                </tr>
              </tbody>
            </table>
            <div className="px-3 py-2.5 border-b border-line-soft flex items-center gap-3 flex-wrap">
              <span className="inline-flex items-center gap-2 text-[11.5px] text-fg-2 leading-snug">
                <UIcon name="alert" size={13} style={{ color: 'var(--warn)', flexShrink: 0 }}/>
                Out of sync with <span className="font-semibold text-fg-1 whitespace-nowrap">{exchange}</span> · differs on <span className="font-semibold" style={{ color: 'var(--warn)' }}>{diffNames.join(' · ')}</span>. Kraite's record is shown on top; exchange values are highlighted.
              </span>
              <span className="flex-1"/>
              <button onClick={(e) => { e.stopPropagation(); onResync(); }} disabled={syncing}
                className="appearance-none cursor-pointer inline-flex items-center gap-1.5 whitespace-nowrap rounded-control border border-line bg-surface-3 text-fg-1 font-sans text-[11.5px] font-semibold py-[6px] px-3 transition-colors duration-fast ease-out hover:border-line-strong disabled:opacity-60 disabled:cursor-default">
                {syncing
                  ? <><span className="w-[12px] h-[12px] rounded-full border-2 border-line-strong border-t-fg-1 animate-spin"/>Syncing…</>
                  : <><UIcon name="refresh" size={13}/>Re-sync order</>}
              </button>
            </div>
          </div>
        </div>
      </td>
    </tr>
  );
};

const OrdersTable = ({ orders, exchange }) => {
  const oh = "font-mono text-[9px] font-bold tracking-[0.1em] uppercase py-[7px] px-2.5 text-center whitespace-nowrap";
  const oc = "py-[9px] px-2.5 border-b border-line-soft text-center whitespace-nowrap font-mono text-[11.5px] tabular-nums text-fg-1";
  const [open, setOpen] = React.useState({});
  const [syncing, setSyncing] = React.useState({});
  const [resolved, setResolved] = React.useState({});
  const toggle = (i) => setOpen(s => ({ ...s, [i]: !s[i] }));
  // re-sync: spin briefly, slide the panel up, then resolve to the in-sync check.
  const resync = (i) => {
    setSyncing(s => ({ ...s, [i]: true }));
    setTimeout(() => {
      setSyncing(s => ({ ...s, [i]: false }));
      setOpen(s => ({ ...s, [i]: false }));                              // slide up
      setTimeout(() => setResolved(s => ({ ...s, [i]: true })), 380);   // resolve after the slide
    }, 1000);
  };

  return (
    <div className="rounded-control border border-line-soft bg-surface overflow-hidden">
      <div className="overflow-x-auto">
        <table className="w-full border-collapse table-fixed min-w-[700px]">
          <OColGroup/>
          <thead><tr style={{ background: 'var(--fg-1)', color: 'var(--bg-elev-1)' }}>
            <th className={oh + " w-[36px]"} aria-label="Sync"/>
            <th className={oh}>Type</th>
            <th className={oh}>Side</th>
            <th className={oh}>Status</th>
            <th className={oh}>Qty</th>
            <th className={oh}>Price</th>
            <th className={oh}>Opened</th>
            <th className={oh}>Filled</th>
          </tr></thead>
          <tbody>
            {orders.map((o, i) => {
              const st = OSTATUS[o.status] || OSTATUS.NEW;
              const buy = o.side === 'BUY';
              const mism = !!o.sync && !resolved[i];
              const isOpen = !!open[i];
              const ex = o.sync && o.sync.exchange;
              const dq = ex && ex.qty !== o.qty;
              const dp = ex && ex.price !== o.price;
              const df = ex && fmtTime(ex.filled) !== fmtTime(o.filled);
              const dood = ex && fmtTime(ex.opened) !== fmtTime(o.opened);
              const dsd = ex && ex.side !== o.side;
              const dst = ex && ex.status !== o.status;
              const diffNames = ex ? [dq && 'Qty', dp && 'Price', df && 'Filled', dood && 'Opened', dsd && 'Side', dst && 'Status'].filter(Boolean) : [];
              const exSt = ex ? (OSTATUS[ex.status] || OSTATUS.NEW) : st;
              return (
                <React.Fragment key={i}>
                  <tr onClick={mism ? () => toggle(i) : undefined}
                    className={mism ? "cursor-pointer transition-colors duration-fast ease-out" : ""}
                    style={mism ? { background: isOpen ? 'color-mix(in srgb, var(--warn) 11%, transparent)' : 'color-mix(in srgb, var(--warn) 6%, transparent)' } : undefined}>
                    <td className={oc + " px-1.5"}>
                      {mism ? (
                        <button onClick={(e) => { e.stopPropagation(); toggle(i); }} title="Out of sync with the exchange — expand to reconcile"
                          className="appearance-none cursor-pointer bg-transparent border-0 inline-flex items-center gap-0.5 p-0.5 rounded-[6px] transition-colors duration-fast hover:bg-[color-mix(in_srgb,var(--warn)_18%,transparent)]">
                          <UIcon name="alert" size={14} style={{ color: 'var(--warn)' }}/>
                          <UIcon name="chevronDown" size={11} style={{ color: 'var(--warn)', transform: isOpen ? 'rotate(180deg)' : 'none', transition: 'transform .2s ease' }}/>
                        </button>
                      ) : resolved[i] ? (
                        <UIcon name="check" size={13} style={{ color: 'var(--pnl-up-fg)' }} title="In sync"/>
                      ) : null}
                    </td>
                    <td className={oc}>
                      <span className={"inline-flex font-mono text-[9.5px] font-bold tracking-[0.06em] rounded-chip py-[3px] px-2 " + (OTYPE[o.type] || OTYPE.LIMIT)}>{o.type}</span>
                    </td>
                    <td className={oc + " font-semibold " + (buy ? 'text-pnlup' : 'text-pnldown')}>{o.side}</td>
                    <td className={oc}>
                      <span className="inline-flex items-center gap-[6px] text-[10.5px] font-semibold tracking-[0.04em]" style={{ color: st.c }}>
                        <span className="w-1.5 h-1.5 rounded-chip" style={{ background: st.c }}/>{o.status}
                      </span>
                    </td>
                    <td className={oc + " text-fg-2"} style={mism ? oDbMark(dq) : undefined}>{o.qty}</td>
                    <td className={oc} style={mism ? oDbMark(dp) : undefined}>{o.price}</td>
                    <td className={oc + " text-fg-3 text-[10.5px]"} style={mism ? oDbMark(dood) : undefined}>{fmtTime(o.opened)}</td>
                    <td className={oc + " text-[10.5px] " + (o.filled ? 'text-fg-3' : 'text-fg-mute')} style={mism ? oDbMark(df) : undefined}>{o.filled ? fmtTime(o.filled) : '—'}</td>
                  </tr>

                  {mism && ex && (
                    <OrderSyncPanel isOpen={isOpen} oc={oc} ex={ex} exchange={exchange} diffNames={diffNames}
                      dq={dq} dp={dp} df={df} dood={dood} dsd={dsd} dst={dst} exSt={exSt}
                      syncing={!!syncing[i]} onResync={() => resync(i)}/>
                  )}
                </React.Fragment>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
};

// ---------- expandable detail panel (position record) ----------
const PositionDetail = ({ p }) => {
  const long = p.side === 'long';
  const tint = long ? 'var(--pnl-up-fg)' : 'var(--pnl-down-fg)';
  const d = React.useMemo(() => buildDetail(p), [p.sym]);
  const r = d.closed ? (REASON[d.reason] || REASON.manual) : null;
  return (
    <div className="border-t border-line-soft px-5 py-5"
      style={{ background: `color-mix(in srgb, ${tint} 5%, var(--bg-elev-3))` }}>

      {/* summary groups */}
      <div className="grid grid-cols-4 gap-3 max-[1100px]:grid-cols-2 max-[620px]:grid-cols-1">
        <Group icon="shield" title="Summary">
          <KV label="Account" value={d.account} mono={false}/>
          <KV label="Exchange" value={d.exch} mono={false}/>
          <KV label="Direction" value={<span className={long ? 'text-pnlup' : 'text-pnldown'}>{long ? 'LONG' : 'SHORT'}</span>}/>
          <KV label="Status" value={d.closed
            ? <span style={{ color: r.color }}>CLOSED · {r.label}</span>
            : <span className="text-pnlup">ACTIVE</span>}/>
        </Group>

        <Group icon="clock" title="Timing">
          <KV label="Opened" value={fmtTime(d.openedAt)}/>
          <KV label="Closed" value={d.closed ? fmtTime(d.closedAt) : '—'} accent={d.closed ? 'text-fg-1' : 'text-fg-mute'}/>
          <KV label="Duration" value={d.duration}/>
          <KV label="Timeframe" value={d.tf}/>
        </Group>

        <Group icon="coins" title="Sizing">
          <KV label="Leverage" value={d.leverage}/>
          <KV label="Margin" value={usd0(d.margin)}/>
          <KV label="Quantity" value={d.qty}/>
          <KV label="Limit orders" value={d.total}/>
        </Group>

        <Group icon="activity" title="Performance">
          <KV label="Opening price" value={d.openPrice}/>
          <KV label={d.closed ? 'Closing price' : 'Mark price'} value={d.markPrice}/>
          <KV label={d.closed ? 'Realized P&L' : 'Net P&L'} value={usdSigned(d.pnl)} accent={d.pnl >= 0 ? 'text-pnlup' : 'text-pnldown'}/>
          <KV label="Profit target" value={'+' + d.tpPct.toFixed(1) + '%'} accent="text-pnlup"/>
          <KV label="Stop-loss" value={'−' + d.slPct + '%'} accent="text-pnldown"/>
          <KV label="First profit price" value={d.firstProfit}/>
        </Group>
      </div>

      {/* orders */}
      <div className="mt-4">
        <div className="font-mono text-[9.5px] font-semibold tracking-[0.12em] uppercase text-fg-3 flex items-center gap-[7px] mb-2">
          <UIcon name="layers" size={12} style={{ color: 'var(--fg-mute)' }}/>Orders <span className="text-fg-mute">· {d.orders.length}</span>
        </div>
        <OrdersTable orders={d.orders} exchange={d.exch}/>
      </div>
    </div>
  );
};

// ---------- shared expand/collapse slide ----------
// The detail row lives in a <td>. CSS max-height/height transitions stick at
// their start value inside table-cell layout in some engines, so we drive the
// slide frame-by-frame with requestAnimationFrame (each frame sets an explicit
// height, which renders reliably). A setTimeout fallback commits the final
// open/closed state even if rAF is throttled, and a mount guard skips the
// animation on first paint (no flash). On open we settle to height:auto so the
// panel stays responsive.
const useSlide = (isOpen) => {
  const wrapRef = React.useRef(null);
  const contentRef = React.useRef(null);
  const firstRef = React.useRef(true);
  React.useLayoutEffect(() => {
    const wrap = wrapRef.current, content = contentRef.current;
    if (!wrap || !content) return;
    wrap.style.overflow = 'hidden';
    if (firstRef.current) {                       // initial paint: no animation
      wrap.style.height = isOpen ? 'auto' : '0px';
      firstRef.current = false;
      return;
    }
    const full = content.scrollHeight;
    const from = isOpen ? 0 : full;
    const to = isOpen ? full : 0;
    wrap.style.height = from + 'px';               // committed start (pre-paint, no flash)
    const DUR = 360;
    const ease = (t) => 1 - Math.pow(1 - t, 3);    // cubic-out
    const settle = () => { wrap.style.height = isOpen ? 'auto' : '0px'; };
    let raf, start = null;
    const tick = (ts) => {
      if (start === null) start = ts;
      const pr = Math.min(1, (ts - start) / DUR);
      wrap.style.height = (from + (to - from) * ease(pr)) + 'px';
      if (pr < 1) raf = requestAnimationFrame(tick); else settle();
    };
    raf = requestAnimationFrame(tick);
    const fb = setTimeout(settle, DUR + 140);       // fallback if rAF is throttled
    return () => { cancelAnimationFrame(raf); clearTimeout(fb); };
  }, [isOpen]);
  return { wrapRef, contentRef };
};

// ---------- a single open-position row + its expandable detail ----------
const PositionRow = ({ p, isOpen, onToggle }) => {
  const td = "py-[12px] px-3 border-b border-line-soft whitespace-nowrap tabular-nums font-mono text-[12.5px] text-right text-fg-1";
  const { wrapRef, contentRef } = useSlide(isOpen);
  return (
    <>
      <tr onClick={onToggle}
        className={"cursor-pointer transition-colors duration-fast ease-out hover:bg-hover " + (isOpen ? 'bg-hover' : '')}>
        <td className="py-[12px] pl-5 pr-3 border-b border-line-soft">
          <div className="flex items-center gap-2.5 min-w-0">
            <Coin p={p}/>
            <div className="flex flex-col leading-[1.2] min-w-0">
              <span className="font-sans font-bold text-[13px] text-fg-1 tracking-[-0.01em] whitespace-nowrap">{p.market}</span>
              <span className="text-[11px] text-fg-mute whitespace-nowrap overflow-hidden text-ellipsis">{p.name}</span>
            </div>
            {p.status === 'opening' && <span className="font-mono text-[8.5px] font-bold tracking-[0.08em] uppercase text-fg-3 bg-surface-3 rounded-chip py-0.5 px-1.5">OPENING</span>}
            {p.status === 'waped' && <span className="font-mono text-[8.5px] font-bold tracking-[0.08em] uppercase text-warn bg-[color-mix(in_srgb,var(--warn)_16%,transparent)] rounded-chip py-0.5 px-1.5">WAP'D</span>}
          </div>
        </td>
        <td className="py-[12px] px-3 border-b border-line-soft text-left"><SideTag side={p.side} lev={p.lev}/></td>
        <td className={td}>{p.open}</td>
        <td className={td}>{p.mark}</td>
        <td className={td + " text-warn"}>{p.liq}</td>
        <td className={td}>{usd0(p.notional)}</td>
        <td className={td + " text-fg-3"}>{usd0(p.margin)}</td>
        <td className={"py-[12px] px-3 border-b border-line-soft whitespace-nowrap tabular-nums font-mono text-[12.5px] text-right font-semibold " + (p.pnl >= 0 ? 'text-pnlup' : 'text-pnldown')}>{usdSigned(p.pnl)}</td>
        <td className={"py-[12px] px-3 border-b border-line-soft whitespace-nowrap tabular-nums font-mono text-[12.5px] text-right font-semibold " + (p.roe >= 0 ? 'text-pnlup' : 'text-pnldown')}>{pctSigned(p.roe)}</td>
        <td className={td + " text-fg-3"}>{fmtAge(p.ageH)}</td>
        <td className="py-[12px] pr-5 pl-1 border-b border-line-soft text-right">
          <span className="inline-flex text-fg-mute transition-transform duration-fast ease-out" style={{ transform: isOpen ? 'rotate(180deg)' : 'none' }}><UIcon name="chevronDown" size={16}/></span>
        </td>
      </tr>
      <tr aria-hidden={!isOpen}>
        <td colSpan={11} className="p-0 border-0">
          <div ref={wrapRef} style={{ overflow: 'hidden' }}>
            <div ref={contentRef}>
              <PositionDetail p={p}/>
            </div>
          </div>
        </td>
      </tr>
    </>
  );
};

// ---------- open positions table ----------
const OPEN_SORT = {
  sym:      (p) => p.sym,
  side:     (p) => (p.side === 'long' ? 1 : 0),
  entry:    (p) => num(p.open),
  mark:     (p) => num(p.mark),
  liq:      (p) => num(p.liq),
  notional: (p) => p.notional,
  margin:   (p) => p.margin,
  pnl:      (p) => p.pnl,
  roe:      (p) => p.roe,
  age:      (p) => p.ageH,
};

const OpenTable = () => {
  const [filter, setFilter] = React.useState('ALL');
  const [sort, setSort] = React.useState({ key: 'notional', dir: 'desc' });
  const [open, setOpen] = React.useState(null);

  const rows = React.useMemo(() => {
    const base = POSITIONS.filter(p => filter === 'ALL' || p.side === filter.toLowerCase());
    const get = OPEN_SORT[sort.key] || OPEN_SORT.notional;
    const sorted = [...base].sort((a, b) => {
      const va = get(a), vb = get(b);
      const c = typeof va === 'string' ? va.localeCompare(vb) : va - vb;
      return sort.dir === 'asc' ? c : -c;
    });
    return sorted;
  }, [filter, sort]);

  const thProps = { sort, setSort };
  return (
    <section className="mb-8">
      <div className="flex items-end justify-between gap-4 mb-4 max-[640px]:flex-col max-[640px]:items-start">
        <div>
          <div className="font-sans font-semibold text-[16px] text-fg-1 flex items-center gap-[9px]"><UIcon name="layers" size={17} style={{ color: 'var(--fg-3)' }}/>Open positions</div>
          <div className="text-[12.5px] text-fg-3 mt-1">{rows.length} managed across the lifecycle · click any row for detail</div>
        </div>
        <div className="flex items-center gap-4 flex-shrink-0 max-[640px]:w-full max-[640px]:flex-wrap max-[640px]:gap-y-2.5">
          <Segmented options={['ALL', 'LONG', 'SHORT']} value={filter} onChange={(f) => { setFilter(f); setOpen(null); }}/>
          <button className="inline-flex items-center gap-[9px] h-[34px] border border-line rounded-control bg-surface px-3 cursor-pointer text-[12.5px] text-fg-2 max-w-[280px] transition-colors duration-fast ease-out hover:border-line-strong max-[640px]:max-w-none max-[640px]:flex-1">
            <span className="w-[7px] h-[7px] rounded-chip bg-green-500 flex-shrink-0"/>
            <span className="whitespace-nowrap overflow-hidden text-ellipsis">All accounts</span>
            <UIcon name="chevronDown" size={14} style={{ color: 'var(--fg-mute)' }}/>
          </button>
        </div>
      </div>

      <div className="card overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full border-collapse min-w-[920px]">
            <thead>
              <tr>
                <Th id="sym" label="Market" align="left" w="200px" {...thProps}/>
                <Th id="side" label="Side" align="left" {...thProps}/>
                <Th id="entry" label="Entry" {...thProps}/>
                <Th id="mark" label="Mark" {...thProps}/>
                <Th id="liq" label="Liq." {...thProps}/>
                <Th id="notional" label="Notional" {...thProps}/>
                <Th id="margin" label="Margin" {...thProps}/>
                <Th id="pnl" label="P&amp;L" {...thProps}/>
                <Th id="roe" label="ROE" {...thProps}/>
                <Th id="age" label="Age" {...thProps}/>
                <th className="w-9 bg-accent"/>
              </tr>
            </thead>
            <tbody>
              {rows.map(p => (
                <PositionRow key={p.sym} p={p} isOpen={open === p.sym}
                  onToggle={() => setOpen(open === p.sym ? null : p.sym)}/>
              ))}
            </tbody>
          </table>
        </div>
        <div className={CARD_FOOT}>
          <span className={FOOT_MONO}>{rows.length} OPEN · MAX 6 PER DIRECTION</span>
          <span className="font-mono text-[11px] text-fg-mute tracking-[0.04em] inline-flex items-center gap-[7px]"><span className="w-1.5 h-1.5 rounded-chip bg-green-500"/>LIVE · SYNC 3s</span>
        </div>
      </div>
    </section>
  );
};

// ---------- numbered pager (prev · pages · next) ----------
const Pager = ({ page, pageCount, setPage }) => {
  if (pageCount <= 1) return null;
  const arrow = "appearance-none cursor-pointer w-[26px] h-[26px] inline-flex items-center justify-center rounded-control border border-line bg-surface text-fg-2 transition-colors duration-fast ease-out hover:border-line-strong hover:text-fg-1 disabled:opacity-35 disabled:cursor-not-allowed disabled:hover:border-line disabled:hover:text-fg-2";
  return (
    <div className="flex items-center gap-1.5">
      <button className={arrow} disabled={page === 0} onClick={() => setPage(p => Math.max(0, p - 1))} aria-label="Previous page"><UIcon name="chevronLeft" size={14}/></button>
      {Array.from({ length: pageCount }, (_, i) => (
        <button key={i} onClick={() => setPage(i)} aria-label={`Page ${i + 1}`} aria-current={i === page}
          className={"appearance-none cursor-pointer w-[26px] h-[26px] inline-flex items-center justify-center rounded-control font-mono text-[11px] font-semibold tabular-nums border transition-colors duration-fast ease-out " + (i === page ? "border-accent bg-accent text-accent-on" : "border-line bg-surface text-fg-3 hover:text-fg-1 hover:border-line-strong")}>{i + 1}</button>
      ))}
      <button className={arrow} disabled={page === pageCount - 1} onClick={() => setPage(p => Math.min(pageCount - 1, p + 1))} aria-label="Next page"><UIcon name="chevronRight" size={14}/></button>
    </div>
  );
};

// ---------- closed / historical table ----------
const CLOSED_SORT = {
  sym:    (p) => p.sym,
  side:   (p) => (p.side === 'long' ? 1 : 0),
  entry:  (p) => num(p.entry),
  exit:   (p) => num(p.exit),
  pnl:    (p) => p.pnl,
  roe:    (p) => p.roe,
  dur:    (p) => p.durH,
  reason: (p) => p.reason,
};

const CLOSED_PER = 6;

// ---------- a single closed-position row + its expandable detail ----------
const ClosedRow = ({ p, isOpen, onToggle }) => {
  const { wrapRef, contentRef } = useSlide(isOpen);
  const r = REASON[p.reason] || REASON.manual;
  const td = "py-[11px] px-3 border-b border-line-soft whitespace-nowrap tabular-nums font-mono text-[12.5px] text-right text-fg-1";
  return (
    <>
      <tr onClick={onToggle}
        className={"cursor-pointer transition-colors duration-fast ease-out hover:bg-hover " + (isOpen ? 'bg-hover' : '')}>
        <td className="py-[11px] pl-5 pr-3 border-b border-line-soft">
          <div className="flex items-center gap-2.5">
            <Coin p={p} size={24}/>
            <div className="flex flex-col leading-[1.2]">
              <span className="font-sans font-bold text-[12.5px] text-fg-1 tracking-[-0.01em] whitespace-nowrap">{p.sym}-PERP</span>
              <span className="text-[10.5px] text-fg-mute">closed {p.closedAgo} ago</span>
            </div>
          </div>
        </td>
        <td className="py-[11px] px-3 border-b border-line-soft text-left"><SideTag side={p.side} lev={p.lev}/></td>
        <td className={td + " text-fg-3"}>{p.entry}</td>
        <td className={td}>{p.exit}</td>
        <td className={td + " text-fg-3"}>{fmtAge(p.durH)}</td>
        <td className={"py-[11px] px-3 border-b border-line-soft whitespace-nowrap tabular-nums font-mono text-[12.5px] text-right font-semibold " + (p.pnl >= 0 ? 'text-pnlup' : 'text-pnldown')}>{usdSigned(p.pnl)}</td>
        <td className={"py-[11px] px-3 border-b border-line-soft whitespace-nowrap tabular-nums font-mono text-[12.5px] text-right font-semibold " + (p.roe >= 0 ? 'text-pnlup' : 'text-pnldown')}>{pctSigned(p.roe)}</td>
        <td className="py-[11px] px-3 border-b border-line-soft text-left">
          <span className="inline-flex items-center gap-[6px] font-mono text-[9.5px] font-bold tracking-[0.08em] uppercase rounded-chip py-[3px] px-2 whitespace-nowrap"
            style={{ color: r.color, background: `color-mix(in srgb, ${r.color} 13%, transparent)` }}>
            <span className="w-1.5 h-1.5 rounded-chip" style={{ background: r.color }}/>{r.label}
          </span>
        </td>
        <td className="py-[11px] pr-5 pl-1 border-b border-line-soft text-right">
          <span className="inline-flex text-fg-mute transition-transform duration-fast ease-out" style={{ transform: isOpen ? 'rotate(180deg)' : 'none' }}><UIcon name="chevronDown" size={16}/></span>
        </td>
      </tr>
      <tr aria-hidden={!isOpen}>
        <td colSpan={9} className="p-0 border-0">
          <div ref={wrapRef} style={{ overflow: 'hidden' }}>
            <div ref={contentRef}>
              <PositionDetail p={p}/>
            </div>
          </div>
        </td>
      </tr>
    </>
  );
};

const ClosedTable = () => {
  const [filter, setFilter] = React.useState('ALL');
  const [sort, setSort] = React.useState({ key: 'pnl', dir: 'desc' });
  const [page, setPage] = React.useState(0);
  const [open, setOpen] = React.useState(null);
  const rows = React.useMemo(() => {
    const base = CLOSED.filter(p => filter === 'ALL' || p.side === filter.toLowerCase());
    const get = CLOSED_SORT[sort.key] || CLOSED_SORT.pnl;
    return [...base].sort((a, b) => {
      const va = get(a), vb = get(b);
      const c = typeof va === 'string' ? va.localeCompare(vb) : va - vb;
      return sort.dir === 'asc' ? c : -c;
    });
  }, [filter, sort]);
  const pageCount = Math.max(1, Math.ceil(rows.length / CLOSED_PER));
  const safePage = Math.min(page, pageCount - 1);
  const pageRows = rows.slice(safePage * CLOSED_PER, safePage * CLOSED_PER + CLOSED_PER);
  const onFilter = (f) => { setFilter(f); setPage(0); setOpen(null); };
  const thProps = { sort, setSort };
  return (
    <section>
      <div className="flex items-end justify-between gap-4 mb-4 max-[640px]:flex-col max-[640px]:items-start">
        <div>
          <div className="font-sans font-semibold text-[16px] text-fg-1 flex items-center gap-[9px]"><UIcon name="clock" size={17} style={{ color: 'var(--fg-3)' }}/>Closed positions</div>
          <div className="text-[12.5px] text-fg-3 mt-1">Realized over the last 48 hours · click any row for detail</div>
        </div>
        <Segmented options={['ALL', 'LONG', 'SHORT']} value={filter} onChange={onFilter}/>
      </div>
      <div className="card overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full border-collapse min-w-[860px]">
            <thead>
              <tr>
                <Th id="sym" label="Market" align="left" w="200px" {...thProps}/>
                <Th id="side" label="Side" align="left" {...thProps}/>
                <Th id="entry" label="Entry" {...thProps}/>
                <Th id="exit" label="Exit" {...thProps}/>
                <Th id="dur" label="Held" {...thProps}/>
                <Th id="pnl" label="Realized P&amp;L" {...thProps}/>
                <Th id="roe" label="ROE" {...thProps}/>
                <Th id="reason" label="Closed" align="left" {...thProps}/>
                <th className="w-9 bg-accent"/>
              </tr>
            </thead>
            <tbody>
              {pageRows.map((p, i) => (
                <ClosedRow key={p.sym + i} p={p} isOpen={open === (p.sym + i)}
                  onToggle={() => setOpen(open === (p.sym + i) ? null : (p.sym + i))}/>
              ))}
            </tbody>
          </table>
        </div>
        <div className={CARD_FOOT}>
          <span className={FOOT_MONO}>{rows.length} CLOSED · 48H WINDOW</span>
          <Pager page={safePage} pageCount={pageCount} setPage={setPage}/>
          <a href="#" className={LINK_ARROW}>Full history <UIcon name="arrowRight" size={13}/></a>
        </div>
      </div>
    </section>
  );
};

// ---------- aggregate summary strip ----------
const AggStrip = () => {
  const longs = POSITIONS.filter(p => p.side === 'long').length;
  const shorts = POSITIONS.length - longs;
  const exposure = POSITIONS.reduce((s, p) => s + p.notional, 0);
  const margin = POSITIONS.reduce((s, p) => s + p.margin, 0);
  const pnl = POSITIONS.reduce((s, p) => s + p.pnl, 0);
  const roe = (pnl / margin) * 100;
  const cells = [
    { label: 'Open positions', value: String(POSITIONS.length), sub: `${longs}L · ${shorts}S` },
    { label: 'Total exposure', value: usd0(exposure), sub: 'NOTIONAL' },
    { label: 'Margin used', value: usd0(margin), sub: `${((margin / exposure) * 100).toFixed(0)}% OF NOTIONAL` },
    { label: 'Unrealized P&L', value: usdSigned(pnl), sub: pctSigned(roe) + ' ROE', tone: pnl >= 0 ? 'up' : 'down' },
    { label: 'Capacity', value: POSITIONS.length + ' / 12', sub: 'MAX 6 / DIR' },
  ];
  return (
    <div className="card flex items-stretch mb-7 max-[900px]:flex-wrap">
      {cells.map((c, i) => (
        <div key={c.label} className={"flex-1 min-w-[150px] py-[15px] px-5 flex flex-col gap-1.5 " + (i ? 'border-l border-line-soft max-[900px]:border-l-0' : '') + (i >= 3 ? ' max-[900px]:border-t max-[900px]:border-line-soft' : '')}>
          <span className="font-mono text-[9.5px] font-medium tracking-[0.1em] uppercase text-fg-mute">{c.label}</span>
          <span className={"font-mono text-[22px] font-semibold leading-none tabular-nums tracking-[-0.02em] " + (c.tone === 'up' ? 'text-pnlup' : c.tone === 'down' ? 'text-pnldown' : 'text-fg-1')}>{c.value}</span>
          <span className="font-mono text-[9.5px] tracking-[0.06em] text-fg-mute">{c.sub}</span>
        </div>
      ))}
    </div>
  );
};

// ---------- page ----------
const Positions = ({ regime, score }) => (
  <>
    <div className={PAGEHEAD}>
      <div>
        <div className={PH_EYEBROW}><UIcon name="layers" size={13} style={{ width: 13, height: 13 }}/>PORTFOLIO</div>
        <h1 className={PH_H1}>Positions</h1>
        <div className={PH_SUB}>Full lifecycle — open positions, realized history, and per-market detail.</div>
      </div>
      <div className="flex items-center gap-3 flex-shrink-0 max-[820px]:flex-wrap max-[820px]:gap-y-2.5">
        <RegimePill regime={regime} score={score} pulse={regime === 'CASCADE' || regime === 'BLACK SWAN'}/>
        <div className="w-px h-[22px] bg-line"/>
        <button className={BTN_SECONDARY}><UIcon name="refresh" size={15}/>Sync</button>
      </div>
    </div>

    <AggStrip/>
    <OpenTable/>

    <div className="flex items-center gap-4 my-7" role="separator" aria-label="History">
      <span className="h-px flex-1 bg-line"/>
      <span className="font-mono text-[10px] font-medium tracking-[0.14em] uppercase text-fg-mute flex items-center gap-[7px] whitespace-nowrap"><UIcon name="clock" size={13}/>History</span>
      <span className="h-px flex-1 bg-line"/>
    </div>

    <ClosedTable/>
  </>
);

Object.assign(window, { Positions, Coin, SideTag });
