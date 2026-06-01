// Kraite admin — application shell: icon rail, top bar, disconnect banner, footer.

const RAIL_ITEMS = [
  { id: 'dashboard',   label: 'Dash' },
  { id: 'positions',   label: 'Positions' },
  { id: 'projections', label: 'Project' },
  { id: 'bscs',        label: 'BSCS' },
  { id: 'accounts',    label: 'Accounts' },
  { id: 'billing',     label: 'Billing' },
];

const Rail = ({ active, setActive }) => (
  <nav className="rail">
    <div className="rail__brand">
      <img src="assets/snake-green.svg" alt="Kraite"/>
    </div>
    <div className="rail__nav">
      {RAIL_ITEMS.map(it => (
        <button key={it.id}
          className={'rail__item' + (active === it.id ? ' is-active' : '')}
          onClick={() => setActive(it.id)}>
          <RailIcon name={it.id}/>
          <span>{it.label}</span>
        </button>
      ))}
    </div>
    <div className="rail__foot">
      <div style={{ width: 8, height: 8, borderRadius: 99, background: 'var(--green-500)' }}
        title="Engine online"/>
    </div>
  </nav>
);

const TopBar = ({ contentDark, setContentDark, onBell }) => {
  const [now, setNow] = React.useState(new Date());
  React.useEffect(() => {
    const t = setInterval(() => setNow(new Date()), 1000);
    return () => clearInterval(t);
  }, []);
  const hh = String(now.getUTCHours()).padStart(2, '0');
  const mm = String(now.getUTCMinutes()).padStart(2, '0');
  const ss = String(now.getUTCSeconds()).padStart(2, '0');
  return (
    <header className="topbar">
      <div className="topbar__brand">
        <span className="topbar__brand-name">Kraite</span>
        <span className="topbar__brand-sep">—</span>
        <span className="topbar__brand-tag">Quantum Crypto Bot</span>
      </div>
      <div className="topbar__spacer"/>
      <div className="topbar__clock">
        <span className="dot"/>
        <span>{hh}:{mm}:{ss} UTC</span>
      </div>
      <div className="topbar__divider"/>
      <button className="iconbtn" onClick={() => setContentDark(!contentDark)}
        title={contentDark ? 'Switch content to light' : 'Switch content to dark'}>
        <UIcon name={contentDark ? 'sun' : 'moon'} size={18}/>
      </button>
      <button className="iconbtn" onClick={onBell} title="Notifications">
        <UIcon name="bell" size={18}/>
        <span className="iconbtn__badge"/>
      </button>
      <div className="topbar__divider"/>
      <button className="avatar">
        <span className="avatar__chip">JR</span>
        <span className="avatar__meta">
          <span className="avatar__name">J. Renner</span>
          <span className="avatar__role">TRADER</span>
        </span>
        <UIcon name="chevronDown" size={14} style={{ color: 'var(--ink-6)' }}/>
      </button>
      <button className="iconbtn" title="Log out"><UIcon name="logout" size={18}/></button>
    </header>
  );
};

const DisconnectBanner = ({ account, onDismiss }) => (
  <div className="banner">
    <span className="banner__icon"><UIcon name="plugOff" size={18}/></span>
    <span className="banner__text">
      <strong>{account.ex} ({account.tag})</strong> lost connectivity to its exchange —{' '}
      <span className="mono">{account.note.toLowerCase().includes('seen') ? account.note : 'last seen 4m ago'}</span>.
      Bot management paused for this account. Open positions are held, not adjusted.
    </span>
    <span className="banner__spacer"/>
    <button className="banner__btn banner__btn--ghost">View account</button>
    <button className="banner__btn banner__btn--primary">Retry connection</button>
    <button className="banner__btn banner__btn--subtle" onClick={onDismiss}>Dismiss</button>
  </div>
);

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
    <div className="dd" ref={ref}>
      <button className={'dd__btn' + (open ? ' is-open' : '')} onClick={() => setOpen(o => !o)}>
        {icon && <UIcon name={icon} size={14}/>}
        <span>{cur.label}</span>
        <UIcon name="chevronDown" size={13} style={{ opacity: 0.6 }}/>
      </button>
      {open && (
        <div className={'dd__menu dd__menu--' + align}>
          {options.map(o => (
            <button key={o.id} className={'dd__opt' + (o.id === value ? ' is-active' : '')}
              onClick={() => { onChange(o.id); setOpen(false); }}>
              <span>{o.label}</span>
              {o.id === value && <UIcon name="check" size={14}/>}
            </button>
          ))}
        </div>
      )}
    </div>
  );
};

const Footer = () => (
  <footer className="footer">
    <span className="footer__badge">v4.2.1 · build 20260531</span>
    <nav className="footer__links">
      <a href="#">Status</a>
      <a href="#">Audit log</a>
      <a href="#">Risk policy</a>
      <a href="#">Terms</a>
      <a href="#">Support</a>
    </nav>
    <span className="footer__spacer"/>
    <span className="footer__disc">
      Autonomous trading carries risk of total loss. Past survival is not a guarantee. Not financial advice.
    </span>
  </footer>
);

Object.assign(window, { Rail, TopBar, DisconnectBanner, Footer, RAIL_ITEMS, Dropdown });
