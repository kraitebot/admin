// Kraite admin — trader Dashboard (hero working screen).

const KpiTile = ({ k }) => (
  <div className="tile">
    <div className="tile__eyebrow">
      <UIcon name={k.icon} size={12}/>{k.label}
    </div>
    {k.key === 'op' ? (
      <div className="tile__line">
        <span className="tile__value">{k.value}</span>
        <div className="tile__opbar">
          <div className="tile__opbar-track">
            <span style={{ flex: 6, background: 'var(--pnl-up-fg)' }}/>
            <span style={{ flex: 4, background: 'var(--pnl-down-fg)' }}/>
          </div>
          <div className="tile__opbar-legend">
            <span className="mono pnl-up">6L</span>
            <span className="mono pnl-down">4S</span>
          </div>
        </div>
      </div>
    ) : (
      <div className="tile__line">
        <span className="tile__value">{k.value}</span>
        <Delta value={k.delta}/>
        {k.spark && <div className="tile__spark"><Sparkline data={k.spark} up={k.up} w={84} h={28}/></div>}
      </div>
    )}
  </div>
);

// ---- position lifecycle tile ----
const PositionTile = ({ p }) => {
  const long = p.side === 'long';
  const sideCls = long ? 'side--long' : 'side--short';
  const status = p.status; // 'opening' | 'waped' | undefined
  const [imgOk, setImgOk] = React.useState(true);
  // lifecycle track: limit ladder on the left, price marker past it, TP on the right
  const LADDER = [26, 44, 62, 80];
  const fillN = parseInt(p.filled, 10) || 0;
  const gainL = Math.min(p.trackPx, p.trackTp);
  const gainW = Math.abs(p.trackTp - p.trackPx);
  return (
    <div className={'ptile' + (status ? ' ptile--' + status : '')}>
      <div className="ptile__body">
        <div className="ptile__head">
          <div className="ptile__icon" style={{ background: imgOk ? 'transparent' : p.color }}>
            {imgOk
              ? <img src={`https://s2.coinmarketcap.com/static/img/coins/64x64/${p.cmcId}.png`} alt={p.sym} onError={() => setImgOk(false)}/>
              : p.sym[0]}
          </div>
          <div className="ptile__id">
            <div className="ptile__symrow">
              <span className="ptile__sym">{p.sym}</span>
              <span className="ptile__name">{p.name}</span>
              {status === 'opening' && (
                <span className="ptile__badge ptile__badge--opening"><span className="ptile__badge-dot"/>Opening</span>
              )}
              {status === 'waped' && (
                <span className="ptile__badge ptile__badge--waped"><UIcon name="layers" size={10}/>WAP'd</span>
              )}
            </div>
            <div className="ptile__metarow">
              <span className={'side dir ' + sideCls}>
                <UIcon name={long ? 'arrowUp' : 'arrowDown'} size={11} style={{ width: 11, height: 11 }}/>
                {p.side} {p.lev}
              </span>
              <span className="ptile__eta"><UIcon name="clock" size={12}/>{p.eta}</span>
            </div>
          </div>
          <div className="ptile__dots" title="Timeframe oscillation">
            {p.osc.map((d, i) => <i key={i} className={'osc--' + d}/>)}
            <button className="ptile__menu" title="Actions"><UIcon name="more" size={16}/></button>
          </div>
        </div>

        <div className="ptrack">
          <div className="ptrack__line"/>
          <div className="ptrack__line ptrack__line--gain" style={{ left: gainL + '%', width: gainW + '%' }}/>
          {LADDER.map((pos, i) => {
            if (i < fillN) return null; // consumed limit — number disappears (TP sits here)
            return (
              <span key={i} className="ptrack__tick" style={{ left: pos + '%' }}>{i + 1}</span>
            );
          })}
          <div className="ptrack__pin ptrack__pin--tp" style={{ left: p.trackTp + '%' }}>
            <span className="ptrack__pinlabel">TP</span>
            <span className="ptrack__pindot"/>
          </div>
          <div className="ptrack__pin ptrack__pin--px" style={{ left: p.trackPx + '%' }}>
            <span className="ptrack__pinlabel ptrack__pinlabel--px">PX</span>
            <span className="ptrack__pindot ptrack__pindot--px"/>
          </div>
        </div>

        <div className="ptile__metrics">
          <div>
            <div className="pcell__lbl"><UIcon name="flag" size={11}/>Path</div>
            <div className="pcell__val accent">{p.path.toFixed(1)}%</div>
          </div>
          <div className="pcell--center">
            <div className="pcell__lbl" style={{ justifyContent: 'center' }}><UIcon name="arrowRight" size={11}/>Limit</div>
            <div className="pcell__val">{p.limit.toFixed(1)}%</div>
          </div>
          <div className="pcell--right">
            <div className="pcell__lbl" style={{ justifyContent: 'flex-end' }}><UIcon name="check" size={11}/>Filled</div>
            <div className="pcell__val">{p.filled}</div>
          </div>
        </div>

        <div className="ptile__divider"/>

        <div className="ptile__prices">
          <div>
            <div className="pcell__lbl"><UIcon name="circleSm" size={11}/>Open</div>
            <div className="pcell__val sm">{p.open}</div>
          </div>
          <div className="pcell--center">
            <div className="pcell__lbl" style={{ justifyContent: 'center', color: 'var(--pnl-up-fg)' }}><UIcon name="arrowUp" size={11}/>TP</div>
            <div className="pcell__val sm accent">{p.tp}</div>
          </div>
          <div className="pcell--right">
            <div className="pcell__lbl" style={{ justifyContent: 'flex-end' }}><UIcon name="arrowDown" size={11}/>Next</div>
            <div className="pcell__val sm">{p.next}</div>
          </div>
        </div>
      </div>
    </div>
  );
};

// ---- open positions section: full-width tile grid, optional pagination ----
const PositionsSection = ({ paginate }) => {
  const [filter, setFilter] = React.useState('ALL');
  const [page, setPage] = React.useState(0);
  const PER = 6;
  const rows = POSITIONS.filter(p => filter === 'ALL' || p.side === filter.toLowerCase());
  const usePager = paginate && rows.length > PER;
  const pageCount = Math.ceil(rows.length / PER);
  const safePage = Math.min(page, pageCount - 1);
  const pages = [];
  for (let i = 0; i < rows.length; i += PER) pages.push(rows.slice(i, i + PER));
  const setFilterReset = (f) => { setFilter(f); setPage(0); };

  // drag / swipe with snap
  const viewRef = React.useRef(null);
  const trackRef = React.useRef(null);
  const dotsRef = React.useRef(null);
  const thumbRef = React.useRef(null);
  const prevPage = React.useRef(safePage);
  const drag = React.useRef({ active: false, startX: 0, base: 0, w: 0, moved: 0 });

  // dot indicator "glue" — stretch a green thumb across to the new dot, then settle
  React.useLayoutEffect(() => {
    if (!usePager) return;
    const th = thumbRef.current, dots = dotsRef.current;
    if (!th || !dots) return;
    const els = dots.querySelectorAll('.pcar__dot');
    const a = els[prevPage.current], b = els[safePage];
    if (!a || !b) return;
    const lo = Math.min(a.offsetLeft, b.offsetLeft);
    const hi = Math.max(a.offsetLeft + a.offsetWidth, b.offsetLeft + b.offsetWidth);
    th.style.transition = 'left 200ms var(--ease-out), width 200ms var(--ease-out)';
    th.style.left = lo + 'px';
    th.style.width = (hi - lo) + 'px';   // stretch to span both
    const t = setTimeout(() => {
      th.style.left = b.offsetLeft + 'px';
      th.style.width = b.offsetWidth + 'px'; // settle on target
    }, 190);
    prevPage.current = safePage;
    return () => clearTimeout(t);
  }, [safePage, usePager, pages.length]);
  const applyTransform = (px) => { if (trackRef.current) trackRef.current.style.transform = `translateX(${px}px)`; };
  const snapTo = (i) => {
    const t = trackRef.current;
    if (t) { t.style.transition = ''; t.style.transform = `translateX(-${i * 100}%)`; }
    setPage(i);
  };
  const onDown = (e) => {
    if (!usePager || e.button === 1 || e.button === 2) return;
    const w = viewRef.current.offsetWidth;
    drag.current = { active: true, startX: e.clientX, base: -safePage * w, w, moved: 0 };
    if (trackRef.current) trackRef.current.style.transition = 'none';
    viewRef.current.setPointerCapture?.(e.pointerId);
  };
  const onMove = (e) => {
    const d = drag.current;
    if (!d.active) return;
    let dx = e.clientX - d.startX;
    d.moved = dx;
    let pos = d.base + dx;
    const min = -(pages.length - 1) * d.w;
    if (pos > 0) pos = pos * 0.35;                 // rubber-band at start
    if (pos < min) pos = min + (pos - min) * 0.35; // rubber-band at end
    applyTransform(pos);
  };
  const onUp = (e) => {
    const d = drag.current;
    if (!d.active) return;
    d.active = false;
    viewRef.current.releasePointerCapture?.(e.pointerId);
    let next = safePage;
    if (d.moved < -d.w * 0.18) next = Math.min(pages.length - 1, safePage + 1);
    else if (d.moved > d.w * 0.18) next = Math.max(0, safePage - 1);
    snapTo(next);
  };

  return (
    <section style={{ marginBottom: 'var(--sp-7)' }}>
      <div className="sec-head">
        <div>
          <div className="sec-head__title"><UIcon name="layers" size={17}/>Open positions</div>
          <div className="sec-head__sub">
            {usePager
              ? <>{rows.length} positions · showing {safePage * PER + 1}–{Math.min(safePage * PER + PER, rows.length)} · max 6 per direction</>
              : <>{rows.length} positions managed across the lifecycle · no manual orders</>}
          </div>
        </div>
        <div className="sec-head__actions">
          <div className="seg">
            {['ALL','LONG','SHORT'].map(f => (
              <button key={f} className={filter === f ? 'is-active' : ''} onClick={() => setFilterReset(f)}>{f}</button>
            ))}
          </div>
          <button className="acct-select">
            <span className="acct-select__dot"/>
            <span className="acct-select__txt">Karine Esnault · Binance</span>
            <UIcon name="chevronDown" size={14}/>
          </button>
        </div>
      </div>
      {usePager ? (
        <>
          <div className="pcar" ref={viewRef}
            onPointerDown={onDown} onPointerMove={onMove} onPointerUp={onUp} onPointerCancel={onUp}>
            <div className="pcar__track" ref={trackRef} style={{ transform: `translateX(-${safePage * 100}%)` }}>
              {pages.map((pg, i) => (
                <div className="pcar__page" key={i}>
                  <div className="ptile-grid">
                    {pg.map(p => <PositionTile key={p.sym} p={p}/>)}
                  </div>
                </div>
              ))}
            </div>
          </div>
          <div className="pcar__dots" ref={dotsRef}>
            {pages.map((_, i) => (
              <button key={i} className="pcar__dot" onClick={() => snapTo(i)} aria-label={`Page ${i + 1}`}/>
            ))}
            <span className="pcar__thumb" ref={thumbRef}/>
          </div>
        </>
      ) : (
        <div className="ptile-grid">
          {rows.map(p => <PositionTile key={p.sym} p={p}/>)}
        </div>
      )}
    </section>
  );
};

// status pill matching the regime pill style (coherent alerts)
const StatusPill = ({ tone, pulse, children }) => {
  const c = tone === 'down' ? 'var(--danger)' : tone === 'warn' ? 'var(--warn)' : 'var(--accent)';
  const fg = tone === 'down' ? 'var(--pnl-down-fg)' : tone === 'warn' ? 'var(--warn)' : 'var(--pnl-up-fg)';
  return (
    <span className="pill" style={{
      background: `color-mix(in srgb, ${c} 12%, transparent)`,
      borderColor: `color-mix(in srgb, ${c} 38%, transparent)`,
      color: fg,
    }}>
      <span className="pill__dot" style={{ background: c, animation: pulse ? 'kr-pulse 1.4s ease-in-out infinite' : undefined }}/>
      {children}
    </span>
  );
};

// ---- server connectivity — our 6 whitelisted egress servers → exchange ----
// All-healthy collapses to a single green light; any fault expands the list.
const ConnectivityCard = ({ fault }) => {
  const servers = fault
    ? SERVERS.map((s, i) => i === 4 ? { ...s, state: 'down', latency: '—' } : s)
    : SERVERS;
  const down = servers.filter(s => s.state === 'down').length;
  const warn = servers.filter(s => s.state === 'warn').length;
  const allOk = down === 0 && warn === 0;
  const [open, setOpen] = React.useState(false);
  const expanded = open || !allOk;

  return (
    <div className={'card' + (down > 0 ? ' card--alert' : warn > 0 ? ' card--warn' : '')}>
      <div className="card__head">
        <div className="card__title"><UIcon name="server" size={16}/>Server connectivity</div>
        {down > 0
          ? <StatusPill tone="down" pulse>{down} DOWN</StatusPill>
          : warn > 0
            ? <StatusPill tone="warn">{warn} DEGRADED</StatusPill>
            : <StatusPill tone="ok">ALL LINKED</StatusPill>}
      </div>

      {!expanded ? (
        // collapsed green-light summary
        <button className="srv-light" onClick={() => setOpen(true)}>
          <span className="srv-light__beacon"><span/></span>
          <span className="srv-light__txt">
            <span className="srv-light__head">All servers linked</span>
            <span className="srv-light__sub">6 / 6 whitelisted · egress → exchange · avg 31ms</span>
          </span>
          <span className="srv-light__chev">Details <UIcon name="chevronDown" size={13}/></span>
        </button>
      ) : (
        <div>
          {(allOk ? servers : servers.filter(s => s.state !== 'ok')).map(s => (
            <div key={s.id} className={'srv' + (s.state === 'down' ? ' is-down' : '')}>
              <span className={'acct__dot acct__dot--' + s.state}/>
              <span className="srv__id">{s.id}</span>
              <span className="srv__region">{s.region}</span>
              <span className={'acct__lat' + (s.state === 'down' ? ' acct__lat--down' : '')}>
                {s.state === 'ok' ? s.latency : 'DOWN'}
              </span>
            </div>
          ))}
          {!allOk && (
            <div className="srv-note">
              <span className="acct__dot acct__dot--ok"/>
              {servers.filter(s => s.state === 'ok').length} other servers linked · egress nominal
            </div>
          )}
        </div>
      )}

      <div className="card__foot">
        <span className="mono" style={{ fontSize: 11, color: 'var(--fg-mute)', display: 'inline-flex', alignItems: 'center', gap: 7 }}>
          <span style={{ width: 6, height: 6, borderRadius: 99, background: 'var(--green-500)' }}/>HEARTBEAT 5s
        </span>
        {expanded && allOk
          ? <button className="link-quiet" onClick={() => setOpen(false)}>Collapse</button>
          : <a href="#" className="link-quiet">whitelist →</a>}
      </div>
    </div>
  );
};

// ---- activity feed — one line per item, collapse past the first few ----
const ActivityCard = () => {
  const [showAll, setShowAll] = React.useState(false);
  const [cat, setCat] = React.useState('all');
  const COLLAPSED = 11;
  const filtered = ACTIVITY.filter(a => cat === 'all' || a.cat === cat);
  const rows = showAll ? filtered : filtered.slice(0, COLLAPSED);
  const hidden = filtered.length - COLLAPSED;
  return (
    <div className="card">
      <div className="card__head">
        <div className="card__title"><UIcon name="bot" size={16}/>Recent bot activity</div>
        <div className="card__actions">
          <Dropdown value={cat} options={ACTIVITY_CATS} onChange={(v) => { setCat(v); setShowAll(false); }} icon="filter" align="right"/>
        </div>
      </div>
      <div className="feed">
        {rows.length === 0 ? (
          <div className="feed-empty">No {ACTIVITY_CATS.find(c => c.id === cat)?.label.toLowerCase()} in this window.</div>
        ) : rows.map((a, i) => (
          <div className="feed-line" key={i}>
            <span className="feed-line__dot" style={{ background: a.dot }}/>
            <span className="feed-line__kind">{a.kind}</span>
            <span className="feed-line__msg">{a.el}</span>
            <span className="feed-line__time">{a.time}</span>
          </div>
        ))}
      </div>
      <div className="card__foot">
        <span className="mono" style={{ fontSize: 11, color: 'var(--fg-mute)' }}>UPDATED 12s AGO · {filtered.length} EVENTS</span>
        {hidden > 0
          ? <button className="link-arrow" style={{ background: 'none', border: 0, cursor: 'pointer', fontFamily: 'inherit' }} onClick={() => setShowAll(!showAll)}>
              {showAll ? 'Show less' : 'Show all activity'}
              <UIcon name={showAll ? 'chevronUp' : 'chevronDown'} size={13}/>
            </button>
          : <a href="#" className="link-arrow">Audit log →</a>}
      </div>
    </div>
  );
};

// ---- BSCS mini snapshot — compact: score + regime + gradient bar ----
const BscsMiniCard = ({ regime, score }) => {
  const r = REGIMES[regime] || REGIMES.CALM;
  const clamp = (v) => Math.max(0.05, Math.min(0.98, v));
  const comps = [
    { label: 'BTC realized vol',    v: clamp(score * 1.00) },
    { label: 'Cross-asset corr.',   v: clamp(score * 1.38) },
    { label: 'Funding dispersion',  v: clamp(score * 0.74) },
    { label: 'Liquidity depth',     v: clamp(score * 1.12) },
  ];
  const barColor = (v) => v < 0.5 ? 'var(--accent)' : v < 0.75 ? 'var(--bsi-watch)' : 'var(--bsi-cascade)';
  const suspended = regime === 'ELEVATED' || regime === 'CASCADE' || regime === 'BLACK SWAN';
  const until = new Date(Date.now() + 24 * 3600 * 1000);
  const untilStr = until.toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false, timeZone: 'UTC' }).replace(' at ', ', ') + ' UTC';
  return (
    <div className="card">
      <div className="card__head">
        <div className="card__title"><UIcon name="shield" size={16}/>Black Swan Composite</div>
        <RegimePill regime={regime} pulse={regime === 'CASCADE' || regime === 'BLACK SWAN'}/>
      </div>
      <div className="bscs-strip">
        <div className="bscs-score">
          <span className="bscs-score__num" style={{ color: r.color }}>{score.toFixed(2)}</span>
          <span className="bscs-score__den">/ 1.00 · <span style={{ color: r.color, fontWeight: 600 }}>{regime}</span></span>
          <span className="bscs-score__pos">{suspended ? 'NEW POS. SUSPENDED' : score < 0.5 ? 'NEW POS. ALLOWED' : 'NEW POS. REDUCED'}</span>
        </div>
        <div>
          <div className="bscs-bar">
            <span className="bscs-bar__marker" style={{ left: (score * 100) + '%' }}/>
          </div>
          <div className="bscs-scale">
            {['CALM','WATCH','ELEV','CASC','SWAN'].map(s => <span key={s}>{s}</span>)}
          </div>
        </div>
        {suspended && (
          <div className="bscs-suspend">
            <UIcon name="alert" size={15}/>
            <span>New position openings <strong>suspended for 24h</strong> — until <span className="mono">{untilStr}</span>. Existing positions are still managed.</span>
          </div>
        )}
        <div className="bscs-comps">
          {comps.map(c => (
            <div className="bscs-comp" key={c.label}>
              <span className="bscs-comp__lbl">{c.label}</span>
              <div className="bscs-comp__track">
                <div className="bscs-comp__fill" style={{ width: (c.v * 100) + '%', background: barColor(c.v) }}/>
              </div>
              <span className="bscs-comp__val">{c.v.toFixed(2)}</span>
            </div>
          ))}
        </div>
      </div>
      <div className="card__foot">
        <span className="mono" style={{ fontSize: 11, color: 'var(--fg-mute)' }}>UPDATED 38s AGO</span>
        <a href="#" className="link-arrow">View details →</a>
      </div>
    </div>
  );
};

const Dashboard = ({ regime, score, serverFault, paginate }) => {
  const suspended = regime === 'ELEVATED' || regime === 'CASCADE' || regime === 'BLACK SWAN';
  const until = new Date(Date.now() + 24 * 3600 * 1000);
  const untilStr = until.toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false, timeZone: 'UTC' }).replace(' at ', ', ') + ' UTC';
  return (
  <>
    <div className="pagehead">
      <div>
        <div className="pagehead__eyebrow"><UIcon name="dashboard" size={13} style={{ width: 13, height: 13 }}/>OVERVIEW</div>
        <h1>Dashboard</h1>
        <div className="pagehead__sub">
          Engine running autonomously · <span className="mono">10</span> open positions · last sync <span className="mono">3s</span> ago
        </div>
      </div>
      <div className="pagehead__actions">
        <div className="htick" title="BTC-USDT · timeframe closes: 1H 4H 1D 1W">
          <img className="htick__icon" src="https://s2.coinmarketcap.com/static/img/coins/64x64/1.png" alt="BTC"/>
          <div className="htick__meta">
            <span className="htick__name">Bitcoin · USDT</span>
            <span className="htick__px mono">68,910.50</span>
          </div>
          <div className="htick__osc">
            {['up','up','down','up'].map((d, i) => <i key={i} className={d}/>)}
          </div>
        </div>
        <div className="divider-v"/>
        <RegimePill regime={regime} score={score} pulse={regime === 'CASCADE' || regime === 'BLACK SWAN'}/>
        <div className="divider-v"/>
        <button className="btn btn--secondary btn--sm"><UIcon name="refresh" size={15}/>Sync</button>
        <button className="btn btn--primary btn--sm"><UIcon name="projections" size={15}/>View projections</button>
      </div>
    </div>

    {suspended && (
      <div className="suspend-banner">
        <span className="suspend-banner__icon"><UIcon name="alert" size={18}/></span>
        <span className="suspend-banner__text">
          <strong>New position openings suspended for 24h</strong> — Black Swan regime is <span className="mono">{regime} {score.toFixed(2)}</span>.
          Resumes <span className="mono">{untilStr}</span> if the regime clears. Existing positions are still managed.
        </span>
        <a href="#" className="suspend-banner__link">View risk policy →</a>
      </div>
    )}

    <div className="kpi-row">
      {KPIS.map(k => <KpiTile key={k.key} k={k}/>)}
    </div>

    <PositionsSection paginate={paginate}/>

    <div className="dash-grid">
      <div className="dash-col dash-col--main">
        <ActivityCard/>
      </div>
      <div className="dash-col">
        <ConnectivityCard fault={serverFault}/>
        <BscsMiniCard regime={regime} score={score}/>
      </div>
    </div>
  </>
  );
};

// ---- generic placeholder for non-dashboard routes (empty state) ----
const Placeholder = ({ route }) => {
  const meta = {
    positions:   { icon: 'layers',      title: 'Positions', desc: 'Full position lifecycle — open, history, and per-market detail.' },
    projections: { icon: 'projections', title: 'Projections', desc: 'Forecast equity and expected-return bands under the current regime.' },
    bscs:        { icon: 'bscs',         title: 'Black Swan Composite Score', desc: 'Market-risk regime detail, component breakdown, and trip history.' },
    accounts:    { icon: 'accounts',     title: 'Accounts', desc: 'Exchange credentials, connectivity testing, and per-account equity.' },
    billing:     { icon: 'billing',      title: 'Billing', desc: 'Balance, plan, wallet history, and top-up.' },
  }[route] || { icon: 'dashboard', title: route, desc: '' };
  return (
    <>
      <div className="pagehead">
        <div>
          <div className="pagehead__eyebrow"><UIcon name={meta.icon} size={13} style={{ width: 13, height: 13 }}/>{meta.title.toUpperCase()}</div>
          <h1>{meta.title}</h1>
          <div className="pagehead__sub">{meta.desc}</div>
        </div>
      </div>
      <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', textAlign: 'center', padding: '90px 20px', border: '1px dashed var(--border)', borderRadius: 'var(--ar-surface)', background: 'var(--bg-elev-1)' }}>
        <div style={{ width: 48, height: 48, borderRadius: 'var(--ar-control)', border: '1px solid var(--border)', display: 'flex', alignItems: 'center', justifyContent: 'center', color: 'var(--fg-mute)', marginBottom: 16 }}>
          <UIcon name={meta.icon} size={24}/>
        </div>
        <h4 style={{ marginBottom: 6 }}>{meta.title} — next in the build queue</h4>
        <p style={{ fontSize: 14, color: 'var(--fg-3)', maxWidth: 420 }}>{meta.desc} We'll design this surface next, iterating page by page from the Dashboard.</p>
        <a href="Design System.html" style={{ marginTop: 18, fontSize: 13, fontWeight: 600, textDecoration: 'none', color: 'var(--accent)' }}>Open the design system reference →</a>
      </div>
    </>
  );
};

Object.assign(window, { Dashboard, Placeholder });
