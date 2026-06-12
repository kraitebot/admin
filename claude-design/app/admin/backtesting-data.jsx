// Kraite SYSADMIN console — Backtesting console data.
// One token in focus at a time. The four AJAX ops (Fetch / Verify / Run / AI)
// are mocked here as canned, deterministic payloads so the workspace feels live.
// Trading semantics: LONG/profit/safe = green, SHORT/loss/risk = red. Teal is a
// neutral "recovered" accent (reboundable / grade B), never a P&L color.

const BT_TEAL = '#15b8a6';

// ---- account defaults (live account) ----
const BT_DEFAULTS = { tp_percent: '0.38', sl_percent: '5.00' };

// ---- timeframes, short → long ----
const BT_TIMEFRAMES = ['5m', '15m', '1h', '4h', '1d'];

// ---- token universe (grouped by quote currency; USDT/USDC first) ----
// Each token pre-fills the config form on select.
const BT_SYMBOLS = [
  { id: 1,  token: 'BTC',   quote: 'USDT', exchange: 'Binance', rank: 1,   cat: 'Layer 1',         gapL: '0.60', gapS: '0.60', orders: 4, mult: '2,2,2,2', status: 'approved' },
  { id: 2,  token: 'ETH',   quote: 'USDT', exchange: 'Binance', rank: 2,   cat: 'Smart Contracts', gapL: '0.60', gapS: '0.60', orders: 4, mult: '2,2,2,2', status: 'approved' },
  { id: 3,  token: 'SOL',   quote: 'USDT', exchange: 'Binance', rank: 5,   cat: 'Layer 1',         gapL: '0.80', gapS: '0.80', orders: 4, mult: '2,2,2,2', status: 'approved' },
  { id: 4,  token: 'XRP',   quote: 'USDT', exchange: 'Bybit',   rank: 7,   cat: 'Payments',        gapL: '0.70', gapS: '0.70', orders: 4, mult: '2,2,2,2', status: null },
  { id: 5,  token: 'DOGE',  quote: 'USDT', exchange: 'Binance', rank: 9,   cat: 'Memes',           gapL: '0.90', gapS: '0.90', orders: 4, mult: '2,2,2,2', status: 'rejected' },
  { id: 6,  token: 'AVAX',  quote: 'USDT', exchange: 'Bybit',   rank: 12,  cat: 'Layer 1',         gapL: '0.80', gapS: '0.80', orders: 3, mult: '2,2,2',   status: null },
  { id: 7,  token: 'LINK',  quote: 'USDT', exchange: 'Binance', rank: 14,  cat: 'Oracles',         gapL: '0.70', gapS: '0.70', orders: 4, mult: '2,2,2,2', status: 'approved' },
  { id: 8,  token: 'ARB',   quote: 'USDT', exchange: 'Bitget',  rank: 38,  cat: 'Layer 2',         gapL: '0.90', gapS: '0.90', orders: 4, mult: '2,2,2,2', status: null },
  { id: 9,  token: 'SUI',   quote: 'USDT', exchange: 'KuCoin',  rank: 22,  cat: 'Layer 1',         gapL: '0.85', gapS: '0.85', orders: 4, mult: '2,2,2,2', status: null },
  { id: 10, token: 'TIA',   quote: 'USDT', exchange: 'Bitget',  rank: 71,  cat: 'Modular',         gapL: '1.10', gapS: '1.10', orders: 4, mult: '2,2,2,2', status: 'rejected' },
  { id: 11, token: 'BTC',   quote: 'USDC', exchange: 'Binance', rank: 1,   cat: 'Layer 1',         gapL: '0.60', gapS: '0.60', orders: 4, mult: '2,2,2,2', status: 'approved' },
  { id: 12, token: 'ETH',   quote: 'USDC', exchange: 'Bybit',   rank: 2,   cat: 'Smart Contracts', gapL: '0.60', gapS: '0.60', orders: 4, mult: '2,2,2,2', status: null },
  { id: 13, token: 'SOL',   quote: 'USDC', exchange: 'KuCoin',  rank: 5,   cat: 'Layer 1',         gapL: '0.80', gapS: '0.80', orders: 4, mult: '2,2,2,2', status: null },
  { id: 14, token: 'OP',    quote: 'USDC', exchange: 'Bitget',  rank: 45,  cat: 'Layer 2',         gapL: '0.95', gapS: '0.95', orders: 4, mult: '2,2,2,2', status: null },
  { id: 15, token: 'PEPE',  quote: 'USDT', exchange: 'Bybit',   rank: 24,  cat: 'Memes',           gapL: '1.40', gapS: '1.40', orders: 4, mult: '2,2,2,2', status: null },
  { id: 16, token: 'WIF',   quote: 'USDT', exchange: 'KuCoin',  rank: 58,  cat: 'Memes',           gapL: '1.50', gapS: '1.50', orders: 4, mult: '2,2,2,2', status: null },
  { id: 17, token: 'INJ',   quote: 'USDT', exchange: 'Bitget',  rank: 31,  cat: 'DeFi',            gapL: '1.00', gapS: '1.00', orders: 4, mult: '2,2,2,2', status: null },
  { id: 18, token: 'NEAR',  quote: 'BTC',  exchange: 'Binance', rank: 18,  cat: 'Layer 1',         gapL: '0.85', gapS: '0.85', orders: 4, mult: '2,2,2,2', status: null },
];

// ---- coverage audit (returned by Verify and after Fetch) ----
const BT_COVERAGE = {
  earliest: '2021-07-01 00:00',
  latest:   '2026-06-12 09:55',
  candles:  516_240,
  holes:    3,
  contiguity: 99.94,
};

// ---- fetch report (tiered download breakdown) ----
const BT_FETCH = {
  message: 'Pulled 1,284 new candles for BTC·USDT 5m — coverage now complete back to 2021-07-01.',
  tiers: [
    { tier: 'Vision',      icon: 'database', state: 'ok',   text: '2 new + 57 already-covered months', sub: '+1,180 candles' },
    { tier: 'Binance REST',icon: 'exchange', state: 'ok',   text: '92 candles forward · 12 gap-fill across 3 gaps', sub: 'caught up to head' },
    { tier: 'TAAPI',       icon: 'zap',      state: 'ok',   text: '12 candles topped up', sub: 'latest 2026-06-12 09:55' },
  ],
};

// ---- verdict breakdown (the four outcome classes) ----
const BT_VERDICT = [
  { key: 'tp_market_only', label: 'TP off market leg', n: 198, color: 'var(--pnl-up-fg)' },
  { key: 'reboundable',    label: 'Reboundable (WAP)', n: 121, color: BT_TEAL },
  { key: 'stopped_out',    label: 'Stopped out',       n: 58,  color: 'var(--pnl-down-fg)' },
  { key: 'inconclusive',   label: 'Inconclusive',      n: 35,  color: 'var(--fg-mute)', striped: true },
];

// ---- rung distribution (how deep the ladder filled) ----
const BT_RUNGS = [
  { rung: 1, n: 198 },
  { rung: 2, n: 96 },
  { rung: 3, n: 71 },
  { rung: 4, n: 47 },
];

// ---- headline totals ----
const BT_TOTALS = {
  grade: 'B',
  verdict: 'Good — minor concerns',
  overall_score: 78.4,
  risk_score: 31.2,
  pass_rate: 84.6,       // (tp + reboundable) / resolved
  max_mae_pct: 14.8,
  avg_rung_depth: 2.3,
  avg_candles_profit: 41,
  p95_candles_profit: 168,
  sample_size: 412,
  sample_size_threshold: 180,
  rows_truncated: true,
  max_rows: 500,
};

// ---- regime stability band (time buckets) ----
const BT_REGIMES = [
  { from: 'Jul 21', to: 'Dec 21', pass: 0.91, stops: 2,  candles: 52040 },
  { from: 'Jan 22', to: 'Jun 22', pass: 0.74, stops: 9,  candles: 51980 },
  { from: 'Jul 22', to: 'Dec 22', pass: 0.42, stops: 21, candles: 52120 },  // worst — bear capitulation
  { from: 'Jan 23', to: 'Jun 23', pass: 0.83, stops: 6,  candles: 51890 },
  { from: 'Jul 23', to: 'Dec 23', pass: 0.88, stops: 4,  candles: 52010 },
  { from: 'Jan 24', to: 'Jun 24', pass: 0.79, stops: 8,  candles: 52240 },
  { from: 'Jul 24', to: 'Dec 24', pass: 0.86, stops: 5,  candles: 51760 },
  { from: 'Jan 25', to: 'Jun 25', pass: 0.81, stops: 7,  candles: 52180 },
  { from: 'Jul 25', to: 'Dec 25', pass: 0.90, stops: 3,  candles: 52000 },
  { from: 'Jan 26', to: 'Jun 26', pass: 0.85, stops: 5,  candles: 48020 },
];

// ---- per-simulation rows ----
const BT_ROWS = [
  { dir: 'LONG',  start: '2021-09-07 04:15', entry: '52,914.0', rung: 1, touch: '2021-09-07 12:40', tp: '53,115.1', mae: 2.1,  status: 'tp_market_only' },
  { dir: 'SHORT', start: '2021-11-10 18:00', entry: '67,210.5', rung: 3, touch: '2021-11-12 02:25', tp: '64,980.2', mae: 8.4,  status: 'reboundable' },
  { dir: 'LONG',  start: '2022-01-21 09:30', entry: '38,420.0', rung: 4, touch: '2022-01-24 14:05', tp: '—',        mae: 14.8, status: 'stopped_out' },
  { dir: 'LONG',  start: '2022-03-15 11:45', entry: '39,640.0', rung: 2, touch: '2022-03-16 07:10', tp: '39,790.4', mae: 4.9,  status: 'reboundable' },
  { dir: 'SHORT', start: '2022-06-13 00:00', entry: '26,580.0', rung: 4, touch: '2022-06-18 19:35', tp: '—',        mae: 13.2, status: 'stopped_out' },
  { dir: 'LONG',  start: '2022-11-09 16:20', entry: '17,602.0', rung: 1, touch: '2022-11-09 23:55', tp: '17,668.9', mae: 1.4,  status: 'tp_market_only' },
  { dir: 'SHORT', start: '2023-03-10 08:00', entry: '20,180.0', rung: 2, touch: '2023-03-11 04:30', tp: '20,103.2', mae: 5.6,  status: 'reboundable' },
  { dir: 'LONG',  start: '2023-07-13 13:10', entry: '30,410.0', rung: 1, touch: '2023-07-13 19:45', tp: '30,525.6', mae: 1.9,  status: 'tp_market_only' },
  { dir: 'LONG',  start: '2024-01-11 05:00', entry: '46,920.0', rung: 3, touch: '2024-01-13 10:20', tp: '46,210.1', mae: 9.7,  status: 'reboundable' },
  { dir: 'SHORT', start: '2024-03-14 21:40', entry: '73,100.0', rung: 1, touch: '2024-03-15 02:15', tp: '72,820.4', mae: 1.1,  status: 'tp_market_only' },
  { dir: 'LONG',  start: '2024-08-05 02:30', entry: '52,340.0', rung: 4, touch: '2024-08-09 17:50', tp: '—',        mae: 12.6, status: 'stopped_out' },
  { dir: 'SHORT', start: '2025-02-25 14:00', entry: '88,420.0', rung: 2, touch: '2025-02-26 09:05', tp: '88,010.7', mae: 4.3,  status: 'reboundable' },
  { dir: 'LONG',  start: '2026-04-02 06:45', entry: '96,710.0', rung: 1, touch: '2026-04-02 13:30', tp: '96,930.2', mae: 1.7,  status: 'tp_market_only' },
  { dir: 'LONG',  start: '2026-06-09 22:10', entry: '101,240.0',rung: 1, touch: '—',                 tp: '—',        mae: 0.8,  status: 'inconclusive' },
];

// ---- run meta (echo of the config that produced the result) ----
const BT_META = {
  tp: '0.38', sl: '5.00', gapL: '0.60', gapS: '0.60',
  leverage: '20×', mult: '[2,2,2,2]', window: 'all history · 5m',
};

// ---- AI insights (markdown returned by the model) ----
const BT_AI_MODEL = 'claude-sonnet-4.5';
const BT_AI_MARKDOWN = `## Diagnosis

The config is **structurally sound** — an 84.6% pass rate over 412 resolved sims with most wins closing straight off the market leg (\`tp_market_only\`). The ladder rarely needs to average down: average rung depth is just **2.3 of 4**, and only 47 sims ever reached rung 4.

The single concern is **regime fragility**. Pass rate collapses to **42%** in the Jul–Dec 2022 bucket (bear capitulation), where 21 of the run's 58 stop-outs cluster. Max MAE of **14.8%** also lands in that window — close to the liquidation envelope at 20× leverage. Outside that period the config is consistently above 79%.

## Suggestions

1. **Widen \`sl_percent\` to 6.0%** *(single-variable test)* — the deepest stop-outs in 2022 breached 14% MAE; a wider stop would have let several reboundable ladders recover without changing behaviour in calm regimes.
2. **Add a regime gate on the 2022-type bucket** — pause new entries when realized volatility exceeds the ELEVATED threshold; this removes the worst cluster without touching the other 9 buckets.
3. **Tighten \`gap_short\` to 0.50%** — shorts carried slightly higher MAE than longs; a tighter rung gap fills the ladder sooner and reduces adverse excursion.

### Tunable levers
- \`sl_percent\` — primary lever for the 2022 fragility
- \`gap_long\` / \`gap_short\` — ladder fill cadence
- \`limit_hit\` filter — isolate deep-ladder behaviour for review

_Advisory only — applies no changes to the live config._`;

Object.assign(window, {
  BT_TEAL, BT_DEFAULTS, BT_TIMEFRAMES, BT_SYMBOLS, BT_COVERAGE, BT_FETCH,
  BT_VERDICT, BT_RUNGS, BT_TOTALS, BT_REGIMES, BT_ROWS, BT_META,
  BT_AI_MODEL, BT_AI_MARKDOWN,
});
