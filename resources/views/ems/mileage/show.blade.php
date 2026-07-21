<x-app-layout>
@php($canManage=app(\App\Application\Sso\PermissionService::class)->allows('AmbulanceFleet','Manage'))
<div class="gpha-page-shell space-y-6">
    @if(session('success'))<x-dismissible-alert>{{ session('success') }}</x-dismissible-alert>@endif
    <section class="gpha-top-pipe rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div><p class="font-extrabold text-gpha-primary">Operations Logs</p><h1 class="text-3xl font-black text-slate-950">Mileage Reading</h1><p class="mt-2 font-semibold text-slate-500">{{ $reading->ambulance->fleet_number }} · {{ $reading->reading_date->format('d M Y') }}</p></div>
            <div class="flex flex-wrap gap-2">
                @if($canManage)
                    <a href="{{ route('ems.mileage.edit',$reading) }}" class="gpha-button-secondary">Edit</a>
                    <form method="POST" action="{{ route('ems.mileage.destroy',$reading) }}" class="inline-flex" data-confirm-title="Delete Mileage Reading?" data-confirm-message="The {{ number_format($reading->odometer_km) }} km reading for {{ $reading->ambulance->fleet_number }} on {{ $reading->reading_date->format('d M Y') }} will be removed from mileage reports but retained in the audit trail." data-confirm-label="Yes, Delete Reading" data-confirm-tone="danger">@csrf @method('DELETE')<button class="gpha-button-danger">Delete</button></form>
                @endif
                <a href="{{ route('ems.mileage') }}" class="gpha-button-primary">Back</a>
            </div>
        </div>
        <div class="mt-6 grid gap-5 rounded-xl border border-slate-200 p-5 md:grid-cols-2 lg:grid-cols-3">
            <p><span class="font-black">Ambulance:</span><br><a href="{{ route('ems.ambulances.show',$reading->ambulance) }}" class="font-bold text-gpha-primary hover:underline">{{ $reading->ambulance->fleet_number }}</a></p>
            <p><span class="font-black">Reading Date:</span><br>{{ $reading->reading_date->format('d M Y') }}</p>
            <p><span class="font-black">Odometer:</span><br>{{ number_format($reading->odometer_km) }} km</p>
            <p><span class="font-black">Reading Type:</span><br>{{ str($reading->source)->headline() }}</p>
            <p><span class="font-black">Recorded By:</span><br>{{ $reading->recordedBy?->name ?: 'Not recorded' }}</p>
            <p><span class="font-black">Recorded At:</span><br>{{ $reading->created_at?->format('d M Y H:i') }}</p>
            <p class="md:col-span-2 lg:col-span-3"><span class="font-black">Notes:</span><br>{{ $reading->notes ?: 'None' }}</p>
        </div>
    </section>
</div>
</x-app-layout>
