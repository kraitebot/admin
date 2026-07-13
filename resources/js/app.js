import './livewire-config';
import { Livewire, Alpine } from '../../vendor/livewire/livewire/dist/livewire.esm';

// NOTE: no separate @alpinejs/* plugin imports — Livewire's bundle already
// registers collapse, persist, morph, focus, etc. on start. Adding our own
// copies would double-register them.
window.Alpine = Alpine;

// ---------------------------------------------------------------------------
// Rail active state — a GLOBAL Alpine store + module-level handlers, NOT
// per-component x-data. The rail is persisted across wire:navigate swaps and
// Alpine re-initializes it after each swap, stacking listeners bound to stale
// closures; instance state desyncs (the source of the vanishing-link bugs).
// A store + functions defined once here are immune to re-init counts.
// ---------------------------------------------------------------------------
Alpine.store('rail', { activeId: null, hl: null, drawerOpen: false });

// ---------------------------------------------------------------------------
// Global help/explainer store. Backs the reusable `<x-ui.help-dot>` [?] dots
// and the single `<x-ui.help-modal>` rendered once in the app layout — so any
// page gets a help popup with zero per-page boilerplate. Two content modes:
//   - inline:   $store.help.showInline(title, body)   (help-dot title/body props)
//   - registry: page calls register({ key: {t,s,b} }), help-dot topic="key"
// `renderMd` is the same lightweight markdown renderer the backtesting console
// used before it was hoisted here.
// ---------------------------------------------------------------------------
Alpine.store('help', {
    open: false,
    title: '',
    body: '',
    registry: {},
    register(map) { Object.assign(this.registry, map || {}); },
    show(topic) {
        const m = this.registry[topic];
        if (!m) { return; }
        this.title = m.t || '';
        this.body = m.b || '';
        this.open = true;
    },
    showInline(title, body) {
        this.title = title || '';
        this.body = body || '';
        this.open = true;
    },
    close() { this.open = false; },
    renderMd(src) {
        if (!src) return '';
        const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        const inl = (t) => esc(t)
            .replace(/`([^`]+)`/g, '<code class="font-mono text-[11.5px] px-1 py-[1px] rounded-r2 bg-surface-3 text-accent">$1</code>')
            .replace(/\*\*([^*]+)\*\*/g, '<strong class="font-semibold text-fg-1">$1</strong>')
            .replace(/(^|[^*])\*([^*]+)\*/g, '$1<em class="italic text-fg-2">$2</em>')
            .replace(/_([^_]+)_/g, '<em class="italic text-fg-mute">$1</em>');
        const li = (body) => {
            const m = body.match(/^\*\*([^*]+?):?\*\*\s*[—–:-]?\s*([\s\S]*)$/);
            if (m && m[2].trim()) {
                return '<li class="flex gap-2.5"><span class="font-mono text-[9px] font-bold tracking-[0.06em] uppercase text-fg-3 leading-tight mt-[3px] w-[74px] flex-shrink-0">' + esc(m[1]) + '</span><span class="text-[12.5px] text-fg-2 leading-normal min-w-0">' + inl(m[2]) + '</span></li>';
            }
            return '<li class="flex gap-2"><span class="text-accent flex-shrink-0 mt-[1px]">·</span><span class="text-[12.5px] text-fg-2 leading-normal min-w-0">' + inl(body) + '</span></li>';
        };
        const lines = src.split('\n'); const out = []; let list = null;
        const flush = () => { if (list) { out.push('<ul class="flex flex-col gap-[5px] mt-1 mb-2 pl-0.5">' + list.join('') + '</ul>'); list = null; } };
        lines.forEach((raw) => {
            const ln = raw.replace(/^[ \t]+/, '');
            if (/^(\s*[-*_]){3,}\s*$/.test(ln)) { flush(); out.push('<div class="my-3 border-t border-line-soft"></div>'); }
            else if (/^###\s+/.test(ln)) { flush(); out.push('<h5 class="font-mono text-[10px] font-bold tracking-[0.1em] uppercase text-fg-mute mt-3 mb-1">' + inl(ln.replace(/^###\s+/, '')) + '</h5>'); }
            else if (/^##\s+/.test(ln)) { flush(); out.push('<h4 class="font-sans font-bold text-[14px] text-fg-1 mt-4 first:mt-0 mb-2 pb-1 border-b border-line-soft">' + inl(ln.replace(/^##\s+/, '')) + '</h4>'); }
            else if (/^\d+\.\s+/.test(ln)) { flush(); const m = ln.match(/^(\d+)\.\s+(.*)$/); out.push('<div class="flex gap-2.5 items-baseline mt-3 first:mt-0"><span class="font-mono text-[11px] font-bold text-accent flex-shrink-0">' + m[1] + '</span><span class="text-[12.5px] font-semibold text-fg-1 leading-snug min-w-0">' + inl(m[2]) + '</span></div>'); }
            else if (/^[-*]\s+/.test(ln)) { list = list || []; list.push(li(ln.replace(/^[-*]\s+/, ''))); }
            else if (ln.trim() === '') { flush(); }
            else { flush(); out.push('<p class="text-[12.5px] text-fg-2 leading-normal my-1.5">' + inl(ln) + '</p>'); }
        });
        flush();
        return out.join('');
    },
});

const railNav = () => document.querySelector('nav[data-rail]');

const railMeasure = (el) => {
    Alpine.store('rail').hl = el
        ? { left: el.offsetLeft, top: el.offsetTop, width: el.offsetWidth, height: el.offsetHeight }
        : null;
};

const railSyncFromUrl = () => {
    const nav = railNav();
    if (!nav) {
        return;
    }
    const here = location.origin + location.pathname.replace(/\/$/, '');
    const match = Array.from(nav.querySelectorAll('a[href][data-id]'))
        .find(a => a.href.replace(/\/$/, '') === here);
    Alpine.store('rail').activeId = match ? match.dataset.id : null;
    railMeasure(match || null);
};

window.railGo = (id, el) => {
    const store = Alpine.store('rail');
    // Navigating from the mobile drawer always closes it, even when the
    // tapped item is already active.
    store.drawerOpen = false;
    if (store.activeId === id) {
        return;
    }
    // Departing link snaps to gray INSTANTLY — easing it at the pill's
    // 420ms leaves dark-on-dark text once the pill slides away. Restore
    // the transition only after the next paint (double rAF) so the snap
    // can't animate.
    const old = store.activeId ? railNav()?.querySelector(`a[data-id='${store.activeId}']`) : null;
    store.activeId = id;
    if (old) {
        old.style.transition = 'none';
        requestAnimationFrame(() => requestAnimationFrame(() => { old.style.transition = ''; }));
    }
    railMeasure(el);
};

document.addEventListener('livewire:navigated', () => requestAnimationFrame(railSyncFromUrl));
window.addEventListener('resize', () => railMeasure(railNav()?.querySelector(`a[data-id='${Alpine.store('rail').activeId}']`) || null));
if (document.fonts && document.fonts.ready) {
    document.fonts.ready.then(railSyncFromUrl);
}

// ---------------------------------------------------------------------------
// SPA navigation fade — wire:navigate swaps the <body>; the shell (rail,
// top bar, footer) survives via @persist, so only the content column visibly
// changes. Sequence per click: fade the .content out (160ms), then let
// Livewire perform the swap, then snap the fresh .content to opacity 0 and
// release it so it fades in. History (back/forward) pops skip the out-fade —
// cancelling those would desync the URL — and only fade in.
// ---------------------------------------------------------------------------
const FADE_MS = 160;
let fadingNavigate = false;

document.addEventListener('livewire:navigate', (event) => {
    if (fadingNavigate || event.detail.history) {
        return; // second pass (our own re-trigger) or back/forward: swap now
    }

    const content = document.querySelector('.content');
    if (!content) {
        return;
    }

    event.preventDefault();
    fadingNavigate = true;
    content.style.opacity = '0';
    setTimeout(() => Livewire.navigate(event.detail.url.href), FADE_MS);
});

document.addEventListener('livewire:navigating', (event) => {
    // Snap the incoming .content to opacity 0 inside the swap, BEFORE the
    // browser paints the new page — without this the new page flashes fully
    // opaque for a frame, which reads as the whole viewport (shell included)
    // blinking instead of a content-only fade.
    event.detail.onSwap(() => {
        const content = document.querySelector('.content');
        if (!content) {
            return;
        }
        content.style.transition = 'none';
        content.style.opacity = '0';
    });
});

document.addEventListener('livewire:navigated', () => {
    fadingNavigate = false;

    const content = document.querySelector('.content');
    if (!content) {
        return;
    }

    // Release the swap-time snap on the next frame so the CSS transition
    // carries the fresh content from 0 to 1.
    requestAnimationFrame(() => {
        requestAnimationFrame(() => {
            content.style.transition = '';
            content.style.opacity = '';
        });
    });
});

// ---------------------------------------------------------------------------
// hubUiFetch — the admin AJAX bridge every server-driven surface calls. Wraps
// fetch with CSRF (Laravel's XSRF-TOKEN cookie -> X-XSRF-TOKEN header, read
// fresh each call so it survives wire:navigate swaps), JSON accept + encode,
// same-origin cookies, and a NON-throwing { ok, data, status } return. The
// backtesting console (Fetch / Verify / Run / Approve / AI) drives all five
// BacktrackingController endpoints through this. Hand-rolled fetch() would
// skip the CSRF header and 419 behind the web middleware group.
// ---------------------------------------------------------------------------
const readCookie = (name) => {
    const match = document.cookie.match('(^|;)\\s*' + name + '\\s*=\\s*([^;]+)');

    return match ? decodeURIComponent(match.pop()) : null;
};

window.hubUiFetch = async (url, options = {}) => {
    const { body, method, headers = {}, toastOnError = false, ...rest } = options;
    const hasBody = body !== undefined && body !== null;

    const finalHeaders = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...headers,
    };

    // Laravel decrypts the X-XSRF-TOKEN header against the session; the cookie
    // value is sent verbatim (still encrypted) and the framework unwraps it.
    const xsrf = readCookie('XSRF-TOKEN');
    if (xsrf) {
        finalHeaders['X-XSRF-TOKEN'] = xsrf;
    }

    let payload;
    if (hasBody) {
        if (body instanceof FormData) {
            payload = body;
        } else {
            payload = JSON.stringify(body);
            finalHeaders['Content-Type'] = 'application/json';
        }
    }

    try {
        const res = await fetch(url, {
            method: method || (hasBody ? 'POST' : 'GET'),
            headers: finalHeaders,
            credentials: 'same-origin',
            body: payload,
            ...rest,
        });

        // Read as text first so an empty body or an HTML error page doesn't
        // explode JSON.parse — callers always get a plain object back.
        let data = {};
        const text = await res.text();
        if (text) {
            try {
                data = JSON.parse(text);
            } catch (_) {
                data = { error: text };
            }
        }
        // Surface the HTTP status to callers that branch on it (e.g. 429
        // rate-limit messaging) without clobbering a real `status` field.
        if (data.status === undefined) {
            data.status = res.status;
        }
        // Framework error responses (503 maintenance, 419 session expired,
        // 429 throttle) carry `message`, not `error` — mirror it so callers'
        // `data.error || 'generic text'` fallbacks show the real reason.
        // (2026-07-09: a deploy's maintenance window 503'd four Reject
        // clicks and the toast said only "Could not save decision".)
        if (!res.ok && data.error === undefined && typeof data.message === 'string' && data.message !== '') {
            data.error = data.message;
        }

        if (!res.ok && toastOnError && typeof window.showToast === 'function') {
            window.showToast(data.error || `Request failed (${res.status})`, 'error');
        }

        return { ok: res.ok, data, status: res.status };
    } catch (e) {
        if (toastOnError && typeof window.showToast === 'function') {
            window.showToast('Network error', 'error');
        }

        return { ok: false, data: { error: e?.message || 'Network error', status: 0 }, status: 0 };
    }
};

Livewire.start();
