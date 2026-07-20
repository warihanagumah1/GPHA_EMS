@props(['type' => 'success', 'class' => ''])

@php
    $palette = match ($type) {
        'error' => 'border-red-200 bg-red-50 text-red-700',
        'warning' => 'border-amber-200 bg-amber-50 text-amber-800',
        default => 'border-emerald-200 bg-emerald-50 text-emerald-700',
    };
@endphp

<div x-data="{ open: true }" x-init="setTimeout(() => open = false, 60000)" x-show="open" x-transition.opacity.duration.150ms class="relative rounded border px-4 py-3 text-[18px] font-bold {{ $palette }} {{ $class }}" role="status">
    <div class="pr-9">{{ $slot }}</div>
    <button type="button" @click="open = false" class="absolute right-3 top-2.5 min-h-0 p-0 text-[24px] font-bold leading-none text-current opacity-70 shadow-none hover:opacity-100" aria-label="Close alert">×</button>
</div>
