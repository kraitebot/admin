// Kraite SYSADMIN console — Revenue & billing: fleet-wide financials. Subscription
// state, prepaid-wallet balances, top-ups, payments and coupons across all
// accounts, plus realized trading revenue. "Is the business healthy?"

const PayRow = ({ p }) => {
  const meta = p.kind === 'topup' ? { c: 'var(--pnl-up-fg)', icon: 'arrowDownLeft' } : p.kind === 'debit' ? { c: 'var(--fg-mute)', icon: 'minus' } : { c: 'var(--accent)', icon: 'gift' };
  return (
    <div className="flex items-center gap-3 py-3 px-5 border-b border-line-soft last:border-b-0 max-[640px]:px-4">
      <span className="w-[28px] h-[28px] rounded-control flex items-center justify-center flex-shrink-0" style={{ background: `color-mix(in srgb, ${meta.c} 14%, transparent)`, color: meta.c }}><UIcon name={meta.icon} size={14}/></span>
      <div className="flex flex-col leading-[1.2] min-w-0">
        <span className="font-sans text-[12.5px] font-semibold text-fg-1 whitespace-nowrap truncate">{p.who}</span>
        <span className="font-mono text-[10px] text-fg-mute tracking-[0.02em] whitespace-nowrap">{p.net}{p.coin ? ' · ' + p.coin : ''}</span>
      </div>
      <span className="ml-auto font-mono text-[13px] font-bold tabular-nums whitespace-nowrap" style={{ color: meta.c }}>{p.amt}{p.coin && p.kind !== 'coupon' ? ' ' + p.coin : ''}</span>
      <span className="font-mono text-[10.5px] text-fg-mute tabular-nums w-[34px] text-right flex-shrink-0">{p.ago}</span>
    </div>
  );
};

const AdminRevenue = () => {
  const subTotal = SUB_STATES.reduce((a, s) => a + s.n, 0);
  return (
    <>
      <div className={A_PAGEHEAD}>
        <div>
          <div className={A_EYEBROW}><UIcon name="wallet" size={13} style={{ width: 13, height: 13 }}/>FINANCE</div>
          <h1 className={A_H1}>Revenue &amp; billing</h1>
          <div className={A_SUB}>Subscriptions, wallets, payments, and realized trading revenue across every account.</div>
        </div>
        <div className="flex items-center gap-3 flex-shrink-0">
          <button className={A_BTN_SECONDARY}><UIcon name="download" size={15}/>Export</button>
        </div>
      </div>

      {/* KPI strip */}
      <div className="grid grid-cols-4 gap-3 mb-6 max-[760px]:grid-cols-2">
        {REV_KPIS.map(k => <StatTile key={k.label} icon={k.icon} label={k.label} value={k.value} delta={k.delta} sub={k.sub}/>)}
      </div>

      <div className="grid grid-cols-[1.3fr_1fr] gap-5 max-[900px]:grid-cols-1">
        {/* subscriptions + plan mix */}
        <div className="flex flex-col gap-5">
          <div className="card card--flat overflow-hidden">
            <ACardHead icon="users" title="Subscriptions" accent right={<span className="font-mono text-[10.5px] text-fg-mute tabular-nums">{subTotal.toLocaleString()} accounts</span>}/>
            <div className="p-5 flex flex-col gap-4">
              <div className="flex h-[10px] rounded-chip overflow-hidden">
                {SUB_STATES.map(s => <div key={s.k} style={{ width: (s.n / subTotal * 100) + '%', background: s.c }} title={s.k}/>)}
              </div>
              <div className="grid grid-cols-2 gap-x-5 gap-y-3 max-[480px]:grid-cols-1">
                {SUB_STATES.map(s => (
                  <div key={s.k} className="flex items-center gap-2.5">
                    <span className="w-[9px] h-[9px] rounded-chip flex-shrink-0" style={{ background: s.c }}/>
                    <span className="text-[12.5px] text-fg-2">{s.k}</span>
                    <span className="ml-auto font-mono text-[13px] font-semibold tabular-nums text-fg-1">{s.n.toLocaleString()}</span>
                    <span className="font-mono text-[10px] text-fg-mute tabular-nums w-[34px] text-right">{Math.round(s.n / subTotal * 100)}%</span>
                  </div>
                ))}
              </div>
            </div>
          </div>

          <div className="card card--flat overflow-hidden">
            <ACardHead icon="trendingUp" title="Plan mix" accent/>
            <div className="p-5 flex flex-col gap-3.5">
              {PLAN_MIX.map(p => (
                <div key={p.name} className="flex flex-col gap-1.5">
                  <div className="flex items-center justify-between gap-3">
                    <span className="text-[12.5px] font-semibold text-fg-1">{p.name} <span className="font-mono text-[10.5px] text-fg-mute font-normal ml-1">{p.price}</span></span>
                    <span className="font-mono text-[12px] font-semibold tabular-nums text-fg-2">{p.n} <span className="text-fg-mute text-[10px]">· {p.share}%</span></span>
                  </div>
                  <div className="h-[6px] rounded-chip bg-surface-3 overflow-hidden"><div className="h-full rounded-chip bg-accent" style={{ width: p.share + '%' }}/></div>
                </div>
              ))}
            </div>
          </div>
        </div>

        {/* payments feed */}
        <div className="card card--flat overflow-hidden self-start">
          <ACardHead icon="activity" title="Payments &amp; top-ups" accent right={<span className="font-mono text-[10px] tracking-[0.06em] uppercase text-fg-faint">today</span>}/>
          {PAYMENTS.map((p, i) => <PayRow key={i} p={p}/>)}
        </div>
      </div>
    </>
  );
};

Object.assign(window, { AdminRevenue });
