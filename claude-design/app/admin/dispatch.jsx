// Kraite SYSADMIN console — Dispatch: the step-dispatcher. The job-orchestration
// engine that runs every position's lifecycle as chained, recoverable steps across
// queues. Surfaces saturation, per-queue depth/lag, throughput, and a live stream
// of dispatched steps (with stalled / dead-letter visibility).

const Q_STATE = { healthy: 'var(--pnl-up-fg)', degraded: 'var(--warn)', idle: 'var(--fg-mute)' };
const QueueRow = ({ q }) => {
  const c = Q_STATE[q.state];
  const sat = Math.min(100, Math.round((q.depth / 80) * 100));
  return (
    <div className="grid grid-cols-[minmax(110px,1fr)_64px_64px_80px_90px] items-center gap-3 py-3 px-5 border-b border-line-soft last:border-b-0 max-[640px]:px-4"
      style={q.state === 'degraded' ? { background: 'color-mix(in srgb, var(--warn) 6%, transparent)' } : undefined}>
      <span className="flex items-center gap-2.5 min-w-0">
        <span className="w-[7px] h-[7px] rounded-chip flex-shrink-0" style={{ background: c }}/>
        <span className="font-mono text-[12.5px] font-semibold text-fg-1">{q.name}</span>
      </span>
      <span className="font-mono text-[12px] font-semibold tabular-nums text-fg-1 text-right">{q.depth}</span>
      <span className="font-mono text-[12px] tabular-nums text-right" style={{ color: q.lag >= 120 ? 'var(--warn)' : 'var(--fg-2)' }}>{q.lag}ms</span>
      <span className="font-mono text-[12px] tabular-nums text-fg-2 text-right">{q.rate}<span className="text-fg-mute text-[9px]">/m</span></span>
      <div className="h-[5px] rounded-chip bg-surface-3 overflow-hidden"><div className="h-full rounded-chip" style={{ width: sat + '%', background: c }}/></div>
    </div>
  );
};

const ST_STATE = {
  ok:      { t: 'ok',      c: 'var(--pnl-up-fg)' },
  retry:   { t: 'retry',   c: 'var(--warn)' },
  stalled: { t: 'stalled', c: 'var(--warn)' },
  failed:  { t: 'failed',  c: 'var(--danger)' },
};
const StepRow = ({ s }) => {
  const m = ST_STATE[s.status];
  const bad = s.status === 'failed' || s.status === 'stalled';
  return (
    <div className="grid grid-cols-[56px_minmax(150px,1.4fr)_100px_120px_88px] items-center gap-3 py-2.5 px-5 border-b border-line-soft last:border-b-0 max-[820px]:grid-cols-[56px_minmax(140px,1.4fr)_100px_88px] max-[640px]:px-4"
      style={bad ? { background: `color-mix(in srgb, ${m.c} 7%, transparent)` } : undefined}>
      <span className="font-mono text-[10.5px] tabular-nums text-fg-mute">{s.t}</span>
      <div className="flex flex-col leading-[1.2] min-w-0">
        <span className="font-mono text-[12px] font-semibold text-fg-1 whitespace-nowrap">{s.bot}</span>
        <span className="font-sans text-[10px] text-fg-mute whitespace-nowrap truncate">{s.client}</span>
      </div>
      <span className="font-mono text-[10.5px] font-semibold tracking-[0.04em] uppercase" style={{ color: 'var(--accent)' }}>{s.type}</span>
      <span className="font-mono text-[11px] tabular-nums text-fg-3 whitespace-nowrap max-[820px]:hidden">{s.worker}</span>
      <span className="flex justify-end"><span className="inline-flex items-center gap-1.5 font-mono text-[10px] font-bold tracking-[0.06em] uppercase" style={{ color: m.c }}><span className={"w-[6px] h-[6px] rounded-chip" + (bad ? " animate-pulse-soft" : "")} style={{ background: m.c }}/>{m.t}</span></span>
    </div>
  );
};

const AdminDispatch = () => {
  const totalDepth = DISPATCH_QUEUES.reduce((a, q) => a + q.depth, 0);
  const sat = 68;
  return (
    <>
      <div className={A_PAGEHEAD}>
        <div>
          <div className={A_EYEBROW}><UIcon name="steps" size={13} style={{ width: 13, height: 13 }}/>ORCHESTRATION</div>
          <h1 className={A_H1}>Step dispatcher</h1>
          <div className={A_SUB}>The scheduler fanning out each bot's lifecycle steps across the worker fleet.</div>
        </div>
        <div className="flex items-center gap-3 flex-shrink-0">
          <span className="inline-flex items-center gap-2 py-[6px] px-3 rounded-chip border font-mono text-[11px] font-bold tracking-[0.06em] uppercase" style={{ color: 'var(--pnl-up-fg)', borderColor: 'color-mix(in srgb, var(--pnl-up-fg) 38%, transparent)', background: 'color-mix(in srgb, var(--pnl-up-fg) 12%, transparent)' }}><span className="w-2 h-2 rounded-chip bg-pnlup animate-pulse-soft"/>Flowing</span>
        </div>
      </div>

      {/* KPI strip */}
      <div className="grid grid-cols-5 gap-3 mb-6 max-[900px]:grid-cols-3 max-[560px]:grid-cols-2">
        <StatTile icon="activity" label="Steps / min" value="3,420" spark={[3010,3180,3120,3240,3360,3300,3420]}/>
        <StatTile icon="steps" label="Queue depth" value={String(totalDepth)} sub="IN FLIGHT"/>
        <StatTile icon="clock" label="p50 dispatch" value="42ms" sub="p99 · 180ms"/>
        <StatTile icon="rotateCcw" label="Stalled" value="3" sub="RETRYING"/>
        <StatTile icon="alert" label="Dead-letter" value="1" sub="NEEDS REQUEUE"/>
      </div>

      {/* queues + saturation */}
      <div className="grid grid-cols-[1.5fr_1fr] gap-5 mb-5 max-[900px]:grid-cols-1">
        <div className="card card--flat overflow-hidden">
          <ACardHead icon="steps" title="Queues" accent hint="depth · lag · rate"/>
          <div className="hidden md:grid grid-cols-[minmax(110px,1fr)_64px_64px_80px_90px] gap-3 py-2 px-5 border-b border-line-soft font-mono text-[9px] font-semibold tracking-[0.1em] uppercase text-fg-faint">
            <span>Queue</span><span className="text-right">Depth</span><span className="text-right">Lag</span><span className="text-right">Rate</span><span>Saturation</span>
          </div>
          {DISPATCH_QUEUES.map(q => <QueueRow key={q.id} q={q}/>)}
        </div>
        <div className="card card--flat overflow-hidden">
          <ACardHead icon="cpu" title="Dispatcher saturation" accent/>
          <div className="p-5 flex flex-col gap-4">
            <div className="flex items-end justify-between">
              <span className="font-mono text-[40px] font-bold tabular-nums leading-none text-fg-1">{sat}<span className="text-[20px] text-fg-mute">%</span></span>
              <span className="font-mono text-[10.5px] tracking-[0.04em] text-fg-mute mb-1">of capacity</span>
            </div>
            <div className="h-[8px] rounded-chip bg-surface-3 overflow-hidden"><div className="h-full rounded-chip bg-accent" style={{ width: sat + '%' }}/></div>
            <div className="rounded-control border border-line-soft overflow-hidden">
              {[['Workers consuming', '8 / 8'], ['Backpressure', 'reconcile queue'], ['Retry budget', '88% left']].map((row, i) => (
                <div key={i} className={"flex items-center justify-between gap-3 py-2.5 px-3.5 " + (i < 2 ? "border-b border-line-soft" : "")}>
                  <span className="text-[12px] text-fg-3">{row[0]}</span>
                  <span className="font-mono text-[12px] font-semibold tabular-nums" style={i === 1 ? { color: 'var(--warn)' } : { color: 'var(--fg-1)' }}>{row[1]}</span>
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>

      {/* live step stream */}
      <div className="card card--flat overflow-hidden">
        <ACardHead icon="activity" title="Live step stream" accent right={<span className="inline-flex items-center gap-1.5 font-mono text-[10px] font-bold tracking-[0.07em] uppercase" style={{ color: 'var(--pnl-up-fg)' }}><span className="w-[6px] h-[6px] rounded-chip bg-pnlup animate-pulse-soft"/>live</span>}/>
        <div className="hidden md:grid grid-cols-[56px_minmax(150px,1.4fr)_100px_120px_88px] gap-3 py-2 px-5 border-b border-line-soft font-mono text-[9px] font-semibold tracking-[0.1em] uppercase text-fg-faint">
          <span>Ago</span><span>Bot · client</span><span>Step</span><span>Worker</span><span className="text-right">Status</span>
        </div>
        {STEP_STREAM.map((s, i) => <StepRow key={i} s={s}/>)}
      </div>
    </>
  );
};

Object.assign(window, { AdminDispatch });
