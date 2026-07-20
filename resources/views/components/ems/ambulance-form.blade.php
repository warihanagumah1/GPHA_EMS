@props(['ambulance' => null, 'action', 'method' => 'POST', 'submitLabel' => 'Save Ambulance'])

<form method="POST" action="{{ $action }}" class="space-y-5">
    @csrf
    @if(strtoupper($method) !== 'POST') @method($method) @endif
    <div class="rounded-lg border border-slate-200 bg-slate-50/60 p-5">
        <h2 class="mb-5 text-xl font-black text-slate-950">Ambulance Details</h2>
        <div class="grid gap-x-5 gap-y-4 md:grid-cols-2 lg:grid-cols-4">
            <label><span class="gpha-label">Ambulance No. <span class="text-red-600">*</span></span><input name="fleet_number" class="gpha-input" value="{{ old('fleet_number', $ambulance?->fleet_number) }}" placeholder="E.g. AMBU 1" autocomplete="off" required></label>
            <label><span class="gpha-label">Registration No. <span class="text-red-600">*</span></span><input name="registration_number" class="gpha-input" value="{{ old('registration_number', $ambulance?->registration_number) }}" placeholder="E.g. GV 1234-26" autocomplete="off" required></label>
            <label><span class="gpha-label">Make</span><input name="make" class="gpha-input" value="{{ old('make', $ambulance?->make) }}" placeholder="E.g. Mercedes-Benz"></label>
            <label><span class="gpha-label">Model</span><input name="model" class="gpha-input" value="{{ old('model', $ambulance?->model) }}" placeholder="E.g. Sprinter"></label>
            <label><span class="gpha-label">Year</span><input type="number" name="year" class="gpha-input" value="{{ old('year', $ambulance?->year) }}" min="1990" max="{{ now()->year + 1 }}" placeholder="{{ now()->year }}"></label>
            <label><span class="gpha-label">Base Location <span class="text-red-600">*</span></span><input name="base_location" class="gpha-input" value="{{ old('base_location', $ambulance?->base_location) }}" placeholder="E.g. Main Clinic" required></label>
            <label><span class="gpha-label">Current Odometer (km) <span class="text-red-600">*</span></span><input type="number" name="odometer_km" class="gpha-input" value="{{ old('odometer_km', $ambulance?->odometer_km ?? 0) }}" min="0" max="9999999" required></label>
            <label><span class="gpha-label">Roadworthy Expiry</span><input type="date" name="roadworthy_expires_at" class="gpha-input" value="{{ old('roadworthy_expires_at', $ambulance?->roadworthy_expires_at?->format('Y-m-d')) }}"></label>
            <label><span class="gpha-label">Insurance Expiry</span><input type="date" name="insurance_expires_at" class="gpha-input" value="{{ old('insurance_expires_at', $ambulance?->insurance_expires_at?->format('Y-m-d')) }}"></label>
            <label class="md:col-span-2 lg:col-span-3"><span class="gpha-label">Notes</span><input name="notes" class="gpha-input" maxlength="2000" value="{{ old('notes', $ambulance?->notes) }}" placeholder="Maintenance, equipment, or operational notes"></label>
        </div>
    </div>
    <div class="flex justify-end"><button class="gpha-button-primary">{{ $submitLabel }}</button></div>
</form>
