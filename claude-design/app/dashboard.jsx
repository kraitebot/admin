// Kraite admin — trader Dashboard (hero working screen).

const TILE_CLS = "tile kpi-invert overflow-hidden bg-surface border border-line rounded-control py-[13px] px-[15px] flex flex-col gap-[9px] relative transition-colors duration-fast ease-out hover:border-line-strong";
const TILE_EYEBROW = "font-mono text-[10px] font-medium tracking-[0.11em] uppercase text-fg-3 flex items-center gap-[7px]";
const TILE_VALUE = "font-mono font-semibold text-[24px] tracking-[-0.025em] text-fg-1 tabular-nums leading-none";

const KpiTile = ({ k }) => (
  <div className={TILE_CLS}>
    <div className={TILE_EYEBROW}>
      <UIcon name={k.icon} size={12}/>{k.label}
    </div>
    {k.key === 'op' ? (
      <div className="flex items-center gap-2.5 min-w-0">
        <span className={TILE_VALUE}>{k.value}</span>
        <div className="ml-auto flex flex-col gap-1 w-24 min-w-0 flex-shrink">
          <div className="flex h-1.5 rounded-chip overflow-hidden gap-0.5">
            <span className="rounded-chip" style={{ flex: 6, background: 'var(--pnl-up-fg)' }}/>
            <span className="rounded-chip" style={{ flex: 4, background: 'var(--pnl-down-fg)' }}/>
          </div>
          <div className="flex justify-between">
            <span className="font-mono text-[10px] font-semibold text-pnlup">6L</span>
            <span className="font-mono text-[10px] font-semibold text-pnldown">4S</span>
          </div>
        </div>
      </div>
    ) : (
      <div className="flex items-center gap-2.5 min-w-0">
        <span className={TILE_VALUE}>{k.value}</span>
        <Delta value={k.delta}/>
        {k.spark && <div className="ml-auto w-[84px] min-w-0 flex-shrink"><Sparkline data={k.spark} up={k.up} w={84} h={28}/></div>}
      </div>
    )}
  </div>
);

// ---- styled hover tooltip layer (instant, on-brand, clip-proof) ----
// One fixed-position layer listens for [data-tip] hovers anywhere in the tree,
// so tooltips never get clipped by a tile's overflow-hidden.
const TipLayer = () => {
  const [tip, setTip] = React.useState(null);
  React.useEffect(() => {
    const show = (e) => {
      const el = e.target.closest('[data-tip]');
      if (!el) return;
      const r = el.getBoundingClientRect();
      setTip({ text: el.getAttribute('data-tip'), x: r.left + r.width / 2, y: r.top });
    };
    const hide = (e) => { if (e.target.closest('[data-tip]')) setTip(null); };
    document.addEventListener('mouseover', show);
    document.addEventListener('mouseout', hide);
    return () => { document.removeEventListener('mouseover', show); document.removeEventListener('mouseout', hide); };
  }, []);
  if (!tip) return null;
  return (
    <div style={{ position: 'fixed', left: tip.x, top: tip.y - 9, transform: 'translate(-50%,-100%)', zIndex: 9999, pointerEvents: 'none' }}
      className="max-w-[230px] bg-[#0b1411] text-[#d6ffe9] border border-[color-mix(in_srgb,var(--accent)_38%,transparent)] rounded-control py-[7px] px-2.5 font-sans text-[11.5px] font-medium leading-[1.45] shadow-2 text-center">
      {tip.text}
    </div>
  );
};

// ---- position lifecycle tile ----
const PositionTile = ({ p }) => {
  const long = p.side === 'long';
  const status = p.status; // 'opening' | 'waped' | undefined
  const waped = status === 'waped';
  const ribbon = true; // ribbon header on every position tile — green for longs, red for shorts
  // ribbon palette keyed off side
  const rib = long
    ? { bg: '#0e3f2a', border: 'color-mix(in srgb, var(--pnl-up-fg) 30%, transparent)', sym: 'text-[#eafff5]', mute: 'text-[#8fd9b4]', muteHover: 'hover:text-[#d6ffe9]', chip: 'text-[#aef0cf]' }
    : { bg: '#3f1212', border: 'color-mix(in srgb, var(--pnl-down-fg) 32%, transparent)', sym: 'text-[#ffecec]', mute: 'text-[#e0a3a3]', muteHover: 'hover:text-[#ffdede]', chip: 'text-[#ffc9c9]' };
  const [imgOk, setImgOk] = React.useState(true);
  // lifecycle track: limit ladder on the left, price marker past it, TP on the right
  const LADDER = [26, 44, 62, 80];
  const fillN = parseInt(p.filled, 10) || 0;
  const gainL = Math.min(p.trackPx, p.trackTp);
  const gainW = Math.abs(p.trackTp - p.trackPx);
  const sideCls = "inline-flex items-center gap-1 font-mono text-[10.5px] font-bold tracking-[0.07em] uppercase rounded-chip py-1 px-2.5 [&_svg]:-ml-[2px] " + (ribbon ? "bg-white/10 " + rib.chip : long ? "bg-pnlup-bg text-pnlup" : "bg-pnldown-bg text-pnldown");
  const badge = "ml-auto flex-shrink-0 inline-flex items-center gap-[5px] font-mono text-[9px] font-bold tracking-[0.08em] uppercase py-0.5 px-2 rounded-chip whitespace-nowrap";
  // metric accent color: green for longs, red for shorts (labels + the "hero" values)
  const mc = long ? 'text-accent' : 'text-pnldown';
  const lbl = "font-mono text-[9.5px] font-medium tracking-[0.08em] uppercase " + mc + " flex items-center gap-[5px] mb-[5px] cursor-help";
  // tiny [?] affordance shown at the end of each metric label
  const Q = () => <span className="inline-flex items-center justify-center w-[11px] h-[11px] rounded-full border border-current text-[7px] font-bold leading-none opacity-45 transition-opacity duration-fast ease-out group-hover/lbl:opacity-90">?</span>;
  const vBase = "font-mono font-semibold tabular-nums tracking-[-0.01em]";
  const v14 = vBase + " text-[14px] text-fg-1", v14a = vBase + " text-[14px] " + mc;
  const v13 = vBase + " text-[13px] text-fg-1", v13a = vBase + " text-[13px] " + mc;
  return (
    <div className={"ptile bg-surface border-2 rounded-surface overflow-hidden transition-colors duration-fast ease-out " + (long ? "ptile--long" : "ptile--short") + (waped ? " ptile--waped" : "")}>
      <div className={"pt-4 px-[18px] pb-[14px]" + (status === 'opening' ? " grayscale opacity-[0.62]" : "")}>
        <div className={"flex items-start gap-[11px]" + (ribbon ? " -mt-4 -mx-[18px] mb-4 px-[18px] pt-4 pb-3.5 border-b" : "")} style={ribbon ? { background: rib.bg, borderColor: rib.border } : undefined}>
          <div className="w-[30px] h-[30px] rounded-full flex items-center justify-center font-mono font-bold text-[12px] text-white flex-shrink-0 overflow-hidden" style={{ background: imgOk ? 'transparent' : p.color }}>
            {imgOk
              ? <img src={`https://s2.coinmarketcap.com/static/img/coins/64x64/${p.cmcId}.png`} alt={p.sym} onError={() => setImgOk(false)} className="block w-full h-full object-cover"/>
              : p.sym[0]}
          </div>
          <div className="flex-1 min-w-0">
            <div className="flex items-center gap-[7px]">
              <span className={"font-sans font-bold text-[14px] tracking-[-0.01em] flex-shrink-0 " + (ribbon ? rib.sym : "text-fg-1")}>{p.sym}</span>
              <span className={"text-[12px] whitespace-nowrap overflow-hidden text-ellipsis min-w-0 " + (ribbon ? rib.mute : "text-fg-mute")}>{p.name}</span>
              {status === 'opening' && (
                <span className={badge + " bg-surface-3 text-fg-3"}><span className="w-1.5 h-1.5 rounded-chip bg-fg-3 animate-pulse-soft"/>Opening</span>
              )}
              {waped && (
                <span className={badge + " text-warn bg-[color-mix(in_srgb,var(--warn)_16%,transparent)]"}><UIcon name="layers" size={10}/>WAP'd</span>
              )}
            </div>
            <div className="flex items-center gap-[9px] mt-1.5">
              <span className={sideCls}>
                <UIcon name={long ? 'arrowUp' : 'arrowDown'} size={11} style={{ width: 11, height: 11 }}/>
                {p.side} {p.lev}
              </span>
              <span className={"font-mono text-[11px] inline-flex items-center gap-[5px] " + (ribbon ? rib.mute : "text-fg-mute")}><UIcon name="clock" size={12}/>{p.eta}</span>
            </div>
          </div>
          <div className="flex gap-1 items-center flex-shrink-0 pt-[3px]" title="Timeframe oscillation">
            {p.osc.map((d, i) => <i key={i} className={"block w-1.5 h-1.5 rounded-chip " + (d === 'up' ? 'bg-pnlup' : d === 'down' ? 'bg-pnldown' : 'bg-line-strong')}/>)}
            <button className={"appearance-none bg-transparent border-0 cursor-pointer px-0.5 leading-none ml-0.5 inline-flex items-center " + (ribbon ? rib.mute + " " + rib.muteHover : "text-fg-faint hover:text-fg-2")} title="Actions"><UIcon name="more" size={16}/></button>
          </div>
        </div>

        <div className="relative mt-[22px] mx-0.5 mb-4 h-[30px] cursor-help" style={{ '--mc': long ? 'var(--accent)' : 'var(--pnl-down-fg)', '--mc-soft': long ? 'var(--accent-soft)' : 'color-mix(in srgb, var(--pnl-down-fg) 22%, transparent)' }} data-tip="Lifecycle track — current price (PX) moving toward the take-profit target (TP). Numbered ticks are the limit-order ladder rungs; the runway is the distance left to TP.">
          <div className="absolute left-0 right-0 top-[21px] h-px bg-[color-mix(in_srgb,var(--mc)_45%,transparent)]"/>
          <div className="absolute top-[19px] h-1 rounded-chip bg-[var(--mc)]" style={{ left: gainL + '%', width: gainW + '%' }}/>
          {LADDER.map((pos, i) => {
            if (i < fillN) return null; // consumed limit — number disappears (TP sits here)
            return (
              <span key={i} className="absolute top-[15px] -translate-x-1/2 font-mono text-[10px] text-[var(--mc)] bg-surface px-1 leading-[1.4]" style={{ left: pos + '%' }}>{i + 1}</span>
            );
          })}
          <div className="absolute top-0 -translate-x-1/2 flex flex-col items-center z-[2]" style={{ left: p.trackTp + '%' }}>
            <span className="font-mono text-[9px] font-bold text-[var(--mc)] tracking-[0.06em]">TP</span>
            <span className="w-[11px] h-[11px] rounded-[50%_50%_50%_0] bg-[var(--mc)] rotate-45 mt-[3px] shadow-[0_0_0_3px_var(--mc-soft)]"/>
          </div>
          <div className="absolute top-0 -translate-x-1/2 flex flex-col items-center z-[2]" style={{ left: p.trackPx + '%' }}>
            <span className="font-mono text-[9px] font-bold text-fg-2 tracking-[0.06em]">PX</span>
            <span className="w-[10px] h-[10px] rounded-full bg-fg-1 mt-[3px] shadow-[0_0_0_3px_color-mix(in_srgb,var(--fg-1)_20%,transparent)]"/>
          </div>
        </div>

        <div className="grid grid-cols-3 gap-2">
          <div>
            <div className={lbl + " group/lbl"} data-tip="Path — how far price has progressed toward the take-profit target."><UIcon name="flag" size={11}/>Path<Q/></div>
            <div className={v14a}>{p.path.toFixed(1)}%</div>
          </div>
          <div className="text-center">
            <div className={lbl + " justify-center group/lbl"} data-tip="Limit — share of the planned position filled through the limit-order ladder."><UIcon name="arrowRight" size={11}/>Limit<Q/></div>
            <div className={v14}>{p.limit.toFixed(1)}%</div>
          </div>
          <div className="text-right">
            <div className={lbl + " justify-end group/lbl"} data-tip="Filled — limit-ladder rungs executed so far (of 4)."><UIcon name="check" size={11}/>Filled<Q/></div>
            <div className={v14}>{p.filled}</div>
          </div>
        </div>

        <div className="h-px bg-line-soft my-[13px]"/>

        <div className="grid grid-cols-3 gap-2">
          <div>
            <div className={lbl + " group/lbl"} data-tip="Open — average entry price of the position."><UIcon name="circleSm" size={11}/>Open<Q/></div>
            <div className={v13}>{p.open}</div>
          </div>
          <div className="text-center">
            <div className={lbl + " justify-center group/lbl"} data-tip="TP — take-profit target price; the position closes for profit here."><UIcon name="arrowUp" size={11}/>TP<Q/></div>
            <div className={v13a}>{p.tp}</div>
          </div>
          <div className="text-right">
            <div className={lbl + " justify-end group/lbl"} data-tip="Next — price of the next limit-order rung waiting to fill."><UIcon name="arrowDown" size={11}/>Next<Q/></div>
            <div className={v13}>{p.next}</div>
          </div>
        </div>
      </div>
    </div>
  );
};

// ---- segmented control with a sliding green pill that animates to the active option ----
const Segmented = ({ options, value, onChange }) => {
  const wrapRef = React.useRef(null);
  const btnRefs = React.useRef({});
  const [hl, setHl] = React.useState(null);
  const measure = React.useCallback(() => {
    const el = btnRefs.current[value];
    if (!el) return;
    setHl({ left: el.offsetLeft, top: el.offsetTop, width: el.offsetWidth, height: el.offsetHeight });
  }, [value]);
  React.useLayoutEffect(() => { measure(); }, [measure]);
  React.useEffect(() => {
    const raf = requestAnimationFrame(measure);
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(measure);
    window.addEventListener('resize', measure);
    return () => { cancelAnimationFrame(raf); window.removeEventListener('resize', measure); };
  }, [measure]);
  return (
    <div ref={wrapRef} className="relative inline-flex items-center h-[34px] bg-surface-3 border border-line rounded-control px-[3px] gap-0.5">
      {hl && (
        <span aria-hidden="true"
          className="absolute z-0 bg-accent rounded-[7px] shadow-1 pointer-events-none transition-all duration-[420ms] ease-[cubic-bezier(0.16,1,0.3,1)]"
          style={{ left: hl.left, top: hl.top, width: hl.width, height: hl.height }}/>
      )}
      {options.map(f => (
        <button key={f} ref={el => { btnRefs.current[f] = el; }} onClick={() => onChange(f)}
          className={"appearance-none bg-transparent border-0 rounded-[7px] h-[26px] inline-flex items-center px-3 font-mono text-[11px] font-semibold tracking-[0.04em] cursor-pointer relative z-[1] transition-colors duration-fast ease-out " + (value === f ? "text-accent-on" : "text-fg-3 hover:text-fg-1")}>{f}</button>
      ))}
    </div>
  );
};

// ---- open positions section: full-width tile grid, optional pagination ----
const PositionsSection = ({ paginate }) => {
  const [filter, setFilter] = React.useState('ALL');
  const [page, setPage] = React.useState(0);
  const PER = 6;
  const rows = POSITIONS.filter(p => filter === 'ALL' || p.side === filter.toLowerCase());
  const usePager = paginate && rows.length > PER;
  const pageCount = Math.ceil(rows.length / PER);
  const safePage = Math.min(page, pageCount - 1);
  const pages = [];
  for (let i = 0; i < rows.length; i += PER) pages.push(rows.slice(i, i + PER));
  const setFilterReset = (f) => { setFilter(f); setPage(0); };

  // drag / swipe with snap
  const viewRef = React.useRef(null);
  const trackRef = React.useRef(null);
  const dotsRef = React.useRef(null);
  const thumbRef = React.useRef(null);
  const prevPage = React.useRef(safePage);
  const drag = React.useRef({ active: false, startX: 0, base: 0, w: 0, moved: 0 });

  // dot indicator "glue" — stretch a green thumb across to the new dot, then settle
  React.useLayoutEffect(() => {
    if (!usePager) return;
    const th = thumbRef.current, dots = dotsRef.current;
    if (!th || !dots) return;
    const els = dots.querySelectorAll('.pcar__dot');
    const a = els[prevPage.current], b = els[safePage];
    if (!a || !b) return;
    const lo = Math.min(a.offsetLeft, b.offsetLeft);
    const hi = Math.max(a.offsetLeft + a.offsetWidth, b.offsetLeft + b.offsetWidth);
    th.style.transition = 'left 200ms var(--ease-out), width 200ms var(--ease-out)';
    th.style.left = lo + 'px';
    th.style.width = (hi - lo) + 'px';   // stretch to span both
    const t = setTimeout(() => {
      th.style.left = b.offsetLeft + 'px';
      th.style.width = b.offsetWidth + 'px'; // settle on target
    }, 190);
    prevPage.current = safePage;
    return () => clearTimeout(t);
  }, [safePage, usePager, pages.length]);
  const applyTransform = (px) => { if (trackRef.current) trackRef.current.style.transform = `translateX(${px}px)`; };
  const snapTo = (i) => {
    const t = trackRef.current;
    if (t) { t.style.transition = ''; t.style.transform = `translateX(-${i * 100}%)`; }
    setPage(i);
  };
  const onDown = (e) => {
    if (!usePager || e.button === 1 || e.button === 2) return;
    const w = viewRef.current.offsetWidth;
    drag.current = { active: true, startX: e.clientX, base: -safePage * w, w, moved: 0 };
    if (trackRef.current) trackRef.current.style.transition = 'none';
    viewRef.current.setPointerCapture?.(e.pointerId);
  };
  const onMove = (e) => {
    const d = drag.current;
    if (!d.active) return;
    let dx = e.clientX - d.startX;
    d.moved = dx;
    let pos = d.base + dx;
    const min = -(pages.length - 1) * d.w;
    if (pos > 0) pos = pos * 0.35;                 // rubber-band at start
    if (pos < min) pos = min + (pos - min) * 0.35; // rubber-band at end
    applyTransform(pos);
  };
  const onUp = (e) => {
    const d = drag.current;
    if (!d.active) return;
    d.active = false;
    viewRef.current.releasePointerCapture?.(e.pointerId);
    let next = safePage;
    if (d.moved < -d.w * 0.18) next = Math.min(pages.length - 1, safePage + 1);
    else if (d.moved > d.w * 0.18) next = Math.max(0, safePage - 1);
    snapTo(next);
  };

  return (
    <section className="mb-6">
      <div className="flex items-end justify-between gap-4 mb-4 max-[640px]:flex-col max-[640px]:items-start">
        <div>
          <div className="font-sans font-semibold text-[16px] text-fg-1 flex items-center gap-[9px] whitespace-nowrap"><UIcon name="layers" size={17} style={{ color: 'var(--fg-3)' }}/>Open positions</div>
          <div className="text-[12.5px] text-fg-3 mt-1 whitespace-nowrap">
            {usePager
              ? <>{rows.length} positions · showing {safePage * PER + 1}–{Math.min(safePage * PER + PER, rows.length)} · max 6 per direction</>
              : <>{rows.length} positions managed across the lifecycle · no manual orders</>}
          </div>
        </div>
        <div className="flex items-center gap-4 flex-shrink-0 max-[640px]:w-full max-[640px]:flex-wrap max-[640px]:gap-y-2.5">
          <Segmented options={['ALL','LONG','SHORT']} value={filter} onChange={setFilterReset}/>
          <button className="inline-flex items-center gap-[9px] h-[34px] border border-line rounded-control bg-surface px-3 cursor-pointer text-[12.5px] text-fg-2 max-w-[280px] transition-colors duration-fast ease-out hover:border-line-strong max-[640px]:max-w-none max-[640px]:flex-1">
            <span className="w-[7px] h-[7px] rounded-chip bg-green-500 flex-shrink-0"/>
            <span className="whitespace-nowrap overflow-hidden text-ellipsis">Karine Esnault · Binance</span>
            <UIcon name="chevronDown" size={14} style={{ color: 'var(--fg-mute)' }}/>
          </button>
        </div>
      </div>
      {usePager ? (
        <>
          <div className="overflow-hidden cursor-grab touch-pan-y active:cursor-grabbing" ref={viewRef}
            onPointerDown={onDown} onPointerMove={onMove} onPointerUp={onUp} onPointerCancel={onUp}>
            <div className="flex transition-transform duration-[380ms] ease-out select-none" ref={trackRef} style={{ transform: `translateX(-${safePage * 100}%)` }}>
              {pages.map((pg, i) => (
                <div className="flex-[0_0_100%] min-w-0 [&_img]:pointer-events-none [&_img]:select-none" key={i}>
                  <div className="grid grid-cols-3 gap-5 max-[1080px]:grid-cols-2 max-[640px]:grid-cols-1">
                    {pg.map(p => <PositionTile key={p.sym} p={p}/>)}
                  </div>
                </div>
              ))}
            </div>
          </div>
          <div className="relative flex justify-center items-center gap-[7px] mt-5" ref={dotsRef}>
            {pages.map((_, i) => (
              <button key={i} className="pcar__dot appearance-none cursor-pointer p-0 border-0 w-[7px] h-[7px] rounded-chip bg-line-strong transition-colors duration-fast ease-out z-[1] hover:bg-fg-mute" onClick={() => snapTo(i)} aria-label={`Page ${i + 1}`}/>
            ))}
            <span className="absolute top-1/2 left-0 h-[7px] w-[7px] -translate-y-1/2 rounded-chip bg-accent z-[2] pointer-events-none" ref={thumbRef}/>
          </div>
        </>
      ) : (
        <div className="grid grid-cols-3 gap-5 max-[1080px]:grid-cols-2 max-[640px]:grid-cols-1">
          {rows.map(p => <PositionTile key={p.sym} p={p}/>)}
        </div>
      )}
    </section>
  );
};

// ---- shared utility-class strings (cards, buttons, links, page headers) ----
const CARD_HEAD  = "flex items-center justify-between gap-3 py-[15px] px-5 border-b border-line-soft";
const CARD_TITLE = "font-sans font-semibold text-[14px] text-fg-1 flex items-center gap-[9px] whitespace-nowrap";
const CARD_FOOT  = "py-3 px-5 border-t border-line-soft flex items-center justify-between gap-3";
const FOOT_MONO  = "font-mono tabular-nums text-[11px] text-fg-mute";
const LINK_ARROW = "text-[12px] font-sans font-semibold no-underline text-accent inline-flex items-center gap-[5px] hover:text-accent-hover cursor-pointer";
const LINK_QUIET = "appearance-none bg-transparent border-0 cursor-pointer font-mono text-[11px] tracking-[0.04em] text-fg-mute no-underline inline-flex items-center transition-colors duration-fast ease-out hover:text-fg-1";
const BTN = "appearance-none font-sans font-semibold rounded-control border cursor-pointer inline-flex items-center gap-[7px] whitespace-nowrap transition-colors duration-fast ease-out active:translate-y-px h-[34px] px-3 text-[12px]";
const BTN_PRIMARY = BTN + " border-transparent bg-accent text-accent-on hover:bg-accent-hover";
const BTN_SECONDARY = BTN + " bg-transparent text-fg-1 border-line-strong hover:bg-hover";
const PAGEHEAD = "flex items-end justify-between gap-5 pb-5 mb-6 border-b border-line max-[820px]:flex-col max-[820px]:items-start";
const PH_EYEBROW = "font-mono text-[11px] font-medium tracking-[0.12em] uppercase text-fg-3 mb-2 flex items-center gap-2";
const PH_H1 = "font-sans font-bold text-[28px] tracking-[-0.02em] text-fg-1 leading-[1.1] max-[640px]:text-[24px]";
const PH_SUB = "text-[13px] text-fg-3 mt-1.5";
const dotCls = (s) => "w-[9px] h-[9px] rounded-chip flex-shrink-0 " + (s === 'ok' ? 'bg-green-500' : s === 'warn' ? 'bg-warn' : s === 'down' ? 'bg-danger animate-pulse-soft' : 'bg-fg-faint');

// status pill matching the regime pill style (coherent alerts)
const StatusPill = ({ tone, pulse, children }) => {
  const c = tone === 'down' ? 'var(--danger)' : tone === 'warn' ? 'var(--warn)' : 'var(--accent)';
  const fg = tone === 'down' ? 'var(--pnl-down-fg)' : tone === 'warn' ? 'var(--warn)' : 'var(--pnl-up-fg)';
  return (
    <span className="inline-flex items-center gap-[7px] py-[5px] px-[13px] rounded-chip border font-mono text-[11px] font-semibold tracking-[0.1em] uppercase whitespace-nowrap" style={{
      background: `color-mix(in srgb, ${c} 12%, transparent)`,
      borderColor: `color-mix(in srgb, ${c} 38%, transparent)`,
      color: fg,
    }}>
      <span className={"w-2 h-2 rounded-chip" + (pulse ? " animate-pulse-soft" : "")} style={{ background: c }}/>
      {children}
    </span>
  );
};

// ---- server connectivity — our 6 whitelisted egress servers → exchange ----
// All-healthy collapses to a single green light; any fault expands the list.
const ConnectivityCard = ({ fault }) => {
  const servers = fault
    ? SERVERS.map((s, i) => i === 4 ? { ...s, state: 'down', latency: '—' } : s)
    : SERVERS;
  const down = servers.filter(s => s.state === 'down').length;
  const warn = servers.filter(s => s.state === 'warn').length;
  const allOk = down === 0 && warn === 0;
  const [open, setOpen] = React.useState(false);
  const expanded = open || !allOk;

  return (
    <div className={'card' + (down > 0 ? ' card--alert' : warn > 0 ? ' card--warn' : '')}>
      <div className={CARD_HEAD}>
        <div className={CARD_TITLE}><UIcon name="server" size={16} style={{ color: 'var(--fg-3)' }}/>Server connectivity</div>
        {down > 0
          ? <StatusPill tone="down" pulse>{down} DOWN</StatusPill>
          : warn > 0
            ? <StatusPill tone="warn">{warn} DEGRADED</StatusPill>
            : <StatusPill tone="ok">ALL LINKED</StatusPill>}
      </div>

      {!expanded ? (
        // collapsed green-light summary
        <button className="appearance-none w-full text-left cursor-pointer bg-transparent border-0 flex items-center gap-[13px] py-4 px-5 transition-colors duration-fast ease-out hover:bg-hover" onClick={() => setOpen(true)}>
          <span className="w-[30px] h-[30px] rounded-full flex-shrink-0 flex items-center justify-center bg-pnlup-bg">
            <span className="w-[11px] h-[11px] rounded-full bg-green-500 shadow-[0_0_0_4px_color-mix(in_srgb,var(--green-500)_22%,transparent)]"/>
          </span>
          <span className="flex-1 min-w-0 flex flex-col gap-0.5">
            <span className="text-[13.5px] font-semibold text-fg-1">All servers linked</span>
            <span className="font-mono text-[10.5px] text-fg-mute tracking-[0.02em]">6 / 6 whitelisted · egress → exchange · avg 31ms</span>
          </span>
          <span className="font-mono text-[10.5px] text-fg-mute tracking-[0.04em] inline-flex items-center gap-1 flex-shrink-0">Details <UIcon name="chevronDown" size={13}/></span>
        </button>
      ) : (
        <div>
          {(allOk ? servers : servers.filter(s => s.state !== 'ok')).map(s => (
            <div key={s.id} className={"srv flex items-center gap-[11px] py-[9px] px-5 border-b border-line-soft transition-colors duration-fast ease-out hover:bg-hover last:border-b-0" + (s.state === 'down' ? ' is-down' : '')}>
              <span className={dotCls(s.state)}/>
              <span className="font-mono text-[12.5px] font-semibold text-fg-1 flex-1">{s.id}</span>
              <span className="font-mono text-[9.5px] text-fg-mute uppercase tracking-[0.08em]">{s.region}</span>
              <span className={"font-mono text-[11.5px] tabular-nums flex-shrink-0" + (s.state === 'down' ? ' text-pnldown font-semibold' : ' text-fg-3')}>
                {s.state === 'ok' ? s.latency : 'DOWN'}
              </span>
            </div>
          ))}
          {!allOk && (
            <div className="flex items-center gap-[9px] py-[9px] px-5 font-mono text-[11px] text-fg-mute tracking-[0.02em]">
              <span className={dotCls('ok')}/>
              {servers.filter(s => s.state === 'ok').length} other servers linked · egress nominal
            </div>
          )}
        </div>
      )}

      <div className={CARD_FOOT}>
        <span className="font-mono tabular-nums text-[11px] text-fg-mute inline-flex items-center gap-[7px]">
          <span className="w-1.5 h-1.5 rounded-chip bg-green-500"/>HEARTBEAT 5s
        </span>
        {expanded && allOk
          ? <button className={LINK_QUIET} onClick={() => setOpen(false)}>Collapse</button>
          : <a href="#" className={LINK_QUIET}>whitelist →</a>}
      </div>
    </div>
  );
};

// ---- activity feed — one line per item, collapse past the first few ----
const ActivityCard = () => {
  const [showAll, setShowAll] = React.useState(false);
  const [cat, setCat] = React.useState('all');
  const COLLAPSED = 11;
  const filtered = ACTIVITY.filter(a => cat === 'all' || a.cat === cat);
  const rows = showAll ? filtered : filtered.slice(0, COLLAPSED);
  const hidden = filtered.length - COLLAPSED;
  return (
    <div className="card">
      <div className={CARD_HEAD}>
        <div className={CARD_TITLE}><UIcon name="bot" size={16} style={{ color: 'var(--fg-3)' }}/>Recent bot activity</div>
        <div className="flex items-center gap-1.5">
          <Dropdown value={cat} options={ACTIVITY_CATS} onChange={(v) => { setCat(v); setShowAll(false); }} icon="filter" align="right"/>
        </div>
      </div>
      <div className="flex flex-col">
        {rows.length === 0 ? (
          <div className="py-7 px-5 text-center text-[12.5px] text-fg-mute">No {ACTIVITY_CATS.find(c => c.id === cat)?.label.toLowerCase()} in this window.</div>
        ) : rows.map((a, i) => (
          <div className="flex items-center gap-2.5 py-[9px] px-5 border-b border-line-soft min-w-0 last:border-b-0" key={i}>
            <span className="w-[7px] h-[7px] rounded-chip flex-shrink-0" style={{ background: a.dot }}/>
            <span className="font-mono text-[9px] font-semibold tracking-[0.07em] uppercase text-fg-mute w-14 flex-shrink-0">{a.kind}</span>
            <span className="flex-1 min-w-0 text-[12.5px] text-fg-2 whitespace-nowrap overflow-hidden text-ellipsis">{a.el}</span>
            <span className="font-mono text-[10.5px] text-fg-mute whitespace-nowrap flex-shrink-0">{a.time}</span>
          </div>
        ))}
      </div>
      <div className={CARD_FOOT}>
        <span className={FOOT_MONO}>UPDATED 12s AGO · {filtered.length} EVENTS</span>
        {hidden > 0
          ? <button className={LINK_ARROW + " bg-transparent border-0 font-[inherit]"} onClick={() => setShowAll(!showAll)}>
              {showAll ? 'Show less' : 'Show all activity'}
              <UIcon name={showAll ? 'chevronUp' : 'chevronDown'} size={13}/>
            </button>
          : <a href="#" className={LINK_ARROW}>Audit log →</a>}
      </div>
    </div>
  );
};

// ---- BSCS mini snapshot — compact: score + regime + gradient bar ----
const BscsMiniCard = ({ regime, score }) => {
  const r = REGIMES[regime] || REGIMES.CALM;
  const clamp = (v) => Math.max(0.05, Math.min(0.98, v));
  const comps = [
    { label: 'BTC realized vol',    v: clamp(score * 1.00) },
    { label: 'Cross-asset corr.',   v: clamp(score * 1.38) },
    { label: 'Funding dispersion',  v: clamp(score * 0.74) },
    { label: 'Liquidity depth',     v: clamp(score * 1.12) },
  ];
  const barColor = (v) => v < 0.5 ? 'var(--accent)' : v < 0.75 ? 'var(--bsi-watch)' : 'var(--bsi-cascade)';
  const suspended = regime === 'ELEVATED' || regime === 'CASCADE' || regime === 'BLACK SWAN';
  const until = new Date(Date.now() + 24 * 3600 * 1000);
  const untilStr = until.toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false, timeZone: 'UTC' }).replace(' at ', ', ') + ' UTC';
  return (
    <div className="card">
      <div className={CARD_HEAD}>
        <div className={CARD_TITLE}><UIcon name="shield" size={16} style={{ color: 'var(--fg-3)' }}/>Black Swan Composite</div>
        <RegimePill regime={regime} pulse={regime === 'CASCADE' || regime === 'BLACK SWAN'}/>
      </div>
      <div className="flex flex-col gap-[13px] pt-4 px-5 pb-[18px]">
        <div className="flex items-baseline gap-[9px] flex-wrap">
          <span className="font-mono text-[32px] font-semibold leading-none tracking-[-0.03em]" style={{ color: r.color }}>{score.toFixed(2)}</span>
          <span className="font-mono text-[11.5px] text-fg-mute whitespace-nowrap">/ 1.00 · <span style={{ color: r.color, fontWeight: 600 }}>{regime}</span></span>
          <span className="font-mono text-[9.5px] tracking-[0.06em] text-fg-mute ml-auto self-center">{suspended ? 'NEW POS. SUSPENDED' : score < 0.5 ? 'NEW POS. ALLOWED' : 'NEW POS. REDUCED'}</span>
        </div>
        <div>
          <div className="h-[7px] rounded-chip relative" style={{ background: 'linear-gradient(90deg, var(--bsi-calm) 0%, var(--bsi-watch) 32%, var(--bsi-elevated) 55%, var(--bsi-cascade) 80%, var(--bsi-blackswan) 100%)' }}>
            <span className="absolute top-1/2 w-[3px] h-[15px] bg-fg-1 border-2 border-surface rounded-chip -translate-x-1/2 -translate-y-1/2 transition-[left] duration-slow ease-snap shadow-[0_0_0_1px_rgba(0,0,0,0.25)]" style={{ left: (score * 100) + '%' }}/>
          </div>
          <div className="flex justify-between mt-1.5">
            {['CALM','WATCH','ELEV','CASC','SWAN'].map(s => <span key={s} className="font-mono text-[9px] text-fg-mute tracking-[0.04em]">{s}</span>)}
          </div>
        </div>
        {suspended && (
          <div className="flex items-start gap-[9px] py-[11px] px-[13px] rounded-control bg-pnldown-bg text-pnldown text-[12px] leading-[1.45] border border-[color-mix(in_srgb,var(--danger)_38%,transparent)]">
            <UIcon name="alert" size={15} style={{ flexShrink: 0, marginTop: 1 }}/>
            <span>New position openings <strong className="font-bold">suspended for 24h</strong> — until <span className="font-mono text-pnldown-strong">{untilStr}</span>. Existing positions are still managed.</span>
          </div>
        )}
        <div className="flex flex-col gap-[11px] pt-[3px]">
          {comps.map(c => (
            <div className="grid grid-cols-[1fr_78px_36px] items-center gap-[11px]" key={c.label}>
              <span className="text-[12px] text-fg-3">{c.label}</span>
              <div className="h-1 rounded-chip bg-surface-3 overflow-hidden">
                <div className="h-full rounded-chip" style={{ width: (c.v * 100) + '%', background: barColor(c.v) }}/>
              </div>
              <span className="font-mono text-[11px] text-fg-2 text-right tabular-nums">{c.v.toFixed(2)}</span>
            </div>
          ))}
        </div>
      </div>
      <div className={CARD_FOOT}>
        <span className={FOOT_MONO}>UPDATED 38s AGO</span>
        <a href="#" className={LINK_ARROW}>View details →</a>
      </div>
    </div>
  );
};

const Dashboard = ({ regime, score, serverFault, paginate }) => {
  const suspended = regime === 'ELEVATED' || regime === 'CASCADE' || regime === 'BLACK SWAN';
  const until = new Date(Date.now() + 24 * 3600 * 1000);
  const untilStr = until.toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false, timeZone: 'UTC' }).replace(' at ', ', ') + ' UTC';
  return (
  <>
    <TipLayer/>
    <div className={PAGEHEAD}>
      <div>
        <div className={PH_EYEBROW}><UIcon name="dashboard" size={13} style={{ width: 13, height: 13 }}/>OVERVIEW</div>
        <h1 className={PH_H1}>Dashboard</h1>
        <div className={PH_SUB}>
          Engine running autonomously · <span className="font-mono tabular-nums text-fg-2">10</span> open positions · last sync <span className="font-mono tabular-nums text-fg-2">3s</span> ago
        </div>
      </div>
      <div className="flex items-center gap-3 flex-shrink-0 max-[820px]:flex-wrap max-[820px]:gap-y-2.5">
        <div className="flex items-center gap-2.5 whitespace-nowrap" title="BTC-USDT · timeframe closes: 1H 4H 1D 1W">
          <img className="w-[26px] h-[26px] rounded-full flex-shrink-0" src="https://s2.coinmarketcap.com/static/img/coins/64x64/1.png" alt="BTC"/>
          <div className="flex flex-col leading-[1.15]">
            <span className="font-mono text-[9.5px] font-medium tracking-[0.08em] uppercase text-fg-mute">Bitcoin · USDT</span>
            <span className="font-mono text-[15px] font-semibold text-fg-1 tabular-nums tracking-[-0.01em]">68,910.50</span>
          </div>
          <div className="flex gap-[3px] items-center">
            {['up','up','down','up'].map((d, i) => <i key={i} className={"block w-1.5 h-1.5 rounded-chip " + (d === 'up' ? 'bg-pnlup' : 'bg-pnldown')}/>)}
          </div>
        </div>
        <div className="w-px h-[22px] bg-line"/>
        <RegimePill regime={regime} score={score} pulse={regime === 'CASCADE' || regime === 'BLACK SWAN'}/>
        <div className="w-px h-[22px] bg-line"/>
        <button className={BTN_SECONDARY}><UIcon name="refresh" size={15}/>Sync</button>
        <button className={BTN_PRIMARY}><UIcon name="projections" size={15}/>View projections</button>
      </div>
    </div>

    {suspended && (
      <div className="flex items-center gap-3 py-[13px] px-4 mb-5 rounded-control bg-pnldown-bg text-pnldown border border-[color-mix(in_srgb,var(--danger)_45%,transparent)]">
        <span className="flex flex-shrink-0 animate-pulse-soft"><UIcon name="alert" size={18}/></span>
        <span className="text-[13px] leading-[1.45] flex-1 min-w-0">
          <strong className="text-pnldown-strong font-bold">New position openings suspended for 24h</strong> — Black Swan regime is <span className="font-mono text-pnldown-strong font-semibold">{regime} {score.toFixed(2)}</span>.
          Resumes <span className="font-mono text-pnldown-strong font-semibold">{untilStr}</span> if the regime clears. Existing positions are still managed.
        </span>
        <a href="#" className="flex-shrink-0 text-[12px] font-semibold no-underline text-pnldown whitespace-nowrap hover:text-pnldown-strong">View risk policy →</a>
      </div>
    )}

    <div className="grid grid-cols-4 gap-5 mb-6 max-[1080px]:grid-cols-2 max-[640px]:grid-cols-2 max-[640px]:gap-3 max-[420px]:grid-cols-1">
      {KPIS.map(k => <KpiTile key={k.key} k={k}/>)}
    </div>

    <PositionsSection paginate={paginate}/>

    {/* section separator between positions and the monitoring row */}
    <div className="flex items-center gap-4 my-7" role="separator" aria-label="Monitoring">
      <span className="h-px flex-1 bg-line"/>
      <span className="font-mono text-[10px] font-medium tracking-[0.14em] uppercase text-fg-mute flex items-center gap-[7px] whitespace-nowrap">
        <UIcon name="activity" size={13}/>Monitoring
      </span>
      <span className="h-px flex-1 bg-line"/>
    </div>

    <div className="grid grid-cols-3 gap-5 items-start max-[1080px]:grid-cols-1">
      <div className="flex flex-col gap-5 min-w-0 col-span-2 max-[1080px]:col-auto">
        <ActivityCard/>
      </div>
      <div className="flex flex-col gap-5 min-w-0">
        <ConnectivityCard fault={serverFault}/>
        <BscsMiniCard regime={regime} score={score}/>
      </div>
    </div>
  </>
  );
};

// ---- generic placeholder for non-dashboard routes (empty state) ----
const Placeholder = ({ route }) => {
  const meta = {
    positions:   { icon: 'layers',      title: 'Positions', desc: 'Full position lifecycle — open, history, and per-market detail.' },
    projections: { icon: 'projections', title: 'Projections', desc: 'Forecast equity and expected-return bands under the current regime.' },
    bscs:        { icon: 'bscs',         title: 'Black Swan Composite Score', desc: 'Market-risk regime detail, component breakdown, and trip history.' },
    accounts:    { icon: 'accounts',     title: 'Accounts', desc: 'Exchange credentials, connectivity testing, and per-account equity.' },
    billing:     { icon: 'billing',      title: 'Billing', desc: 'Balance, plan, wallet history, and top-up.' },
    profile:     { icon: 'user',         title: 'Profile', desc: 'Trader identity, sessions, notification and security settings.' },
  }[route] || { icon: 'dashboard', title: route, desc: '' };
  return (
    <>
      <div className={PAGEHEAD}>
        <div>
          <div className={PH_EYEBROW}><UIcon name={meta.icon} size={13} style={{ width: 13, height: 13 }}/>{meta.title.toUpperCase()}</div>
          <h1 className={PH_H1}>{meta.title}</h1>
          <div className={PH_SUB}>{meta.desc}</div>
        </div>
      </div>
      <div className="flex flex-col items-center justify-center text-center py-[90px] px-5 border border-dashed border-line rounded-surface bg-surface">
        <div className="w-12 h-12 rounded-control border border-line flex items-center justify-center text-fg-mute mb-4">
          <UIcon name={meta.icon} size={24}/>
        </div>
        <h4 className="font-sans font-semibold text-[22px] text-fg-1 leading-[1.2] tracking-[-0.01em] mb-1.5">{meta.title} — next in the build queue</h4>
        <p className="text-[14px] text-fg-3 max-w-[420px]">{meta.desc} We'll design this surface next, iterating page by page from the Dashboard.</p>
        <a href="Design System.html" className="mt-[18px] text-[13px] font-semibold no-underline text-accent">Open the design system reference →</a>
      </div>
    </>
  );
};

Object.assign(window, {
  Dashboard, Placeholder, Segmented, TipLayer, StatusPill, PositionTile,
  CARD_HEAD, CARD_TITLE, CARD_FOOT, FOOT_MONO, LINK_ARROW, LINK_QUIET,
  BTN, BTN_PRIMARY, BTN_SECONDARY, PAGEHEAD, PH_EYEBROW, PH_H1, PH_SUB, dotCls,
});
