@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'w-full bg-zinc-800/50 border border-white/10 text-white placeholder-zinc-500 rounded-lg shadow-sm focus:border-krait-500 focus:ring-krait-500 focus:ring-1']) }}>
