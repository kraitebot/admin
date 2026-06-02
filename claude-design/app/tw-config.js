/* =========================================================================
   KRAITE — Tailwind config (Play CDN)
   Every value maps to the CSS custom properties defined in
   assets/colors_and_type.css + app/shell.css, so utilities stay theme-aware
   (light/dark via [data-theme]) and respect runtime Tweaks overrides
   (--accent, --font-sans, ...). Preflight is OFF — colors_and_type.css owns
   the base reset + element typography.
   ========================================================================= */
tailwind.config = {
  corePlugins: { preflight: false },
  theme: {
    extend: {
      colors: {
        transparent: 'transparent',
        current: 'currentColor',

        black: 'var(--black)',
        bone: 'var(--bone)',

        ink: {
          0: 'var(--ink-0)', 1: 'var(--ink-1)', 2: 'var(--ink-2)', 3: 'var(--ink-3)',
          4: 'var(--ink-4)', 5: 'var(--ink-5)', 6: 'var(--ink-6)', 7: 'var(--ink-7)',
          8: 'var(--ink-8)', 9: 'var(--ink-9)',
        },
        green: {
          25: 'var(--green-25)', 50: 'var(--green-50)', 100: 'var(--green-100)',
          300: 'var(--green-300)', 500: 'var(--green-500)', 600: 'var(--green-600)', 700: 'var(--green-700)',
        },

        /* semantic surfaces (theme-aware) */
        canvas: 'var(--bg)',
        surface: {
          DEFAULT: 'var(--bg-elev-1)',
          2: 'var(--bg-elev-2)',
          3: 'var(--bg-elev-3)',
        },
        input: 'var(--bg-input)',
        hover: 'var(--bg-hover)',
        activebg: 'var(--bg-active)',

        /* foreground / text */
        fg: {
          DEFAULT: 'var(--fg)',
          1: 'var(--fg-1)', 2: 'var(--fg-2)', 3: 'var(--fg-3)',
          mute: 'var(--fg-mute)', faint: 'var(--fg-faint)',
          'on-accent': 'var(--fg-on-accent)',
        },

        /* borders */
        line: {
          DEFAULT: 'var(--border)',
          soft: 'var(--border-soft)',
          strong: 'var(--border-strong)',
          focus: 'var(--border-focus)',
        },

        /* brand accent (terminal green) */
        accent: {
          DEFAULT: 'var(--accent)',
          hover: 'var(--accent-hover)',
          press: 'var(--accent-press)',
          soft: 'var(--accent-soft)',
          on: 'var(--on-accent)',
        },

        /* P&L semantic */
        pnlup: {
          DEFAULT: 'var(--pnl-up-fg)',
          bg: 'var(--pnl-up-bg)',
          strong: 'var(--pnl-up-strong)',
        },
        pnldown: {
          DEFAULT: 'var(--pnl-down-fg)',
          bg: 'var(--pnl-down-bg)',
          strong: 'var(--pnl-down-strong)',
        },

        /* Black-Swan regime ramp */
        bsi: {
          calm: 'var(--bsi-calm)', watch: 'var(--bsi-watch)', elevated: 'var(--bsi-elevated)',
          cascade: 'var(--bsi-cascade)', blackswan: 'var(--bsi-blackswan)',
        },

        info: 'var(--info)',
        warn: 'var(--warn)',
        danger: 'var(--danger)',
        success: 'var(--success)',
      },

      fontFamily: {
        sans: 'var(--font-sans)',
        mono: 'var(--font-mono)',
        display: 'var(--font-display)',
      },

      borderRadius: {
        surface: 'var(--ar-surface)',   /* 14px */
        control: 'var(--ar-control)',   /* 10px */
        chip: 'var(--ar-chip)',         /* 999px */
        r1: 'var(--r-1)', r2: 'var(--r-2)', r3: 'var(--r-3)',
      },

      boxShadow: {
        1: 'var(--shadow-1)',
        2: 'var(--shadow-2)',
        3: 'var(--shadow-3)',
        glow: 'var(--shadow-glow)',
        danger: 'var(--shadow-danger)',
        insetline: 'var(--shadow-inset)',
      },

      transitionTimingFunction: {
        out: 'var(--ease-out)',
        ink: 'var(--ease-in)',
        snap: 'var(--ease-snap)',
      },
      transitionDuration: {
        instant: '80ms', fast: '140ms', base: '200ms', slow: '320ms',
      },

      maxWidth: {
        prose: 'var(--max-w-prose)',
        content: 'var(--max-w-content)',
        wide: 'var(--max-w-wide)',
      },

      keyframes: {
        'pulse-soft': { '0%,100%': { opacity: '1' }, '50%': { opacity: '0.45' } },
        'dd-in': { from: { opacity: '0', transform: 'translateY(-4px)' }, to: { opacity: '1', transform: 'translateY(0)' } },
        'flash-up': { '0%': { background: 'var(--pnl-up-bg)' }, '100%': { background: 'transparent' } },
        'flash-down': { '0%': { background: 'var(--pnl-down-bg)' }, '100%': { background: 'transparent' } },
      },
      animation: {
        'pulse-soft': 'pulse-soft 1.4s ease-in-out infinite',
        'dd-in': 'dd-in 120ms var(--ease-out)',
        'flash-up': 'flash-up 0.6s var(--ease-out)',
        'flash-down': 'flash-down 0.6s var(--ease-out)',
      },
    },
  },
};
