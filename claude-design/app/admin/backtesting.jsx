// Kraite SYSADMIN console — Backtesting: rows table, AI insights, and the main
// AdminBacktesting workspace that orchestrates Fetch → Verify → Run → Approve.

// ---------- [I] rows table ----------
const RowsTable = ({ rows, totals }) => {
  const [statusFilter, setStatusFilter] = React.useState('all');
  const [dirFilter, setDirFilter] = React.useState('all');
  const counts = rows.reduce((a, r) => { a[r.status] = (a[r.status] || 0) + 1; return a; }, {});
  const view = rows.filter(r => (statusFilter === 'all' || r.status === statusFilter) && (dirFilter === 'all' || r.dir === dirFilter));

  const chip = (active, color, onClick, children) => (
    <button onClick={onClick}
      className="appearance-none cursor-pointer inline-flex items-center gap-1.5 h-[28px] px-2.5 rounded-chip border font-mono text-[10.5px] font-semibold tracking-[0.04em] whitespace-nowrap transition-colors duration-fast"
      style={active
        ? { color: color || 'var(--accent)', borderColor: `color-mix(in srgb, ${color || 'var(--accent)'} 45%, transparent)`, background: `color-mix(in srgb, ${color || 'var(--accent)'} 13%, transparent)` }
        : { color: 'var(--fg-mute)', borderColor: 'var(--border)', background: 'transparent' }}>
      {children}
    </button>
  );

  const maeColor = (m) => m >= 12 ? 'var(--pnl-down-fg)' : m >= 7 ? 'var(--warn)' : 'var(--fg-2)';
  const COLS = "grid-cols-[64px_136px_1fr_56px_136px_1fr_64px_112px]";

  return (
    <div className="card card--flat overflow-hidden">
      <ACardHead icon="database" title="Per-simulation rows" accent
        right={<span className="font-mono text-[10.5px] text-fg-mute tabular-nums">{view.length} of {rows.length}{totals.rows_truncated ? ` · ${totals.max_rows} cap` : ''}</span>}/>

      {/* filter chips */}
      <div className="flex items-center gap-1.5 flex-wrap py-2.5 px-4 border-b border-line-soft bg-surface-2/40">
        <span className="font-mono text-[9px] font-bold tracking-[0.1em] uppercase text-fg-3 mr-1">Status</span>
        {chip(statusFilter === 'all', null, () => setStatusFilter('all'), <>All</>)}
        {chip(statusFilter === 'stopped_out', 'var(--pnl-down-fg)', () => setStatusFilter('stopped_out'), <><UIcon name="alert" size={12}/>Stopped out · {counts.stopped_out || 0}</>)}
        {chip(statusFilter === 'reboundable', BT_TEAL, () => setStatusFilter('reboundable'), <>Reboundable · {counts.reboundable || 0}</>)}
        {chip(statusFilter === 'tp_market_only', 'var(--pnl-up-fg)', () => setStatusFilter('tp_market_only'), <>TP market · {counts.tp_market_only || 0}</>)}
        <span className="w-px h-4 bg-line-soft mx-1.5"/>
        <span className="font-mono text-[9px] font-bold tracking-[0.1em] uppercase text-fg-3 mr-1">Side</span>
        {chip(dirFilter === 'all', null, () => setDirFilter('all'), <>Both</>)}
        {chip(dirFilter === 'LONG', 'var(--pnl-up-fg)', () => setDirFilter('LONG'), <>Long</>)}
        {chip(dirFilter === 'SHORT', 'var(--pnl-down-fg)', () => setDirFilter('SHORT'), <>Short</>)}
      </div>

      {/* header */}
      <div className={"hidden lg:grid " + COLS + " gap-2 py-2 px-4 border-b border-line-soft bg-surface-2 font-mono text-[9px] font-semibold tracking-[0.08em] uppercase text-fg-3"}>
        <span>Side</span><span>Start candle</span><span>Entry ref</span><span>Rung</span><span>Last touch</span><span>TP price</span><span>MAE %</span><span>Status</span>
      </div>

      {/* rows */}
      <div className="max-h-[420px] overflow-y-auto">
        {view.map((r, i) => {
          const sm = STATUS_META[r.status];
          const long = r.dir === 'LONG';
          return (
            <div key={i} className={"grid " + COLS + " gap-2 items-center py-2.5 px-4 border-b border-line-soft last:border-b-0 max-lg:grid-cols-2 max-lg:gap-y-1.5"}>
              <span className="flex"><span className="font-mono text-[9.5px] font-bold tracking-[0.05em] py-[2px] px-[7px] rounded-chip" style={{ color: long ? 'var(--pnl-up-fg)' : 'var(--pnl-down-fg)', background: `color-mix(in srgb, ${long ? 'var(--pnl-up-fg)' : 'var(--pnl-down-fg)'} 13%, transparent)` }}>{r.dir}</span></span>
              <span className="font-mono text-[11px] tabular-nums text-fg-2">{r.start}</span>
              <span className="font-mono text-[11.5px] tabular-nums text-fg-1">{r.entry}</span>
              <span className="font-mono text-[11.5px] font-semibold tabular-nums" style={{ color: r.rung >= 4 ? 'var(--pnl-down-fg)' : r.rung === 3 ? 'var(--warn)' : 'var(--fg-2)' }}>{r.rung}</span>
              <span className="font-mono text-[11px] tabular-nums text-fg-mute">{r.touch}</span>
              <span className="font-mono text-[11.5px] tabular-nums text-fg-2">{r.tp}</span>
              <span className="font-mono text-[11.5px] font-semibold tabular-nums" style={{ color: maeColor(r.mae) }}>{r.mae.toFixed(1)}</span>
              <span className="flex"><span className="inline-flex items-center gap-1.5 font-mono text-[9.5px] font-bold tracking-[0.04em] uppercase" style={{ color: sm.color }}><span className="w-[6px] h-[6px] rounded-[2px]" style={{ background: sm.color, opacity: sm.striped ? 0.6 : 1 }}/>{sm.short}</span></span>
            </div>
          );
        })}
        {!view.length && <div className="py-8 text-center text-[12px] text-fg-mute">No rows match this filter.</div>}
      </div>
    </div>
  );
};

// ---------- markdown renderer (minimal: ## / ### headings, lists, inline) ----------
const mdInline = (text) => {
  const parts = text.split(/(`[^`]+`|\*\*[^*]+\*\*|\*[^*]+\*|_[^_]+_)/g);
  return parts.map((p, i) => {
    if (/^`[^`]+`$/.test(p)) return <code key={i} className="font-mono text-[12px] px-1 py-[1px] rounded-[4px] bg-surface-3 text-accent">{p.slice(1, -1)}</code>;
    if (/^\*\*[^*]+\*\*$/.test(p)) return <strong key={i} className="font-bold text-fg-1">{p.slice(2, -2)}</strong>;
    if (/^\*[^*]+\*$/.test(p)) return <em key={i} className="italic text-fg-2">{p.slice(1, -1)}</em>;
    if (/^_[^_]+_$/.test(p)) return <em key={i} className="italic text-fg-mute">{p.slice(1, -1)}</em>;
    return p;
  });
};
const Markdown = ({ src }) => {
  const lines = src.split('\n');
  const out = [];
  let list = null;
  const flush = () => { if (list) { out.push(<ul key={'l' + out.length} className="flex flex-col gap-1.5 my-1.5 pl-1">{list}</ul>); list = null; } };
  lines.forEach((ln, i) => {
    if (/^###\s+/.test(ln)) { flush(); out.push(<h5 key={i} className="font-mono text-[10.5px] font-bold tracking-[0.1em] uppercase text-fg-mute mt-4 mb-1">{mdInline(ln.replace(/^###\s+/, ''))}</h5>); }
    else if (/^##\s+/.test(ln)) { flush(); out.push(<h4 key={i} className="font-sans font-bold text-[15px] text-fg-1 mt-4 first:mt-0 mb-1.5 pb-1.5 border-b border-line-soft">{mdInline(ln.replace(/^##\s+/, ''))}</h4>); }
    else if (/^\d+\.\s+/.test(ln)) { const m = ln.match(/^(\d+)\.\s+(.*)$/); list = list || []; list.push(<li key={i} className="flex gap-2.5 text-[13px] text-fg-2 leading-relaxed"><span className="font-mono text-[11px] font-bold text-accent flex-shrink-0 mt-[2px]">{m[1]}</span><span>{mdInline(m[2])}</span></li>); }
    else if (/^-\s+/.test(ln)) { list = list || []; list.push(<li key={i} className="flex gap-2.5 text-[13px] text-fg-2 leading-relaxed"><span className="text-accent flex-shrink-0">·</span><span>{mdInline(ln.replace(/^-\s+/, ''))}</span></li>); }
    else if (ln.trim() === '') { flush(); }
    else { flush(); out.push(<p key={i} className="text-[13px] text-fg-2 leading-relaxed my-1.5">{mdInline(ln)}</p>); }
  });
  flush();
  return <div>{out}</div>;
};

// ---------- [J] AI insights panel ----------
const AIPanel = ({ visible, loading, text, model, onRun }) => {
  if (!visible) return null;
  return (
    <div className="card card--flat overflow-hidden">
      <ACardHead icon="zap" title="AI insights" accent
        right={text ? <span className="font-mono text-[10px] text-fg-faint">via {model}</span> : <span className="font-mono text-[10px] text-fg-faint">advisory · applies no changes</span>}/>
      {!text && !loading && (
        <div className="flex items-center gap-3 p-4 max-[520px]:flex-col max-[520px]:items-stretch">
          <span className="text-[12.5px] text-fg-mute flex-1">Ask the model to interpret this run — diagnosis plus three single-variable tests to try next.</span>
          <button onClick={onRun} className={A_BTN_PRIMARY + " justify-center flex-shrink-0"}><UIcon name="zap" size={15}/>Get AI insights</button>
        </div>
      )}
      {loading && (
        <div className="flex items-center gap-2.5 p-5">
          <span className="w-[15px] h-[15px] rounded-full border-2 border-accent border-t-transparent animate-spin"/>
          <span className="font-mono text-[12px] text-fg-mute">Analysing ladder behaviour…</span>
        </div>
      )}
      {text && <div className="p-5"><Markdown src={text}/></div>}
    </div>
  );
};

// ---------- empty state (no run yet) ----------
const RunEmpty = ({ selected }) => (
  <div className="card card--flat flex flex-col items-center justify-center text-center py-16 px-6">
    <span className="w-[52px] h-[52px] rounded-control bg-surface-3 flex items-center justify-center mb-4"><UIcon name="projections" size={24} style={{ color: 'var(--fg-mute)' }}/></span>
    <h4 className="font-sans font-semibold text-[15px] text-fg-1 mb-1.5">{selected ? 'Run a backtest to see results' : 'Select a token to begin'}</h4>
    <p className="text-[12.5px] text-fg-mute max-w-[340px] leading-snug">{selected ? <>Fetch history, then <span className="font-semibold text-fg-2">Run backtest</span> — grade, pass rate, regime stability, and per-trade rows appear here.</> : 'Pick a symbol from the left rail. Its config pre-fills and the actions unlock.'}</p>
  </div>
);

// ---------- confirm modal ----------
const ConfirmModal = ({ kind, token, onConfirm, onCancel }) => {
  if (!kind) return null;
  const approve = kind === 'approve';
  const c = approve ? 'var(--pnl-up-fg)' : 'var(--pnl-down-fg)';
  return (
    <div className="fixed inset-0 z-[80] flex items-center justify-center p-4 bg-black/55 animate-dd-in" onMouseDown={onCancel}>
      <div className="w-[420px] max-w-full bg-surface border rounded-control shadow-3 p-5" style={{ borderColor: `color-mix(in srgb, ${c} 40%, var(--border))` }} onMouseDown={e => e.stopPropagation()}>
        <div className="flex items-center gap-2.5 mb-2.5">
          <span className="w-[32px] h-[32px] rounded-control flex items-center justify-center flex-shrink-0" style={{ background: `color-mix(in srgb, ${c} 15%, transparent)`, color: c }}><UIcon name={approve ? 'check' : 'alert'} size={17}/></span>
          <h4 className="font-sans font-bold text-[15px] text-fg-1">{approve ? 'Approve' : 'Reject'} {token.token} {token.quote}?</h4>
        </div>
        <p className="text-[12.5px] text-fg-3 leading-snug mb-4">
          {approve
            ? <>Enables <span className="font-semibold text-fg-1">{token.token}/{token.quote}</span> for live trading and pushes the tested gap / TP / SL config to the engine — and to sibling exchanges.</>
            : <>Flags <span className="font-semibold text-fg-1">{token.token}/{token.quote}</span> as rejected. No config is pushed; the live engine is untouched.</>}
        </p>
        <div className="flex items-center gap-2 justify-end">
          <button onClick={onCancel} className={A_BTN_SECONDARY}>Cancel</button>
          <button onClick={onConfirm} className="appearance-none cursor-pointer inline-flex items-center justify-center gap-2 h-[36px] px-4 rounded-control font-sans text-[13px] font-bold text-white border-0 transition-colors duration-fast" style={{ background: c }}>
            <UIcon name={approve ? 'check' : 'power'} size={15}/>{approve ? 'Approve & push' : 'Reject'}
          </button>
        </div>
      </div>
    </div>
  );
};

// ---------- toast ----------
const Toast = ({ msg }) => {
  if (!msg) return null;
  const c = msg.kind === 'error' ? 'var(--danger)' : msg.kind === 'reject' ? 'var(--pnl-down-fg)' : 'var(--pnl-up-fg)';
  return (
    <div className="fixed bottom-6 left-1/2 -translate-x-1/2 z-[90] flex items-center gap-2.5 py-2.5 px-4 rounded-control bg-surface border shadow-3 animate-dd-in" style={{ borderColor: `color-mix(in srgb, ${c} 45%, var(--border))` }}>
      <UIcon name={msg.kind === 'error' ? 'alert' : msg.kind === 'reject' ? 'power' : 'check'} size={16} style={{ color: c }}/>
      <span className="font-sans text-[12.5px] font-semibold text-fg-1">{msg.text}</span>
    </div>
  );
};

// ============================ MAIN ============================
const AdminBacktesting = () => {
  const [selId, setSelId] = React.useState(null);
  const [filters, setFilters] = React.useState({ top100: false, approved: false, notConcluded: false });
  const setFilter = (k, v) => setFilters(f => ({ ...f, [k]: v }));
  const visibleSymbols = BT_SYMBOLS.filter(s => {
    if (filters.top100 && s.rank > 100) return false;
    // status filters combine as a union: if either is on, the token must match one of them
    if (filters.approved || filters.notConcluded) {
      const ok = (filters.approved && s.status === 'approved') || (filters.notConcluded && s.status == null);
      if (!ok) return false;
    }
    return true;
  });
  const [tf, setTf] = React.useState('5m');
  const [cfgOpen, setCfgOpen] = React.useState(false);   // Config card collapsed by default
  const [cfg, setCfg] = React.useState({});
  const [cov, setCov] = React.useState(null);
  const [report, setReport] = React.useState(null);
  const [busy, setBusy] = React.useState(null);        // 'fetch' | 'verify' | 'run'
  const [result, setResult] = React.useState(null);    // null until run
  const [reviews, setReviews] = React.useState({});    // id -> status override
  const [ai, setAi] = React.useState({ loading: false, text: null });
  const [confirm, setConfirm] = React.useState(null);
  const [toast, setToast] = React.useState(null);
  const timers = React.useRef([]);

  React.useEffect(() => () => timers.current.forEach(clearTimeout), []);
  const later = (fn, ms) => { const id = setTimeout(fn, ms); timers.current.push(id); };
  const flashToast = (text, kind) => { setToast({ text, kind }); later(() => setToast(null), 2600); };

  const selected = BT_SYMBOLS.find(s => s.id === selId) || null;
  const status = selected ? (reviews[selected.id] !== undefined ? reviews[selected.id] : selected.status) : null;

  const selectToken = (s) => {
    setSelId(s.id);
    setCfg({ since: '', candles_back: '', tp: BT_DEFAULTS.tp_percent, sl: BT_DEFAULTS.sl_percent, gapL: s.gapL, gapS: s.gapS, limit_hit: '', max_rows: '500', taapi: true, max_months: '' });
    setCov(null); setReport(null); setResult(null); setAi({ loading: false, text: null });
  };
  const setF = (k, v) => setCfg(c => ({ ...c, [k]: v }));

  const doFetch = () => { setBusy('fetch'); later(() => { setReport(BT_FETCH); setCov(BT_COVERAGE); setBusy(null); }, 1400); };
  const doVerify = () => { setBusy('verify'); later(() => { setCov(BT_COVERAGE); setBusy(null); }, 700); };
  const doRun = () => { setBusy('run'); setResult(null); setAi({ loading: false, text: null }); later(() => { setResult({ totals: BT_TOTALS, verdict: BT_VERDICT, rungs: BT_RUNGS, regimes: BT_REGIMES, rows: BT_ROWS, meta: BT_META }); setBusy(null); }, 1600); };
  const doAI = () => { setAi({ loading: true, text: null }); later(() => setAi({ loading: false, text: BT_AI_MARKDOWN }), 1800); };

  const onConfirm = () => {
    const approve = confirm === 'approve';
    setReviews(r => ({ ...r, [selected.id]: approve ? 'approved' : 'rejected' }));
    setConfirm(null);
    flashToast(approve ? 'Approved — config live' : 'Rejected — no config pushed', approve ? 'ok' : 'reject');
  };

  const meta = result && result.meta;

  return (
    <>
      <div className={A_PAGEHEAD}>
        <div>
          <div className={A_EYEBROW}><UIcon name="projections" size={13} style={{ width: 13, height: 13 }}/>SYSADMIN</div>
          <h1 className={A_H1}>Backtesting</h1>
          <div className={A_SUB}>Pull history, run the martingale-ladder simulation, read the grade, and approve a token's config for the live engine.</div>
        </div>
      </div>

      <div className="grid grid-cols-[380px_1fr] gap-5 items-start max-[1080px]:grid-cols-1">
        {/* ===================== LEFT RAIL ===================== */}
        <div className="flex flex-col gap-4 max-[1080px]:contents">
          <div className="flex flex-col gap-4 max-[1080px]:order-1 lg:sticky lg:top-2">
            {/* [A] selection — overflow-visible so the token dropdown can escape the card clip */}
            <div className="card card--flat !overflow-visible relative z-20">
              <ACardHead icon="coins" title="Token" accent/>
              <div className="p-4 flex flex-col gap-3">
                <TokenSelector symbols={visibleSymbols} selected={selected} onSelect={selectToken}/>
                <div className="flex flex-col gap-2 -mt-0.5">
                  <BtCheck label="Top 100" checked={filters.top100} onChange={v => setFilter('top100', v)}
                    count={BT_SYMBOLS.filter(s => s.rank <= 100).length}/>
                  <BtCheck label="Only approved" checked={filters.approved} onChange={v => setFilter('approved', v)}
                    count={BT_SYMBOLS.filter(s => s.status === 'approved').length}/>
                  <BtCheck label="Not concluded" checked={filters.notConcluded} onChange={v => setFilter('notConcluded', v)}
                    count={BT_SYMBOLS.filter(s => s.status == null).length}/>
                </div>
                {selected && <TokenHeader s={selected} status={status}/>}
                <BtField label="Timeframe">
                  <div className="flex gap-1.5">
                    {BT_TIMEFRAMES.map(t => (
                      <button key={t} onClick={() => setTf(t)} disabled={!selected}
                        className="flex-1 h-[32px] rounded-control font-mono text-[11.5px] font-semibold border transition-colors duration-fast disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer"
                        style={tf === t ? { color: 'var(--accent-on)', background: 'var(--accent)', borderColor: 'transparent' } : { color: 'var(--fg-2)', background: 'var(--bg-elev-2)', borderColor: 'var(--border)' }}>{t}</button>
                    ))}
                  </div>
                </BtField>
              </div>
            </div>

            {/* [B] config — collapsible (slide down/up) */}
            <div className={"card card--flat overflow-hidden transition-opacity " + (selected ? '' : 'opacity-50 pointer-events-none')}>
              <ACardHead icon="sliders" title="Config" accent collapsed={!cfgOpen}
                onClick={() => setCfgOpen(o => !o)}
                right={<span className="flex items-center gap-2.5">
                  {!cfgOpen && <span className="font-mono text-[10.5px] text-fg-mute tracking-[0.02em]">{selected ? 'ladder parameters' : 'select a token'}</span>}
                  <UIcon name="chevronDown" size={16} style={{ color: 'var(--fg-3)', transition: 'transform .28s cubic-bezier(.4,0,.2,1)', transform: cfgOpen ? 'rotate(180deg)' : 'none' }}/>
                </span>}/>
              <div className="grid transition-[grid-template-rows] duration-[280ms] ease-[cubic-bezier(.4,0,.2,1)]"
                style={{ gridTemplateRows: cfgOpen ? '1fr' : '0fr' }}>
                <div className="overflow-hidden min-h-0">
                  <div className="p-4 flex flex-col gap-4">
                <div className="flex flex-col gap-3">
                  <span className="font-mono text-[9px] font-bold tracking-[0.12em] uppercase text-fg-3">Window</span>
                  <div className="grid grid-cols-2 gap-3">
                    <BtField label="Since"><input type="date" className={BT_INPUT} value={cfg.since || ''} onChange={e => setF('since', e.target.value)} disabled={!selected}/></BtField>
                    <BtField label="Candles back"><input type="number" placeholder="all" className={BT_INPUT} value={cfg.candles_back || ''} onChange={e => setF('candles_back', e.target.value)} disabled={!selected}/></BtField>
                  </div>
                  <span className={BT_HINT}>Leave both empty to walk all available history. A date overrides the candle count.</span>
                </div>

                <div className="flex flex-col gap-3 pt-3 border-t border-line-soft">
                  <span className="font-mono text-[9px] font-bold tracking-[0.12em] uppercase text-fg-3">Strategy</span>
                  <div className="grid grid-cols-2 gap-3">
                    <BtField label="Take-profit %"><input type="number" className={BT_INPUT} value={cfg.tp || ''} onChange={e => setF('tp', e.target.value)} disabled={!selected}/></BtField>
                    <BtField label="Stop-loss %"><input type="number" className={BT_INPUT} value={cfg.sl || ''} onChange={e => setF('sl', e.target.value)} disabled={!selected}/></BtField>
                    <BtField label="Gap long %"><input type="number" className={BT_INPUT} value={cfg.gapL || ''} onChange={e => setF('gapL', e.target.value)} disabled={!selected}/></BtField>
                    <BtField label="Gap short %"><input type="number" className={BT_INPUT} value={cfg.gapS || ''} onChange={e => setF('gapS', e.target.value)} disabled={!selected}/></BtField>
                    <BtField label="Limit hit ≥" hint="filter rung"><input type="number" placeholder="any" className={BT_INPUT} value={cfg.limit_hit || ''} onChange={e => setF('limit_hit', e.target.value)} disabled={!selected}/></BtField>
                    <BtField label="Max rows"><input type="number" className={BT_INPUT} value={cfg.max_rows || ''} onChange={e => setF('max_rows', e.target.value)} disabled={!selected}/></BtField>
                  </div>
                </div>

                <div className="flex flex-col gap-2 pt-3 border-t border-line-soft">
                  <span className="font-mono text-[9px] font-bold tracking-[0.12em] uppercase text-fg-3 mb-0.5">Fixed envelope</span>
                  <BtStatic label="Margin" value="5,000"/>
                  <BtStatic label="Leverage" value="20×"/>
                  <BtStatic label="Limit orders" value="4"/>
                  <BtStatic label="Multipliers" value="[2,2,2,2]"/>
                  <span className={BT_HINT + " mt-1"}>Sizing is fixed — backtests measure price geometry (does WAP recover to TP?), not capital allocation.</span>
                </div>
                  </div>
                </div>
              </div>
            </div>

            {/* [C] actions */}
            <div className="card card--flat p-4 flex flex-col gap-2.5">
              <BtActionBtn variant="secondary" icon="download" label="Fetch candles" loading={busy === 'fetch'} loadingText="Fetching history…" onClick={doFetch} disabled={!selected || busy}/>
              <BtActionBtn variant="ghost" icon="check" label="Verify coverage" loading={busy === 'verify'} loadingText="Auditing…" onClick={doVerify} disabled={!selected || busy}/>
              <BtActionBtn variant="primary" icon="play" label="Run backtest" loading={busy === 'run'} loadingText="Simulating ladder…" onClick={doRun} disabled={!selected || busy}/>
              <span className={BT_HINT + " text-center"}>Fetch and Run can take a few seconds.</span>
            </div>

            {/* [G] approval */}
            {selected && (
              <div className="card card--flat overflow-hidden" style={{ borderColor: result ? 'color-mix(in srgb, var(--accent) 30%, var(--border))' : undefined }}>
                <ACardHead icon="shield" title="Decision" accent right={<BtPill color={REVIEW_META[status == null ? 'null' : status].color} dot>{REVIEW_META[status == null ? 'null' : status].label}</BtPill>}/>
                <div className="p-4 flex flex-col gap-2.5">
                  {!result && <span className={BT_HINT}>Run a backtest before approving — the decision pushes the tested config live.</span>}
                  <div className="flex gap-2">
                    <button onClick={() => setConfirm('approve')} disabled={!result || status === 'approved'}
                      className="flex-1 appearance-none cursor-pointer inline-flex items-center justify-center gap-2 h-[38px] rounded-control font-sans text-[13px] font-bold text-white border-0 transition-colors duration-fast disabled:opacity-40 disabled:cursor-not-allowed" style={{ background: 'var(--pnl-up-fg)' }}>
                      <UIcon name="check" size={15}/>Approve
                    </button>
                    <button onClick={() => setConfirm('reject')} disabled={!result || status === 'rejected'}
                      className={A_BTN_SECONDARY + " h-[38px] flex-1 justify-center disabled:opacity-40 disabled:cursor-not-allowed"} style={{ color: 'var(--pnl-down-fg)', borderColor: 'color-mix(in srgb, var(--pnl-down-fg) 40%, transparent)' }}>
                      <UIcon name="power" size={15}/>Reject
                    </button>
                  </div>
                </div>
              </div>
            )}
          </div>
        </div>

        {/* ===================== RIGHT PANEL ===================== */}
        <div className="flex flex-col gap-4 min-w-0 max-[1080px]:order-2">
          <CoverageStrip cov={cov}/>
          <FetchReport report={report}/>

          {!result && busy !== 'run' && <RunEmpty selected={selected}/>}
          {busy === 'run' && (
            <div className="card card--flat flex items-center justify-center gap-3 py-16">
              <span className="w-[18px] h-[18px] rounded-full border-2 border-accent border-t-transparent animate-spin"/>
              <span className="font-mono text-[13px] text-fg-mute">Simulating ladder over history…</span>
            </div>
          )}

          {result && (
            <>
              <GradeHero totals={result.totals}/>
              {result.totals.rows_truncated && (
                <div className="card card--flat flex items-center gap-2.5 py-2.5 px-4" style={{ background: 'color-mix(in srgb, var(--info) 7%, transparent)', borderColor: 'color-mix(in srgb, var(--info) 28%, var(--border))' }}>
                  <UIcon name="alert" size={14} style={{ color: 'var(--info)' }}/>
                  <span className="text-[12px] text-fg-2">Showing first {result.totals.max_rows} rows of a larger set.</span>
                </div>
              )}
              <div className="grid grid-cols-3 gap-3 max-[640px]:grid-cols-2">
                <StatMini label="Pass rate" value={result.totals.pass_rate.toFixed(1) + '%'} color="var(--pnl-up-fg)" sub="resolved sims" tip="Resolved sims that closed in profit — TP hit or WAP rebound."/>
                <StatMini label="Max MAE %" value={result.totals.max_mae_pct.toFixed(1)} color="var(--pnl-down-fg)" sub="liq-risk proxy" warn tip="Worst adverse excursion before resolving — a liquidation-risk proxy."/>
                <StatMini label="Avg rung depth" value={result.totals.avg_rung_depth.toFixed(1)} sub="of 4 rungs" tip="Average ladder rung reached before close, out of 4."/>
                <StatMini label="Avg → profit" value={result.totals.avg_candles_profit + ' c'} sub="candles" tip="Mean candles from entry to a profitable close."/>
                <StatMini label="p95 → profit" value={result.totals.p95_candles_profit + ' c'} sub="candles" tip="95th-percentile candles to profit — the slow tail."/>
                <StatMini label="Sample size" value={result.totals.sample_size.toLocaleString()} sub={result.totals.sample_size >= result.totals.sample_size_threshold ? 'sims' : 'below threshold'} warn={result.totals.sample_size < result.totals.sample_size_threshold} tip="Resolved sims behind these stats — below threshold means low confidence."/>
              </div>

              <div className="grid grid-cols-2 gap-4 max-[760px]:grid-cols-1">
                <VerdictBar verdict={result.verdict}/>
                <RungChart rungs={result.rungs}/>
              </div>

              {/* config echo */}
              <div className="flex items-center gap-x-4 gap-y-1 flex-wrap py-2.5 px-4 card card--flat">
                <span className="font-mono text-[9px] font-bold tracking-[0.1em] uppercase text-fg-3 inline-flex items-center gap-[5px]">Config<BtHelp tip="The exact ladder parameters this run used."/></span>
                {[['TP', meta.tp + '%'], ['SL', meta.sl + '%'], ['Gap L', meta.gapL + '%'], ['Gap S', meta.gapS + '%'], ['Lev', meta.leverage], ['Mult', meta.mult], ['Window', meta.window]].map(([k, v]) => (
                  <span key={k} className="font-mono text-[10.5px] text-fg-mute"><span className="text-fg-3">{k}</span> <span className="font-semibold text-fg-2 tabular-nums">{v}</span></span>
                ))}
              </div>

              <RegimeBand regimes={result.regimes}/>
              <RowsTable rows={result.rows} totals={result.totals}/>
              <AIPanel visible={!!result} loading={ai.loading} text={ai.text} model={BT_AI_MODEL} onRun={doAI}/>
            </>
          )}
        </div>
      </div>

      <ConfirmModal kind={confirm} token={selected || {}} onConfirm={onConfirm} onCancel={() => setConfirm(null)}/>
      <Toast msg={toast}/>
    </>
  );
};

Object.assign(window, { RowsTable, Markdown, AIPanel, RunEmpty, ConfirmModal, Toast, AdminBacktesting });
