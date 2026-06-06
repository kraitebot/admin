// Kraite admin — Billing page (composition). Data + primitives live in
// billing-kit.jsx (loaded before this). Reuses the system-wide form/card/banner
// vocabulary (AcctField/AcctInput/AcctBandHead, PAGEHEAD, BTN_*, RegimePill).
//
// The page is a STATE MACHINE: a prepaid USDT wallet funded by crypto top-ups,
// debited monthly by the active plan. Six lifecycle states (billState Tweak),
// with local interactive overrides (start trial · pause · resume · switch · top up).
// Green = credit/safe, red = debit/loss/danger — never inverted.

// ============================ countdown ============================
const blFmtCountdown = (secs) => {
  if (secs <= 0) return 'now';
  const d = Math.floor(secs / 86400), h = Math.floor((secs % 86400) / 3600), m = Math.floor((secs % 3600) / 60), s = Math.floor(secs % 60);
  if (d > 0) return `${d}d ${h}h ${m}m`;
  if (h > 0) return `${h}h ${String(m).padStart(2, '0')}m ${String(s).padStart(2, '0')}s`;
  return `${m}m ${String(s).padStart(2, '0')}s`;
};

// ============================ wallet hero ============================
// The heart of the page: big USDT balance + the renewal picture. The renewal
// panel is state-driven (covered ✓ / shortfall CTA / trial / paused / failed).
const WalletHero = ({ view, wallet, credited, plan, rate, covered, shortfall, surplus,
  renewalLabel, daysLeft, trialSecs, freshTrial, pausedSince, pausing,
  onPauseStart, onPauseConfirm, onPauseCancel, onTopUp }) => {
  const [whole, frac] = usdt(wallet, 4).split('.');
  const planName = plan ? BL_PLAN(plan).name : null;

  // ---- renewal panel content, by state ----
  let renewal;
  if (view === 'no-plan') {
    renewal = (
      <div className="flex flex-col items-start justify-center h-full gap-2">
        <div className={BL_EYEBROW}>No active plan</div>
        <div className="text-[13px] text-fg-3 leading-snug max-w-[260px]">Pick a plan below to start your 7-day free trial. The wallet is only charged after the trial ends.</div>
      </div>
    );
  } else if (view === 'trial-ready') {
    renewal = (
      <div className="flex flex-col items-start justify-center h-full gap-2">
        <div className={BL_EYEBROW}>Trial not started</div>
        <div className="font-sans text-[15px] text-fg-1 font-semibold">{planName} · {usdt2(rate)} USDT<span className="text-fg-mute font-normal">/mo</span></div>
        <div className="text-[12.5px] text-fg-3 leading-snug max-w-[260px]">Your 7-day free trial begins when you start trading. No debit until the first renewal.</div>
      </div>
    );
  } else if (view === 'trial') {
    renewal = (
      <div className="flex flex-col items-start justify-center h-full gap-2.5">
        <div className={BL_EYEBROW + " flex items-center gap-2"}><span className="w-1.5 h-1.5 rounded-chip bg-info animate-pulse-soft"/>Free trial · ends in</div>
        <div className="font-mono text-[26px] font-semibold tabular-nums tracking-[-0.01em] text-fg-1 leading-none">{blFmtCountdown(trialSecs)}</div>
        <div className="text-[12px] text-fg-3 leading-snug">First renewal <span className="text-fg-1 font-semibold">{renewalLabel}</span> · {planName} {usdt2(rate)} USDT/mo. Wallet untouched during the trial.</div>
      </div>
    );
  } else if (view === 'paused') {
    renewal = (
      <div className="flex flex-col items-start justify-center h-full gap-2">
        <div className={BL_EYEBROW + " flex items-center gap-2"} style={{ color: 'var(--warn)' }}><UIcon name="pause" size={12}/>Subscription paused</div>
        <div className="font-sans text-[15px] text-fg-1 font-semibold">Paused since {pausedSince}</div>
        <div className="text-[12.5px] text-fg-3 leading-snug max-w-[270px]">Renewals are stopped and the wallet is untouched. Existing positions keep trading; new positions are blocked. Resuming pushes the renewal date forward by the pause length.</div>
      </div>
    );
  } else if (view === 'read-only') {
    renewal = (
      <div className="flex flex-col items-start justify-center h-full gap-2.5">
        <div className="font-mono text-[10px] font-semibold tracking-[0.11em] uppercase flex items-center gap-2" style={{ color: 'var(--danger)' }}><UIcon name="lock" size={12}/>Renewal failed · {renewalLabel}</div>
        <div className="flex items-baseline gap-2">
          <span className="font-mono text-[28px] font-semibold tabular-nums tracking-[-0.02em] leading-none" style={{ color: 'var(--pnl-down-fg)' }}>−{usdt2(shortfall)}</span>
          <span className="font-mono text-[12px] text-fg-mute">USDT short</span>
        </div>
        <button onClick={onTopUp} className={BTN_PRIMARY + " h-[36px] px-3.5"}><UIcon name="plus" size={14}/>{`Top up ${usdt2(shortfall)} USDT to retry`}</button>
      </div>
    );
  } else if (pausing) {
    renewal = (
      <div className="flex flex-col items-start justify-center h-full gap-3">
        <div>
          <div className="font-sans font-semibold text-[14px] text-fg-1">Pause subscription?</div>
          <div className="text-[12px] text-fg-3 mt-1 leading-snug max-w-[270px]">Renewals stop and nothing is debited. Existing positions keep trading; new positions are blocked. Resume anytime — your renewal date moves forward by the pause length.</div>
        </div>
        <div className="flex items-center gap-2">
          <button onClick={onPauseConfirm} className={BTN_PRIMARY + " h-[34px] px-3.5"} style={{ background: 'var(--warn)', color: '#1a1200' }}><UIcon name="pause" size={14}/>Pause now</button>
          <button onClick={onPauseCancel} className={BTN_SECONDARY + " h-[34px]"}>Keep active</button>
        </div>
      </div>
    );
  } else {
    // active — covered / short
    renewal = (
      <div className="flex flex-col h-full">
        <div className="flex-1 flex flex-col justify-center gap-2">
          <div className={BL_EYEBROW}>Next renewal</div>
          <div className="flex items-baseline gap-2 flex-wrap">
            <span className="font-sans text-[17px] text-fg-1 font-semibold">{planName}</span>
            <span className="font-mono text-[14px] text-fg-2 tabular-nums">{usdt2(rate)} USDT<span className="text-fg-mute">/mo</span></span>
          </div>
          <div className="font-mono text-[12.5px] text-fg-3 tabular-nums">{renewalLabel} · in {daysLeft} days</div>
          {rate === 0 ? (
            <div className="inline-flex items-center gap-2 mt-1 font-mono text-[11.5px] font-semibold" style={{ color: 'var(--pnl-up-fg)' }}><UIcon name="check" size={14}/>Starter is free — no renewal charge</div>
          ) : covered ? (
            <div className="inline-flex items-center gap-2 mt-1 font-mono text-[11.5px] font-semibold" style={{ color: 'var(--pnl-up-fg)' }}>
              <UIcon name="check" size={14}/>Wallet covers next renewal · {usdt2(surplus)} USDT left after
            </div>
          ) : (
            <div className="flex items-center gap-2.5 mt-1 flex-wrap">
              <span className="inline-flex items-center gap-1.5 font-mono text-[12px] font-semibold" style={{ color: 'var(--pnl-down-fg)' }}><UIcon name="alert" size={13}/>Short {usdt2(shortfall)} USDT</span>
              <button onClick={onTopUp} className={BTN_PRIMARY + " h-[30px] px-3 text-[11.5px]"}><UIcon name="plus" size={13}/>Top up</button>
            </div>
          )}
        </div>
        <button onClick={onPauseStart} className="self-start mt-3 appearance-none bg-transparent border-0 cursor-pointer font-mono text-[10.5px] tracking-[0.06em] uppercase text-fg-mute inline-flex items-center gap-1.5 transition-colors duration-fast hover:text-fg-2">
          <UIcon name="pause" size={12}/>Pause subscription
        </button>
      </div>
    );
  }

  return (
    <div className="card card--flat mb-6 overflow-visible">
      <div className="grid grid-cols-[1.15fr_1fr] max-[760px]:grid-cols-1">
        {/* balance */}
        <div className="p-6 max-[640px]:p-5 flex flex-col gap-3 relative">
          <div className="flex items-center gap-2.5">
            <div className={BL_EYEBROW}>Prepaid wallet</div>
            <span className="inline-flex items-center gap-1.5 font-mono text-[10px] text-fg-mute tracking-[0.04em]">
              <span className="w-1.5 h-1.5 rounded-chip bg-accent animate-pulse-soft"/>live
            </span>
            {credited && (
              <span className="inline-flex items-center gap-1 font-mono text-[10.5px] font-bold tracking-[0.04em] py-[2px] px-2 rounded-chip animate-dd-in" style={{ color: 'var(--pnl-up-fg)', background: 'var(--pnl-up-bg)' }}>
                <UIcon name="arrowDownLeft" size={11}/>+{usdt(credited, 4)} credited
              </span>
            )}
          </div>
          <div className={"flex items-baseline gap-2 rounded-control -mx-1 px-1 " + (credited ? "animate-flash-up" : "")}>
            <span className="font-mono font-semibold tabular-nums tracking-[-0.03em] text-fg-1 leading-none text-[56px] max-[640px]:text-[44px]">{whole}</span>
            <span className="font-mono font-semibold tabular-nums tracking-[-0.02em] text-fg-mute leading-none text-[30px] max-[640px]:text-[24px]">.{frac}</span>
            <span className="font-mono text-[15px] font-semibold text-fg-3 ml-1 self-end mb-1">USDT</span>
          </div>
          <div className="font-mono text-[11.5px] text-fg-mute tabular-nums tracking-[0.02em]">≈ ${usdt2(wallet)} · held by Kraite · polled just now</div>
          <button onClick={onTopUp} className={BTN_PRIMARY + " h-[38px] px-4 self-start mt-1"}><UIcon name="plus" size={15}/>Add funds</button>
        </div>
        {/* renewal picture */}
        <div className="p-6 max-[640px]:p-5 border-l border-line-soft max-[760px]:border-l-0 max-[760px]:border-t"
          style={view === 'read-only' ? { background: 'color-mix(in srgb, var(--danger) 7%, transparent)' } : undefined}>
          {renewal}
        </div>
      </div>
    </div>
  );
};

// ============================ plan card ============================
const PlanCard = ({ p, current, mode, onSwitch }) => {
  const free = p.price === 0;
  const cta = current
    ? <span className="inline-flex items-center justify-center gap-1.5 h-[38px] rounded-control font-sans font-semibold text-[12.5px] w-full whitespace-nowrap" style={{ color: 'var(--accent)', background: 'color-mix(in srgb, var(--accent) 12%, transparent)' }}><UIcon name="check" size={15}/>Current plan</span>
    : <button onClick={() => onSwitch(p.id)} className={(mode === 'choose' ? BTN_PRIMARY : BTN_SECONDARY) + " w-full justify-center h-[38px] whitespace-nowrap"}>
        {mode === 'choose' ? `Choose ${p.name}` : `Switch to ${p.name}`}
      </button>;
  return (
    <div className="card relative flex flex-col p-5 max-[640px]:p-4"
      style={current ? { borderColor: 'var(--accent)', boxShadow: 'inset 0 0 0 1px var(--accent), 0 2px 14px rgba(0,0,0,0.35)' } : undefined}>
      <div className="flex items-center justify-between gap-2 mb-3">
        <h3 className="font-sans font-semibold text-[16px] tracking-[-0.01em] text-fg-1">{p.name}</h3>
        {p.popular && !current && <span className="font-mono text-[9px] font-bold tracking-[0.1em] uppercase py-[3px] px-2 rounded-chip" style={{ color: 'var(--accent)', background: 'color-mix(in srgb, var(--accent) 14%, transparent)' }}>Popular</span>}
        {current && <span className="font-mono text-[9px] font-bold tracking-[0.1em] uppercase py-[3px] px-2 rounded-chip" style={{ color: 'var(--accent)', background: 'color-mix(in srgb, var(--accent) 14%, transparent)' }}>Active</span>}
      </div>
      <div className="flex items-baseline gap-1.5 mb-1">
        <span className="font-mono font-semibold tabular-nums tracking-[-0.03em] text-fg-1 leading-none text-[34px]">{free ? '$0' : '$' + p.price}</span>
        <span className="font-mono text-[13px] text-fg-mute">/mo</span>
      </div>
      <div className="text-[12.5px] text-fg-3 leading-snug mb-4 min-h-[34px]">{p.blurb}</div>
      <div className="flex flex-col gap-2 mb-5">
        {p.features.map((f, i) => (
          <div key={i} className="flex items-center gap-2.5 text-[12.5px] text-fg-2">
            <UIcon name={i === 0 && p.id === 'unlimited' ? 'infinity' : 'check'} size={14} style={{ color: 'var(--accent)', flexShrink: 0 }}/>{f}
          </div>
        ))}
      </div>
      <div className="mt-auto">{cta}</div>
    </div>
  );
};

// ============================ switch confirm ============================
const SwitchConfirm = ({ fromId, toId, isTrial, proration, downgradeAccts, keepAcct, setKeepAcct, onConfirm, onCancel, onTopUp }) => {
  const from = fromId ? BL_PLAN(fromId) : null;
  const to = BL_PLAN(toId);
  const short = !isTrial && proration.walletAfter < 0;
  return (
    <div className="card card--flat mt-4 overflow-hidden animate-dd-in">
      <AcctBandHead icon="refresh" title={`Switch ${from ? from.name + ' → ' : 'to '}${to.name}`}
        right={<button onClick={onCancel} className="appearance-none bg-transparent border-0 cursor-pointer text-fg-mute hover:text-fg-1 transition-colors"><UIcon name="plus" size={18} style={{ transform: 'rotate(45deg)' }}/></button>}/>

      <div className="p-6 max-[640px]:p-4 flex flex-col gap-5">
        {isTrial ? (
          <div className="flex items-start gap-3 rounded-control border px-4 py-3.5" style={{ borderColor: 'color-mix(in srgb, var(--info) 38%, transparent)', background: 'color-mix(in srgb, var(--info) 9%, transparent)' }}>
            <UIcon name="clock" size={17} style={{ color: 'var(--info)', flexShrink: 0, marginTop: 1 }}/>
            <div className="text-[12.5px] text-fg-2 leading-snug">During your free trial, plan changes are <span className="font-semibold text-fg-1">free and instant</span> — no proration and no debit. {to.name} takes effect immediately.</div>
          </div>
        ) : (
          <div className="rounded-control border border-line-soft overflow-hidden">
            <div className="flex items-center justify-between gap-4 py-3 px-4 border-b border-line-soft bg-surface-2">
              <span className="text-[12.5px] text-fg-2">Prorate refund · {BL_DAYS_LEFT} unused days of {from ? from.name : '—'}</span>
              <span className="font-mono text-[13px] font-semibold tabular-nums" style={{ color: 'var(--pnl-up-fg)' }}>{usdtSigned(proration.refund)}</span>
            </div>
            <div className="flex items-center justify-between gap-4 py-3 px-4 border-b border-line-soft">
              <span className="text-[12.5px] text-fg-2">{to.name} · one month</span>
              <span className="font-mono text-[13px] font-semibold tabular-nums" style={{ color: to.price === 0 ? 'var(--fg-mute)' : 'var(--pnl-down-fg)' }}>{to.price === 0 ? '0.0000' : usdtSigned(-proration.debit)}</span>
            </div>
            <div className="flex items-center justify-between gap-4 py-3.5 px-4" style={{ background: short ? 'color-mix(in srgb, var(--danger) 8%, transparent)' : 'transparent' }}>
              <span className="font-sans text-[12.5px] font-semibold text-fg-1 whitespace-nowrap">Wallet after switch</span>
              <span className="font-mono text-[15px] font-semibold tabular-nums" style={{ color: short ? 'var(--pnl-down-fg)' : 'var(--fg-1)' }}>{short ? '−' : ''}{usdt(Math.abs(proration.walletAfter))} USDT</span>
            </div>
          </div>
        )}

        {/* downgrade-from-unlimited: pick which account stays active */}
        {!isTrial && downgradeAccts && (
          <div>
            <div className="font-mono text-[10px] font-semibold tracking-[0.11em] uppercase text-fg-mute mb-2.5 flex items-center gap-2"><UIcon name="alert" size={13} style={{ color: 'var(--warn)' }}/>{to.name} allows one account — keep which active?</div>
            <div className="grid grid-cols-2 gap-2 max-[560px]:grid-cols-1">
              {ACCOUNTS.map((a, i) => {
                const on = keepAcct === i;
                return (
                  <button key={AC_KEY(a)} onClick={() => setKeepAcct(i)}
                    className="appearance-none cursor-pointer text-left flex items-center gap-3 rounded-control border bg-surface-2 py-2.5 px-3 transition-colors duration-fast"
                    style={{ borderColor: on ? 'var(--accent)' : 'var(--border)', boxShadow: on ? 'inset 0 0 0 1px var(--accent)' : 'none' }}>
                    <span className="w-[28px] h-[28px] rounded-full bg-surface-3 text-fg-2 font-mono font-bold text-[11px] flex items-center justify-center flex-shrink-0">{a.mono}</span>
                    <span className="flex flex-col leading-[1.2] min-w-0 flex-1">
                      <span className="text-[12.5px] font-semibold text-fg-1 whitespace-nowrap">{a.ex} <span className="text-fg-mute font-normal">· {a.tag}</span></span>
                      <span className="font-mono text-[10px] text-fg-mute tabular-nums">{a.equity}</span>
                    </span>
                    <span className="w-[16px] h-[16px] rounded-full flex items-center justify-center flex-shrink-0" style={{ background: on ? 'var(--accent)' : 'transparent', boxShadow: on ? 'none' : 'inset 0 0 0 1.5px var(--border-strong)' }}>
                      {on && <UIcon name="check" size={11} style={{ color: 'var(--on-accent)' }}/>}
                    </span>
                  </button>
                );
              })}
            </div>
            <div className="text-[11.5px] text-fg-mute mt-2 leading-snug">The other {ACCOUNTS.length - 1} accounts stay connected but the bot stops trading them until you upgrade again.</div>
          </div>
        )}

        {short ? (
          <div className="flex items-center gap-3 rounded-control border px-4 py-3.5 flex-wrap" style={{ borderColor: 'color-mix(in srgb, var(--danger) 42%, transparent)', background: 'color-mix(in srgb, var(--danger) 9%, transparent)' }}>
            <UIcon name="alert" size={17} style={{ color: 'var(--danger)', flexShrink: 0 }}/>
            <span className="text-[12.5px] text-fg-2 flex-1 min-w-[200px]">The wallet falls <span className="font-mono font-semibold" style={{ color: 'var(--pnl-down-fg)' }}>{usdt2(Math.abs(proration.walletAfter))} USDT</span> short of {to.name} after the refund. Top up first, then switch.</span>
            <button onClick={onTopUp} className={BTN_PRIMARY + " h-[34px] px-3.5"} style={{ background: 'var(--danger)', color: '#fff' }}><UIcon name="plus" size={14}/>{`Top up ${usdt2(Math.abs(proration.walletAfter))}`}</button>
          </div>
        ) : (
          <div className="flex items-center gap-2.5 flex-wrap">
            <button onClick={onConfirm} className={BTN_PRIMARY + " h-[40px] px-5"}><UIcon name="check" size={16}/>{`Confirm switch to ${to.name}`}</button>
            <button onClick={onCancel} className={BTN_SECONDARY + " h-[40px]"}>Cancel</button>
            {!isTrial && <span className="font-mono text-[10.5px] text-fg-mute tracking-[0.03em]">Refund credits instantly, then {to.name} is debited</span>}
          </div>
        )}
      </div>
    </div>
  );
};

// ============================ top-up ============================
// Coin + network are NOT chosen here — the customer picks them on the
// NOWPayments checkout. Our side only takes the USDT amount to credit; the
// supported coins are shown as a non-interactive "accepted" strip.
const TopUp = React.forwardRef(({ covered, shortfall, rate, onSubmit }, ref) => {
  const [amount, setAmount] = React.useState(() => String(Math.max(covered ? 20 : shortfall, 75)));
  const [invoice, setInvoice] = React.useState(null);

  const effMin = covered ? 20 : shortfall;            // ~$20 floor when covered, else clear the shortfall
  const amtNum = parseFloat(amount) || 0;
  const belowMin = amtNum < effMin - 1e-9;

  const minLine = !covered
    ? <>Minimum <span className="font-mono text-fg-2">{usdt2(effMin)} USDT</span> — clears your renewal shortfall.</>
    : <>Minimum top-up is <span className="font-mono text-fg-2">{usdt2(effMin)} USDT</span>. NOWPayments may set a higher floor for some coins.</>;

  const presets = [...new Set([effMin, rate > 0 ? rate : 0, rate > 0 ? rate * 2 : 50, 100].filter(v => v >= effMin - 1e-9))].sort((a, b) => a - b).slice(0, 4);

  if (invoice) {
    return (
      <div ref={ref} className="card card--flat mb-6 overflow-hidden scroll-mt-4">
        <AcctBandHead icon="arrowUpRight" title="Leaving Kraite — NOWPayments checkout"/>
        <div className="p-6 max-[640px]:p-4 flex flex-col gap-5">
          <div className="flex items-start gap-3 rounded-control border px-4 py-3.5" style={{ borderColor: 'color-mix(in srgb, var(--info) 38%, transparent)', background: 'color-mix(in srgb, var(--info) 9%, transparent)' }}>
            <UIcon name="shield" size={17} style={{ color: 'var(--info)', flexShrink: 0, marginTop: 1 }}/>
            <div className="text-[12.5px] text-fg-2 leading-snug">You'll be taken to <span className="font-semibold text-fg-1">NOWPayments</span> to complete this top-up — you're leaving Kraite. <span className="font-semibold text-fg-1">Choose your coin and network there</span>; non-USDT coins convert to USDT at the gateway rate. Your wallet credits automatically once the transfer confirms on-chain.</div>
          </div>
          <div className="rounded-control border border-line-soft overflow-hidden">
            {[['Credit to wallet', `${usdt2(invoice.amount)} USDT`], ['Pay with', 'Chosen on NOWPayments'], ['Gateway fee', '≈ 0.5% + network gas']].map((r, i) => (
              <div key={i} className={"flex items-center justify-between gap-4 py-3 px-4 " + (i < 2 ? "border-b border-line-soft" : "")}>
                <span className="text-[12.5px] text-fg-3">{r[0]}</span>
                <span className="font-mono text-[13px] font-semibold text-fg-1 tabular-nums">{r[1]}</span>
              </div>
            ))}
          </div>
          <div className="flex items-center gap-2.5 flex-wrap">
            <button onClick={() => { onSubmit && onSubmit(invoice.amount); setInvoice(null); }} className={BTN_PRIMARY + " h-[40px] px-5"}>Continue to NOWPayments<UIcon name="arrowUpRight" size={15}/></button>
            <button onClick={() => setInvoice(null)} className={BTN_SECONDARY + " h-[40px]"}>Back</button>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div ref={ref} className="card card--flat mb-6 overflow-hidden scroll-mt-4">
      <AcctBandHead icon="plus" title="Top up wallet" hint="crypto · via NOWPayments"/>
      <div className="p-6 max-[640px]:p-4 flex flex-col gap-5">
        <p className="text-[12.5px] text-fg-3 leading-snug max-w-[560px]">Enter how much USDT to add to your wallet. You'll pick the coin and network on the NOWPayments checkout — pay in USDT, USDC, BTC, ETH and more.</p>

        {/* amount + submit */}
        <div className="grid grid-cols-[1fr_auto] gap-5 items-start max-[640px]:grid-cols-1">
          <div>
            <div className="font-mono text-[10px] font-semibold tracking-[0.11em] uppercase text-fg-mute mb-2.5">Amount to credit</div>
            <div className="relative flex items-center">
              <input value={amount} onChange={e => setAmount(e.target.value.replace(/[^0-9.]/g, ''))} inputMode="decimal"
                className="w-full h-[52px] bg-input border rounded-control pl-4 pr-[72px] font-mono text-[22px] font-semibold tabular-nums text-fg-1 outline-none transition-[border-color,box-shadow] duration-fast"
                style={{ borderColor: belowMin ? 'var(--danger)' : 'var(--border)' }}/>
              <span className="absolute right-4 font-mono text-[14px] font-semibold text-fg-mute">USDT</span>
            </div>
            <div className="flex items-center gap-1.5 mt-2.5 flex-wrap">
              {presets.map(v => (
                <button key={v} onClick={() => setAmount(String(v))}
                  className="appearance-none cursor-pointer font-mono text-[11px] font-semibold tabular-nums rounded-chip border border-line bg-surface-3 text-fg-2 py-1 px-2.5 transition-colors duration-fast hover:border-line-strong hover:text-fg-1">
                  {v === effMin ? 'Min ' : ''}{usdt2(v)}
                </button>
              ))}
            </div>
            <div className={"text-[11.5px] mt-2 leading-snug " + (belowMin ? "" : "text-fg-mute")} style={belowMin ? { color: 'var(--danger)' } : undefined}>
              {belowMin ? <><UIcon name="alert" size={12} style={{ display: 'inline', verticalAlign: '-1px', marginRight: 4 }}/>Below minimum. {minLine}</> : minLine}
            </div>
          </div>

          <div className="flex flex-col gap-2.5 min-w-[230px] max-[640px]:min-w-0 self-end">
            <button disabled={belowMin || amtNum <= 0} onClick={() => setInvoice({ amount: amtNum })}
              className={BTN_PRIMARY + " h-[52px] px-4 justify-center text-[13.5px] " + (belowMin || amtNum <= 0 ? "opacity-40 cursor-not-allowed hover:bg-accent" : "")}>
              {`Top up ${usdt2(amtNum)} USDT`}<UIcon name="arrowUpRight" size={15}/>
            </button>
            <span className="font-mono text-[10px] text-fg-mute tracking-[0.03em] text-center max-[640px]:text-left">Continues to NOWPayments</span>
          </div>
        </div>

        {/* accepted coins (informational — selection happens on NOWPayments) */}
        <div className="flex items-center gap-3 flex-wrap pt-1 border-t border-line-soft mt-1">
          <span className="font-mono text-[9.5px] font-semibold tracking-[0.1em] uppercase text-fg-mute pt-3">Accepted</span>
          <div className="flex items-center gap-1.5 flex-wrap pt-3">
            {BL_COINS.filter((c, i, a) => a.findIndex(x => x.sym === c.sym) === i).map(c => (
              <span key={c.sym} className="inline-flex items-center gap-1.5 rounded-chip border border-line-soft bg-surface-2 pl-1 pr-2.5 py-1">
                <CoinGlyph coin={c} size={20}/>
                <span className="font-mono text-[11px] font-semibold text-fg-2">{c.sym}</span>
              </span>
            ))}
            <span className="font-mono text-[10.5px] text-fg-faint tracking-[0.03em] ml-1">on Tron · BNB Chain · Solana · Bitcoin · Ethereum &amp; more</span>
          </div>
        </div>
      </div>
    </div>
  );
});

// ============================ ledger ============================
const Ledger = ({ wallet, extra, empty }) => {
  if (empty) {
    return (
      <div className="card card--flat mb-6 overflow-hidden">
        <div className={CARD_HEAD}><div className={CARD_TITLE}><UIcon name="activity" size={16} style={{ color: 'var(--fg-3)' }}/>Transaction history</div></div>
        <div className="flex flex-col items-center justify-center text-center py-[64px] px-5">
          <div className="w-12 h-12 rounded-control border border-line flex items-center justify-center text-fg-mute mb-4"><UIcon name="activity" size={24}/></div>
          <h4 className="font-sans font-semibold text-[17px] text-fg-1 mb-1.5">No wallet movements yet</h4>
          <p className="text-[13px] text-fg-3 max-w-[380px]">Top-ups, subscription debits, refunds and bonuses will appear here. Add funds to get started.</p>
        </div>
      </div>
    );
  }
  // running balance computed DOWN from the live wallet (top row = balance now)
  const rows = [...extra, ...BL_LEDGER];
  let bal = wallet;
  const withBal = rows.map(m => { const post = bal; bal = bal - m.amount; return { ...m, balance: post }; });
  return (
    <div className="card card--flat mb-6 overflow-hidden">
      <div className={CARD_HEAD}>
        <div className={CARD_TITLE}><UIcon name="activity" size={16} style={{ color: 'var(--fg-3)' }}/>Transaction history</div>
        <span className="font-mono text-[10.5px] text-fg-mute tracking-[0.04em] max-[640px]:hidden">Last {withBal.length} movements</span>
      </div>
      <div className="overflow-x-auto">
        <table className="w-full border-collapse min-w-[640px]">
          <thead>
            <tr className="border-b border-line-soft">
              {['Date', 'Type', 'Description', 'Amount', 'Balance'].map((h, i) => (
                <th key={h} className={"font-mono text-[9.5px] font-semibold tracking-[0.1em] uppercase text-fg-mute py-2.5 px-4 " + (i >= 3 ? "text-right" : "text-left")}>{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {withBal.map((m, i) => {
              const credit = m.amount > 0;
              const zero = Math.abs(m.amount) < 1e-9;
              return (
                <tr key={i} className="border-b border-line-soft last:border-b-0 hover:bg-hover transition-colors duration-fast">
                  <td className="py-3 px-4 font-mono text-[11.5px] text-fg-3 tabular-nums whitespace-nowrap">{m.date}</td>
                  <td className="py-3 px-4"><LedgerBadge type={m.type}/></td>
                  <td className="py-3 px-4 text-[12.5px] text-fg-2">{m.desc}</td>
                  <td className="py-3 px-4 text-right font-mono text-[12.5px] font-semibold tabular-nums whitespace-nowrap"
                    style={{ color: zero ? 'var(--fg-mute)' : credit ? 'var(--pnl-up-fg)' : 'var(--pnl-down-fg)' }}>
                    {zero ? '0.0000' : usdtSigned(m.amount)}
                  </td>
                  <td className="py-3 px-4 text-right font-mono text-[12px] text-fg-3 tabular-nums whitespace-nowrap">{usdt(m.balance)}</td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
};

// ============================ terms (collapsible) ============================
const Terms = () => {
  const [open, setOpen] = React.useState(false);
  return (
    <div className="card card--flat overflow-hidden">
      <button onClick={() => setOpen(o => !o)} className="w-full flex items-center gap-3 py-[15px] px-5 bg-transparent border-0 cursor-pointer text-left transition-colors duration-fast hover:bg-hover">
        <UIcon name="shield" size={16} style={{ color: 'var(--fg-3)', flexShrink: 0 }}/>
        <span className="font-sans font-semibold text-[14px] text-fg-1">Billing terms &amp; fine print</span>
        <span className="font-mono text-[10.5px] text-fg-mute tracking-[0.04em] ml-1 max-[560px]:hidden">monthly model · trial · fees · read-only</span>
        <UIcon name="chevronDown" size={18} style={{ color: 'var(--fg-mute)', marginLeft: 'auto', transform: open ? 'rotate(180deg)' : 'none', transition: 'transform .22s cubic-bezier(0.16,1,0.3,1)' }}/>
      </button>
      <div className="grid transition-[grid-template-rows] duration-[320ms] ease-[cubic-bezier(0.16,1,0.3,1)]" style={{ gridTemplateRows: open ? '1fr' : '0fr' }}>
        <div className="min-h-0 overflow-hidden">
          <div className="border-t border-line-soft px-5 py-5 grid grid-cols-2 gap-x-7 gap-y-5 max-[760px]:grid-cols-1">
            {BL_TERMS.map((t, i) => (
              <div key={i} className="flex items-start gap-3">
                <div className="w-[30px] h-[30px] rounded-control bg-surface-3 border border-line flex items-center justify-center text-fg-2 flex-shrink-0 mt-0.5"><UIcon name={t.icon} size={15}/></div>
                <div>
                  <div className="font-sans font-semibold text-[13px] text-fg-1 mb-1">{t.title}</div>
                  <div className="text-[12px] text-fg-3 leading-[1.5]">{t.body}</div>
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
};

// ============================ the page ============================
const Billing = ({ regime, score, billState = 'active' }) => {
  const seed = BL_SEED[billState] || BL_SEED.active;
  const [view, setView] = React.useState(billState);
  const [plan, setPlan] = React.useState(seed.plan);
  const [wallet, setWallet] = React.useState(seed.wallet);
  const [pausedSince, setPausedSince] = React.useState(seed.pausedSince);
  const [pausing, setPausing] = React.useState(false);
  const [switchTo, setSwitchTo] = React.useState(null);
  const [keepAcct, setKeepAcct] = React.useState(0);
  const [credited, setCredited] = React.useState(null);
  const [extraLedger, setExtraLedger] = React.useState([]);
  const [freshTrial, setFreshTrial] = React.useState(false);
  const [trialSecs, setTrialSecs] = React.useState((seed.trialHoursLeft || 0) * 3600);
  const topUpRef = React.useRef(null);
  const creditTimer = React.useRef(null);

  // reset everything when the demo state changes
  React.useEffect(() => {
    const s = BL_SEED[billState] || BL_SEED.active;
    setView(billState); setPlan(s.plan); setWallet(s.wallet); setPausedSince(s.pausedSince);
    setPausing(false); setSwitchTo(null); setKeepAcct(0); setCredited(null); setExtraLedger([]);
    setFreshTrial(false); setTrialSecs((s.trialHoursLeft || 0) * 3600);
  }, [billState]);

  // trial countdown ticking
  React.useEffect(() => {
    if (view !== 'trial') return;
    const t = setInterval(() => setTrialSecs(s => Math.max(0, s - 1)), 1000);
    return () => clearInterval(t);
  }, [view]);

  // live "credited" moment — one incoming top-up confirmation lands shortly after
  // entering the active view (demonstrates polling + the credit animation).
  React.useEffect(() => {
    clearTimeout(creditTimer.current);
    if (view !== 'active') return;
    creditTimer.current = setTimeout(() => {
      const amt = 25.0000;
      setWallet(w => +(w + amt).toFixed(4));
      setExtraLedger(e => [{ date: 'Jun 6', type: 'credit-topup', desc: 'Top-up · USDT (Solana · SPL)', amount: amt }, ...e]);
      setCredited(amt);
      setTimeout(() => setCredited(null), 4200);
    }, 5200);
    return () => clearTimeout(creditTimer.current);
  }, [view]);

  const rate = plan ? BL_PLAN(plan).price : 0;
  const covered = wallet >= rate;
  const shortfall = view === 'read-only'
    ? Math.max(0, BL_PLAN(plan).price - wallet)
    : Math.max(0, rate - wallet);
  const surplus = Math.max(0, wallet - rate);
  const renewalLabel = blDate(BL_RENEWAL);

  const focusTopUp = () => {
    setSwitchTo(null);
    requestAnimationFrame(() => {
      const el = topUpRef.current, c = document.querySelector('.content');
      if (el && c) c.scrollTo({ top: c.scrollTop + el.getBoundingClientRect().top - c.getBoundingClientRect().top - 16, behavior: 'smooth' });
    });
  };

  // proration preview for the active switch target
  const proration = React.useMemo(() => {
    if (!switchTo) return { refund: 0, debit: 0, walletAfter: wallet, net: 0 };
    const from = plan ? BL_PLAN(plan) : null;
    const refund = +(((from ? from.price : 0) * BL_DAYS_LEFT) / BL_CYCLE_DAYS).toFixed(4);
    const debit = BL_PLAN(switchTo).price;
    return { refund, debit, walletAfter: +(wallet + refund - debit).toFixed(4), net: +(refund - debit).toFixed(4) };
  }, [switchTo, plan, wallet]);

  const isTrialView = view === 'trial' || view === 'trial-ready';
  const downgradeAccts = !!(switchTo && plan === 'unlimited' && BL_PLAN(switchTo).accounts !== Infinity && ACCOUNTS.length > 1);

  const startSwitch = (toId) => { setSwitchTo(toId); setKeepAcct(0); };
  const confirmSwitch = () => {
    const toId = switchTo;
    if (!isTrialView) setWallet(proration.walletAfter);
    setPlan(toId);
    setSwitchTo(null);
    if (view === 'no-plan') setView('trial-ready');
  };
  const choosePlan = (id) => { setPlan(id); setView('trial-ready'); };
  const startTrial = () => { setFreshTrial(true); setTrialSecs(167.5 * 3600); setView('trial'); };
  const pauseConfirm = () => { setPausing(false); setPausedSince(blDate(BL_TODAY)); setView('paused'); };
  const resume = () => { setView('active'); setPausedSince(null); };
  const topUpSubmit = () => {};   // real credit lands on-chain; invoice flow shows the redirect

  const header = (
    <div className={PAGEHEAD}>
      <div>
        <div className={PH_EYEBROW}><UIcon name="wallet" size={13} style={{ width: 13, height: 13 }}/>SUBSCRIPTION</div>
        <h1 className={PH_H1}>Billing</h1>
        <div className={PH_SUB}>Fund and manage your Kraite subscription — prepaid in USDT, debited monthly by your plan.</div>
      </div>
      <div className="flex items-center gap-3 flex-shrink-0 max-[820px]:flex-wrap max-[820px]:gap-y-2.5">
        <RegimePill regime={regime} score={score} pulse={regime === 'CASCADE' || regime === 'BLACK SWAN'}/>
        <div className="w-px h-[22px] bg-line"/>
        <button className={BTN_SECONDARY}><UIcon name="refresh" size={15}/>Sync</button>
      </div>
    </div>
  );

  // ---- state banner ----
  let banner = null;
  if (view === 'no-plan') {
    banner = <BillBanner tone="accent" icon="flag" title="Welcome to Kraite — pick a plan to get started"
      action={<button onClick={focusPlans} className={BTN_PRIMARY + " h-[38px] px-4"}>See plans<UIcon name="arrowDown" size={15}/></button>}>
      Choose a plan below to begin your 7-day free trial. You won't be charged until the trial ends — and you can fund your wallet any time.
    </BillBanner>;
  } else if (view === 'trial-ready') {
    banner = <BillBanner tone="accent" icon="zap" title={`You're on ${BL_PLAN(plan).name} — start your 7-day free trial`}
      action={<button onClick={startTrial} className={BTN_PRIMARY + " h-[40px] px-5 text-[13px]"}><UIcon name="play" size={15}/>Start trading</button>}>
      Starting trading begins the trial and the bot goes live. The wallet stays untouched until your first renewal on {renewalLabel}.
    </BillBanner>;
  } else if (view === 'trial') {
    banner = <BillBanner tone="info" icon="clock" pulse title={`Free trial active — ${blFmtCountdown(trialSecs)} left`}>
      The bot is trading live. Your wallet is untouched during the trial; the first renewal{freshTrial ? '' : ` (${BL_PLAN(plan).name}, ${usdt2(rate)} USDT)`} lands on {renewalLabel}. Fund your wallet now so the first renewal can't fail.
    </BillBanner>;
  } else if (view === 'paused') {
    banner = <BillBanner tone="warn" icon="pause" title={`Subscription paused since ${pausedSince}`}
      action={<button onClick={resume} className={BTN_PRIMARY + " h-[40px] px-5 text-[13px]"}><UIcon name="play" size={15}/>Resume subscription</button>}>
      Renewals are stopped. Existing positions keep trading; new positions are blocked. Resuming moves your renewal date forward by the pause length.
    </BillBanner>;
  } else if (view === 'read-only') {
    banner = <BillBanner tone="danger" icon="lock" pulse title={`Read-only mode — renewal failed, ${usdt2(shortfall)} USDT short`}
      action={<button onClick={focusTopUp} className={BTN_PRIMARY + " h-[40px] px-5 text-[13px]"} style={{ background: 'var(--danger)', color: '#fff' }}><UIcon name="plus" size={15}/>Top up to retry now</button>}>
      The wallet couldn't cover the {BL_PLAN(plan).name} renewal. The bot has stopped opening new positions — existing positions still close at their take-profit or stop-loss. Top up to clear the shortfall and the renewal retries immediately.
    </BillBanner>;
  }

  const plansRef = React.useRef(null);
  function focusPlans() {
    const el = plansRef.current, c = document.querySelector('.content');
    if (el && c) c.scrollTo({ top: c.scrollTop + el.getBoundingClientRect().top - c.getBoundingClientRect().top - 16, behavior: 'smooth' });
  }

  const emptyLedger = view === 'no-plan' || view === 'trial-ready';

  return (
    <>
      {header}
      {banner}
      <WalletHero view={view} wallet={wallet} credited={credited} plan={plan} rate={rate}
        covered={covered} shortfall={shortfall} surplus={surplus}
        renewalLabel={renewalLabel} daysLeft={BL_DAYS_LEFT} trialSecs={trialSecs} freshTrial={freshTrial}
        pausedSince={pausedSince} pausing={pausing}
        onPauseStart={() => setPausing(true)} onPauseConfirm={pauseConfirm} onPauseCancel={() => setPausing(false)}
        onTopUp={focusTopUp}/>

      {/* plans */}
      <div ref={plansRef} className="scroll-mt-4">
        <div className="flex items-center justify-between gap-3 mb-4">
          <span className="font-mono text-[10.5px] font-semibold tracking-[0.12em] uppercase text-fg-mute">{view === 'no-plan' ? 'Choose a plan' : 'Plans'}</span>
          <span className="font-mono text-[10.5px] text-fg-faint tracking-[0.04em] max-[640px]:hidden">All plans include a 7-day free trial</span>
        </div>
        <div className="grid grid-cols-2 gap-4 max-[680px]:grid-cols-1">
          {BL_PLANS.map(p => (
            <PlanCard key={p.id} p={p} current={plan === p.id && view !== 'no-plan'}
              mode={view === 'no-plan' ? 'choose' : 'switch'}
              onSwitch={view === 'no-plan' ? choosePlan : startSwitch}/>
          ))}
        </div>
        {switchTo && (
          <SwitchConfirm fromId={plan} toId={switchTo} isTrial={isTrialView} proration={proration}
            downgradeAccts={downgradeAccts} keepAcct={keepAcct} setKeepAcct={setKeepAcct}
            onConfirm={confirmSwitch} onCancel={() => setSwitchTo(null)} onTopUp={focusTopUp}/>
        )}
      </div>

      <div className="h-6"/>
      <TopUp ref={topUpRef} covered={covered} shortfall={shortfall} rate={rate} onSubmit={topUpSubmit}/>
      <Ledger wallet={wallet} extra={extraLedger} empty={emptyLedger}/>
      <Terms/>
    </>
  );
};

Object.assign(window, { Billing });
