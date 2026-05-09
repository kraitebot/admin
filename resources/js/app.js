import './persist-patch';
import './bootstrap';
import './lifecycle/engine';
import './lifecycle/grid';

import { Livewire, Alpine } from '../../vendor/livewire/livewire/dist/livewire.esm';
import collapse from '@alpinejs/collapse';
import { initToast, initConfirmation, registerCounter, registerSidebarStore } from '../../vendor/brunocfalcao/hub-ui/resources/js/hub-ui.js';

Alpine.plugin(collapse);

registerSidebarStore(Alpine);
registerCounter();

Livewire.start();

initToast();
initConfirmation();
