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
const PILL_CLS = "inline-flex items-center gap-[7px] py-[5px] px-[13px] rounded-chip border font-mono text-[11px] font-semibold tracking-[0.1em] uppercase whitespace-nowrap";
const RegimePill = ({ regime, score, pulse }) => {
  const r = REGIMES[regime] || REGIMES.CALM;
  return (
    <span className={PILL_CLS} style={{
      background: 'color-mix(in srgb, ' + r.color + ' 12%, transparent)',
      borderColor: 'color-mix(in srgb, ' + r.color + ' 38%, transparent)',
      color: r.color,
    }}>
      <span className={"w-2 h-2 rounded-chip" + (pulse ? " animate-pulse-soft" : "")} style={{ background: r.color }}/>
      {regime}{score != null && <span className="opacity-70 ml-0.5">{score.toFixed(2)}</span>}
    </span>
  );
};

// shared inline-data class strings (feed + table cells)
const SYM  = "font-mono font-semibold text-fg-1";
const MONO = "font-mono tabular-nums text-fg-1";
const MUP  = "font-mono tabular-nums text-pnlup";
const MDN  = "font-mono tabular-nums text-pnldown";
const TAG_BASE = "align-middle inline-flex items-center gap-[5px] font-mono text-[10.5px] font-bold tracking-[0.07em] uppercase rounded-chip py-px px-[5px] before:content-[''] before:w-1.5 before:h-1.5 before:rounded-chip before:bg-current before:opacity-90";
const TAG_L = TAG_BASE + " bg-pnlup-bg text-pnlup";
const TAG_S = TAG_BASE + " bg-pnldown-bg text-pnldown";

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
    <svg className="block w-full" viewBox={`0 0 ${w} ${h}`} preserveAspectRatio="none" width={w} height={h}>
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
    <span className={"font-mono text-[11px] font-semibold tabular-nums inline-flex items-center gap-[2px] py-0.5 px-[7px] rounded-chip " + (up ? "text-pnlup bg-pnlup-bg" : "text-pnldown bg-pnldown-bg")}>
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
  { sym: 'BTC',  market: 'BTC-PERP',  name: 'Bitcoin',   cmcId: 1,     color: '#f7931a', osc: ['up','up','down','up'],    side: 'long',  lev: '3×', eta: '2h from now',  stage: 3, path: 4.3, limit: 16.7, filled: '1 / 4', trackTp: 26, trackPx: 35, open: '67,420.00', tp: '70,250.00', next: '66,310.00', pnl: 1266.93,  pnlPct: 2.21, mark: '68,910.50', size: '0.850', notional: 57307, margin: 19102, liq: '45,910.00', funding: 12.40, roe: 6.63, ageH: 28 },
  { sym: 'ETH',  market: 'ETH-PERP',  name: 'Ethereum',  cmcId: 1027,  color: '#627eea', osc: ['idle','idle','idle','idle'], status: 'opening', side: 'long',  lev: '3×', eta: '1h from now',  stage: 2, path: 3.4, limit: 13.1, filled: '0 / 4', trackTp: 5, trackPx: 13, open: '3,512.00',  tp: '3,624.00',  next: '3,448.00',  pnl: 448.88,   pnlPct: 1.03, mark: '3,548.17', size: '12.40', notional: 43549, margin: 14516, liq: '2,389.50', funding: -3.20, roe: 3.09, ageH: 0.2 },
  { sym: 'SOL',  market: 'SOL-PERP',  name: 'Solana',    cmcId: 5426,  color: '#9945ff', osc: ['down','up','up','up'],    status: 'waped',   side: 'short', lev: '2×', eta: '40m from now', stage: 4, path: 2.2, limit: 8.6,  filled: '2 / 4', trackTp: 44, trackPx: 54, open: '168.40',    tp: '162.10',    next: '171.20',    pnl: 1386.00,  pnlPct: 3.74, mark: '162.10', size: '308', notional: 51867, margin: 25933, liq: '244.10', funding: 8.90, roe: 5.34, ageH: 54 },
  { sym: 'ARB',  market: 'ARB-PERP',  name: 'Arbitrum',  cmcId: 11841, color: '#28a0f0', osc: ['down','down','up','down'], side: 'long',  lev: '4×', eta: '3h from now',  stage: 1, path: 1.9, limit: 7.2,  filled: '0 / 4', trackTp: 5, trackPx: 20, open: '0.8920',    tp: '0.9180',    next: '0.8710',    pnl: -113.40,  pnlPct: -2.35, mark: '0.8710', size: '5,400', notional: 4817, margin: 1204, liq: '0.6920', funding: -1.10, roe: -9.42, ageH: 8 },
  { sym: 'AVAX', market: 'AVAX-PERP', name: 'Avalanche', cmcId: 5805,  color: '#e84142', osc: ['up','down','down','up'],  side: 'short', lev: '2×', eta: '1h from now',  stage: 3, path: 1.5, limit: 6.0,  filled: '1 / 4', trackTp: 26, trackPx: 37, open: '38.20',     tp: '36.40',     next: '39.05',     pnl: -80.75,   pnlPct: -2.22, mark: '39.05', size: '95', notional: 3629, margin: 1814, liq: '55.10', funding: 2.30, roe: -4.45, ageH: 14 },
  { sym: 'DOGE', market: 'DOGE-PERP', name: 'Dogecoin',  cmcId: 74,    color: '#c2a633', osc: ['up','up','down','up'],    side: 'long',  lev: '3×', eta: '5h from now',  stage: 2, path: 1.2, limit: 4.8,  filled: '0 / 4', trackTp: 5, trackPx: 16, open: '0.16200',   tp: '0.16850',   next: '0.15900',   pnl: 140.00,   pnlPct: 2.16, mark: '0.16550', size: '43,000', notional: 6966, margin: 2322, liq: '0.11000', funding: -0.80, roe: 6.03, ageH: 22 },
  { sym: 'LINK', market: 'LINK-PERP', name: 'Chainlink', cmcId: 1975,  color: '#2a5ada', osc: ['up','down','up','up'],    side: 'long',  lev: '3×', eta: '2h from now',  stage: 2, path: 2.7, limit: 9.4,  filled: '1 / 4', trackTp: 26, trackPx: 33, open: '18.92',     tp: '20.10',     next: '18.20',     pnl: 318.50,   pnlPct: 1.74, mark: '19.25', size: '970', notional: 18352, margin: 6117, liq: '12.85', funding: 4.10, roe: 5.21, ageH: 26 },
  { sym: 'OP',   market: 'OP-PERP',   name: 'Optimism',  cmcId: 11840, color: '#ff0420', osc: ['down','up','up','down'],  side: 'long',  lev: '4×', eta: '4h from now',  stage: 1, path: 1.6, limit: 6.4,  filled: '0 / 4', trackTp: 5, trackPx: 19, open: '2.480',     tp: '2.610',     next: '2.395',     pnl: -54.20,   pnlPct: -1.18, mark: '2.451', size: '1,850', notional: 4588, margin: 1147, liq: '1.9250', funding: -0.90, roe: -4.73, ageH: 6 },
  { sym: 'XRP',  market: 'XRP-PERP',  name: 'XRP',       cmcId: 52,    color: '#23292f', osc: ['down','down','up','down'], side: 'short', lev: '2×', eta: '1h from now',  stage: 3, path: 2.0, limit: 7.8,  filled: '2 / 4', trackTp: 44, trackPx: 53, open: '0.5420',    tp: '0.5180',    next: '0.5560',    pnl: 412.00,   pnlPct: 2.62, mark: '0.5278', size: '13,800', notional: 7480, margin: 3740, liq: '0.7850', funding: 3.40, roe: 11.02, ageH: 16 },
  { sym: 'INJ',  market: 'INJ-PERP',  name: 'Injective', cmcId: 7226,  color: '#00a3ff', osc: ['up','down','down','up'],  side: 'short', lev: '2×', eta: '3h from now',  stage: 1, path: 1.1, limit: 5.2,  filled: '0 / 4', trackTp: 5, trackPx: 15, open: '24.80',     tp: '23.40',     next: '25.50',     pnl: -38.90,   pnlPct: -1.02, mark: '25.05', size: '310', notional: 7688, margin: 3844, liq: '36.10', funding: 1.80, roe: -1.01, ageH: 10 },
];

// ---- closed / historical positions (realized) ----
// reason: 'tp' (target hit) · 'stop' (stop-loss) · 'manual' · 'regime' (closed by Black-Swan halt)
const CLOSED = [
  { sym: 'LINK', name: 'Chainlink', cmcId: 1975,  color: '#2a5ada', side: 'long',  lev: '3×', entry: '17.80',     exit: '18.92',     size: '970',    pnl: 312.40,   roe: 6.0,  durH: 28, closedAgo: '1h',    reason: 'tp' },
  { sym: 'APT',  name: 'Aptos',     cmcId: 21794, color: '#1a1a2e', side: 'short', lev: '2×', entry: '8.900',    exit: '9.140',     size: '1,200',  pnl: -96.10,   roe: -2.7, durH: 3,  closedAgo: '3h',    reason: 'stop' },
  { sym: 'BTC',  name: 'Bitcoin',   cmcId: 1,     color: '#f7931a', side: 'long',  lev: '3×', entry: '64,200.00', exit: '66,980.00', size: '0.620',  pnl: 1722.60,  roe: 8.1,  durH: 41, closedAgo: '6h',    reason: 'tp' },
  { sym: 'SUI',  name: 'Sui',       cmcId: 20947, color: '#4da2ff', side: 'long',  lev: '3×', entry: '1.840',    exit: '1.762',     size: '3,400',  pnl: -265.20,  roe: -7.2, durH: 11, closedAgo: '9h',    reason: 'stop' },
  { sym: 'SOL',  name: 'Solana',    cmcId: 5426,  color: '#9945ff', side: 'short', lev: '2×', entry: '178.20',    exit: '171.40',    size: '290',    pnl: 1972.00,  roe: 7.6,  durH: 33, closedAgo: '12h',   reason: 'tp' },
  { sym: 'DOGE', name: 'Dogecoin',  cmcId: 74,    color: '#c2a633', side: 'long',  lev: '3×', entry: '0.15800',  exit: '0.16240',   size: '38,000', pnl: 167.20,   roe: 5.4,  durH: 19, closedAgo: '14h',   reason: 'manual' },
  { sym: 'ETH',  name: 'Ethereum',  cmcId: 1027,  color: '#627eea', side: 'long',  lev: '3×', entry: '3,640.00',  exit: '3,512.00',  size: '9.80',   pnl: -1254.40, roe: -8.8, durH: 7,  closedAgo: '18h',   reason: 'regime' },
  { sym: 'AVAX', name: 'Avalanche', cmcId: 5805,  color: '#e84142', side: 'short', lev: '2×', entry: '41.20',     exit: '38.60',     size: '120',    pnl: 312.00,   roe: 5.1,  durH: 22, closedAgo: '1d',    reason: 'tp' },
  { sym: 'TIA',  name: 'Celestia',  cmcId: 22861, color: '#7b2bf9', side: 'short', lev: '2×', entry: '9.800',    exit: '10.120',    size: '2,100',  pnl: -134.40,  roe: -3.6, durH: 5,  closedAgo: '1d 4h', reason: 'stop' },
  { sym: 'XRP',  name: 'XRP',       cmcId: 52,    color: '#23292f', side: 'long',  lev: '3×', entry: '0.5180',   exit: '0.5420',    size: '12,000', pnl: 288.00,   roe: 6.7,  durH: 26, closedAgo: '1d 8h', reason: 'tp' },
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
  { kind: 'OPEN',    cat: 'trading', dot: 'var(--pnl-up-fg)',   time: '2m',  el: (<>Opened <span className={TAG_L}>LONG</span> <span className={SYM}>BTC-PERP</span> <span className={MONO}>0.850</span> @ <span className={MONO}>67,420.00</span></>) },
  { kind: 'REDUCE',  cat: 'trading', dot: 'var(--fg-mute)',     time: '14m', el: (<>Reduced <span className={SYM}>SOL-PERP</span> short by <span className={MONO}>40.0</span> @ <span className={MONO}>162.10</span> · realized <span className={MUP}>+$252.00</span></>) },
  { kind: 'REGIME',  cat: 'risk',    dot: 'var(--bsi-watch)',   time: '38m', el: (<>BSCS regime escalated <span className={MONO}>CALM → WATCH</span> at score <span className={MONO}>0.42</span></>) },
  { kind: 'ALERT',   cat: 'system',  dot: 'var(--danger)',      time: '52m', el: (<>Exchange account <span className={SYM}>OKX</span> (arb) lost connectivity — bot management paused</>) },
  { kind: 'CLOSE',   cat: 'trading', dot: 'var(--pnl-up-fg)',   time: '1h',  el: (<>Closed <span className={SYM}>LINK-PERP</span> long <span className={MUP}>+$312.40</span> @ <span className={MONO}>18.92</span></>) },
  { kind: 'SIZING',  cat: 'risk',    dot: 'var(--bsi-watch)',   time: '1h',  el: (<>Position sizing tightened — max notional <span className={MONO}>4.0× → 3.0×</span></>) },
  { kind: 'FUNDING', cat: 'funding', dot: 'var(--fg-mute)',     time: '2h',  el: (<>Funding collected <span className={MUP}>+$84.20</span> across <span className={MONO}>4</span> positions</>) },
  { kind: 'LOGIN',   cat: 'system',  dot: 'var(--fg-mute)',     time: '2h',  el: (<>New sign-in from <span className={MONO}>Frankfurt, DE</span> · session <span className={MONO}>a1f9…</span></>) },
  { kind: 'FUNDING', cat: 'funding', dot: 'var(--fg-mute)',     time: '3h',  el: (<>Funding paid <span className={MDN}>-$31.50</span> on <span className={SYM}>SOL-PERP</span> short</>) },
  { kind: 'CLOSE',   cat: 'trading', dot: 'var(--pnl-down-fg)', time: '3h',  el: (<>Closed <span className={SYM}>APT-PERP</span> short <span className={MDN}>-$96.10</span> @ <span className={MONO}>9.14</span> · stop hit</>) },
  { kind: 'OPEN',    cat: 'trading', dot: 'var(--pnl-up-fg)',   time: '4h',  el: (<>Opened <span className={TAG_S}>SHORT</span> <span className={SYM}>AVAX-PERP</span> <span className={MONO}>95.0</span> @ <span className={MONO}>38.20</span></>) },
  { kind: 'REGIME',  cat: 'risk',    dot: 'var(--fg-mute)',     time: '5h',  el: (<>BSCS regime eased <span className={MONO}>WATCH → CALM</span> at score <span className={MONO}>0.31</span></>) },
  { kind: 'FUNDING', cat: 'funding', dot: 'var(--pnl-up-fg)',   time: '6h',  el: (<>Funding collected <span className={MUP}>+$61.80</span> across <span className={MONO}>5</span> positions</>) },
  { kind: 'SYNC',    cat: 'system',  dot: 'var(--fg-mute)',     time: '6h',  el: (<>Account <span className={SYM}>Binance</span> balances synced · <span className={MONO}>$184,210.08</span></>) },
];

Object.assign(window, { REGIMES, RegimePill, Sparkline, Delta, KPIS, POSITIONS, CLOSED, ACCOUNTS, SERVERS, ACTIVITY, ACTIVITY_CATS });
