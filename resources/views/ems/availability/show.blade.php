<x-app-layout>
@php
    $first=$checks->first();
    $responded=$checks->where('responded',true)->count();
    $canManage=app(\App\Application\Sso\PermissionService::class)->allows('ReadinessAndActivities','Manage');
@endphp
<div class="gpha-page-shell space-y-6">
    @if(session('success'))<x-dismissible-alert>{{ session('success') }}</x-dismissible-alert>@endif
    <section class="gpha-top-pipe rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div><p class="font-extrabold text-gpha-primary">Operations Logs</p><h1 class="text-3xl font-black text-slate-950">Check Session</h1><p class="mt-2 font-semibold text-slate-500">{{ $first->check_date->format('d M Y') }} · {{ str($first->period)->headline() }} · {{ $first->checked_at?substr($first->checked_at,0,5).' hrs':'Time not recorded' }}</p></div>
            <div class="flex flex-wrap gap-2">@if($canManage)<a href="{{ route('ems.availability.sessions.edit',$session) }}" class="gpha-button-secondary">Edit</a><form method="POST" action="{{ route('ems.availability.sessions.destroy',$session) }}" class="inline-flex" data-confirm-title="Delete Check Session?" data-confirm-message="This entire availability check session will be removed from operational lists but retained in the audit trail." data-confirm-label="Yes, Delete Session" data-confirm-tone="danger">@csrf @method('DELETE')<button class="gpha-button-danger">Delete</button></form>@endif<a href="{{ route('ems.availability') }}" class="gpha-button-primary">Back</a></div>
        </div>
        <div class="mt-6 grid gap-4 sm:grid-cols-3"><div class="rounded-xl bg-slate-50 p-4"><p class="font-bold text-slate-500">Units Checked</p><p class="text-3xl font-black">{{ $checks->count() }}</p></div><div class="rounded-xl bg-emerald-50 p-4"><p class="font-bold text-emerald-700">Responded</p><p class="text-3xl font-black text-emerald-800">{{ $responded }}</p></div><div class="rounded-xl bg-red-50 p-4"><p class="font-bold text-red-700">No Response</p><p class="text-3xl font-black text-red-800">{{ $checks->count()-$responded }}</p></div></div>
    </section>
    <section class="gpha-panel overflow-hidden"><div class="border-b border-slate-200 px-5 py-4"><h2 class="text-xl font-black">Unit Responses</h2></div><div class="overflow-x-auto"><table class="gpha-table"><thead><tr><th>Department / Unit</th><th>Response</th><th>Response Location</th><th>Observation</th></tr></thead><tbody>@foreach($checks as $check)<tr><td class="font-extrabold">{{ $check->unit_name }}</td><td><x-ems.status-badge :status="$check->responded?'responded':'negative'" /></td><td>{{ $check->response_location?:'—' }}</td><td>{{ $check->observation?:'—' }}</td></tr>@endforeach</tbody></table></div></section>
</div>
</x-app-layout>
