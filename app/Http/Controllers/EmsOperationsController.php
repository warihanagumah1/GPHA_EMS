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
        $availabilityFilters = [];
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
                        ->orWhere('crew_lead','like',"%{$search}%")
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
                ->latest('requested_at')->paginate(20)->withQueryString();
        }

        $checks = collect();
        if ($module === 'availability') {
            $availabilityFilters = $request->validate([
                'date_from' => ['nullable','date'],
                'date_to' => ['nullable','date','after_or_equal:date_from'],
                'period' => ['nullable',Rule::in(['morning','afternoon'])],
                'unit_name' => ['nullable','string','max:120'],
                'responded' => ['nullable',Rule::in(['1','0'])],
            ]);
            $checks = AvailabilityCheck::query()
                ->when(filled($availabilityFilters['date_from'] ?? null), fn ($query) => $query->whereDate('check_date','>=',$availabilityFilters['date_from']))
                ->when(filled($availabilityFilters['date_to'] ?? null), fn ($query) => $query->whereDate('check_date','<=',$availabilityFilters['date_to']))
                ->when(filled($availabilityFilters['period'] ?? null), fn ($query) => $query->where('period',$availabilityFilters['period']))
                ->when(filled($availabilityFilters['unit_name'] ?? null), fn ($query) => $query->where('unit_name',$availabilityFilters['unit_name']))
                ->when(array_key_exists('responded',$availabilityFilters) && $availabilityFilters['responded'] !== null && $availabilityFilters['responded'] !== '', fn ($query) => $query->where('responded',(bool) $availabilityFilters['responded']))
                ->latest('check_date')->latest('checked_at')->paginate(15)->withQueryString();
        }

        $ambulances = Ambulance::orderBy('fleet_number')->get();
        return view('ems.module', [
            'module' => $module,
            'ambulances' => $ambulances,
            'dispatches' => $dispatches,
            'movementFilters' => $movementFilters,
            'availabilityFilters' => $availabilityFilters,
            'readings' => $module === 'mileage' ? MileageReading::with('ambulance')->latest('reading_date')->paginate(25) : collect(),
            'checks' => $checks,
            'activities' => $module === 'activities' ? WeeklyActivity::latest('activity_date')->paginate(25) : collect(),
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
                        ->orWhere('purpose', 'like', "%{$search}%")
                        ->orWhere('crew_lead', 'like', "%{$search}%");
                });
            })
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->where('status', $filters['status']))
            ->when(filled($filters['date_from'] ?? null), fn ($query) => $query->whereDate('requested_at', '>=', $filters['date_from']))
            ->when(filled($filters['date_to'] ?? null), fn ($query) => $query->whereDate('requested_at', '<=', $filters['date_to']))
            ->latest('requested_at')
            ->paginate(10)
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
        if($ambulance->status!=='available')throw ValidationException::withMessages(['ambulance_id'=>'Only an available ambulance can be assigned to a new movement.']);
        $data['odometer_start'] ??= $ambulance->odometer_km;
        Dispatch::create($data+['uuid'=>(string)Str::uuid(),'reference'=>'EMS-'.now()->format('ymd').'-'.strtoupper(Str::random(5)),'status'=>'requested','requested_at'=>now(),'created_by'=>auth()->id()]);
        $ambulance->update(['status'=>'dispatched','current_location'=>$data['destination']]);
        return back()->with('success','Movement recorded and ambulance status updated.');
    }

    public function showDispatch(Dispatch $dispatch)
    {
        $dispatch->load('ambulance');
        return view('ems.movements.show', compact('dispatch'));
    }

    public function editDispatch(Dispatch $dispatch)
    {
        abort_if(in_array($dispatch->status, ['completed', 'cancelled'], true), 422, 'Completed or cancelled movements cannot be edited.');
        return view('ems.movements.edit', [
            'dispatch' => $dispatch,
            'ambulances' => Ambulance::orderBy('fleet_number')->get(),
        ]);
    }

    public function updateDispatch(Request $request, Dispatch $dispatch): RedirectResponse
    {
        abort_if(in_array($dispatch->status, ['completed', 'cancelled'], true), 422, 'Completed or cancelled movements cannot be edited.');
        $data = $this->validateMovement($request);
        $newAmbulance = Ambulance::findOrFail($data['ambulance_id']);

        if ($newAmbulance->id !== $dispatch->ambulance_id && $newAmbulance->status !== 'available') {
            throw ValidationException::withMessages(['ambulance_id' => 'Only an available ambulance can be assigned to this movement.']);
        }

        DB::transaction(function () use ($dispatch, $data, $newAmbulance) {
            $previousAmbulance = $dispatch->ambulance;
            $dispatch->update($data);
            $newAmbulance->update(['status' => 'dispatched', 'current_location' => $data['destination']]);
            if ($previousAmbulance->id !== $newAmbulance->id) {
                $previousAmbulance->update(['status' => 'available']);
            }
        });

        return redirect()->route('ems.dispatches.show', $dispatch)->with('success', 'Movement updated successfully.');
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
        $data=$request->validate(['ambulance_id'=>'required|exists:ambulances,id','reading_date'=>'required|date|before_or_equal:today','odometer_km'=>'required|integer|min:0|max:9999999','source'=>'required|in:weekly,service','notes'=>'nullable|string|max:1000']);
        $ambulance=Ambulance::findOrFail($data['ambulance_id']);
        $previous=MileageReading::where('ambulance_id',$ambulance->id)->whereDate('reading_date','<',$data['reading_date'])->latest('reading_date')->first();
        $next=MileageReading::where('ambulance_id',$ambulance->id)->whereDate('reading_date','>',$data['reading_date'])->oldest('reading_date')->first();
        if($previous && $data['odometer_km']<$previous->odometer_km)throw ValidationException::withMessages(['odometer_km'=>'The reading cannot be lower than the previous reading of '.number_format($previous->odometer_km).' km.']);
        if($next && $data['odometer_km']>$next->odometer_km)throw ValidationException::withMessages(['odometer_km'=>'The reading cannot exceed the next recorded reading of '.number_format($next->odometer_km).' km.']);
        if(!$next && $data['reading_date']===today()->toDateString() && $data['odometer_km']<$ambulance->odometer_km)throw ValidationException::withMessages(['odometer_km'=>'Today’s reading cannot be lower than the current ambulance odometer of '.number_format($ambulance->odometer_km).' km.']);
        DB::transaction(function() use($data,$ambulance,$next){ MileageReading::updateOrCreate(['ambulance_id'=>$data['ambulance_id'],'reading_date'=>$data['reading_date'],'source'=>$data['source']],$data+['recorded_by'=>auth()->id()]); if(!$next && $data['odometer_km']>=$ambulance->odometer_km)$ambulance->update(['odometer_km'=>$data['odometer_km']]); });
        return back()->with('success','Mileage reading saved.');
    }

    public function storeAvailability(Request $request): RedirectResponse
    {
        if($request->has('checks')){
            $data=$request->validate([
                'check_date'=>'required|date|before_or_equal:today','period'=>'required|in:morning,afternoon','checked_at'=>'required|date_format:H:i',
                'checks'=>'required|array|min:1','checks.*.unit_name'=>'required|string|max:120','checks.*.responded'=>'required|boolean',
                'checks.*.response_location'=>'nullable|string|max:160','checks.*.observation'=>'nullable|string|max:1000',
            ]);
            DB::transaction(function()use($data){foreach($data['checks'] as $check)AvailabilityCheck::updateOrCreate(
                ['check_date'=>$data['check_date'],'period'=>$data['period'],'unit_name'=>$check['unit_name']],
                $check+['checked_at'=>$data['checked_at'],'recorded_by'=>auth()->id()]
            );});
            return back()->with('success',count($data['checks']).' availability checks saved for the session.');
        }
        $data=$request->validate(['check_date'=>'required|date|before_or_equal:today','period'=>'required|in:morning,afternoon','checked_at'=>'nullable|date_format:H:i','unit_name'=>'required|max:120','responded'=>'required|boolean','response_location'=>'nullable|max:160','observation'=>'nullable|max:1000']);
        AvailabilityCheck::updateOrCreate(['check_date'=>$data['check_date'],'period'=>$data['period'],'unit_name'=>$data['unit_name']],$data+['checked_at'=>$data['checked_at']??now()->format('H:i'),'recorded_by'=>auth()->id()]);
        return back()->with('success','Availability check saved.');
    }

    public function storeActivity(Request $request): RedirectResponse
    {
        $data=$request->validate(['activity_date'=>'required|date|before_or_equal:today','category'=>'required|in:operations,meeting,training,inspection,administration,outreach','description'=>'required|max:4000','outcome'=>'nullable|max:2000','requires_follow_up'=>'nullable|boolean','follow_up_action'=>'nullable|required_if:requires_follow_up,1|max:2000','follow_up_owner'=>'nullable|required_if:requires_follow_up,1|max:160','follow_up_due_date'=>'nullable|date|after_or_equal:activity_date']);
        $data['title']=str($data['description'])->before('.')->squish()->limit(120)->toString();
        WeeklyActivity::create($data+['requires_follow_up'=>$request->boolean('requires_follow_up'),'created_by'=>auth()->id()]);
        return back()->with('success','Weekly activity recorded.');
    }

    public function generateReport(Request $request): RedirectResponse
    {
        $data=$request->validate(['type'=>'required|in:mileage,weekly_activity,availability','period_start'=>'required|date','period_end'=>'required|date|after_or_equal:period_start']);
        [$snapshot,$summary,$recommendations]=$this->buildPrintableReport($data['type'],$data['period_start'],$data['period_end']);
        $report=EmsReport::create($data+['uuid'=>(string)Str::uuid(),'status'=>'draft','snapshot'=>$snapshot,'summary'=>$summary,'recommendations'=>$recommendations,'prepared_by'=>auth()->id()]);
        return redirect()->route('ems.reports.print',$report);
    }

    public function reportsDashboard(Request $request)
    {
        $filters = $this->reportFilters($request);
        $query = $this->filteredMovements($filters);
        $records = (clone $query)->oldest('requested_at')->get();
        $completed = $records->where('status', 'completed');
        $totalDistance = $completed->sum(fn (Dispatch $movement) => max(0, $movement->distance_km ?? 0));
        $statusOrder = ['requested', 'completed'];
        $statusCounts = collect($statusOrder)->mapWithKeys(fn ($status) => [$status => $records->where('status', $status)->count()]);
        $dailyCounts = $records->groupBy(fn (Dispatch $movement) => $movement->requested_at->format('Y-m-d'))
            ->map->count()
            ->sortKeys();
        $maxDaily = max(1, (int) $dailyCounts->max());
        $availability = AvailabilityCheck::whereDate('check_date','>=',$filters['period_start'])->whereDate('check_date','<=',$filters['period_end'])->get();

        return view('ems.reports.dashboard', [
            'filters' => $filters,
            'ambulances' => Ambulance::orderBy('fleet_number')->get(),
            'totalMovements' => $records->count(),
            'completedMovements' => $completed->count(),
            'activeMovements' => $records->whereIn('status', ['requested', 'dispatched', 'arrived'])->count(),
            'criticalMovements' => $records->where('priority', 'critical')->count(),
            'totalDistance' => $totalDistance,
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
            fputcsv($out, ['Reference','Date','Ambulance','Registration','Origin','Destination','Case Category','Priority','Status','Crew Lead','Distance (km)']);
            $movements->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $movement) {
                    fputcsv($out, [$movement->reference,$movement->requested_at?->format('Y-m-d H:i'),$movement->ambulance?->fleet_number,$movement->ambulance?->registration_number,$movement->origin,$movement->destination,$movement->purpose,$movement->priority,$movement->status,$movement->crew_lead,$movement->distance_km]);
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
        return view('ems.audit',['logs'=>EmsAuditLog::with('user')->latest('created_at')->paginate(100)]);
    }

    public function exportAudit()
    {
        return response()->streamDownload(function(){$out=fopen('php://output','w');fputcsv($out,['Date','User','Branch','Action','Route','Method','Path','Status']);EmsAuditLog::with('user')->latest('created_at')->chunk(500,function($logs)use($out){foreach($logs as $log)fputcsv($out,[$log->created_at,$log->user?->name,$log->branch_code,$log->action,$log->route,$log->method,$log->path,$log->response_status]);});fclose($out);},'EMS-audit-'.today()->format('Y-m-d').'.csv',['Content-Type'=>'text/csv']);
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

        return $request->validate([
            'fleet_number' => [
                'required',
                'string',
                'max:30',
                'regex:/^AMBU(?:LANCE)?[\s-]?[0-9]{1,3}$/i',
                Rule::unique('ambulances', 'fleet_number')->ignore($ambulance?->id),
            ],
            'registration_number' => ['required', 'string', 'max:30', Rule::unique('ambulances', 'registration_number')->ignore($ambulance?->id)],
            'make' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:80'],
            'year' => ['nullable', 'integer', 'min:1990', 'max:'.(now()->year + 1)],
            'base_location' => ['required', 'string', 'max:120'],
            'odometer_km' => ['required', 'integer', 'min:0', 'max:9999999'],
            'next_service_km' => ['nullable', 'integer', 'gte:odometer_km', 'max:9999999'],
            'roadworthy_expires_at' => ['nullable', 'date'],
            'insurance_expires_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'fleet_number.regex' => 'Enter a valid ambulance number such as AMBU 1 or AMBULANCE 1.',
            'fleet_number.unique' => 'This ambulance number has already been registered.',
            'registration_number.unique' => 'This vehicle registration number has already been registered.',
            'next_service_km.gte' => 'The next service odometer must be equal to or greater than the current odometer.',
        ]);
    }

    private function validateMovement(Request $request): array
    {
        return $request->validate([
            'ambulance_id'=>['required','exists:ambulances,id'],
            'priority'=>['required',Rule::in(['routine','urgent','critical'])],
            'origin'=>['required',Rule::in(config('ems.movement_locations'))],
            'destination'=>['required','different:origin',Rule::in(config('ems.movement_locations'))],
            'purpose'=>['required',Rule::in(config('ems.case_categories'))],
            'crew_lead'=>['nullable','string','max:120'],
            'odometer_start'=>['nullable','integer','min:0'],
            'notes'=>['nullable','string','max:2000'],
        ], ['destination.different'=>'The destination must be different from the origin.']);
    }

    private function reportFilters(Request $request): array
    {
        $request->merge([
            'period_start' => $request->input('period_start', today()->subDays(29)->toDateString()),
            'period_end' => $request->input('period_end', today()->toDateString()),
        ]);

        return $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'ambulance_id' => ['nullable', 'integer', 'exists:ambulances,id'],
            'status' => ['nullable', Rule::in(['requested','completed'])],
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
