import './bootstrap';

import * as Turbo from '@hotwired/turbo';
window.Turbo = Turbo;
import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import { initToast, initConfirmation } from './vendor/hub-ui/hub-ui.js';

Alpine.plugin(collapse);
window.Alpine = Alpine;

// Start Alpine once on initial load.
Alpine.start();

// Initialize hub-ui modules on every page visit.
function initHubUI() {
    initToast();
    initConfirmation();
}

document.addEventListener('DOMContentLoaded', initHubUI);
document.addEventListener('turbo:load', initHubUI);

// Ensure Turbo doesn't cache pages with stale Alpine state.
document.addEventListener('turbo:before-cache', function () {
    // Remove Alpine-generated attributes so they re-init cleanly.
    document.querySelectorAll('[x-cloak]').forEach(el => {
        el.setAttribute('x-cloak', '');
    });
});
