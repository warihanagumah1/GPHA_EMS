<x-app-layout>
<div class="gpha-page-shell space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"><div><p class="font-extrabold text-gpha-primary">Operations Logs</p><h1 class="text-3xl font-black text-slate-950">Edit Mileage Reading</h1><p class="mt-1 font-semibold text-slate-500">Update the reading without breaking the ambulance’s odometer sequence.</p></div><a href="{{ route('ems.mileage.show',$reading) }}" class="gpha-button-primary">Close</a></div>
    @if($errors->any())<x-dismissible-alert type="error"><p class="font-extrabold">Please correct the following:</p><ul class="mt-1 list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-dismissible-alert>@endif
    <section class="gpha-panel p-5">
        <form method="POST" action="{{ route('ems.mileage.update',$reading) }}" class="space-y-5">@csrf @method('PUT')
            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                <label><span class="gpha-label">Ambulance <span class="text-red-600">*</span></span><select name="ambulance_id" class="gpha-input" required>@foreach($ambulances as $ambulance)<option value="{{ $ambulance->id }}" @selected(old('ambulance_id',$reading->ambulance_id)==$ambulance->id)>{{ $ambulance->fleet_number }}</option>@endforeach</select></label>
                <label><span class="gpha-label">Reading Date <span class="text-red-600">*</span></span><input type="date" name="reading_date" value="{{ old('reading_date',$reading->reading_date->toDateString()) }}" max="{{ today()->toDateString() }}" class="gpha-input" required></label>
                <label><span class="gpha-label">Odometer (km) <span class="text-red-600">*</span></span><input type="number" name="odometer_km" value="{{ old('odometer_km',$reading->odometer_km) }}" min="0" max="9999999" class="gpha-input" required></label>
                <label><span class="gpha-label">Reading Type <span class="text-red-600">*</span></span><select name="source" class="gpha-input" required><option value="weekly" @selected(old('source',$reading->source)==='weekly')>Scheduled weekly reading</option><option value="service" @selected(old('source',$reading->source)==='service')>Service reading</option></select></label>
                <label class="md:col-span-2 lg:col-span-4"><span class="gpha-label">Notes</span><textarea name="notes" rows="2" maxlength="1000" class="gpha-input" placeholder="Reason for unusual mileage, servicing, or correction">{{ old('notes',$reading->notes) }}</textarea></label>
            </div>
            <div class="flex flex-wrap justify-end gap-2"><a href="{{ route('ems.mileage.show',$reading) }}" class="gpha-button-secondary">Cancel</a><button class="gpha-button-primary">Save Changes</button></div>
        </form>
    </section>
</div>
</x-app-layout>
