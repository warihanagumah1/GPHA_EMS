@props(['ambulance' => null, 'action', 'method' => 'POST', 'submitLabel' => 'Save Ambulance'])

<form method="POST" action="{{ $action }}" class="space-y-5">
    @csrf
    @if(strtoupper($method) !== 'POST') @method($method) @endif
    <div class="rounded-lg border border-slate-200 bg-slate-50/60 p-5">
        <h2 class="mb-5 text-xl font-black text-slate-950">Ambulance Details</h2>
        <div class="grid gap-x-5 gap-y-4 md:grid-cols-2 lg:grid-cols-4">
            <label><span class="gpha-label">Ambulance No. <span class="text-red-600">*</span></span><input name="fleet_number" class="gpha-input uppercase" value="{{ old('fleet_number', $ambulance?->fleet_number) }}" placeholder="E.g. AMBU 1" pattern="AMBU(?:LANCE)?[ -]?[0-9]{1,3}" title="Use AMBU followed by a number, for example AMBU 1" autocomplete="off" maxlength="30" required></label>
            <label><span class="gpha-label">Registration No. <span class="text-red-600">*</span></span><input name="registration_number" class="gpha-input uppercase" value="{{ old('registration_number', $ambulance?->registration_number) }}" placeholder="E.g. GV 1234-26" pattern="[A-Za-z]{1,3} [0-9]{1,4}-[0-9]{2}" title="Use the Ghana format GV 1234-26" autocomplete="off" maxlength="30" required><span class="gpha-help-text mt-1 block text-slate-500">Format: GV 1234-26</span></label>
            <label><span class="gpha-label">Make</span><input name="make" class="gpha-input" value="{{ old('make', $ambulance?->make) }}" placeholder="E.g. Mercedes-Benz" maxlength="80"></label>
            <label><span class="gpha-label">Model</span><input name="model" class="gpha-input" value="{{ old('model', $ambulance?->model) }}" placeholder="E.g. Sprinter" maxlength="80"></label>
            <label><span class="gpha-label">Year</span><input type="number" name="year" class="gpha-input" value="{{ old('year', $ambulance?->year) }}" min="1980" max="{{ now()->year }}" step="1" placeholder="{{ now()->year }}"><span class="gpha-help-text mt-1 block text-slate-500">1980–{{ now()->year }}</span></label>
            <label><span class="gpha-label">Base Location <span class="text-red-600">*</span></span><select name="base_location" class="gpha-input" required><option value="">Select base location</option>@foreach(config('ems.movement_locations') as $location)<option value="{{ $location }}" @selected(old('base_location',$ambulance?->base_location)===$location)>{{ $location }}</option>@endforeach</select></label>
            <label><span class="gpha-label">Current Odometer (km) <span class="text-red-600">*</span></span><input type="number" name="odometer_km" class="gpha-input" value="{{ old('odometer_km', $ambulance?->odometer_km ?? 0) }}" min="0" max="9999999" step="1" required></label>
            <label><span class="gpha-label">Roadworthy Expiry</span><input type="date" name="roadworthy_expires_at" class="gpha-input" value="{{ old('roadworthy_expires_at', $ambulance?->roadworthy_expires_at?->format('Y-m-d')) }}" @if(!$ambulance || !$ambulance->roadworthy_expires_at || !$ambulance->roadworthy_expires_at->isPast()) min="{{ today()->toDateString() }}" @endif></label>
            <label><span class="gpha-label">Insurance Expiry</span><input type="date" name="insurance_expires_at" class="gpha-input" value="{{ old('insurance_expires_at', $ambulance?->insurance_expires_at?->format('Y-m-d')) }}" @if(!$ambulance || !$ambulance->insurance_expires_at || !$ambulance->insurance_expires_at->isPast()) min="{{ today()->toDateString() }}" @endif></label>
            <label class="md:col-span-2 lg:col-span-3"><span class="gpha-label">Notes</span><input name="notes" class="gpha-input" maxlength="2000" value="{{ old('notes', $ambulance?->notes) }}" placeholder="Maintenance, equipment, or operational notes"></label>
        </div>
    </div>
    <div class="flex justify-end"><button class="gpha-button-primary">{{ $submitLabel }}</button></div>
</form>
