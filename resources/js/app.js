import './livewire-config';
import { Livewire, Alpine } from '../../vendor/livewire/livewire/dist/livewire.esm';

// NOTE: no separate @alpinejs/* plugin imports — Livewire's bundle already
// registers collapse, persist, morph, focus, etc. on start. Adding our own
// copies would double-register them.
window.Alpine = Alpine;

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

Livewire.start();
