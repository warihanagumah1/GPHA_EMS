<x-app-layout>
@php($canManage=app(\App\Application\Sso\PermissionService::class)->allows('DispatchAndMovement','Manage'))
<div class="gpha-page-shell space-y-5">
    @if(session('success'))<x-dismissible-alert>{{ session('success') }}</x-dismissible-alert>@endif
    <section class="gpha-top-pipe rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div><h1 class="text-[1.2rem] font-black text-slate-900">Movement {{ $dispatch->reference }}</h1><p class="mt-2 font-semibold text-slate-500">{{ $dispatch->ambulance->fleet_number }} · {{ $dispatch->origin }} to {{ $dispatch->destination }}</p></div>
            <div class="flex flex-wrap gap-2">
                @if($canManage)
                    <a href="{{ route('ems.dispatches.edit',$dispatch) }}" class="gpha-button-secondary">Edit</a>
                    @if($dispatch->status==='requested')<form method="POST" action="{{ route('ems.dispatches.complete',$dispatch) }}" class="inline-flex" data-confirm-title="Complete Movement?" data-confirm-message="{{ $dispatch->reference }} will be completed and {{ $dispatch->ambulance->fleet_number }} will become available for another movement." data-confirm-label="Yes, Complete Movement" data-confirm-tone="success">@csrf @method('PATCH')<button class="gpha-button-success">Mark Complete</button></form>@endif
                    <form method="POST" action="{{ route('ems.dispatches.destroy',$dispatch) }}" class="inline-flex" data-confirm-title="Delete Movement?" data-confirm-message="{{ $dispatch->reference }} will be removed from operational lists. Its deletion details will remain preserved in the audit trail." data-confirm-label="Yes, Delete Movement" data-confirm-tone="danger">@csrf @method('DELETE')<button class="gpha-button-danger">Delete</button></form>
                @endif
                <a href="{{ route('ems.dispatches') }}" class="gpha-button-primary">Back</a>
            </div>
        </div>
        <div class="mt-6 rounded-xl border border-slate-200 bg-white p-4">
            <div class="grid gap-x-10 gap-y-5 md:grid-cols-3">
                <div class="space-y-5"><p><span class="font-black">Status:</span> <x-ems.status-badge :status="$dispatch->status" /></p><p><span class="font-black">Priority:</span> <x-ems.status-badge :status="$dispatch->priority" /></p><p><span class="font-black">Ambulance:</span> <a href="{{ route('ems.ambulances.show',$dispatch->ambulance) }}" class="font-bold text-gpha-primary hover:underline">{{ $dispatch->ambulance->fleet_number }}</a></p></div>
                <div class="space-y-5"><p><span class="font-black">Origin:</span> {{ $dispatch->origin }}</p><p><span class="font-black">Destination:</span> {{ $dispatch->destination }}</p><p><span class="font-black">Case Category:</span> {{ $dispatch->purpose }}</p></div>
                <div class="space-y-5"><p><span class="font-black">Requested:</span> {{ $dispatch->requested_at?->format('d/m/Y H:i') }}</p><p><span class="font-black">Completed:</span> {{ $dispatch->completed_at?->format('d/m/Y H:i') ?: '—' }}</p></div>
            </div>
            <div class="mt-5 border-t border-slate-200 pt-5"><p><span class="font-black">Operational Notes:</span> {{ $dispatch->notes ?: 'None' }}</p></div>
        </div>
    </section>
</div>
</x-app-layout>
