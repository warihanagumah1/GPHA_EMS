<x-app-layout>
    <div class="gpha-page-shell space-y-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-sm font-extrabold uppercase tracking-wider text-gpha-primary">Governance</p>
                <h1 class="text-3xl font-black tracking-tight text-slate-950">EMS activity & audit</h1>
                <p class="mt-1 text-slate-500">Trace operational changes made within the active branch.</p>
            </div>
            @if(app(\App\Application\Sso\PermissionService::class)->allows('EMSActivityAndAudit','Export'))
                <a href="{{ route('ems.audit.export') }}" class="gpha-button-primary">Export audit CSV</a>
            @endif
        </div>
        <section class="gpha-panel overflow-hidden">
            <div class="overflow-x-auto">
                <table class="gpha-table">
                    <thead><tr><th>Date</th><th>User / Branch</th><th>Action</th><th>Record</th><th>Changes</th><th>Result</th></tr></thead>
                    <tbody>
                    @forelse($logs as $log)
                        @php($changes=collect($log->new_values??[]))
                        <tr><td class="whitespace-nowrap">{{ $log->created_at?->format('d M Y H:i:s') }}</td><td><b>{{ $log->user?->name ?? 'System' }}</b><p class="text-xs text-slate-500">{{ $log->branch_code ?: 'No branch' }}</p></td><td><span class="font-extrabold {{ $log->action==='movement.deleted'?'text-red-600':'text-gpha-primary' }}">{{ str($log->action)->replace('.',' ')->headline() }}</span><p class="text-xs text-slate-500">{{ $log->route ?: '—' }}</p></td><td>@if($log->subject_reference)<b>{{ $log->subject_reference }}</b><p class="text-xs text-slate-500">{{ str($log->subject_type)->headline() }} #{{ $log->subject_id }}</p>@else<span class="text-slate-400">Request-level event</span>@endif</td><td class="min-w-72">@if($log->action==='movement.deleted')<span class="font-bold text-red-600">Soft deleted; previous values preserved.</span>@elseif($changes->isNotEmpty())<div class="space-y-1">@foreach($changes as $field=>$value)<p><b>{{ str($field)->replace('_',' ')->headline() }}:</b> @if(array_key_exists($field,$log->old_values??[]))<span class="text-slate-500">{{ is_scalar(($log->old_values??[])[$field])?($log->old_values??[])[$field]:'—' }} →</span> @endif{{ is_scalar($value)?$value:'—' }}</p>@endforeach</div>@else<span class="text-slate-400">{{ $log->method }} /{{ $log->path }}</span>@endif</td><td><span class="gpha-status {{ $log->response_status < 400 ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700' }}">{{ $log->response_status }}</span></td></tr>
                    @empty
                        <tr><td colspan="6" class="py-12 text-center text-slate-500">No recorded changes for this branch yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-200 px-5 py-4">{{ $logs->links() }}</div>
        </section>
    </div>
</x-app-layout>
