<x-app-layout>
@php($first=$checks->first())
<div class="gpha-page-shell space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"><div><p class="font-extrabold text-gpha-primary">Operations Logs</p><h1 class="text-3xl font-black text-slate-950">Edit Check Session</h1><p class="mt-1 font-semibold text-slate-500">Update the session and its unit responses.</p></div><a href="{{ route('ems.availability.sessions.show',$session) }}" class="gpha-button-primary">Close</a></div>
    @if($errors->any())<x-dismissible-alert type="error"><p class="font-extrabold">Please correct the following:</p><ul class="mt-1 list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-dismissible-alert>@endif
    <section class="gpha-panel p-5"><form method="POST" action="{{ route('ems.availability.sessions.update',$session) }}" class="space-y-5">@csrf @method('PUT')
        <div class="grid gap-4 md:grid-cols-3">
            <label><span class="gpha-label">Check Date <span class="text-red-600">*</span></span><input type="date" name="check_date" value="{{ old('check_date',$first->check_date->toDateString()) }}" max="{{ today()->toDateString() }}" class="gpha-input" required></label>
            <label><span class="gpha-label">Session <span class="text-red-600">*</span></span><select name="period" class="gpha-input" required><option value="morning" @selected(old('period',$first->period)==='morning')>Morning</option><option value="afternoon" @selected(old('period',$first->period)==='afternoon')>Afternoon</option></select></label>
            <label><span class="gpha-label">Check Time <span class="text-red-600">*</span></span><input type="time" name="checked_at" value="{{ old('checked_at',substr($first->checked_at,0,5)) }}" class="gpha-input" required></label>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3"><p class="font-semibold text-blue-900">Set every unit response to Responded.</p><button type="button" data-mark-all-responded class="gpha-button-secondary border-blue-300 bg-white">Mark All Responded</button></div>
        <div class="overflow-x-auto rounded-xl border border-slate-200"><table class="gpha-table"><thead><tr><th>Department / Unit</th><th>Response <span class="text-red-600">*</span></th><th>Response Location</th><th>Observation</th></tr></thead><tbody>@foreach($checks as $index=>$check)<tr>
            <td class="font-extrabold">{{ $check->unit_name }}<input type="hidden" name="checks[{{ $index }}][id]" value="{{ $check->id }}"></td>
            <td><select name="checks[{{ $index }}][responded]" data-response class="gpha-input min-w-40" required><option value="1" @selected((string)old("checks.$index.responded",(int)$check->responded)==='1')>Responded</option><option value="0" @selected((string)old("checks.$index.responded",(int)$check->responded)==='0')>No response</option></select></td>
            <td><select name="checks[{{ $index }}][response_location]" class="gpha-input min-w-52"><option value="">Not stated</option>@foreach(config('ems.movement_locations') as $location)<option value="{{ $location }}" @selected(old("checks.$index.response_location",$check->response_location)===$location)>{{ $location }}</option>@endforeach</select></td>
            <td><input name="checks[{{ $index }}][observation]" value="{{ old("checks.$index.observation",$check->observation) }}" class="gpha-input min-w-60" maxlength="1000" placeholder="Fault, reason, or note"></td>
        </tr>@endforeach</tbody></table></div>
        <div class="flex justify-end gap-2"><a href="{{ route('ems.availability.sessions.show',$session) }}" class="gpha-button-secondary">Cancel</a><button class="gpha-button-primary">Save Changes</button></div>
    </form></section>
</div>
</x-app-layout>
