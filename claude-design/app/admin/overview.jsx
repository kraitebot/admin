// Kraite SYSADMIN console — flagship page: Fleet & platform health overview.
// Live-feeling ops dashboard: platform KPIs, the worker-node fleet, the global
// BSCS regime, deploy/rollout state, exchange connectivity, and an incident feed.

// ---------- worker fleet ----------
const OvwWorkerRow = ({ w }) => {
  const tinted = w.state === 'degraded';
  return (
    <div className={"grid grid-cols-[minmax(150px,1.5fr)_120px_1fr_1fr_70px_64px_72px] items-center gap-4 py-3 px-5 border-b border-line-soft last:border-b-0 max-[1024px]:grid-cols-[minmax(140px,1.5fr)_110px_1fr_1fr] max-[640px]:px-4 transition-colors duration-fast"}
      style={tinted ? { background: 'color-mix(in srgb, var(--warn) 7%, transparent)' } : undefined}>
      <div className="flex items-center gap-2.5 min-w-0">
        <span className="font-mono text-[10px] font-bold tracking-[0.06em] text-fg-mute w-[34px] flex-shrink-0">{w.code}</span>
        <div className="flex flex-col leading-[1.2] min-w-0">
          <span className="font-mono text-[12.5px] font-semibold text-fg-1 tracking-[0.01em] whitespace-nowrap">{w.id}</span>
          <span className="font-mono text-[10px] text-fg-mute tracking-[0.02em] whitespace-nowrap">{w.region} · up {w.up}</span>
        </div>
      </div>
      <HealthChip state={w.state}/>
      <UsageBar pct={w.cpu} label="CPU"/>
      <UsageBar pct={w.mem} label="MEM"/>
      <div className="flex flex-col items-end leading-tight max-[1024px]:hidden">
        <span className="font-mono text-[12px] font-semibold tabular-nums" style={{ color: w.lat >= 100 ? 'var(--warn)' : 'var(--fg-1)' }}>{w.lat}ms</span>
        <span className="font-mono text-[9px] tracking-[0.06em] uppercase text-fg-mute">latency</span>
      </div>
      <div className="flex flex-col items-end leading-tight max-[1024px]:hidden">
        <span className="font-mono text-[12px] font-semibold tabular-nums text-fg-1">{w.bots}</span>
        <span className="font-mono text-[9px] tracking-[0.06em] uppercase text-fg-mute">bots</span>
      </div>
      <div className="flex items-center justify-end gap-1.5 max-[1024px]:hidden">
        <span className="font-mono text-[10px] text-fg-mute tabular-nums">{w.build}</span>
        <button className="appearance-none bg-transparent border-0 cursor-pointer text-fg-mute hover:text-fg-1 w-[24px] h-[24px] inline-flex items-center justify-center rounded-[6px] hover:bg-hover transition-colors"><UIcon name="more" size={16}/></button>
      </div>
    </div>
  );
};

const OvwFleet = () => (
  <div className="card card--flat overflow-hidden">
    <ACardHead icon="server" title="Worker fleet" accent
      right={<div className="flex items-center gap-3">
        <span className="font-mono text-[10.5px] text-fg-mute tabular-nums">6 healthy · 1 degraded · 1 draining</span>
        <button className={A_BTN_GHOST + " h-[30px] px-2.5 text-[12px]"}><UIcon name="zap" size={13}/>Deploy</button>
      </div>}/>
    <div className="hidden md:grid grid-cols-[minmax(150px,1.5fr)_120px_1fr_1fr_70px_64px_72px] items-center gap-4 py-2 px-5 border-b border-line-soft bg-surface-2/40 font-mono text-[9px] font-semibold tracking-[0.1em] uppercase text-fg-faint">
      <span>Node</span><span>Status</span><span>CPU</span><span>Memory</span><span className="text-right max-[1024px]:hidden">Latency</span><span className="text-right max-[1024px]:hidden">Load</span><span className="text-right max-[1024px]:hidden">Build</span>
    </div>
    {A_WORKERS.map(w => <OvwWorkerRow key={w.id} w={w}/>)}
  </div>
);

// ---------- regime + deploy + revenue (right column) ----------
const OvwRegime = ({ regime, score }) => {
  const r = REGIMES[regime] || REGIMES.CALM;
  return (
    <div className="card card--flat overflow-hidden">
      <ACardHead icon="shield" title="Market regime" accent hint="BSCS · platform-wide"/>
      <div className="p-5 flex flex-col gap-4">
        <div className="flex items-end justify-between gap-3">
          <div className="flex flex-col gap-1.5">
            <span className="font-sans text-[22px] font-bold tracking-[-0.01em] leading-none" style={{ color: r.color }}>{regime}</span>
            <span className="font-mono text-[10.5px] tracking-[0.04em] text-fg-mute">Exposure auto-reduced</span>
          </div>
          <span className="font-mono text-[34px] font-bold tabular-nums leading-none" style={{ color: r.color }}>{score.toFixed(2)}</span>
        </div>
        <RegimeRamp regime={regime} score={score}/>
        <button className={A_BTN_SECONDARY + " w-full justify-center h-[34px] mt-1"}><UIcon name="sliders" size={14}/>Override regime</button>
      </div>
    </div>
  );
};

const OvwSymbols = () => {
  const rows = VENUE_TRADE_STATS;
  const tot = rows.reduce((a, v) => ({
    total: a.total + v.total, tradable: a.tradable + v.tradable,
    longs: a.longs + v.longs, shorts: a.shorts + v.shorts,
  }), { total: 0, tradable: 0, longs: 0, shorts: 0 });
  const COLS = "grid-cols-[1fr_repeat(4,42px)] gap-x-2.5";
  const agg = [
    { k: 'Symbols',  v: tot.total,    c: 'var(--fg-1)' },
    { k: 'Tradable', v: tot.tradable, c: 'var(--accent)' },
    { k: 'Long',     v: tot.longs,    c: 'var(--pnl-up-fg)' },
    { k: 'Short',    v: tot.shorts,   c: 'var(--pnl-down-fg)' },
  ];
  return (
    <div className="card card--flat overflow-hidden">
      <ACardHead icon="exchange" title="Tradable symbols" accent hint={rows.length + ' venues'}/>
      {/* aggregate strip */}
      <div className="grid grid-cols-4 border-b border-line-soft">
        {agg.map((s, i) => (
          <div key={s.k} className={"flex flex-col gap-1.5 py-3.5 px-3.5 " + (i < 3 ? "border-r border-line-soft" : "")}>
            <span className="font-mono text-[9px] tracking-[0.08em] uppercase text-fg-mute whitespace-nowrap">{s.k}</span>
            <span className="font-mono text-[21px] font-bold tabular-nums leading-none" style={{ color: s.c }}>{s.v.toLocaleString()}</span>
          </div>
        ))}
      </div>
      {/* per-venue table */}
      <div className={"hidden md:grid " + COLS + " py-2 px-5 border-b border-line-soft bg-surface-2/40 font-mono text-[9px] font-semibold tracking-[0.1em] uppercase text-fg-faint"}>
        <span>Venue</span>
        <span className="text-right">Sym</span>
        <span className="text-right">Trd</span>
        <span className="text-right">Lng</span>
        <span className="text-right">Sht</span>
      </div>
      {rows.map(v => (
        <div key={v.ex} className={"grid " + COLS + " items-center py-2.5 px-5 border-b border-line-soft last:border-b-0"}>
          <div className="flex items-center gap-2.5 min-w-0">
            <span className="w-[24px] h-[24px] rounded-full bg-[#15181f] ring-1 ring-white/5 flex items-center justify-center flex-shrink-0 overflow-hidden">
              <img src={"assets/exch/" + v.slug + ".svg"} alt={v.ex} width="16" height="16" style={{ width: 16, height: 16 }}/>
            </span>
            <span className="font-sans text-[12.5px] font-semibold text-fg-1 whitespace-nowrap">{v.ex}</span>
          </div>
          <span className="font-mono text-[12px] tabular-nums text-right text-fg-3">{v.total}</span>
          <span className="font-mono text-[12px] font-semibold tabular-nums text-right" style={{ color: 'var(--accent)' }}>{v.tradable}</span>
          <span className="font-mono text-[12px] font-semibold tabular-nums text-right" style={{ color: 'var(--pnl-up-fg)' }}>{v.longs}</span>
          <span className="font-mono text-[12px] font-semibold tabular-nums text-right" style={{ color: 'var(--pnl-down-fg)' }}>{v.shorts}</span>
        </div>
      ))}
    </div>
  );
};

const OvwRevenue = () => {
  const rows = [
    { k: 'MRR', v: A_REVENUE.mrr, d: A_REVENUE.mrrDelta, icon: 'trendingUp' },
    { k: 'Top-ups today', v: A_REVENUE.topupsToday, sub: A_REVENUE.topupCount + ' payments', icon: 'plus' },
    { k: 'Wallet float held', v: A_REVENUE.float, sub: 'across all wallets', icon: 'wallet' },
  ];
  return (
    <div className="card card--flat overflow-hidden">
      <ACardHead icon="wallet" title="Revenue today" accent/>
      <div className="px-5 py-1.5">
        {rows.map((r, i) => (
          <div key={r.k} className={"flex items-center justify-between gap-3 py-3 " + (i < rows.length - 1 ? "border-b border-line-soft" : "")}>
            <span className="flex items-center gap-2.5 text-[12.5px] text-fg-3"><UIcon name={r.icon} size={14} style={{ color: 'var(--fg-mute)' }}/>{r.k}</span>
            <span className="flex items-center gap-2">
              {r.sub && <span className="font-mono text-[10px] text-fg-mute tracking-[0.02em] max-[480px]:hidden">{r.sub}</span>}
              {r.d != null && <Delta value={r.d}/>}
              <span className="font-mono text-[14px] font-bold tabular-nums text-fg-1">{r.v}</span>
            </span>
          </div>
        ))}
      </div>
    </div>
  );
};

// ---------- exchange connectivity ----------
const OvwVenueRow = ({ v }) => {
  const tinted = v.state === 'degraded';
  const up = v.state !== 'degraded';
  return (
    <div className="grid grid-cols-[minmax(130px,1.4fr)_120px_120px_72px_70px] items-center gap-4 py-3 px-5 border-b border-line-soft last:border-b-0 max-[820px]:grid-cols-[minmax(120px,1.4fr)_110px_1fr] max-[640px]:px-4 transition-colors"
      style={tinted ? { background: 'color-mix(in srgb, var(--warn) 7%, transparent)' } : undefined}>
      <div className="flex items-center gap-2.5 min-w-0">
        <span className="w-[28px] h-[28px] rounded-full bg-surface-3 text-fg-1 font-mono font-bold text-[11px] flex items-center justify-center flex-shrink-0">{v.mono}</span>
        <span className="font-sans text-[13px] font-semibold text-fg-1 whitespace-nowrap">{v.ex}</span>
      </div>
      <HealthChip state={v.state}/>
      <div className="flex items-center gap-2.5 max-[820px]:hidden">
        <div className="w-[52px] h-[20px] flex-shrink-0 opacity-80"><Sparkline data={v.spark} up={up} h={20} fill={false}/></div>
        <span className="font-mono text-[11.5px] font-semibold tabular-nums" style={{ color: v.lat == null ? 'var(--fg-mute)' : v.lat >= 100 ? 'var(--warn)' : 'var(--fg-1)' }}>{v.lat == null ? '—' : v.lat + 'ms'}</span>
      </div>
      <div className="flex flex-col items-end leading-tight max-[820px]:hidden">
        <span className="font-mono text-[11.5px] font-semibold tabular-nums" style={{ color: v.err == null ? 'var(--fg-mute)' : v.err >= 1 ? 'var(--warn)' : 'var(--fg-2)' }}>{v.err == null ? '—' : v.err.toFixed(2) + '%'}</span>
        <span className="font-mono text-[9px] tracking-[0.06em] uppercase text-fg-mute">errors</span>
      </div>
      <div className="flex flex-col items-end leading-tight">
        <span className="font-mono text-[11.5px] font-semibold tabular-nums text-fg-1">{v.accts}</span>
        <span className="font-mono text-[9px] tracking-[0.06em] uppercase text-fg-mute">accts</span>
      </div>
    </div>
  );
};

const OvwVenues = () => (
  <div className="card card--flat overflow-hidden">
    <ACardHead icon="exchange" title="Exchange connectivity" accent
      right={<span className="font-mono text-[10.5px] text-fg-mute tabular-nums">6 venues · 1 degraded</span>}/>
    {A_VENUES.map(v => <OvwVenueRow key={v.ex} v={v}/>)}
  </div>
);

// ---------- incident feed ----------
const SEV_C = { warn: 'var(--warn)', good: 'var(--pnl-up-fg)', brand: 'var(--accent)', mute: 'var(--fg-mute)', bad: 'var(--danger)' };
const OvwIncidents = () => (
  <div className="card card--flat overflow-hidden">
    <ACardHead icon="activity" title="Incidents & events" accent right={<button className={A_BTN_GHOST + " h-[28px] px-2 text-[11.5px]"}>View all<UIcon name="arrowRight" size={13}/></button>}/>
    <div className="px-5 py-1">
      {A_INCIDENTS.map((ev, i) => {
        const c = SEV_C[ev.sev] || SEV_C.mute;
        return (
          <div key={i} className={"flex items-start gap-3 py-3 " + (i < A_INCIDENTS.length - 1 ? "border-b border-line-soft" : "")}>
            <span className="w-[26px] h-[26px] rounded-control flex items-center justify-center flex-shrink-0 mt-px" style={{ background: `color-mix(in srgb, ${c} 14%, transparent)`, color: c }}><UIcon name={ev.icon} size={14}/></span>
            <span className="flex-1 min-w-0 text-[12.5px] text-fg-2 leading-snug">{ev.text}</span>
            <span className="font-mono text-[10.5px] text-fg-mute tabular-nums flex-shrink-0 mt-0.5">{ev.time}</span>
          </div>
        );
      })}
    </div>
  </div>
);

// ---------- page ----------
const AdminOverview = ({ regime, score, halted }) => (
  <>
    <div className={A_PAGEHEAD}>
      <div>
        <div className={A_EYEBROW}><UIcon name="activity" size={13} style={{ width: 13, height: 13 }}/>PLATFORM</div>
        <h1 className={A_H1}>Fleet overview</h1>
        <div className={A_SUB}>Live health across every Kraite worker, exchange, and customer account.</div>
      </div>
      <div className="flex items-center gap-3 flex-shrink-0 max-[820px]:flex-wrap max-[820px]:gap-y-2.5">
        {halted
          ? <span className="inline-flex items-center gap-2 py-[6px] px-3 rounded-chip border font-mono text-[11px] font-bold tracking-[0.06em] uppercase" style={{ color: 'var(--danger)', borderColor: 'color-mix(in srgb, var(--danger) 40%, transparent)', background: 'color-mix(in srgb, var(--danger) 12%, transparent)' }}><span className="w-2 h-2 rounded-chip bg-danger animate-pulse-soft"/>Trading halted</span>
          : <RegimePill regime={regime} score={score} pulse={regime === 'CASCADE' || regime === 'BLACK SWAN'}/>}
        <div className="w-px h-[22px] bg-line"/>
        <button className={A_BTN_SECONDARY}><UIcon name="refresh" size={15}/>Sync</button>
      </div>
    </div>

    {/* KPI row */}
    <div className="grid grid-cols-5 gap-3 mb-6 max-[1100px]:grid-cols-3 max-[680px]:grid-cols-2">
      <StatTile icon="users" label="Active traders" value="1,284" delta={1.4} sub="24H · +18 SIGNUPS"/>
      <StatTile icon="cpu" label="Worker nodes" value="6 / 8" sub="HEALTHY · 1 DEGRADED">
        <span className="flex items-center gap-1.5"><HealthDot state="degraded" pulse/><HealthDot state="draining"/></span>
      </StatTile>
      <StatTile icon="database" label="Capital under mgmt" value="$84.2M" delta={2.1} sub="AUM · ALL ACCOUNTS"/>
      <StatTile icon="activity" label="Engine throughput" value="3,420" sub="ORDERS / MIN" spark={[2800,3010,2950,3180,3240,3120,3360,3420]}/>
      <StatTile icon="layers" label="Open positions" value="9,612" sub="PLATFORM-WIDE"/>
    </div>

    {/* fleet + side column */}
    <div className="grid grid-cols-[1.6fr_1fr] gap-5 mb-5 max-[1024px]:grid-cols-1">
      <OvwFleet/>
      <div className="flex flex-col gap-5">
        <OvwRegime regime={regime} score={score}/>
        <OvwSymbols/>
        <OvwRevenue/>
      </div>
    </div>

    {/* venues + incidents */}
    <div className="grid grid-cols-[1.6fr_1fr] gap-5 max-[1024px]:grid-cols-1">
      <OvwVenues/>
      <OvwIncidents/>
    </div>
  </>
);

Object.assign(window, { AdminOverview });
