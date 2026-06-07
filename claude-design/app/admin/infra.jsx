// Kraite SYSADMIN console — Infra: the physical + network layer beneath the
// fleet. Worker nodes, their exchange-IP allowlist state, market-data stream /
// listen-key health, and connectivity to each venue. Reuses SERVERS + AC_IPS.

const StreamRow = ({ d }) => (
  <div className="grid grid-cols-[minmax(120px,1.3fr)_60px_72px_1fr] items-center gap-3 py-3 px-5 border-b border-line-soft last:border-b-0 max-[640px]:px-4"
    style={d.state === 'degraded' ? { background: 'color-mix(in srgb, var(--warn) 6%, transparent)' } : undefined}>
    <span className="flex items-center gap-2.5 min-w-0">
      <span className="w-[26px] h-[26px] rounded-full bg-surface-3 text-fg-1 font-mono font-bold text-[10px] flex items-center justify-center flex-shrink-0">{d.venue[0]}</span>
      <span className="font-sans text-[13px] font-semibold text-fg-1 whitespace-nowrap">{d.venue}</span>
    </span>
    <span className="font-mono text-[12px] tabular-nums text-fg-2 text-right">{d.streams}<span className="text-fg-mute text-[9px] ml-0.5">ws</span></span>
    <span className="font-mono text-[12px] tabular-nums text-right" style={{ color: d.lag == null ? 'var(--fg-mute)' : d.lag >= 120 ? 'var(--warn)' : 'var(--fg-1)' }}>{d.lag == null ? '—' : d.lag + 'ms'}</span>
    <span className="flex items-center justify-end gap-2.5">
      <span className="font-mono text-[10.5px] text-fg-mute tracking-[0.02em] max-[480px]:hidden">key {d.key}</span>
      <HealthChip state={d.state}/>
    </span>
  </div>
);

const AdminInfra = () => {
  const healthyStreams = DATA_STREAMS.filter(d => d.state === 'healthy').length;
  return (
    <>
      <div className={A_PAGEHEAD}>
        <div>
          <div className={A_EYEBROW}><UIcon name="server" size={13} style={{ width: 13, height: 13 }}/>INFRASTRUCTURE</div>
          <h1 className={A_H1}>Infrastructure</h1>
          <div className={A_SUB}>Servers, exchange-IP allowlisting, and the market-data streams beneath the fleet.</div>
        </div>
        <div className="flex items-center gap-3 flex-shrink-0">
          <button className={A_BTN_SECONDARY}><UIcon name="refresh" size={15}/>Re-check</button>
        </div>
      </div>

      {/* KPI strip */}
      <div className="grid grid-cols-4 gap-3 mb-6 max-[760px]:grid-cols-2">
        <StatTile icon="server" label="Worker nodes" value="8" sub="5 REGIONS"/>
        <StatTile icon="globe" label="Regions" value="5" sub="FRA·LDN·NYC·SGP·TOK"/>
        <StatTile icon="shield" label="Egress IPs" value={String(AC_IPS.length)} sub="ALLOWLISTED"/>
        <StatTile icon="wifi" label="Data streams" value={healthyStreams + '/' + DATA_STREAMS.length} sub="HEALTHY"/>
      </div>

      <div className="grid grid-cols-[1fr_1.2fr] gap-5 mb-5 max-[900px]:grid-cols-1">
        {/* egress IPs */}
        <div className="card card--flat overflow-hidden">
          <ACardHead icon="shield" title="Egress IP allowlist" accent
            right={<AcctCopy text={AC_IPS.map(i => i.ip).join('\n')} label="Copy all" full/>}/>
          <p className="text-[12px] text-fg-3 leading-snug px-5 py-3 border-b border-line-soft max-[640px]:px-4">The canonical addresses traders allowlist on the exchange side. Rotating any of these requires a coordinated announcement.</p>
          {AC_IPS.map(ip => (
            <div key={ip.id} className="flex items-center gap-3 py-2.5 px-5 border-b border-line-soft last:border-b-0 max-[640px]:px-4">
              <span className="w-[8px] h-[8px] rounded-chip bg-pnlup flex-shrink-0"/>
              <span className="font-mono text-[12.5px] font-semibold tabular-nums text-fg-1 tracking-[0.02em]">{ip.ip}</span>
              <span className="font-mono text-[10px] tracking-[0.07em] uppercase text-fg-mute">{ip.region}</span>
              <span className="ml-auto font-mono text-[9.5px] font-bold tracking-[0.06em] uppercase text-pnlup max-[480px]:hidden">Allowlisted</span>
              <span className="ml-2"><AcctCopy text={ip.ip}/></span>
            </div>
          ))}
        </div>

        {/* data streams */}
        <div className="card card--flat overflow-hidden">
          <ACardHead icon="wifi" title="Market-data streams" accent hint="websocket · listen-key"/>
          <div className="hidden md:grid grid-cols-[minmax(120px,1.3fr)_60px_72px_1fr] gap-3 py-2 px-5 border-b border-line-soft font-mono text-[9px] font-semibold tracking-[0.1em] uppercase text-fg-faint">
            <span>Venue</span><span className="text-right">Streams</span><span className="text-right">Lag</span><span className="text-right">Listen-key · health</span>
          </div>
          {DATA_STREAMS.map(d => <StreamRow key={d.venue} d={d}/>)}
        </div>
      </div>

      {/* node reachability */}
      <div className="card card--flat overflow-hidden">
        <ACardHead icon="server" title="Node reachability" accent hint="control-plane heartbeat"/>
        <div className="grid grid-cols-3 max-[820px]:grid-cols-2 max-[560px]:grid-cols-1">
          {A_WORKERS.map((w, i) => (
            <div key={w.id} className={"flex items-center gap-3 py-3.5 px-5 border-b border-line-soft " + ((i % 3 !== 2) ? "border-r border-line-soft max-[820px]:[&:nth-child(2n)]:border-r-0 max-[560px]:border-r-0" : "")}>
              <HealthDot state={w.state} pulse={w.state === 'degraded'}/>
              <div className="flex flex-col leading-[1.2] min-w-0">
                <span className="font-mono text-[12px] font-semibold text-fg-1 whitespace-nowrap">{w.id}</span>
                <span className="font-mono text-[10px] text-fg-mute whitespace-nowrap">{w.region} · {w.lat}ms</span>
              </div>
              <span className="ml-auto font-mono text-[9.5px] font-bold tracking-[0.06em] uppercase" style={{ color: w.state === 'degraded' ? 'var(--warn)' : 'var(--pnl-up-fg)' }}>{w.state === 'degraded' ? 'Slow' : 'Reachable'}</span>
            </div>
          ))}
        </div>
      </div>
    </>
  );
};

Object.assign(window, { AdminInfra });
