// Kraite SYSADMIN console — Settings: the single runtime config record. Regime
// thresholds, cooldown windows, notification channels, and the global trading
// guards every account inherits. Highest-leverage, most-guarded screen — changes
// re-tune the whole fleet without a deploy. Uses the shared form kit (Acct*).

const AdminSaveBar = ({ state, onSave, dirty }) => (
  <div className="flex items-center gap-3 py-4 px-5 bg-surface-2 border-t border-line-soft max-[560px]:flex-col max-[560px]:items-stretch">
    <button onClick={() => state === 'idle' && onSave()} disabled={state !== 'idle' || !dirty}
      className={A_BTN_PRIMARY + " h-[40px] px-5 min-w-[200px] justify-center " + (!dirty ? "opacity-40 cursor-not-allowed hover:bg-accent" : "")}
      style={state === 'done' ? { background: 'var(--pnl-up-fg)', color: '#04140d' } : undefined}>
      {state === 'saving' ? <><span className="w-[15px] h-[15px] rounded-full border-2 border-[rgba(255,255,255,.35)] border-t-white animate-spin"/>Applying to fleet…</>
        : state === 'done' ? <><UIcon name="check" size={16}/>Applied to all accounts</>
        : <><UIcon name="shield" size={15}/>Apply configuration</>}
    </button>
    <span className="font-mono text-[10.5px] tracking-[0.04em] max-[560px]:text-center" style={{ color: dirty ? 'var(--warn)' : 'var(--fg-mute)' }}>
      {dirty ? 'Unsaved — applies to every account on save' : 'Inherited by all 1,284 accounts · changes are audited'}
    </span>
  </div>
);

const NotifRow = ({ ch, on, endpoint, onToggle, last }) => (
  <div className={"flex items-center gap-4 py-3.5 px-5 max-[640px]:px-4 " + (last ? "" : "border-b border-line-soft")}>
    <div className="flex flex-col gap-0.5 min-w-0">
      <span className="font-sans text-[13px] font-semibold text-fg-1">{ch}</span>
      <span className="font-mono text-[10.5px] text-fg-mute tracking-[0.01em] truncate">{endpoint}</span>
    </div>
    <div className="ml-auto flex items-center gap-3 flex-shrink-0">
      <span className="font-mono text-[9.5px] font-bold tracking-[0.07em] uppercase" style={{ color: on ? 'var(--pnl-up-fg)' : 'var(--fg-mute)' }}>{on ? 'ON' : 'OFF'}</span>
      <AcctToggle checked={on} onChange={onToggle}/>
    </div>
  </div>
);

const AdminSettings = () => {
  const SEED = {
    maint: false, allowOpens: true,
    watch: '0.40', elevated: '0.55', cascade: '0.78', blackswan: '0.90',
    coolStop: '15 min', coolRegime: '30 min', backoff: '4× exp',
    maxLev: '20×', maxNotional: '6.00%', maxConc: '10', dailyLoss: '8.00%',
    slack: true, pager: true, email: false, webhook: true,
  };
  const [v, setV] = React.useState(SEED);
  const [saved, setSaved] = React.useState('idle');
  const timers = React.useRef([]);
  React.useEffect(() => () => timers.current.forEach(clearTimeout), []);
  const set = (k, val) => { setV(s => ({ ...s, [k]: val })); if (saved === 'done') setSaved('idle'); };
  const dirty = JSON.stringify(v) !== JSON.stringify(SEED);
  const save = () => { setSaved('saving'); timers.current.push(setTimeout(() => setSaved('done'), 620)); timers.current.push(setTimeout(() => setSaved('idle'), 2400)); };

  return (
    <>
      <div className={A_PAGEHEAD}>
        <div>
          <div className={A_EYEBROW}><UIcon name="sliders" size={13} style={{ width: 13, height: 13 }}/>CONTROL</div>
          <h1 className={A_H1}>System settings</h1>
          <div className={A_SUB}>The single runtime config record every account inherits — guards, thresholds, and channels.</div>
        </div>
        <div className="flex items-center gap-3 flex-shrink-0">
          <span className="inline-flex items-center gap-2 py-[6px] px-3 rounded-chip border font-mono text-[10px] font-bold tracking-[0.08em] uppercase text-fg-3 border-line"><UIcon name="lock" size={12} style={{ color: 'var(--fg-mute)' }}/>guarded</span>
        </div>
      </div>

      {/* maintenance mode */}
      <div className="card card--flat overflow-hidden mb-5" style={v.maint ? { borderColor: 'color-mix(in srgb, var(--warn) 38%, var(--border))' } : undefined}>
        <div className="flex items-center gap-4 py-4 px-5 max-[640px]:px-4" style={v.maint ? { background: 'color-mix(in srgb, var(--warn) 8%, transparent)' } : undefined}>
          <span className="w-[36px] h-[36px] rounded-control flex items-center justify-center flex-shrink-0" style={{ background: v.maint ? 'color-mix(in srgb, var(--warn) 16%, transparent)' : 'var(--bg-elev-3)', color: v.maint ? 'var(--warn)' : 'var(--fg-mute)' }}><UIcon name="maintenance" size={18}/></span>
          <div className="flex flex-col gap-0.5 min-w-0">
            <span className="font-sans text-[14px] font-semibold text-fg-1">Maintenance mode</span>
            <span className="text-[12px] text-fg-3 leading-snug">{v.maint ? 'Writes gated platform-wide · a banner is shown to every trader.' : 'Platform operating normally. Enabling gates writes and posts a status banner.'}</span>
          </div>
          <div className="ml-auto flex items-center gap-3 flex-shrink-0">
            <span className="font-mono text-[9.5px] font-bold tracking-[0.07em] uppercase" style={{ color: v.maint ? 'var(--warn)' : 'var(--fg-mute)' }}>{v.maint ? 'ON' : 'OFF'}</span>
            <AcctToggle checked={v.maint} onChange={(x) => set('maint', x)}/>
          </div>
        </div>
      </div>

      <div className="card card--flat overflow-hidden">
        <AcctGroup title="Regime thresholds" icon="shield" hint="BSCS score cutoffs">
          <AcctField label="WATCH at" htmlFor="s-watch"><AcctInput id="s-watch" value={v.watch} onChange={(x) => set('watch', x)} mono/></AcctField>
          <AcctField label="ELEVATED at" htmlFor="s-elev"><AcctInput id="s-elev" value={v.elevated} onChange={(x) => set('elevated', x)} mono/></AcctField>
          <AcctField label="CASCADE at" htmlFor="s-casc"><AcctInput id="s-casc" value={v.cascade} onChange={(x) => set('cascade', x)} mono/></AcctField>
          <AcctField label="BLACK SWAN at" htmlFor="s-bs"><AcctInput id="s-bs" value={v.blackswan} onChange={(x) => set('blackswan', x)} mono/></AcctField>
        </AcctGroup>

        <AcctGroup title="Cooldown windows" icon="clock">
          <AcctField label="After a stop-loss"><AcctSelect value={v.coolStop} onChange={(x) => set('coolStop', x)} options={['5 min', '15 min', '30 min', '60 min']}/></AcctField>
          <AcctField label="After regime escalation"><AcctSelect value={v.coolRegime} onChange={(x) => set('coolRegime', x)} options={['15 min', '30 min', '60 min', '120 min']}/></AcctField>
          <AcctField label="Reconnect backoff"><AcctSelect value={v.backoff} onChange={(x) => set('backoff', x)} options={['2× exp', '4× exp', '8× exp']}/></AcctField>
        </AcctGroup>

        <AcctGroup title="Global trading guards" icon="gauge" hint="inherited by every account">
          <AcctField label="Allow new opens" help={v.allowOpens ? 'Engine may open new positions fleet-wide.' : 'Master off — no new opens anywhere.'}>
            <div className="h-[42px] flex items-center gap-3 px-3.5 rounded-control border border-line bg-input">
              <AcctToggle checked={v.allowOpens} onChange={(x) => set('allowOpens', x)}/>
              <span className="font-mono text-[12px] font-semibold tracking-[0.03em]" style={{ color: v.allowOpens ? 'var(--pnl-up-fg)' : 'var(--fg-mute)' }}>{v.allowOpens ? 'ENABLED' : 'BLOCKED'}</span>
            </div>
          </AcctField>
          <AcctField label="Max leverage"><AcctSelect value={v.maxLev} onChange={(x) => set('maxLev', x)} options={['10×', '15×', '20×', '25×']}/></AcctField>
          <AcctField label="Max notional / position"><AcctSelect value={v.maxNotional} onChange={(x) => set('maxNotional', x)} options={['4.00%', '5.00%', '6.00%', '8.00%']}/></AcctField>
          <AcctField label="Max concurrent positions"><AcctSelect value={v.maxConc} onChange={(x) => set('maxConc', x)} options={['6', '8', '10', '12']}/></AcctField>
          <AcctField label="Daily loss limit"><AcctSelect value={v.dailyLoss} onChange={(x) => set('dailyLoss', x)} options={['5.00%', '8.00%', '10.00%', '12.00%']}/></AcctField>
        </AcctGroup>

        <div className="border-b border-line-soft">
          <AcctBandHead icon="bell" title="Notification channels"/>
          <NotifRow ch="Slack · #kraite-ops" endpoint="hooks.slack.com/services/T0…/B0…" on={v.slack} onToggle={(x) => set('slack', x)}/>
          <NotifRow ch="PagerDuty · Platform on-call" endpoint="events.pagerduty.com · severity ≥ high" on={v.pager} onToggle={(x) => set('pager', x)}/>
          <NotifRow ch="Email digest" endpoint="ops@kraite.io · daily 08:00 UTC" on={v.email} onToggle={(x) => set('email', x)}/>
          <NotifRow ch="Webhook" endpoint="https://internal.kraite.io/hooks/events" on={v.webhook} onToggle={(x) => set('webhook', x)} last/>
        </div>

        <AdminSaveBar state={saved} onSave={save} dirty={dirty}/>
      </div>
    </>
  );
};

Object.assign(window, { AdminSettings });
