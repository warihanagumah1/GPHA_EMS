<x-app-layout>
@php
    $titles=[
        'ambulances'=>'Ambulance Fleet',
        'dispatches'=>'Dispatch & Movement',
        'mileage'=>'Mileage Readings',
        'availability'=>'Radio Communication & Availability',
        'activities'=>'Weekly Activities',
    ];
    $permissionMap=[
        'ambulances'=>['AmbulanceFleet','Manage'],
        'mileage'=>['AmbulanceFleet','Manage'],
        'dispatches'=>['DispatchAndMovement','Manage'],
        'availability'=>['ReadinessAndActivities','Manage'],
        'activities'=>['ReadinessAndActivities','Manage'],
    ];
    $permissionService=app(\App\Application\Sso\PermissionService::class);
    $canManage=$permissionService->allows(...$permissionMap[$module]);
    $newLabel=match($module){
        'ambulances'=>'Add Ambulance',
        'dispatches'=>'Add Movement',
        'mileage'=>'Add Mileage Reading',
        'availability'=>'Record Check Session',
        default=>'Add Activity',
    };
@endphp

<div class="gpha-page-shell space-y-6" x-data="{showForm:@js(request()->boolean('new') || $errors->any())}">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="font-extrabold text-gpha-primary">{{ in_array($module,['mileage','availability','activities'],true) ? 'Operations Logs' : 'EMS Operations' }}</p>
            <h1 class="text-3xl font-black tracking-tight text-slate-950">{{ $titles[$module] }}</h1>
            @if($module==='mileage')<p class="mt-1 font-semibold text-slate-500">Capture scheduled odometer readings for weekly mileage reconciliation.</p>@endif
            @if($module==='availability')<p class="mt-1 font-semibold text-slate-500">Record a complete radio and readiness check session for all operational units.</p>@endif
            @if($module==='activities')<p class="mt-1 font-semibold text-slate-500">Record meetings, inspections, training, administration, and follow-up actions.</p>@endif
        </div>
        @if($canManage)<button @click="showForm=!showForm" class="gpha-button-primary"><span x-text="showForm ? 'Close' : '{{ $newLabel }}'"></span></button>@endif
    </div>

    @if(session('success'))<x-dismissible-alert>{{ session('success') }}</x-dismissible-alert>@endif
    @if($errors->any())<x-dismissible-alert type="error"><p class="font-extrabold">Please correct the following:</p><ul class="mt-1 list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-dismissible-alert>@endif

    @if($canManage)
    <section x-cloak x-show="showForm" x-transition class="gpha-panel p-5">
        @if($module==='ambulances')
            <x-ems.ambulance-form :action="route('ems.ambulances.store')" />
        @elseif($module==='dispatches')
            <x-ems.movement-form :ambulances="$ambulances" />
        @elseif($module==='mileage')
            <form method="POST" action="{{ route('ems.mileage.store') }}" class="space-y-5">@csrf
                <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                    <label><span class="gpha-label">Ambulance <span class="text-red-600">*</span></span><select name="ambulance_id" class="gpha-input" required><option value="">Select ambulance</option>@foreach($ambulances as $ambulance)<option value="{{ $ambulance->id }}" @selected(old('ambulance_id')==$ambulance->id)>{{ $ambulance->fleet_number }} · {{ number_format($ambulance->odometer_km) }} km</option>@endforeach</select></label>
                    <label><span class="gpha-label">Reading Date <span class="text-red-600">*</span></span><input type="date" name="reading_date" value="{{ old('reading_date',today()->toDateString()) }}" max="{{ today()->toDateString() }}" class="gpha-input" required></label>
                    <label><span class="gpha-label">Odometer (km) <span class="text-red-600">*</span></span><input type="number" name="odometer_km" value="{{ old('odometer_km') }}" min="0" max="9999999" class="gpha-input" required></label>
                    <label><span class="gpha-label">Reading Type <span class="text-red-600">*</span></span><select name="source" class="gpha-input" required><option value="weekly" @selected(old('source','weekly')==='weekly')>Scheduled weekly reading</option><option value="service" @selected(old('source')==='service')>Service reading</option></select></label>
                    <label class="md:col-span-2 lg:col-span-4"><span class="gpha-label">Notes</span><textarea name="notes" rows="2" maxlength="1000" class="gpha-input" placeholder="Reason for unusual mileage, servicing, or correction">{{ old('notes') }}</textarea></label>
                </div>
                <div class="flex justify-end"><button class="gpha-button-primary">Save Mileage Reading</button></div>
            </form>
        @elseif($module==='availability')
            <form method="POST" action="{{ route('ems.availability.store') }}" class="space-y-5">@csrf
                <div class="grid gap-4 md:grid-cols-3">
                    <label><span class="gpha-label">Check Date <span class="text-red-600">*</span></span><input type="date" name="check_date" value="{{ old('check_date',today()->toDateString()) }}" max="{{ today()->toDateString() }}" class="gpha-input" required></label>
                    <label><span class="gpha-label">Session <span class="text-red-600">*</span></span><select name="period" class="gpha-input" required><option value="morning" @selected(old('period','morning')==='morning')>Morning</option><option value="afternoon" @selected(old('period')==='afternoon')>Afternoon</option></select></label>
                    <label><span class="gpha-label">Check Time <span class="text-red-600">*</span></span><input type="time" name="checked_at" value="{{ old('checked_at',now()->format('H:i')) }}" class="gpha-input" required></label>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3"><p class="font-semibold text-blue-900">If every unit answered, complete all response fields in one click.</p><button type="button" data-mark-all-responded class="gpha-button-secondary border-blue-300 bg-white">Mark All Responded</button></div>
                <div class="overflow-x-auto rounded-xl border border-slate-200">
                    <table class="gpha-table"><thead><tr><th>Department / Unit</th><th>Response <span class="text-red-600">*</span></th><th>Response Location</th><th>Observation</th></tr></thead>
                    <tbody>@foreach($availabilityUnits as $index=>$unit)<tr>
                        <td class="font-extrabold">{{ $unit }}<input type="hidden" name="checks[{{ $index }}][unit_name]" value="{{ $unit }}"></td>
                        <td><select name="checks[{{ $index }}][responded]" data-response class="gpha-input min-w-40" required><option value="">Select response</option><option value="1" @selected(old("checks.$index.responded")==='1')>Responded</option><option value="0" @selected(old("checks.$index.responded")==='0')>No response</option></select></td>
                        <td><select name="checks[{{ $index }}][response_location]" class="gpha-input min-w-52"><option value="">Not stated</option>@foreach(config('ems.movement_locations') as $location)<option value="{{ $location }}" @selected(old("checks.$index.response_location")===$location)>{{ $location }}</option>@endforeach</select></td>
                        <td><input name="checks[{{ $index }}][observation]" value="{{ old("checks.$index.observation") }}" class="gpha-input min-w-60" maxlength="1000" placeholder="Fault, reason, or note"></td>
                    </tr>@endforeach</tbody></table>
                </div>
                <div class="flex justify-end"><button class="gpha-button-primary">Save Check Session</button></div>
            </form>
        @else
            <form method="POST" action="{{ route('ems.activities.store') }}" class="space-y-5" x-data="{followUp:@js(old('requires_follow_up')==='1'),outcomeOpen:@js(filled(old('outcome')))}">@csrf
                <div class="grid gap-4 md:grid-cols-2">
                    <label><span class="gpha-label">Activity Date <span class="text-red-600">*</span></span><input type="date" name="activity_date" value="{{ old('activity_date',today()->toDateString()) }}" max="{{ today()->toDateString() }}" class="gpha-input" required></label>
                    <label><span class="gpha-label">Category <span class="text-red-600">*</span></span><select name="category" class="gpha-input" required>@foreach(['operations','meeting','training','inspection','administration','outreach'] as $category)<option value="{{ $category }}" @selected(old('category')===$category)>{{ str($category)->headline() }}</option>@endforeach</select></label>
                    <div class="md:col-span-2"><span class="gpha-label">What happened? <span class="text-red-600">*</span></span><x-ems.rich-text-editor name="description" :value="old('description')" placeholder="Briefly describe the activity, people involved, location, and important details" required /></div>
                    <div class="flex flex-wrap items-center gap-5 md:col-span-2"><button type="button" @click="outcomeOpen=!outcomeOpen" class="gpha-button-secondary" x-text="outcomeOpen?'Hide Outcome':'Add Outcome / Decision'"></button><label class="flex items-center gap-3"><input type="checkbox" name="requires_follow_up" value="1" x-model="followUp" @checked(old('requires_follow_up'))><span class="font-extrabold">Requires follow-up</span></label></div>
                    <div x-cloak x-show="outcomeOpen" x-transition class="md:col-span-2"><span class="gpha-label">Outcome / Decision</span><x-ems.rich-text-editor name="outcome" :value="old('outcome')" placeholder="Record the outcome or decision" /></div>
                </div>
                <div x-cloak x-show="followUp" x-transition class="grid gap-4 rounded-xl border border-amber-200 bg-amber-50 p-4 md:grid-cols-3">
                    <label><span class="gpha-label">Follow-up Action <span class="text-red-600">*</span></span><input name="follow_up_action" value="{{ old('follow_up_action') }}" class="gpha-input" :required="followUp" maxlength="2000"></label>
                    <label><span class="gpha-label">Action Owner <span class="text-red-600">*</span></span><input name="follow_up_owner" value="{{ old('follow_up_owner') }}" class="gpha-input" :required="followUp" maxlength="160"></label>
                    <label><span class="gpha-label">Due Date</span><input type="date" name="follow_up_due_date" value="{{ old('follow_up_due_date') }}" class="gpha-input"></label>
                </div>
                <div class="flex justify-end"><button class="gpha-button-primary">Save Activity</button></div>
            </form>
        @endif
    </section>
    @endif

    @if($module==='dispatches')
    <section class="gpha-panel p-5" x-data="{moreFilters:@js(filled($movementFilters['priority']??null)||filled($movementFilters['purpose']??null)||filled($movementFilters['origin']??null)||filled($movementFilters['destination']??null))}">
        <form method="GET" action="{{ route('ems.dispatches') }}" class="space-y-4">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                <label><span class="gpha-label">Search</span><input name="search" value="{{ $movementFilters['search']??'' }}" class="gpha-input" placeholder="Reference or notes"></label>
                <label><span class="gpha-label">Ambulance</span><select name="ambulance_id" class="gpha-input"><option value="">All ambulances</option>@foreach($ambulances as $ambulance)<option value="{{ $ambulance->id }}" @selected(($movementFilters['ambulance_id']??null)==$ambulance->id)>{{ $ambulance->fleet_number }}</option>@endforeach</select></label>
                <label><span class="gpha-label">Status</span><select name="status" class="gpha-input"><option value="">All statuses</option>@foreach(['requested','completed'] as $value)<option value="{{ $value }}" @selected(($movementFilters['status']??'')===$value)>{{ str($value)->headline() }}</option>@endforeach</select></label>
                <label><span class="gpha-label">From Date</span><input type="date" name="date_from" value="{{ $movementFilters['date_from']??'' }}" class="gpha-input"></label>
                <label><span class="gpha-label">To Date</span><input type="date" name="date_to" value="{{ $movementFilters['date_to']??'' }}" class="gpha-input"></label>
            </div>
            <div x-cloak x-show="moreFilters" x-transition class="grid gap-4 rounded-xl border border-slate-200 bg-slate-50 p-4 md:grid-cols-2 xl:grid-cols-4">
                <label><span class="gpha-label">Priority</span><select name="priority" class="gpha-input"><option value="">All priorities</option>@foreach(['routine','urgent','critical'] as $value)<option value="{{ $value }}" @selected(($movementFilters['priority']??'')===$value)>{{ str($value)->headline() }}</option>@endforeach</select></label>
                <label><span class="gpha-label">Case Category</span><select name="purpose" class="gpha-input"><option value="">All categories</option>@foreach(config('ems.case_categories') as $value)<option value="{{ $value }}" @selected(($movementFilters['purpose']??'')===$value)>{{ $value }}</option>@endforeach</select></label>
                <label><span class="gpha-label">Origin</span><select name="origin" class="gpha-input"><option value="">All origins</option>@foreach(config('ems.movement_locations') as $value)<option value="{{ $value }}" @selected(($movementFilters['origin']??'')===$value)>{{ $value }}</option>@endforeach</select></label>
                <label><span class="gpha-label">Destination</span><select name="destination" class="gpha-input"><option value="">All destinations</option>@foreach(config('ems.movement_locations') as $value)<option value="{{ $value }}" @selected(($movementFilters['destination']??'')===$value)>{{ $value }}</option>@endforeach</select></label>
            </div>
            <div class="flex flex-wrap justify-end gap-2"><button type="button" @click="moreFilters=!moreFilters" class="gpha-button-secondary" x-text="moreFilters?'Fewer Filters':'More Filters'"></button><a href="{{ route('ems.dispatches') }}" class="gpha-button-secondary">Clear</a><button class="gpha-button-primary">Apply Filters</button></div>
        </form>
    </section>
    @endif

    @if($module==='availability')
    <section class="gpha-panel p-5">
        <form method="GET" action="{{ route('ems.availability') }}" class="space-y-4">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                <label><span class="gpha-label">From Date</span><input type="date" name="date_from" value="{{ $availabilityFilters['date_from']??'' }}" class="gpha-input"></label>
                <label><span class="gpha-label">To Date</span><input type="date" name="date_to" value="{{ $availabilityFilters['date_to']??'' }}" class="gpha-input"></label>
                <label><span class="gpha-label">Session</span><select name="period" class="gpha-input"><option value="">All sessions</option>@foreach(['morning','afternoon'] as $value)<option value="{{ $value }}" @selected(($availabilityFilters['period']??'')===$value)>{{ str($value)->headline() }}</option>@endforeach</select></label>
                <label><span class="gpha-label">Unit</span><select name="unit_name" class="gpha-input"><option value="">All units</option>@foreach($availabilityUnits as $unit)<option value="{{ $unit }}" @selected(($availabilityFilters['unit_name']??'')===$unit)>{{ $unit }}</option>@endforeach</select></label>
                <label><span class="gpha-label">Response</span><select name="responded" class="gpha-input"><option value="">All responses</option><option value="1" @selected(($availabilityFilters['responded']??'')==='1')>Responded</option><option value="0" @selected(($availabilityFilters['responded']??'')==='0')>No response</option></select></label>
            </div>
            <div class="flex justify-end gap-2"><a href="{{ route('ems.availability') }}" class="gpha-button-secondary">Clear</a><button class="gpha-button-primary">Apply Filters</button></div>
        </form>
    </section>
    @endif

    <section class="gpha-panel overflow-hidden">
        <div class="border-b border-slate-200 px-5 py-4"><h2 class="text-xl font-black">{{ match($module){'ambulances'=>'Ambulance List','dispatches'=>'Movement List','mileage'=>'Odometer History','availability'=>'Check Sessions',default=>'Activity History'} }}</h2></div>
        <div class="overflow-x-auto"><table class="gpha-table"><thead><tr>
            @if($module==='ambulances')<th>Ambulance No.</th><th>Registration</th><th>Location</th><th>Odometer</th><th>Status</th><th class="gpha-actions-heading">Actions</th>
            @elseif($module==='dispatches')<th>Reference</th><th>Date</th><th>Ambulance</th><th>Route</th><th>Priority</th><th>Status</th><th class="gpha-actions-heading">Actions</th>
            @elseif($module==='mileage')<th>Date</th><th>Ambulance</th><th>Odometer</th><th>Type</th><th>Notes</th><th class="gpha-actions-heading">Actions</th>
            @elseif($module==='availability')<th>Date / Time</th><th>Session</th><th>Units Checked</th><th>Responded</th><th>No Response</th><th class="gpha-actions-heading">Actions</th>
            @else<th>Date</th><th>Activity</th><th>Category</th><th>Outcome</th><th>Follow-up</th><th class="gpha-actions-heading">Actions</th>@endif
        </tr></thead><tbody>
            @php($rows=match($module){'ambulances'=>$ambulances,'dispatches'=>$dispatches,'mileage'=>$readings,'availability'=>$checks,default=>$activities})
            @forelse($rows as $row)<tr>
                @if($module==='ambulances')<x-ems.ambulance-row :ambulance="$row" :can-manage="$canManage" />
                @elseif($module==='dispatches')<td><a href="{{ route('ems.dispatches.show',$row) }}" class="font-extrabold text-gpha-primary hover:underline">{{ $row->reference }}</a></td><td>{{ $row->requested_at?->format('d M Y H:i') }}</td><td>{{ $row->ambulance?->fleet_number }}</td><td>{{ $row->origin }} → {{ $row->destination }}<p class="text-sm font-semibold text-slate-500">{{ $row->purpose }}</p></td><td><x-ems.status-badge :status="$row->priority" /></td><td><x-ems.status-badge :status="$row->status" /></td><x-ems.movement-row :movement="$row" :can-manage="$canManage" actions-only />
                @elseif($module==='mileage')<td>{{ $row->reading_date->format('d M Y') }}</td><td>{{ $row->ambulance?->fleet_number }}</td><td class="font-extrabold">{{ number_format($row->odometer_km) }} km</td><td>{{ str($row->source)->headline() }}</td><td>{{ $row->notes?:'—' }}</td><td class="gpha-actions-cell"><div class="relative inline-block text-left" x-data="{open:false,menuTop:0,menuLeft:0,positionMenu(){const r=this.$refs.trigger.getBoundingClientRect(),w=224,h=170,p=8;this.menuTop=Math.max(p,Math.min(r.top,window.innerHeight-h-p));this.menuLeft=r.right+p+w<=window.innerWidth-p?r.right+p:Math.max(p,r.left-w-p)}}" @resize.window="open&&positionMenu()" @scroll.window="open&&positionMenu()" @keydown.escape.window="open=false"><x-ems.action-trigger x-ref="trigger" x-bind:class="{'is-open':open}" @click.stop="positionMenu();open=!open" label="Mileage reading actions" /><div x-cloak x-show="open" x-transition @click.outside="open=false" :style="`top:${menuTop}px;left:${menuLeft}px`" class="gpha-floating-action-menu"><a href="{{ route('ems.mileage.show',$row) }}" class="block w-full px-4 py-2 text-left font-semibold text-slate-700 hover:bg-slate-50 hover:text-gpha-primary">View</a>@if($canManage)<a href="{{ route('ems.mileage.edit',$row) }}" class="block w-full px-4 py-2 text-left font-semibold text-gpha-primary hover:bg-blue-50">Edit</a><form method="POST" action="{{ route('ems.mileage.destroy',$row) }}" data-confirm-title="Delete Mileage Reading?" data-confirm-message="The {{ number_format($row->odometer_km) }} km reading for {{ $row->ambulance?->fleet_number }} on {{ $row->reading_date->format('d M Y') }} will be removed from mileage reports but retained in the audit trail." data-confirm-label="Yes, Delete Reading" data-confirm-tone="danger">@csrf @method('DELETE')<button type="submit" @click="open=false" class="block min-h-0 w-full px-4 py-2 text-left font-semibold text-red-600 hover:bg-red-50">Delete</button></form>@endif</div></div></td>
                @elseif($module==='availability')
                    <td>{{ $row->check_date->format('d M Y') }}<p class="text-sm font-semibold text-slate-500">{{ $row->checked_at ? substr($row->checked_at,0,5).' hrs' : '—' }}</p></td>
                    <td class="font-extrabold">{{ str($row->period)->headline() }}</td>
                    <td>{{ number_format($row->unit_count) }}</td>
                    <td><span class="gpha-status bg-emerald-100 text-emerald-700">{{ number_format($row->responded_count) }}</span></td>
                    <td><span class="gpha-status {{ ($row->unit_count-$row->responded_count)>0?'bg-red-100 text-red-700':'bg-slate-100 text-slate-600' }}">{{ number_format($row->unit_count-$row->responded_count) }}</span></td>
                    <td class="gpha-actions-cell"><div class="relative inline-block text-left" x-data="{open:false,menuTop:0,menuLeft:0,positionMenu(){const r=this.$refs.trigger.getBoundingClientRect(),w=224,h=170,p=8;this.menuTop=Math.max(p,Math.min(r.top,window.innerHeight-h-p));this.menuLeft=r.right+p+w<=window.innerWidth-p?r.right+p:Math.max(p,r.left-w-p)}}" @resize.window="open&&positionMenu()" @scroll.window="open&&positionMenu()" @keydown.escape.window="open=false"><x-ems.action-trigger x-ref="trigger" x-bind:class="{'is-open':open}" @click.stop="positionMenu();open=!open" label="Check session actions" /><div x-cloak x-show="open" x-transition @click.outside="open=false" :style="`top:${menuTop}px;left:${menuLeft}px`" class="gpha-floating-action-menu"><a href="{{ route('ems.availability.sessions.show',$row->session_uuid) }}" class="block w-full px-4 py-2 text-left font-semibold text-slate-700 hover:bg-slate-50 hover:text-gpha-primary">View</a>@if($canManage)<a href="{{ route('ems.availability.sessions.edit',$row->session_uuid) }}" class="block w-full px-4 py-2 text-left font-semibold text-gpha-primary hover:bg-blue-50">Edit</a><form method="POST" action="{{ route('ems.availability.sessions.destroy',$row->session_uuid) }}" data-confirm-title="Delete Check Session?" data-confirm-message="This entire availability check session will be removed from operational lists but retained in the audit trail." data-confirm-label="Yes, Delete Session" data-confirm-tone="danger">@csrf @method('DELETE')<button type="submit" @click="open=false" class="block min-h-0 w-full px-4 py-2 text-left font-semibold text-red-600 hover:bg-red-50">Delete</button></form>@endif</div></div></td>
                @else<td>{{ $row->activity_date->format('d M Y') }}</td><td><p class="max-w-xl font-semibold text-slate-700">{{ str(\App\Support\RichText::plain($row->description))->limit(150) }}</p></td><td>{{ str($row->category)->headline() }}</td><td>{{ $row->outcome?str(\App\Support\RichText::plain($row->outcome))->limit(80):'—' }}</td><td>@if($row->requires_follow_up)<span class="font-bold text-amber-700">{{ $row->follow_up_action }}<br><small>{{ $row->follow_up_owner }}{{ $row->follow_up_due_date?' · due '.$row->follow_up_due_date->format('d M Y'):'' }}</small></span>@else<span class="font-bold text-emerald-700">Closed</span>@endif</td><td class="gpha-actions-cell"><div class="relative inline-block text-left" x-data="{open:false,menuTop:0,menuLeft:0,positionMenu(){const r=this.$refs.trigger.getBoundingClientRect(),w=224,h=170,p=8;this.menuTop=Math.max(p,Math.min(r.top,window.innerHeight-h-p));this.menuLeft=r.right+p+w<=window.innerWidth-p?r.right+p:Math.max(p,r.left-w-p)}}" @resize.window="open&&positionMenu()" @scroll.window="open&&positionMenu()" @keydown.escape.window="open=false"><x-ems.action-trigger x-ref="trigger" x-bind:class="{'is-open':open}" @click.stop="positionMenu();open=!open" label="Activity actions" /><div x-cloak x-show="open" x-transition @click.outside="open=false" :style="`top:${menuTop}px;left:${menuLeft}px`" class="gpha-floating-action-menu"><a href="{{ route('ems.activities.show',$row) }}" class="block w-full px-4 py-2 text-left font-semibold text-slate-700 hover:bg-slate-50 hover:text-gpha-primary">View</a>@if($canManage)<a href="{{ route('ems.activities.edit',$row) }}" class="block w-full px-4 py-2 text-left font-semibold text-gpha-primary hover:bg-blue-50">Edit</a><form method="POST" action="{{ route('ems.activities.destroy',$row) }}" data-confirm-title="Delete Activity?" data-confirm-message="This activity will be removed from operational lists but retained in the audit trail." data-confirm-label="Yes, Delete Activity" data-confirm-tone="danger">@csrf @method('DELETE')<button type="submit" @click="open=false" class="block min-h-0 w-full px-4 py-2 text-left font-semibold text-red-600 hover:bg-red-50">Delete</button></form>@endif</div></div></td>
                @endif
            </tr>@empty<tr><td colspan="7" class="py-12 text-center text-slate-500">No records found.</td></tr>@endforelse
        </tbody></table></div>
        @if(method_exists($rows,'links'))<div class="border-t border-slate-200 px-5 py-4">{{ $rows->links() }}</div>@endif
    </section>
</div>
</x-app-layout>
