// Kraite SYSADMIN console — Backtesting workspace (/system/backtesting).
// Single-token ladder backtester: Fetch → Verify → Run → Approve, with an
// optional AI Insights side-trip after Run. Left rail = selection + config +
// actions + approval; right panel = coverage, fetch report, scorecards, regime
// band, rows table, AI panel. Trading semantics: green=long/safe, red=short/risk.

// ---------- shared style constants ----------
const BT_INPUT = "w-full h-[34px] px-2.5 bg-surface-2 border border-line rounded-control font-mono text-[12.5px] text-fg-1 tabular-nums outline-none transition-colors duration-fast focus:border-[color:var(--border-focus)] placeholder:text-fg-faint disabled:opacity-45 disabled:cursor-not-allowed";
const BT_FLABEL = "font-mono text-[10px] font-semibold tracking-[0.08em] uppercase text-fg-3";
const BT_HINT = "font-mono text-[10px] text-fg-3 tracking-[0.01em] leading-snug";

const GRADE_COLOR = { A: 'var(--pnl-up-fg)', B: BT_TEAL, C: 'var(--warn)', D: '#ff8a3d', F: 'var(--danger)' };
const STATUS_META = {
  tp_market_only: { label: 'TP off market', short: 'TP market', color: 'var(--pnl-up-fg)' },
  reboundable:    { label: 'Reboundable',   short: 'Rebound',   color: BT_TEAL },
  stopped_out:    { label: 'Stopped out',   short: 'Stopped',   color: 'var(--pnl-down-fg)' },
  inconclusive:   { label: 'Inconclusive',  short: 'Inconcl.',  color: 'var(--fg-mute)', striped: true },
  skipped:        { label: 'Skipped',       short: 'Skipped',   color: 'var(--fg-faint)' },
};
const REVIEW_META = {
  approved: { label: 'Approved',     color: 'var(--pnl-up-fg)' },
  rejected: { label: 'Rejected',     color: 'var(--pnl-down-fg)' },
  null:     { label: 'Not reviewed', color: 'var(--fg-mute)' },
};

// ---------- tiny pieces ----------
const BtPill = ({ color, children, dot }) => (
  <span className="inline-flex items-center gap-[6px] py-[4px] px-[10px] rounded-chip border font-mono text-[10px] font-bold tracking-[0.06em] uppercase whitespace-nowrap"
    style={{ color, borderColor: `color-mix(in srgb, ${color} 36%, transparent)`, background: `color-mix(in srgb, ${color} 11%, transparent)` }}>
    {dot && <span className="w-[6px] h-[6px] rounded-chip" style={{ background: color }}/>}{children}
  </span>
);

const BtField = ({ label, hint, children }) => (
  <label className="flex flex-col gap-[6px]">
    <span className={BT_FLABEL}>{label}</span>
    {children}
    {hint && <span className={BT_HINT}>{hint}</span>}
  </label>
);

const BtStatic = ({ label, value }) => (
  <div className="flex items-center justify-between gap-3 py-[7px] border-b border-line-soft last:border-b-0">
    <span className={BT_FLABEL}>{label}</span>
    <span className="font-mono text-[12px] font-semibold tabular-nums text-fg-2">{value}</span>
  </div>
);

// stable per-token hue → each coin gets its own avatar without inventing brand palettes
const tokenHue = (sym) => { let h = 0; for (let i = 0; i < sym.length; i++) h = (h * 31 + sym.charCodeAt(i)) >>> 0; return Math.round(((h * 0.6180339887) % 1) * 360); };
const TokenAvatar = ({ token, size = 24 }) => {
  const hue = tokenHue(token);
  return (
    <span className="flex items-center justify-center flex-shrink-0 rounded-chip font-mono font-bold leading-none"
      style={{ width: size, height: size, fontSize: Math.round(size * 0.42), color: `oklch(0.76 0.13 ${hue})`,
        background: `oklch(0.76 0.13 ${hue} / 0.15)`, border: `1px solid oklch(0.76 0.13 ${hue} / 0.32)` }}>
      {token.slice(0, 1)}
    </span>
  );
};

// ---------- filter checkbox (token universe filters) ----------
const BtCheck = ({ checked, onChange, label, count }) => (
  <label className="flex items-center gap-2 cursor-pointer select-none group">
    <span className="relative flex items-center justify-center w-[16px] h-[16px] rounded-[4px] border transition-colors duration-fast flex-shrink-0"
      style={checked
        ? { background: 'var(--accent)', borderColor: 'var(--accent)' }
        : { background: 'var(--bg-elev-2)', borderColor: 'var(--border-strong)' }}>
      <input type="checkbox" checked={checked} onChange={e => onChange(e.target.checked)} className="sr-only"/>
      {checked && <UIcon name="check" size={11} style={{ width: 11, height: 11, color: 'var(--accent-on)', strokeWidth: 3 }}/>}
    </span>
    <span className="text-[12px] font-medium text-fg-2 group-hover:text-fg-1 transition-colors whitespace-nowrap">{label}</span>
    {count != null && <span className="font-mono text-[10px] tabular-nums text-fg-faint ml-auto">{count}</span>}
  </label>
);

// ---------- [A] token selector ----------
const QUOTE_ORDER = (q) => (q === 'USDT' ? 0 : q === 'USDC' ? 1 : 2);
const TokenSelector = ({ symbols, selected, onSelect }) => {
  const [open, setOpen] = React.useState(false);
  const [query, setQuery] = React.useState('');
  const ref = React.useRef(null);
  React.useEffect(() => {
    if (!open) return;
    const h = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
    document.addEventListener('mousedown', h);
    return () => document.removeEventListener('mousedown', h);
  }, [open]);

  const filtered = symbols.filter(s => (s.token + ' ' + s.quote).toLowerCase().includes(query.toLowerCase()));
  const quotes = [...new Set(filtered.map(s => s.quote))].sort((a, b) => QUOTE_ORDER(a) - QUOTE_ORDER(b) || a.localeCompare(b));

  return (
    <div className="relative" ref={ref}>
      <button onClick={() => setOpen(o => !o)}
        className="w-full flex items-center gap-2.5 h-[40px] px-3 bg-surface-2 border border-line rounded-control cursor-pointer hover:border-line-strong transition-colors duration-fast text-left">
        {selected ? (
          <>
            <TokenAvatar token={selected.token} size={22}/>
            <span className="font-mono font-bold text-[14px] text-fg-1">{selected.token}</span>
            <span className="font-mono text-[12px] text-fg-mute">· {selected.quote}</span>
            {selected.rank && <span className="font-mono text-[9.5px] font-bold tabular-nums py-[2px] px-[6px] rounded-chip" style={{ color: 'var(--accent)', background: 'color-mix(in srgb, var(--accent) 14%, transparent)' }}>#{selected.rank}</span>}
          </>
        ) : <span className="text-[13px] text-fg-mute">Select a token…</span>}
        <UIcon name="chevronDown" size={16} style={{ color: 'var(--fg-mute)', marginLeft: 'auto', transform: open ? 'rotate(180deg)' : 'none', transition: 'transform .2s' }}/>
      </button>
      {open && (
        <div className="absolute top-[calc(100%+6px)] left-0 right-0 z-50 bg-surface border border-line-strong rounded-control shadow-3 overflow-hidden animate-dd-in">
          <div className="flex items-center gap-2 p-2 border-b border-line-soft bg-surface-2">
            <UIcon name="search" size={15} style={{ color: 'var(--fg-mute)' }}/>
            <input autoFocus value={query} onChange={e => setQuery(e.target.value)} placeholder="Filter tokens…"
              className="flex-1 bg-transparent border-0 outline-none font-mono text-[12.5px] text-fg-1 placeholder:text-fg-faint"/>
          </div>
          <div className="max-h-[300px] overflow-y-auto">
            {quotes.map(q => (
              <div key={q}>
                <div className="sticky top-0 px-3 py-1.5 bg-surface-2 border-b border-line-soft font-mono text-[9px] font-bold tracking-[0.12em] uppercase text-fg-faint">{q}</div>
                {filtered.filter(s => s.quote === q).map(s => {
                  const on = selected && selected.id === s.id;
                  return (
                    <button key={s.id} onClick={() => { onSelect(s); setOpen(false); setQuery(''); }}
                      className={"w-full flex items-center gap-2.5 px-3 py-2 text-left cursor-pointer border-b border-line-soft last:border-b-0 transition-colors duration-fast " + (on ? "bg-hover" : "bg-transparent hover:bg-hover")}>
                      <TokenAvatar token={s.token}/>
                      <span className="font-mono font-bold text-[13px] text-fg-1 w-[44px]">{s.token}</span>
                      <span className="font-mono text-[11px] text-fg-mute">{s.exchange}</span>
                      {s.rank && <span className="font-mono text-[9.5px] tabular-nums text-fg-faint ml-auto">#{s.rank}</span>}
                      {s.status && <span className="w-[6px] h-[6px] rounded-chip flex-shrink-0" style={{ background: REVIEW_META[s.status].color, marginLeft: s.rank ? 0 : 'auto' }}/>}
                    </button>
                  );
                })}
              </div>
            ))}
            {!filtered.length && <div className="px-3 py-4 text-center text-[12px] text-fg-mute">No tokens match “{query}”.</div>}
          </div>
        </div>
      )}
    </div>
  );
};

// ---------- selected token header strip ----------
const TokenHeader = ({ s, status }) => {
  const rm = REVIEW_META[status == null ? 'null' : status];
  return (
    <div className="flex items-center gap-3 flex-wrap py-3 px-4 bg-surface-2 border border-line rounded-control">
      <div className="flex items-baseline gap-1.5">
        <span className="font-mono font-bold text-[16px] text-fg-1">{s.token}</span>
        <span className="font-mono text-[12px] text-fg-mute">/ {s.quote}</span>
      </div>
      <BtPill color="var(--accent)">{s.exchange}</BtPill>
      <span className="font-mono text-[11px] text-fg-mute tracking-[0.02em]">{s.cat}</span>
      <span className="ml-auto"><BtPill color={rm.color} dot>{rm.label}</BtPill></span>
    </div>
  );
};

// ---------- [C] action button ----------
const BtActionBtn = ({ variant, icon, label, loading, loadingText, onClick, disabled }) => {
  const base = variant === 'primary' ? A_BTN_PRIMARY : variant === 'ghost' ? A_BTN_GHOST : A_BTN_SECONDARY;
  return (
    <button onClick={onClick} disabled={disabled || loading}
      className={base + " w-full justify-center h-[40px] disabled:opacity-45 disabled:cursor-not-allowed"}>
      {loading
        ? <><span className="w-[14px] h-[14px] rounded-full border-2 border-current border-t-transparent animate-spin"/>{loadingText || label}</>
        : <><UIcon name={icon} size={16}/>{label}</>}
    </button>
  );
};

Object.assign(window, {
  BT_INPUT, BT_FLABEL, BT_HINT, GRADE_COLOR, STATUS_META, REVIEW_META,
  BtPill, BtField, BtStatic, TokenSelector, TokenHeader, BtActionBtn,
});
