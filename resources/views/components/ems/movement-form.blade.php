@props(['ambulances', 'movement' => null, 'action' => null, 'method' => 'POST', 'submitLabel' => 'Save Movement'])

@php
    $locations = config('ems.movement_locations');
    $savedOrigin = old('origin', $movement?->origin);
    $originChoice = in_array($savedOrigin, $locations, true) || $savedOrigin === 'Other' ? $savedOrigin : (filled($savedOrigin) ? 'Other' : '');
    $otherOrigin = old('origin_other', $originChoice === 'Other' && $savedOrigin !== 'Other' ? $savedOrigin : '');
@endphp

<form method="POST" action="{{ $action ?: route('ems.dispatches.store') }}" class="space-y-5">
    @csrf
    @if(strtoupper($method) !== 'POST') @method($method) @endif
    <div class="rounded-xl border border-slate-200 bg-slate-50/60 p-5" x-data="{notesOpen:@js(filled(old('notes',$movement?->notes))),status:@js(old('status',$movement?->status ?? 'requested'))}">
        <div class="grid gap-x-5 gap-y-4 md:grid-cols-2 lg:grid-cols-3">
            <label><span class="gpha-label">Ambulance <span class="text-red-600">*</span></span><select name="ambulance_id" class="gpha-input" required><option value="">Select ambulance</option>@foreach($ambulances as $ambulance)<option value="{{ $ambulance->id }}" :disabled="status==='requested' && @js($ambulance->status !== 'available' && $ambulance->id !== $movement?->ambulance_id)" @selected((string) old('ambulance_id', $movement?->ambulance_id) === (string) $ambulance->id)>{{ $ambulance->fleet_number }} · {{ $ambulance->registration_number }}{{ $ambulance->status!=='available'?' · '.str($ambulance->status)->headline():'' }}</option>@endforeach</select></label>
            <label><span class="gpha-label">Priority <span class="text-red-600">*</span></span><select name="priority" class="gpha-input" required>@foreach(config('ems.movement_priorities') as $priority => $label)<option value="{{ $priority }}" @selected(old('priority',$movement?->priority ?? 'routine') === $priority)>{{ $label }}</option>@endforeach</select></label>
            <label><span class="gpha-label">Purpose / Case Category <span class="text-red-600">*</span></span><select name="purpose" class="gpha-input" required><option value="">Select case category</option>@foreach(config('ems.case_categories') as $category)<option value="{{ $category }}" @selected(old('purpose',$movement?->purpose) === $category)>{{ $category }}</option>@endforeach</select></label>
            <label><span class="gpha-label">Movement Date & Time <span class="text-red-600">*</span></span><input type="datetime-local" name="requested_at" value="{{ old('requested_at',$movement?->requested_at?->format('Y-m-d\TH:i') ?? now()->format('Y-m-d\TH:i')) }}" max="{{ now()->format('Y-m-d\TH:i') }}" step="60" class="gpha-input" required></label>
            <label><span class="gpha-label">Status <span class="text-red-600">*</span></span><select name="status" x-model="status" class="gpha-input" required><option value="requested">Requested</option><option value="completed">Completed</option></select></label>
            <div><span class="gpha-label">Origin <span class="text-red-600">*</span></span><x-ems.searchable-select name="origin" :options="[...$locations,'Other']" :value="$originChoice" placeholder="Search and select origin" other-name="origin_other" :other-value="$otherOrigin" other-label="Other Origin" /></div>
            <div><span class="gpha-label">Destination <span class="text-red-600">*</span></span><x-ems.searchable-select name="destination" :options="$locations" :value="old('destination',$movement?->destination)" placeholder="Search and select destination" /></div>
            <div class="flex items-end"><button type="button" @click="notesOpen=!notesOpen" class="gpha-button-secondary w-full" x-text="notesOpen ? 'Hide Notes' : 'Add Optional Notes'"></button></div>
            <label x-cloak x-show="notesOpen" x-transition class="md:col-span-2 lg:col-span-3"><span class="gpha-label">Operational Notes</span><textarea name="notes" class="gpha-input" rows="2" maxlength="2000" placeholder="Only add information that is important for this movement">{{ old('notes',$movement?->notes) }}</textarea></label>
        </div>
    </div>
    <div class="flex justify-end"><button class="gpha-button-primary">{{ $submitLabel }}</button></div>
</form>
