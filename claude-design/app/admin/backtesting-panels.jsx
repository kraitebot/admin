// Kraite SYSADMIN console — Backtesting result panels (right column).
// Coverage pill, fetch report, run scorecards, verdict bar, rung chart, regime
// stability band, rows table, AI insights. Pure presentation off canned data.

// ---------- [D] coverage strip ----------
const CoverageStrip = ({ cov }) => {
  if (!cov) return (
    <div className="card card--flat flex items-center gap-2.5 py-3 px-4">
      <UIcon name="database" size={15} style={{ color: 'var(--fg-faint)' }}/>
      <span className="text-[12px] text-fg-mute">No coverage data — run <span className="font-semibold text-fg-2">Verify</span> or <span className="font-semibold text-fg-2">Fetch</span>.</span>
    </div>
  );
  const clean = cov.holes === 0;
  const c = clean ? 'var(--pnl-up-fg)' : 'var(--warn)';
  const cell = (k, v) => (
    <div className="flex flex-col gap-1 min-w-0">
      <span className="font-mono text-[9px] tracking-[0.08em] uppercase text-fg-3 whitespace-nowrap">{k}</span>
      <span className="font-mono text-[12px] font-semibold tabular-nums text-fg-1 whitespace-nowrap">{v}</span>
    </div>
  );
  return (
    <div className="card card--flat overflow-hidden">
      <div className="flex items-center gap-2.5 py-2.5 px-4 border-b border-line-soft" style={{ background: `color-mix(in srgb, ${c} 8%, transparent)` }}>
        <span className="w-[8px] h-[8px] rounded-chip flex-shrink-0" style={{ background: c }}/>
        <span className="font-mono text-[11px] font-bold tracking-[0.04em] uppercase" style={{ color: c }}>
          {clean ? 'Complete coverage' : `${cov.holes} gap${cov.holes > 1 ? 's' : ''} · ${cov.contiguity}% contiguity`}
        </span>
        {!clean && <span className="font-mono text-[10px] text-fg-mute ml-auto max-[520px]:hidden">Fetch can backfill the gaps</span>}
      </div>
      <div className="grid grid-cols-4 gap-3 py-3 px-4 max-[520px]:grid-cols-2 max-[520px]:gap-y-3">
        {cell('Earliest', cov.earliest)}
        {cell('Latest', cov.latest)}
        {cell('Candles', cov.candles.toLocaleString())}
        {cell('Contiguity', cov.contiguity + '%')}
      </div>
    </div>
  );
};

// ---------- [E] fetch report ----------
const FetchReport = ({ report }) => {
  const [open, setOpen] = React.useState(true);
  if (!report) return null;
  return (
    <div className="card card--flat overflow-hidden">
      <button onClick={() => setOpen(o => !o)} className="w-full flex items-center gap-2.5 py-[13px] px-5 bg-surface-2 border-b border-line-soft cursor-pointer hover:bg-hover transition-colors text-left">
        <UIcon name="download" size={15} style={{ color: 'var(--accent)' }}/>
        <h4 className="font-sans font-semibold text-[14px] text-fg-1">Fetch report</h4>
        <span className="font-mono text-[10px] text-fg-mute ml-auto">{report.tiers.length} tiers</span>
        <UIcon name="chevronDown" size={15} style={{ color: 'var(--fg-mute)', transform: open ? 'rotate(180deg)' : 'none', transition: 'transform .2s' }}/>
      </button>
      {open && (
        <div>
          <div className="flex items-start gap-2.5 py-3 px-5 border-b border-line-soft" style={{ background: 'color-mix(in srgb, var(--pnl-up-fg) 7%, transparent)' }}>
            <UIcon name="check" size={14} style={{ color: 'var(--pnl-up-fg)', marginTop: 2 }}/>
            <span className="text-[12px] text-fg-2 leading-snug">{report.message}</span>
          </div>
          {report.tiers.map(t => (
            <div key={t.tier} className="flex items-center gap-3 py-3 px-5 border-b border-line-soft last:border-b-0">
              <span className="w-[30px] h-[30px] rounded-control bg-surface-3 flex items-center justify-center flex-shrink-0"><UIcon name={t.icon} size={15} style={{ color: 'var(--fg-2)' }}/></span>
              <div className="flex flex-col min-w-0">
                <span className="font-sans text-[12.5px] font-semibold text-fg-1">{t.tier}</span>
                <span className="font-mono text-[11px] text-fg-mute leading-snug">{t.text}</span>
              </div>
              <span className="ml-auto font-mono text-[11px] font-semibold tabular-nums text-right" style={{ color: 'var(--pnl-up-fg)' }}>{t.sub}</span>
            </div>
          ))}
        </div>
      )}
    </div>
  );
};

// ---------- [F] scorecards ----------
const StatMini = ({ label, value, sub, color, warn }) => (
  <div className="card card--flat px-3.5 py-3 flex flex-col gap-1.5">
    <span className="font-mono text-[9px] tracking-[0.08em] uppercase text-fg-mute whitespace-nowrap">{label}</span>
    <span className="font-mono text-[20px] font-bold tabular-nums leading-none" style={{ color: color || 'var(--fg-1)' }}>{value}</span>
    {sub && <span className="font-mono text-[9px] tracking-[0.05em] uppercase whitespace-nowrap" style={{ color: warn ? 'var(--warn)' : 'var(--fg-3)' }}>{sub}</span>}
  </div>
);

const GradeHero = ({ totals }) => {
  const gc = GRADE_COLOR[totals.grade] || 'var(--fg-1)';
  return (
    <div className="card card--flat overflow-hidden flex items-stretch" style={{ borderColor: `color-mix(in srgb, ${gc} 30%, var(--border))` }}>
      <div className="flex items-center justify-center px-6 py-5 flex-shrink-0" style={{ background: `color-mix(in srgb, ${gc} 12%, transparent)`, borderRight: `1px solid color-mix(in srgb, ${gc} 22%, var(--border))` }}>
        <span className="font-mono font-bold text-[56px] leading-none tabular-nums" style={{ color: gc }}>{totals.grade}</span>
      </div>
      <div className="flex flex-col justify-center gap-1.5 px-5 py-4 min-w-0">
        <span className="font-mono text-[10px] font-semibold tracking-[0.1em] uppercase text-fg-mute">Grade · verdict</span>
        <span className="font-sans font-bold text-[17px] text-fg-1 leading-tight">{totals.verdict}</span>
        <div className="flex items-center gap-4 mt-0.5">
          <span className="font-mono text-[11.5px] text-fg-2">Overall <span className="font-bold tabular-nums text-fg-1">{totals.overall_score.toFixed(1)}</span><span className="text-fg-faint">/100</span></span>
          <span className="font-mono text-[11.5px] text-fg-2">Risk <span className="font-bold tabular-nums" style={{ color: totals.risk_score > 50 ? 'var(--warn)' : 'var(--fg-1)' }}>{totals.risk_score.toFixed(1)}</span></span>
        </div>
      </div>
    </div>
  );
};

// ---------- verdict breakdown bar ----------
const VerdictBar = ({ verdict }) => {
  const total = verdict.reduce((a, v) => a + v.n, 0);
  return (
    <div className="card card--flat overflow-hidden">
      <ACardHead icon="layers" title="Verdict breakdown" accent hint={total + ' sims'}/>
      <div className="p-4">
        <div className="flex h-[26px] rounded-control overflow-hidden border border-line">
          {verdict.map(v => (
            <div key={v.key} title={`${v.label} · ${v.n}`} style={{
              width: (v.n / total * 100) + '%',
              background: v.striped
                ? `repeating-linear-gradient(45deg, color-mix(in srgb, ${v.color} 40%, transparent), color-mix(in srgb, ${v.color} 40%, transparent) 5px, transparent 5px, transparent 10px)`
                : v.color,
            }}/>
          ))}
        </div>
        <div className="grid grid-cols-2 gap-x-5 gap-y-2 mt-3.5 max-[420px]:grid-cols-1">
          {verdict.map(v => (
            <div key={v.key} className="flex items-center gap-2">
              <span className="w-[10px] h-[10px] rounded-[2px] flex-shrink-0" style={{ background: v.color, opacity: v.striped ? 0.6 : 1 }}/>
              <span className="font-mono text-[11px] text-fg-2 truncate">{v.label}{v.key === 'inconclusive' && <span className="text-fg-faint"> · not actionable</span>}</span>
              <span className="font-mono text-[11.5px] font-bold tabular-nums ml-auto" style={{ color: v.color }}>{v.n}</span>
              <span className="font-mono text-[10px] tabular-nums text-fg-faint w-[38px] text-right">{(v.n / total * 100).toFixed(0)}%</span>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
};

// ---------- rung distribution ----------
const RungChart = ({ rungs }) => {
  const max = Math.max(...rungs.map(r => r.n));
  return (
    <div className="card card--flat overflow-hidden">
      <ACardHead icon="steps" title="Rung distribution" accent hint="ladder depth reached"/>
      <div className="p-4 flex flex-col gap-2.5">
        {rungs.map(r => {
          const deepest = r.rung === rungs.length;
          const c = deepest ? 'var(--pnl-down-fg)' : r.rung === rungs.length - 1 ? 'var(--warn)' : 'var(--accent)';
          return (
            <div key={r.rung} className="flex items-center gap-3">
              <span className="font-mono text-[10px] font-bold tracking-[0.05em] uppercase text-fg-mute w-[48px] flex-shrink-0">Rung {r.rung}</span>
              <div className="flex-1 h-[16px] rounded-chip bg-surface-3 overflow-hidden">
                <div className="h-full rounded-chip transition-[width] duration-base" style={{ width: (r.n / max * 100) + '%', background: c, opacity: deepest ? 1 : 0.85 }}/>
              </div>
              <span className="font-mono text-[11.5px] font-semibold tabular-nums text-fg-1 w-[36px] text-right">{r.n}</span>
            </div>
          );
        })}
        <span className="font-mono text-[10px] text-fg-faint mt-0.5">Deeper rungs = more averaging-down — rung {rungs.length} reach is the key risk signal.</span>
      </div>
    </div>
  );
};

// ---------- [H] regime stability band ----------
const RegimeBand = ({ regimes }) => {
  const worst = Math.min(...regimes.map(r => r.pass));
  const passColor = (p) => p >= 0.8 ? 'var(--pnl-up-fg)' : p >= 0.6 ? 'var(--warn)' : 'var(--pnl-down-fg)';
  return (
    <div className="card card--flat overflow-hidden">
      <ACardHead icon="activity" title="Regime stability" accent hint={`worst ${(worst * 100).toFixed(0)}% pass`}/>
      <div className="p-4">
        <div className="flex items-end gap-1.5 h-[96px]">
          {regimes.map((r, i) => {
            const isWorst = r.pass === worst;
            const c = passColor(r.pass);
            return (
              <div key={i} className="flex-1 flex flex-col items-center justify-end h-full group relative">
                <div className="w-full rounded-t-[3px] transition-all duration-base relative" style={{
                  height: (r.pass * 100) + '%', minHeight: 4, background: c, opacity: isWorst ? 1 : 0.8,
                  boxShadow: isWorst ? `0 0 0 2px color-mix(in srgb, ${c} 55%, transparent)` : 'none',
                }}/>
                <div className="absolute bottom-[calc(100%+6px)] left-1/2 -translate-x-1/2 z-20 hidden group-hover:block whitespace-nowrap bg-surface border border-line-strong rounded-control shadow-3 px-2.5 py-1.5 pointer-events-none">
                  <div className="font-mono text-[10px] font-bold text-fg-1">{r.from} – {r.to}</div>
                  <div className="font-mono text-[10px]" style={{ color: c }}>{(r.pass * 100).toFixed(0)}% pass · {r.stops} stops</div>
                </div>
              </div>
            );
          })}
        </div>
        <div className="flex items-center justify-between mt-2 pt-2 border-t border-line-soft">
          <span className="font-mono text-[9.5px] tracking-[0.06em] uppercase text-fg-3">{regimes[0].from}</span>
          <span className="font-mono text-[10px] text-fg-mute">Each bar = a time bucket · height = pass rate · worst highlighted</span>
          <span className="font-mono text-[9.5px] tracking-[0.06em] uppercase text-fg-3">{regimes[regimes.length - 1].to}</span>
        </div>
      </div>
    </div>
  );
};

Object.assign(window, {
  CoverageStrip, FetchReport, StatMini, GradeHero, VerdictBar, RungChart, RegimeBand,
});
