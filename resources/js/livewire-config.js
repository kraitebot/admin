// Must evaluate BEFORE the livewire.esm module (import order in app.js).
// Defining livewireScriptConfig disables livewire.esm's DOMContentLoaded
// auto-start — without this, that auto-start plus our manual
// Livewire.start() boots Livewire twice, re-applying the Alpine plugin
// array and crashing on persist's non-configurable $persist property
// ("Cannot redefine property: $persist" on every page load).
window.livewireScriptConfig = {};
