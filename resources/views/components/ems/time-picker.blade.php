@props([
    'name',
    'value' => '',
    'label' => 'Time',
    'id' => null,
    'required' => false,
])

@php($fieldId = $id ?: 'time-'.str_replace(['[', ']', '_'], '-', $name))

<div class="relative" data-time-picker x-data="{
    open:false,
    value:@js($value),
    hour:'00',
    minute:'00',
    syncPicker(){const match=this.value.match(/^([01][0-9]|2[0-3]):([0-5][0-9])$/);if(match){this.hour=match[1];this.minute=match[2]}},
    toggle(){this.open=!this.open;if(this.open){this.syncPicker();this.$nextTick(() => this.$refs.hour.focus())}},
    apply(){this.value=`${this.hour}:${this.minute}`;this.open=false;this.$refs.input.dispatchEvent(new Event('input',{bubbles:true}))}
}" @click.outside="open=false" @keydown.escape.window="open=false">
    <label for="{{ $fieldId }}" class="gpha-label">{{ $label }} @if($required)<span class="text-red-600">*</span>@endif</label>
    <div class="relative">
        <input id="{{ $fieldId }}" x-ref="input" x-model="value" type="text" name="{{ $name }}" value="{{ $value }}" inputmode="numeric" placeholder="HH:MM" pattern="(?:[01][0-9]|2[0-3]):[0-5][0-9]" maxlength="5" autocomplete="off" title="Enter time in 24-hour HH:MM format, for example 14:48." class="gpha-input pr-12" @required($required)>
        <button type="button" @click="toggle()" class="absolute inset-y-0 right-0 flex w-12 items-center justify-center rounded-r text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-gpha-primary" aria-label="Open 24-hour clock selector" :aria-expanded="open">
            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 7v5l3 2"/></svg>
        </button>
    </div>
    <p class="mt-1 text-sm text-slate-500">24-hour format (HH:MM), e.g. 14:48.</p>

    <div x-cloak x-show="open" x-transition class="absolute right-0 z-50 mt-2 w-full min-w-64 rounded-xl border border-slate-200 bg-white p-4 shadow-xl" role="dialog" aria-label="Choose a time">
        <div class="grid grid-cols-[1fr_auto_1fr] items-end gap-2">
            <label><span class="gpha-label">Hour</span><select x-ref="hour" x-model="hour" class="gpha-input" aria-label="Hour in 24-hour format">@foreach(range(0,23) as $hour)<option value="{{ sprintf('%02d',$hour) }}">{{ sprintf('%02d',$hour) }}</option>@endforeach</select></label>
            <span class="pb-2 text-2xl font-black text-slate-600" aria-hidden="true">:</span>
            <label><span class="gpha-label">Minute</span><select x-model="minute" class="gpha-input" aria-label="Minute">@foreach(range(0,59) as $minute)<option value="{{ sprintf('%02d',$minute) }}">{{ sprintf('%02d',$minute) }}</option>@endforeach</select></label>
        </div>
        <div class="mt-4 flex justify-end gap-2">
            <button type="button" @click="open=false" class="gpha-button-secondary">Cancel</button>
            <button type="button" @click="apply()" class="gpha-button-primary">Set Time</button>
        </div>
    </div>

    @error($name)<p class="mt-1 text-sm font-semibold text-red-600">{{ $message }}</p>@enderror
</div>
