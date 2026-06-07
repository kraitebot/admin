// Kraite SYSADMIN console — Exchanges: the exchange-integration catalog. Each
// connected venue, the symbols it exposes, their leverage brackets, and live
// mark prices. Verify which markets are wired in and whether data is fresh.

const VenueCard = ({ v, open, onToggle }) => {
  const syms = VENUE_SYMBOLS[v.ex] || [];
  const stale = v.state === 'maintenance';
  return (
    <div className="card card--flat overflow-hidden" style={v.state === 'degraded' ? { borderColor: 'color-mix(in srgb, var(--warn) 30%, var(--border))' } : undefined}>
      <button onClick={onToggle} className="w-full flex items-center gap-3.5 py-4 px-5 text-left bg-transparent border-0 cursor-pointer hover:bg-hover transition-colors duration-fast max-[640px]:px-4">
        <UIcon name="chevronDown" size={18} style={{ color: 'var(--fg-mute)', flexShrink: 0, transform: open ? 'rotate(180deg)' : 'none', transition: 'transform .22s cubic-bezier(0.16,1,0.3,1)' }}/>
        <span className="w-[34px] h-[34px] rounded-full bg-surface-3 text-fg-1 font-mono font-bold text-[13px] flex items-center justify-center flex-shrink-0">{v.mono}</span>
        <div className="flex flex-col leading-[1.2] min-w-0">
          <span className="font-sans text-[14.5px] font-semibold text-fg-1 whitespace-nowrap">{v.ex}</span>
          <span className="font-mono text-[10.5px] text-fg-mute tracking-[0.02em] whitespace-nowrap">{syms.length} symbols · {v.accts} accounts</span>
        </div>
        <div className="ml-auto flex items-center gap-4 flex-shrink-0 max-[640px]:gap-2.5">
          <span className="font-mono text-[12px] font-semibold tabular-nums max-[480px]:hidden" style={{ color: v.lat == null ? 'var(--fg-mute)' : v.lat >= 100 ? 'var(--warn)' : 'var(--fg-1)' }}>{v.lat == null ? '—' : v.lat + 'ms'}</span>
          <HealthChip state={v.state}/>
        </div>
      </button>
      <div className="grid transition-[grid-template-rows] duration-[320ms] ease-[cubic-bezier(0.16,1,0.3,1)]" style={{ gridTemplateRows: open ? '1fr' : '0fr' }}>
        <div className="min-h-0 overflow-hidden">
          <div className="border-t border-line-soft">
            <div className="hidden md:grid grid-cols-[minmax(120px,1.4fr)_90px_1fr_80px] gap-3 py-2 px-5 border-b border-line-soft bg-surface-2 font-mono text-[9px] font-semibold tracking-[0.1em] uppercase text-fg-faint">
              <span>Symbol</span><span>Max lev</span><span className="text-right">Mark</span><span className="text-right">Data</span>
            </div>
            {syms.map(s => (
              <div key={s.s} className="grid grid-cols-[minmax(120px,1.4fr)_90px_1fr_80px] items-center gap-3 py-2.5 px-5 border-b border-line-soft last:border-b-0 max-[640px]:px-4">
                <span className="font-mono text-[12.5px] font-semibold text-fg-1">{s.s}</span>
                <span className="font-mono text-[11.5px] font-semibold tabular-nums" style={{ color: 'var(--accent)' }}>{s.lev}</span>
                <span className="font-mono text-[12.5px] tabular-nums text-fg-1 text-right">{s.mark}</span>
                <span className="flex justify-end"><span className="font-mono text-[9.5px] font-bold tracking-[0.06em] uppercase" style={{ color: stale || s.mark === '—' ? 'var(--fg-mute)' : 'var(--pnl-up-fg)' }}>{stale || s.mark === '—' ? 'Stale' : 'Fresh'}</span></span>
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
};

const AdminExchanges = () => {
  const [openIdx, setOpenIdx] = React.useState(0);
  const totalSyms = Object.values(VENUE_SYMBOLS).reduce((a, s) => a + s.length, 0);
  const totalAccts = A_VENUES.reduce((a, v) => a + v.accts, 0);
  return (
    <>
      <div className={A_PAGEHEAD}>
        <div>
          <div className={A_EYEBROW}><UIcon name="exchange" size={13} style={{ width: 13, height: 13 }}/>CONNECTIVITY</div>
          <h1 className={A_H1}>Exchanges</h1>
          <div className={A_SUB}>Connected venues, the symbols they expose, leverage brackets, and live marks.</div>
        </div>
        <div className="flex items-center gap-3 flex-shrink-0">
          <button className={A_BTN_SECONDARY}><UIcon name="plus" size={15}/>Add venue</button>
        </div>
      </div>

      <div className="grid grid-cols-4 gap-3 mb-6 max-[760px]:grid-cols-2">
        <StatTile icon="exchange" label="Venues" value={String(A_VENUES.length)} sub="INTEGRATED"/>
        <StatTile icon="users" label="Connected accounts" value={totalAccts.toLocaleString()} sub="ACROSS VENUES"/>
        <StatTile icon="layers" label="Symbols wired" value={String(totalSyms)} sub="TRADABLE"/>
        <StatTile icon="alert" label="Degraded" value="1" sub="OKX · LATENCY"/>
      </div>

      <div className="flex items-center justify-between gap-3 mb-4">
        <span className="font-mono text-[10.5px] font-semibold tracking-[0.12em] uppercase text-fg-mute">Integrated venues · {A_VENUES.length}</span>
        <span className="font-mono text-[10.5px] text-fg-faint tracking-[0.04em] max-[640px]:hidden">Expand a venue to inspect its symbols</span>
      </div>
      <div className="flex flex-col gap-3">
        {A_VENUES.map((v, i) => (
          <VenueCard key={v.ex} v={v} open={openIdx === i} onToggle={() => setOpenIdx(o => (o === i ? -1 : i))}/>
        ))}
      </div>
    </>
  );
};

Object.assign(window, { AdminExchanges });
