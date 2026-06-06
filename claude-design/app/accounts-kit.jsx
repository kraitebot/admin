// Kraite admin — Accounts page.
// Where a trader connects + configures the exchange accounts the bot trades on.
// Two distinct, SEQUENTIAL jobs:
//   1. Exchange connection — credential handshake. Enter keys → Test → Save unlocks
//      only on a completed test. IP allowlist is shown (the #1 reason tests fail).
//      Editing keys after a pass re-locks Save until re-tested.
//   2. Account configuration — trading parameters, all chosen from constrained
//      dropdowns (backend-validated safe ranges). One Save with a success morph.
//
// This is the first real FORM surface of the redesign — the TextInput / Select /
// Toggle / Field language defined here is the system-wide form vocabulary.

// ============================ form-control language ============================
const AC_FIELD_LABEL = "font-mono text-[10px] font-semibold tracking-[0.11em] uppercase text-fg-mute";
const AC_HELP = "text-[11.5px] leading-[1.45] text-fg-mute mt-1.5";

// Field — label row (+ optional aside) over a control, with help/error slot.
const AcctField = ({ label, aside, help, error, htmlFor, children, dir }) => (
  <div className="flex flex-col">
    <div className="flex items-center justify-between gap-2 mb-[7px]">
      <label htmlFor={htmlFor} className={AC_FIELD_LABEL + (dir === 'long' ? ' text-pnlup' : dir === 'short' ? ' text-pnldown' : '')}>{label}</label>
      {aside}
    </div>
    {children}
    {error ? <div className="text-[11.5px] leading-[1.45] text-danger mt-1.5 flex items-center gap-1.5"><UIcon name="alert" size={12}/>{error}</div>
      : help ? <div className={AC_HELP}>{help}</div> : null}
  </div>
);

// TextInput — h-[42px], mono optional. Supports password reveal + trailing slot.
const AC_INPUT = "w-full h-[42px] bg-input border rounded-control px-3.5 text-[13.5px] text-fg-1 placeholder:text-fg-faint outline-none transition-[border-color,box-shadow] duration-fast ease-out";
const AcctInput = ({ id, value, onChange, placeholder, mono, secret, readOnly, disabled, invalid, trailing }) => {
  const [reveal, setReveal] = React.useState(false);
  const [focus, setFocus] = React.useState(false);
  const type = secret && !reveal ? 'password' : 'text';
  return (
    <div className="relative flex items-center">
      <input id={id} type={type} value={value} placeholder={placeholder} readOnly={readOnly} disabled={disabled}
        onChange={onChange ? (e) => onChange(e.target.value) : undefined}
        onFocus={() => setFocus(true)} onBlur={() => setFocus(false)}
        className={AC_INPUT + (mono ? " font-mono tracking-[0.01em]" : " font-sans") + ((secret || trailing) ? " pr-[42px]" : "")
          + (disabled ? " opacity-50 cursor-not-allowed" : "") + (readOnly ? " text-fg-2" : "")}
        style={{
          borderColor: invalid ? 'var(--danger)' : focus ? 'var(--accent)' : 'var(--border)',
          boxShadow: focus && !invalid ? '0 0 0 3px color-mix(in srgb, var(--accent) 18%, transparent)' : invalid && focus ? '0 0 0 3px color-mix(in srgb, var(--danger) 18%, transparent)' : 'none',
        }}/>
      {secret && !disabled && (
        <button type="button" onClick={() => setReveal(r => !r)} aria-label={reveal ? 'Hide' : 'Reveal'}
          className="absolute right-1.5 w-[32px] h-[32px] inline-flex items-center justify-center rounded-[7px] bg-transparent border-0 text-fg-mute hover:text-fg-1 hover:bg-hover transition-colors duration-fast cursor-pointer">
          <UIcon name={reveal ? 'eyeOff' : 'eye'} size={16}/>
        </button>
      )}
      {trailing && <div className="absolute right-1.5">{trailing}</div>}
    </div>
  );
};

// Select — constrained dropdown. options: [{value,label,dir?}] | strings.
// states: loading (spinner), empty (message), disabled.
const AcctSelect = ({ value, onChange, options, placeholder, disabled, loading, empty, emptyMsg, dir, prefix }) => {
  const [open, setOpen] = React.useState(false);
  const ref = React.useRef(null);
  React.useEffect(() => {
    if (!open) return;
    const h = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
    document.addEventListener('mousedown', h);
    return () => document.removeEventListener('mousedown', h);
  }, [open]);
  const opts = (options || []).map(o => typeof o === 'string' ? { value: o, label: o } : o);
  const sel = opts.find(o => o.value === value);
  const locked = disabled || loading || empty;
  const dirColor = dir === 'long' ? 'var(--pnl-up-fg)' : dir === 'short' ? 'var(--pnl-down-fg)' : 'var(--fg-1)';
  return (
    <div className="relative" ref={ref}>
      <button type="button" disabled={locked} onClick={() => setOpen(o => !o)}
        className={"w-full h-[42px] bg-input border rounded-control pl-3.5 pr-3 flex items-center justify-between gap-2 text-[13.5px] transition-[border-color,box-shadow] duration-fast ease-out "
          + (locked ? "cursor-not-allowed" : "cursor-pointer")}
        style={{ borderColor: open ? 'var(--accent)' : 'var(--border)', boxShadow: open ? '0 0 0 3px color-mix(in srgb, var(--accent) 18%, transparent)' : 'none', opacity: disabled ? 0.5 : 1 }}>
        {loading ? (
          <span className="flex items-center gap-2 text-fg-mute"><span className="w-[14px] h-[14px] rounded-full border-2 border-line-strong border-t-accent animate-spin"/><span className="font-mono text-[12px]">Loading balances…</span></span>
        ) : empty ? (
          <span className="font-mono text-[12px] text-fg-faint">{emptyMsg || 'No assets on exchange'}</span>
        ) : sel ? (
          <span className="font-mono font-semibold tabular-nums tracking-[0.01em] flex items-center gap-1.5" style={{ color: dirColor }}>{prefix}{sel.label}</span>
        ) : (
          <span className="font-sans text-fg-faint">{placeholder || 'Select…'}</span>
        )}
        <UIcon name="chevronDown" size={15} style={{ color: 'var(--fg-mute)', flexShrink: 0, transform: open ? 'rotate(180deg)' : 'none', transition: 'transform .18s ease' }}/>
      </button>
      {open && !locked && (
        <div className="absolute top-[calc(100%+5px)] left-0 right-0 z-[60] bg-surface border border-line rounded-control shadow-2 p-[5px] flex flex-col gap-px animate-dd-in max-h-[260px] overflow-y-auto">
          {opts.map(o => {
            const on = o.value === value;
            const oc = o.dir === 'long' ? 'var(--pnl-up-fg)' : o.dir === 'short' ? 'var(--pnl-down-fg)' : undefined;
            return (
              <button key={o.value} type="button" onClick={() => { onChange(o.value); setOpen(false); }}
                className={"appearance-none cursor-pointer text-left flex items-center justify-between gap-3 bg-transparent border-0 rounded-[7px] py-2 px-2.5 transition-colors duration-fast ease-out hover:bg-hover " + (on ? "bg-hover" : "")}>
                <span className="font-mono text-[13px] font-semibold tabular-nums" style={{ color: oc || (on ? 'var(--fg-1)' : 'var(--fg-2)') }}>{prefix}{o.label}</span>
                {on && <UIcon name="check" size={15} style={{ color: 'var(--accent)' }}/>}
              </button>
            );
          })}
        </div>
      )}
    </div>
  );
};

// Toggle — pill switch. green = enabled/on (semantic: trading-enabled is safe/go).
const AcctToggle = ({ checked, onChange, disabled, labels }) => (
  <button type="button" role="switch" aria-checked={checked} disabled={disabled} onClick={() => onChange(!checked)}
    className={"relative inline-flex items-center h-[26px] w-[46px] rounded-chip transition-colors duration-[180ms] ease-out flex-shrink-0 " + (disabled ? "opacity-40 cursor-not-allowed" : "cursor-pointer")}
    style={{ background: checked ? 'var(--accent)' : 'var(--bg-elev-3)', boxShadow: checked ? 'none' : 'inset 0 0 0 1px var(--border-strong)' }}>
    <span className="absolute rounded-chip bg-white transition-[left] duration-[180ms] ease-[cubic-bezier(0.16,1,0.3,1)]"
      style={{ width: 18, height: 18, top: 4, left: checked ? 24 : 4, boxShadow: '0 1px 3px rgba(0,0,0,.4)' }}/>
  </button>
);

// ============================ accounts data ============================
const AC_DOT = (state) => "w-[8px] h-[8px] rounded-chip flex-shrink-0 " + (state === 'ok' ? 'bg-green-500' : state === 'down' ? 'bg-danger animate-pulse-soft' : 'bg-warn');

// Kraite egress IPs the user must allowlist on their exchange (paired to SERVERS).
const AC_IPS = [
  { id: 'kr-fra-01', region: 'Frankfurt', ip: '51.158.10.21' },
  { id: 'kr-fra-02', region: 'Frankfurt', ip: '51.158.10.22' },
  { id: 'kr-ldn-01', region: 'London',    ip: '178.62.40.13' },
  { id: 'kr-nyc-01', region: 'New York',  ip: '159.65.20.44' },
  { id: 'kr-sgp-01', region: 'Singapore', ip: '128.199.80.5' },
  { id: 'kr-sgp-02', region: 'Singapore', ip: '128.199.80.6' },
];

// per-account identity + config seed (owner, passphrase requirement, held quotes)
const AC_META = {
  'Binance|main':    { owner: 'Frankfurt desk',  needsPass: false, quotes: ['USDT', 'USDC', 'BNB'],  cfgName: 'Primary book' },
  'Bybit|hedge':     { owner: 'Frankfurt desk',  needsPass: false, quotes: ['USDT', 'USDC'],          cfgName: 'Hedge sleeve' },
  'OKX|arb':         { owner: 'Singapore desk',   needsPass: true,  quotes: ['USDT'],                  cfgName: 'Arb engine' },
  'Deribit|options': { owner: 'Frankfurt desk',  needsPass: false, quotes: ['BTC', 'ETH', 'USDC'],    cfgName: 'Options book' },
};
const AC_KEY = (a) => a.ex + '|' + a.tag;

// constrained config option lists (verbatim, backend-validated)
const AC_OPTS = {
  pt:        ['0.360', '0.380', '0.400'],
  sl:        ['2.50', '5.00', '7.50'],
  slots:     ['4', '5', '6'],
  lev:       ['10', '15', '20'],
  margin:    ['4.00', '5.00', '6.00'],
};
// default config per account
const AC_DEFAULT_CFG = {
  'Binance|main':    { canTrade: true,  pq: 'USDT', tq: 'USDT', pt: '0.380', sl: '5.00', sL: '5', sS: '5', lL: '15', lS: '15', mL: '5.00', mS: '5.00' },
  'Bybit|hedge':     { canTrade: true,  pq: 'USDT', tq: 'USDC', pt: '0.360', sl: '5.00', sL: '4', sS: '6', lL: '10', lS: '15', mL: '4.00', mS: '5.00' },
  'OKX|arb':         { canTrade: false, pq: 'USDT', tq: 'USDT', pt: '0.400', sl: '7.50', sL: '6', sS: '6', lL: '20', lS: '20', mL: '6.00', mS: '6.00' },
  'Deribit|options': { canTrade: true,  pq: 'BTC',  tq: 'USDC', pt: '0.380', sl: '2.50', sL: '5', sS: '4', lL: '15', lS: '10', mL: '5.00', mS: '4.00' },
};

// ============================ section primitives ============================
const AcctZone = ({ children }) => (
  <div className="card card--flat">{children}</div>
);
const AcctZoneHead = ({ icon, step, title, sub, right }) => (
  <div className="flex items-start justify-between gap-4 py-[18px] px-6 border-b border-line-soft max-[640px]:px-4">
    <div className="flex items-start gap-3.5">
      <div className="w-[34px] h-[34px] rounded-control bg-surface-3 border border-line flex items-center justify-center text-fg-2 flex-shrink-0 mt-0.5"><UIcon name={icon} size={17}/></div>
      <div>
        <div className="flex items-center gap-2.5">
          {step && <span className="font-mono text-[10px] font-bold tracking-[0.1em] text-fg-faint">{step}</span>}
          <h3 className="font-sans font-semibold text-[16px] tracking-[-0.01em] text-fg-1 leading-tight">{title}</h3>
        </div>
        {sub && <p className="text-[12.5px] text-fg-3 mt-1 leading-snug max-w-[520px]">{sub}</p>}
      </div>
    </div>
    {right && <div className="flex-shrink-0 mt-0.5">{right}</div>}
  </div>
);
// Section header BAND — matches the card-header treatment used across the app:
// a filled strip with its own padding + bottom border, title in white sans-
// semibold with a leading icon. Gives sub-sections the same contrast as the
// card headers on Dashboard / Positions (not just inline grey text).
const AcctBandHead = ({ icon, title, hint, right, children }) => (
  <div className="flex items-center justify-between gap-3 py-[13px] px-6 bg-surface-2 border-b border-line-soft max-[640px]:px-4">
    <h4 className="font-sans font-semibold text-[14px] text-fg-1 flex items-center gap-[9px] whitespace-nowrap leading-none">
      {icon && <UIcon name={icon} size={16} style={{ color: 'var(--fg-3)' }}/>}{title}
    </h4>
    {right || (hint ? <span className="font-mono text-[10.5px] text-fg-mute tracking-[0.02em]">{hint}</span> : null) || children}
  </div>
);
const AcctGroup = ({ title, icon, hint, children, cols = 2 }) => (
  <div className="border-b border-line-soft last:border-b-0">
    <AcctBandHead icon={icon} title={title} hint={hint}/>
    <div className="py-5 px-6 max-[640px]:px-4">
      <div className={"grid gap-x-5 gap-y-5 " + (cols === 2 ? "grid-cols-2 max-[700px]:grid-cols-1" : "grid-cols-1")}>{children}</div>
    </div>
  </div>
);

// status chip (Connection OK / Trading disabled / Not connected)
const AcctStatusChip = ({ kind }) => {
  const map = {
    ok:       { c: 'var(--pnl-up-fg)', t: 'Connection OK',   d: false },
    disabled: { c: 'var(--warn)',      t: 'Trading disabled', d: true },
    none:     { c: 'var(--fg-mute)',   t: 'Not connected',    d: false },
    testing:  { c: 'var(--info)',      t: 'Testing…',         d: false },
  };
  const m = map[kind] || map.none;
  return (
    <span className="inline-flex items-center gap-2 py-[6px] px-3 rounded-chip border font-mono text-[11px] font-semibold tracking-[0.06em] whitespace-nowrap"
      style={{ color: m.c, borderColor: `color-mix(in srgb, ${m.c} 38%, transparent)`, background: `color-mix(in srgb, ${m.c} 12%, transparent)` }}>
      <span className={"w-2 h-2 rounded-chip" + (m.d ? " animate-pulse-soft" : "")} style={{ background: m.c }}/>{m.t}
    </span>
  );
};

// copy-to-clipboard affordance
const AcctCopy = ({ text, label, full }) => {
  const [done, setDone] = React.useState(false);
  const copy = () => {
    const ok = () => { setDone(true); setTimeout(() => setDone(false), 1400); };
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(ok).catch(ok);
    else ok();
  };
  return (
    <button type="button" onClick={copy}
      className={"appearance-none cursor-pointer inline-flex items-center gap-1.5 rounded-[7px] border border-line bg-surface-3 text-fg-2 font-mono text-[10.5px] font-semibold tracking-[0.04em] transition-colors duration-fast hover:border-line-strong hover:text-fg-1 "
        + (full ? "h-[30px] px-3" : "h-[26px] px-2.5")}
      style={done ? { color: 'var(--pnl-up-fg)', borderColor: 'color-mix(in srgb, var(--pnl-up-fg) 40%, transparent)' } : undefined}>
      <UIcon name={done ? 'check' : 'copy'} size={13}/>{done ? 'Copied' : (label || 'Copy')}
    </button>
  );
};

Object.assign(window, {
  AcctField, AcctInput, AcctSelect, AcctToggle, AcctZone, AcctZoneHead, AcctGroup, AcctBandHead,
  AcctStatusChip, AcctCopy, AC_DOT, AC_IPS, AC_META, AC_KEY, AC_OPTS, AC_DEFAULT_CFG,
});
