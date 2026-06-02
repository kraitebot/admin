// Kraite admin — application shell: icon rail, top bar, disconnect banner, footer.
// Styled with Tailwind utilities mapped to the design tokens (see app/tw-config.js).
// Chrome is always dark — it uses theme-independent ink-*/green-* tokens.

const RAIL_ITEMS = [
  { id: 'dashboard',   label: 'Dash' },
  { id: 'positions',   label: 'Positions' },
  { id: 'projections', label: 'Project' },
  { id: 'bscs',        label: 'BSCS' },
  { id: 'accounts',    label: 'Accounts' },
  { id: 'billing',     label: 'Billing' },
];

const Rail = ({ active, setActive }) => {
  const listRef = React.useRef(null);
  const btnRefs = React.useRef({});
  const [hl, setHl] = React.useState(null); // sliding highlight rect

  const measure = React.useCallback(() => {
    const el = btnRefs.current[active];
    const list = listRef.current;
    if (!el || !list) return;
    setHl({ left: el.offsetLeft, top: el.offsetTop, width: el.offsetWidth, height: el.offsetHeight });
  }, [active]);

  React.useLayoutEffect(() => { measure(); }, [measure]);
  React.useEffect(() => {
    // re-measure after first paint + once webfonts load (Space Grotesk swap
    // shifts button positions; without this the highlight keeps a stale spot).
    const raf = requestAnimationFrame(measure);
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(measure);
    window.addEventListener('load', measure);
    window.addEventListener('resize', measure);
    return () => {
      cancelAnimationFrame(raf);
      window.removeEventListener('load', measure);
      window.removeEventListener('resize', measure);
    };
  }, [measure]);

  return (
    <nav className="relative z-30 flex flex-col items-stretch bg-[#07090b] pt-3 pb-2 max-[640px]:fixed max-[640px]:inset-x-0 max-[640px]:bottom-0 max-[640px]:top-auto max-[640px]:z-[60] max-[640px]:h-[62px] max-[640px]:w-full max-[640px]:flex-row max-[640px]:border-t max-[640px]:border-ink-3 max-[640px]:p-0 max-[420px]:h-[56px]">
      <div className="flex items-center justify-center h-11 mb-4 max-[640px]:hidden">
        <img src="assets/snake-green.svg" alt="Kraite" className="block w-[30px] h-[30px]"/>
      </div>
      <div ref={listRef} className="relative flex flex-col gap-0.5 flex-1 justify-center px-2 max-[640px]:flex-row max-[640px]:justify-around max-[640px]:items-center max-[640px]:px-1 max-[640px]:gap-0">
        {/* single sliding green highlight that animates to the active item */}
        {hl && (
          <span aria-hidden="true"
            className="absolute z-0 bg-green-25 rounded-control pointer-events-none transition-all duration-[420ms] ease-[cubic-bezier(0.16,1,0.3,1)] before:content-[''] before:absolute before:-left-3 before:top-1/2 before:-translate-y-1/2 before:w-[3px] before:h-[22px] before:bg-green-500 before:rounded-chip max-[640px]:before:hidden"
            style={{ left: hl.left, top: hl.top, width: hl.width, height: hl.height }}/>
        )}
        {RAIL_ITEMS.map(it => {
          const on = active === it.id;
          return (
            <button key={it.id} ref={el => { btnRefs.current[it.id] = el; }} onClick={() => setActive(it.id)}
              className={
                "appearance-none border-0 cursor-pointer bg-transparent flex flex-col items-center gap-[5px] pt-2.5 pb-2 px-1 rounded-control font-mono text-[10px] font-medium tracking-[0.06em] uppercase relative z-[1] transition-colors duration-fast ease-out max-[640px]:flex-1 max-[640px]:py-2 max-[640px]:px-0.5 max-[640px]:text-[9px] max-[420px]:p-0 " +
                (on ? "text-green-500" : "text-ink-7 hover:text-ink-9")
              }>
              <RailIcon name={it.id}/>
              <span className="max-[420px]:hidden">{it.label}</span>
            </button>
          );
        })}
      </div>
      <div className="flex flex-col items-center gap-1.5 pt-2 mx-2 border-t border-ink-2 max-[640px]:hidden">
        <div className="w-2 h-2 rounded-chip bg-green-500" title="Engine online"/>
      </div>
    </nav>
  );
};

const TopBar = ({ contentDark, setContentDark, onBell }) => {
  const [now, setNow] = React.useState(new Date());
  React.useEffect(() => {
    const t = setInterval(() => setNow(new Date()), 1000);
    return () => clearInterval(t);
  }, []);
  const hh = String(now.getUTCHours()).padStart(2, '0');
  const mm = String(now.getUTCMinutes()).padStart(2, '0');
  const ss = String(now.getUTCSeconds()).padStart(2, '0');
  const iconBtn = "appearance-none bg-transparent border border-transparent rounded-control text-ink-7 cursor-pointer w-[34px] h-[34px] inline-flex items-center justify-center relative transition-colors duration-fast ease-out hover:text-ink-9 hover:bg-ink-1";
  return (
    <header className="h-14 flex-shrink-0 bg-[#07090b] flex items-center gap-4 px-5 z-20 max-[640px]:px-3 max-[640px]:gap-2">
      <div className="flex items-baseline gap-[9px] whitespace-nowrap">
        <span className="font-sans font-bold text-[15px] tracking-[-0.01em] text-ink-9 max-[640px]:text-[14px]">Kraite</span>
        <span className="text-ink-5 text-[13px]">—</span>
        <span className="font-mono text-[11px] font-medium tracking-[0.06em] uppercase text-green-500 max-[820px]:hidden">Quantum Crypto Bot</span>
      </div>
      <div className="flex-1"/>
      <div className="font-mono text-[12px] text-ink-7 tabular-nums flex items-center gap-2 max-[820px]:hidden">
        <span className="w-1.5 h-1.5 rounded-chip bg-green-500"/>
        <span>{hh}:{mm}:{ss} UTC</span>
      </div>
      <div className="w-px h-6 bg-ink-3"/>
      <button className={iconBtn} onClick={() => setContentDark(!contentDark)}
        title={contentDark ? 'Switch content to light' : 'Switch content to dark'}>
        <UIcon name={contentDark ? 'sun' : 'moon'} size={18}/>
      </button>
      <button className={iconBtn} onClick={onBell} title="Notifications">
        <UIcon name="bell" size={18}/>
        <span className="absolute top-[5px] right-[5px] w-[7px] h-[7px] rounded-chip bg-danger border-[1.5px] border-[#07090b]"/>
      </button>
      <div className="w-px h-6 bg-ink-3"/>
      <button className="flex items-center gap-[9px] bg-transparent border border-transparent rounded-control py-[5px] pr-2 pl-[6px] cursor-pointer transition-colors duration-fast ease-out hover:bg-ink-1">
        <span className="w-[30px] h-[30px] rounded-full bg-green-50 text-green-600 font-mono font-bold text-[12px] flex items-center justify-center">JR</span>
        <span className="flex flex-col leading-[1.15] text-left max-[820px]:hidden">
          <span className="text-[12.5px] font-semibold text-ink-9">J. Renner</span>
          <span className="font-mono text-[10px] text-ink-6 tracking-[0.04em]">TRADER</span>
        </span>
        <UIcon name="chevronDown" size={14} style={{ color: 'var(--ink-6)' }}/>
      </button>
      <button className={iconBtn} title="Log out"><UIcon name="logout" size={18}/></button>
    </header>
  );
};

const DisconnectBanner = ({ account, onDismiss }) => {
  const btn = "appearance-none bg-transparent border border-transparent rounded-chip py-1.5 px-[14px] font-sans text-[12px] font-semibold cursor-pointer whitespace-nowrap transition-colors duration-fast ease-out";
  return (
    <div className="flex-shrink-0 bg-[#2a0808] text-[#ffb0b0] flex items-center gap-3 py-[13px] px-5 rounded-t-2xl relative z-[1] after:content-[''] after:absolute after:inset-x-0 after:top-full after:h-4 after:bg-[#2a0808] max-[640px]:flex-wrap max-[640px]:py-3 max-[640px]:px-3">
      <span className="text-danger flex flex-shrink-0 animate-pulse-soft"><UIcon name="plugOff" size={18}/></span>
      <span className="text-[13px] text-[#ffd0d0] max-[640px]:basis-full">
        <strong className="text-white font-bold">{account.ex} ({account.tag})</strong> lost connectivity to its exchange —{' '}
        <span className="font-mono tabular-nums text-[#ff8585]">{account.note.toLowerCase().includes('seen') ? account.note : 'last seen 4m ago'}</span>.
        Bot management paused for this account. Open positions are held, not adjusted.
      </span>
      <span className="flex-1 max-[640px]:hidden"/>
      <button className={btn + " border-[#7a1515] text-[#ffc4c4] hover:bg-[#3a0a0a] hover:text-white"}>View account</button>
      <button className={btn + " bg-danger text-white hover:bg-[#ff4d4d]"}>Retry connection</button>
      <button className={btn + " text-[#c98585] px-2.5 hover:text-white hover:bg-[#3a0a0a]"} onClick={onDismiss}>Dismiss</button>
    </div>
  );
};

// reusable dropdown — button + popover menu, click-outside closes
const Dropdown = ({ value, options, onChange, align = 'right', icon }) => {
  const [open, setOpen] = React.useState(false);
  const ref = React.useRef(null);
  React.useEffect(() => {
    if (!open) return;
    const h = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
    document.addEventListener('mousedown', h);
    return () => document.removeEventListener('mousedown', h);
  }, [open]);
  const cur = options.find(o => o.id === value) || options[0];
  return (
    <div className="relative" ref={ref}>
      <button onClick={() => setOpen(o => !o)}
        className={
          "appearance-none cursor-pointer inline-flex items-center gap-[7px] h-[34px] bg-surface rounded-control px-[11px] font-sans text-[12.5px] font-medium whitespace-nowrap border transition-colors duration-fast ease-out " +
          (open ? "border-accent text-fg-1" : "border-line text-fg-2 hover:border-line-strong hover:text-fg-1")
        }>
        {icon && <UIcon name={icon} size={14} style={{ color: 'var(--fg-3)' }}/>}
        <span>{cur.label}</span>
        <UIcon name="chevronDown" size={13} style={{ opacity: 0.6 }}/>
      </button>
      {open && (
        <div className={
          "absolute top-[calc(100%+6px)] z-[60] min-w-[184px] bg-surface border border-line rounded-control shadow-2 p-[5px] flex flex-col gap-px animate-dd-in " +
          (align === 'right' ? "right-0" : "left-0")
        }>
          {options.map(o => {
            const on = o.id === value;
            return (
              <button key={o.id} onClick={() => { onChange(o.id); setOpen(false); }}
                className={
                  "appearance-none cursor-pointer text-left flex items-center justify-between gap-3 bg-transparent border-0 rounded-[7px] py-[7px] px-[9px] font-sans text-[12.5px] transition-colors duration-fast ease-out hover:bg-hover hover:text-fg-1 " +
                  (on ? "text-fg-1 font-semibold" : "text-fg-2")
                }>
                <span>{o.label}</span>
                {on && <UIcon name="check" size={14} style={{ color: 'var(--accent)' }}/>}
              </button>
            );
          })}
        </div>
      )}
    </div>
  );
};

const Footer = () => (
  <footer className="flex-shrink-0 bg-[#07090b] border-t border-ink-3 py-2.5 px-8 flex items-center gap-5 max-[820px]:flex-wrap max-[820px]:gap-x-4 max-[820px]:gap-y-2 max-[820px]:px-4">
    <span className="font-mono text-[10px] font-semibold tracking-[0.06em] text-green-600 bg-green-25 border border-green-50 rounded-chip py-[3px] px-2.5 whitespace-nowrap">v4.2.1 · build 20260531</span>
    <nav className="flex items-center gap-4">
      {['Status', 'Audit log', 'Risk policy', 'Terms', 'Support'].map(l => (
        <a key={l} href="#" className="font-mono text-[11px] text-ink-7 no-underline tracking-[0.02em] whitespace-nowrap hover:text-ink-9">{l}</a>
      ))}
    </nav>
    <span className="flex-1 max-[820px]:hidden"/>
    <span className="font-mono text-[10px] text-ink-6 tracking-[0.02em] whitespace-nowrap max-[820px]:whitespace-normal max-[820px]:basis-full max-[820px]:order-9">
      Autonomous trading carries risk of total loss. Past survival is not a guarantee. Not financial advice.
    </span>
  </footer>
);

Object.assign(window, { Rail, TopBar, DisconnectBanner, Footer, RAIL_ITEMS, Dropdown });
