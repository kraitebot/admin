<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center px-5 py-2.5 bg-krait-500 border border-transparent rounded-lg font-semibold text-sm text-black tracking-wide hover:bg-krait-400 focus:bg-krait-600 active:bg-krait-600 focus:outline-none focus:ring-2 focus:ring-krait-500 focus:ring-offset-2 focus:ring-offset-zinc-900 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
