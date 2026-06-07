// Kraite SYSADMIN console — section stubs. The flagship (Overview) is fully
// built; these five are scaffolded with intent: what each will hold, plus a few
// live headline numbers so the console never reads as empty.

const STUB_META = {
  positions: {
    icon: 'layers', eyebrow: 'RECONCILIATION', title: 'Position sync',
    desc: 'Positions where Kraite’s ledger disagrees with the exchange of record — the ones that need a human.',
    stats: [{ v: '14', k: 'Out of sync' }, { v: '3', k: 'Stuck > 5m' }, { v: '9,598', k: 'In sync' }],
    plans: [
      { icon: 'layers', t: 'Drift table', d: 'Every position whose size, side, or entry differs from the exchange — with the exact delta and which side is stale.' },
      { icon: 'refresh', t: 'Force re-sync', d: 'Re-pull from the venue and reconcile a single position or a whole account in one action.' },
      { icon: 'alert', t: 'Stuck & orphaned', d: 'Orders acked by Kraite but missing on the venue (and the reverse), flagged by age.' },
    ],
  },
  dispatcher: {
    icon: 'steps', eyebrow: 'ORCHESTRATION', title: 'Step dispatcher',
    desc: 'The scheduler that fans out each bot’s lifecycle steps — evaluate, place, adjust, close — across the worker fleet.',
    stats: [{ v: '3,420', k: 'Steps / min' }, { v: '128', k: 'Queue depth' }, { v: '42ms', k: 'p50 dispatch' }],
    plans: [
      { icon: 'steps', t: 'Live step stream', d: 'Every dispatched step with its bot, type, target worker, and outcome — filterable and replayable.' },
      { icon: 'clock', t: 'Queues & backpressure', d: 'Per-queue depth, lag, and retry rate; throttle or drain a queue when a region is hot.' },
      { icon: 'alert', t: 'Failed & dead-letter', d: 'Steps that errored or exhausted retries — inspect the payload and requeue.' },
    ],
  },
  engine: {
    icon: 'bot', eyebrow: 'EXECUTION', title: 'Trading engine',
    desc: 'The bot fleet — every worker process, deploy, and restart across the platform.',
    stats: [{ v: '1,240', k: 'Bots running' }, { v: '3,420', k: 'Orders / min' }, { v: 'v4.2.1', k: 'Current build' }],
    plans: [
      { icon: 'cpu', t: 'Per-worker process table', d: 'Drill into any node — running bots, queue depth, restart or drain a single process.' },
      { icon: 'zap', t: 'Deploys & rollback', d: 'Canary rollout controls, build diff, one-click rollback to the last green build.' },
      { icon: 'rotateCcw', t: 'Restart & drain', d: 'Graceful drain that migrates bots before a host is cycled.' },
    ],
  },
  infra: {
    icon: 'server', eyebrow: 'INFRASTRUCTURE', title: 'Infrastructure',
    desc: 'Kraite servers, regions, and the egress IPs traders allowlist on their exchanges.',
    stats: [{ v: '8', k: 'Worker nodes' }, { v: '5', k: 'Regions' }, { v: '6', k: 'Egress IPs' }],
    plans: [
      { icon: 'globe', t: 'Region & node map', d: 'Capacity, latency, and health by region — Frankfurt, London, New York, Singapore, Tokyo.' },
      { icon: 'shield', t: 'Egress IP allowlist', d: 'The canonical IP set traders add on the exchange side — rotate and announce changes.' },
      { icon: 'database', t: 'Data plane', d: 'Market-data feeds, time-series store, and replication lag.' },
    ],
  },
  venues: {
    icon: 'exchange', eyebrow: 'CONNECTIVITY', title: 'Exchanges',
    desc: 'Supported venues and global connectivity — API status, latency, and error budgets.',
    stats: [{ v: '6', k: 'Venues' }, { v: '1,514', k: 'Connected accounts' }, { v: '1', k: 'Degraded' }],
    plans: [
      { icon: 'wifi', t: 'Venue status board', d: 'Per-exchange uptime, latency history, and rate-limit headroom.' },
      { icon: 'sliders', t: 'Capabilities matrix', d: 'Which products, margin modes, and quote currencies each venue supports.' },
      { icon: 'alert', t: 'Incident routing', d: 'Auto-throttle and trader notifications when a venue degrades.' },
    ],
  },
  revenue: {
    icon: 'wallet', eyebrow: 'FINANCE', title: 'Revenue & billing',
    desc: 'Platform-wide wallets, plans, top-ups, and reconciliation across every trader.',
    stats: [{ v: '$412.8k', k: 'MRR' }, { v: '$1.92M', k: 'Wallet float' }, { v: '$84.2k', k: 'Top-ups today' }],
    plans: [
      { icon: 'trendingUp', t: 'Revenue & MRR', d: 'Plan mix, churn, and net revenue retention over time.' },
      { icon: 'wallet', t: 'Wallet ledger', d: 'Every prepaid USDT wallet, top-up, and monthly debit — fully auditable.' },
      { icon: 'database', t: 'Reconciliation', d: 'Match NOWPayments settlements against credited wallet balances.' },
    ],
  },
  settings: {
    icon: 'sliders', eyebrow: 'CONTROL', title: 'System settings',
    desc: 'Feature flags, maintenance windows, and platform-wide configuration.',
    stats: [{ v: '24', k: 'Feature flags' }, { v: 'Off', k: 'Maintenance mode' }, { v: '3', k: 'Pending changes' }],
    plans: [
      { icon: 'sliders', t: 'Feature flags', d: 'Roll a flag out by cohort, region, or percentage — with an audit trail.' },
      { icon: 'maintenance', t: 'Maintenance mode', d: 'Schedule a window, post a banner, and gate writes platform-wide.' },
      { icon: 'shield', t: 'Risk policy defaults', d: 'Global leverage caps, BSCS thresholds, and circuit-breaker rules.' },
    ],
  },
  sql: {
    icon: 'database', eyebrow: 'DATA', title: 'SQL queries',
    desc: 'A read-only query console over the platform database — for support, audits, and one-off lookups.',
    stats: [{ v: '48', k: 'Saved queries' }, { v: 'read-only', k: 'Default role' }, { v: '1.2s', k: 'Median runtime' }],
    plans: [
      { icon: 'database', t: 'Query editor', d: 'Schema-aware editor with autocomplete, a result grid, and CSV export.' },
      { icon: 'shield', t: 'Guardrails', d: 'Read-only by default, statement timeouts, row caps, and a full audit of every run.' },
      { icon: 'clock', t: 'Saved & scheduled', d: 'Shared saved queries and lightweight scheduled reports.' },
    ],
  },
};

const AdminStub = ({ route }) => {
  const m = STUB_META[route] || STUB_META.engine;
  return (
    <>
      <div className={A_PAGEHEAD}>
        <div>
          <div className={A_EYEBROW}><UIcon name={m.icon} size={13} style={{ width: 13, height: 13 }}/>{m.eyebrow}</div>
          <h1 className={A_H1}>{m.title}</h1>
          <div className={A_SUB}>{m.desc}</div>
        </div>
        <div className="flex items-center gap-2.5 flex-shrink-0">
          <span className="inline-flex items-center gap-2 py-[6px] px-3 rounded-chip border font-mono text-[10.5px] font-bold tracking-[0.08em] uppercase" style={{ color: 'var(--accent)', borderColor: 'color-mix(in srgb, var(--accent) 36%, transparent)', background: 'color-mix(in srgb, var(--accent) 10%, transparent)' }}>
            <span className="w-[6px] h-[6px] rounded-chip bg-accent"/>In build
          </span>
        </div>
      </div>

      {/* live headline stats */}
      <div className="grid grid-cols-3 gap-3 mb-6 max-[560px]:grid-cols-1">
        {m.stats.map(s => (
          <div key={s.k} className="card card--flat px-5 py-4 flex flex-col gap-1">
            <span className="font-mono text-[24px] font-bold tabular-nums tracking-[-0.01em] text-fg-1 leading-none">{s.v}</span>
            <span className="font-mono text-[9.5px] tracking-[0.1em] uppercase text-fg-mute">{s.k}</span>
          </div>
        ))}
      </div>

      {/* planned capabilities */}
      <div className="card card--flat overflow-hidden">
        <ACardHead icon={m.icon} title={'What ' + m.title.toLowerCase() + ' will hold'} accent hint="planned"/>
        <div className="divide-y divide-[color:var(--border-soft)]">
          {m.plans.map(p => (
            <div key={p.t} className="flex items-start gap-4 py-4 px-5 max-[640px]:px-4">
              <span className="w-[36px] h-[36px] rounded-control bg-surface-3 border border-line flex items-center justify-center text-fg-2 flex-shrink-0 mt-0.5"><UIcon name={p.icon} size={17}/></span>
              <div className="flex-1 min-w-0">
                <div className="font-sans font-semibold text-[14px] text-fg-1 leading-tight">{p.t}</div>
                <div className="text-[12.5px] text-fg-3 mt-1 leading-snug">{p.d}</div>
              </div>
              <span className="font-mono text-[9.5px] tracking-[0.08em] uppercase text-fg-faint flex-shrink-0 mt-1.5 max-[480px]:hidden">soon</span>
            </div>
          ))}
        </div>
        <div className="flex items-center gap-2.5 py-3.5 px-5 bg-surface-2 border-t border-line-soft">
          <UIcon name="activity" size={14} style={{ color: 'var(--fg-mute)' }}/>
          <span className="text-[12px] text-fg-mute">Overview is the live page today — say the word and I'll build this section out next.</span>
        </div>
      </div>
    </>
  );
};

Object.assign(window, { AdminStub });
