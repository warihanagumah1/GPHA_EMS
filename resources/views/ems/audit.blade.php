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
                    <thead><tr><th>Date</th><th>User</th><th>Branch</th><th>Action</th><th>Route</th><th>Result</th></tr></thead>
                    <tbody>
                    @forelse($logs as $log)
                        <tr><td>{{ $log->created_at?->format('d M Y H:i:s') }}</td><td>{{ $log->user?->name ?? 'System' }}</td><td>{{ $log->branch_code ?: '—' }}</td><td>{{ str($log->action)->headline() }}</td><td><span class="font-bold">{{ $log->route ?: '—' }}</span><p class="text-xs text-slate-500">{{ $log->method }} /{{ $log->path }}</p></td><td><span class="gpha-status {{ $log->response_status < 400 ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700' }}">{{ $log->response_status }}</span></td></tr>
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
