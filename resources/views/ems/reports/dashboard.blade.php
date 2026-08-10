<x-app-layout>
@php
    $reportPermissions=app(\App\Application\Sso\PermissionService::class);
    $canExport=$reportPermissions->allows('EMSReports','Export');
    $canManage=$reportPermissions->allows('EMSReports','Manage');
@endphp
<div class="gpha-page-shell space-y-5">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div><p class="font-extrabold text-gpha-primary">EMS Analytics</p><h1 class="text-3xl font-black text-slate-950">Operational Reports</h1><p class="mt-1 font-semibold text-slate-500">Movement, fleet utilisation, and readiness performance.</p></div>
        @if($canExport)<a href="{{ route('ems.reports.operations.export',request()->query()) }}" class="gpha-button-primary">Export CSV</a>@endif
    </div>

    @if($errors->any())<x-dismissible-alert type="error">{{ $errors->first() }}</x-dismissible-alert>@endif

    @if($canManage)
    <section class="gpha-top-pipe rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div><h2 class="text-xl font-black text-slate-950">Generate Printable Report</h2><p class="font-semibold text-slate-500">Create a frozen, print-ready report from the selected operational period.</p></div>
            <form method="POST" action="{{ route('ems.reports.store') }}" class="grid w-full gap-3 md:grid-cols-2 xl:grid-cols-3 lg:max-w-5xl" x-data="{periodPreset:@js(old('period_preset','this_week'))}">@csrf
                <label><span class="gpha-label">Report Type <span class="text-red-600">*</span></span><select name="type" class="gpha-input" required><option value="mileage" @selected(old('type')==='mileage')>Ambulance Mileage Report</option><option value="weekly_activity" @selected(old('type')==='weekly_activity')>Operational Activities Report</option><option value="availability" @selected(old('type')==='availability')>Radio & Availability Report</option></select></label>
                <div><div class="flex items-center gap-2"><label for="report-period" class="gpha-label">Reporting Period <span class="text-red-600">*</span></label><x-ems.help-tooltip label="About reporting periods">“This” periods run up to today. “Last” periods use the most recently completed period. EMS weeks run from Sunday through Saturday. Choose Custom Dates for any other range.</x-ems.help-tooltip></div><select id="report-period" name="period_preset" x-model="periodPreset" class="gpha-input" required><option value="today">Today</option><option value="yesterday">Yesterday</option><option value="this_week">This Week</option><option value="last_week">Last Week</option><option value="this_month">This Month</option><option value="last_month">Last Month</option><option value="this_quarter">This Quarter</option><option value="last_quarter">Last Quarter</option><option value="last_six_months">Last 6 Months</option><option value="this_year">This Year</option><option value="last_year">Last Year</option><option value="custom">Custom Dates</option></select></div>
                <label x-show="periodPreset==='custom'"><span class="gpha-label">From Date <span class="text-red-600">*</span></span><input type="date" name="period_start" value="{{ old('period_start',$filters['period_start']) }}" :disabled="periodPreset!=='custom'" :required="periodPreset==='custom'" class="gpha-input"></label>
                <label x-show="periodPreset==='custom'"><span class="gpha-label">To Date <span class="text-red-600">*</span></span><input type="date" name="period_end" value="{{ old('period_end',$filters['period_end']) }}" :disabled="periodPreset!=='custom'" :required="periodPreset==='custom'" class="gpha-input"></label>
                <div class="flex items-end"><button class="gpha-button-primary w-full">Generate Report</button></div>
            </form>
        </div>
    </section>
    @endif

    <section class="gpha-panel p-5" x-data="{filtersOpen:false,dashboardPeriod:@js($filters['period_preset'])}">
        <x-ems.mobile-filter-toggle />
        <form method="GET" action="{{ route('ems.reports') }}" :class="filtersOpen ? '!grid' : 'hidden'" class="hidden gap-4 md:!grid md:grid-cols-2 lg:grid-cols-4">
            <div><div class="flex items-center gap-2"><label for="dashboard-period" class="gpha-label">Reporting Period</label><x-ems.help-tooltip label="About dashboard reporting periods">“This” periods run up to today. “Last” periods use the most recently completed period. EMS weeks run from Sunday through Saturday. Choose Custom Dates for any other range.</x-ems.help-tooltip></div><select id="dashboard-period" name="period_preset" x-model="dashboardPeriod" class="gpha-input" required><option value="today">Today</option><option value="yesterday">Yesterday</option><option value="this_week">This Week</option><option value="last_week">Last Week</option><option value="this_month">This Month</option><option value="last_month">Last Month</option><option value="this_quarter">This Quarter</option><option value="last_quarter">Last Quarter</option><option value="last_six_months">Last 6 Months</option><option value="this_year">This Year</option><option value="last_year">Last Year</option><option value="custom">Custom Dates</option></select></div>
            <label x-show="dashboardPeriod==='custom'"><span class="gpha-label">From Date <span class="text-red-600">*</span></span><input type="date" name="period_start" value="{{ $filters['period_start'] }}" :disabled="dashboardPeriod!=='custom'" :required="dashboardPeriod==='custom'" class="gpha-input"></label>
            <label x-show="dashboardPeriod==='custom'"><span class="gpha-label">To Date <span class="text-red-600">*</span></span><input type="date" name="period_end" value="{{ $filters['period_end'] }}" :disabled="dashboardPeriod!=='custom'" :required="dashboardPeriod==='custom'" class="gpha-input"></label>
            <label><span class="gpha-label">Ambulance</span><select name="ambulance_id" class="gpha-input"><option value="">All ambulances</option>@foreach($ambulances as $ambulance)<option value="{{ $ambulance->id }}" @selected((string)($filters['ambulance_id']??'')===(string)$ambulance->id)>{{ $ambulance->fleet_number }}</option>@endforeach</select></label>
            <label><span class="gpha-label">Movement Status</span><select name="status" class="gpha-input"><option value="">All statuses</option>@foreach(['requested','completed'] as $status)<option value="{{ $status }}" @selected(($filters['status']??'')===$status)>{{ str($status)->headline() }}</option>@endforeach</select></label>
            <div class="flex items-end gap-2 lg:justify-end"><a href="{{ route('ems.reports') }}" class="gpha-button-secondary">Clear</a><button class="gpha-button-primary whitespace-nowrap">Apply Filters</button></div>
        </form>
    </section>

    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div><h2 class="text-2xl font-black text-slate-950">Management Analytics</h2><p class="font-semibold text-slate-500">A decision-ready view of movement, fleet, readiness, and activity performance.</p></div>
        <div class="flex flex-wrap items-center gap-3"><span data-snapshot-status class="font-bold text-slate-500" aria-live="polite"></span><button type="button" data-download-analytics-snapshot data-target="management-analytics-snapshot" data-filename="gpha-ems-management-analytics-{{ $filters['period_start'] }}-to-{{ $filters['period_end'] }}.png" class="gpha-button-primary"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M8 7 9.5 5h5L16 7h2a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2h2Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><circle cx="12" cy="13" r="3" stroke="currentColor" stroke-width="2"/></svg>Download Snapshot</button></div>
    </div>

    <div id="management-analytics-snapshot" class="space-y-5 rounded-xl bg-gpha-shell p-1 sm:p-2">
        <section class="flex flex-col gap-3 rounded-xl bg-gpha-primary px-5 py-4 text-white sm:flex-row sm:items-center sm:justify-between">
            <div><p class="text-sm font-black uppercase tracking-wider text-white/70">GPHA EMS</p><h2 class="text-2xl font-black">Management Analytics Snapshot</h2></div>
            <div class="sm:text-right"><p class="font-black text-gpha-secondary">{{ $filters['period_label'] }}</p><p class="font-semibold text-white/80">{{ \Carbon\Carbon::parse($filters['period_start'])->format('d M Y') }}, 00:00 – {{ \Carbon\Carbon::parse($filters['period_end'])->format('d M Y') }}, 23:59</p></div>
        </section>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="gpha-metric"><p class="font-bold text-slate-500">Total Movements</p><p class="mt-2 text-3xl font-black text-slate-950">{{ number_format($totalMovements) }}</p><p class="font-bold text-slate-500">All recorded dispatch activity</p></div>
            <div class="gpha-metric"><p class="font-bold text-slate-500">Completion Rate</p><p class="mt-2 text-3xl font-black text-emerald-700">{{ $completionRate }}%</p><p class="font-bold text-slate-500">{{ number_format($completedMovements) }} completed movements</p></div>
            <div class="gpha-metric"><p class="font-bold text-slate-500">Active Movements</p><p class="mt-2 text-3xl font-black text-gpha-primary">{{ number_format($activeMovements) }}</p><p class="font-bold text-slate-500">Currently in progress</p></div>
            <div class="gpha-metric"><p class="font-bold text-slate-500">Fleet Utilisation</p><p class="mt-2 text-3xl font-black text-slate-950">{{ $fleetUtilizationRate }}%</p><p class="font-bold text-slate-500">Ambulances Used: {{ number_format($ambulancesUsed) }} of {{ number_format($totalAmbulances) }}</p></div>
            <div class="gpha-metric"><p class="font-bold text-slate-500">Emergency Priority</p><p class="mt-2 text-3xl font-black text-red-600">{{ number_format($emergencyMovements) }}</p><p class="font-bold text-slate-500">{{ $emergencyRate }}% of all movements</p></div>
            <div class="gpha-metric"><p class="font-bold text-slate-500">Availability Response</p><p class="mt-2 text-3xl font-black {{ $availabilityRate===null?'text-slate-950':($availabilityRate>=90?'text-emerald-700':'text-amber-700') }}">{{ $availabilityRate===null?'—':$availabilityRate.'%' }}</p><p class="font-bold text-slate-500">{{ number_format($availabilityResponded) }} of {{ number_format($availabilityChecks) }} unit checks responded</p></div>
            <div class="gpha-metric"><p class="font-bold text-slate-500">Recorded Activities</p><p class="mt-2 text-3xl font-black text-violet-700">{{ number_format($activityCount) }}</p><p class="font-bold text-slate-500">Operational and management records</p></div>
            <div class="gpha-metric"><p class="font-bold text-slate-500">Open Follow-ups</p><p class="mt-2 text-3xl font-black {{ $openFollowUps?'text-amber-700':'text-emerald-700' }}">{{ number_format($openFollowUps) }}</p><p class="font-bold text-slate-500">Activities requiring action</p></div>
        </div>

        <div class="grid gap-5 xl:grid-cols-2">
            <section class="gpha-panel p-5 xl:col-span-2">
                <h2 class="text-xl font-black text-slate-950">Movement Trend</h2><p class="font-semibold text-slate-500">Movements recorded per day in the selected period.</p>
                @if($dailyCounts->isNotEmpty())
                    <div class="mt-5 overflow-x-auto"><div class="flex h-64 min-w-[680px] items-end gap-3 border-b border-slate-300 px-2 pt-4">@foreach($dailyCounts as $date=>$count)<div class="flex min-w-12 flex-1 flex-col items-center justify-end gap-2"><span class="font-black text-gpha-primary">{{ $count }}</span><div class="w-full max-w-14 rounded-t bg-gpha-primary" style="height:{{ max(8,round(($count/$maxDaily)*170)) }}px"></div><span class="gpha-chart-label whitespace-nowrap font-bold text-slate-500">{{ \Carbon\Carbon::parse($date)->format('d M') }}</span></div>@endforeach</div></div>
                @else<p class="mt-8 rounded-lg bg-slate-50 p-8 text-center text-slate-500">No movement data for this period.</p>@endif
            </section>

            <section class="gpha-panel p-5">
                <h2 class="text-xl font-black text-slate-950">Status Distribution</h2><p class="font-semibold text-slate-500">Current workflow outcomes for filtered movements.</p>
                <div class="mt-5 space-y-4">@foreach($statusCounts as $status=>$count)@php($percentage=$totalMovements?round(($count/$totalMovements)*100):0)<div><div class="mb-1 flex justify-between"><span class="font-bold">{{ str($status)->headline() }}</span><span class="font-black">{{ $count }} · {{ $percentage }}%</span></div><div class="h-3 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full {{ $status==='completed'?'bg-emerald-500':'bg-blue-500' }}" style="width:{{ $percentage }}%"></div></div></div>@endforeach</div>
            </section>

            <section class="gpha-panel p-5">
                <h2 class="text-xl font-black text-slate-950">Fleet Movement Load</h2><p class="font-semibold text-slate-500">Movement volume handled by each ambulance.</p>
                @if($ambulanceMovementCounts->sum()>0)<div class="mt-5 space-y-4">@foreach($ambulanceMovementCounts as $ambulance=>$count)<div><div class="mb-1 flex justify-between gap-3"><span class="font-bold">{{ $ambulance }}</span><span class="font-black">{{ number_format($count) }}</span></div><div class="h-3 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-cyan-600" style="width:{{ round(($count/$maxAmbulanceMovements)*100) }}%"></div></div></div>@endforeach</div>@else<p class="mt-8 rounded-lg bg-slate-50 p-8 text-center text-slate-500">No fleet movement load for this period.</p>@endif
            </section>

            <section class="gpha-panel p-5">
                <h2 class="text-xl font-black text-slate-950">Priority Mix</h2><p class="font-semibold text-slate-500">Operational demand by movement priority.</p>
                <div class="mt-5 space-y-4">@foreach($priorityCounts as $priority=>$count)@php($percentage=$totalMovements?round(($count/$totalMovements)*100):0)<div><div class="mb-1 flex justify-between gap-3"><span class="font-bold">{{ config("ems.movement_priorities.{$priority}",str($priority)->headline()) }}</span><span class="font-black">{{ $count }} · {{ $percentage }}%</span></div><div class="h-3 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full {{ match($priority){'emergency'=>'bg-red-500','non_emergency'=>'bg-amber-500',default=>'bg-blue-500'} }}" style="width:{{ $percentage }}%"></div></div></div>@endforeach</div>
            </section>

            <section class="gpha-panel p-5">
                <h2 class="text-xl font-black text-slate-950">Readiness Performance</h2><p class="font-semibold text-slate-500">Response results from radio and availability checks.</p>
                <div class="mt-5 space-y-4">@foreach($availabilityStatusCounts as $status=>$count)@php($percentage=$availabilityChecks?round(($count/$availabilityChecks)*100):0)<div><div class="mb-1 flex justify-between gap-3"><span class="font-bold">{{ $status==='responded'?'Responded':'No Response' }}</span><span class="font-black">{{ $count }} · {{ $percentage }}%</span></div><div class="h-4 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full {{ $status==='responded'?'bg-emerald-500':'bg-red-500' }}" style="width:{{ $percentage }}%"></div></div></div>@endforeach</div>
                @if(!$availabilityChecks)<p class="mt-5 rounded-lg bg-slate-50 p-5 text-center text-slate-500">No availability checks for this period.</p>@endif
            </section>

            <section class="gpha-panel p-5 xl:col-span-2">
                <h2 class="text-xl font-black text-slate-950">Activity Mix</h2><p class="font-semibold text-slate-500">Departmental activities grouped by management category.</p>
                @if($activityCategoryCounts->sum()>0)<div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">@foreach($activityCategoryCounts as $category=>$count)<div class="rounded-lg border border-slate-200 bg-slate-50 p-4"><div class="mb-2 flex justify-between gap-3"><span class="font-bold">{{ str($category)->headline() }}</span><span class="font-black {{ match($category){'operations'=>'text-blue-700','meeting'=>'text-amber-700','training'=>'text-emerald-700','inspection'=>'text-cyan-700','administration'=>'text-violet-700',default=>'text-rose-700'} }}">{{ number_format($count) }}</span></div><div class="h-3 overflow-hidden rounded-full bg-white"><div class="h-full rounded-full {{ match($category){'operations'=>'bg-blue-600','meeting'=>'bg-amber-500','training'=>'bg-emerald-600','inspection'=>'bg-cyan-600','administration'=>'bg-violet-600',default=>'bg-rose-600'} }}" style="width:{{ round(($count/$maxActivityCategoryCount)*100) }}%"></div></div></div>@endforeach</div>@else<p class="mt-8 rounded-lg bg-slate-50 p-8 text-center text-slate-500">No operational activities for this period.</p>@endif
            </section>
        </div>
    </div>

</div>
</x-app-layout>
