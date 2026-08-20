<x-app-layout>
@php
    $reportPermissions=app(\App\Application\Sso\PermissionService::class);
    $testingAccess=app()->environment('testing')&&!auth()->user()?->sso_user_id;
    $canManage=$testingAccess||$reportPermissions->allows('EMSReports','Manage');
    $canApprove=$testingAccess||$reportPermissions->allows('EMSReports','Approve');
@endphp
<div class="gpha-page-shell space-y-5">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div><p class="font-extrabold text-gpha-primary">Formal EMS Reporting</p><h1 class="text-3xl font-black text-slate-950">Reports</h1><p class="mt-1 font-semibold text-slate-500">Generate, sign, submit, approve, print, and retain formal reports.</p></div>
        <a href="{{ route('dashboard') }}" class="gpha-button-secondary">View Analytics Dashboard</a>
    </div>

    @if(session('success'))<x-dismissible-alert type="success">{{ session('success') }} @if(session('success_report_uuid'))<a href="{{ route('ems.reports.print',['report'=>session('success_report_uuid')]) }}" class="ml-2 inline-flex font-black underline underline-offset-2">View Report</a>@endif</x-dismissible-alert>@endif
    @if($errors->any())<x-dismissible-alert type="error">{{ $errors->first() }}</x-dismissible-alert>@endif

    @if($canManage)
    <section class="gpha-top-pipe rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div><h2 class="text-xl font-black text-slate-950">Generate Report</h2><p class="font-semibold text-slate-500">Create a draft report, review it, then sign and submit it for approval.</p></div>
            <form method="POST" action="{{ route('ems.reports.store') }}" class="grid w-full gap-3 md:grid-cols-2 xl:grid-cols-3 lg:max-w-5xl" x-data="{periodPreset:@js(old('period_preset','this_week'))}">@csrf
                <label><span class="gpha-label">Report Type <span class="text-red-600">*</span></span><select name="type" class="gpha-input" required><option value="mileage" @selected(old('type')==='mileage')>Ambulance Mileage Report</option><option value="weekly_activity" @selected(old('type')==='weekly_activity')>Operational Activities Report</option><option value="availability" @selected(old('type')==='availability')>Radio & Availability Report</option></select></label>
                <div><div class="flex items-center gap-2"><label for="report-period" class="gpha-label">Reporting Period <span class="text-red-600">*</span></label><x-ems.help-tooltip label="About reporting periods">“This” periods run up to today. “Last” periods use the most recently completed period. EMS weeks run from Sunday through Saturday. Choose Custom Dates for any other range.</x-ems.help-tooltip></div><select id="report-period" name="period_preset" x-model="periodPreset" class="gpha-input" required><option value="today">Today</option><option value="yesterday">Yesterday</option><option value="this_week">This Week</option><option value="last_week">Last Week</option><option value="this_month">This Month</option><option value="last_month">Last Month</option><option value="this_quarter">This Quarter</option><option value="last_quarter">Last Quarter</option><option value="last_six_months">Last 6 Months</option><option value="this_year">This Year</option><option value="last_year">Last Year</option><option value="custom">Custom Dates</option></select></div>
                <label x-show="periodPreset==='custom'"><span class="gpha-label">From Date <span class="text-red-600">*</span></span><input type="date" name="period_start" value="{{ old('period_start',$generationPeriod['period_start']) }}" :disabled="periodPreset!=='custom'" :required="periodPreset==='custom'" class="gpha-input"></label>
                <label x-show="periodPreset==='custom'"><span class="gpha-label">To Date <span class="text-red-600">*</span></span><input type="date" name="period_end" value="{{ old('period_end',$generationPeriod['period_end']) }}" :disabled="periodPreset!=='custom'" :required="periodPreset==='custom'" class="gpha-input"></label>
                <div class="flex items-end"><button class="gpha-button-primary w-full">Generate Report</button></div>
            </form>
        </div>
    </section>
    @endif

    @if($approverTabs)
    @php($tabFilters=request()->only(['report_type','report_date_from','report_date_to']))
    <nav class="flex flex-wrap gap-2 rounded-xl border border-slate-200 bg-white p-2 shadow-sm" aria-label="Report sections">
        @foreach(['all'=>'All Reports','submitted'=>'Submitted Reports','approved'=>'Approved Reports'] as $tab=>$label)
            <a href="{{ route('ems.reports',[...$tabFilters,'report_tab'=>$tab]) }}" @if($activeReportTab===$tab) aria-current="page" @endif class="rounded-lg px-4 py-3 font-black transition {{ $activeReportTab===$tab?'bg-gpha-primary text-white shadow-sm':'text-slate-600 hover:bg-slate-100 hover:text-slate-950' }}">{{ $label }}</a>
        @endforeach
    </nav>
    @endif

    <section class="gpha-panel p-5" x-data="{filtersOpen:false}">
        <x-ems.mobile-filter-toggle />
        <form method="GET" action="{{ route('ems.reports') }}" :class="filtersOpen ? '!grid' : 'hidden'" class="hidden gap-4 md:!grid md:grid-cols-2 {{ $approverTabs?'xl:grid-cols-4':'xl:grid-cols-5' }}">
            @if($approverTabs)<input type="hidden" name="report_tab" value="{{ $activeReportTab }}">@else<label><span class="gpha-label">Report Status</span><select name="report_status" class="gpha-input"><option value="">All statuses</option><option value="draft" @selected(($reportFilters['report_status']??'')==='draft')>Draft</option><option value="submitted" @selected(($reportFilters['report_status']??'')==='submitted')>Submitted / Awaiting Approval</option><option value="approved" @selected(($reportFilters['report_status']??'')==='approved')>Approved</option></select></label>@endif
            <label><span class="gpha-label">Report Type</span><select name="report_type" class="gpha-input"><option value="">All report types</option><option value="mileage" @selected(($reportFilters['report_type']??'')==='mileage')>Ambulance Mileage</option><option value="weekly_activity" @selected(($reportFilters['report_type']??'')==='weekly_activity')>Operational Activities</option><option value="availability" @selected(($reportFilters['report_type']??'')==='availability')>Radio & Availability</option></select></label>
            <label><span class="gpha-label">Report Date From</span><input type="date" name="report_date_from" value="{{ $reportFilters['report_date_from']??'' }}" class="gpha-input"></label>
            <label><span class="gpha-label">Report Date To</span><input type="date" name="report_date_to" value="{{ $reportFilters['report_date_to']??'' }}" class="gpha-input"></label>
            <div class="flex items-end gap-2 xl:justify-end"><a href="{{ route('ems.reports',$approverTabs?['report_tab'=>$activeReportTab]:[]) }}" class="gpha-button-secondary">Clear</a><button class="gpha-button-primary whitespace-nowrap">Apply Filters</button></div>
        </form>
    </section>

    @include('ems.reports._workflow')
</div>
</x-app-layout>
