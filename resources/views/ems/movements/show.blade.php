<x-app-layout>
@php($canManage=app(\App\Application\Sso\PermissionService::class)->allows('DispatchAndMovement','Manage'))
<div class="gpha-page-shell space-y-5">
    @if(session('success'))<x-dismissible-alert>{{ session('success') }}</x-dismissible-alert>@endif
    <section class="gpha-top-pipe rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div><h1 class="text-[1.2rem] font-black text-slate-900">Movement {{ $dispatch->reference }}</h1><p class="mt-2 font-semibold text-slate-500">{{ $dispatch->ambulance->fleet_number }} · {{ $dispatch->origin }} to {{ $dispatch->destination }}</p></div>
            <div class="flex flex-wrap gap-2">@if($canManage&&!in_array($dispatch->status,['completed','cancelled'],true))<a href="{{ route('ems.dispatches.edit',$dispatch) }}" class="gpha-button-secondary">Edit</a>@endif<a href="{{ route('ems.dispatches') }}" class="gpha-button-primary">Back</a></div>
        </div>
        <div class="mt-6 rounded-xl border border-slate-200 bg-white p-4">
            <div class="grid gap-x-10 gap-y-5 md:grid-cols-3">
                <div class="space-y-5"><p><span class="font-black">Status:</span> <x-ems.status-badge :status="$dispatch->status" /></p><p><span class="font-black">Priority:</span> <x-ems.status-badge :status="$dispatch->priority" /></p><p><span class="font-black">Ambulance:</span> <a href="{{ route('ems.ambulances.show',$dispatch->ambulance) }}" class="font-bold text-gpha-primary hover:underline">{{ $dispatch->ambulance->fleet_number }}</a></p></div>
                <div class="space-y-5"><p><span class="font-black">Origin:</span> {{ $dispatch->origin }}</p><p><span class="font-black">Destination:</span> {{ $dispatch->destination }}</p><p><span class="font-black">Case Category:</span> {{ $dispatch->purpose }}</p></div>
                <div class="space-y-5"><p><span class="font-black">Requested:</span> {{ $dispatch->requested_at?->format('d/m/Y H:i') }}</p><p><span class="font-black">Completed:</span> {{ $dispatch->completed_at?->format('d/m/Y H:i') ?: '—' }}</p><p><span class="font-black">Crew Lead:</span> {{ $dispatch->crew_lead ?: 'Not set' }}</p><p><span class="font-black">Odometer:</span> {{ number_format($dispatch->odometer_start ?? 0) }} → {{ $dispatch->odometer_end!==null?number_format($dispatch->odometer_end):'—' }} km</p><p><span class="font-black">Distance:</span> {{ $dispatch->distance_km!==null?number_format($dispatch->distance_km).' km':'—' }}</p></div>
            </div>
            <div class="mt-5 border-t border-slate-200 pt-5"><p><span class="font-black">Operational Notes:</span> {{ $dispatch->notes ?: 'None' }}</p></div>
        </div>
        @if($canManage&&in_array($dispatch->status,['requested','dispatched','arrived'],true))<div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 p-4"><form method="POST" action="{{ route('ems.dispatches.complete',$dispatch) }}" class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-end">@csrf @method('PATCH')<label class="w-full sm:max-w-xs"><span class="gpha-label">Ending Odometer (km) <span class="text-red-600">*</span></span><input type="number" name="odometer_end" min="{{ $dispatch->odometer_start ?? $dispatch->ambulance->odometer_km }}" value="{{ $dispatch->ambulance->odometer_km }}" class="gpha-input" required></label><button class="rounded bg-emerald-600 px-4 py-2 font-extrabold text-white hover:bg-emerald-700">Mark Complete</button></form></div>@endif
    </section>
</div>
</x-app-layout>
