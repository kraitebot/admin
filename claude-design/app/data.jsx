// Kraite admin — mock data + small shared display components.
// Realistic crypto-futures operator data. Numbers are the hero.

// ---- regime palette (maps BSCS regime -> color + bg/border for pills) ----
const REGIMES = {
  CALM:        { color: 'var(--bsi-calm)',      bg: '#002e1c', border: '#003d24', fg: '#1ee08e' },
  WATCH:       { color: 'var(--bsi-watch)',     bg: '#3a2a00', border: '#5a4500', fg: '#ffc94a' },
  ELEVATED:    { color: 'var(--bsi-cascade)',   bg: '#3a0a0a', border: '#5a0f0f', fg: '#ff6b6b' },
  CASCADE:     { color: 'var(--bsi-cascade)',   bg: '#3a0a0a', border: '#5a0f0f', fg: '#ff6b6b' },
  'BLACK SWAN':{ color: 'var(--bsi-blackswan)', bg: '#1a0033', border: '#2a004d', fg: '#c97aff' },
};

// regime pills render fine on light content too — use semantic light tones there.
const RegimePill = ({ regime, score, pulse }) => {
  const r = REGIMES[regime] || REGIMES.CALM;
  return (
    <span className="pill" style={{
      background: 'color-mix(in srgb, ' + r.color + ' 12%, transparent)',
      borderColor: 'color-mix(in srgb, ' + r.color + ' 38%, transparent)',
      color: r.color,
    }}>
      <span className="pill__dot" style={{ background: r.color, animation: pulse ? 'kr-pulse 1.4s ease-in-out infinite' : undefined }}/>
      {regime}{score != null && <span style={{ opacity: 0.7, marginLeft: 2 }}>{score.toFixed(2)}</span>}
    </span>
  );
};

// ---- compact sparkline ----
const Sparkline = ({ data, up = true, w = 120, h = 34, fill = true }) => {
  const min = Math.min(...data), max = Math.max(...data);
  const rng = max - min || 1;
  const pts = data.map((v, i) => [
    (i / (data.length - 1)) * w,
    h - 3 - ((v - min) / rng) * (h - 6),
  ]);
  const line = pts.map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1)).join(' ');
  const area = line + ` L${w} ${h} L0 ${h} Z`;
  const col = up ? 'var(--pnl-up-fg)' : 'var(--pnl-down-fg)';
  const id = 'sp' + Math.random().toString(36).slice(2, 7);
  return (
    <svg className="spark" viewBox={`0 0 ${w} ${h}`} preserveAspectRatio="none" width={w} height={h}>
      {fill && <defs><linearGradient id={id} x1="0" y1="0" x2="0" y2="1">
        <stop offset="0%" stopColor={col} stopOpacity="0.18"/>
        <stop offset="100%" stopColor={col} stopOpacity="0"/>
      </linearGradient></defs>}
      {fill && <path d={area} fill={`url(#${id})`}/>}
      <path d={line} fill="none" stroke={col} strokeWidth="1.5" vectorEffect="non-scaling-stroke"/>
    </svg>
  );
};

// ---- delta number with arrow ----
const Delta = ({ value, suffix = '%', showArrow = true }) => {
  const up = value >= 0;
  return (
    <span className={'tile__delta ' + (up ? 'pnl-up' : 'pnl-down')}>
      {showArrow && <UIcon name={up ? 'arrowUp' : 'arrowDown'} size={12} style={{ width: 11, height: 11 }}/>}
      {up ? '+' : ''}{value.toFixed(2)}{suffix}
    </span>
  );
};

// ========================= MOCK DATA =========================

const KPIS = [
  { key: 'pv',  label: 'Portfolio value', icon: 'wallet',  value: '$284,910.42', delta: 1.84,  sub: 'NET EQUITY · USD',
    spark: [262,264,261,268,270,267,272,275,273,279,278,281,280,285], up: true },
  { key: 'pnl', label: "P&L — today",     icon: 'zap',     value: '+$5,142.18',  delta: 1.84,  sub: 'REALIZED + UNREAL.',
    spark: [0,0.4,0.2,0.9,0.7,1.3,1.1,1.0,1.6,1.4,1.9,1.7,2.1,1.84], up: true },
  { key: 'p30', label: 'P&L — 30 day',    icon: 'arrowUpRight', value: '+$23,418.06', delta: 8.96, sub: 'NET OF FEES',
    spark: [0,2,1.5,3,4,3.6,5,4.7,6,5.8,7,6.6,8,8.96], up: true },
  { key: 'op',  label: 'Open positions',  icon: 'layers',  value: '10', delta: null, sub: '6 LONG · 4 SHORT',
    spark: null, up: true, foot: true },
];

// cmcId → CoinMarketCap 64px coin icon. osc → 4 timeframe-oscillation states (up/down/idle).
// trackPx / trackTp = % positions of the price marker and TP marker on the lifecycle track.
// The limit-order ladder sits on the left (LADDER); price sits past it; TP on the right.
const POSITIONS = [
  { sym: 'BTC',  market: 'BTC-PERP',  name: 'Bitcoin',   cmcId: 1,     color: '#f7931a', osc: ['up','up','down','up'],    side: 'long',  lev: '3×', eta: '2h from now',  stage: 3, path: 4.3, limit: 16.7, filled: '1 / 4', trackTp: 26, trackPx: 35, open: '67,420.00', tp: '70,250.00', next: '66,310.00', pnl: 1266.93,  pnlPct: 2.21 },
  { sym: 'ETH',  market: 'ETH-PERP',  name: 'Ethereum',  cmcId: 1027,  color: '#627eea', osc: ['idle','idle','idle','idle'], status: 'opening', side: 'long',  lev: '3×', eta: '1h from now',  stage: 2, path: 3.4, limit: 13.1, filled: '0 / 4', trackTp: 5, trackPx: 13, open: '3,512.00',  tp: '3,624.00',  next: '3,448.00',  pnl: 448.88,   pnlPct: 1.03 },
  { sym: 'SOL',  market: 'SOL-PERP',  name: 'Solana',    cmcId: 5426,  color: '#9945ff', osc: ['down','up','up','up'],    status: 'waped',   side: 'short', lev: '2×', eta: '40m from now', stage: 4, path: 2.2, limit: 8.6,  filled: '2 / 4', trackTp: 44, trackPx: 54, open: '168.40',    tp: '162.10',    next: '171.20',    pnl: 1386.00,  pnlPct: 3.74 },
  { sym: 'ARB',  market: 'ARB-PERP',  name: 'Arbitrum',  cmcId: 11841, color: '#28a0f0', osc: ['down','down','up','down'], side: 'long',  lev: '4×', eta: '3h from now',  stage: 1, path: 1.9, limit: 7.2,  filled: '0 / 4', trackTp: 5, trackPx: 20, open: '0.8920',    tp: '0.9180',    next: '0.8710',    pnl: -113.40,  pnlPct: -2.35 },
  { sym: 'AVAX', market: 'AVAX-PERP', name: 'Avalanche', cmcId: 5805,  color: '#e84142', osc: ['up','down','down','up'],  side: 'short', lev: '2×', eta: '1h from now',  stage: 3, path: 1.5, limit: 6.0,  filled: '1 / 4', trackTp: 26, trackPx: 37, open: '38.20',     tp: '36.40',     next: '39.05',     pnl: -80.75,   pnlPct: -2.22 },
  { sym: 'DOGE', market: 'DOGE-PERP', name: 'Dogecoin',  cmcId: 74,    color: '#c2a633', osc: ['up','up','down','up'],    side: 'long',  lev: '3×', eta: '5h from now',  stage: 2, path: 1.2, limit: 4.8,  filled: '0 / 4', trackTp: 5, trackPx: 16, open: '0.16200',   tp: '0.16850',   next: '0.15900',   pnl: 140.00,   pnlPct: 2.16 },
  { sym: 'LINK', market: 'LINK-PERP', name: 'Chainlink', cmcId: 1975,  color: '#2a5ada', osc: ['up','down','up','up'],    side: 'long',  lev: '3×', eta: '2h from now',  stage: 2, path: 2.7, limit: 9.4,  filled: '1 / 4', trackTp: 26, trackPx: 33, open: '18.92',     tp: '20.10',     next: '18.20',     pnl: 318.50,   pnlPct: 1.74 },
  { sym: 'OP',   market: 'OP-PERP',   name: 'Optimism',  cmcId: 11840, color: '#ff0420', osc: ['down','up','up','down'],  side: 'long',  lev: '4×', eta: '4h from now',  stage: 1, path: 1.6, limit: 6.4,  filled: '0 / 4', trackTp: 5, trackPx: 19, open: '2.480',     tp: '2.610',     next: '2.395',     pnl: -54.20,   pnlPct: -1.18 },
  { sym: 'XRP',  market: 'XRP-PERP',  name: 'XRP',       cmcId: 52,    color: '#23292f', osc: ['down','down','up','down'], side: 'short', lev: '2×', eta: '1h from now',  stage: 3, path: 2.0, limit: 7.8,  filled: '2 / 4', trackTp: 44, trackPx: 53, open: '0.5420',    tp: '0.5180',    next: '0.5560',    pnl: 412.00,   pnlPct: 2.62 },
  { sym: 'INJ',  market: 'INJ-PERP',  name: 'Injective', cmcId: 7226,  color: '#00a3ff', osc: ['up','down','down','up'],  side: 'short', lev: '2×', eta: '3h from now',  stage: 1, path: 1.1, limit: 5.2,  filled: '0 / 4', trackTp: 5, trackPx: 15, open: '24.80',     tp: '23.40',     next: '25.50',     pnl: -38.90,   pnlPct: -1.02 },
];

const ACCOUNTS = [
  { ex: 'Binance', mono: 'B',  tag: 'main',    state: 'ok',   latency: '12ms', equity: '$184,210.08', note: 'Futures · cross' },
  { ex: 'Bybit',   mono: 'BY', tag: 'hedge',   state: 'ok',   latency: '31ms', equity: '$62,840.12',  note: 'Perp · isolated' },
  { ex: 'OKX',     mono: 'O',  tag: 'arb',     state: 'down', latency: '—',    equity: '$24,980.55',  note: 'Last seen 4m ago' },
  { ex: 'Deribit', mono: 'D',  tag: 'options', state: 'ok',   latency: '48ms', equity: '$12,879.67',  note: 'Options · portfolio' },
];

// Our whitelisted egress servers (IP-allowlisted on the exchange side).
// All-healthy collapses the connectivity widget to a single green light.
const SERVERS = [
  { id: 'kr-fra-01', region: 'fra',  state: 'ok', latency: '11ms' },
  { id: 'kr-fra-02', region: 'fra',  state: 'ok', latency: '12ms' },
  { id: 'kr-ldn-01', region: 'ldn',  state: 'ok', latency: '19ms' },
  { id: 'kr-nyc-01', region: 'nyc',  state: 'ok', latency: '38ms' },
  { id: 'kr-sgp-01', region: 'sgp',  state: 'ok', latency: '52ms' },
  { id: 'kr-sgp-02', region: 'sgp',  state: 'ok', latency: '54ms' },
];

// Activity categories: trading · risk · funding · system
const ACTIVITY_CATS = [
  { id: 'all',     label: 'All activity' },
  { id: 'trading', label: 'Trading' },
  { id: 'risk',    label: 'Risk & regime' },
  { id: 'funding', label: 'Funding' },
  { id: 'system',  label: 'System & alerts' },
];

const ACTIVITY = [
  { kind: 'OPEN',    cat: 'trading', dot: 'var(--pnl-up-fg)',   time: '2m',  el: (<>Opened <span className="side side--long" style={{padding:'1px 5px'}}>LONG</span> <span className="sym">BTC-PERP</span> <span className="mono">0.850</span> @ <span className="mono">67,420.00</span></>) },
  { kind: 'REDUCE',  cat: 'trading', dot: 'var(--fg-mute)',     time: '14m', el: (<>Reduced <span className="sym">SOL-PERP</span> short by <span className="mono">40.0</span> @ <span className="mono">162.10</span> · realized <span className="mono pnl-up">+$252.00</span></>) },
  { kind: 'REGIME',  cat: 'risk',    dot: 'var(--bsi-watch)',   time: '38m', el: (<>BSCS regime escalated <span className="mono">CALM → WATCH</span> at score <span className="mono">0.42</span></>) },
  { kind: 'ALERT',   cat: 'system',  dot: 'var(--danger)',      time: '52m', el: (<>Exchange account <span className="sym">OKX</span> (arb) lost connectivity — bot management paused</>) },
  { kind: 'CLOSE',   cat: 'trading', dot: 'var(--pnl-up-fg)',   time: '1h',  el: (<>Closed <span className="sym">LINK-PERP</span> long <span className="mono pnl-up">+$312.40</span> @ <span className="mono">18.92</span></>) },
  { kind: 'SIZING',  cat: 'risk',    dot: 'var(--bsi-watch)',   time: '1h',  el: (<>Position sizing tightened — max notional <span className="mono">4.0× → 3.0×</span></>) },
  { kind: 'FUNDING', cat: 'funding', dot: 'var(--fg-mute)',     time: '2h',  el: (<>Funding collected <span className="mono pnl-up">+$84.20</span> across <span className="mono">4</span> positions</>) },
  { kind: 'LOGIN',   cat: 'system',  dot: 'var(--fg-mute)',     time: '2h',  el: (<>New sign-in from <span className="mono">Frankfurt, DE</span> · session <span className="mono">a1f9…</span></>) },
  { kind: 'FUNDING', cat: 'funding', dot: 'var(--fg-mute)',     time: '3h',  el: (<>Funding paid <span className="mono pnl-down">-$31.50</span> on <span className="sym">SOL-PERP</span> short</>) },
  { kind: 'CLOSE',   cat: 'trading', dot: 'var(--pnl-down-fg)', time: '3h',  el: (<>Closed <span className="sym">APT-PERP</span> short <span className="mono pnl-down">-$96.10</span> @ <span className="mono">9.14</span> · stop hit</>) },
  { kind: 'OPEN',    cat: 'trading', dot: 'var(--pnl-up-fg)',   time: '4h',  el: (<>Opened <span className="side side--short" style={{padding:'1px 5px'}}>SHORT</span> <span className="sym">AVAX-PERP</span> <span className="mono">95.0</span> @ <span className="mono">38.20</span></>) },
  { kind: 'REGIME',  cat: 'risk',    dot: 'var(--fg-mute)',     time: '5h',  el: (<>BSCS regime eased <span className="mono">WATCH → CALM</span> at score <span className="mono">0.31</span></>) },
  { kind: 'FUNDING', cat: 'funding', dot: 'var(--pnl-up-fg)',   time: '6h',  el: (<>Funding collected <span className="mono pnl-up">+$61.80</span> across <span className="mono">5</span> positions</>) },
  { kind: 'SYNC',    cat: 'system',  dot: 'var(--fg-mute)',     time: '6h',  el: (<>Account <span className="sym">Binance</span> balances synced · <span className="mono">$184,210.08</span></>) },
];

Object.assign(window, { REGIMES, RegimePill, Sparkline, Delta, KPIS, POSITIONS, ACCOUNTS, SERVERS, ACTIVITY, ACTIVITY_CATS });
