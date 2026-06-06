// Kraite admin icon set — monoline, 1.75 stroke, currentColor, square caps.
// Lucide-family substitute per design system iconography rules.

const KI = {
  // ---- rail nav ----
  dashboard: 'M3 3h8v8H3zM13 3h8v5h-8zM13 10h8v11h-8zM3 13h8v8H3z',
  projections: 'M3 3v18h18 M7 14l4-5 3 3 5-7',
  billing: 'M3 6h18v12H3zM3 10h18 M7 14h4',
  bscs: 'M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6l8-3z M12 9v4 M12 16h.01',
  accounts: 'M9 2v4 M15 2v4 M7 6h10v6a5 5 0 0 1-10 0V6z M12 17v5',
  positions: 'M3 12l9-5 9 5-9 5z M3 17l9 5 9-5',
  profile: 'M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z M5 21v-1a7 7 0 0 1 14 0v1',
};

// path-based simple icons (single or multi path via array)
const KI_UI = {
  search: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="square" strokeLinejoin="miter"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4-4"/></svg>),
  bell: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="square" strokeLinejoin="miter"><path d="M6 16V11a6 6 0 1 1 12 0v5l2 2H4l2-2zM10 20a2 2 0 0 0 4 0"/></svg>),
  sun: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="square" strokeLinejoin="miter"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4 12H2M22 12h-2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"/></svg>),
  moon: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="square" strokeLinejoin="miter"><path d="M20 14a8 8 0 1 1-10-10 6 6 0 0 0 10 10z"/></svg>),
  user: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="square" strokeLinejoin="miter"><circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1"/></svg>),
  logout: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="square" strokeLinejoin="miter"><path d="M14 4h5v16h-5M3 12h12M11 8l4 4-4 4"/></svg>),
  chevronDown: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="square" strokeLinejoin="miter"><path d="M5 9l7 7 7-7"/></svg>),
  chevronUp: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="square" strokeLinejoin="miter"><path d="M5 15l7-7 7 7"/></svg>),
  chevronRight: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="square" strokeLinejoin="miter"><path d="M9 5l7 7-7 7"/></svg>),
  chevronLeft: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="square" strokeLinejoin="miter"><path d="M15 5l-7 7 7 7"/></svg>),
  arrowUpRight: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="square" strokeLinejoin="miter"><path d="M7 17L17 7M8 7h9v9"/></svg>),
  arrowUp: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="square" strokeLinejoin="miter"><path d="M12 19V5M6 11l6-6 6 6"/></svg>),
  arrowDown: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="square" strokeLinejoin="miter"><path d="M12 5v14M6 13l6 6 6-6"/></svg>),
  refresh: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="square" strokeLinejoin="miter"><path d="M3 12a9 9 0 0 1 15-6.7L21 8M21 3v5h-5M21 12a9 9 0 0 1-15 6.7L3 16M3 21v-5h5"/></svg>),
  alert: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="square" strokeLinejoin="miter"><path d="M12 3l10 18H2L12 3zM12 9v5M12 18h.01"/></svg>),
  plugOff: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="square" strokeLinejoin="miter"><path d="M9 2v3M15 2v3M7 6h10v5a5 5 0 0 1-5 5 5 5 0 0 1-5-5V6zM12 16v6M3 3l18 18"/></svg>),
  more: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="miter"><circle cx="5" cy="12" r="1.2" fill="currentColor"/><circle cx="12" cy="12" r="1.2" fill="currentColor"/><circle cx="19" cy="12" r="1.2" fill="currentColor"/></svg>),
  filter: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="square" strokeLinejoin="miter"><path d="M3 5h18l-7 8v6l-4 2v-8z"/></svg>),
  activity: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>),
  download: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="square" strokeLinejoin="miter"><path d="M12 3v12M7 11l5 5 5-5M4 21h16"/></svg>),
  clock: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="square" strokeLinejoin="miter"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>),
  bot: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="square" strokeLinejoin="miter"><rect x="4" y="8" width="16" height="12" rx="1"/><path d="M12 4v4M9 13h.01M15 13h.01M9 17h6"/></svg>),
  zap: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="square" strokeLinejoin="miter"><path d="M13 3L4 14h7l-1 7 9-11h-7l1-7z"/></svg>),
  wallet: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="square" strokeLinejoin="miter"><path d="M3 6h15v12H3zM18 10h3v4h-3a2 2 0 0 1 0-4z"/></svg>),
  layers: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="square" strokeLinejoin="miter"><path d="M3 12l9-5 9 5-9 5z M3 17l9 5 9-5"/></svg>),
  projections: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="square" strokeLinejoin="miter"><path d="M3 3v18h18"/><path d="M7 14l4-5 3 3 5-7"/></svg>),
  gauge: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="square" strokeLinejoin="miter"><path d="M12 14l4-4"/><circle cx="12" cy="14" r="0.6" fill="currentColor"/><path d="M3.5 16a9 9 0 1 1 17 0"/></svg>),
  scissors: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="square" strokeLinejoin="miter"><circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M20 4L8.5 15.5M14.5 14.5L20 20M8.5 8.5L11 11"/></svg>),
  coins: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="square" strokeLinejoin="miter"><ellipse cx="9" cy="7" rx="6" ry="3"/><path d="M3 7v5c0 1.7 2.7 3 6 3s6-1.3 6-3V7M9 12v5c0 1.7 2.7 3 6 3s6-1.3 6-3v-5"/></svg>),
  flag: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="square" strokeLinejoin="miter"><path d="M5 21V4M5 4h12l-2.5 4L17 12H5"/></svg>),
  arrowRight: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="square" strokeLinejoin="miter"><path d="M4 12h16M14 6l6 6-6 6"/></svg>),
  circleSm: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="square" strokeLinejoin="miter"><circle cx="12" cy="12" r="8"/></svg>),
  check: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="square" strokeLinejoin="miter"><path d="M4 12l5 5 11-11"/></svg>),
  server: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="square" strokeLinejoin="miter"><rect x="3" y="4" width="18" height="7" rx="1"/><rect x="3" y="13" width="18" height="7" rx="1"/><path d="M7 7.5h.01M7 16.5h.01"/></svg>),
  shield: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="square" strokeLinejoin="miter"><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6l8-3z"/></svg>),
  copy: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="square" strokeLinejoin="miter"><rect x="9" y="9" width="11" height="11" rx="1.5"/><path d="M5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1"/></svg>),
  key: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="square" strokeLinejoin="miter"><circle cx="7.5" cy="15.5" r="4.5"/><path d="M10.7 12.3L21 2M16 7l3 3M14 9l2 2"/></svg>),
  eye: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="square" strokeLinejoin="miter"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>),
  eyeOff: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="square" strokeLinejoin="miter"><path d="M10.6 5.1A10.9 10.9 0 0 1 12 5c6.5 0 10 7 10 7a18 18 0 0 1-3 3.9M6.6 6.6A18 18 0 0 0 2 12s3.5 7 10 7a10.9 10.9 0 0 0 4-.7M9.9 9.9a3 3 0 0 0 4.2 4.2M3 3l18 18"/></svg>),
  plus: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="square" strokeLinejoin="miter"><path d="M12 5v14M5 12h14"/></svg>),
  pause: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="square" strokeLinejoin="miter"><path d="M8 4v16M16 4v16"/></svg>),
  play: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="square" strokeLinejoin="miter"><path d="M6 4l14 8-14 8z"/></svg>),
  lock: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="square" strokeLinejoin="miter"><rect x="4" y="11" width="16" height="10" rx="1"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>),
  gift: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="square" strokeLinejoin="miter"><path d="M4 11h16v9H4zM3 7h18v4H3zM12 7v13M12 7S10.5 3 8 3a2 2 0 0 0 0 4M12 7s1.5-4 4-4a2 2 0 0 1 0 4"/></svg>),
  arrowDownLeft: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="square" strokeLinejoin="miter"><path d="M17 7L7 17M16 17H7V8"/></svg>),
  minus: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="square" strokeLinejoin="miter"><path d="M5 12h14"/></svg>),
  infinity: (p) => (<svg {...p} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round"><path d="M6.5 8.5a3.5 3.5 0 1 0 0 7c1.8 0 3-1.5 4.5-3.5l1.5-2c1.5-2 2.7-3.5 4.5-3.5a3.5 3.5 0 1 1 0 7c-1.8 0-3-1.5-4.5-3.5"/></svg>),
};

const RailIcon = ({ name, size = 22 }) => (
  <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="square" strokeLinejoin="miter">
    {KI[name].split(' M').map((d, i) => <path key={i} d={(i ? 'M' : '') + d}/>)}
  </svg>
);

const UIcon = ({ name, size = 18, style }) => {
  const C = KI_UI[name];
  if (!C) return null;
  return <C width={size} height={size} style={{ display: 'block', flexShrink: 0, ...style }}/>;
};

Object.assign(window, { RailIcon, UIcon });
