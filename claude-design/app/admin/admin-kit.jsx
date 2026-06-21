// Kraite SYSADMIN console — shared kit: layout constants, platform mock data,
// and small display primitives. Loaded before admin-shell / overview.
// Self-contained (does not depend on the trader page scripts) but reuses the
// global tokens, icons (UIcon), and data helpers (RegimePill/Sparkline/Delta).

// ---------- layout + button constants (admin-local; no trader collision) ----------
const A_BTN = "appearance-none font-sans font-semibold rounded-control border cursor-pointer inline-flex items-center gap-[7px] whitespace-nowrap transition-colors duration-fast ease-out active:translate-y-px h-[36px] px-3.5 text-[13px]";
const A_BTN_PRIMARY = A_BTN + " border-transparent bg-accent text-accent-on hover:bg-accent-hover";
const A_BTN_SECONDARY = A_BTN + " bg-transparent text-fg-1 border-line-strong hover:bg-hover";
const A_BTN_GHOST = A_BTN + " bg-transparent text-fg-3 border-transparent hover:bg-hover hover:text-fg-1";
const A_PAGEHEAD = "flex items-end justify-between gap-5 pb-5 mb-6 border-b border-line max-[820px]:flex-col max-[820px]:items-start";
const A_EYEBROW = "font-mono text-[11px] font-medium tracking-[0.12em] uppercase text-fg-3 mb-2 flex items-center gap-2";
const A_H1 = "font-sans font-bold text-[28px] tracking-[-0.02em] text-fg-1 leading-[1.1] max-[640px]:text-[24px]";
const A_SUB = "text-[13px] text-fg-3 mt-1.5";

// ---------- section card head (mirrors AcctBandHead) ----------
const ACardHead = ({ icon, title, hint, right, accent, onClick, collapsed }) => {
  const Tag = onClick ? 'button' : 'div';
  return (
    <Tag onClick={onClick}
      className={"w-full flex items-center justify-between gap-3 py-[13px] px-5 bg-surface-2 rounded-t-surface max-[640px]:px-4 text-left "
        + (collapsed ? "rounded-b-surface " : "border-b border-line-soft ")
        + (onClick ? "appearance-none border-x-0 border-t-0 cursor-pointer transition-colors duration-fast hover:bg-hover" : "")}>
      <h4 className="font-sans font-semibold text-[14px] text-fg-1 flex items-center gap-[9px] whitespace-nowrap leading-none">
        {icon && <UIcon name={icon} size={16} style={{ color: accent ? 'var(--accent)' : 'var(--fg-3)' }}/>}{title}
      </h4>
      {right || (hint ? <span className="font-mono text-[10.5px] text-fg-mute tracking-[0.02em]">{hint}</span> : null)}
    </Tag>
  );
};

// ---------- health dot + chip ----------
const HEALTH = {
  healthy:  { c: 'var(--pnl-up-fg)', t: 'Healthy' },
  degraded: { c: 'var(--warn)',      t: 'Degraded' },
  draining: { c: 'var(--info)',      t: 'Draining' },
  down:     { c: 'var(--danger)',    t: 'Down' },
  operational: { c: 'var(--pnl-up-fg)', t: 'Operational' },
  maintenance: { c: 'var(--fg-mute)',   t: 'Maintenance' },
};
const HealthDot = ({ state, pulse }) => {
  const m = HEALTH[state] || HEALTH.healthy;
  return <span className={"w-[8px] h-[8px] rounded-chip flex-shrink-0" + (pulse ? " animate-pulse-soft" : "")} style={{ background: m.c }}/>;
};
const HealthChip = ({ state }) => {
  const m = HEALTH[state] || HEALTH.healthy;
  const pulse = state === 'down' || state === 'degraded';
  return (
    <span className="inline-flex items-center gap-[7px] py-[5px] px-[11px] rounded-chip border font-mono text-[10.5px] font-bold tracking-[0.07em] uppercase whitespace-nowrap"
      style={{ color: m.c, borderColor: `color-mix(in srgb, ${m.c} 36%, transparent)`, background: `color-mix(in srgb, ${m.c} 12%, transparent)` }}>
      <span className={"w-[7px] h-[7px] rounded-chip" + (pulse ? " animate-pulse-soft" : "")} style={{ background: m.c }}/>{m.t}
    </span>
  );
};

// ---------- usage meter (cpu / mem) ----------
const UsageBar = ({ pct, label }) => {
  const c = pct >= 90 ? 'var(--danger)' : pct >= 75 ? 'var(--warn)' : 'var(--pnl-up-fg)';
  return (
    <div className="flex flex-col gap-1 min-w-[64px]">
      <div className="flex items-center justify-between gap-2">
        <span className="font-mono text-[9px] tracking-[0.08em] uppercase text-fg-mute">{label}</span>
        <span className="font-mono text-[11px] font-semibold tabular-nums" style={{ color: pct >= 75 ? c : 'var(--fg-2)' }}>{pct}%</span>
      </div>
      <div className="h-[4px] rounded-chip bg-surface-3 overflow-hidden">
        <div className="h-full rounded-chip transition-[width] duration-base" style={{ width: pct + '%', background: c }}/>
      </div>
    </div>
  );
};

// ---------- mini ring gauge (circular perf dial with centered value) ----------
const MiniGauge = ({ value, size = 60 }) => {
  const pct = Math.max(0, Math.min(100, value)) / 100;
  const sw = 6, r = (size - sw) / 2, c = size / 2, circ = 2 * Math.PI * r;
  // perf bands: >=80 green (good), 60–80 warn, <60 red — trading-safe semantics
  const col = value >= 80 ? 'var(--pnl-up-fg)' : value >= 60 ? 'var(--warn)' : 'var(--pnl-down-fg)';
  return (
    <div className="relative inline-flex items-center justify-center flex-shrink-0" style={{ width: size, height: size }}>
      <svg width={size} height={size} className="-rotate-90">
        <circle cx={c} cy={c} r={r} fill="none" stroke="var(--border)" strokeWidth={sw}/>
        <circle cx={c} cy={c} r={r} fill="none" stroke={col} strokeWidth={sw} strokeLinecap="round"
          strokeDasharray={circ} strokeDashoffset={circ * (1 - pct)}
          style={{ transition: 'stroke-dashoffset .6s cubic-bezier(.22,1,.36,1)' }}/>
      </svg>
      <span className="absolute font-mono font-bold tabular-nums leading-none" style={{ fontSize: 15, color: col }}>
        {Math.round(value)}<span style={{ fontSize: 9 }}>%</span>
      </span>
    </div>
  );
};

// ---------- KPI stat tile (white-invert, mirrors trader dashboard tiles) ----------
const StatTile = ({ icon, label, value, sub, delta, spark, accent, children }) => (
  <div className="tile kpi-invert overflow-hidden bg-surface border border-line rounded-control py-[13px] px-[15px] flex flex-col gap-[9px] relative transition-colors duration-fast">
    <div className="flex items-center justify-between gap-2">
      <span className="font-mono text-[10px] font-semibold tracking-[0.1em] uppercase text-fg-mute flex items-center gap-[7px]">
        <UIcon name={icon} size={14} style={{ color: 'var(--fg-3)' }}/>{label}
      </span>
      {delta != null && <Delta value={delta}/>}
    </div>
    <div className="flex items-end justify-between gap-3">
      <span className="font-mono text-[26px] font-bold tabular-nums tracking-[-0.01em] text-fg-1 leading-none">{value}</span>
      {children}
    </div>
    {spark && <div className="h-[26px] -mb-1"><Sparkline data={spark} up={true} h={26}/></div>}
    {sub && <span className="font-mono text-[9.5px] tracking-[0.08em] uppercase text-fg-mute">{sub}</span>}
  </div>
);

// ================= PLATFORM MOCK DATA =================

const A_NAV = [
  { id: 'overview',  label: 'Overview',  icon: 'activity' },
  { id: 'positions', label: 'Positions', icon: 'layers' },
  { id: 'engine',    label: 'Engine',    icon: 'bot' },
  { id: 'backtesting', label: 'Backtest', icon: 'projections' },
  { id: 'dispatcher', label: 'Dispatch', icon: 'steps' },
  { id: 'infra',     label: 'Infra',     icon: 'server' },
  { id: 'venues',    label: 'Exchanges', icon: 'exchange' },
  { id: 'sql',       label: 'SQL',       icon: 'database' },
  { id: 'revenue',   label: 'Revenue',   icon: 'wallet' },
  { id: 'settings',  label: 'Settings',  icon: 'sliders' },
];

// worker node fleet — physical hosts running bot processes
const A_WORKERS = [
  { id: 'kr-fra-01', region: 'Frankfurt', code: 'FRA', state: 'healthy',  cpu: 41, mem: 58, lat: 11, bots: 214, build: 'v4.2.1', up: '38d' },
  { id: 'kr-fra-02', region: 'Frankfurt', code: 'FRA', state: 'healthy',  cpu: 47, mem: 61, lat: 12, bots: 201, build: 'v4.2.1', up: '38d' },
  { id: 'kr-ldn-01', region: 'London',    code: 'LDN', state: 'healthy',  cpu: 52, mem: 64, lat: 19, bots: 188, build: 'v4.2.1', up: '21d' },
  { id: 'kr-nyc-01', region: 'New York',  code: 'NYC', state: 'healthy',  cpu: 38, mem: 49, lat: 38, bots: 142, build: 'v4.2.0', up: '12d' },
  { id: 'kr-nyc-02', region: 'New York',  code: 'NYC', state: 'draining', cpu: 9,  mem: 22, lat: 41, bots: 18,  build: 'v4.2.0', up: '12d' },
  { id: 'kr-sgp-01', region: 'Singapore', code: 'SGP', state: 'healthy',  cpu: 63, mem: 70, lat: 52, bots: 156, build: 'v4.2.1', up: '7d' },
  { id: 'kr-sgp-02', region: 'Singapore', code: 'SGP', state: 'degraded', cpu: 94, mem: 88, lat: 142, bots: 161, build: 'v4.2.1', up: '7d' },
  { id: 'kr-tok-01', region: 'Tokyo',     code: 'TOK', state: 'healthy',  cpu: 44, mem: 55, lat: 61, bots: 160, build: 'v4.2.1', up: '4d' },
];

// supported exchanges — global connectivity matrix
const A_VENUES = [
  { ex: 'Binance',  mono: 'B',  state: 'operational', lat: 12,  err: 0.02, accts: 642, spark: [11,12,12,11,13,12,12] },
  { ex: 'Bybit',    mono: 'BY', state: 'operational', lat: 31,  err: 0.04, accts: 388, spark: [30,31,29,32,31,33,31] },
  { ex: 'OKX',      mono: 'O',  state: 'degraded',    lat: 210, err: 1.84, accts: 124, spark: [44,52,90,140,180,205,210] },
  { ex: 'Deribit',  mono: 'D',  state: 'operational', lat: 48,  err: 0.01, accts: 96,  spark: [47,48,49,48,47,48,48] },
  { ex: 'Kraken',   mono: 'K',  state: 'operational', lat: 22,  err: 0.03, accts: 210, spark: [21,22,23,22,21,22,22] },
  { ex: 'Coinbase', mono: 'C',  state: 'maintenance', lat: null, err: null, accts: 54, spark: [26,27,28,0,0,0,0] },
];

// platform incident + event feed
const A_INCIDENTS = [
  { sev: 'warn',  icon: 'exchange', time: '2m',  text: <>OKX API latency elevated to <span className="font-mono text-warn font-semibold">210ms</span> · 124 accounts affected · auto-throttling engaged</> },
  { sev: 'warn',  icon: 'cpu',      time: '18m', text: <>Worker <span className="font-mono text-fg-1 font-semibold">kr-sgp-02</span> CPU saturation <span className="font-mono text-warn font-semibold">94%</span> · load shedding active</> },
  { sev: 'brand', icon: 'zap',      time: '41m', text: <>Deploy <span className="font-mono text-fg-1 font-semibold">v4.2.1</span> shipped to FRA-01, FRA-02, LDN-01 <span className="text-fg-mute">(canary)</span></> },
  { sev: 'warn',  icon: 'shield',   time: '1h',  text: <>BSCS regime escalated <span className="font-mono font-semibold">WATCH → ELEVATED</span> at score <span className="font-mono">0.63</span> · platform-wide</> },
  { sev: 'mute',  icon: 'maintenance', time: '2h', text: <>Worker <span className="font-mono text-fg-1 font-semibold">kr-nyc-02</span> drained for maintenance · 18 bots migrated</> },
  { sev: 'good',  icon: 'users',    time: '3h',  text: <>Signup spike — <span className="font-mono text-pnlup font-semibold">+64 traders</span> in the last hour</> },
  { sev: 'mute',  icon: 'power',    time: '5h',  text: <>Kill-switch drill completed · halt → resume in <span className="font-mono text-fg-1 font-semibold">0.8s</span></> },
];

const A_REVENUE = { mrr: '$412.8k', mrrDelta: 4.2, topupsToday: '$84,210', topupCount: 12, float: '$1.92M' };

// ---------- BSCS regime ramp bar (platform-wide) ----------
const REGIME_ORDER = ['CALM', 'WATCH', 'ELEVATED', 'CASCADE', 'BLACK SWAN'];
const RegimeRamp = ({ regime, score }) => {
  const idx = REGIME_ORDER.indexOf(regime);
  return (
    <div className="flex flex-col gap-2">
      <div className="flex items-stretch gap-1 h-[8px]">
        {REGIME_ORDER.map((r, i) => {
          const m = REGIMES[r];
          const on = i <= idx;
          return <div key={r} className="flex-1 rounded-chip transition-colors duration-base" style={{ background: on ? m.color : 'var(--bg-elev-3)', opacity: on ? 1 : 1 }}/>;
        })}
      </div>
      <div className="flex items-center justify-between">
        <span className="font-mono text-[9px] tracking-[0.08em] uppercase text-fg-mute">Calm</span>
        <span className="font-mono text-[9px] tracking-[0.08em] uppercase text-fg-mute">Black swan</span>
      </div>
    </div>
  );
};

Object.assign(window, {
  A_BTN, A_BTN_PRIMARY, A_BTN_SECONDARY, A_BTN_GHOST, A_PAGEHEAD, A_EYEBROW, A_H1, A_SUB,
  ACardHead, HEALTH, HealthDot, HealthChip, UsageBar, StatTile,
  A_NAV, A_WORKERS, A_VENUES, A_INCIDENTS, A_REVENUE, REGIME_ORDER, RegimeRamp,
});
