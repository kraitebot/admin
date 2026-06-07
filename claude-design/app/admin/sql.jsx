// Kraite SYSADMIN console — SQL: a read-only query console against the shared
// database for ad-hoc investigation. Editor → Run → result grid, with guardrails
// (read-only, statement timeout, row cap). Saved queries on the left.

const SAVED_QUERIES = [
  {
    id: 'top-equity', name: 'Top accounts by equity',
    sql: "select a.name, a.plan, a.equity_usd, count(p.id) as open_pos\nfrom accounts a\nleft join positions p on p.account_id = a.id and p.status = 'open'\ngroup by a.id\norder by a.equity_usd desc\nlimit 8;",
    cols: [{ k: 'name', l: 'name' }, { k: 'plan', l: 'plan' }, { k: 'equity_usd', l: 'equity_usd', num: true }, { k: 'open_pos', l: 'open_pos', num: true }],
    rows: [
      ['Renner Capital', 'Quant', '284,910.42', '6'], ['Northwind', 'Quant', '198,440.10', '4'],
      ['Halcyon Desk', 'Pro', '142,030.55', '5'], ['Meridian FX', 'Pro', '96,210.00', '3'],
      ['Sato Trading', 'Pro', '74,880.20', '2'], ['Bridge & Co', 'Pro', '61,540.90', '3'],
      ['Vantage Q', 'Starter', '38,210.44', '1'], ['Okafor Family', 'Starter', '24,980.55', '0'],
    ],
  },
  {
    id: 'drift', name: 'Out-of-sync positions',
    sql: "select p.id, p.market, a.name as account, p.drift_bps, p.age\nfrom positions p\njoin accounts a on a.id = p.account_id\nwhere p.ledger_hash <> p.venue_hash\norder by p.age desc;",
    cols: [{ k: 'id', l: 'id', mono: true }, { k: 'market', l: 'market' }, { k: 'account', l: 'account' }, { k: 'drift', l: 'drift_bps', num: true }, { k: 'age', l: 'age' }],
    rows: [
      ['pos_8f3a21', 'OKX:ARB-PERP', 'Northwind', '142', '7m'], ['pos_b210d4', 'OKX:ARB-PERP', 'Bridge & Co', '88', '5m'],
    ],
  },
  {
    id: 'failed-steps', name: 'Failed dispatch steps · 1h',
    sql: "select s.id, s.bot, s.type, s.worker, s.error\nfrom dispatch_steps s\nwhere s.status = 'failed' and s.ts > now() - interval '1 hour'\norder by s.ts desc;",
    cols: [{ k: 'id', l: 'id', mono: true }, { k: 'bot', l: 'bot' }, { k: 'type', l: 'type' }, { k: 'worker', l: 'worker' }, { k: 'error', l: 'error' }],
    rows: [
      ['stp_44c1', 'ARB-PERP', 'open', 'kr-fra-02', 'venue_reject: margin'], ['stp_44a9', 'OKX:ARB', 'reconcile', 'kr-sgp-02', 'timeout'],
    ],
  },
  {
    id: 'low-wallet', name: 'Wallets below $500',
    sql: "select a.name, w.balance_usdt, a.plan, a.sub_status\nfrom wallets w\njoin accounts a on a.id = w.account_id\nwhere w.balance_usdt < 500\norder by w.balance_usdt asc;",
    cols: [{ k: 'name', l: 'name' }, { k: 'bal', l: 'balance_usdt', num: true }, { k: 'plan', l: 'plan' }, { k: 'status', l: 'sub_status' }],
    rows: [
      ['Okafor Family', '128.40', 'Starter', 'read-only'], ['Vantage Q', '312.00', 'Starter', 'active'],
    ],
  },
];

const ResultGrid = ({ q }) => {
  const grid = '0.7fr ' + q.cols.slice(1).map(() => '1fr').join(' ');
  return (
    <div className="rounded-control border border-line-soft overflow-hidden">
      <div className="grid gap-3 py-2 px-3.5 bg-surface-2 border-b border-line-soft font-mono text-[9.5px] font-semibold tracking-[0.08em] uppercase text-fg-faint" style={{ gridTemplateColumns: grid }}>
        {q.cols.map(c => <span key={c.k} className={c.num ? 'text-right' : ''}>{c.l}</span>)}
      </div>
      {q.rows.map((row, ri) => (
        <div key={ri} className="grid gap-3 py-2 px-3.5 border-b border-line-soft last:border-b-0" style={{ gridTemplateColumns: grid }}>
          {row.map((cell, ci) => {
            const c = q.cols[ci];
            return <span key={ci} className={"text-[12px] truncate " + (c.num ? "font-mono tabular-nums text-right text-fg-1 font-semibold" : c.mono ? "font-mono text-fg-2" : "font-sans text-fg-2")}>{cell}</span>;
          })}
        </div>
      ))}
    </div>
  );
};

const AdminSql = () => {
  const [activeId, setActiveId] = React.useState(SAVED_QUERIES[0].id);
  const [sql, setSql] = React.useState(SAVED_QUERIES[0].sql);
  const [phase, setPhase] = React.useState('done'); // idle | running | done
  const [result, setResult] = React.useState(SAVED_QUERIES[0]);
  const [ms, setMs] = React.useState(1.2);
  const timer = React.useRef([]);
  React.useEffect(() => () => timer.current.forEach(clearTimeout), []);

  const q = SAVED_QUERIES.find(x => x.id === activeId) || SAVED_QUERIES[0];
  const load = (id) => {
    const next = SAVED_QUERIES.find(x => x.id === id);
    setActiveId(id); setSql(next.sql); run(next);
  };
  const run = (target) => {
    const tq = target || q;
    timer.current.forEach(clearTimeout);
    setPhase('running');
    const t = (Math.random() * 1.6 + 0.4);
    timer.current.push(setTimeout(() => { setResult(tq); setMs(t); setPhase('done'); }, 480));
  };

  return (
    <>
      <div className={A_PAGEHEAD}>
        <div>
          <div className={A_EYEBROW}><UIcon name="database" size={13} style={{ width: 13, height: 13 }}/>DATA</div>
          <h1 className={A_H1}>SQL queries</h1>
          <div className={A_SUB}>A read-only query console over the platform database — for support, audits, and lookups.</div>
        </div>
        <div className="flex items-center gap-2 flex-shrink-0 max-[640px]:flex-wrap">
          {['Read-only', 'Timeout 30s', 'Row cap 1,000'].map(g => (
            <span key={g} className="inline-flex items-center gap-1.5 py-[5px] px-2.5 rounded-chip border border-line font-mono text-[10px] font-semibold tracking-[0.04em] text-fg-3"><UIcon name="shield" size={11} style={{ color: 'var(--fg-mute)' }}/>{g}</span>
          ))}
        </div>
      </div>

      <div className="grid grid-cols-[220px_1fr] gap-5 max-[820px]:grid-cols-1">
        {/* saved queries */}
        <div className="card card--flat overflow-hidden self-start max-[820px]:hidden">
          <ACardHead icon="clock" title="Saved" accent/>
          <div className="p-1.5 flex flex-col gap-0.5">
            {SAVED_QUERIES.map(s => {
              const on = s.id === activeId;
              return (
                <button key={s.id} onClick={() => load(s.id)}
                  className={"appearance-none cursor-pointer text-left flex items-center gap-2.5 rounded-[8px] py-2.5 px-3 transition-colors duration-fast border-0 " + (on ? "bg-hover" : "bg-transparent hover:bg-hover")}>
                  <UIcon name="database" size={13} style={{ color: on ? 'var(--accent)' : 'var(--fg-mute)', flexShrink: 0 }}/>
                  <span className={"text-[12px] leading-tight " + (on ? "text-fg-1 font-semibold" : "text-fg-2")}>{s.name}</span>
                </button>
              );
            })}
          </div>
        </div>

        {/* editor + results */}
        <div className="flex flex-col gap-5 min-w-0">
          <div className="card card--flat overflow-hidden">
            <div className="flex items-center justify-between gap-3 py-2.5 px-4 bg-surface-2 border-b border-line-soft">
              <span className="font-mono text-[11px] font-semibold text-fg-2 flex items-center gap-2"><UIcon name="database" size={14} style={{ color: 'var(--accent)' }}/>query editor <span className="text-fg-faint">· kraite-prod (replica)</span></span>
              <button onClick={() => run()} disabled={phase === 'running'}
                className={A_BTN_PRIMARY + " h-[32px] px-3.5 text-[12px] " + (phase === 'running' ? "opacity-60 cursor-wait" : "")}>
                {phase === 'running' ? <><span className="w-[12px] h-[12px] rounded-full border-2 border-[rgba(255,255,255,.35)] border-t-white animate-spin"/>Running…</> : <><UIcon name="play" size={13}/>Run</>}
              </button>
            </div>
            <textarea value={sql} onChange={(e) => setSql(e.target.value)} spellCheck={false}
              className="w-full bg-input text-fg-1 font-mono text-[12.5px] leading-[1.6] p-4 outline-none resize-y min-h-[132px] tracking-[0.01em] [tab-size:2]"
              style={{ borderColor: 'transparent' }}/>
          </div>

          <div className="card card--flat overflow-hidden">
            <ACardHead icon="layers" title="Results" accent
              right={<span className="font-mono text-[10.5px] text-fg-mute tabular-nums">{phase === 'running' ? 'running…' : `${result.rows.length} rows · ${ms.toFixed(1)}s`}</span>}/>
            <div className="p-4">
              {phase === 'running'
                ? <div className="flex items-center justify-center gap-2.5 py-10 text-fg-mute"><span className="w-[15px] h-[15px] rounded-full border-2 border-line-strong border-t-accent animate-spin"/><span className="font-mono text-[12px]">Executing on replica…</span></div>
                : <ResultGrid q={result}/>}
            </div>
          </div>
        </div>
      </div>
    </>
  );
};

Object.assign(window, { AdminSql });
