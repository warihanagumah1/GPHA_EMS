<x-app-layout>
<div class="gpha-page-shell space-y-5">
    <div class="flex justify-end">
        <div class="flex flex-wrap gap-2"><a href="{{ route('ems.dispatches',['new'=>1]) }}" class="gpha-button-primary">Add Movement</a><a href="{{ route('ems.reports') }}" class="gpha-button-secondary">Manage Reports</a></div>
    </div>

    @if($errors->any())<x-dismissible-alert type="error">{{ $errors->first() }}</x-dismissible-alert>@endif

    <section class="gpha-panel p-5" x-data="{filtersOpen:false,dashboardPeriod:@js($filters['period_preset'])}">
        <x-ems.mobile-filter-toggle />
        <form method="GET" action="{{ route('dashboard') }}" :class="filtersOpen ? '!grid' : 'hidden'" class="hidden gap-4 md:!grid md:grid-cols-2 lg:grid-cols-4">
            <div><div class="flex items-center gap-2"><label for="dashboard-period" class="gpha-label">Reporting Period</label><x-ems.help-tooltip label="About dashboard reporting periods">“This” periods run up to today. “Last” periods use the most recently completed period. EMS weeks run from Sunday through Saturday. Choose Custom Dates for any other range.</x-ems.help-tooltip></div><select id="dashboard-period" name="period_preset" x-model="dashboardPeriod" class="gpha-input" required><option value="today">Today</option><option value="yesterday">Yesterday</option><option value="this_week">This Week</option><option value="last_week">Last Week</option><option value="this_month">This Month</option><option value="last_month">Last Month</option><option value="this_quarter">This Quarter</option><option value="last_quarter">Last Quarter</option><option value="last_six_months">Last 6 Months</option><option value="this_year">This Year</option><option value="last_year">Last Year</option><option value="custom">Custom Dates</option></select></div>
            <label x-show="dashboardPeriod==='custom'"><span class="gpha-label">From Date <span class="text-red-600">*</span></span><input type="date" name="period_start" value="{{ $filters['period_start'] }}" :disabled="dashboardPeriod!=='custom'" :required="dashboardPeriod==='custom'" class="gpha-input"></label>
            <label x-show="dashboardPeriod==='custom'"><span class="gpha-label">To Date <span class="text-red-600">*</span></span><input type="date" name="period_end" value="{{ $filters['period_end'] }}" :disabled="dashboardPeriod!=='custom'" :required="dashboardPeriod==='custom'" class="gpha-input"></label>
            <label><span class="gpha-label">Ambulance</span><select name="ambulance_id" class="gpha-input"><option value="">All ambulances</option>@foreach($ambulances as $ambulance)<option value="{{ $ambulance->id }}" @selected((string)($filters['ambulance_id']??'')===(string)$ambulance->id)>{{ $ambulance->fleet_number }}</option>@endforeach</select></label>
            <label><span class="gpha-label">Movement Status</span><select name="status" class="gpha-input"><option value="">All statuses</option>@foreach(['requested','completed'] as $status)<option value="{{ $status }}" @selected(($filters['status']??'')===$status)>{{ str($status)->headline() }}</option>@endforeach</select></label>
            <div class="flex items-end gap-2 lg:justify-end"><a href="{{ route('dashboard') }}" class="gpha-button-secondary">Clear</a><button class="gpha-button-primary whitespace-nowrap">Apply Filters</button></div>
        </form>
    </section>

    @include('ems._analytics')
</div>
</x-app-layout>
