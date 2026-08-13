@props([
    'name',
    'options',
    'value' => '',
    'placeholder' => 'Search and select',
    'otherName' => null,
    'otherValue' => '',
    'otherLabel' => 'Other value',
])

<div class="relative" x-data="{
    open:false,
    search:@js($value),
    selected:@js($value),
    options:@js(array_values($options)),
    filtered(){const term=this.search.trim().toLowerCase();return term===''?this.options:this.options.filter(option=>option.toLowerCase().includes(term))},
    choose(option){this.selected=option;this.search=option;this.open=false},
    sync(){this.selected=this.options.includes(this.search)?this.search:'';this.open=true}
}" @click.outside="open=false" @keydown.escape.window="open=false">
    <input type="hidden" name="{{ $name }}" x-model="selected">
    <div class="relative">
        <input type="search" x-model="search" @focus="open=true" @input="sync()" class="gpha-input pr-11" placeholder="{{ $placeholder }}" autocomplete="off" required role="combobox" :aria-expanded="open">
        <button type="button" @click="open=!open" class="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-slate-500" aria-label="Toggle options">
            <svg class="h-5 w-5 transition-transform" :class="open?'rotate-180':''" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd"/></svg>
        </button>
    </div>
    <div x-cloak x-show="open" x-transition class="absolute z-50 mt-2 max-h-64 w-full overflow-y-auto rounded-xl border border-slate-200 bg-white p-2 shadow-xl" role="listbox">
        <template x-for="option in filtered()" :key="option"><button type="button" @click="choose(option)" @keydown.arrow-down.prevent="$el.nextElementSibling?.focus()" @keydown.arrow-up.prevent="$el.previousElementSibling?.focus()" class="flex w-full items-center justify-between rounded-lg px-3 py-2.5 text-left font-semibold text-slate-700 hover:bg-blue-50 hover:text-gpha-primary focus:bg-blue-50 focus:text-gpha-primary focus:outline-none" role="option"><span x-text="option"></span><svg x-show="selected===option" class="h-5 w-5 text-emerald-600" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-8 8a1 1 0 0 1-1.4 0l-4-4a1 1 0 1 1 1.4-1.4L8 12.6l7.3-7.3a1 1 0 0 1 1.4 0Z" clip-rule="evenodd"/></svg></button></template>
        <p x-show="filtered().length===0" class="px-3 py-4 text-center font-semibold text-slate-500">No matching option</p>
    </div>
    @if($otherName)
        <label x-cloak x-show="selected==='Other'" x-transition class="mt-3 block"><span class="gpha-label">{{ $otherLabel }} <span class="text-red-600">*</span></span><input name="{{ $otherName }}" value="{{ $otherValue }}" class="gpha-input" maxlength="160" placeholder="Enter the origin" :required="selected==='Other'" :disabled="selected!=='Other'"></label>
    @endif
</div>
