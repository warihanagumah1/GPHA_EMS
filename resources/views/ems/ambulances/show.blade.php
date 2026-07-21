<x-app-layout>
@php($canManage = app(\App\Application\Sso\PermissionService::class)->allows('AmbulanceFleet', 'Manage'))
<div class="gpha-page-shell space-y-5">
    @if(session('success'))<x-dismissible-alert>{{ session('success') }}</x-dismissible-alert>@endif
    @if($errors->any())<x-dismissible-alert type="error">{{ $errors->first() }}</x-dismissible-alert>@endif

    <section class="gpha-top-pipe rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div><h1 class="text-[1.2rem] font-black text-slate-900">Ambulance {{ $ambulance->fleet_number }}</h1><p class="mt-2 font-semibold text-slate-500">{{ $ambulance->registration_number }} · {{ $ambulance->current_location ?: $ambulance->base_location }}</p></div>
            <div class="flex flex-wrap gap-2">
                @if($canManage)<a href="{{ route('ems.ambulances.edit', $ambulance) }}" class="gpha-button-secondary">Edit</a>@endif
                <a href="{{ route('ems.ambulances') }}" class="gpha-button-primary">Back</a>
            </div>
        </div>

        <div class="mt-6 rounded-xl border border-slate-200 bg-white p-4">
            <div class="grid gap-x-10 gap-y-5 md:grid-cols-3">
                <div class="space-y-5">
                    <p class="text-slate-950"><span class="font-black">Status:</span> <x-ems.status-badge :status="$ambulance->status" /></p>
                    <p class="text-slate-950"><span class="font-black">Registration:</span> {{ $ambulance->registration_number }}</p>
                    <p class="text-slate-950"><span class="font-black">Make / Model:</span> {{ trim($ambulance->make.' '.$ambulance->model) ?: 'Not set' }}</p>
                    <p class="text-slate-950"><span class="font-black">Year:</span> {{ $ambulance->year ?: 'Not set' }}</p>
                </div>
                <div class="space-y-5">
                    <p class="text-slate-950"><span class="font-black">Base Location:</span> {{ $ambulance->base_location }}</p>
                    <p class="text-slate-950"><span class="font-black">Current Location:</span> {{ $ambulance->current_location ?: $ambulance->base_location }}</p>
                    <p class="text-slate-950"><span class="font-black">Odometer:</span> {{ number_format($ambulance->odometer_km) }} km</p>
                    <p class="text-slate-950"><span class="font-black">Next Service:</span> {{ $ambulance->next_service_km ? number_format($ambulance->next_service_km).' km' : 'Not set' }}</p>
                </div>
                <div class="space-y-5">
                    <p class="text-slate-950"><span class="font-black">Movements:</span> {{ number_format($ambulance->dispatches_count) }}</p>
                    <p class="text-slate-950"><span class="font-black">Roadworthy Expiry:</span> {{ $ambulance->roadworthy_expires_at?->format('d/m/Y') ?: 'Not set' }}</p>
                    <p class="text-slate-950"><span class="font-black">Insurance Expiry:</span> {{ $ambulance->insurance_expires_at?->format('d/m/Y') ?: 'Not set' }}</p>
                    <p class="text-slate-950"><span class="font-black">Notes:</span> {{ $ambulance->notes ?: 'None' }}</p>
                </div>
            </div>
        </div>

        @if($canManage && $ambulance->status !== 'dispatched')
            <div class="mt-4 flex justify-end">
                <form method="POST" action="{{ route('ems.ambulances.status', $ambulance) }}" class="inline-flex" data-confirm-title="Change Ambulance Status?" data-confirm-message="{{ $ambulance->fleet_number }} will be marked {{ $ambulance->status === 'available' ? 'unavailable' : 'available' }}." data-confirm-label="Yes, Mark {{ $ambulance->status === 'available' ? 'Unavailable' : 'Available' }}" data-confirm-tone="{{ $ambulance->status === 'available' ? 'danger' : 'success' }}">
                    @csrf @method('PATCH')
                    <input type="hidden" name="status" value="{{ $ambulance->status === 'available' ? 'unavailable' : 'available' }}">
                    <button class="{{ $ambulance->status === 'available' ? 'gpha-button-danger' : 'gpha-button-success' }}">{{ $ambulance->status === 'available' ? 'Mark Unavailable' : 'Mark Available' }}</button>
                </form>
            </div>
        @endif
    </section>

    <section class="gpha-top-pipe rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <div><h2 class="text-[1.2rem] font-black text-slate-900">Movement History</h2><p class="mt-1 font-semibold text-slate-500">Completed, active, and cancelled movements carried out by this ambulance.</p></div>

        <form method="GET" action="{{ route('ems.ambulances.show', $ambulance) }}" class="mt-4 grid gap-3 lg:grid-cols-5">
            <label><span class="gpha-label">Status</span><select name="status" class="gpha-input"><option value="">All statuses</option>@foreach(['requested','completed'] as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ str($status)->headline() }}</option>@endforeach</select></label>
            <label class="lg:col-span-2"><span class="gpha-label">Search</span><input name="search" value="{{ request('search') }}" class="gpha-input" placeholder="Reference, location, purpose, or crew lead"></label>
            <label><span class="gpha-label">From Date</span><input name="date_from" value="{{ request('date_from') }}" type="date" class="gpha-input"></label>
            <label><span class="gpha-label">To Date</span><input name="date_to" value="{{ request('date_to') }}" type="date" class="gpha-input"></label>
            <div class="flex flex-wrap gap-2 lg:col-span-5 lg:justify-end"><a href="{{ route('ems.ambulances.show', $ambulance) }}" class="gpha-button-secondary">Clear Filters</a><button class="gpha-button-primary">Apply Filters</button></div>
        </form>

        <div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="gpha-table">
                <thead><tr><th>Reference</th><th>Date</th><th>Route</th><th>Purpose</th><th>Crew Lead</th><th>Distance</th><th>Status</th></tr></thead>
                <tbody>
                @forelse($movements as $movement)
                    <tr><td class="font-extrabold text-gpha-primary">{{ $movement->reference }}</td><td>{{ $movement->requested_at?->format('d/m/Y H:i') }}</td><td><span class="font-bold">{{ $movement->origin }}</span><br><span class="text-slate-500">to {{ $movement->destination }}</span></td><td>{{ $movement->purpose }}</td><td>{{ $movement->crew_lead ?: 'Not set' }}</td><td>{{ $movement->distance_km !== null ? number_format($movement->distance_km).' km' : '—' }}</td><td><x-ems.status-badge :status="$movement->status" /></td></tr>
                @empty
                    <tr><td colspan="7" class="py-10 text-center text-slate-500">No movements found for the selected filters.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $movements->links() }}</div>
    </section>
</div>
</x-app-layout>
