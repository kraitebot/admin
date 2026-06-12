// Kraite SYSADMIN console — data for the built-out section pages.
// Grounded in the trading models: indicators + regime sub-signals (Engine),
// the step-dispatcher queues/stream (Dispatch), data-stream health (Infra),
// venue symbol catalog (Exchanges), fleet financials (Revenue), the single
// runtime config record (Settings). Reuses POSITIONS/CLOSED/ACCOUNTS/A_VENUES.

// ---- client accounts (which trader/desk each fleet position belongs to) ----
const A_CLIENTS = ['Renner Capital', 'Halcyon Desk', 'Meridian FX', 'Okafor Family', 'Northwind', 'Bridge & Co', 'Sato Trading', 'Vantage Q'];

// =================== ENGINE ===================
// BSCS = Black Swan Composite Score — 5 weighted sub-signals feeding the gate.
const BSCS_SIGNALS = [
  { id: 'vol',  name: 'Volatility term-structure', val: 0.71, w: 0.30, state: 'hot',  note: 'Front-month IV over realized — backwardated' },
  { id: 'liq',  name: 'Liquidity depth',           val: 0.58, w: 0.25, state: 'warn', note: 'Top-of-book thinning on majors' },
  { id: 'fund', name: 'Funding skew',              val: 0.49, w: 0.20, state: 'warn', note: 'Perp funding tilting short' },
  { id: 'corr', name: 'Correlation breakdown',     val: 0.62, w: 0.15, state: 'warn', note: 'Cross-asset dispersion rising' },
  { id: 'tail', name: 'Tail-hedge demand',         val: 0.44, w: 0.10, state: 'ok',   note: 'Put skew elevated but stable' },
];

// entry indicators the engine arms per market
const ENGINE_INDICATORS = [
  { id: 'mom',  name: 'Momentum breakout',  state: 'firing', markets: 'BTC · ETH · SOL', fired: '2m',  hits24: 41, tf: '4h' },
  { id: 'mrev', name: 'Mean-reversion band', state: 'armed',  markets: 'All majors',       fired: '14m', hits24: 28, tf: '1h' },
  { id: 'sweep',name: 'Liquidity sweep',     state: 'armed',  markets: 'BTC · ETH',        fired: '38m', hits24: 12, tf: '15m' },
  { id: 'farb', name: 'Funding arb',         state: 'idle',   markets: 'Perp basket',      fired: '2h',  hits24: 6,  tf: '8h' },
  { id: 'trend',name: 'Trend continuation',  state: 'gated',  markets: 'Alt basket',       fired: '—',   hits24: 0,  tf: '4h' },
];

// per-symbol trade configuration (constrained, backend-validated)
const SYMBOL_CFG = [
  { sym: 'BTC',  color: '#f7931a', lev: '3×', ladder: 4, pt: '0.380', sl: '5.00', enabled: true },
  { sym: 'ETH',  color: '#627eea', lev: '3×', ladder: 4, pt: '0.380', sl: '5.00', enabled: true },
  { sym: 'SOL',  color: '#9945ff', lev: '2×', ladder: 4, pt: '0.360', sl: '5.00', enabled: true },
  { sym: 'ARB',  color: '#28a0f0', lev: '4×', ladder: 4, pt: '0.400', sl: '7.50', enabled: true },
  { sym: 'AVAX', color: '#e84142', lev: '2×', ladder: 3, pt: '0.360', sl: '5.00', enabled: true },
  { sym: 'LINK', color: '#2a5ada', lev: '3×', ladder: 4, pt: '0.380', sl: '5.00', enabled: true },
  { sym: 'DOGE', color: '#c2a633', lev: '3×', ladder: 4, pt: '0.380', sl: '7.50', enabled: false },
  { sym: 'XRP',  color: '#23292f', lev: '2×', ladder: 3, pt: '0.360', sl: '5.00', enabled: true },
];

// =================== DISPATCH ===================
const DISPATCH_QUEUES = [
  { id: 'evaluate', name: 'evaluate', depth: 64, lag: 38,  rate: 1840, state: 'healthy' },
  { id: 'open',     name: 'open',     depth: 22, lag: 51,  rate: 420,  state: 'healthy' },
  { id: 'manage',   name: 'manage',   depth: 38, lag: 44,  rate: 980,  state: 'healthy' },
  { id: 'close',    name: 'close',    depth: 4,  lag: 33,  rate: 180,  state: 'healthy' },
  { id: 'hedge',    name: 'hedge',    depth: 0,  lag: 0,   rate: 0,    state: 'idle' },
  { id: 'reconcile',name: 'reconcile',depth: 31, lag: 210, rate: 60,   state: 'degraded' },
];
const STEP_STREAM = [
  { t: '0.4s', bot: 'BTC-PERP', client: 'Renner Capital', type: 'evaluate', worker: 'kr-fra-01', status: 'ok' },
  { t: '0.6s', bot: 'ETH-PERP', client: 'Halcyon Desk',   type: 'open',     worker: 'kr-fra-02', status: 'ok' },
  { t: '0.9s', bot: 'SOL-PERP', client: 'Meridian FX',    type: 'manage',   worker: 'kr-ldn-01', status: 'ok' },
  { t: '1.1s', bot: 'OKX:ARB',  client: 'Northwind',      type: 'reconcile',worker: 'kr-sgp-02', status: 'retry' },
  { t: '1.3s', bot: 'XRP-PERP', client: 'Bridge & Co',    type: 'evaluate', worker: 'kr-tok-01', status: 'ok' },
  { t: '1.6s', bot: 'AVAX-PERP',client: 'Sato Trading',   type: 'close',    worker: 'kr-nyc-01', status: 'ok' },
  { t: '1.8s', bot: 'OKX:ARB',  client: 'Northwind',      type: 'reconcile',worker: 'kr-sgp-02', status: 'stalled' },
  { t: '2.1s', bot: 'LINK-PERP',client: 'Vantage Q',      type: 'manage',   worker: 'kr-fra-01', status: 'ok' },
  { t: '2.4s', bot: 'DOGE-PERP',client: 'Renner Capital', type: 'evaluate', worker: 'kr-ldn-01', status: 'ok' },
  { t: '2.7s', bot: 'ARB-PERP', client: 'Halcyon Desk',   type: 'open',     worker: 'kr-fra-02', status: 'failed' },
];

// =================== INFRA ===================
// per-venue market-data stream health (websocket / listen-key)
const DATA_STREAMS = [
  { venue: 'Binance',  streams: 8, lag: 14,  state: 'healthy',  key: 'valid · 41m left' },
  { venue: 'Bybit',    streams: 6, lag: 31,  state: 'healthy',  key: 'valid · 58m left' },
  { venue: 'OKX',      streams: 5, lag: 210, state: 'degraded', key: 'renewing…' },
  { venue: 'Deribit',  streams: 3, lag: 48,  state: 'healthy',  key: 'valid · 22m left' },
  { venue: 'Kraken',   streams: 4, lag: 22,  state: 'healthy',  key: 'valid · 47m left' },
  { venue: 'Coinbase', streams: 0, lag: null,state: 'maintenance', key: '— paused' },
];

// =================== EXCHANGES ===================
// per-venue tradable symbol catalog: leverage bracket ceiling + live mark.
const VENUE_SYMBOLS = {
  Binance:  [{ s: 'BTC-PERP', lev: '125×', mark: '68,910.50' }, { s: 'ETH-PERP', lev: '100×', mark: '3,548.17' }, { s: 'SOL-PERP', lev: '50×', mark: '162.10' }, { s: 'XRP-PERP', lev: '75×', mark: '0.5278' }, { s: 'DOGE-PERP', lev: '75×', mark: '0.16550' }],
  Bybit:    [{ s: 'BTC-PERP', lev: '100×', mark: '68,905.00' }, { s: 'ETH-PERP', lev: '100×', mark: '3,548.00' }, { s: 'AVAX-PERP', lev: '25×', mark: '39.05' }, { s: 'LINK-PERP', lev: '25×', mark: '19.25' }],
  OKX:      [{ s: 'BTC-PERP', lev: '100×', mark: '68,902.10' }, { s: 'ETH-PERP', lev: '75×', mark: '3,547.80' }, { s: 'ARB-PERP', lev: '20×', mark: '0.8710' }],
  Deribit:  [{ s: 'BTC-PERP', lev: '50×', mark: '68,915.00' }, { s: 'ETH-PERP', lev: '50×', mark: '3,549.00' }],
  Kraken:   [{ s: 'BTC-PERP', lev: '50×', mark: '68,908.40' }, { s: 'ETH-PERP', lev: '50×', mark: '3,548.40' }, { s: 'SOL-PERP', lev: '20×', mark: '162.08' }],
  Coinbase: [{ s: 'BTC-PERP', lev: '10×', mark: '—' }, { s: 'ETH-PERP', lev: '10×', mark: '—' }],
};

// per-venue universe: every symbol the venue lists, how many Kraite has wired
// as tradable, and of those how many are long- / short-eligible (shorts are
// fewer — borrow / locate restrictions). Green=long, red=short (never invert).
// slug + brand hex drive the exchange logo (simple-icons CDN).
const VENUE_TRADE_STATS = [
  { ex: 'Binance', slug: 'binance', color: '#F0B90B', total: 412, tradable: 168, longs: 168, shorts: 151 },
  { ex: 'Bybit',   slug: 'bybit',   color: '#F7A600', total: 386, tradable: 142, longs: 142, shorts: 128 },
  { ex: 'Bitget',  slug: 'bitget',  color: '#00CED1', total: 340, tradable: 120, longs: 120, shorts: 104 },
  { ex: 'KuCoin',  slug: 'kucoin',  color: '#24D08A', total: 312, tradable: 108, longs: 108, shorts: 92  },
];

// =================== REVENUE ===================
const REV_KPIS = [
  { icon: 'trendingUp', label: 'MRR',               value: '$412.8k', delta: 4.2,  sub: 'RECURRING · NET' },
  { icon: 'zap',        label: 'Realized rev (30d)', value: '$1.84M',  delta: 8.9,  sub: 'PERFORMANCE FEES' },
  { icon: 'wallet',     label: 'Wallet float held',  value: '$1.92M',  delta: null, sub: 'PREPAID · ALL ACCTS' },
  { icon: 'plus',       label: 'Top-ups today',      value: '$84.2k',  delta: null, sub: '12 PAYMENTS' },
];
const SUB_STATES = [
  { k: 'Active',    n: 1042, c: 'var(--pnl-up-fg)' },
  { k: 'Trial',     n: 138,  c: 'var(--info)' },
  { k: 'Paused',    n: 71,   c: 'var(--warn)' },
  { k: 'Read-only', n: 33,   c: 'var(--danger)' },
];
const PLAN_MIX = [
  { name: 'Quant',     price: '$499/mo', n: 412, share: 40 },
  { name: 'Pro',       price: '$249/mo', n: 538, share: 52 },
  { name: 'Starter',   price: '$99/mo',  n: 92,  share: 8 },
];
const PAYMENTS = [
  { who: 'Renner Capital', amt: '+12,000', coin: 'USDT', net: 'TRC-20', ago: '4m',  kind: 'topup' },
  { who: 'Halcyon Desk',   amt: '+5,000',  coin: 'USDC', net: 'ERC-20', ago: '22m', kind: 'topup' },
  { who: 'Meridian FX',    amt: '-499',    coin: 'USDT', net: 'debit',  ago: '1h',  kind: 'debit' },
  { who: 'Northwind',      amt: '+25,000', coin: 'BTC',  net: 'on-chain',ago: '2h', kind: 'topup' },
  { who: 'Bridge & Co',    amt: '-249',    coin: 'USDT', net: 'debit',  ago: '3h',  kind: 'debit' },
  { who: 'Sato Trading',   amt: '+3,000',  coin: 'USDT', net: 'TRC-20', ago: '5h',  kind: 'topup' },
  { who: 'Vantage Q',      amt: 'WELCOME25', coin: '',    net: 'coupon', ago: '6h', kind: 'coupon' },
];

// =================== SETTINGS (single config record) ===================
const REGIME_THRESHOLDS = [
  { k: 'WATCH at',      v: '0.40' },
  { k: 'ELEVATED at',   v: '0.55' },
  { k: 'CASCADE at',    v: '0.78' },
  { k: 'BLACK SWAN at', v: '0.90' },
];

Object.assign(window, {
  A_CLIENTS, BSCS_SIGNALS, ENGINE_INDICATORS, SYMBOL_CFG,
  DISPATCH_QUEUES, STEP_STREAM, DATA_STREAMS, VENUE_SYMBOLS, VENUE_TRADE_STATS,
  REV_KPIS, SUB_STATES, PLAN_MIX, PAYMENTS, REGIME_THRESHOLDS,
});
