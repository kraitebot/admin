// Kraite admin — Projections page.
// A monthly REVENUE CALENDAR. Past days show realized trading revenue (actual
// closed-position P&L — strict green/red). Today is the anchor between reality
// and forecast. Future days show PROJECTED revenue: today's wallet compounded
// forward day-by-day at an observed daily rate, under one of three scenarios.
//
// Hard rules honored:
//  · green/red are RESERVED for realized profit/loss. Projected cells/totals
//    carry the scenario TONE (pess=red, neutral=info-blue, opt=green) but
//    desaturated + PROJ-tagged so they can never be mistaken for real money.
//  · numbers are the hero — mono, tabular, compact (K/M/B/T → scientific) so
//    far-future compounding stays scannable.

// ============================ constants ============================
const PJ_TODAY = new Date(Date.UTC(2026, 5, 5));          // Jun 5 2026
const PJ_CUR_Y = 2026, PJ_CUR_M = 5;                       // June (0-indexed)
const PJ_MON   = ['January','February','March','April','May','June','July','August','September','October','November','December'];
const PJ_MON_S = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
const PJ_WD    = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];   // ISO, Monday-start
const PJ_HISTORY_MONTHS = 14;     // realized history extends this far back
const PJ_FUTURE_MONTHS  = 72;     // ~6 years forward
const PJ_NEUTRAL_M      = 0.16;   // assumed monthly growth used to back-date past-month start balances
const PJ_ABS_CUR = PJ_CUR_Y * 12 + PJ_CUR_M;
const PJ_ABS_MIN = PJ_ABS_CUR - PJ_HISTORY_MONTHS;
const PJ_ABS_MAX = PJ_ABS_CUR + PJ_FUTURE_MONTHS;

// scenario tone — chrome only (active segment, projected totals, light cell tint)
const PJ_TONE = {
  pess:    { key: 'pess',    label: 'Pessimistic', css: 'var(--pnl-down-fg)', activeText: '#fff' },
  neutral: { key: 'neutral', label: 'Neutral',     css: 'var(--info)',        activeText: '#fff' },
  opt:     { key: 'opt',     label: 'Optimistic',  css: 'var(--pnl-up-fg)',   activeText: '#04140d' },
};

// ============================ rng ============================
const pjSeed = (s) => { let h = 2166136261 >>> 0; for (let i = 0; i < s.length; i++) { h ^= s.charCodeAt(i); h = Math.imul(h, 16777619); } return h >>> 0; };
const pjRng  = (a) => { a = a >>> 0; return () => { a = (a + 0x6D2B79F5) >>> 0; let t = a; t = Math.imul(t ^ (t >>> 15), 1 | t); t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t; return ((t ^ (t >>> 14)) >>> 0) / 4294967296; }; };

// ============================ accounts ============================
const pjAcctNum = (s) => parseFloat(String(s).replace(/[^0-9.]/g, '')) || 0;
const PJ_ACCTS = ACCOUNTS.map(a => ({
  ex: a.ex, tag: a.tag, mono: a.mono, state: a.state, equityStr: a.equity, note: a.note,
  wallet: pjAcctNum(a.equity),
  seed: pjSeed(a.ex + '|' + a.tag),
}));

// ============================ formatters ============================
const pjAbs = (a) => {                                       // compact, no sign
  if (!isFinite(a)) return '$∞';
  if (a < 1000) return '$' + Math.round(a).toLocaleString('en-US');
  if (a >= 1e15) return '$' + a.toExponential(2).replace('e+', 'e');
  for (const [u, v] of [['T', 1e12], ['B', 1e9], ['M', 1e6], ['K', 1e3]]) if (a >= v) return '$' + (a / v).toFixed(2) + u;
  return '$' + Math.round(a).toLocaleString('en-US');
};
const pjAbsFull = (a) => {                                   // full integers up to 10M, then compact
  if (!isFinite(a)) return '$∞';
  if (a < 1e7) return '$' + Math.round(a).toLocaleString('en-US');
  if (a >= 1e15) return '$' + a.toExponential(2).replace('e+', 'e');
  for (const [u, v] of [['T', 1e12], ['B', 1e9], ['M', 1e6]]) if (a >= v) return '$' + (a / v).toFixed(2) + u;
  return '$' + Math.round(a).toLocaleString('en-US');
};
const pjSigned     = (n) => (n >= 0 ? '+' : '−') + pjAbs(Math.abs(n));
const pjSignedFull = (n) => (n >= 0 ? '+' : '−') + pjAbsFull(Math.abs(n));
const pjFull       = (n) => (n < 0 ? '−' : '') + pjAbsFull(Math.abs(n));
const pjPct        = (n, d = 2) => (n >= 0 ? '+' : '−') + Math.abs(n).toFixed(d) + '%';

// ============================ model ============================
const pjDim = (y, m) => new Date(Date.UTC(y, m + 1, 0)).getUTCDate();
const pjIdx = (y, m, d) => Math.round((Date.UTC(y, m, d) - PJ_TODAY.getTime()) / 86400000);  // 0 = today
const pjMonthType = (y, m) => (y === PJ_CUR_Y && m === PJ_CUR_M) ? 'current'
  : (y > PJ_CUR_Y || (y === PJ_CUR_Y && m > PJ_CUR_M)) ? 'future' : 'past';

// realized daily revenue for a month (reconciled so the current month's
// today-balance equals the account wallet exactly).
const pjRealized = (acct, year, month) => {
  const dim = pjDim(year, month);
  const isCur = (year === PJ_CUR_Y && month === PJ_CUR_M);
  const upto = isCur ? PJ_TODAY.getUTCDate() : dim;
  const rng = pjRng(acct.seed ^ Math.imul(((year * 16 + month) >>> 0), 2654435761));
  const raw = [];
  for (let d = 1; d <= upto; d++) {
    if (rng() < 0.17) { raw.push({ has: false, r: 0 }); continue; }   // no closes that day
    let r = 0.0055 + (rng() * 2 - 1) * 0.016;                          // ~ −1.0% … +2.1%
    if (rng() < 0.10) r = -(0.018 + rng() * 0.020);                    // bad day  −1.8% … −3.8%
    else if (rng() < 0.12) r = 0.026 + rng() * 0.012;                  // big day  +2.6% … +3.8%
    raw.push({ has: true, r });
  }
  let target;
  if (isCur) target = acct.wallet;
  else target = acct.wallet / Math.pow(1 + PJ_NEUTRAL_M, (PJ_CUR_Y - year) * 12 + (PJ_CUR_M - month));
  const cumF = raw.reduce((f, x) => f * (1 + (x.has ? x.r : 0)), 1);
  const startedAt = target / cumF;
  let bal = startedAt;
  const days = raw.map(x => {
    const before = bal; bal = bal * (1 + (x.has ? x.r : 0));
    return { has: x.has, revenue: x.has ? bal - before : null };
  });
  return { startedAt, days, endBal: bal, upto, dim };
};

// scenario daily rates from the CURRENT month's observed days (per account)
const pjScenarioRates = (acct) => {
  const cur = pjRealized(acct, PJ_CUR_Y, PJ_CUR_M);
  // observed daily % = revenue / balance-before, across days that had closes
  const rates = [];
  let bal = cur.startedAt;
  cur.days.forEach(x => { const before = bal; bal = before + (x.revenue || 0); if (x.has) rates.push((x.revenue) / before); });
  rates.sort((a, b) => a - b);
  const n = rates.length;
  const worst = n ? rates[0] : -0.012;
  const best = n ? rates[n - 1] : 0.018;
  return {
    pess: worst,
    neutral: (worst + best) / 2,          // midpoint of the observed range (robust for small samples)
    opt: best,
    n, wallet: acct.wallet, startedAt: cur.startedAt, realizedSoFar: acct.wallet - cur.startedAt,
  };
};

const pjBuildMonth = (acct, year, month, scenario) => {
  const type = pjMonthType(year, month);
  const dim = pjDim(year, month);
  const rates = pjScenarioRates(acct);
  const rate = rates[scenario];
  const S0 = rates.wallet;
  const cells = [];
  let startedAt, realized = null, projected = null, endBal;

  if (type === 'past') {
    const ser = pjRealized(acct, year, month);
    for (let d = 1; d <= dim; d++) {
      const x = ser.days[d - 1];
      cells.push({ day: d, kind: x.has ? 'realized' : 'empty', amount: x.has ? x.revenue : null });
    }
    startedAt = ser.startedAt; realized = ser.endBal - ser.startedAt; endBal = ser.endBal;
  } else if (type === 'current') {
    const ser = pjRealized(acct, year, month);
    const todayD = PJ_TODAY.getUTCDate();
    for (let d = 1; d <= dim; d++) {
      if (d < todayD) { const x = ser.days[d - 1]; cells.push({ day: d, kind: x.has ? 'realized' : 'empty', amount: x.has ? x.revenue : null }); }
      else if (d === todayD) { const x = ser.days[d - 1]; cells.push({ day: d, kind: 'today', amount: x.has ? x.revenue : 0, todayHas: x.has }); }
      else { const k = d - todayD; cells.push({ day: d, kind: 'projected', amount: S0 * Math.pow(1 + rate, k) - S0 * Math.pow(1 + rate, k - 1) }); }
    }
    startedAt = ser.startedAt; realized = S0 - ser.startedAt;
    endBal = S0 * Math.pow(1 + rate, dim - todayD); projected = endBal - S0;
  } else {
    for (let d = 1; d <= dim; d++) {
      const k = pjIdx(year, month, d);
      cells.push({ day: d, kind: 'projected', amount: S0 * Math.pow(1 + rate, k) - S0 * Math.pow(1 + rate, k - 1) });
    }
    startedAt = S0 * Math.pow(1 + rate, pjIdx(year, month, 1) - 1);
    endBal = S0 * Math.pow(1 + rate, pjIdx(year, month, dim));
    projected = endBal - startedAt;
  }
  return {
    type, dim, cells, startedAt, realized, projected, endBal,
    monthlyPct: (endBal / startedAt - 1) * 100,
    cumFromToday: endBal - S0, rate, rates, S0,
    firstWeekday: (new Date(Date.UTC(year, month, 1)).getUTCDay() + 6) % 7,   // Mon=0
  };
};

// ============================ scenario switch (tone-aware Segmented) ============================
const PJ_SCEN = [['pess', 'Pessimistic'], ['neutral', 'Neutral'], ['opt', 'Optimistic']];
// Deterministic 3-column segmented control — highlight position AND color both
// derive straight from `value` (no measured state), so they can never desync.
const ScenarioSwitch = ({ value, onChange, disabled, rates }) => {
  const idx = Math.max(0, PJ_SCEN.findIndex(([k]) => k === value));
  const tone = PJ_TONE[value] || PJ_TONE.neutral;
  return (
    <div className={"relative inline-grid grid-cols-3 h-[44px] min-w-[330px] bg-surface-3 border border-line rounded-control transition-opacity " + (disabled ? "opacity-45 pointer-events-none" : "")}
      title={disabled ? 'Realized history — scenarios apply only to projected months' : undefined}>
      <span aria-hidden="true" className="absolute top-[3px] bottom-[3px] z-0 rounded-[7px] shadow-1 pointer-events-none transition-[left] duration-[320ms] ease-[cubic-bezier(0.16,1,0.3,1)]"
        style={{ left: `${(idx * 100 / 3).toFixed(4)}%`, marginLeft: '3px', width: 'calc(33.3333% - 6px)', background: tone.css }}/>
      {PJ_SCEN.map(([k, lbl]) => {
        const on = value === k;
        const rate = rates ? rates[k] : null;
        return (
          <button key={k} onClick={() => onChange(k)}
            className="appearance-none bg-transparent border-0 rounded-[7px] flex flex-col items-center justify-center gap-[2px] px-1 cursor-pointer relative z-[1] transition-colors duration-fast ease-out">
            <span className="font-mono text-[11px] font-semibold tracking-[0.03em] leading-none" style={{ color: on ? tone.activeText : 'var(--fg-3)' }}>{lbl}</span>
            {rate != null && <span className="font-mono text-[8.5px] tabular-nums leading-none tracking-[0.01em]" style={{ color: on ? tone.activeText : 'var(--fg-mute)', opacity: on ? 0.82 : 1 }}>{pjPct(rate * 100, 2)}/d</span>}
          </button>
        );
      })}
    </div>
  );
};

// ============================ account picker ============================
const PJ_DOT = (state) => "w-[8px] h-[8px] rounded-chip flex-shrink-0 " + (state === 'ok' ? 'bg-green-500' : state === 'down' ? 'bg-danger animate-pulse-soft' : 'bg-warn');
const PJAccountPicker = ({ accts, idx, onPick }) => {
  const [open, setOpen] = React.useState(false);
  const ref = React.useRef(null);
  React.useEffect(() => {
    if (!open) return;
    const h = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
    document.addEventListener('mousedown', h);
    return () => document.removeEventListener('mousedown', h);
  }, [open]);
  const a = accts[idx];
  return (
    <div className="relative" ref={ref}>
      <button onClick={() => setOpen(o => !o)}
        className={"inline-flex items-center gap-2.5 h-[34px] border rounded-control bg-surface pl-2 pr-3 cursor-pointer transition-colors duration-fast ease-out " + (open ? "border-accent" : "border-line hover:border-line-strong")}>
        <span className="w-[24px] h-[24px] rounded-full bg-surface-3 text-fg-2 font-mono font-bold text-[10px] flex items-center justify-center flex-shrink-0">{a.mono}</span>
        <span className="flex flex-col items-start leading-[1.15] min-w-0">
          <span className="text-[12.5px] font-semibold text-fg-1 whitespace-nowrap">{a.ex} <span className="text-fg-mute font-normal">· {a.tag}</span></span>
          <span className="font-mono text-[10px] text-fg-mute tabular-nums tracking-[0.02em] whitespace-nowrap">{a.equityStr}</span>
        </span>
        <span className={PJ_DOT(a.state)}/>
        <UIcon name="chevronDown" size={14} style={{ color: 'var(--fg-mute)' }}/>
      </button>
      {open && (
        <div className="absolute top-[calc(100%+6px)] left-0 z-[60] w-[280px] bg-surface border border-line rounded-control shadow-2 p-[5px] flex flex-col gap-px animate-dd-in">
          <div className="font-mono text-[9px] font-semibold tracking-[0.12em] uppercase text-fg-mute px-[9px] pt-1.5 pb-1">Exchange accounts · {accts.length}</div>
          {accts.map((ac, i) => (
            <button key={ac.ex + ac.tag} onClick={() => { onPick(i); setOpen(false); }}
              className={"appearance-none cursor-pointer text-left flex items-center gap-2.5 bg-transparent border-0 rounded-[7px] py-2 px-[9px] transition-colors duration-fast ease-out hover:bg-hover " + (i === idx ? "bg-hover" : "")}>
              <span className="w-[26px] h-[26px] rounded-full bg-surface-3 text-fg-2 font-mono font-bold text-[10.5px] flex items-center justify-center flex-shrink-0">{ac.mono}</span>
              <span className="flex flex-col leading-[1.2] flex-1 min-w-0">
                <span className="text-[12.5px] font-semibold text-fg-1 whitespace-nowrap">{ac.ex} <span className="text-fg-mute font-normal">· {ac.tag}</span></span>
                <span className="font-mono text-[10px] text-fg-mute tracking-[0.02em] whitespace-nowrap">{ac.note}</span>
              </span>
              <span className="flex flex-col items-end gap-1 flex-shrink-0">
                <span className="font-mono text-[11.5px] font-semibold text-fg-1 tabular-nums">{ac.equityStr}</span>
                <span className="inline-flex items-center gap-[5px] font-mono text-[8.5px] font-bold tracking-[0.08em] uppercase" style={{ color: ac.state === 'ok' ? 'var(--pnl-up-fg)' : ac.state === 'down' ? 'var(--danger)' : 'var(--warn)' }}>
                  <span className={PJ_DOT(ac.state)}/>{ac.state === 'ok' ? 'Linked' : ac.state === 'down' ? 'Down' : 'Degraded'}
                </span>
              </span>
              {i === idx && <UIcon name="check" size={15} style={{ color: 'var(--accent)', flexShrink: 0 }}/>}
            </button>
          ))}
        </div>
      )}
    </div>
  );
};

// ============================ month picker ============================
const PJ_ARROW = "appearance-none cursor-pointer w-[34px] h-[34px] inline-flex items-center justify-center rounded-control border border-line bg-surface text-fg-2 transition-colors duration-fast ease-out hover:border-line-strong hover:text-fg-1 disabled:opacity-30 disabled:cursor-not-allowed disabled:hover:border-line disabled:hover:text-fg-2";
const PJMonthPicker = ({ ym, onPick }) => {
  const [open, setOpen] = React.useState(false);
  const [pYear, setPYear] = React.useState(ym.year);
  const ref = React.useRef(null);
  React.useEffect(() => { if (open) setPYear(ym.year); }, [open, ym.year]);
  React.useEffect(() => {
    if (!open) return;
    const h = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
    document.addEventListener('mousedown', h);
    return () => document.removeEventListener('mousedown', h);
  }, [open]);
  const minYear = Math.floor(PJ_ABS_MIN / 12), maxYear = Math.floor(PJ_ABS_MAX / 12);
  const inRange = (y, m) => { const abs = y * 12 + m; return abs >= PJ_ABS_MIN && abs <= PJ_ABS_MAX; };
  return (
    <div className="relative" ref={ref}>
      <button onClick={() => setOpen(o => !o)}
        className={"inline-flex items-center justify-center gap-2 h-[34px] px-3.5 min-w-[156px] rounded-control border bg-surface font-sans font-semibold text-[13.5px] text-fg-1 cursor-pointer transition-colors duration-fast ease-out " + (open ? "border-accent" : "border-line hover:border-line-strong")}>
        {PJ_MON[ym.month]} {ym.year}
        <UIcon name="chevronDown" size={14} style={{ color: 'var(--fg-mute)' }}/>
      </button>
      {open && (
        <div className="absolute top-[calc(100%+6px)] left-1/2 -translate-x-1/2 z-[60] w-[280px] bg-surface border border-line rounded-control shadow-2 p-3 animate-dd-in">
          <div className="flex items-center justify-between mb-3">
            <button className={PJ_ARROW + " w-[28px] h-[28px]"} disabled={pYear <= minYear} onClick={() => setPYear(y => y - 1)} aria-label="Previous year"><UIcon name="chevronLeft" size={14}/></button>
            <span className="font-mono text-[14px] font-semibold text-fg-1 tabular-nums">{pYear}</span>
            <button className={PJ_ARROW + " w-[28px] h-[28px]"} disabled={pYear >= maxYear} onClick={() => setPYear(y => y + 1)} aria-label="Next year"><UIcon name="chevronRight" size={14}/></button>
          </div>
          <div className="grid grid-cols-3 gap-1.5">
            {PJ_MON_S.map((ms, m) => {
              const ok = inRange(pYear, m);
              const sel = pYear === ym.year && m === ym.month;
              const isCur = pYear === PJ_CUR_Y && m === PJ_CUR_M;
              return (
                <button key={ms} disabled={!ok} onClick={() => { onPick({ year: pYear, month: m }); setOpen(false); }}
                  className={"appearance-none relative h-[38px] rounded-[7px] font-mono text-[12px] font-semibold tracking-[0.02em] cursor-pointer border transition-colors duration-fast ease-out disabled:opacity-25 disabled:cursor-not-allowed " +
                    (sel ? "bg-accent text-accent-on border-transparent" : "bg-surface-3 text-fg-2 border-line hover:border-line-strong hover:text-fg-1")}>
                  {ms}
                  {isCur && !sel && <span className="absolute top-1.5 right-1.5 w-1 h-1 rounded-chip bg-accent"/>}
                </button>
              );
            })}
          </div>
          <div className="mt-3 pt-2.5 border-t border-line-soft font-mono text-[9.5px] text-fg-mute tracking-[0.04em] text-center">PROJECT UP TO {Math.floor(PJ_FUTURE_MONTHS / 12)} YEARS FORWARD</div>
        </div>
      )}
    </div>
  );
};

// ============================ day cell ============================
const PJDayCell = ({ c, tone }) => {
  const day = (
    <span className="font-mono text-[10.5px] tabular-nums leading-none"
      style={{ color: c.kind === 'today' ? 'var(--fg-1)' : c.kind === 'projected' ? `color-mix(in srgb, ${tone.css} 55%, var(--fg-mute))` : 'var(--fg-mute)', fontWeight: c.kind === 'today' ? 700 : 500 }}>
      {String(c.day).padStart(2, '0')}
    </span>
  );
  // ---- empty (no closes) ----
  if (c.kind === 'empty') {
    return (
      <div className="relative rounded-control border border-line-soft bg-transparent min-h-[88px] p-2.5 flex flex-col">
        {day}
        <div className="flex-1 flex flex-col items-center justify-center gap-1 -mt-1">
          <span className="font-mono text-[15px] text-fg-faint leading-none">—</span>
          <span className="font-mono text-[8.5px] tracking-[0.1em] uppercase text-fg-faint">no closes</span>
        </div>
      </div>
    );
  }
  // ---- today (anchor) ----
  if (c.kind === 'today') {
    const gain = c.amount >= 0;
    return (
      <div className="relative rounded-control min-h-[88px] p-2.5 flex flex-col"
        style={{ background: 'color-mix(in srgb, var(--fg-1) 7%, transparent)', boxShadow: 'inset 0 0 0 1.5px var(--fg-2)' }}>
        <div className="flex items-start justify-between">
          {day}
          <span className="font-mono text-[8px] font-bold tracking-[0.12em] uppercase px-1.5 py-[2px] rounded-chip" style={{ background: 'var(--fg-1)', color: 'var(--bg-elev-1)' }}>TODAY</span>
        </div>
        <div className="flex-1 flex flex-col justify-end">
          <span className={"font-mono font-semibold tabular-nums tracking-[-0.01em] leading-none " + (c.todayHas ? (gain ? 'text-pnlup' : 'text-pnldown') : 'text-fg-mute')} style={{ fontSize: 19 }}>
            {c.todayHas ? pjSigned(c.amount) : '$0'}
          </span>
          <span className="font-mono text-[8.5px] tracking-[0.08em] uppercase text-fg-mute mt-1">{c.todayHas ? 'realized so far' : 'no closes yet'}</span>
        </div>
      </div>
    );
  }
  // ---- projected ----
  if (c.kind === 'projected') {
    const s = pjSigned(c.amount);
    const fs = s.length > 11 ? 14 : s.length > 8 ? 16 : 18;
    return (
      <div className="relative rounded-control border border-line-soft min-h-[88px] p-2.5 flex flex-col"
        style={{ background: `color-mix(in srgb, ${tone.css} 7%, transparent)` }}>
        <div className="flex items-start justify-between">
          {day}
          <span className="font-mono text-[8px] font-bold tracking-[0.1em] uppercase" style={{ color: `color-mix(in srgb, ${tone.css} 70%, var(--fg-mute))` }}>PROJ</span>
        </div>
        <div className="flex-1 flex items-end">
          <span className="font-mono font-medium tabular-nums tracking-[-0.01em] leading-none" style={{ fontSize: fs, color: `color-mix(in srgb, ${tone.css} 60%, var(--fg-2))` }}>{s}</span>
        </div>
      </div>
    );
  }
  // ---- realized ----
  const gain = c.amount >= 0;
  const s = pjSigned(c.amount);
  const fs = s.length > 11 ? 14 : s.length > 8 ? 16 : 19;
  return (
    <div className="relative rounded-control border border-line-soft min-h-[88px] p-2.5 flex flex-col"
      style={{ background: `color-mix(in srgb, ${gain ? 'var(--pnl-up-fg)' : 'var(--pnl-down-fg)'} 7%, transparent)` }}>
      {day}
      <div className="flex-1 flex items-end">
        <span className={"font-mono font-semibold tabular-nums tracking-[-0.01em] leading-none " + (gain ? 'text-pnlup' : 'text-pnldown')} style={{ fontSize: fs }}>{s}</span>
      </div>
    </div>
  );
};

// ============================ calendar ============================
const PJCalendar = ({ model, tone }) => {
  const lead = model.firstWeekday;
  const total = lead + model.dim;
  const trail = (7 - total % 7) % 7;
  return (
    <div className="overflow-x-auto">
      <div className="min-w-[680px]">
        <div className="grid grid-cols-7 gap-1.5 mb-1.5">
          {PJ_WD.map((w, i) => (
            <div key={w} className={"font-mono text-[9.5px] font-semibold tracking-[0.1em] uppercase text-center py-1.5 " + (i >= 5 ? 'text-fg-faint' : 'text-fg-mute')}>{w}</div>
          ))}
        </div>
        <div className="grid grid-cols-7 gap-1.5">
          {Array.from({ length: lead }, (_, i) => <div key={'l' + i} aria-hidden="true"/>)}
          {model.cells.map(c => <PJDayCell key={c.day} c={c} tone={tone}/>)}
          {Array.from({ length: trail }, (_, i) => <div key={'t' + i} aria-hidden="true"/>)}
        </div>
      </div>
    </div>
  );
};

const PJLegend = ({ tone, type }) => {
  const item = (sw, label) => (
    <span className="inline-flex items-center gap-2 font-mono text-[10.5px] text-fg-3 whitespace-nowrap">{sw}{label}</span>
  );
  return (
    <div className="flex items-center gap-x-5 gap-y-2 flex-wrap py-3 px-5 border-t border-line-soft">
      {type !== 'future' && item(<span className="w-3.5 h-2.5 rounded-[3px]" style={{ background: 'color-mix(in srgb, var(--pnl-up-fg) 22%, transparent)', boxShadow: 'inset 0 0 0 1px color-mix(in srgb, var(--pnl-up-fg) 40%, transparent)' }}/>, 'Realized gain')}
      {type !== 'future' && item(<span className="w-3.5 h-2.5 rounded-[3px]" style={{ background: 'color-mix(in srgb, var(--pnl-down-fg) 22%, transparent)', boxShadow: 'inset 0 0 0 1px color-mix(in srgb, var(--pnl-down-fg) 40%, transparent)' }}/>, 'Realized loss')}
      {type === 'current' && item(<span className="w-3.5 h-2.5 rounded-[3px]" style={{ boxShadow: 'inset 0 0 0 1.5px var(--fg-2)' }}/>, 'Today')}
      {type !== 'past' && item(<span className="w-3.5 h-2.5 rounded-[3px]" style={{ background: `color-mix(in srgb, ${tone.css} 16%, transparent)`, boxShadow: `inset 0 0 0 1px color-mix(in srgb, ${tone.css} 35%, transparent)` }}/>, <>Projected · <span style={{ color: tone.css }}>{tone.label}</span></>)}
      {type !== 'future' && item(<span className="font-mono text-fg-faint text-[12px] leading-none w-3.5 text-center">—</span>, 'No closes')}
      <span className="flex-1"/>
      <span className="font-mono text-[10px] text-fg-mute tracking-[0.04em]">{type === 'past' ? 'REALIZED HISTORY' : type === 'future' ? 'PURE PROJECTION' : 'REALIZED → PROJECTED'}</span>
    </div>
  );
};

// ============================ totals strip ============================
const PJTotals = ({ model, tone, ym }) => {
  const t = model.type;
  const labels = t === 'past'
    ? ['Started month at', 'Realized this month', 'Projected', 'Ended at', 'Monthly return']
    : t === 'future'
      ? ['Starts month at', 'Realized', 'Projected this month', 'Expected end balance', 'Monthly return']
      : ['Started month at', 'Made so far', 'Projected · rest of month', 'Expected end balance', 'Monthly return'];

  const realizedCell = t === 'future'
    ? { value: '—', cls: 'text-fg-faint' }
    : { value: pjSignedFull(model.realized), cls: model.realized >= 0 ? 'text-pnlup' : 'text-pnldown', sub: 'REALIZED' };
  const projectedCell = t === 'past'
    ? { value: '—', cls: 'text-fg-faint' }
    : { value: pjSignedFull(model.projected), css: tone.css, sub: tone.label.toUpperCase() + ' · PROJ' };
  const monthlyCss = t === 'past' ? (model.monthlyPct >= 0 ? 'var(--pnl-up-fg)' : 'var(--pnl-down-fg)') : tone.css;
  // current month blends realized + projected — surface the split so the green-ish
  // total never reads as "all realized money".
  const realPct = (model.S0 / model.startedAt - 1) * 100;
  const projPct = (model.endBal / model.S0 - 1) * 100;
  const monthlySub = t === 'past' ? 'REALIZED'
    : t === 'current' ? `${pjPct(realPct, 1)} REAL · ${pjPct(projPct, 1)} PROJ`
    : tone.label.toUpperCase() + ' · PROJ';

  const cells = [
    { label: labels[0], value: pjFull(model.startedAt), css: 'var(--fg-1)', sub: 'OPENING BALANCE' },
    { label: labels[1], value: realizedCell.value, cls: realizedCell.cls, sub: realizedCell.sub },
    { label: labels[2], value: projectedCell.value, cls: projectedCell.cls, css: projectedCell.css, sub: projectedCell.sub },
    { label: labels[3], value: pjFull(model.endBal), css: 'var(--fg-1)', sub: t === 'past' ? 'REALIZED' : 'EXPECTED' },
    { label: labels[4], value: pjPct(model.monthlyPct), css: monthlyCss, sub: monthlySub },
  ];

  return (
    <div className="card card--flat mb-6">
      <div className="flex items-stretch max-[1000px]:flex-wrap">
        {cells.map((c, i) => (
          <div key={c.label} className={"flex-1 min-w-[164px] py-[15px] px-5 flex flex-col gap-1.5 " + (i ? 'border-l border-line-soft max-[1000px]:border-l-0 ' : '') + (i >= 2 ? 'max-[1000px]:border-t max-[1000px]:border-line-soft' : '')}>
            <span className="font-mono text-[9.5px] font-medium tracking-[0.09em] uppercase text-fg-mute whitespace-nowrap overflow-hidden text-ellipsis">{c.label}</span>
            <span className={"font-mono text-[21px] font-semibold leading-none tabular-nums tracking-[-0.02em] " + (c.cls || '')} style={c.css ? { color: c.css } : undefined}>{c.value}</span>
            <span className="font-mono text-[9px] tracking-[0.07em] text-fg-mute uppercase">{c.sub}</span>
          </div>
        ))}
      </div>
      {model.type === 'future' && (
        <div className="flex items-center gap-3 py-[13px] px-5 border-t border-line-soft" style={{ background: `color-mix(in srgb, ${tone.css} 6%, transparent)` }}>
          <UIcon name="projections" size={15} style={{ color: tone.css, flexShrink: 0 }}/>
          <span className="text-[12.5px] text-fg-2">Cumulative from <span className="font-semibold text-fg-1">today</span> → end of <span className="font-semibold text-fg-1">{PJ_MON[ym.month]} {ym.year}</span></span>
          <span className="flex-1"/>
          <span className="font-mono text-[17px] font-semibold tabular-nums tracking-[-0.01em]" style={{ color: tone.css }}>{pjSignedFull(model.cumFromToday)}</span>
          <span className="font-mono text-[9px] tracking-[0.08em] uppercase" style={{ color: `color-mix(in srgb, ${tone.css} 70%, var(--fg-mute))` }}>PROJECTED</span>
        </div>
      )}
    </div>
  );
};

// ============================ footnotes ============================
const PJFootnotes = ({ model, ym, tone }) => {
  const r = model.rates;
  const isProj = model.type !== 'past';
  return (
    <div className="mt-6 rounded-control border border-line-soft bg-surface px-5 py-4 flex flex-col gap-2.5">
      <div className="flex items-start gap-2.5">
        <UIcon name="gauge" size={14} style={{ color: 'var(--fg-mute)', flexShrink: 0, marginTop: 2 }}/>
        <p className="text-[12px] leading-[1.55] text-fg-3 m-0">
          {isProj ? (
            <>Projection compounds daily from today's wallet of <span className="font-mono text-fg-2">{pjFull(model.S0)}</span> at <span className="font-mono" style={{ color: tone.css }}>{pjPct(model.rate * 100, 2)}/day</span> — the <span style={{ color: tone.css, fontWeight: 600 }}>{tone.label.toLowerCase()}</span> daily rate, derived from <span className="font-mono text-fg-2">{r.n}</span> observed trading {r.n === 1 ? 'day' : 'days'} this month. Observed range: <span className="font-mono text-pnldown">{pjPct(r.pess * 100, 2)}</span> … <span className="font-mono text-pnlup">{pjPct(r.opt * 100, 2)}</span> per day.</>
          ) : (
            <><span className="font-mono text-fg-2">{PJ_MON[ym.month]} {ym.year}</span> is realized history — actual revenue from closed positions. No projection is applied to past months.</>
          )}
        </p>
      </div>
      <div className="flex items-start gap-2.5">
        <UIcon name="alert" size={14} style={{ color: 'var(--fg-mute)', flexShrink: 0, marginTop: 2 }}/>
        <p className="text-[12px] leading-[1.55] text-fg-mute m-0">
          Projections are <span className="font-semibold text-fg-3">illustrative only</span> and not a guarantee of future revenue. Compounding assumes a constant daily rate and reinvestment; real markets are volatile and the Black-Swan engine resizes or halts trading as risk escalates. Realized results will differ — potentially materially.
        </p>
      </div>
    </div>
  );
};

// ============================ skeleton / states ============================
const PJSkel = ({ className }) => <div className={"animate-pulse-soft bg-surface-3 rounded-control " + className}/>;
const PJLoading = () => (
  <>
    <div className="card mb-6 flex items-stretch max-[1000px]:flex-wrap">
      {[0, 1, 2, 3, 4].map(i => (
        <div key={i} className={"flex-1 min-w-[164px] py-[15px] px-5 flex flex-col gap-2.5 " + (i ? 'border-l border-line-soft max-[1000px]:border-l-0' : '')}>
          <PJSkel className="h-2.5 w-2/3"/><PJSkel className="h-5 w-4/5"/><PJSkel className="h-2 w-1/2"/>
        </div>
      ))}
    </div>
    <div className="card p-4">
      <div className="grid grid-cols-7 gap-1.5 mb-1.5">{PJ_WD.map(w => <div key={w} className="h-4"/>)}</div>
      <div className="grid grid-cols-7 gap-1.5">{Array.from({ length: 35 }, (_, i) => <PJSkel key={i} className="h-[88px]"/>)}</div>
    </div>
  </>
);

const PJEmptyState = ({ icon, title, desc, children }) => (
  <div className="card">
    <div className="flex flex-col items-center justify-center text-center py-[78px] px-5">
      <div className="w-12 h-12 rounded-control border border-line flex items-center justify-center text-fg-mute mb-4"><UIcon name={icon} size={24}/></div>
      <h4 className="font-sans font-semibold text-[19px] text-fg-1 leading-[1.2] tracking-[-0.01em] mb-1.5">{title}</h4>
      <p className="text-[13px] text-fg-3 max-w-[440px] m-0">{desc}</p>
      {children}
    </div>
  </div>
);

// ============================ page ============================
const Projections = ({ regime, score, projState = 'normal', noPositions }) => {
  const [acctIdx, setAcctIdx] = React.useState(0);
  const [scenario, setScenario] = React.useState('neutral');
  const [ym, setYm] = React.useState({ year: PJ_CUR_Y, month: PJ_CUR_M });
  const [internalLoading, setIL] = React.useState(false);
  const firstRef = React.useRef(true);

  // brief loading shimmer on account switch / month change (scenario is instant)
  React.useEffect(() => {
    if (firstRef.current) { firstRef.current = false; return; }
    if (projState !== 'normal') return;
    setIL(true);
    const t = setTimeout(() => setIL(false), 340);
    return () => clearTimeout(t);
  }, [acctIdx, ym.year, ym.month, projState]);

  const acct = PJ_ACCTS[acctIdx];
  const model = React.useMemo(() => pjBuildMonth(acct, ym.year, ym.month, scenario), [acctIdx, ym.year, ym.month, scenario]);
  const tone = PJ_TONE[scenario];
  // 'no-closes' review state: demonstrate the empty-day treatment by blanking a
  // few realized cells in the current month (display-only — totals unaffected).
  const dispModel = projState === 'no-closes'
    ? { ...model, cells: model.cells.map(c => (c.kind === 'realized' && (c.day === 2 || c.day === 3 || c.day === 9)) ? { ...c, kind: 'empty', amount: null } : c) }
    : model;
  const absCur = ym.year * 12 + ym.month;
  const isCurrentMonth = absCur === PJ_ABS_CUR;
  const shift = (n) => setYm(prev => { const abs = Math.min(PJ_ABS_MAX, Math.max(PJ_ABS_MIN, prev.year * 12 + prev.month + n)); return { year: Math.floor(abs / 12), month: abs % 12 }; });

  const loading = projState === 'loading' || internalLoading;
  const view = projState === 'no-account' ? 'no-account' : projState === 'zero-data' ? 'zero-data' : loading ? 'loading' : 'normal';

  const header = (
    <div className={PAGEHEAD}>
      <div>
        <div className={PH_EYEBROW}><UIcon name="projections" size={13} style={{ width: 13, height: 13 }}/>PERFORMANCE</div>
        <h1 className={PH_H1}>Projections</h1>
        <div className={PH_SUB}>Realized revenue and forward projection — where the book is heading from how the engine has actually performed.</div>
      </div>
      <div className="flex items-center gap-3 flex-shrink-0 max-[820px]:flex-wrap max-[820px]:gap-y-2.5">
        <RegimePill regime={regime} score={score} pulse={regime === 'CASCADE' || regime === 'BLACK SWAN'}/>
        <div className="w-px h-[22px] bg-line"/>
        <button className={BTN_SECONDARY}><UIcon name="refresh" size={15}/>Sync</button>
      </div>
    </div>
  );

  if (noPositions) {
    return (
      <>
        {header}
        <PJEmptyState icon="projections" title="Nothing to project yet" desc="Projections are built from realized trading revenue. Once the engine opens and closes its first positions, this account's revenue calendar and forward projection appear here.">
          <span className="mt-5 inline-flex items-center gap-[7px] font-mono text-[10.5px] font-medium tracking-[0.08em] uppercase text-fg-mute">
            <span className="w-1.5 h-1.5 rounded-chip bg-green-500"/>Engine running · scanning for entries
          </span>
        </PJEmptyState>
      </>
    );
  }

  if (view === 'no-account') {
    return (
      <>
        {header}
        <PJEmptyState icon="wallet" title="No account selected" desc="Projections run per exchange account. Choose an account to view its realized revenue and forward projection.">
          <div className="mt-5 flex flex-col gap-1.5 w-full max-w-[340px]">
            {PJ_ACCTS.map((ac, i) => (
              <button key={ac.ex + ac.tag} onClick={() => setAcctIdx(i)}
                className="appearance-none cursor-pointer text-left flex items-center gap-2.5 bg-surface border border-line rounded-control py-2.5 px-3 transition-colors duration-fast ease-out hover:border-line-strong">
                <span className="w-[26px] h-[26px] rounded-full bg-surface-3 text-fg-2 font-mono font-bold text-[10.5px] flex items-center justify-center flex-shrink-0">{ac.mono}</span>
                <span className="flex flex-col leading-[1.2] flex-1 min-w-0">
                  <span className="text-[12.5px] font-semibold text-fg-1 text-left">{ac.ex} · {ac.tag}</span>
                  <span className="font-mono text-[10px] text-fg-mute">{ac.note}</span>
                </span>
                <span className="font-mono text-[11.5px] font-semibold text-fg-1 tabular-nums">{ac.equityStr}</span>
                <span className={PJ_DOT(ac.state)}/>
              </button>
            ))}
          </div>
        </PJEmptyState>
      </>
    );
  }

  const controlRow = (
    <div className="flex items-center justify-between gap-4 mb-5 flex-wrap">
      <div className="flex items-center gap-3 flex-wrap">
        <PJAccountPicker accts={PJ_ACCTS} idx={acctIdx} onPick={setAcctIdx}/>
        <div className="w-px h-[26px] bg-line max-[640px]:hidden"/>
        <div className="flex items-center gap-1.5">
          <button className={PJ_ARROW} disabled={absCur <= PJ_ABS_MIN} onClick={() => shift(-1)} aria-label="Previous month"><UIcon name="chevronLeft" size={16}/></button>
          <PJMonthPicker ym={ym} onPick={setYm}/>
          <button className={PJ_ARROW} disabled={absCur >= PJ_ABS_MAX} onClick={() => shift(1)} aria-label="Next month"><UIcon name="chevronRight" size={16}/></button>
        </div>
        {!isCurrentMonth && (
          <button onClick={() => setYm({ year: PJ_CUR_Y, month: PJ_CUR_M })}
            className={BTN_SECONDARY}><UIcon name="clock" size={14}/>Today</button>
        )}
      </div>
      <ScenarioSwitch value={scenario} onChange={setScenario} disabled={model.type === 'past'} rates={model.rates}/>
    </div>
  );

  return (
    <>
      {header}
      {controlRow}
      {view === 'loading' ? <PJLoading/>
        : view === 'zero-data' ? (
          <>
            <div className="card mb-6 flex items-stretch max-[1000px]:flex-wrap">
              {['Started month at', 'Made so far', 'Projected', 'Expected end balance', 'Monthly return'].map((l, i) => (
                <div key={l} className={"flex-1 min-w-[164px] py-[15px] px-5 flex flex-col gap-1.5 " + (i ? 'border-l border-line-soft max-[1000px]:border-l-0' : '')}>
                  <span className="font-mono text-[9.5px] font-medium tracking-[0.09em] uppercase text-fg-mute">{l}</span>
                  <span className="font-mono text-[21px] font-semibold leading-none text-fg-faint">—</span>
                  <span className="font-mono text-[9px] tracking-[0.07em] text-fg-faint uppercase">NO DATA</span>
                </div>
              ))}
            </div>
            <PJEmptyState icon="server" title={`No snapshots for ${PJ_MON[ym.month]} ${ym.year}`} desc="The engine hasn't ingested position snapshots for this period yet. Realized revenue and projections appear once the first daily snapshot lands."/>
          </>
        ) : (
          <>
            <PJTotals model={model} tone={tone} ym={ym}/>
            <div className="card card--flat mb-0">
              <div className={CARD_HEAD}>
                <div className={CARD_TITLE}>
                  <UIcon name="projections" size={16} style={{ color: 'var(--fg-3)' }}/>
                  {PJ_MON[ym.month]} {ym.year}
                  <span className="font-mono text-[10px] font-semibold tracking-[0.1em] uppercase px-2 py-[3px] rounded-chip ml-1"
                    style={{ color: model.type === 'past' ? 'var(--fg-mute)' : tone.css, background: model.type === 'past' ? 'var(--bg-elev-3)' : `color-mix(in srgb, ${tone.css} 13%, transparent)` }}>
                    {model.type === 'past' ? 'Realized' : model.type === 'future' ? 'Projected' : 'Hybrid'}
                  </span>
                </div>
                <span className="font-mono text-[10.5px] text-fg-mute tracking-[0.04em] max-[640px]:hidden">{acct.ex} · {acct.tag}</span>
              </div>
              <div className="p-4">
                <PJCalendar model={dispModel} tone={tone}/>
              </div>
              <PJLegend tone={tone} type={model.type}/>
            </div>
            <PJFootnotes model={model} ym={ym} tone={tone}/>
          </>
        )}
    </>
  );
};

Object.assign(window, { Projections });
