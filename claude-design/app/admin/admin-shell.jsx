// Kraite SYSADMIN console — application shell: staff-mode rail, top bar with the
// global kill switch, halt banner, footer. Chrome is always dark; the violet
// --accent (set in the page <style>) signals "staff mode" distinct from traders.

// ---------- rail ----------
const AdminRail = ({ active, setActive }) => {
  const listRef = React.useRef(null);
  const btnRefs = React.useRef({});
  const [hl, setHl] = React.useState(null);

  const measure = React.useCallback(() => {
    const el = btnRefs.current[active];
    const list = listRef.current;
    if (!el || !list) return;
    setHl({ left: el.offsetLeft, top: el.offsetTop, width: el.offsetWidth, height: el.offsetHeight });
  }, [active]);

  React.useLayoutEffect(() => { measure(); }, [measure]);
  React.useEffect(() => {
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
      <div className="flex items-center justify-center h-11 mb-3 max-[640px]:hidden">
        <img src="assets/snake-white.svg" alt="Kraite" className="block w-[30px] h-[30px]"/>
      </div>
      <div ref={listRef} className="relative flex flex-col flex-1 min-h-0 overflow-y-auto px-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden max-[640px]:flex-row max-[640px]:overflow-visible max-[640px]:px-1">
        <div className="relative flex flex-col gap-0.5 my-auto py-1 max-[640px]:flex-row max-[640px]:justify-around max-[640px]:items-center max-[640px]:w-full max-[640px]:my-0 max-[640px]:py-0 max-[640px]:gap-0">
        {hl && (
          <span aria-hidden="true"
            className="absolute z-0 bg-accent rounded-control pointer-events-none transition-all duration-[420ms] ease-[cubic-bezier(0.16,1,0.3,1)] before:content-[''] before:absolute before:-left-3 before:top-1/2 before:-translate-y-1/2 before:w-[3px] before:h-[22px] before:bg-accent before:rounded-chip max-[640px]:before:hidden"
            style={{ left: hl.left, top: hl.top, width: hl.width, height: hl.height }}/>
        )}
        {A_NAV.map(it => {
          const on = active === it.id;
          return (
            <button key={it.id} ref={el => { btnRefs.current[it.id] = el; }} onClick={() => setActive(it.id)}
              className={
                "appearance-none border-0 cursor-pointer bg-transparent flex flex-col items-center gap-[4px] pt-1.5 pb-1 px-1 rounded-control font-mono text-[10px] font-medium tracking-[0.06em] uppercase relative z-[1] transition-colors duration-fast ease-out flex-shrink-0 max-[640px]:flex-1 max-[640px]:py-2 max-[640px]:px-0.5 max-[640px]:text-[9px] max-[420px]:p-0 " +
                (on ? "text-fg-on-accent" : "text-ink-7 hover:text-ink-9")
              }>
              <UIcon name={it.icon} size={20}/>
              <span className="whitespace-nowrap max-[420px]:hidden">{it.label}</span>
            </button>
          );
        })}
        </div>
      </div>
      <div className="flex flex-col items-center gap-1.5 pt-2 mx-2 border-t border-ink-2 max-[640px]:hidden">
        <div className="w-2 h-2 rounded-chip bg-green-500" title="Control plane online"/>
      </div>
    </nav>
  );
};

// ---------- kill switch (top bar, prominent, confirm-gated) ----------
const KillSwitch = ({ halted, onChange }) => {
  const [open, setOpen] = React.useState(false);
  const ref = React.useRef(null);
  React.useEffect(() => {
    if (!open) return;
    const h = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
    document.addEventListener('mousedown', h);
    return () => document.removeEventListener('mousedown', h);
  }, [open]);

  if (halted) {
    return (
      <button onClick={() => onChange(false)}
        className="appearance-none cursor-pointer inline-flex items-center gap-2 h-[34px] px-3.5 rounded-control font-sans text-[12.5px] font-bold whitespace-nowrap border transition-colors duration-fast"
        style={{ color: 'var(--pnl-up-fg)', borderColor: 'color-mix(in srgb, var(--pnl-up-fg) 45%, transparent)', background: 'color-mix(in srgb, var(--pnl-up-fg) 12%, transparent)' }}>
        <UIcon name="play" size={15}/>Resume trading
      </button>
    );
  }

  return (
    <div className="relative" ref={ref}>
      <button onClick={() => setOpen(o => !o)}
        className="appearance-none cursor-pointer inline-flex items-center gap-2 h-[34px] px-3.5 rounded-control font-sans text-[12.5px] font-bold whitespace-nowrap border transition-colors duration-fast"
        style={{ color: 'var(--danger)', borderColor: 'color-mix(in srgb, var(--danger) 55%, transparent)', background: open ? 'color-mix(in srgb, var(--danger) 16%, transparent)' : 'color-mix(in srgb, var(--danger) 8%, transparent)' }}>
        <UIcon name="power" size={15}/><span className="max-[820px]:hidden">Halt all</span>
      </button>
      {open && (
        <div className="absolute top-[calc(100%+8px)] right-0 z-[70] w-[320px] bg-surface border rounded-control shadow-3 p-4 animate-dd-in"
          style={{ borderColor: 'color-mix(in srgb, var(--danger) 40%, var(--border))' }}>
          <div className="flex items-center gap-2.5 mb-2.5">
            <span className="w-[30px] h-[30px] rounded-control flex items-center justify-center flex-shrink-0" style={{ background: 'color-mix(in srgb, var(--danger) 15%, transparent)', color: 'var(--danger)' }}><UIcon name="power" size={17}/></span>
            <h4 className="font-sans font-bold text-[14px] text-fg-1">Halt all trading?</h4>
          </div>
          <p className="text-[12px] text-fg-3 leading-snug mb-3.5">Immediately pauses every bot across <span className="font-semibold text-fg-1">all 8 workers</span> and <span className="font-semibold text-fg-1">1,284 accounts</span>. Open positions are held, not closed. Reversible.</p>
          <div className="flex items-center gap-2">
            <button onClick={() => { onChange(true); setOpen(false); }}
              className="flex-1 appearance-none cursor-pointer inline-flex items-center justify-center gap-2 h-[38px] rounded-control font-sans text-[12.5px] font-bold text-white border-0 transition-colors duration-fast" style={{ background: 'var(--danger)' }}>
              <UIcon name="power" size={15}/>Halt everything
            </button>
            <button onClick={() => setOpen(false)} className={A_BTN_SECONDARY + " h-[38px]"}>Cancel</button>
          </div>
        </div>
      )}
    </div>
  );
};

// ---------- top bar ----------
const AdminTopBar = ({ contentDark, setContentDark, halted, onHalt }) => {
  const [now, setNow] = React.useState(new Date());
  React.useEffect(() => { const t = setInterval(() => setNow(new Date()), 1000); return () => clearInterval(t); }, []);
  const hh = String(now.getUTCHours()).padStart(2, '0');
  const mm = String(now.getUTCMinutes()).padStart(2, '0');
  const ss = String(now.getUTCSeconds()).padStart(2, '0');
  const iconBtn = "appearance-none bg-transparent border border-transparent rounded-control text-ink-7 cursor-pointer w-[34px] h-[34px] inline-flex items-center justify-center relative transition-colors duration-fast ease-out hover:text-ink-9 hover:bg-ink-1";
  return (
    <header className="h-14 flex-shrink-0 bg-[#07090b] flex items-center gap-4 px-5 z-20 max-[640px]:px-3 max-[640px]:gap-2">
      <div className="flex items-center gap-[10px] whitespace-nowrap">
        <span className="font-sans font-bold text-[15px] tracking-[-0.01em] text-ink-9 max-[640px]:text-[14px]">Kraite</span>
        <span className="font-mono text-[10px] font-bold tracking-[0.12em] uppercase py-[3px] px-2 rounded-chip" style={{ color: 'var(--accent)', background: 'color-mix(in srgb, var(--accent) 16%, transparent)', border: '1px solid color-mix(in srgb, var(--accent) 38%, transparent)' }}>Sysadmin</span>
        <span className="font-mono text-[11px] font-medium tracking-[0.06em] uppercase text-ink-6 max-[820px]:hidden">Platform ops</span>
      </div>
      <div className="flex-1"/>
      <div className="font-mono text-[12px] text-ink-7 tabular-nums flex items-center gap-2 max-[820px]:hidden">
        <span className={"w-1.5 h-1.5 rounded-chip " + (halted ? "bg-danger animate-pulse-soft" : "bg-green-500")}/>
        <span>{hh}:{mm}:{ss} UTC</span>
      </div>
      <div className="w-px h-6 bg-ink-3"/>
      <KillSwitch halted={halted} onChange={onHalt}/>
      <div className="w-px h-6 bg-ink-3"/>
      <button className={iconBtn} onClick={() => setContentDark(!contentDark)} title={contentDark ? 'Switch content to light' : 'Switch content to dark'}>
        <UIcon name={contentDark ? 'sun' : 'moon'} size={18}/>
      </button>
      <button className={iconBtn} title="Alerts">
        <UIcon name="bell" size={18}/>
        <span className="absolute top-[5px] right-[5px] w-[7px] h-[7px] rounded-chip bg-warn border-[1.5px] border-[#07090b]"/>
      </button>
      <div className="w-px h-6 bg-ink-3"/>
      <button className="flex items-center gap-[9px] bg-transparent border border-transparent rounded-control py-[5px] pr-2 pl-[6px] cursor-pointer transition-colors duration-fast ease-out hover:bg-ink-1">
        <span className="w-[30px] h-[30px] rounded-full font-mono font-bold text-[12px] flex items-center justify-center" style={{ background: 'color-mix(in srgb, var(--accent) 22%, transparent)', color: 'var(--accent)' }}>SO</span>
        <span className="flex flex-col leading-[1.15] text-left max-[820px]:hidden">
          <span className="text-[12.5px] font-semibold text-ink-9">S. Okafor</span>
          <span className="font-mono text-[10px] text-ink-6 tracking-[0.04em]">PLATFORM SRE</span>
        </span>
        <UIcon name="chevronDown" size={14} style={{ color: 'var(--ink-6)' }}/>
      </button>
      <button className={iconBtn} title="Log out"><UIcon name="logout" size={18}/></button>
    </header>
  );
};

// ---------- global halt banner ----------
const HaltBanner = ({ onResume }) => {
  const btn = "appearance-none border border-transparent rounded-chip py-1.5 px-[14px] font-sans text-[12px] font-semibold cursor-pointer whitespace-nowrap transition-colors duration-fast ease-out";
  return (
    <div className="flex-shrink-0 bg-[#2a0808] text-[#ffb0b0] flex items-center gap-3 py-[13px] px-5 rounded-t-2xl relative z-[1] after:content-[''] after:absolute after:inset-x-0 after:top-full after:h-4 after:bg-[#2a0808] max-[640px]:flex-wrap max-[640px]:py-3 max-[640px]:px-3">
      <span className="text-danger flex flex-shrink-0 animate-pulse-soft"><UIcon name="power" size={18}/></span>
      <span className="text-[13px] text-[#ffd0d0] max-[640px]:basis-full">
        <strong className="text-white font-bold">ALL TRADING HALTED</strong> — engine paused across every worker and account by <span className="font-mono text-[#ff8585]">S. Okafor</span> · just now. Open positions are held, not closed.
      </span>
      <span className="flex-1 max-[640px]:hidden"/>
      <button className={btn + " bg-danger text-white hover:bg-[#ff4d4d]"} onClick={onResume}>Resume trading</button>
    </div>
  );
};

// ---------- footer ----------
const AdminFooter = () => (
  <footer className="flex-shrink-0 bg-[#07090b] border-t border-ink-3 py-2.5 px-8 flex items-center gap-5 max-[820px]:flex-wrap max-[820px]:gap-x-4 max-[820px]:gap-y-2 max-[820px]:px-4">
    <span className="font-mono text-[10px] font-semibold tracking-[0.06em] py-[3px] px-2.5 rounded-chip whitespace-nowrap" style={{ color: 'var(--accent)', background: 'color-mix(in srgb, var(--accent) 12%, transparent)', border: '1px solid color-mix(in srgb, var(--accent) 30%, transparent)' }}>control-plane v4.2.1 · build 20260531</span>
    <nav className="flex items-center gap-4">
      {['Status', 'Runbooks', 'Incidents', 'Audit', 'On-call'].map(l => (
        <a key={l} href="#" className="font-mono text-[11px] text-ink-7 no-underline tracking-[0.02em] whitespace-nowrap hover:text-ink-9">{l}</a>
      ))}
    </nav>
    <span className="flex-1 max-[820px]:hidden"/>
    <span className="font-mono text-[10px] text-ink-6 tracking-[0.02em] whitespace-nowrap max-[820px]:whitespace-normal max-[820px]:basis-full max-[820px]:order-9">
      Operator console · actions are logged and attributed. Handle with care.
    </span>
  </footer>
);

Object.assign(window, { AdminRail, AdminTopBar, KillSwitch, HaltBanner, AdminFooter });
