@props(['status'])

@if ($status)
    <div {{ $attributes->merge(['class' => 'font-medium text-sm text-krait-400']) }}>
        {{ $status }}
    </div>
@endif
