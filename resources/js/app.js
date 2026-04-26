import './persist-patch';
import './bootstrap';

import { Livewire, Alpine } from '../../vendor/livewire/livewire/dist/livewire.esm';
import collapse from '@alpinejs/collapse';
import { initToast, initConfirmation, registerCounter, registerSidebarStore } from '/home/waygou/packages/brunocfalcao/hub-ui/resources/js/hub-ui.js';

Alpine.plugin(collapse);

registerSidebarStore(Alpine);
registerCounter();

Livewire.start();

initToast();
initConfirmation();
