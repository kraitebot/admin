// Kraite SYSADMIN console — Engine: the trading brain's control surface.
// The BSCS regime gate (score + 5 sub-signals) that decides whether opens are
// allowed, the entry indicators the engine arms per market, and the per-symbol
// trade configuration (leverage / ladder / profit / stop) the whole fleet runs.

const SIG_C = { hot: 'var(--danger)', warn: 'var(--warn)', ok: 'var(--pnl-up-fg)' };

const SignalRow = ({ s }) => {
  const c = SIG_C[s.state] || SIG_C.ok;
  return (
    <div className="flex items-center gap-4 py-3 px-5 border-b border-line-soft last:border-b-0 max-[640px]:px-4">
      <div className="flex flex-col gap-1.5 flex-1 min-w-0">
        <div className="flex items-center justify-between gap-3">
          <span className="font-sans text-[13px] font-semibold text-fg-1 whitespace-nowrap">{s.name}</span>
          <span className="font-mono text-[12px] font-bold tabular-nums" style={{ color: c }}>{s.val.toFixed(2)}</span>
        </div>
        <div className="h-[5px] rounded-chip bg-surface-3 overflow-hidden"><div className="h-full rounded-chip transition-[width] duration-base" style={{ width: (s.val * 100) + '%', background: c }}/></div>
        <span className="font-mono text-[10px] text-fg-mute tracking-[0.02em]">{s.note} · weight {Math.round(s.w * 100)}%</span>
      </div>
    </div>
  );
};

const IND_STATE = {
  firing: { t: 'Firing', c: 'var(--pnl-up-fg)', pulse: true },
  armed:  { t: 'Armed',  c: 'var(--accent)', pulse: false },
  idle:   { t: 'Idle',   c: 'var(--fg-mute)', pulse: false },
  gated:  { t: 'Gated',  c: 'var(--warn)', pulse: false },
};
const IndicatorRow = ({ ind }) => {
  const m = IND_STATE[ind.state];
  return (
    <div className="grid grid-cols-[minmax(160px,1.4fr)_110px_1fr_70px_70px] items-center gap-3 py-3 px-5 border-b border-line-soft last:border-b-0 max-[820px]:grid-cols-[minmax(150px,1.4fr)_110px_1fr] max-[640px]:px-4">
      <span className="font-sans text-[13px] font-semibold text-fg-1 whitespace-nowrap">{ind.name}</span>
      <span className="inline-flex items-center gap-2 font-mono text-[10.5px] font-bold tracking-[0.07em] uppercase" style={{ color: m.c }}>
        <span className={"w-[7px] h-[7px] rounded-chip" + (m.pulse ? " animate-pulse-soft" : "")} style={{ background: m.c }}/>{m.t}
      </span>
      <span className="font-mono text-[11.5px] text-fg-3 tracking-[0.02em] whitespace-nowrap truncate max-[820px]:hidden">{ind.markets}</span>
      <span className="font-mono text-[11px] tabular-nums text-fg-mute text-right max-[820px]:hidden">{ind.tf}</span>
      <span className="font-mono text-[11px] tabular-nums text-fg-2 text-right">{ind.hits24}<span className="text-fg-mute text-[9px]">/24h</span></span>
    </div>
  );
};

const CfgSymbolRow = ({ c }) => (
  <div className={"grid grid-cols-[minmax(120px,1fr)_70px_64px_72px_72px_60px] items-center gap-3 py-2.5 px-5 border-b border-line-soft last:border-b-0 max-[640px]:px-4 " + (c.enabled ? "" : "opacity-50")}>
    <div className="flex items-center gap-2.5">
      <span className="w-[24px] h-[24px] rounded-full flex items-center justify-center flex-shrink-0 font-mono text-[10px] font-bold text-white" style={{ background: c.color }}>{c.sym[0]}</span>
      <span className="font-mono text-[12.5px] font-semibold text-fg-1">{c.sym}</span>
    </div>
    <span className="font-mono text-[12px] font-semibold tabular-nums text-fg-1">{c.lev}</span>
    <span className="font-mono text-[12px] tabular-nums text-fg-2">{c.ladder} <span className="text-fg-mute text-[9px]">steps</span></span>
    <span className="font-mono text-[12px] tabular-nums text-pnlup">+{c.pt}</span>
    <span className="font-mono text-[12px] tabular-nums text-pnldown">−{c.sl}</span>
    <span className="flex justify-end"><span className="font-mono text-[9px] font-bold tracking-[0.07em] uppercase" style={{ color: c.enabled ? 'var(--pnl-up-fg)' : 'var(--fg-mute)' }}>{c.enabled ? 'ON' : 'OFF'}</span></span>
  </div>
);

const AdminEngine = ({ regime, score, halted }) => {
  const r = REGIMES[regime] || REGIMES.CALM;
  const gated = halted || regime === 'CASCADE' || regime === 'BLACK SWAN';
  return (
    <>
      <div className={A_PAGEHEAD}>
        <div>
          <div className={A_EYEBROW}><UIcon name="bot" size={13} style={{ width: 13, height: 13 }}/>EXECUTION</div>
          <h1 className={A_H1}>Trading engine</h1>
          <div className={A_SUB}>What the engine is allowed to trade right now, and the rules it trades under.</div>
        </div>
        <div className="flex items-center gap-3 flex-shrink-0">
          <span className="inline-flex items-center gap-2 py-[6px] px-3 rounded-chip border font-mono text-[11px] font-bold tracking-[0.06em] uppercase" style={{ color: gated ? 'var(--warn)' : 'var(--pnl-up-fg)', borderColor: `color-mix(in srgb, ${gated ? 'var(--warn)' : 'var(--pnl-up-fg)'} 40%, transparent)`, background: `color-mix(in srgb, ${gated ? 'var(--warn)' : 'var(--pnl-up-fg)'} 12%, transparent)` }}>
            <span className="w-2 h-2 rounded-chip" style={{ background: gated ? 'var(--warn)' : 'var(--pnl-up-fg)' }}/>{gated ? 'Opens gated' : 'Opens allowed'}
          </span>
        </div>
      </div>

      {/* regime gate */}
      <div className="grid grid-cols-[1.5fr_1fr] gap-5 mb-5 max-[900px]:grid-cols-1">
        <div className="card card--flat overflow-hidden">
          <ACardHead icon="shield" title="Black Swan Composite — regime gate" accent hint="5 sub-signals"/>
          {BSCS_SIGNALS.map(s => <SignalRow key={s.id} s={s}/>)}
        </div>
        <div className="card card--flat overflow-hidden">
          <ACardHead icon="gauge" title="Posture" accent/>
          <div className="p-5 flex flex-col gap-4">
            <div className="flex items-end justify-between">
              <span className="font-sans text-[20px] font-bold tracking-[-0.01em] leading-none" style={{ color: r.color }}>{regime}</span>
              <span className="font-mono text-[34px] font-bold tabular-nums leading-none" style={{ color: r.color }}>{score.toFixed(2)}</span>
            </div>
            <RegimeRamp regime={regime} score={score}/>
            <div className="rounded-control border border-line-soft overflow-hidden mt-1">
              {[['New opens', gated ? 'Blocked' : 'Reduced size'], ['Max notional', '3.0×'], ['Long slots', '5'], ['Short slots', '5']].map((row, i) => (
                <div key={i} className={"flex items-center justify-between gap-3 py-2.5 px-3.5 " + (i < 3 ? "border-b border-line-soft" : "")}>
                  <span className="text-[12px] text-fg-3">{row[0]}</span>
                  <span className="font-mono text-[12px] font-semibold tabular-nums" style={i === 0 ? { color: gated ? 'var(--warn)' : 'var(--fg-1)' } : { color: 'var(--fg-1)' }}>{row[1]}</span>
                </div>
              ))}
            </div>
            <button className={A_BTN_SECONDARY + " w-full justify-center"}><UIcon name="sliders" size={14}/>Override gate</button>
          </div>
        </div>
      </div>

      {/* indicators */}
      <div className="card card--flat overflow-hidden mb-5">
        <ACardHead icon="zap" title="Entry indicators" accent hint="arming the engine"/>
        <div className="hidden md:grid grid-cols-[minmax(160px,1.4fr)_110px_1fr_70px_70px] gap-3 py-2 px-5 border-b border-line-soft font-mono text-[9px] font-semibold tracking-[0.1em] uppercase text-fg-faint">
          <span>Indicator</span><span>State</span><span>Markets</span><span className="text-right">TF</span><span className="text-right">Hits</span>
        </div>
        {ENGINE_INDICATORS.map(ind => <IndicatorRow key={ind.id} ind={ind}/>)}
      </div>

      {/* per-symbol config */}
      <div className="card card--flat overflow-hidden">
        <ACardHead icon="sliders" title="Per-symbol trade configuration" accent
          right={<span className="font-mono text-[10.5px] text-fg-mute tabular-nums">{SYMBOL_CFG.filter(c => c.enabled).length}/{SYMBOL_CFG.length} enabled</span>}/>
        <div className="hidden md:grid grid-cols-[minmax(120px,1fr)_70px_64px_72px_72px_60px] gap-3 py-2 px-5 border-b border-line-soft font-mono text-[9px] font-semibold tracking-[0.1em] uppercase text-fg-faint">
          <span>Symbol</span><span>Leverage</span><span>Ladder</span><span>Profit</span><span>Stop</span><span className="text-right">State</span>
        </div>
        {SYMBOL_CFG.map(c => <CfgSymbolRow key={c.sym} c={c}/>)}
      </div>
    </>
  );
};

Object.assign(window, { AdminEngine });
