// Kraite admin — Profile page (composition). First pass: personal identity +
// sign-in security. Reuses the system-wide form vocabulary from accounts-kit
// (AcctField / AcctInput / AcctSelect / AcctToggle / AcctGroup / AcctBandHead)
// and the shared page-head + button constants from dashboard.
//
// Two cards:
//   · Identity — avatar (monogram, swappable), name fields, email, locked role.
//   · Sign-in & security — change password (current → new → confirm, with a live
//     strength meter + match check), plus a two-factor toggle.
// Role is read-only (set by the administrator). Save buttons morph idle→saving→done.

// ---------- save bar: idle | saving | done → reverts ----------
const ProfileSaveBar = ({ state, onSave, disabled, dirty, label = 'Save changes', note }) => (
  <div className="flex items-center gap-3 py-4 px-6 max-[640px]:px-4 max-[560px]:flex-col max-[560px]:items-stretch">
    <button onClick={() => state === 'idle' && onSave()} disabled={disabled || state !== 'idle'}
      className={BTN_PRIMARY + " h-[40px] px-5 min-w-[168px] justify-center " + (disabled ? "opacity-40 cursor-not-allowed hover:bg-accent" : "")}
      style={state === 'done' ? { background: 'var(--pnl-up-fg)', color: '#04140d' } : undefined}>
      {state === 'saving' ? <><span className="w-[15px] h-[15px] rounded-full border-2 border-[rgba(4,20,13,.35)] border-t-[#04140d] animate-spin"/>Saving…</>
        : state === 'done' ? <><UIcon name="check" size={16}/>Saved</>
        : label}
    </button>
    {state === 'idle' && dirty
      ? <span className="font-mono text-[10.5px] tracking-[0.04em] max-[560px]:text-center" style={{ color: 'var(--warn)' }}>Unsaved changes</span>
      : note ? <span className="font-mono text-[10.5px] text-fg-mute tracking-[0.04em] max-[560px]:text-center">{note}</span> : null}
  </div>
);

// ---------- password strength (0–4) ----------
const pwScore = (v) => {
  if (!v) return 0;
  let s = 0;
  if (v.length >= 8) s++;
  if (v.length >= 12) s++;
  if (/[A-Z]/.test(v) && /[a-z]/.test(v)) s++;
  if (/\d/.test(v) && /[^A-Za-z0-9]/.test(v)) s++;
  return Math.min(s, 4);
};
const PW_META = [
  { t: 'Too short', c: 'var(--fg-faint)' },
  { t: 'Weak',      c: 'var(--danger)' },
  { t: 'Fair',      c: 'var(--warn)' },
  { t: 'Good',      c: 'var(--info)' },
  { t: 'Strong',    c: 'var(--pnl-up-fg)' },
];

const PwStrength = ({ value }) => {
  const score = pwScore(value);
  const m = PW_META[score];
  return (
    <div className="mt-2.5">
      <div className="flex items-center gap-1.5">
        {[0, 1, 2, 3].map(i => (
          <span key={i} className="h-[4px] flex-1 rounded-chip transition-colors duration-fast"
            style={{ background: i < score ? m.c : 'var(--bg-elev-3)' }}/>
        ))}
      </div>
      <div className="flex items-center justify-between gap-3 mt-1.5">
        <span className="font-mono text-[10.5px] font-semibold tracking-[0.04em]" style={{ color: m.c }}>{value ? m.t : 'Enter a new password'}</span>
        <span className="font-mono text-[10px] text-fg-faint tracking-[0.04em]">12+ chars · mixed case · number · symbol</span>
      </div>
    </div>
  );
};

// ============================ identity card ============================
const IdentityCard = () => {
  const SEED = { full: 'Jonas Renner', display: 'J. Renner', email: 'jonas.renner@kraite.io', tz: 'UTC' };
  const [v, setV] = React.useState(SEED);
  const [avatar, setAvatar] = React.useState(null); // data-url when a photo is uploaded
  const [saved, setSaved] = React.useState('idle');
  const fileRef = React.useRef(null);
  const timers = React.useRef([]);
  React.useEffect(() => () => timers.current.forEach(clearTimeout), []);

  const set = (k, val) => { setV(s => ({ ...s, [k]: val })); if (saved === 'done') setSaved('idle'); };
  const dirty = JSON.stringify(v) !== JSON.stringify(SEED) || !!avatar;
  const save = () => {
    setSaved('saving');
    timers.current.push(setTimeout(() => setSaved('done'), 520));
    timers.current.push(setTimeout(() => setSaved('idle'), 2200));
  };

  const initials = (v.full || 'J R').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0]).join('').toUpperCase() || 'JR';
  const onFile = (e) => {
    const f = e.target.files && e.target.files[0];
    if (!f) return;
    const r = new FileReader();
    r.onload = () => { setAvatar(r.result); if (saved === 'done') setSaved('idle'); };
    r.readAsDataURL(f);
  };

  const TZ = ['UTC', 'Europe/Berlin', 'Europe/London', 'America/New_York', 'Asia/Singapore', 'Asia/Tokyo'];

  return (
    <AcctZone>
      <AcctZoneHead icon="user" title="Identity"
        sub="Your name and contact details. Display name is what appears across the Kraite console."/>

      {/* avatar row */}
      <div className="flex items-center gap-5 py-5 px-6 border-b border-line-soft max-[640px]:px-4 max-[560px]:flex-col max-[560px]:items-start">
        <div className="relative flex-shrink-0">
          {avatar
            ? <img src={avatar} alt="" className="w-[68px] h-[68px] rounded-full object-cover border border-line"/>
            : <span className="w-[68px] h-[68px] rounded-full bg-green-50 text-green-600 font-mono font-bold text-[24px] flex items-center justify-center border border-green-100">{initials}</span>}
        </div>
        <div className="flex flex-col gap-2.5 min-w-0">
          <div className="flex items-center gap-2.5 flex-wrap">
            <button onClick={() => fileRef.current && fileRef.current.click()} className={BTN_SECONDARY + " h-[34px] px-3.5 text-[12.5px]"}>
              <UIcon name="download" size={14} style={{ transform: 'rotate(180deg)' }}/>Upload photo
            </button>
            {avatar && (
              <button onClick={() => { setAvatar(null); if (fileRef.current) fileRef.current.value = ''; }}
                className="appearance-none bg-transparent border-0 cursor-pointer font-mono text-[11px] font-semibold tracking-[0.04em] text-fg-mute hover:text-danger transition-colors duration-fast">
                Remove
              </button>
            )}
            <input ref={fileRef} type="file" accept="image/*" onChange={onFile} className="hidden"/>
          </div>
          <span className="font-mono text-[10.5px] text-fg-faint tracking-[0.02em] leading-snug">PNG or JPG · square works best · falls back to your initials</span>
        </div>
      </div>

      {/* name + contact fields */}
      <div className="py-5 px-6 max-[640px]:px-4">
        <div className="grid grid-cols-2 gap-x-5 gap-y-5 max-[700px]:grid-cols-1">
          <AcctField label="Full name" htmlFor="pf-full">
            <AcctInput id="pf-full" value={v.full} onChange={(val) => set('full', val)} placeholder="Your full name"/>
          </AcctField>
          <AcctField label="Display name" htmlFor="pf-display" help="Shown in the top bar and on activity records.">
            <AcctInput id="pf-display" value={v.display} onChange={(val) => set('display', val)} placeholder="Shorter handle"/>
          </AcctField>
          <AcctField label="Email address" htmlFor="pf-email" help="Used for sign-in and critical alerts.">
            <AcctInput id="pf-email" value={v.email} onChange={(val) => set('email', val)} mono placeholder="you@example.com"/>
          </AcctField>
          <AcctField label="Time zone" help="Times across the console still display in UTC; this sets your local reference.">
            <AcctSelect value={v.tz} onChange={(val) => set('tz', val)} options={TZ}/>
          </AcctField>
          <AcctField label="Role" aside={<span className="font-mono text-[9px] font-bold tracking-[0.1em] uppercase text-fg-faint inline-flex items-center gap-1"><UIcon name="lock" size={11}/>Locked</span>}
            help="Set by your administrator. Determines what you can change.">
            <div className="h-[42px] flex items-center gap-2.5 px-3.5 rounded-control border border-line bg-surface-2">
              <span className="font-mono text-[12px] font-bold tracking-[0.08em] uppercase text-fg-2">TRADER</span>
              <span className="ml-auto font-mono text-[10px] text-fg-faint tracking-[0.04em]">read-only</span>
            </div>
          </AcctField>
        </div>
      </div>

      <ProfileSaveBar state={saved} onSave={save} dirty={dirty} disabled={!dirty} note="Updates your name across the console"/>
    </AcctZone>
  );
};

// ============================ security card ============================
const SecurityCard = () => {
  const [p, setP] = React.useState({ cur: '', next: '', confirm: '' });
  const [saved, setSaved] = React.useState('idle');
  const [twoFA, setTwoFA] = React.useState(true);
  const timers = React.useRef([]);
  React.useEffect(() => () => timers.current.forEach(clearTimeout), []);

  const set = (k, val) => { setP(s => ({ ...s, [k]: val })); if (saved === 'done') setSaved('idle'); };

  const score = pwScore(p.next);
  const matchError = p.confirm.length > 0 && p.confirm !== p.next;
  const canSave = p.cur.trim().length > 0 && score >= 2 && p.next === p.confirm && p.confirm.length > 0;
  const save = () => {
    setSaved('saving');
    timers.current.push(setTimeout(() => setSaved('done'), 600));
    timers.current.push(setTimeout(() => { setSaved('idle'); setP({ cur: '', next: '', confirm: '' }); }, 2200));
  };

  return (
    <AcctZone>
      <AcctZoneHead icon="lock" title="Sign-in & security"
        sub="Change your password and manage two-factor authentication for this account."/>

      <AcctGroup title="Change password" icon="key">
        <AcctField label="Current password" htmlFor="pf-cur">
          <AcctInput id="pf-cur" value={p.cur} onChange={(val) => set('cur', val)} secret mono placeholder="Enter current password"/>
        </AcctField>
        <div className="max-[700px]:hidden"/>
        <AcctField label="New password" htmlFor="pf-next">
          <AcctInput id="pf-next" value={p.next} onChange={(val) => set('next', val)} secret mono placeholder="Choose a new password"/>
          <PwStrength value={p.next}/>
        </AcctField>
        <AcctField label="Confirm new password" htmlFor="pf-confirm"
          error={matchError ? 'Passwords don’t match' : null}>
          <AcctInput id="pf-confirm" value={p.confirm} onChange={(val) => set('confirm', val)} secret mono placeholder="Re-enter new password" invalid={matchError}/>
        </AcctField>
      </AcctGroup>

      {/* two-factor */}
      <div className="border-b border-line-soft last:border-b-0">
        <AcctBandHead icon="shield" title="Two-factor authentication"/>
        <div className="flex items-center gap-4 py-5 px-6 max-[640px]:px-4">
          <div className="flex flex-col gap-1 min-w-0">
            <span className="font-sans text-[13.5px] font-semibold text-fg-1">Authenticator app</span>
            <span className="text-[12px] text-fg-3 leading-snug">{twoFA ? 'Active — a 6-digit code is required at every sign-in.' : 'Off — your account is protected by password only.'}</span>
          </div>
          <div className="ml-auto flex items-center gap-3 flex-shrink-0">
            <span className="font-mono text-[10px] font-bold tracking-[0.08em] uppercase" style={{ color: twoFA ? 'var(--pnl-up-fg)' : 'var(--fg-mute)' }}>{twoFA ? 'ON' : 'OFF'}</span>
            <AcctToggle checked={twoFA} onChange={setTwoFA}/>
          </div>
        </div>
      </div>

      <ProfileSaveBar state={saved} onSave={save} disabled={!canSave} dirty={false}
        label="Update password" note={canSave ? 'You’ll stay signed in on this device' : 'Fill in all three fields to update'}/>
    </AcctZone>
  );
};

// ============================ the page ============================
const Profile = () => {
  const header = (
    <div className={PAGEHEAD}>
      <div>
        <div className={PH_EYEBROW}><UIcon name="user" size={13} style={{ width: 13, height: 13 }}/>ACCOUNT</div>
        <h1 className={PH_H1}>Profile</h1>
        <div className={PH_SUB}>Your personal account details and sign-in security.</div>
      </div>
    </div>
  );

  return (
    <>
      {header}
      <div className="flex flex-col gap-6 max-w-[860px]">
        <IdentityCard/>
        <SecurityCard/>
      </div>
    </>
  );
};

Object.assign(window, { Profile });
