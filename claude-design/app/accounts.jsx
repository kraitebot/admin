// Kraite admin — Accounts page (composition). Form-control kit lives in
// accounts-kit.jsx (loaded before this).
//
// Structure: a list of exchange accounts, each an EXPANDABLE card (accordion,
// one open at a time — replaces the single-account picker). Expanding an account
// reveals two sub-tabs:
//   · General information  (config: identity / trading / slots / leverage) — default
//   · Connectivity         (credential handshake: keys, IP allowlist, test → save)
// The collapsed header stays scannable (badge, name, equity, trading state, status).

// ---- per-server connectivity result row ----
const ServerResultRow = ({ s, status }) => {
  const c = status === 'ok' ? 'var(--pnl-up-fg)' : status === 'fail' ? 'var(--danger)' : status === 'testing' ? 'var(--info)' : 'var(--fg-faint)';
  return (
    <div className="flex items-center gap-3 py-2.5 px-3.5 border-b border-line-soft last:border-b-0">
      <span className="w-[18px] flex items-center justify-center flex-shrink-0">
        {status === 'testing'
          ? <span className="w-[13px] h-[13px] rounded-full border-2 border-line-strong border-t-info animate-spin"/>
          : status === 'ok' ? <UIcon name="check" size={15} style={{ color: c }}/>
          : status === 'fail' ? <UIcon name="plugOff" size={14} style={{ color: c }}/>
          : <span className="w-[7px] h-[7px] rounded-chip" style={{ background: c }}/>}
      </span>
      <span className="font-mono text-[12px] font-semibold text-fg-1 tracking-[0.02em]">{s.id}</span>
      <span className="font-mono text-[10.5px] tracking-[0.08em] uppercase text-fg-mute">{s.region}</span>
      <span className="font-mono text-[11px] text-fg-faint tabular-nums ml-auto">{s.ip}</span>
      <span className="font-mono text-[10px] font-bold tracking-[0.09em] uppercase w-[78px] text-right" style={{ color: c }}>
        {status === 'ok' ? 'Connected' : status === 'fail' ? 'Blocked' : status === 'testing' ? 'Testing' : 'Queued'}
      </span>
    </div>
  );
};

// Save (connection) — primary; morphs to a checkmark briefly on save.
const SaveButton = ({ disabled, onSave, label }) => {
  const [done, setDone] = React.useState(false);
  const click = () => { if (disabled) return; onSave(); setDone(true); setTimeout(() => setDone(false), 1900); };
  return (
    <button onClick={click} disabled={disabled}
      className={BTN_PRIMARY + " h-[40px] px-4 " + (disabled ? "opacity-40 cursor-not-allowed hover:bg-accent" : "")}
      style={done ? { background: 'var(--pnl-up-fg)', color: '#04140d' } : undefined}>
      {done ? <><UIcon name="check" size={16}/>Saved</> : <><UIcon name="shield" size={15}/>{label}</>}
    </button>
  );
};

// Save (configuration) — idle | saving (spinner) | done (checkmark) → reverts.
const ConfigSaveButton = ({ state, onSave, disabled }) => (
  <button onClick={() => state === 'idle' && onSave()} disabled={disabled || state !== 'idle'}
    className={BTN_PRIMARY + " h-[40px] px-5 min-w-[188px] justify-center " + (disabled ? "opacity-40 cursor-not-allowed hover:bg-accent" : "")}
    style={state === 'done' ? { background: 'var(--pnl-up-fg)', color: '#04140d' } : undefined}>
    {state === 'saving' ? <><span className="w-[15px] h-[15px] rounded-full border-2 border-[rgba(4,20,13,.35)] border-t-[#04140d] animate-spin"/>Saving…</>
      : state === 'done' ? <><UIcon name="check" size={16}/>Configuration saved</>
      : <>Save configuration</>}
  </button>
);

const AC_DIR_ASIDE = (txt, dir) => <span className="font-mono text-[9px] font-bold tracking-[0.1em] uppercase" style={{ color: dir === 'long' ? 'var(--pnl-up-fg)' : 'var(--pnl-down-fg)' }}>{txt}</span>;

// ============================ one account (expandable) ============================
const AccountCard = ({ acct, idx, demoState, expanded, onToggle, single }) => {
  const key = AC_KEY(acct);
  const meta = AC_META[key];
  // focused account (index 0 / the single account) is driven by the demo tweak;
  // all others derive their state naturally from their connection status.
  const focused = idx === 0;
  const eff = focused ? demoState : (acct.state === 'down' ? 'trading-disabled' : 'connected');
  const passField = meta.needsPass;
  const failIds = ['kr-sgp-01', 'kr-sgp-02'];
  const connStates = ['first-run', 'testing', 'test-failed', 'trading-disabled'];

  const allOk = () => Object.fromEntries(SERVERS.map(s => [s.id, 'ok']));
  const allFail = () => Object.fromEntries(SERVERS.map(s => [s.id, failIds.includes(s.id) ? 'fail' : 'ok']));
  const initCreds = (st) => st === 'first-run'
    ? { key: '', secret: '', pass: '', phase: 'empty' }
    : { key: 'kx_live_8f3a…d21', secret: '••••••••••••••••', pass: meta.needsPass ? '••••••' : '',
        phase: st === 'testing' ? 'testing' : (st === 'test-failed' || st === 'trading-disabled') ? 'fail' : 'ok' };
  const initResults = (st) => (st === 'test-failed' || st === 'trading-disabled') ? allFail()
    : st === 'connected' ? allOk() : Object.fromEntries(SERVERS.map(s => [s.id, 'pending']));

  const [creds, setCreds] = React.useState(() => initCreds(eff));
  const [results, setResults] = React.useState(() => initResults(eff));
  const [cfg, setCfg] = React.useState(() => ({ ...AC_DEFAULT_CFG[key] }));
  const [cfgSaved, setCfgSaved] = React.useState('idle');
  const [tab, setTab] = React.useState(connStates.includes(eff) ? 'connectivity' : 'general');
  const timers = React.useRef([]);

  // re-init when the focused demo state (or account) changes
  React.useEffect(() => {
    timers.current.forEach(clearTimeout); timers.current = [];
    setCreds(initCreds(eff));
    setResults(initResults(eff));
    setCfg({ ...AC_DEFAULT_CFG[key] });
    setCfgSaved('idle');
    setTab(connStates.includes(eff) ? 'connectivity' : 'general');
    if (eff === 'testing') runTest(true);
    return () => { timers.current.forEach(clearTimeout); timers.current = []; };
    // eslint-disable-next-line
  }, [eff, key]);

  // live progressive test — servers resolve one-by-one, Save unlocks on completion
  const runTest = (forceFail) => {
    timers.current.forEach(clearTimeout); timers.current = [];
    setCreds(c => ({ ...c, phase: 'testing' }));
    setResults(Object.fromEntries(SERVERS.map(s => [s.id, 'pending'])));
    SERVERS.forEach((s, i) => {
      timers.current.push(setTimeout(() => setResults(r => ({ ...r, [s.id]: 'testing' })), 220 + i * 540));
      timers.current.push(setTimeout(() => {
        const bad = forceFail && failIds.includes(s.id);
        setResults(r => ({ ...r, [s.id]: bad ? 'fail' : 'ok' }));
      }, 220 + i * 540 + 460));
    });
    timers.current.push(setTimeout(() => setCreds(c => ({ ...c, phase: forceFail ? 'fail' : 'ok' })), 220 + SERVERS.length * 540 + 480));
  };

  const setC = (k, v) => setCfg(c => ({ ...c, [k]: v }));
  const saveCfg = () => {
    setCfgSaved('saving');
    timers.current.push(setTimeout(() => setCfgSaved('done'), 520));
    timers.current.push(setTimeout(() => setCfgSaved('idle'), 2200));
  };
  const editCred = (field, v) => setCreds(c => ({ ...c, [field]: v, phase: c.phase === 'ok' ? 'idle' : c.phase === 'empty' ? 'idle' : c.phase }));

  const phase = creds.phase;
  const tested = phase === 'ok' || phase === 'fail';
  const testing = phase === 'testing';
  const canTest = !testing && (creds.key.trim() && creds.secret.trim() && (!meta.needsPass || creds.pass.trim()));
  const canSave = tested && !testing;
  const connectionUsable = phase === 'ok';
  const tradingDisabled = phase === 'fail';
  const quotesLoading = eff === 'quotes-loading';
  const quotesEmpty = eff === 'quotes-empty';
  const quoteOpts = meta.quotes;
  const configLocked = phase === 'empty';
  const statusKind = testing ? 'testing' : phase === 'ok' ? 'ok' : phase === 'fail' ? 'disabled' : 'none';
  const tradingActive = connectionUsable && cfg.canTrade;

  // ---------- collapsed header (always visible) ----------
  // trading pill only when the connection is usable — otherwise the status chip
  // (Trading disabled / Testing / Not connected) already carries the state.
  const tradingPill = phase !== 'ok' ? null : (
    <span className="hidden sm:inline-flex items-center gap-1.5 font-mono text-[9.5px] font-bold tracking-[0.09em] uppercase"
      style={{ color: tradingActive ? 'var(--pnl-up-fg)' : 'var(--fg-mute)' }}>
      <span className="w-[6px] h-[6px] rounded-chip" style={{ background: tradingActive ? 'var(--pnl-up-fg)' : 'var(--border-strong)' }}/>
      {tradingActive ? 'Trading' : 'Paused'}
    </span>
  );

  const header = (
    <button onClick={single ? undefined : onToggle}
      className={"w-full flex items-center gap-3.5 py-4 px-6 text-left bg-transparent border-0 max-[640px]:px-4 transition-colors duration-fast ease-out " + (single ? "cursor-default" : "cursor-pointer hover:bg-hover")}>
      {!single && (
        <UIcon name="chevronDown" size={18} style={{ color: 'var(--fg-mute)', flexShrink: 0, transform: expanded ? 'rotate(180deg)' : 'none', transition: 'transform .22s cubic-bezier(0.16,1,0.3,1)' }}/>
      )}
      <span className="w-[36px] h-[36px] rounded-full bg-surface-3 text-fg-1 font-mono font-bold text-[14px] flex items-center justify-center flex-shrink-0">{acct.mono}</span>
      <div className="flex flex-col leading-[1.2] min-w-0">
        <span className="text-[14.5px] font-semibold text-fg-1 whitespace-nowrap">{acct.ex} <span className="text-fg-mute font-normal">· {acct.tag}</span></span>
        <span className="font-mono text-[10.5px] text-fg-mute tracking-[0.02em] whitespace-nowrap">{meta.owner} · {acct.note}</span>
      </div>
      <div className="ml-auto flex items-center gap-4 flex-shrink-0 max-[640px]:gap-2.5">
        {tradingPill}
        <span className="font-mono text-[13px] font-semibold text-fg-1 tabular-nums max-[480px]:hidden">{acct.equity}</span>
        <AcctStatusChip kind={statusKind}/>
      </div>
    </button>
  );

  // ---------- sub-tab bar ----------
  const TabBtn = ({ id, label, dot }) => {
    const on = tab === id;
    return (
      <button onClick={() => setTab(id)}
        className="relative inline-flex items-center gap-2 py-3.5 bg-transparent border-0 font-mono text-[12px] font-semibold tracking-[0.04em] transition-colors duration-fast ease-out cursor-pointer"
        style={{ color: on ? 'var(--fg-1)' : 'var(--fg-mute)' }}>
        {label}
        {dot && <span className="w-[7px] h-[7px] rounded-chip" style={{ background: dot }}/>}
        {on && <span className="absolute left-0 right-0 -bottom-px h-[2px] rounded-t" style={{ background: 'var(--accent)' }}/>}
      </button>
    );
  };
  const connDot = statusKind === 'ok' ? 'var(--pnl-up-fg)' : statusKind === 'disabled' ? 'var(--warn)' : statusKind === 'testing' ? 'var(--info)' : 'var(--fg-faint)';
  const tabBar = (
    <div className="flex items-center gap-7 px-6 border-b border-line-soft max-[640px]:px-4 max-[640px]:gap-5">
      <TabBtn id="general" label="General information"/>
      <TabBtn id="connectivity" label="Connectivity" dot={connDot}/>
    </div>
  );

  // ---------- General information panel ----------
  const generalPanel = (
    <div className={configLocked ? "opacity-40 pointer-events-none select-none" : ""}>
      {configLocked && (
        <div className="flex items-center gap-2.5 py-3 px-6 border-b border-line-soft max-[640px]:px-4">
          <UIcon name="key" size={14} style={{ color: 'var(--fg-mute)' }}/>
          <span className="text-[12.5px] text-fg-3">Connect this account first — configuration unlocks after a successful connection.</span>
        </div>
      )}
      <AcctGroup title="Identity" icon="user">
        <AcctField label="Account name" htmlFor={key + '-name'} help="Label only — has no effect on trading.">
          <AcctInput id={key + '-name'} value={cfg.cfgName ?? meta.cfgName} onChange={(v) => setC('cfgName', v)} placeholder="Account name"/>
        </AcctField>
        <AcctField label="Trading enabled" help={cfg.canTrade ? 'Bot may open and manage positions on this account.' : 'Master off — bot will not trade this account.'}>
          <div className="h-[42px] flex items-center gap-3 px-3.5 rounded-control border border-line bg-input">
            <AcctToggle checked={cfg.canTrade} onChange={(v) => setC('canTrade', v)} disabled={!connectionUsable}/>
            <span className="font-mono text-[12px] font-semibold tracking-[0.03em]" style={{ color: cfg.canTrade ? 'var(--pnl-up-fg)' : 'var(--fg-mute)' }}>{cfg.canTrade ? 'CAN TRADE' : 'PAUSED'}</span>
            {!connectionUsable && <span className="ml-auto font-mono text-[9.5px] tracking-[0.06em] uppercase text-fg-faint">needs connection</span>}
          </div>
        </AcctField>
        <AcctField label="Portfolio quote" help="Currency the portfolio is valued in.">
          <AcctSelect value={cfg.pq} onChange={(v) => setC('pq', v)} options={quoteOpts} loading={quotesLoading} empty={quotesEmpty}/>
        </AcctField>
        <AcctField label="Trading quote" help="Quote currency for new positions.">
          <AcctSelect value={cfg.tq} onChange={(v) => setC('tq', v)} options={quoteOpts} loading={quotesLoading} empty={quotesEmpty}/>
        </AcctField>
      </AcctGroup>

      <AcctGroup title="Trading" icon="gauge" hint="per position">
        <AcctField label="Profit target" htmlFor={key + '-pt'}>
          <AcctSelect value={cfg.pt} onChange={(v) => setC('pt', v)} options={AC_OPTS.pt}/>
        </AcctField>
        <AcctField label="Stop-loss" htmlFor={key + '-sl'}>
          <AcctSelect value={cfg.sl} onChange={(v) => setC('sl', v)} options={AC_OPTS.sl}/>
        </AcctField>
      </AcctGroup>

      <AcctGroup title="Position slots" icon="layers" hint="max concurrent">
        <AcctField label="Long slots" dir="long" aside={AC_DIR_ASIDE('Long', 'long')}>
          <AcctSelect value={cfg.sL} onChange={(v) => setC('sL', v)} options={AC_OPTS.slots} dir="long"/>
        </AcctField>
        <AcctField label="Short slots" dir="short" aside={AC_DIR_ASIDE('Short', 'short')}>
          <AcctSelect value={cfg.sS} onChange={(v) => setC('sS', v)} options={AC_OPTS.slots} dir="short"/>
        </AcctField>
      </AcctGroup>

      <AcctGroup title="Leverage & margin" icon="coins">
        <AcctField label="Leverage — long" dir="long" aside={AC_DIR_ASIDE('Long', 'long')}>
          <AcctSelect value={cfg.lL} onChange={(v) => setC('lL', v)} options={AC_OPTS.lev} dir="long"/>
        </AcctField>
        <AcctField label="Leverage — short" dir="short" aside={AC_DIR_ASIDE('Short', 'short')}>
          <AcctSelect value={cfg.lS} onChange={(v) => setC('lS', v)} options={AC_OPTS.lev} dir="short"/>
        </AcctField>
        <AcctField label="Margin % — long" dir="long" aside={AC_DIR_ASIDE('Long', 'long')}>
          <AcctSelect value={cfg.mL} onChange={(v) => setC('mL', v)} options={AC_OPTS.margin} dir="long"/>
        </AcctField>
        <AcctField label="Margin % — short" dir="short" aside={AC_DIR_ASIDE('Short', 'short')}>
          <AcctSelect value={cfg.mS} onChange={(v) => setC('mS', v)} options={AC_OPTS.margin} dir="short"/>
        </AcctField>
      </AcctGroup>

      <div className="flex items-center gap-3 py-4 px-6 max-[640px]:px-4 max-[560px]:flex-col max-[560px]:items-stretch">
        <ConfigSaveButton state={cfgSaved} onSave={saveCfg} disabled={configLocked}/>
        <span className="font-mono text-[10.5px] text-fg-mute tracking-[0.04em] max-[560px]:text-center">Applies to new positions opened after saving</span>
      </div>
    </div>
  );

  // ---------- Connectivity panel ----------
  const connectivityPanel = (
    <div>
      {tradingDisabled && (
        <div className="m-6 mb-0 rounded-control border px-4 py-3.5 flex items-start gap-3 max-[640px]:mx-4"
          style={{ borderColor: 'color-mix(in srgb, var(--warn) 42%, transparent)', background: 'color-mix(in srgb, var(--warn) 11%, transparent)' }}>
          <UIcon name="alert" size={17} style={{ color: 'var(--warn)', flexShrink: 0, marginTop: 1 }}/>
          <div className="flex-1 min-w-0">
            <div className="font-sans font-semibold text-[13px] text-fg-1 leading-tight">Trading is disabled on this account</div>
            <div className="text-[12px] text-fg-3 mt-1 leading-snug">Some Kraite IP addresses are not allowlisted in your {acct.ex} account. Keys are saved, but the bot will not open or manage positions here until the test passes from every server.</div>
          </div>
        </div>
      )}

      <div className="border-b border-line-soft">
        <AcctBandHead icon="key" title="API credentials"/>
        <div className="py-5 px-6 max-[640px]:px-4">
          <div className="grid grid-cols-2 gap-x-5 gap-y-5 max-[700px]:grid-cols-1">
            <AcctField label="API key" htmlFor={key + '-apikey'} help={phase === 'empty' ? 'Read + trade permission required. No withdrawal permission.' : null}>
              <AcctInput id={key + '-apikey'} value={creds.key} onChange={(v) => editCred('key', v)} mono placeholder="Paste API key" disabled={testing}/>
            </AcctField>
            <AcctField label="API secret" htmlFor={key + '-apisecret'} help={phase === 'empty' ? 'Shown once by the exchange — store it safely.' : null}>
              <AcctInput id={key + '-apisecret'} value={creds.secret} onChange={(v) => editCred('secret', v)} mono secret placeholder="Paste API secret" disabled={testing}/>
            </AcctField>
            {passField && (
              <AcctField label="API passphrase" htmlFor={key + '-apipass'} help="Required by this exchange.">
                <AcctInput id={key + '-apipass'} value={creds.pass} onChange={(v) => editCred('pass', v)} mono secret placeholder="Paste passphrase" disabled={testing}/>
              </AcctField>
            )}
          </div>
        </div>
      </div>

      <div className="border-b border-line-soft">
        <AcctBandHead icon="shield" title="Allowlist Kraite's IP addresses"
          right={<AcctCopy text={AC_IPS.map(i => i.ip).join('\n')} label="Copy all" full/>}/>
        <div className="py-5 px-6 max-[640px]:px-4">
          <p className="text-[12px] text-fg-3 mb-3 leading-snug max-w-[480px]">Add every address below to your {acct.ex} API key's IP restriction. <span className="text-fg-2">Missing IPs are the #1 reason a test fails.</span></p>
          <div className="grid grid-cols-2 gap-2 max-[700px]:grid-cols-1">
            {AC_IPS.map(ip => (
              <div key={ip.id} className="flex items-center gap-3 py-2 px-3 rounded-control border border-line-soft bg-surface-2">
                <span className="font-mono text-[12.5px] font-semibold text-fg-1 tabular-nums tracking-[0.02em]">{ip.ip}</span>
                <span className="font-mono text-[10px] tracking-[0.07em] uppercase text-fg-mute">{ip.region}</span>
                <span className="ml-auto"><AcctCopy text={ip.ip}/></span>
              </div>
            ))}
          </div>
        </div>
      </div>

      {(testing || tested) && (
        <div className="border-b border-line-soft">
          <AcctBandHead icon="server" title="Connectivity from Kraite servers"
            right={<span className="font-mono text-[10.5px] text-fg-mute tabular-nums">{Object.values(results).filter(v => v === 'ok').length}/{SERVERS.length} connected</span>}/>
          <div className="py-5 px-6 max-[640px]:px-4">
            <div className="rounded-control border border-line-soft overflow-hidden bg-surface-2">
              {SERVERS.map(s => <ServerResultRow key={s.id} s={AC_IPS.find(i => i.id === s.id) || s} status={results[s.id]}/>)}
            </div>
          </div>
        </div>
      )}

      <div className="flex items-center gap-3 py-4 px-6 max-[640px]:px-4 max-[560px]:flex-col max-[560px]:items-stretch">
        <button onClick={() => runTest(eff === 'first-run' ? false : tradingDisabled)} disabled={!canTest}
          className={BTN_SECONDARY + " h-[40px] px-4 " + (!canTest ? "opacity-40 cursor-not-allowed hover:bg-transparent" : "")}>
          {testing ? <><span className="w-[14px] h-[14px] rounded-full border-2 border-line-strong border-t-fg-1 animate-spin"/>Testing…</> : <><UIcon name="refresh" size={15}/>{tested ? 'Re-test connection' : 'Test connection'}</>}
        </button>
        <SaveButton disabled={!canSave} onSave={() => {}} label={tradingDisabled ? 'Save keys (trading stays off)' : 'Save & enable trading'}/>
        {!tested && !testing && <span className="font-mono text-[10.5px] text-fg-mute tracking-[0.04em] max-[560px]:text-center">Save unlocks after a successful test</span>}
        {phase === 'idle' && (creds.key || creds.secret) && <span className="font-mono text-[10.5px] tracking-[0.04em] max-[560px]:text-center" style={{ color: 'var(--warn)' }}>Credentials changed — re-test required</span>}
      </div>
    </div>
  );

  const tinted = statusKind === 'disabled';
  return (
    <div className="card card--flat overflow-hidden"
      style={tinted ? { borderColor: 'color-mix(in srgb, var(--warn) 32%, var(--border))' } : undefined}>
      {header}
      <div className="grid transition-[grid-template-rows] duration-[320ms] ease-[cubic-bezier(0.16,1,0.3,1)]"
        style={{ gridTemplateRows: expanded ? '1fr' : '0fr' }}>
        <div className="min-h-0 overflow-hidden">
          <div className="border-t border-line-soft">
            {tabBar}
            <div>{tab === 'general' ? generalPanel : connectivityPanel}</div>
          </div>
        </div>
      </div>
    </div>
  );
};

// ============================ the page ============================
const ACCT_STATES = ['connected', 'first-run', 'testing', 'test-failed', 'trading-disabled', 'quotes-loading', 'quotes-empty', 'single'];

const Accounts = ({ regime, score, acctState = 'connected' }) => {
  const single = acctState === 'single';
  const accts = single ? [ACCOUNTS[0]] : ACCOUNTS;
  const [openIdx, setOpenIdx] = React.useState(0);
  // focus the first account whenever the demo state changes (so its state is visible)
  React.useEffect(() => { setOpenIdx(0); }, [acctState]);

  const header = (
    <div className={PAGEHEAD}>
      <div>
        <div className={PH_EYEBROW}><UIcon name="accounts" size={13} style={{ width: 13, height: 13 }}/>EXCHANGES</div>
        <h1 className={PH_H1}>Accounts</h1>
        <div className={PH_SUB}>Connect and configure the exchange accounts the bot trades on.</div>
      </div>
      <div className="flex items-center gap-3 flex-shrink-0 max-[820px]:flex-wrap max-[820px]:gap-y-2.5">
        <RegimePill regime={regime} score={score} pulse={regime === 'CASCADE' || regime === 'BLACK SWAN'}/>
        <div className="w-px h-[22px] bg-line"/>
        <button className={BTN_SECONDARY}><UIcon name="refresh" size={15}/>Sync</button>
      </div>
    </div>
  );

  return (
    <>
      {header}
      {!single && (
        <div className="flex items-center justify-between gap-3 mb-4">
          <span className="font-mono text-[10.5px] font-semibold tracking-[0.12em] uppercase text-fg-mute">Your exchange accounts · {accts.length}</span>
          <span className="font-mono text-[10.5px] text-fg-faint tracking-[0.04em] max-[640px]:hidden">Expand an account to configure it</span>
        </div>
      )}
      <div className="flex flex-col gap-3">
        {accts.map((acct, idx) => (
          <AccountCard key={AC_KEY(acct)} acct={acct} idx={idx} demoState={acctState}
            single={single}
            expanded={single || openIdx === idx}
            onToggle={() => setOpenIdx(o => (o === idx ? -1 : idx))}/>
        ))}
      </div>
    </>
  );
};

Object.assign(window, { Accounts });
