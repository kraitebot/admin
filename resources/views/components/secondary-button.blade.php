<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center px-4 py-2 bg-zinc-800 border border-white/10 rounded-lg font-semibold text-xs text-zinc-300 uppercase tracking-widest shadow-sm hover:bg-zinc-700 focus:outline-none focus:ring-2 focus:ring-krait-500 focus:ring-offset-2 focus:ring-offset-zinc-900 disabled:opacity-25 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
