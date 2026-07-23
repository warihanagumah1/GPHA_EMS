<button type="button" @click="filtersOpen=!filtersOpen" :aria-expanded="filtersOpen" class="mb-4 flex w-full items-center justify-between rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-left font-extrabold text-slate-800 md:hidden">
    <span class="flex items-center gap-2">
        <svg class="h-5 w-5 text-gpha-primary" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 5h16l-6.5 7.2V18l-3 1.5v-7.3L4 5Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round" /></svg>
        <span x-text="filtersOpen ? 'Hide Filters' : 'Show Filters'">Show Filters</span>
    </span>
    <svg class="h-5 w-5 transition-transform" :class="filtersOpen ? 'rotate-180' : ''" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" /></svg>
</button>
