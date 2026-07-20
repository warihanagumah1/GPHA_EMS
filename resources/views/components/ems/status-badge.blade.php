@props(['status'])
@php
    $value = strtoupper((string) $status);
    $label = str($status)->replace('_', ' ')->lower()->title();
    $classes = match ($value) {
        'AVAILABLE', 'COMPLETED', 'APPROVED', 'RESPONDED' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'DISPATCHED', 'ARRIVED', 'SUBMITTED', 'IN_PROGRESS' => 'bg-blue-50 text-blue-700 ring-blue-200',
        'REQUESTED', 'DRAFT', 'ROUTINE' => 'bg-slate-100 text-slate-700 ring-slate-200',
        'URGENT', 'MAINTENANCE' => 'bg-amber-50 text-amber-700 ring-amber-200',
        'CRITICAL', 'UNAVAILABLE', 'CANCELLED', 'NEGATIVE' => 'bg-red-50 text-red-700 ring-red-200',
        default => 'bg-slate-50 text-slate-700 ring-slate-200',
    };
@endphp
<span {{ $attributes->merge(['class' => "gpha-status-badge inline-flex items-center rounded-full px-2.5 py-1 font-bold ring-1 ring-inset {$classes}"]) }}>{{ $label }}</span>
