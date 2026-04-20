import './bootstrap';

import { Livewire, Alpine } from '../../vendor/livewire/livewire/dist/livewire.esm';
import collapse from '@alpinejs/collapse';
import { initToast, initConfirmation, registerCounter } from '/home/waygou/packages/brunocfalcao/hub-ui/resources/js/hub-ui.js';

Alpine.plugin(collapse);

Livewire.start();

initToast();
initConfirmation();
registerCounter();
