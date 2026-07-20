<x-app-layout>
@php
    $reportPermissions=app(\App\Application\Sso\PermissionService::class);
    $canExport=$reportPermissions->allows('EMSReports','Export');
    $canManage=$reportPermissions->allows('EMSReports','Manage');
@endphp
<div class="gpha-page-shell space-y-5">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div><p class="font-extrabold text-gpha-primary">EMS Analytics</p><h1 class="text-3xl font-black text-slate-950">Operational Reports</h1><p class="mt-1 font-semibold text-slate-500">Movement, fleet utilisation, distance, and readiness performance.</p></div>
        @if($canExport)<a href="{{ route('ems.reports.operations.export',request()->query()) }}" class="gpha-button-primary">Export CSV</a>@endif
    </div>

    @if($errors->any())<x-dismissible-alert type="error">{{ $errors->first() }}</x-dismissible-alert>@endif

    <section class="gpha-panel p-5">
        <form method="GET" action="{{ route('ems.reports') }}" class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
            <label><span class="gpha-label">From Date <span class="text-red-600">*</span></span><input type="date" name="period_start" value="{{ $filters['period_start'] }}" class="gpha-input" required></label>
            <label><span class="gpha-label">To Date <span class="text-red-600">*</span></span><input type="date" name="period_end" value="{{ $filters['period_end'] }}" class="gpha-input" required></label>
            <label><span class="gpha-label">Ambulance</span><select name="ambulance_id" class="gpha-input"><option value="">All ambulances</option>@foreach($ambulances as $ambulance)<option value="{{ $ambulance->id }}" @selected((string)($filters['ambulance_id']??'')===(string)$ambulance->id)>{{ $ambulance->fleet_number }}</option>@endforeach</select></label>
            <label><span class="gpha-label">Movement Status</span><select name="status" class="gpha-input"><option value="">All statuses</option>@foreach(['requested','completed'] as $status)<option value="{{ $status }}" @selected(($filters['status']??'')===$status)>{{ str($status)->headline() }}</option>@endforeach</select></label>
            <div class="flex flex-wrap justify-end gap-2 md:col-span-2 lg:col-span-4"><a href="{{ route('ems.reports') }}" class="gpha-button-secondary">Clear</a><button class="gpha-button-primary whitespace-nowrap">Apply Filters</button></div>
        </form>
    </section>

    @if($canManage)
    <section class="gpha-top-pipe rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div><h2 class="text-xl font-black text-slate-950">Generate Printable Report</h2><p class="font-semibold text-slate-500">Create a frozen, print-ready report from the selected operational period.</p></div>
            <form method="POST" action="{{ route('ems.reports.store') }}" class="grid w-full gap-3 md:grid-cols-4 lg:max-w-5xl">@csrf
                <label><span class="gpha-label">Report Type <span class="text-red-600">*</span></span><select name="type" class="gpha-input" required><option value="mileage">Weekly Mileage Report</option><option value="weekly_activity">Weekly Report</option><option value="availability">EMS Weekly Availability Report</option></select></label>
                <label><span class="gpha-label">From Date <span class="text-red-600">*</span></span><input type="date" name="period_start" value="{{ $filters['period_start'] }}" class="gpha-input" required></label>
                <label><span class="gpha-label">To Date <span class="text-red-600">*</span></span><input type="date" name="period_end" value="{{ $filters['period_end'] }}" class="gpha-input" required></label>
                <div class="flex items-end"><button class="gpha-button-primary w-full">Generate Report</button></div>
            </form>
        </div>
    </section>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="gpha-metric"><p class="font-bold text-slate-500">Total Movements</p><p class="mt-2 text-3xl font-black text-slate-950">{{ number_format($totalMovements) }}</p><p class="font-bold text-slate-500">{{ number_format($criticalMovements) }} critical priority</p></div>
        <div class="gpha-metric"><p class="font-bold text-slate-500">Completed</p><p class="mt-2 text-3xl font-black text-emerald-700">{{ number_format($completedMovements) }}</p><p class="font-bold text-slate-500">{{ $completionRate }}% completion rate</p></div>
        <div class="gpha-metric"><p class="font-bold text-slate-500">Active Movements</p><p class="mt-2 text-3xl font-black text-gpha-primary">{{ number_format($activeMovements) }}</p><p class="font-bold text-slate-500">Currently in progress</p></div>
        <div class="gpha-metric"><p class="font-bold text-slate-500">Recorded Distance</p><p class="mt-2 text-3xl font-black text-slate-950">{{ number_format($totalDistance) }} km</p><p class="font-bold text-slate-500">From completed movements</p></div>
    </div>

    <div class="grid gap-5 xl:grid-cols-[1.5fr_1fr]">
        <section class="gpha-panel p-5">
            <h2 class="text-xl font-black text-slate-950">Movement Trend</h2><p class="font-semibold text-slate-500">Movements recorded per day in the selected period.</p>
            @if($dailyCounts->isNotEmpty())
                <div class="mt-5 overflow-x-auto"><div class="flex h-64 min-w-[680px] items-end gap-3 border-b border-slate-300 px-2 pt-4">@foreach($dailyCounts as $date=>$count)<div class="flex min-w-12 flex-1 flex-col items-center justify-end gap-2"><span class="font-black text-gpha-primary">{{ $count }}</span><div class="w-full max-w-14 rounded-t bg-gpha-primary" style="height:{{ max(8,round(($count/$maxDaily)*170)) }}px"></div><span class="gpha-chart-label whitespace-nowrap font-bold text-slate-500">{{ \Carbon\Carbon::parse($date)->format('d M') }}</span></div>@endforeach</div></div>
            @else<p class="mt-8 rounded-lg bg-slate-50 p-8 text-center text-slate-500">No movement data for this period.</p>@endif
        </section>
        <section class="gpha-panel p-5">
            <h2 class="text-xl font-black text-slate-950">Status Distribution</h2><p class="font-semibold text-slate-500">Current workflow outcomes for filtered movements.</p>
            <div class="mt-5 space-y-4">@foreach($statusCounts as $status=>$count)@php($percentage=$totalMovements?round(($count/$totalMovements)*100):0)<div><div class="mb-1 flex justify-between"><span class="font-bold">{{ str($status)->headline() }}</span><span class="font-black">{{ $count }} · {{ $percentage }}%</span></div><div class="h-3 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full {{ match($status){'completed'=>'bg-emerald-500','cancelled'=>'bg-red-500','arrived'=>'bg-violet-500','dispatched'=>'bg-blue-500',default=>'bg-slate-500'} }}" style="width:{{ $percentage }}%"></div></div></div>@endforeach</div>
            <div class="mt-6 grid grid-cols-2 gap-3 border-t border-slate-200 pt-4"><div><p class="font-bold text-slate-500">Availability Checks</p><p class="text-2xl font-black">{{ number_format($availabilityChecks) }}</p><p class="font-bold {{ $availabilityRate===null?'text-slate-500':($availabilityRate>=90?'text-emerald-700':'text-amber-700') }}">{{ $availabilityRate===null?'No checks':$availabilityRate.'% responded' }}</p></div><div><p class="font-bold text-slate-500">Recorded Activities</p><p class="text-2xl font-black">{{ number_format($activityCount) }}</p><p class="font-bold text-slate-500">Supporting records</p></div></div>
        </section>
    </div>

</div>
</x-app-layout>
