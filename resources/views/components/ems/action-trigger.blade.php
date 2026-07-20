@props(['label' => 'Actions'])
<button type="button" {{ $attributes->merge(['class' => 'gpha-action-trigger', 'aria-label' => $label]) }}>
    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round" /></svg>
</button>
