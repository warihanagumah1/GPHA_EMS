<?php

namespace App\Http\Controllers;

use App\Application\Sso\PermissionService;
use App\Mail\ReportReadyForApproval;
use Carbon\Carbon;
use App\Models\Ambulance;
use App\Models\AvailabilityCheck;
use App\Models\AvailabilityUnit;
use App\Models\Dispatch;
use App\Models\EmsReport;
use App\Models\EmsAuditLog;
use App\Models\MileageReading;
use App\Models\Location;
use App\Models\WeeklyActivity;
use App\Support\RichText;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class EmsOperationsController extends Controller
{
    public function dashboard(Request $request)
    {
        return view('ems.dashboard', $this->analyticsData($request));
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
                'priority' => ['nullable',Rule::in(array_keys(config('ems.movement_priorities')))],
                'purpose' => ['nullable',Rule::in(config('ems.case_categories'))],
                'origin' => ['nullable','string','max:160'],
                'destination' => ['nullable','string','max:160'],
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
                'period' => ['nullable',Rule::in(['morning','afternoon','evening'])],
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
                ->when(($activityFilters['requires_follow_up'] ?? '') !== '', fn ($query) => $query->where('requires_follow_up',(bool) $activityFilters['requires_follow_up']))
                ->when(filled($activityFilters['date_from'] ?? null), fn ($query) => $query->whereDate('activity_date','>=',$activityFilters['date_from']))
                ->when(filled($activityFilters['date_to'] ?? null), fn ($query) => $query->whereDate('activity_date','<=',$activityFilters['date_to']))
                ->latest('activity_date')->latest('id')->paginate(15)->withQueryString();
        }

        $ambulances = Ambulance::orderBy('fleet_number')->get();
        $activeAvailabilityUnitNames = $module === 'availability'
            ? AvailabilityUnit::where('is_active',true)->orderBy('name')->pluck('name')
            : collect();
        $locations = Location::activeNames();
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
            'availabilityUnits' => collect($ambulances->pluck('fleet_number'))->merge($activeAvailabilityUnitNames)->unique()->values(),
            'locations' => $locations,
        ]);
    }

    public function storeAmbulance(Request $request): RedirectResponse
    {
        $data = $this->validateAmbulance($request);
        Ambulance::create($data+['uuid'=>(string)Str::uuid(),'status'=>'available']);
        return redirect()->route('ems.ambulances')->with('success','Ambulance added successfully.');
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
        $locations = Location::activeNames()->push($ambulance->base_location)->filter()->unique()->sort()->values();

        return view('ems.ambulances.edit', compact('ambulance', 'locations'));
    }

    public function updateAmbulance(Request $request, Ambulance $ambulance): RedirectResponse
    {
        $data = $this->validateAmbulance($request, $ambulance);

        if ($ambulance->mileageReadings()->exists() && $data['odometer_km'] < $ambulance->odometer_km) {
            throw ValidationException::withMessages([
                'odometer_km' => 'The odometer cannot be reduced because mileage readings already exist. Correct or delete the relevant mileage reading instead.',
            ]);
        }

        $ambulance->update($data);

        return redirect()->route('ems.ambulances')->with('success', 'Ambulance updated successfully.');
    }

    public function destroyAmbulance(Ambulance $ambulance): RedirectResponse
    {
        $hasActiveMovement = $ambulance->dispatches()->whereNotIn('status', ['completed', 'cancelled'])->exists();

        if ($hasActiveMovement) {
            throw ValidationException::withMessages([
                'ambulance' => 'This ambulance has an active movement and cannot be deleted yet.',
            ]);
        }

        $ambulance->delete();

        return redirect()->route('ems.ambulances')->with('success', 'Ambulance deleted successfully.');
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
            'locations' => Location::activeNames(),
        ]);
    }

    public function updateDispatch(Request $request, Dispatch $dispatch): RedirectResponse
    {
        $data = $this->validateMovement($request, $dispatch);
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

        return redirect()->route('ems.dispatches')->with('success', 'Movement updated successfully.');
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

        return redirect()->route('ems.dispatches')->with('success','Movement deleted successfully.');
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
        [$data,$ambulance,$next,$deletedDuplicate]=$this->validateMileage($request);
        DB::transaction(function() use($data,$ambulance,$next,$deletedDuplicate){
            if($deletedDuplicate){
                $deletedDuplicate->restore();
                $deletedDuplicate->update($data+['recorded_by'=>auth()->id()]);
            }else{
                MileageReading::create($data+['recorded_by'=>auth()->id()]);
            }
            if(!$next && $data['odometer_km']>=$ambulance->odometer_km)$ambulance->update(['odometer_km'=>$data['odometer_km']]);
        });
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
        return redirect()->route('ems.mileage')->with('success','Mileage reading updated successfully.');
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
        return redirect()->route('ems.mileage')->with('success','Mileage reading deleted and mileage totals recalculated.');
    }

    public function storeAvailability(Request $request): RedirectResponse
    {
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

    public function showAvailabilitySession(string $session)
    {
        $checks=$this->availabilitySession($session);
        return view('ems.availability.show',compact('checks','session'));
    }

    public function editAvailabilitySession(string $session)
    {
        $checks=$this->availabilitySession($session);
        $locations = Location::activeNames()->merge($checks->pluck('response_location'))->filter()->unique()->sort()->values();

        return view('ems.availability.edit',compact('checks','session','locations'));
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
        return redirect()->route('ems.availability')->with('success','Check session updated successfully.');
    }

    public function destroyAvailabilitySession(string $session): RedirectResponse
    {
        $checks=$this->availabilitySession($session);
        DB::transaction(fn()=> $checks->each->delete());
        return redirect()->route('ems.availability')->with('success','Check session deleted successfully.');
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
        $activity->update($this->validateActivity($request,$activity));
        return redirect()->route('ems.activities')->with('success','Activity updated successfully.');
    }

    public function destroyActivity(WeeklyActivity $activity): RedirectResponse
    {
        $activity->delete();
        return redirect()->route('ems.activities')->with('success','Activity deleted successfully.');
    }

    public function generateReport(Request $request): RedirectResponse
    {
        $data = $this->validateReportDefinition($request);
        [$periodStart,$periodEnd,$periodLabel]=$this->reportPeriodDates($data);
        [$snapshot,$summary,$recommendations]=$this->buildPrintableReport($data['type'],$periodStart,$periodEnd);
        $snapshot['reporting_period_label']=$periodLabel??'Custom Dates';
        $snapshot['reporting_period_cadence']=$this->reportCadence($data['period_preset'],$periodStart,$periodEnd);
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
        $reportFilters = $request->validate([
            'report_status' => ['nullable', Rule::in(['draft','submitted','approved'])],
            'report_type' => ['nullable', Rule::in(['mileage','weekly_activity','availability'])],
            'report_date_from' => ['nullable', 'required_with:report_date_to', 'date_format:Y-m-d'],
            'report_date_to' => ['nullable', 'required_with:report_date_from', 'date_format:Y-m-d', 'after_or_equal:report_date_from'],
            'report_tab' => ['nullable', Rule::in(['all','submitted','approved'])],
        ]);
        $approverTabs = app(PermissionService::class)->allows('EMSReports', 'Approve');
        $activeReportTab = $approverTabs ? ($reportFilters['report_tab'] ?? 'all') : null;
        $reports = EmsReport::with(['preparedBy','submittedBy','approvedBy'])
            ->when(!$approverTabs, fn ($query) => $query->where(fn ($query) => $query
                ->where('prepared_by', auth()->id())
                ->orWhere('submitted_by', auth()->id())))
            ->when($approverTabs && $activeReportTab !== 'all', fn ($query) => $query->where('status', $activeReportTab))
            ->when(!$approverTabs && filled($reportFilters['report_status'] ?? null), fn ($query) => $query->where('status', $reportFilters['report_status']))
            ->when(filled($reportFilters['report_type'] ?? null), fn ($query) => $query->where('type', $reportFilters['report_type']))
            ->when(filled($reportFilters['report_date_from'] ?? null), fn ($query) => $query->whereDate('created_at', '>=', $reportFilters['report_date_from']))
            ->when(filled($reportFilters['report_date_to'] ?? null), fn ($query) => $query->whereDate('created_at', '<=', $reportFilters['report_date_to']))
            ->latest('created_at')
            ->paginate(15)
            ->withQueryString();
        [$periodStart, $periodEnd] = $this->reportPeriodDates(['period_preset' => 'this_week']);

        return view('ems.reports.dashboard', [
            'reports' => $reports,
            'reportFilters' => $reportFilters,
            'approverTabs' => $approverTabs,
            'activeReportTab' => $activeReportTab,
            'generationPeriod' => ['period_start' => $periodStart, 'period_end' => $periodEnd],
        ]);
    }

    public function editReport(EmsReport $report)
    {
        $this->ensureReportIsMutableByPreparer($report);

        return view('ems.reports.edit', compact('report'));
    }

    public function updateReport(Request $request, EmsReport $report): RedirectResponse
    {
        $this->ensureReportIsMutableByPreparer($report);
        $data = $this->validateReportDefinition($request);
        [$periodStart, $periodEnd, $periodLabel] = $this->reportPeriodDates($data);
        [$snapshot, $summary, $recommendations] = $this->buildPrintableReport($data['type'], $periodStart, $periodEnd);
        $snapshot['reporting_period_label'] = $periodLabel ?? 'Custom Dates';
        $snapshot['reporting_period_cadence'] = $this->reportCadence($data['period_preset'], $periodStart, $periodEnd);

        if ($report->status === 'submitted') {
            Storage::disk('local')->delete(array_filter([$report->submitter_signature_path]));
        }

        $report->update([
            'type' => $data['type'],
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'status' => 'draft',
            'snapshot' => $snapshot,
            'summary' => $summary,
            'recommendations' => $recommendations,
            'submitted_by' => null,
            'submitted_at' => null,
            'submitter_signature_method' => null,
            'submitter_signature_path' => null,
            'approved_by' => null,
            'approved_by_name' => null,
            'approved_by_email' => null,
            'approved_at' => null,
            'approver_signature_method' => null,
            'approver_signature_path' => null,
            'signed_report_path' => null,
        ]);

        return redirect()->route('ems.reports')->with([
            'success' => 'Report updated and returned to Draft.',
            'success_report_uuid' => $report->uuid,
        ]);
    }

    public function destroyReport(EmsReport $report): RedirectResponse
    {
        $this->ensureReportIsMutableByPreparer($report);
        Storage::disk('local')->deleteDirectory('ems-report-signatures/'.$report->uuid);
        $report->delete();

        return redirect()->route('ems.reports')->with('success', 'Report deleted successfully.');
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
                    fputcsv($out, [$movement->reference,$movement->requested_at?->format('Y-m-d H:i'),$movement->ambulance?->fleet_number,$movement->ambulance?->registration_number,$movement->origin,$movement->destination,$movement->purpose,config("ems.movement_priorities.{$movement->priority}", str($movement->priority)->headline()),str($movement->status)->headline()]);
                }
            });
            fclose($out);
        }, $fileName, ['Content-Type' => 'text/csv']);
    }

    public function printReport(EmsReport $report)
    {
        $report->load(['preparedBy','submittedBy','approvedBy']);
        return view('ems.report-print', compact('report'));
    }

    public function guestApproval(Request $request, EmsReport $report)
    {
        abort_unless(in_array($report->status, ['submitted', 'approved'], true), 404);
        $report->load(['preparedBy','submittedBy','approvedBy']);
        $approver = $this->reportApprover($request->query('approver'));

        return view('ems.report-print', [
            'report' => $report,
            'guestApproval' => true,
            'guestApproveUrl' => $report->status === 'submitted'
                ? $this->temporaryReportUrl('ems.reports.guest-approve', $report, ['approver' => $approver['email']])
                : null,
        ]);
    }

    public function submitReport(Request $request, EmsReport $report): RedirectResponse
    {
        abort_unless($report->status === 'draft', 422, 'Only draft reports can be submitted.');
        abort_unless((int) $report->prepared_by === (int) auth()->id(), 403, 'Only the person who prepared this report can sign and submit it.');

        $signature = $this->storeReportSignature($request, $report, 'submitter');
        $report->update([
            'status' => 'submitted',
            'submitted_by' => auth()->id(),
            'submitted_at' => now(),
            'submitter_signature_method' => $signature['method'],
            'submitter_signature_path' => $signature['signature_path'],
        ]);
        $this->sendReportApprovalEmail($report->fresh(['preparedBy','submittedBy']));

        return redirect()->route('ems.reports.print', $report)->with('success', 'Report signed and submitted for approval.');
    }

    public function approveReport(Request $request, EmsReport $report): RedirectResponse
    {
        abort_unless($report->status === 'submitted', 422, 'Only submitted reports can be approved.');

        $signature = $this->storeReportSignature($request, $report, 'approver');
        $report->update([
            'status' => 'approved',
            'approved_by' => auth()->id(),
            'approved_by_name' => auth()->user()?->name,
            'approved_by_email' => auth()->user()?->email,
            'approved_at' => now(),
            'approver_signature_method' => $signature['method'],
            'approver_signature_path' => $signature['signature_path'],
            'signed_report_path' => $signature['signed_report_path'],
        ]);
        return back()->with('success','Report approved successfully.');
    }

    public function guestApproveReport(Request $request, EmsReport $report): RedirectResponse
    {
        abort_unless($report->status === 'submitted', 422, 'Only submitted reports can be approved.');

        $signature = $this->storeReportSignature($request, $report, 'approver');
        $approver = $this->reportApprover($request->query('approver'));
        $report->update([
            'status' => 'approved',
            'approved_by' => null,
            'approved_by_name' => $approver['name'],
            'approved_by_email' => $approver['email'],
            'approved_at' => now(),
            'approver_signature_method' => $signature['method'],
            'approver_signature_path' => $signature['signature_path'],
            'signed_report_path' => $signature['signed_report_path'],
        ]);

        return redirect($this->temporaryReportUrl('ems.reports.guest-approval', $report, ['approver' => $approver['email']]))
            ->with('success', 'Report approved successfully.');
    }

    public function reportFile(EmsReport $report, string $file)
    {
        return $this->reportFileResponse($report, $file);
    }

    public function guestReportFile(EmsReport $report, string $file)
    {
        abort_unless(in_array($report->status, ['submitted', 'approved'], true), 404);

        return $this->reportFileResponse($report, $file);
    }

    private function reportFileResponse(EmsReport $report, string $file)
    {
        $path = match ($file) {
            'submitter-signature' => $report->submitter_signature_path,
            'approver-signature' => $report->approver_signature_path,
            'signed-report' => $report->signed_report_path,
        };
        abort_if(blank($path) || !Storage::disk('local')->exists($path), 404);

        if ($file === 'signed-report') {
            return Storage::disk('local')->download($path, 'EMS-signed-report-'.$report->uuid.'.pdf');
        }

        return Storage::disk('local')->response($this->normalizedSignaturePath($path));
    }

    private function temporaryReportUrl(string $route, EmsReport $report, array $parameters = []): string
    {
        return URL::temporarySignedRoute(
            $route,
            now()->addHours((int) config('ems.report_approval_link_hours', 72)),
            ['report' => $report, ...$parameters],
        );
    }

    private function storeReportSignature(Request $request, EmsReport $report, string $party): array
    {
        if ($request->hasFile('signed_report')) {
            throw ValidationException::withMessages(['signed_report' => 'Upload a signature image or draw your signature instead.']);
        }
        $data = $request->validate([
            'signature_data' => ['nullable','string','max:3000000'],
            'signature_file' => ['nullable','file','mimes:png,jpg,jpeg','max:2048','dimensions:max_width=5000,max_height=5000'],
            'signature_confirmation' => ['accepted'],
        ], [
            'signature_confirmation.accepted' => 'Confirm that this signature belongs to you and that you accept responsibility for this report.',
            'signature_file.mimes' => 'Upload a PNG or JPG signature image.',
        ]);

        $signaturePath = null;
        $directory = 'ems-report-signatures/'.$report->uuid;
        $method = match (true) {
            $request->hasFile('signature_file') => 'upload',
            filled($data['signature_data'] ?? null) => 'drawn',
            default => null,
        };
        if ($method === null) {
            throw ValidationException::withMessages(['signature_data' => 'Draw your signature or upload a signature file before continuing.']);
        }

        if ($method === 'drawn') {
            $signaturePath = $this->storeDrawnSignature((string) ($data['signature_data'] ?? ''), $directory, $party);
        } elseif ($method === 'upload') {
            $extension = $request->file('signature_file')->extension();
            $signaturePath = $request->file('signature_file')->storeAs($directory, $party.'-'.Str::uuid().'.'.$extension, 'local');
            if (!$signaturePath) {
                throw ValidationException::withMessages(['signature_file' => 'The signature could not be saved. Please try again.']);
            }
        }

        return [
            'method' => $method,
            'signature_path' => $signaturePath,
            'signed_report_path' => null,
        ];
    }

    private function normalizedSignaturePath(string $path): string
    {
        $disk = Storage::disk('local');
        $normalizedPath = $path.'.normalized.png';
        if ($disk->exists($normalizedPath) && $disk->lastModified($normalizedPath) >= $disk->lastModified($path)) {
            return $normalizedPath;
        }

        $binary = $disk->get($path);
        $dimensions = @getimagesizefromstring($binary);
        if (!function_exists('imagecreatefromstring') || !$dimensions || $dimensions[0] > 5000 || $dimensions[1] > 5000) {
            return $path;
        }
        $image = @imagecreatefromstring($binary);
        if (!$image) {
            return $path;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        if ($width > 2000 || $height > 1200) {
            $scale = min(2000 / $width, 1200 / $height);
            $scaled = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)), IMG_BILINEAR_FIXED);
            if ($scaled) {
                imagedestroy($image);
                $image = $scaled;
                $width = imagesx($image);
                $height = imagesy($image);
            }
        }
        $left = $width;
        $top = $height;
        $right = -1;
        $bottom = -1;
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $pixel = imagecolorat($image, $x, $y);
                $alpha = ($pixel >> 24) & 0x7f;
                $red = ($pixel >> 16) & 0xff;
                $green = ($pixel >> 8) & 0xff;
                $blue = $pixel & 0xff;
                if ($alpha < 120 && ($red < 245 || $green < 245 || $blue < 245)) {
                    $left = min($left, $x);
                    $top = min($top, $y);
                    $right = max($right, $x);
                    $bottom = max($bottom, $y);
                }
            }
        }
        if ($right < $left || $bottom < $top) {
            imagedestroy($image);
            return $path;
        }

        $padding = max(6, (int) round(max($right - $left + 1, $bottom - $top + 1) * 0.06));
        $left = max(0, $left - $padding);
        $top = max(0, $top - $padding);
        $right = min($width - 1, $right + $padding);
        $bottom = min($height - 1, $bottom + $padding);
        $croppedWidth = $right - $left + 1;
        $croppedHeight = $bottom - $top + 1;
        $cropped = imagecreatetruecolor($croppedWidth, $croppedHeight);
        imagealphablending($cropped, false);
        imagesavealpha($cropped, true);
        $transparent = imagecolorallocatealpha($cropped, 255, 255, 255, 127);
        imagefill($cropped, 0, 0, $transparent);
        imagecopy($cropped, $image, 0, 0, $left, $top, $croppedWidth, $croppedHeight);
        ob_start();
        imagepng($cropped, null, 6);
        $normalized = ob_get_clean();
        imagedestroy($cropped);
        imagedestroy($image);
        if ($normalized !== false) {
            $disk->put($normalizedPath, $normalized);
            return $normalizedPath;
        }

        return $path;
    }

    private function sendReportApprovalEmail(EmsReport $report): void
    {
        $approvers = $this->reportApprovers();
        if ($approvers === []) {
            Log::error('No valid EMS report approval email addresses are configured.');
            return;
        }

        foreach ($approvers as $approver) {
            try {
                Mail::to($approver['email'])->send(new ReportReadyForApproval($report, $approver['name'], $approver['email']));
            } catch (Throwable $exception) {
                Log::error('EMS report approval email could not be sent.', [
                    'report_uuid' => $report->uuid,
                    'recipient' => $approver['email'],
                    'reason' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function reportApprover(?string $email = null): array
    {
        $approvers = $this->reportApprovers();
        abort_if($approvers === [], 500, 'No report approver is configured.');

        if (filled($email)) {
            $approver = collect($approvers)->first(fn (array $item): bool => strcasecmp($item['email'], $email) === 0);
            abort_unless($approver, 403, 'This approval recipient is not configured.');
            return $approver;
        }

        return $approvers[0];
    }

    private function reportApprovers(): array
    {
        $configured = (string) config('ems.report_approvers', '');
        $approvers = [];

        foreach (str_getcsv($configured) as $mailbox) {
            $mailbox = trim($mailbox);
            if ($mailbox === '') continue;

            if (preg_match('/^(.+?)\s*<([^>]+)>$/', $mailbox, $matches)) {
                $name = trim($matches[1], " \t\n\r\0\x0B\"");
                $email = trim($matches[2]);
            } else {
                $email = $mailbox;
                $name = strstr($email, '@', true) ?: 'EMS Approver';
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                Log::warning('An EMS report approver entry has an invalid email address.', ['entry' => $mailbox]);
                continue;
            }

            $approvers[strtolower($email)] = ['name' => $name ?: 'EMS Approver', 'email' => $email];
        }

        return array_values($approvers);
    }

    private function storeDrawnSignature(string $dataUrl, string $directory, string $party): string
    {
        if (!preg_match('/^data:image\/png;base64,([A-Za-z0-9+\/=]+)$/', $dataUrl, $matches)) {
            throw ValidationException::withMessages(['signature_data' => 'Draw your signature in the signature box before continuing.']);
        }

        $binary = base64_decode($matches[1], true);
        $image = $binary === false ? false : @getimagesizefromstring($binary);
        if ($binary === false || strlen($binary) > 2 * 1024 * 1024 || $image === false || ($image['mime'] ?? null) !== 'image/png') {
            throw ValidationException::withMessages(['signature_data' => 'The drawn signature is invalid or too large. Please draw it again.']);
        }

        $path = $directory.'/'.$party.'-'.Str::uuid().'.png';
        if (!Storage::disk('local')->put($path, $binary)) {
            throw ValidationException::withMessages(['signature_data' => 'The signature could not be saved. Please try again.']);
        }

        return $path;
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
            'base_location' => ['required', Rule::in(Location::activeNames()->when($ambulance, fn ($locations) => $locations->push($ambulance->base_location))->filter()->unique()->all())],
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

    private function validateMovement(Request $request, ?Dispatch $dispatch = null): array
    {
        $locations = Location::activeNames()->all();
        $data = $request->validate([
            'ambulance_id'=>['required','exists:ambulances,id'],
            'priority'=>['required',Rule::in(array_keys(config('ems.movement_priorities')))],
            'requested_at'=>['required','date','before_or_equal:now'],
            'status'=>['required',Rule::in(['requested','completed'])],
            'origin'=>['required',Rule::in([...$locations,'Other'])],
            'origin_other'=>['nullable','required_if:origin,Other','string','max:160'],
            'destination'=>['required',Rule::in([...$locations,'Other'])],
            'destination_other'=>['nullable','required_if:destination,Other','string','max:160'],
            'purpose'=>['required',Rule::in(config('ems.case_categories'))],
            'notes'=>['nullable','string','max:2000'],
        ], ['requested_at.before_or_equal'=>'The movement date and time cannot be in the future.','origin.in'=>'Select a listed origin or choose Other.','destination.in'=>'Select a listed destination or choose Other.']);

        if ($data['origin'] === 'Other') {
            $data['origin'] = trim($data['origin_other']);
        }
        if ($data['destination'] === 'Other') {
            $data['destination'] = trim($data['destination_other']);
        }
        unset($data['origin_other'], $data['destination_other']);

        if (strcasecmp($data['origin'], $data['destination']) === 0) {
            throw ValidationException::withMessages(['destination' => 'The destination must be different from the origin.']);
        }

        return $data;
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
        $duplicate=MileageReading::withoutGlobalScopes()->withTrashed()->where('ambulance_id',$ambulance->id)
            ->whereDate('reading_date',$data['reading_date'])->where('source',$data['source'])
            ->when($reading,fn($query)=>$query->where('id','!=',$reading->id))->first();
        if($duplicate&&!$duplicate->trashed())throw ValidationException::withMessages(['reading_date'=>'A '.$data['source'].' reading already exists for this ambulance on this date. Choose the correct reading date or edit the existing reading.']);
        if($duplicate&&$reading)throw ValidationException::withMessages(['reading_date'=>'A deleted '.$data['source'].' reading already exists for this ambulance on this date. Restore that reading or choose another date.']);
        $previous=MileageReading::where('ambulance_id',$ambulance->id)->whereDate('reading_date','<',$data['reading_date'])
            ->when($reading,fn($query)=>$query->where('id','!=',$reading->id))->latest('reading_date')->first();
        $next=MileageReading::where('ambulance_id',$ambulance->id)->whereDate('reading_date','>',$data['reading_date'])
            ->when($reading,fn($query)=>$query->where('id','!=',$reading->id))->oldest('reading_date')->first();
        if($previous&&$data['odometer_km']<$previous->odometer_km)throw ValidationException::withMessages(['odometer_km'=>'The reading cannot be lower than the previous reading of '.number_format($previous->odometer_km).' km.']);
        if($next&&$data['odometer_km']>$next->odometer_km)throw ValidationException::withMessages(['odometer_km'=>'The reading cannot exceed the next recorded reading of '.number_format($next->odometer_km).' km.']);
        if(!$next&&$data['reading_date']===today()->toDateString()&&$data['odometer_km']<$ambulance->odometer_km)throw ValidationException::withMessages(['odometer_km'=>'Today’s reading cannot be lower than the current ambulance odometer of '.number_format($ambulance->odometer_km).' km.']);
        return [$data,$ambulance,$next,$duplicate];
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

    private function analyticsData(Request $request): array
    {
        $filters = $this->reportFilters($request);
        $records = $this->filteredMovements($filters)->oldest('requested_at')->get();
        $completed = $records->where('status', 'completed');
        $ambulances = Ambulance::orderBy('fleet_number')->get();
        $statusCounts = collect(['requested', 'completed'])
            ->mapWithKeys(fn ($status) => [$status => $records->where('status', $status)->count()]);
        $dailyCounts = $records->groupBy(fn (Dispatch $movement) => $movement->requested_at->format('Y-m-d'))
            ->map->count()
            ->sortKeys();
        $availability = AvailabilityCheck::whereDate('check_date', '>=', $filters['period_start'])
            ->whereDate('check_date', '<=', $filters['period_end'])
            ->get();
        $activities = WeeklyActivity::whereDate('activity_date', '>=', $filters['period_start'])
            ->whereDate('activity_date', '<=', $filters['period_end'])
            ->get();
        $totalMovements = $records->count();
        $ambulancesUsed = $records->pluck('ambulance_id')->filter()->unique()->count();
        $emergencyMovements = $records->where('priority', 'emergency')->count();
        $availabilityResponded = $availability->where('responded', true)->count();
        $priorityCounts = collect(array_keys(config('ems.movement_priorities')))
            ->mapWithKeys(fn ($priority) => [$priority => $records->where('priority', $priority)->count()]);
        $movementLoadAmbulances = filled($filters['ambulance_id'] ?? null)
            ? $ambulances->where('id', (int) $filters['ambulance_id'])
            : $ambulances;
        $ambulanceMovementCounts = $movementLoadAmbulances
            ->mapWithKeys(fn (Ambulance $ambulance) => [$ambulance->fleet_number => $records->where('ambulance_id', $ambulance->id)->count()]);

        return [
            'filters' => $filters,
            'ambulances' => $ambulances,
            'totalMovements' => $totalMovements,
            'completedMovements' => $completed->count(),
            'activeMovements' => $records->whereIn('status', ['requested', 'dispatched', 'arrived'])->count(),
            'emergencyMovements' => $emergencyMovements,
            'emergencyRate' => $totalMovements ? round(($emergencyMovements / $totalMovements) * 100, 1) : 0,
            'ambulancesUsed' => $ambulancesUsed,
            'totalAmbulances' => $ambulances->count(),
            'fleetUtilizationRate' => $ambulances->isEmpty() ? 0 : round(($ambulancesUsed / $ambulances->count()) * 100, 1),
            'completionRate' => $records->isEmpty() ? 0 : round(($completed->count() / $records->count()) * 100, 1),
            'statusCounts' => $statusCounts,
            'dailyCounts' => $dailyCounts,
            'maxDaily' => max(1, (int) $dailyCounts->max()),
            'availabilityChecks' => $availability->count(),
            'availabilityResponded' => $availabilityResponded,
            'availabilityRate' => $availability->isEmpty() ? null : round(($availabilityResponded / $availability->count()) * 100, 1),
            'activityCount' => $activities->count(),
            'openFollowUps' => $activities->where('requires_follow_up', true)->count(),
            'priorityCounts' => $priorityCounts,
            'ambulanceMovementCounts' => $ambulanceMovementCounts,
            'maxAmbulanceMovements' => max(1, (int) $ambulanceMovementCounts->max()),
            'availabilityStatusCounts' => collect([
                'responded' => $availabilityResponded,
                'no_response' => $availability->count() - $availabilityResponded,
            ]),
        ];
    }

    private function validateReportDefinition(Request $request): array
    {
        $request->merge(['period_preset' => $request->input('period_preset', 'custom')]);

        return $request->validate([
            'type' => ['required', Rule::in(['mileage','weekly_activity','availability'])],
            'period_preset' => ['required', Rule::in(['today','yesterday','this_week','last_week','this_month','last_month','this_quarter','last_quarter','last_six_months','this_year','last_year','custom'])],
            'period_start' => ['nullable','required_if:period_preset,custom','date'],
            'period_end' => ['nullable','required_if:period_preset,custom','date','after_or_equal:period_start'],
        ]);
    }

    private function ensureReportIsMutableByPreparer(EmsReport $report): void
    {
        abort_unless(in_array($report->status, ['draft','submitted'], true), 422, 'Approved reports cannot be edited or deleted.');
        abort_unless((int) $report->prepared_by === (int) auth()->id(), 403, 'Only the person who prepared this report can edit or delete it.');
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

    private function reportCadence(string $preset,string $periodStart,string $periodEnd): string
    {
        return match($preset){
            'today','yesterday'=>'Daily',
            'this_week','last_week'=>'Weekly',
            'this_month','last_month'=>'Monthly',
            'this_quarter','last_quarter'=>'Quarterly',
            'last_six_months'=>'Six-Month',
            'this_year','last_year'=>'Annual',
            default=>match((int)Carbon::parse($periodStart)->diffInDays(Carbon::parse($periodEnd))+1){
                1=>'Daily',
                7=>'Weekly',
                28,29,30,31=>'Monthly',
                89,90,91,92=>'Quarterly',
                181,182,183,184=>'Six-Month',
                365,366=>'Annual',
                default=>'Custom Period',
            },
        };
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
            'Continue scheduled morning, afternoon, and evening communication checks.',
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
        $allowedUnits = Ambulance::pluck('fleet_number')->merge(AvailabilityUnit::where('is_active',true)->pluck('name'))->unique()->values()->all();
        $allowedResponseLocations = Location::activeNames();
        if ($editing) {
            $checkIds = collect($request->input('checks', []))->pluck('id')->filter()->all();
            $allowedResponseLocations = $allowedResponseLocations
                ->merge(AvailabilityCheck::whereIn('id', $checkIds)->pluck('response_location'));
        }
        $allowedResponseLocations = $allowedResponseLocations->filter()->unique()->values()->all();

        return $request->validate([
            'check_date'=>'required|date|before_or_equal:today',
            'period'=>'required|in:morning,afternoon,evening',
            'checked_at'=>'required|date_format:H:i',
            'checks'=>'required|array|min:1',
            'checks.*.id'=>$editing?'required|integer|exists:availability_checks,id':'prohibited',
            'checks.*.unit_name'=>$editing?'prohibited':['required','string','max:120','distinct',Rule::in($allowedUnits)],
            'checks.*.responded'=>'required|boolean',
            'checks.*.response_location'=>['nullable','string','max:160',Rule::in($allowedResponseLocations)],
            'checks.*.observation'=>'nullable|string|max:1000',
        ], [
            'checks.required' => 'Select at least one unit for this check session.',
            'checks.min' => 'Select at least one unit for this check session.',
            'checks.*.unit_name.in' => 'Select a valid configured unit.',
            'checks.*.unit_name.distinct' => 'Each unit can only be included once per check session.',
            'checks.*.response_location.in' => 'Select a valid active response location.',
        ]);
    }

    private function validateActivity(Request $request,?WeeklyActivity $activity=null): array
    {
        $data=$request->validate([
            'activity_date'=>['required','date','before_or_equal:today',Rule::unique('weekly_activities','activity_date')->whereNull('deleted_at')->ignore($activity?->id)],
            'description'=>'required|string|max:12000','outcome'=>'nullable|string|max:6000','requires_follow_up'=>'nullable|boolean',
            'follow_up_action'=>'nullable|required_if:requires_follow_up,1|max:2000','follow_up_owner'=>'nullable|required_if:requires_follow_up,1|max:160',
            'follow_up_due_date'=>['nullable','date',function($attribute,$value,$fail)use($request){
                if($request->filled('activity_date')&&Carbon::parse($value)->startOfDay()->lt(Carbon::parse($request->input('activity_date'))->startOfDay())){
                    $fail('The follow-up due date must be on or after the activity date.');
                }
            }],
        ], ['activity_date.unique'=>'An activity record already exists for this date. Open that record and add the remaining activities there.']);
        $data['description']=RichText::clean($data['description']);
        $data['outcome']=RichText::clean($data['outcome']??null);
        $data['category']='operations';
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
            'date' => $activity->activity_date->toDateString(),
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
        $summary = [
            number_format($activities->count()).' departmental activities and key engagements were recorded.',
            number_format($followUps).' activities require follow-up action.',
        ];
        $capturedActions=$activities->where('requires_follow_up',true)->pluck('follow_up_action')->filter()->unique()->values();
        $recommendations = $capturedActions->take(5)->values()->all();
        $recommendations = array_values(array_unique(array_merge($recommendations, [
            'Continue documenting significant daily activities, decisions, and operational issues.',
            'Review recurring activity themes to guide staffing, training, and equipment planning.',
            'Escalate unresolved operational issues to the EMS Manager with clear owners and target dates.',
        ])));
        return [['activities' => $rows->all(), 'total_activities' => $activities->count(), 'follow_ups' => $followUps], $summary, $recommendations];
    }
}
