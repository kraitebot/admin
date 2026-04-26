// Livewire 3's bundled livewire.esm registers Alpine's `$persist` magic via
// two code paths, causing a noisy "can't redefine non-configurable property
// $persist" on Alpine boot. Until the upstream bundle stops double-defining,
// swallow the redundant define so the console stays clean. Loaded as the
// first import of app.js so the patch is in place before Alpine boots.
const __origDefineProperty = Object.defineProperty;
Object.defineProperty = function (target, prop, descriptor) {
    if (prop === '$persist') {
        const existing = Object.getOwnPropertyDescriptor(target, prop);
        if (existing && existing.configurable === false) {
            return target;
        }
    }
    return __origDefineProperty.call(this, target, prop, descriptor);
};
