<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Ambulance;
use App\Models\AvailabilityCheck;
use App\Models\Dispatch;
use App\Models\EmsReport;
use App\Models\EmsAuditLog;
use App\Models\MileageReading;
use App\Models\WeeklyActivity;
use App\Support\RichText;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmsOperationsController extends Controller
{
    public function dashboard()
    {
        return view('ems.dashboard', [
            'ambulances' => Ambulance::orderBy('fleet_number')->get(),
            'activeDispatches' => Dispatch::with('ambulance')->whereNotIn('status', ['completed', 'cancelled'])->latest('requested_at')->get(),
            'recentDispatches' => Dispatch::with('ambulance')->latest('requested_at')->limit(6)->get(),
            'completedToday' => Dispatch::where('status', 'completed')->whereDate('completed_at', today())->count(),
            'criticalActive' => Dispatch::where('priority', 'critical')->whereNotIn('status', ['completed', 'cancelled'])->count(),
            'checksToday' => AvailabilityCheck::whereDate('check_date', today())->count(),
            'negativeChecksToday' => AvailabilityCheck::whereDate('check_date', today())->where('responded', false)->count(),
            'followUps' => WeeklyActivity::where('requires_follow_up', true)->whereDate('activity_date', '>=', now()->subDays(14))->count(),
        ]);
    }

    public function index(Request $request, string $module)
    {
        abort_unless(in_array($module, ['ambulances','dispatches','mileage','availability','activities'], true), 404);

        $movementFilters = [];
        $mileageFilters = [];
        $availabilityFilters = [];
        $activityFilters = [];
        $dispatches = collect();
        if ($module === 'dispatches') {
            $movementFilters = $request->validate([
                'search' => ['nullable','string','max:120'],
                'ambulance_id' => ['nullable','integer','exists:ambulances,id'],
                'status' => ['nullable',Rule::in(['requested','completed'])],
                'priority' => ['nullable',Rule::in(['routine','urgent','critical'])],
                'purpose' => ['nullable',Rule::in(config('ems.case_categories'))],
                'origin' => ['nullable',Rule::in(config('ems.movement_locations'))],
                'destination' => ['nullable',Rule::in(config('ems.movement_locations'))],
                'date_from' => ['nullable','date'],
                'date_to' => ['nullable','date','after_or_equal:date_from'],
            ]);
            $dispatches = Dispatch::with('ambulance')
                ->when(filled($movementFilters['search'] ?? null), function ($query) use ($movementFilters) {
                    $search = trim($movementFilters['search']);
                    $query->where(fn ($query) => $query->where('reference','like',"%{$search}%")
                        ->orWhere('notes','like',"%{$search}%"));
                })
                ->when(filled($movementFilters['ambulance_id'] ?? null), fn ($query) => $query->where('ambulance_id',$movementFilters['ambulance_id']))
                ->when(filled($movementFilters['status'] ?? null), fn ($query) => $query->where('status',$movementFilters['status']))
                ->when(filled($movementFilters['priority'] ?? null), fn ($query) => $query->where('priority',$movementFilters['priority']))
                ->when(filled($movementFilters['purpose'] ?? null), fn ($query) => $query->where('purpose',$movementFilters['purpose']))
                ->when(filled($movementFilters['origin'] ?? null), fn ($query) => $query->where('origin',$movementFilters['origin']))
                ->when(filled($movementFilters['destination'] ?? null), fn ($query) => $query->where('destination',$movementFilters['destination']))
                ->when(filled($movementFilters['date_from'] ?? null), fn ($query) => $query->whereDate('requested_at','>=',$movementFilters['date_from']))
                ->when(filled($movementFilters['date_to'] ?? null), fn ($query) => $query->whereDate('requested_at','<=',$movementFilters['date_to']))
                ->latest('requested_at')->paginate(15)->withQueryString();
        }

        $readings = collect();
        $mileageMovementSummaries = collect();
        $mileageTotalMovement = 0;
        if ($module === 'mileage') {
            $mileageFilters = $request->validate([
                'ambulance_id' => ['nullable','integer','exists:ambulances,id'],
                'source' => ['nullable',Rule::in(['weekly','service'])],
                'date_from' => ['nullable','date'],
                'date_to' => ['nullable','date','after_or_equal:date_from'],
            ]);
            $mileageQuery = MileageReading::with('ambulance')
                ->when(filled($mileageFilters['ambulance_id'] ?? null), fn ($query) => $query->where('ambulance_id',$mileageFilters['ambulance_id']))
                ->when(filled($mileageFilters['source'] ?? null), fn ($query) => $query->where('source',$mileageFilters['source']))
                ->when(filled($mileageFilters['date_from'] ?? null), fn ($query) => $query->whereDate('reading_date','>=',$mileageFilters['date_from']))
                ->when(filled($mileageFilters['date_to'] ?? null), fn ($query) => $query->whereDate('reading_date','<=',$mileageFilters['date_to']));

            $summaryReadings = (clone $mileageQuery)->oldest('reading_date')->oldest('id')->get();
            $mileageMovementSummaries = $summaryReadings->groupBy('ambulance_id')
                ->map(function ($ambulanceReadings) {
                    $first = $ambulanceReadings->first();
                    $last = $ambulanceReadings->last();
                    $hasMovement = $ambulanceReadings->count() >= 2;

                    return [
                        'ambulance' => $first->ambulance?->fleet_number ?? 'Unknown ambulance',
                        'reading_count' => $ambulanceReadings->count(),
                        'first_date' => $first->reading_date,
                        'last_date' => $last->reading_date,
                        'opening_odometer' => (int) $first->odometer_km,
                        'closing_odometer' => (int) $last->odometer_km,
                        'movement_km' => $hasMovement ? max(0, (int) $last->odometer_km - (int) $first->odometer_km) : null,
                    ];
                })->sortBy('ambulance')->values();
            $mileageTotalMovement = $mileageMovementSummaries->sum(fn ($summary) => $summary['movement_km'] ?? 0);
            $readings = $mileageQuery->latest('reading_date')->latest('id')->paginate(15)->withQueryString();
        }

        $checks = collect();
        if ($module === 'availability') {
            $availabilityFilters = $request->validate([
                'date_from' => ['nullable','date'],
                'date_to' => ['nullable','date','after_or_equal:date_from'],
                'period' => ['nullable',Rule::in(['morning','afternoon'])],
                'response_status' => ['nullable',Rule::in(['all_responded','has_no_response'])],
            ]);
            $checks = AvailabilityCheck::query()
                ->selectRaw('session_uuid, check_date, period, checked_at, COUNT(*) as unit_count, SUM(CASE WHEN responded = 1 THEN 1 ELSE 0 END) as responded_count')
                ->when(filled($availabilityFilters['date_from'] ?? null), fn ($query) => $query->whereDate('check_date','>=',$availabilityFilters['date_from']))
                ->when(filled($availabilityFilters['date_to'] ?? null), fn ($query) => $query->whereDate('check_date','<=',$availabilityFilters['date_to']))
                ->when(filled($availabilityFilters['period'] ?? null), fn ($query) => $query->where('period',$availabilityFilters['period']))
                ->groupBy('session_uuid','check_date','period','checked_at')
                ->when(($availabilityFilters['response_status'] ?? '') === 'all_responded', fn ($query) => $query->havingRaw('SUM(CASE WHEN responded = 1 THEN 1 ELSE 0 END) = COUNT(*)'))
                ->when(($availabilityFilters['response_status'] ?? '') === 'has_no_response', fn ($query) => $query->havingRaw('SUM(CASE WHEN responded = 1 THEN 1 ELSE 0 END) < COUNT(*)'))
                ->latest('check_date')->latest('checked_at')->paginate(15)->withQueryString();
        }

        $activities = collect();
        if ($module === 'activities') {
            $activityFilters = $request->validate([
                'search' => ['nullable','string','max:120'],
                'category' => ['nullable',Rule::in(['operations','meeting','training','inspection','administration','outreach'])],
                'requires_follow_up' => ['nullable',Rule::in(['1','0'])],
                'date_from' => ['nullable','date'],
                'date_to' => ['nullable','date','after_or_equal:date_from'],
            ]);
            $activities = WeeklyActivity::query()
                ->when(filled($activityFilters['search'] ?? null), function ($query) use ($activityFilters) {
                    $search = trim($activityFilters['search']);
                    $query->where(fn ($query) => $query->where('title','like',"%{$search}%")
                        ->orWhere('description','like',"%{$search}%")
                        ->orWhere('outcome','like',"%{$search}%")
                        ->orWhere('follow_up_action','like',"%{$search}%")
                        ->orWhere('follow_up_owner','like',"%{$search}%"));
                })
                ->when(filled($activityFilters['category'] ?? null), fn ($query) => $query->where('category',$activityFilters['category']))
                ->when(($activityFilters['requires_follow_up'] ?? '') !== '', fn ($query) => $query->where('requires_follow_up',(bool) $activityFilters['requires_follow_up']))
                ->when(filled($activityFilters['date_from'] ?? null), fn ($query) => $query->whereDate('activity_date','>=',$activityFilters['date_from']))
                ->when(filled($activityFilters['date_to'] ?? null), fn ($query) => $query->whereDate('activity_date','<=',$activityFilters['date_to']))
                ->latest('activity_date')->latest('id')->paginate(15)->withQueryString();
        }

        $ambulances = Ambulance::orderBy('fleet_number')->get();
        $fleet = $module === 'ambulances'
            ? Ambulance::orderBy('fleet_number')->paginate(15)->withQueryString()
            : collect();
        return view('ems.module', [
            'module' => $module,
            'ambulances' => $ambulances,
            'fleet' => $fleet,
            'dispatches' => $dispatches,
            'movementFilters' => $movementFilters,
            'mileageFilters' => $mileageFilters,
            'mileageMovementSummaries' => $mileageMovementSummaries,
            'mileageTotalMovement' => $mileageTotalMovement,
            'availabilityFilters' => $availabilityFilters,
            'activityFilters' => $activityFilters,
            'readings' => $readings,
            'checks' => $checks,
            'activities' => $activities,
            'availabilityUnits' => collect($ambulances->pluck('fleet_number'))->merge(config('ems.availability_units'))->unique()->values(),
        ]);
    }

    public function storeAmbulance(Request $request): RedirectResponse
    {
        $data = $this->validateAmbulance($request);
        Ambulance::create($data+['uuid'=>(string)Str::uuid(),'status'=>'available']);
        return back()->with('success','Ambulance added successfully.');
    }

    public function showAmbulance(Request $request, Ambulance $ambulance)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['requested', 'completed'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $movements = $ambulance->dispatches()
            ->when(filled($filters['search'] ?? null), function ($query) use ($filters) {
                $search = trim((string) $filters['search']);
                $query->where(function ($query) use ($search) {
                    $query->where('reference', 'like', "%{$search}%")
                        ->orWhere('origin', 'like', "%{$search}%")
                        ->orWhere('destination', 'like', "%{$search}%")
                        ->orWhere('purpose', 'like', "%{$search}%");
                });
            })
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->where('status', $filters['status']))
            ->when(filled($filters['date_from'] ?? null), fn ($query) => $query->whereDate('requested_at', '>=', $filters['date_from']))
            ->when(filled($filters['date_to'] ?? null), fn ($query) => $query->whereDate('requested_at', '<=', $filters['date_to']))
            ->latest('requested_at')
            ->paginate(15)
            ->withQueryString();

        $ambulance->loadCount('dispatches');

        return view('ems.ambulances.show', compact('ambulance', 'movements'));
    }

    public function editAmbulance(Ambulance $ambulance)
    {
        return view('ems.ambulances.edit', compact('ambulance'));
    }

    public function updateAmbulance(Request $request, Ambulance $ambulance): RedirectResponse
    {
        $data = $this->validateAmbulance($request, $ambulance);

        if ($data['odometer_km'] < $ambulance->odometer_km) {
            throw ValidationException::withMessages([
                'odometer_km' => 'The odometer cannot be lower than the current recorded value of '.number_format($ambulance->odometer_km).' km.',
            ]);
        }

        $ambulance->update($data);

        return redirect()->route('ems.ambulances.show', $ambulance)->with('success', 'Ambulance updated successfully.');
    }

    public function updateAmbulanceStatus(Request $request, Ambulance $ambulance): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['available', 'unavailable'])]]);
        $hasActiveMovement = $ambulance->dispatches()->whereNotIn('status', ['completed', 'cancelled'])->exists();

        if ($hasActiveMovement) {
            throw ValidationException::withMessages([
                'status' => 'This ambulance has an active movement and its availability cannot be changed yet.',
            ]);
        }

        $ambulance->update(['status' => $data['status']]);

        return back()->with('success', $data['status'] === 'available'
            ? 'Ambulance marked as available.'
            : 'Ambulance marked as unavailable.');
    }

    public function storeDispatch(Request $request): RedirectResponse
    {
        $data=$this->validateMovement($request);
        $ambulance=Ambulance::findOrFail($data['ambulance_id']);
        if($data['status']==='requested'&&$ambulance->status!=='available')throw ValidationException::withMessages(['ambulance_id'=>'Only an available ambulance can be assigned to a requested movement.']);
        $movementDate=Carbon::parse($data['requested_at']);
        if($data['status']==='completed')$data['completed_at']=$movementDate;
        Dispatch::create($data+['uuid'=>(string)Str::uuid(),'reference'=>'EMS-'.$movementDate->format('ymd').'-'.strtoupper(Str::random(5)),'created_by'=>auth()->id()]);
        if($data['status']==='requested')$ambulance->update(['status'=>'dispatched','current_location'=>$data['destination']]);
        return back()->with('success',$data['status']==='completed'?'Completed movement recorded.':'Movement requested and ambulance status updated.');
    }

    public function showDispatch(Dispatch $dispatch)
    {
        $dispatch->load('ambulance');
        return view('ems.movements.show', compact('dispatch'));
    }

    public function editDispatch(Dispatch $dispatch)
    {
        return view('ems.movements.edit', [
            'dispatch' => $dispatch,
            'ambulances' => Ambulance::orderBy('fleet_number')->get(),
        ]);
    }

    public function updateDispatch(Request $request, Dispatch $dispatch): RedirectResponse
    {
        $data = $this->validateMovement($request);
        $newAmbulance = Ambulance::findOrFail($data['ambulance_id']);

        if (($dispatch->status === 'completed' || $data['status'] === 'completed') && $newAmbulance->id !== $dispatch->ambulance_id) {
            throw ValidationException::withMessages(['ambulance_id' => 'The ambulance cannot be changed on a completed movement.']);
        }

        $wasActive=$dispatch->status==='requested';
        $willBeActive=$data['status']==='requested';
        if ($willBeActive && (!$wasActive || $newAmbulance->id !== $dispatch->ambulance_id) && $newAmbulance->status !== 'available') {
            throw ValidationException::withMessages(['ambulance_id' => 'Only an available ambulance can be assigned to this movement.']);
        }

        DB::transaction(function () use ($dispatch, $data, $newAmbulance, $wasActive, $willBeActive) {
            $previousAmbulance = $dispatch->ambulance;
            $data['completed_at']=$willBeActive?null:($dispatch->completed_at??now());
            $dispatch->update($data);
            if($willBeActive)$newAmbulance->update(['status' => 'dispatched', 'current_location' => $data['destination']]);
            if($wasActive&&!$willBeActive)$previousAmbulance->update(['status'=>'available','current_location'=>$data['destination']]);
            elseif ($wasActive && $previousAmbulance->id !== $newAmbulance->id) {
                $previousAmbulance->update(['status' => 'available']);
            }
        });

        return redirect()->route('ems.dispatches.show', $dispatch)->with('success', 'Movement updated successfully.');
    }

    public function destroyDispatch(Dispatch $dispatch): RedirectResponse
    {
        DB::transaction(function () use ($dispatch) {
            $ambulance=$dispatch->ambulance;
            $wasActive=!in_array($dispatch->status,['completed','cancelled'],true);
            $origin=$dispatch->origin;
            $dispatch->delete();
            if($wasActive&&!$ambulance->dispatches()->whereNotIn('status',['completed','cancelled'])->exists()){
                $ambulance->update(['status'=>'available','current_location'=>$origin]);
            }
        });

        return redirect()->route('ems.dispatches')->with('success','Movement deleted. The record remains preserved in the audit trail.');
    }

    public function completeDispatch(Request $request, Dispatch $dispatch): RedirectResponse
    {
        abort_unless(in_array($dispatch->status, ['requested', 'dispatched', 'arrived'], true), 422, 'Only an active movement can be completed.');
        DB::transaction(function () use ($dispatch) {
            $dispatch->update(['status' => 'completed', 'completed_at' => now()]);
            $dispatch->ambulance->update(['status' => 'available', 'current_location' => $dispatch->destination]);
        });

        return back()->with('success', 'Movement marked as completed and the ambulance is available again.');
    }

    public function storeMileage(Request $request): RedirectResponse
    {
        [$data,$ambulance,$next]=$this->validateMileage($request);
        DB::transaction(function() use($data,$ambulance,$next){ MileageReading::create($data+['recorded_by'=>auth()->id()]); if(!$next && $data['odometer_km']>=$ambulance->odometer_km)$ambulance->update(['odometer_km'=>$data['odometer_km']]); });
        return back()->with('success','Mileage reading saved.');
    }

    public function showMileage(MileageReading $reading)
    {
        $reading->load(['ambulance','recordedBy']);
        return view('ems.mileage.show',compact('reading'));
    }

    public function editMileage(MileageReading $reading)
    {
        return view('ems.mileage.edit',[
            'reading'=>$reading->load('ambulance'),
            'ambulances'=>Ambulance::orderBy('fleet_number')->get(),
        ]);
    }

    public function updateMileage(Request $request,MileageReading $reading): RedirectResponse
    {
        [$data,$ambulance,$next]=$this->validateMileage($request,$reading);
        DB::transaction(function()use($reading,$data,$ambulance,$next){
            $reading->update($data);
            if(!$next&&$data['odometer_km']>=$ambulance->odometer_km)$ambulance->update(['odometer_km'=>$data['odometer_km']]);
        });
        return redirect()->route('ems.mileage.show',$reading)->with('success','Mileage reading updated successfully.');
    }

    public function destroyMileage(MileageReading $reading): RedirectResponse
    {
        DB::transaction(function()use($reading){
            $ambulance=$reading->ambulance;
            $wasLatest=(int)MileageReading::where('ambulance_id',$reading->ambulance_id)
                ->latest('reading_date')->latest('id')->value('id')===$reading->id;
            $deletedOdometer=(int)$reading->odometer_km;
            $reading->delete();

            if($wasLatest&&(int)$ambulance->odometer_km===$deletedOdometer){
                $latestRemaining=MileageReading::where('ambulance_id',$ambulance->id)
                    ->latest('reading_date')->latest('id')->first();
                if($latestRemaining)$ambulance->update(['odometer_km'=>$latestRemaining->odometer_km]);
            }
        });
        return redirect()->route('ems.mileage')->with('success','Mileage reading deleted and mileage totals recalculated. The record remains preserved in the audit trail.');
    }

    public function storeAvailability(Request $request): RedirectResponse
    {
        if($request->has('checks')){
            $data=$this->validateAvailabilitySession($request);
            if(AvailabilityCheck::whereDate('check_date',$data['check_date'])->where('period',$data['period'])->exists()){
                throw ValidationException::withMessages(['period'=>'A '.$data['period'].' check session already exists for this date. Open that session and use Edit.']);
            }
            $sessionUuid=(string)Str::uuid();
            DB::transaction(function()use($data,$sessionUuid){foreach($data['checks'] as $check)AvailabilityCheck::create(
                $check+['session_uuid'=>$sessionUuid,'check_date'=>$data['check_date'],'period'=>$data['period'],'checked_at'=>$data['checked_at'],'recorded_by'=>auth()->id()]
            );});
            return back()->with('success',count($data['checks']).' availability checks saved for the session.');
        }
        $data=$request->validate(['check_date'=>'required|date|before_or_equal:today','period'=>'required|in:morning,afternoon','checked_at'=>'nullable|date_format:H:i','unit_name'=>'required|max:120','responded'=>'required|boolean','response_location'=>'nullable|max:160','observation'=>'nullable|max:1000']);
        AvailabilityCheck::create($data+['session_uuid'=>(string)Str::uuid(),'checked_at'=>$data['checked_at']??now()->format('H:i'),'recorded_by'=>auth()->id()]);
        return back()->with('success','Availability check saved.');
    }

    public function showAvailabilitySession(string $session)
    {
        $checks=$this->availabilitySession($session);
        return view('ems.availability.show',compact('checks','session'));
    }

    public function editAvailabilitySession(string $session)
    {
        $checks=$this->availabilitySession($session);
        return view('ems.availability.edit',compact('checks','session'));
    }

    public function updateAvailabilitySession(Request $request,string $session): RedirectResponse
    {
        $checks=$this->availabilitySession($session);
        $data=$this->validateAvailabilitySession($request,true);
        $duplicate=AvailabilityCheck::whereDate('check_date',$data['check_date'])->where('period',$data['period'])->where('session_uuid','!=',$session)->exists();
        if($duplicate)throw ValidationException::withMessages(['period'=>'Another '.$data['period'].' check session already exists for this date.']);

        $submitted=collect($data['checks'])->mapWithKeys(fn($row)=>[(int)$row['id']=>$row]);
        abort_unless($submitted->keys()->sort()->values()->all()===$checks->pluck('id')->sort()->values()->all(),422,'Every check in the session must be submitted.');
        DB::transaction(function()use($checks,$data,$submitted){foreach($checks as $check){$row=$submitted->get($check->id);$check->update([
            'check_date'=>$data['check_date'],'period'=>$data['period'],'checked_at'=>$data['checked_at'],'responded'=>$row['responded'],
            'response_location'=>$row['response_location']??null,'observation'=>$row['observation']??null,
        ]);}});
        return redirect()->route('ems.availability.sessions.show',$session)->with('success','Check session updated successfully.');
    }

    public function destroyAvailabilitySession(string $session): RedirectResponse
    {
        $checks=$this->availabilitySession($session);
        DB::transaction(fn()=> $checks->each->delete());
        return redirect()->route('ems.availability')->with('success','Check session deleted. Its history remains preserved in the audit trail.');
    }

    public function storeActivity(Request $request): RedirectResponse
    {
        $data=$this->validateActivity($request);
        WeeklyActivity::create($data+['created_by'=>auth()->id()]);
        return back()->with('success','Weekly activity recorded.');
    }

    public function showActivity(WeeklyActivity $activity)
    {
        return view('ems.activities.show',compact('activity'));
    }

    public function editActivity(WeeklyActivity $activity)
    {
        return view('ems.activities.edit',compact('activity'));
    }

    public function updateActivity(Request $request,WeeklyActivity $activity): RedirectResponse
    {
        $activity->update($this->validateActivity($request));
        return redirect()->route('ems.activities.show',$activity)->with('success','Activity updated successfully.');
    }

    public function destroyActivity(WeeklyActivity $activity): RedirectResponse
    {
        $activity->delete();
        return redirect()->route('ems.activities')->with('success','Activity deleted. Its history remains preserved in the audit trail.');
    }

    public function generateReport(Request $request): RedirectResponse
    {
        $request->merge(['period_preset'=>$request->input('period_preset','custom')]);
        $data=$request->validate([
            'type'=>['required',Rule::in(['mileage','weekly_activity','availability'])],
            'period_preset'=>['required',Rule::in(['today','yesterday','this_week','last_week','this_month','last_month','this_quarter','last_quarter','last_six_months','this_year','last_year','custom'])],
            'period_start'=>['nullable','required_if:period_preset,custom','date'],
            'period_end'=>['nullable','required_if:period_preset,custom','date','after_or_equal:period_start'],
        ]);
        [$periodStart,$periodEnd,$periodLabel]=$this->reportPeriodDates($data);
        [$snapshot,$summary,$recommendations]=$this->buildPrintableReport($data['type'],$periodStart,$periodEnd);
        if ($periodLabel !== null) {
            $snapshot['reporting_period_label']=$periodLabel;
        }
        $report=EmsReport::create([
            'type'=>$data['type'],
            'period_start'=>$periodStart,
            'period_end'=>$periodEnd,
            'uuid'=>(string)Str::uuid(),
            'status'=>'draft',
            'snapshot'=>$snapshot,
            'summary'=>$summary,
            'recommendations'=>$recommendations,
            'prepared_by'=>auth()->id(),
        ]);
        return redirect()->route('ems.reports.print',$report);
    }

    public function reportsDashboard(Request $request)
    {
        $filters = $this->reportFilters($request);
        $query = $this->filteredMovements($filters);
        $records = (clone $query)->oldest('requested_at')->get();
        $completed = $records->where('status', 'completed');
        $ambulances = Ambulance::orderBy('fleet_number')->get();
        $statusOrder = ['requested', 'completed'];
        $statusCounts = collect($statusOrder)->mapWithKeys(fn ($status) => [$status => $records->where('status', $status)->count()]);
        $dailyCounts = $records->groupBy(fn (Dispatch $movement) => $movement->requested_at->format('Y-m-d'))
            ->map->count()
            ->sortKeys();
        $maxDaily = max(1, (int) $dailyCounts->max());
        $availability = AvailabilityCheck::whereDate('check_date','>=',$filters['period_start'])->whereDate('check_date','<=',$filters['period_end'])->get();

        return view('ems.reports.dashboard', [
            'filters' => $filters,
            'ambulances' => $ambulances,
            'totalMovements' => $records->count(),
            'completedMovements' => $completed->count(),
            'activeMovements' => $records->whereIn('status', ['requested', 'dispatched', 'arrived'])->count(),
            'criticalMovements' => $records->where('priority', 'critical')->count(),
            'ambulancesUsed' => $records->pluck('ambulance_id')->filter()->unique()->count(),
            'totalAmbulances' => $ambulances->count(),
            'completionRate' => $records->isEmpty() ? 0 : round(($completed->count() / $records->count()) * 100, 1),
            'statusCounts' => $statusCounts,
            'dailyCounts' => $dailyCounts,
            'maxDaily' => $maxDaily,
            'availabilityChecks' => $availability->count(),
            'availabilityRate' => $availability->isEmpty() ? null : round(($availability->where('responded', true)->count() / $availability->count()) * 100, 1),
            'activityCount' => WeeklyActivity::whereDate('activity_date','>=',$filters['period_start'])->whereDate('activity_date','<=',$filters['period_end'])->count(),
        ]);
    }

    public function exportOperationsReport(Request $request)
    {
        $filters = $this->reportFilters($request);
        $movements = $this->filteredMovements($filters)->with('ambulance')->oldest('requested_at');
        $fileName = 'EMS-operations-'.$filters['period_start'].'-to-'.$filters['period_end'].'.csv';

        return response()->streamDownload(function () use ($movements) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Reference','Date','Ambulance','Registration','Origin','Destination','Case Category','Priority','Status']);
            $movements->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $movement) {
                    fputcsv($out, [$movement->reference,$movement->requested_at?->format('Y-m-d H:i'),$movement->ambulance?->fleet_number,$movement->ambulance?->registration_number,$movement->origin,$movement->destination,$movement->purpose,$movement->priority,$movement->status]);
                }
            });
            fclose($out);
        }, $fileName, ['Content-Type' => 'text/csv']);
    }

    public function printReport(EmsReport $report)
    {
        $report->load(['preparedBy','approvedBy']);
        return view('ems.report-print', compact('report'));
    }

    public function approveReport(EmsReport $report): RedirectResponse
    {
        abort_unless(in_array($report->status, ['draft','submitted'], true), 422, 'Only draft or submitted reports can be approved.');
        $report->update(['status'=>'approved','approved_by'=>auth()->id(),'approved_at'=>now()]);
        return back()->with('success','Report approved and its snapshot has been frozen.');
    }

    public function exportReport(EmsReport $report)
    {
        return response()->streamDownload(function()use($report){$out=fopen('php://output','w');$snapshot=$report->snapshot??[];$rows=match($report->type){'mileage'=>$snapshot['readings']??$snapshot,'availability'=>$snapshot['checks']??$snapshot,default=>$snapshot['activities']??$snapshot};if($rows!==[]&&isset($rows[0])&&is_array($rows[0])){fputcsv($out,array_keys($rows[0]));foreach($rows as $row)fputcsv($out,array_map(fn($v)=>is_array($v)?json_encode($v):$v,$row));}fclose($out);},'EMS-'.$report->type.'-'.$report->period_end->format('Y-m-d').'.csv',['Content-Type'=>'text/csv']);
    }

    public function audit()
    {
        return view('ems.audit',['logs'=>EmsAuditLog::with('user')->latest('created_at')->paginate(15)]);
    }

    public function exportAudit()
    {
        return response()->streamDownload(function(){$out=fopen('php://output','w');fputcsv($out,['Date','User','Branch','Action','Record Type','Record Reference','Previous Values','New Values','Route','Method','Path','Status']);EmsAuditLog::with('user')->latest('created_at')->chunk(500,function($logs)use($out){foreach($logs as $log)fputcsv($out,[$log->created_at,$log->user?->name,$log->branch_code,$log->action,$log->subject_type,$log->subject_reference,json_encode($log->old_values,JSON_UNESCAPED_SLASHES),json_encode($log->new_values,JSON_UNESCAPED_SLASHES),$log->route,$log->method,$log->path,$log->response_status]);});fclose($out);},'EMS-audit-'.today()->format('Y-m-d').'.csv',['Content-Type'=>'text/csv']);
    }

    private function validateAmbulance(Request $request, ?Ambulance $ambulance = null): array
    {
        $fleetNumber = strtoupper((string) preg_replace('/\s+/', ' ', trim((string) $request->input('fleet_number'))));
        if (preg_match('/^AMBU(?:LANCE)?[\s-]*([0-9]{1,3})$/i', $fleetNumber, $matches)) {
            $fleetNumber = 'AMBU '.(int) $matches[1];
        }

        $request->merge([
            'fleet_number' => $fleetNumber,
            'registration_number' => strtoupper((string) preg_replace('/\s+/', ' ', trim((string) $request->input('registration_number')))),
        ]);

        $registrationYear=function($attribute,$value,$fail)use($request){
            if(!preg_match('/-([0-9]{2})$/',(string)$value,$matches))return;
            $year=2000+(int)$matches[1];
            if($year>now()->year)$fail('The registration year cannot be in the future.');
            if($request->filled('year')&&$year<(int)$request->input('year'))$fail('The registration year cannot be earlier than the vehicle manufacture year.');
        };
        $validExpiry=function(string $column)use($ambulance){return function($attribute,$value,$fail)use($ambulance,$column){
            if(!$value)return;
            $existing=$ambulance?->{$column}?->toDateString();
            if(Carbon::parse($value)->isBefore(today())&&$value!==$existing)$fail('The '.$attribute.' must be today or a future date.');
        };};

        return $request->validate([
            'fleet_number' => [
                'required',
                'string',
                'max:30',
                'regex:/^AMBU(?:LANCE)?[\s-]?[0-9]{1,3}$/i',
                Rule::unique('ambulances', 'fleet_number')->ignore($ambulance?->id),
            ],
            'registration_number' => ['required', 'string', 'max:30', 'regex:/^[A-Z]{1,3} [0-9]{1,4}-[0-9]{2}$/', $registrationYear, Rule::unique('ambulances', 'registration_number')->ignore($ambulance?->id)],
            'make' => ['nullable', 'string', 'max:80', "regex:/^[\\pL\\pN .&()\\/'-]+$/u"],
            'model' => ['nullable', 'string', 'max:80', "regex:/^[\\pL\\pN .&()\\/'-]+$/u"],
            'year' => ['nullable', 'integer', 'min:1980', 'max:'.now()->year],
            'base_location' => ['required', Rule::in(config('ems.movement_locations'))],
            'odometer_km' => ['required', 'integer', 'min:0', 'max:9999999'],
            'roadworthy_expires_at' => ['nullable', 'date_format:Y-m-d', $validExpiry('roadworthy_expires_at')],
            'insurance_expires_at' => ['nullable', 'date_format:Y-m-d', $validExpiry('insurance_expires_at')],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'fleet_number.regex' => 'Enter a valid ambulance number such as AMBU 1 or AMBULANCE 1.',
            'fleet_number.unique' => 'This ambulance number has already been registered.',
            'registration_number.regex' => 'Enter a valid Ghana registration number such as GV 1234-26.',
            'registration_number.unique' => 'This vehicle registration number has already been registered.',
            'year.min' => 'The manufacture year must be 1980 or later.',
            'year.max' => 'The manufacture year cannot be in the future.',
            'base_location.in' => 'Select a valid predefined base location.',
        ]);
    }

    private function validateMovement(Request $request): array
    {
        return $request->validate([
            'ambulance_id'=>['required','exists:ambulances,id'],
            'priority'=>['required',Rule::in(['routine','urgent','critical'])],
            'requested_at'=>['required','date','before_or_equal:now'],
            'status'=>['required',Rule::in(['requested','completed'])],
            'origin'=>['required',Rule::in(config('ems.movement_locations'))],
            'destination'=>['required','different:origin',Rule::in(config('ems.movement_locations'))],
            'purpose'=>['required',Rule::in(config('ems.case_categories'))],
            'notes'=>['nullable','string','max:2000'],
        ], ['destination.different'=>'The destination must be different from the origin.','requested_at.before_or_equal'=>'The movement date and time cannot be in the future.']);
    }

    private function validateMileage(Request $request,?MileageReading $reading=null): array
    {
        $data=$request->validate([
            'ambulance_id'=>['required','integer','exists:ambulances,id'],
            'reading_date'=>['required','date','before_or_equal:today'],
            'odometer_km'=>['required','integer','min:0','max:9999999'],
            'source'=>['required',Rule::in(['weekly','service'])],
            'notes'=>['nullable','string','max:1000'],
        ]);
        $ambulance=Ambulance::findOrFail($data['ambulance_id']);
        $duplicate=MileageReading::where('ambulance_id',$ambulance->id)
            ->whereDate('reading_date',$data['reading_date'])->where('source',$data['source'])
            ->when($reading,fn($query)=>$query->where('id','!=',$reading->id))->exists();
        if($duplicate)throw ValidationException::withMessages(['reading_date'=>'A '.$data['source'].' reading already exists for this ambulance on this date.']);
        $previous=MileageReading::where('ambulance_id',$ambulance->id)->whereDate('reading_date','<',$data['reading_date'])
            ->when($reading,fn($query)=>$query->where('id','!=',$reading->id))->latest('reading_date')->first();
        $next=MileageReading::where('ambulance_id',$ambulance->id)->whereDate('reading_date','>',$data['reading_date'])
            ->when($reading,fn($query)=>$query->where('id','!=',$reading->id))->oldest('reading_date')->first();
        if($previous&&$data['odometer_km']<$previous->odometer_km)throw ValidationException::withMessages(['odometer_km'=>'The reading cannot be lower than the previous reading of '.number_format($previous->odometer_km).' km.']);
        if($next&&$data['odometer_km']>$next->odometer_km)throw ValidationException::withMessages(['odometer_km'=>'The reading cannot exceed the next recorded reading of '.number_format($next->odometer_km).' km.']);
        if(!$next&&$data['reading_date']===today()->toDateString()&&$data['odometer_km']<$ambulance->odometer_km)throw ValidationException::withMessages(['odometer_km'=>'Today’s reading cannot be lower than the current ambulance odometer of '.number_format($ambulance->odometer_km).' km.']);
        return [$data,$ambulance,$next];
    }

    private function reportFilters(Request $request): array
    {
        $preset=$request->filled('period_preset')
            ? $request->input('period_preset')
            : ($request->filled('period_start') || $request->filled('period_end') ? 'custom' : 'this_week');
        $request->merge(['period_preset'=>$preset]);
        $filters=$request->validate([
            'period_preset'=>['required',Rule::in(['today','yesterday','this_week','last_week','this_month','last_month','this_quarter','last_quarter','last_six_months','this_year','last_year','custom'])],
            'period_start'=>['nullable','required_if:period_preset,custom','date'],
            'period_end'=>['nullable','required_if:period_preset,custom','date','after_or_equal:period_start'],
            'ambulance_id' => ['nullable', 'integer', 'exists:ambulances,id'],
            'status' => ['nullable', Rule::in(['requested','completed'])],
        ]);
        [$periodStart,$periodEnd,$periodLabel]=$this->reportPeriodDates($filters);

        return array_merge($filters,[
            'period_start'=>$periodStart,
            'period_end'=>$periodEnd,
            'period_label'=>$periodLabel??'Custom Dates',
        ]);
    }

    private function filteredMovements(array $filters)
    {
        return Dispatch::query()
            ->whereBetween('requested_at', [$filters['period_start'].' 00:00:00', $filters['period_end'].' 23:59:59'])
            ->when(filled($filters['ambulance_id'] ?? null), fn ($query) => $query->where('ambulance_id', $filters['ambulance_id']))
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->where('status', $filters['status']));
    }

    private function buildPrintableReport(string $type, string $periodStart, string $periodEnd): array
    {
        return match ($type) {
            'mileage' => $this->buildMileageReport($periodStart, $periodEnd),
            'availability' => $this->buildAvailabilityReport($periodStart, $periodEnd),
            default => $this->buildWeeklyOperationsReport($periodStart, $periodEnd),
        };
    }

    private function reportPeriodDates(array $data): array
    {
        if ($data['period_preset'] === 'custom') {
            return [$data['period_start'],$data['period_end'],null];
        }

        $today = today();
        [$start,$end] = match ($data['period_preset']) {
            'today' => [$today->copy(),$today->copy()],
            'yesterday' => [$today->copy()->subDay(),$today->copy()->subDay()],
            'this_week' => [$today->copy()->startOfWeek(Carbon::SUNDAY),$today->copy()],
            'last_week' => [$today->copy()->subWeek()->startOfWeek(Carbon::SUNDAY),$today->copy()->subWeek()->endOfWeek(Carbon::SATURDAY)],
            'this_month' => [$today->copy()->startOfMonth(),$today->copy()],
            'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(),$today->copy()->subMonthNoOverflow()->endOfMonth()],
            'this_quarter' => [$today->copy()->startOfQuarter(),$today->copy()],
            'last_quarter' => [$today->copy()->subQuarter()->startOfQuarter(),$today->copy()->subQuarter()->endOfQuarter()],
            'last_six_months' => [$today->copy()->startOfMonth()->subMonths(6),$today->copy()->startOfMonth()->subDay()],
            'this_year' => [$today->copy()->startOfYear(),$today->copy()],
            'last_year' => [$today->copy()->subYear()->startOfYear(),$today->copy()->subYear()->endOfYear()],
        };

        $label=match($data['period_preset']){
            'today'=>'Today',
            'yesterday'=>'Yesterday',
            'this_week'=>'This Week',
            'last_week'=>'Last Week',
            'this_month'=>'This Month',
            'last_month'=>'Last Month',
            'this_quarter'=>'This Quarter',
            'last_quarter'=>'Last Quarter',
            'last_six_months'=>'Last 6 Months',
            'this_year'=>'This Year',
            'last_year'=>'Last Year',
        };

        return [$start->toDateString(),$end->toDateString(),$label];
    }

    private function buildMileageReport(string $periodStart, string $periodEnd): array
    {
        $readings = MileageReading::with('ambulance')->whereDate('reading_date','>=',$periodStart)->whereDate('reading_date','<=',$periodEnd)->oldest('reading_date')->get();
        $weeklyReadings = $readings->where('source','weekly');
        $readingRows = $readings->map(fn (MileageReading $reading) => [
            'date' => $reading->reading_date->toDateString(),
            'ambulance' => $reading->ambulance?->fleet_number ?? 'Unknown',
            'registration' => $reading->ambulance?->registration_number ?? '—',
            'odometer_km' => $reading->odometer_km,
            'source' => $reading->source,
        ])->values()->all();
        $ambulanceNames = $weeklyReadings->pluck('ambulance.fleet_number')->filter()->unique()->sort()->values();
        $readingMatrix = $weeklyReadings->groupBy(fn (MileageReading $reading) => $reading->reading_date->toDateString())->map(function ($rows,$date) use($ambulanceNames) {
            $values=$ambulanceNames->mapWithKeys(fn($name)=>[$name=>$rows->firstWhere('ambulance.fleet_number',$name)?->odometer_km]);
            return ['date'=>$date,'values'=>$values->all()];
        })->sortBy('date')->values()->all();
        $weekly = $weeklyReadings->groupBy('ambulance_id')->flatMap(function ($ambulanceReadings) {
            $ordered=$ambulanceReadings->sortBy('reading_date')->values();
            return $ordered->map(function (MileageReading $opening,int $index) use($ordered) {
                $closing=$ordered->get($index+1);
                if(!$closing)return null;
                $days=max(1,$opening->reading_date->diffInDays($closing->reading_date));
                $distance=max(0,$closing->odometer_km-$opening->odometer_km);
                return ['week_start'=>$opening->reading_date->toDateString(),'week_end'=>$closing->reading_date->copy()->subDay()->toDateString(),'ambulance'=>$opening->ambulance?->fleet_number??'Unknown','start_odometer'=>$opening->odometer_km,'end_odometer'=>$closing->odometer_km,'distance_km'=>$distance,'daily_average_km'=>round($distance/$days,2),'days'=>$days];
            })->filter();
        })->sortBy('week_start')->values();
        $totalDistance = $weekly->sum('distance_km');
        $summary = [
            number_format($weeklyReadings->count()).' scheduled odometer readings were captured across '.number_format($ambulanceNames->count()).' ambulances.',
            number_format($weekly->count()).' weekly mileage intervals covered '.number_format($totalDistance).' km from consecutive odometer readings.',
            'Weekly and daily-average kilometres were calculated directly from consecutive readings.',
        ];
        $recommendations = [
            'Continue taking scheduled weekly odometer readings for every ambulance on the same day each week.',
            'Review unusually high or low weekly mileage and confirm operational reasons.',
            'Use recorded mileage trends to plan preventive maintenance and balance fleet utilisation.',
        ];
        return [[
            'readings' => $readingRows,
            'ambulances' => $ambulanceNames->all(),
            'reading_matrix' => $readingMatrix,
            'weekly_summaries' => $weekly->all(),
            'total_distance_km' => $totalDistance,
        ], $summary, $recommendations];
    }

    private function buildAvailabilityReport(string $periodStart, string $periodEnd): array
    {
        $checks = AvailabilityCheck::whereDate('check_date','>=',$periodStart)->whereDate('check_date','<=',$periodEnd)->oldest('check_date')->orderBy('checked_at')->orderBy('unit_name')->get();
        $rows = $checks->map(fn (AvailabilityCheck $check) => [
            'session_uuid' => $check->session_uuid,
            'date' => Carbon::parse($check->check_date)->toDateString(),
            'period' => $check->period,
            'time' => $check->checked_at,
            'unit' => $check->unit_name,
            'responded' => (bool) $check->responded,
            'response_location' => $check->response_location,
            'observation' => $check->observation,
        ])->values()->all();
        $responded = $checks->where('responded', true)->count();
        $negativeUnits = $checks->where('responded', false)->pluck('unit_name')->unique()->values();
        $rate = $checks->isEmpty() ? 0 : round(($responded / $checks->count()) * 100, 1);
        $summary = [
            number_format($checks->count()).' radio communication and availability checks were recorded.',
            number_format($responded).' checks received a response, representing '.$rate.'% availability.',
            $negativeUnits->isEmpty() ? 'No negative responses were recorded.' : 'Negative responses were recorded for: '.$negativeUnits->join(', ').'.',
        ];
        $recommendations = [
            'Continue scheduled morning and afternoon communication checks.',
            $negativeUnits->isEmpty() ? 'Maintain radio equipment and response discipline across all units.' : 'Investigate radio, staffing, or equipment issues affecting: '.$negativeUnits->join(', ').'.',
            'Use repeated negative-response trends to prioritise corrective action and equipment replacement.',
        ];
        return [['checks' => $rows, 'response_rate' => $rate, 'responded' => $responded, 'negative' => $checks->count() - $responded], $summary, $recommendations];
    }

    private function availabilitySession(string $session)
    {
        $checks=AvailabilityCheck::where('session_uuid',$session)->orderBy('unit_name')->get();
        abort_if($checks->isEmpty(),404);
        return $checks;
    }

    private function validateAvailabilitySession(Request $request,bool $editing=false): array
    {
        return $request->validate([
            'check_date'=>'required|date|before_or_equal:today',
            'period'=>'required|in:morning,afternoon',
            'checked_at'=>'required|date_format:H:i',
            'checks'=>'required|array|min:1',
            'checks.*.id'=>$editing?'required|integer|exists:availability_checks,id':'prohibited',
            'checks.*.unit_name'=>$editing?'prohibited':'required|string|max:120',
            'checks.*.responded'=>'required|boolean',
            'checks.*.response_location'=>'nullable|string|max:160',
            'checks.*.observation'=>'nullable|string|max:1000',
        ]);
    }

    private function validateActivity(Request $request): array
    {
        $data=$request->validate([
            'activity_date'=>'required|date|before_or_equal:today','category'=>'required|in:operations,meeting,training,inspection,administration,outreach',
            'description'=>'required|string|max:12000','outcome'=>'nullable|string|max:6000','requires_follow_up'=>'nullable|boolean',
            'follow_up_action'=>'nullable|required_if:requires_follow_up,1|max:2000','follow_up_owner'=>'nullable|required_if:requires_follow_up,1|max:160',
            'follow_up_due_date'=>'nullable|date|after_or_equal:activity_date',
        ]);
        $data['description']=RichText::clean($data['description']);
        $data['outcome']=RichText::clean($data['outcome']??null);
        if(RichText::plain($data['description'])==='')throw ValidationException::withMessages(['description'=>'Please enter the activity details.']);
        $data['title']=str(RichText::plain($data['description']))->before('.')->squish()->limit(120)->toString();
        $data['requires_follow_up']=$request->boolean('requires_follow_up');
        if(!$data['requires_follow_up']){
            $data['follow_up_action']=null;$data['follow_up_owner']=null;$data['follow_up_due_date']=null;
        }
        return $data;
    }

    private function buildWeeklyOperationsReport(string $periodStart, string $periodEnd): array
    {
        $activities = WeeklyActivity::whereDate('activity_date','>=',$periodStart)->whereDate('activity_date','<=',$periodEnd)->get()->map(fn (WeeklyActivity $activity) => [
            'date' => Carbon::parse($activity->activity_date)->toDateString(),
            'category' => $activity->category,
            'title' => $activity->title,
            'description' => $activity->description,
            'location' => $activity->location,
            'participants' => $activity->participants,
            'outcome' => $activity->outcome,
            'requires_follow_up' => (bool) $activity->requires_follow_up,
            'follow_up_action' => $activity->follow_up_action,
            'follow_up_owner' => $activity->follow_up_owner,
            'follow_up_due_date' => $activity->follow_up_due_date?->toDateString(),
        ]);
        $rows = $activities->sortBy('date')->values();
        $followUps = $rows->where('requires_follow_up', true)->count();
        $categoryCounts=$activities->groupBy('category')->map->count();
        $summary = [
            number_format($activities->count()).' departmental activities and key engagements were recorded.',
            number_format($categoryCounts->get('meeting',0)).' meetings, '.number_format($categoryCounts->get('training',0)).' training activities, and '.number_format($categoryCounts->get('inspection',0)).' inspections were documented.',
            number_format($followUps).' activities require follow-up action.',
        ];
        $capturedActions=$activities->where('requires_follow_up',true)->pluck('follow_up_action')->filter()->unique()->values();
        $recommendations = $capturedActions->take(5)->values()->all();
        $recommendations = array_values(array_unique(array_merge($recommendations, [
            'Continue documenting significant daily activities, decisions, and operational issues.',
            'Review recurring activity themes to guide staffing, training, and equipment planning.',
            'Escalate unresolved operational issues to the EMS Manager with clear owners and target dates.',
        ])));
        return [['activities' => $rows->all(), 'total_activities' => $activities->count(), 'meetings' => $categoryCounts->get('meeting',0), 'training' => $categoryCounts->get('training',0), 'follow_ups' => $followUps], $summary, $recommendations];
    }
}
