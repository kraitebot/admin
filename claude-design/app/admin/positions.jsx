// Kraite SYSADMIN console — Positions: the fleet-wide book. Every open and
// closed position the engine is running across ALL client accounts (not scoped
// to one trader). Each row exposes lifecycle, direction, leverage, ladder fill,
// live P&L — plus a sync indicator (ledger vs. exchange of record).

const fmtUsd = (n) => (n < 0 ? '-' : '+') + '$' + Math.abs(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const clientFor = (i) => A_CLIENTS[i % A_CLIENTS.length];
// a couple of positions drift from the exchange of record (the OKX/arb story)
const DRIFT_IDX = new Set([3, 7]);

const PnL = ({ v, pct }) => {
  const up = v >= 0;
  return (
    <span className="flex flex-col items-end leading-tight">
      <span className={"font-mono text-[12.5px] font-semibold tabular-nums " + (up ? "text-pnlup" : "text-pnldown")}>{fmtUsd(v)}</span>
      {pct != null && <span className={"font-mono text-[10px] tabular-nums " + (up ? "text-pnlup" : "text-pnldown")}>{up ? '+' : ''}{pct.toFixed(2)}%</span>}
    </span>
  );
};

const SideTag = ({ side, lev }) => (
  <span className="inline-flex items-center gap-1.5">
    <span className={"font-mono text-[10px] font-bold tracking-[0.08em] uppercase py-px px-[6px] rounded-chip " + (side === 'long' ? "text-pnlup bg-pnlup-bg" : "text-pnldown bg-pnldown-bg")}>{side === 'long' ? 'L' : 'S'}</span>
    <span className="font-mono text-[11px] text-fg-2 tabular-nums">{lev}</span>
  </span>
);

const Lifecycle = ({ stage }) => (
  <div className="flex items-center gap-1.5">
    {[1, 2, 3, 4].map(s => (
      <span key={s} className="w-[7px] h-[7px] rounded-chip" style={{ background: s <= stage ? 'var(--accent)' : 'var(--bg-elev-3)' }}/>
    ))}
    <span className="font-mono text-[9.5px] tracking-[0.04em] text-fg-mute ml-1">{stage}/4</span>
  </div>
);

const SyncChip = ({ drift }) => (
  <span className="inline-flex items-center gap-1.5 font-mono text-[9.5px] font-bold tracking-[0.07em] uppercase whitespace-nowrap" style={{ color: drift ? 'var(--warn)' : 'var(--fg-mute)' }}>
    <span className={"w-[6px] h-[6px] rounded-chip" + (drift ? " animate-pulse-soft" : "")} style={{ background: drift ? 'var(--warn)' : 'var(--pnl-up-fg)' }}/>
    {drift ? 'Drift' : 'Synced'}
  </span>
);

const OpenRow = ({ p, i }) => {
  const drift = DRIFT_IDX.has(i);
  return (
    <div className="grid grid-cols-[minmax(150px,1.5fr)_84px_118px_72px_110px_96px_84px] items-center gap-3 py-3 px-5 border-b border-line-soft last:border-b-0 max-[1100px]:grid-cols-[minmax(140px,1.5fr)_84px_1fr_84px] max-[640px]:px-4 transition-colors"
      style={drift ? { background: 'color-mix(in srgb, var(--warn) 6%, transparent)' } : undefined}>
      <div className="flex items-center gap-2.5 min-w-0">
        <span className="w-[26px] h-[26px] rounded-full flex items-center justify-center flex-shrink-0 font-mono text-[10px] font-bold text-white" style={{ background: p.color }}>{p.sym[0]}</span>
        <div className="flex flex-col leading-[1.2] min-w-0">
          <span className="font-mono text-[12.5px] font-semibold text-fg-1 whitespace-nowrap">{p.market}</span>
          <span className="font-sans text-[10.5px] text-fg-mute whitespace-nowrap truncate">{clientFor(i)}</span>
        </div>
      </div>
      <SideTag side={p.side} lev={p.lev}/>
      <div className="max-[1100px]:hidden"><Lifecycle stage={p.stage}/></div>
      <div className="flex flex-col items-end leading-tight max-[1100px]:hidden">
        <span className="font-mono text-[11.5px] font-semibold tabular-nums text-fg-1">{p.filled}</span>
        <span className="font-mono text-[9px] tracking-[0.06em] uppercase text-fg-mute">filled</span>
      </div>
      <div className="flex flex-col items-end leading-tight max-[1100px]:hidden">
        <span className="font-mono text-[11.5px] font-semibold tabular-nums text-fg-1">${(p.notional / 1000).toFixed(1)}k</span>
        <span className="font-mono text-[9px] tracking-[0.06em] uppercase text-fg-mute">notional</span>
      </div>
      <PnL v={p.pnl} pct={p.pnlPct}/>
      <div className="flex justify-end"><SyncChip drift={drift}/></div>
    </div>
  );
};

const ClosedRow = ({ c, i }) => {
  const reasonMeta = { tp: { t: 'Target', c: 'var(--pnl-up-fg)' }, stop: { t: 'Stop', c: 'var(--pnl-down-fg)' }, manual: { t: 'Manual', c: 'var(--fg-mute)' }, regime: { t: 'Regime', c: 'var(--bsi-blackswan)' } }[c.reason];
  return (
    <div className="grid grid-cols-[minmax(150px,1.5fr)_84px_1fr_100px_84px_80px] items-center gap-3 py-3 px-5 border-b border-line-soft last:border-b-0 max-[900px]:grid-cols-[minmax(140px,1.5fr)_84px_100px] max-[640px]:px-4">
      <div className="flex items-center gap-2.5 min-w-0">
        <span className="w-[26px] h-[26px] rounded-full flex items-center justify-center flex-shrink-0 font-mono text-[10px] font-bold text-white" style={{ background: c.color }}>{c.sym[0]}</span>
        <div className="flex flex-col leading-[1.2] min-w-0">
          <span className="font-mono text-[12.5px] font-semibold text-fg-1 whitespace-nowrap">{c.sym}-PERP</span>
          <span className="font-sans text-[10.5px] text-fg-mute whitespace-nowrap truncate">{clientFor(i + 2)}</span>
        </div>
      </div>
      <SideTag side={c.side} lev={c.lev}/>
      <div className="flex items-center gap-1.5 font-mono text-[11.5px] tabular-nums text-fg-2 max-[900px]:hidden">
        <span>{c.entry}</span><UIcon name="arrowRight" size={12} style={{ color: 'var(--fg-mute)' }}/><span className="text-fg-1">{c.exit}</span>
      </div>
      <PnL v={c.pnl} pct={c.roe}/>
      <span className="font-mono text-[10px] font-bold tracking-[0.06em] uppercase text-right max-[900px]:hidden" style={{ color: reasonMeta.c }}>{reasonMeta.t}</span>
      <span className="font-mono text-[10.5px] text-fg-mute tabular-nums text-right">{c.closedAgo}</span>
    </div>
  );
};

const AdminPositions = ({ regime, score }) => {
  const [tab, setTab] = React.useState('open');
  const openNotional = POSITIONS.reduce((a, p) => a + p.notional, 0);
  const openPnl = POSITIONS.reduce((a, p) => a + p.pnl, 0);
  const realizedToday = CLOSED.slice(0, 6).reduce((a, c) => a + c.pnl, 0);

  const TabBtn = ({ id, label, n }) => {
    const on = tab === id;
    return (
      <button onClick={() => setTab(id)}
        className="relative inline-flex items-center gap-2 py-3 bg-transparent border-0 font-mono text-[12px] font-semibold tracking-[0.04em] transition-colors duration-fast cursor-pointer"
        style={{ color: on ? 'var(--fg-1)' : 'var(--fg-mute)' }}>
        {label}<span className="font-mono text-[10px] tabular-nums px-[6px] py-px rounded-chip" style={{ background: on ? 'color-mix(in srgb, var(--accent) 16%, transparent)' : 'var(--bg-elev-3)', color: on ? 'var(--accent)' : 'var(--fg-mute)' }}>{n}</span>
        {on && <span className="absolute left-0 right-0 -bottom-px h-[2px] rounded-t" style={{ background: 'var(--accent)' }}/>}
      </button>
    );
  };

  return (
    <>
      <div className={A_PAGEHEAD}>
        <div>
          <div className={A_EYEBROW}><UIcon name="layers" size={13} style={{ width: 13, height: 13 }}/>FLEET BOOK</div>
          <h1 className={A_H1}>Positions</h1>
          <div className={A_SUB}>Every open and closed position the engine is running — across all client accounts.</div>
        </div>
        <div className="flex items-center gap-3 flex-shrink-0 max-[820px]:flex-wrap max-[820px]:gap-y-2.5">
          <RegimePill regime={regime} score={score} pulse={regime === 'CASCADE' || regime === 'BLACK SWAN'}/>
          <div className="w-px h-[22px] bg-line"/>
          <button className={A_BTN_SECONDARY}><UIcon name="refresh" size={15}/>Reconcile</button>
        </div>
      </div>

      {/* summary strip */}
      <div className="grid grid-cols-4 gap-3 mb-6 max-[760px]:grid-cols-2">
        <div className="card card--flat px-5 py-4 flex flex-col gap-1">
          <span className="font-mono text-[22px] font-bold tabular-nums text-fg-1 leading-none">{POSITIONS.length} <span className="text-fg-mute text-[14px]">open</span></span>
          <span className="font-mono text-[9.5px] tracking-[0.1em] uppercase text-fg-mute">${(openNotional / 1e6).toFixed(2)}M notional</span>
        </div>
        <div className="card card--flat px-5 py-4 flex flex-col gap-1">
          <span className={"font-mono text-[22px] font-bold tabular-nums leading-none " + (openPnl >= 0 ? "text-pnlup" : "text-pnldown")}>{fmtUsd(openPnl)}</span>
          <span className="font-mono text-[9.5px] tracking-[0.1em] uppercase text-fg-mute">Unrealized · fleet</span>
        </div>
        <div className="card card--flat px-5 py-4 flex flex-col gap-1">
          <span className={"font-mono text-[22px] font-bold tabular-nums leading-none " + (realizedToday >= 0 ? "text-pnlup" : "text-pnldown")}>{fmtUsd(realizedToday)}</span>
          <span className="font-mono text-[9.5px] tracking-[0.1em] uppercase text-fg-mute">Realized · today</span>
        </div>
        <div className="card card--flat px-5 py-4 flex flex-col gap-1">
          <span className="font-mono text-[22px] font-bold tabular-nums leading-none" style={{ color: 'var(--warn)' }}>{DRIFT_IDX.size}</span>
          <span className="font-mono text-[9.5px] tracking-[0.1em] uppercase text-fg-mute">Out of sync</span>
        </div>
      </div>

      <div className="card card--flat overflow-hidden">
        <div className="flex items-center gap-6 px-5 border-b border-line-soft">
          <TabBtn id="open" label="Open" n={POSITIONS.length}/>
          <TabBtn id="closed" label="Closed" n={CLOSED.length}/>
          <span className="ml-auto font-mono text-[10px] tracking-[0.06em] uppercase text-fg-faint max-[640px]:hidden">{tab === 'open' ? 'live · refreshed 2s ago' : 'last 24h'}</span>
        </div>
        {tab === 'open'
          ? POSITIONS.map((p, i) => <OpenRow key={p.market} p={p} i={i}/>)
          : CLOSED.map((c, i) => <ClosedRow key={c.sym + i} c={c} i={i}/>)}
      </div>
    </>
  );
};

Object.assign(window, { AdminPositions });
