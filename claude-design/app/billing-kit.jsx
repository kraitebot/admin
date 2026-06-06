// Kraite admin — Billing page data + primitives.
// The trader funds a PREPAID USDT wallet held by Kraite, debited once per month
// by the active plan's rate. No cards — funding is crypto top-ups via NOWPayments.
// Numbers are USDT throughout (4-dp precision, mono). Green = credit/safe,
// red = debit/loss/danger (never inverted).

// ============================ time anchor ============================
const BL_TODAY = new Date(Date.UTC(2026, 5, 6));      // Jun 6 2026 (matches system clock)
const BL_CYCLE_START = new Date(Date.UTC(2026, 5, 1)); // current cycle opened Jun 1
const BL_RENEWAL = new Date(Date.UTC(2026, 6, 1));     // renews Jul 1
const BL_CYCLE_DAYS = Math.round((BL_RENEWAL - BL_CYCLE_START) / 86400000);          // 30
const BL_DAYS_LEFT = Math.max(0, Math.round((BL_RENEWAL - BL_TODAY) / 86400000));    // 25
const BL_MON_S = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
const blDate = (d) => `${BL_MON_S[d.getUTCMonth()]} ${d.getUTCDate()}, ${d.getUTCFullYear()}`;

// ============================ formatters ============================
const usdt = (n, dp = 4) => Number(n).toLocaleString('en-US', { minimumFractionDigits: dp, maximumFractionDigits: dp });
const usdt2 = (n) => usdt(n, 2);
const usdtSigned = (n, dp = 4) => (n >= 0 ? '+' : '−') + usdt(Math.abs(n), dp);
const blCrypto = (n, dp) => Number(n).toLocaleString('en-US', { minimumFractionDigits: dp, maximumFractionDigits: dp });

// ============================ plans ============================
// Two live tiers. Both carry a 7-day free trial.
const BL_PLANS = [
  { id: 'basic',     name: 'Basic',     price: 75,  accounts: 1,        accountsLabel: '1 exchange account',
    blurb: 'Full automation on one account.',           features: ['1 exchange account', 'Full autonomous trading', 'Priority support'] },
  { id: 'unlimited', name: 'Unlimited', price: 150, accounts: Infinity, accountsLabel: 'Unlimited accounts', popular: true,
    blurb: 'Every account you connect, no caps.',       features: ['Unlimited exchange accounts', 'Full autonomous trading', 'Priority support'] },
];
const BL_PLAN = (id) => BL_PLANS.find(p => p.id === id) || BL_PLANS[0];

// ============================ top-up coins ============================
// rate = USDT per 1 unit of coin. floor = gateway per-coin minimum (USDT-equiv).
// dp = display decimals for the crypto amount.
const BL_COINS = [
  { id: 'usdt-trc', sym: 'USDT', name: 'Tether',   net: 'Tron',      tag: 'TRC-20', color: '#26a17b', rate: 1,      floor: 1.00,  dp: 2 },
  { id: 'usdt-bsc', sym: 'USDT', name: 'Tether',   net: 'BNB Chain', tag: 'BEP-20', color: '#26a17b', rate: 1,      floor: 1.50,  dp: 2 },
  { id: 'usdt-sol', sym: 'USDT', name: 'Tether',   net: 'Solana',    tag: 'SPL',    color: '#26a17b', rate: 1,      floor: 1.00,  dp: 2 },
  { id: 'usdc-bsc', sym: 'USDC', name: 'USD Coin', net: 'BNB Chain', tag: 'BEP-20', color: '#2775ca', rate: 1,      floor: 1.50,  dp: 2 },
  { id: 'usdc-sol', sym: 'USDC', name: 'USD Coin', net: 'Solana',    tag: 'SPL',    color: '#2775ca', rate: 1,      floor: 1.00,  dp: 2 },
  { id: 'btc',      sym: 'BTC',  name: 'Bitcoin',  net: 'Bitcoin',   tag: 'Native', color: '#f7931a', rate: 68910,  floor: 22.00, dp: 6 },
  { id: 'eth',      sym: 'ETH',  name: 'Ethereum', net: 'Ethereum',  tag: 'Native', color: '#627eea', rate: 3546,   floor: 38.20, dp: 5 },
  { id: 'sol',      sym: 'SOL',  name: 'Solana',   net: 'Solana',    tag: 'Native', color: '#9945ff', rate: 162.10, floor: 4.00,  dp: 4 },
  { id: 'ltc',      sym: 'LTC',  name: 'Litecoin', net: 'Litecoin',  tag: 'Native', color: '#345d9d', rate: 84.20,  floor: 3.00,  dp: 4 },
  { id: 'bnb',      sym: 'BNB',  name: 'BNB',      net: 'BNB Chain', tag: 'Native', color: '#f3ba2f', rate: 602.0,  floor: 6.00,  dp: 4 },
];
const BL_COIN = (id) => BL_COINS.find(c => c.id === id) || BL_COINS[0];

// ============================ per-state seed ============================
// The wallet/plan/renewal picture each state opens with. Local actions
// (start trial, pause, resume, switch) evolve it from here.
const BL_SEED = {
  'no-plan':     { plan: null,        wallet: 0,        trialHoursLeft: null, pausedSince: null, renewalFailed: false },
  'trial-ready': { plan: 'basic',     wallet: 0,        trialHoursLeft: null, pausedSince: null, renewalFailed: false },
  'trial':       { plan: 'basic',     wallet: 90.0000,  trialHoursLeft: 6.2,  pausedSince: null, renewalFailed: false },
  'active':      { plan: 'basic',     wallet: 164.5000, trialHoursLeft: null, pausedSince: null, renewalFailed: false },
  'paused':      { plan: 'basic',     wallet: 164.5000, trialHoursLeft: null, pausedSince: 'Jun 3, 2026', renewalFailed: false },
  'read-only':   { plan: 'unlimited', wallet: 12.4000,  trialHoursLeft: null, pausedSince: null, renewalFailed: true },
};
const BL_STATES = ['active', 'trial', 'paused', 'read-only', 'trial-ready', 'no-plan'];

// ============================ ledger ============================
// Hand-authored realistic wallet movements (newest first). Running balance is
// computed in the table from the live wallet. types: debit-sub · credit-topup ·
// credit-bonus · credit-refund · credit-admin · debit-admin.
const BL_LEDGER = [
  { date: 'Jun 5',  type: 'credit-topup',  desc: 'Top-up · USDT (Tron · TRC-20)',         amount: 75.0000 },
  { date: 'Jun 1',  type: 'debit-sub',     desc: 'Subscription · Basic — Jun cycle',      amount: -75.0000 },
  { date: 'May 28', type: 'credit-bonus',  desc: 'Top-up bonus · +5% over 100 USDT',      amount: 5.0000 },
  { date: 'May 28', type: 'credit-topup',  desc: 'Top-up · ETH (Ethereum)',               amount: 60.0000 },
  { date: 'May 22', type: 'credit-refund', desc: 'Prorate refund · Unlimited → Basic',     amount: 12.5000 },
  { date: 'May 22', type: 'debit-sub',     desc: 'Subscription · Basic — mid-cycle start', amount: -50.0000 },
  { date: 'May 9',  type: 'credit-topup',  desc: 'Top-up · USDT (BNB Chain · BEP-20)',    amount: 20.0000 },
  { date: 'Apr 26', type: 'credit-topup',  desc: 'Top-up · BTC (Bitcoin)',                amount: 30.0000 },
  { date: 'Apr 18', type: 'debit-admin',   desc: 'Admin debit · duplicate credit reversal', amount: -8.0000 },
  { date: 'Apr 12', type: 'credit-bonus',  desc: 'Top-up bonus · first deposit',          amount: 4.0000 },
  { date: 'Apr 12', type: 'credit-topup',  desc: 'Top-up · SOL (Solana)',                 amount: 36.0000 },
  { date: 'Mar 27', type: 'credit-admin',  desc: 'Admin credit · referral reward',        amount: 15.0000 },
  { date: 'Mar 19', type: 'credit-topup',  desc: 'Top-up · USDC (BNB Chain · BEP-20)',    amount: 40.0000 },
];

const BL_LTYPE = {
  'debit-sub':     { label: 'Subscription',  icon: 'refresh',       credit: false },
  'credit-topup':  { label: 'Top-up',        icon: 'arrowDownLeft', credit: true },
  'credit-bonus':  { label: 'Bonus',         icon: 'gift',          credit: true },
  'credit-refund': { label: 'Prorate refund',icon: 'refresh',       credit: true },
  'credit-admin':  { label: 'Admin credit',  icon: 'shield',        credit: true },
  'debit-admin':   { label: 'Admin debit',   icon: 'shield',        credit: false },
};

// ============================ billing terms (fine print) ============================
const BL_TERMS = [
  { icon: 'refresh', title: 'Monthly prepaid model',
    body: 'Your plan rate is debited from the prepaid USDT wallet once per month on the renewal date. There are no cards and no recurring card charges — you fund the wallet ahead of time and the engine draws from it.' },
  { icon: 'clock', title: '7-day free trial',
    body: 'Every plan starts with a 7-day free trial. The wallet is never debited during the trial and switching plans mid-trial is free and instant. The first renewal — and first debit — lands when the trial ends.' },
  { icon: 'coins', title: 'Gateway & network fees',
    body: 'Top-ups are processed by NOWPayments, which takes roughly 0.5% of the transacted amount. You also pay the network gas for the chain you send on. Only the amount that settles on-chain credits your wallet.' },
  { icon: 'gauge', title: 'Conversion spread',
    body: 'Paying in a non-USDT coin (BTC, ETH, SOL, LTC, BNB) converts to USDT at the gateway rate at confirmation time. That rate carries a small spread and moves with the market, so the credited USDT can differ slightly from the quoted estimate.' },
  { icon: 'lock', title: 'Read-only mode',
    body: 'If the wallet can\'t cover a renewal, the account drops to read-only: the bot stops opening new positions, but existing positions keep closing at their take-profit or stop-loss. Top up to clear the shortfall and the renewal retries immediately.' },
];

// ============================ shared class strings ============================
const BL_EYEBROW = "font-mono text-[10px] font-semibold tracking-[0.11em] uppercase text-fg-mute";
const BL_BIG_NUM = "font-mono font-semibold tabular-nums tracking-[-0.02em] text-fg-1 leading-none";

// ============================ small components ============================

// Coin glyph — colored rounded square with the ticker. Self-contained (no
// network coin-logo dependency), legible, on-brand mono.
const CoinGlyph = ({ coin, size = 30 }) => (
  <span className="rounded-[8px] flex items-center justify-center flex-shrink-0 font-mono font-bold tracking-[0.01em]"
    style={{ width: size, height: size, fontSize: size * 0.32, background: `color-mix(in srgb, ${coin.color} 22%, transparent)`, color: coin.color, boxShadow: `inset 0 0 0 1px color-mix(in srgb, ${coin.color} 45%, transparent)` }}>
    {coin.sym.slice(0, 3)}
  </span>
);

// Ledger type badge — credit green / debit red (directional rule).
const LedgerBadge = ({ type }) => {
  const m = BL_LTYPE[type];
  const c = m.credit ? 'var(--pnl-up-fg)' : 'var(--pnl-down-fg)';
  return (
    <span className="inline-flex items-center gap-1.5 py-[3px] pl-1.5 pr-2.5 rounded-chip font-mono text-[10px] font-bold tracking-[0.05em] uppercase whitespace-nowrap"
      style={{ color: c, background: `color-mix(in srgb, ${c} 12%, transparent)` }}>
      <UIcon name={m.icon} size={11} style={{ width: 11, height: 11 }}/>{m.label}
    </span>
  );
};

// State banner — toned strip (info / warn / danger / accent) with icon, copy, action.
const BL_BANNER_TONE = {
  info:   { c: 'var(--info)' },
  accent: { c: 'var(--accent)' },
  warn:   { c: 'var(--warn)' },
  danger: { c: 'var(--danger)' },
};
const BillBanner = ({ tone = 'info', icon, title, children, action, pulse }) => {
  const c = BL_BANNER_TONE[tone].c;
  return (
    <div className="rounded-surface border px-5 py-4 mb-6 flex items-center gap-4 max-[760px]:flex-col max-[760px]:items-start"
      style={{ borderColor: `color-mix(in srgb, ${c} 42%, transparent)`, background: `color-mix(in srgb, ${c} 9%, transparent)` }}>
      <span className={"flex-shrink-0 flex" + (pulse ? " animate-pulse-soft" : "")} style={{ color: c }}><UIcon name={icon} size={22}/></span>
      <div className="flex-1 min-w-0">
        <div className="font-sans font-semibold text-[14.5px] text-fg-1 leading-tight">{title}</div>
        <div className="text-[12.5px] text-fg-3 mt-1 leading-snug">{children}</div>
      </div>
      {action && <div className="flex-shrink-0 max-[760px]:w-full">{action}</div>}
    </div>
  );
};

Object.assign(window, {
  BL_TODAY, BL_CYCLE_START, BL_RENEWAL, BL_CYCLE_DAYS, BL_DAYS_LEFT, blDate,
  usdt, usdt2, usdtSigned, blCrypto,
  BL_PLANS, BL_PLAN, BL_COINS, BL_COIN, BL_SEED, BL_STATES,
  BL_LEDGER, BL_LTYPE, BL_TERMS, BL_EYEBROW, BL_BIG_NUM,
  CoinGlyph, LedgerBadge, BillBanner,
});
