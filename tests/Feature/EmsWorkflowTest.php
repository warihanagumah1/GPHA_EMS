<?php

namespace Tests\Feature;

use App\Models\Ambulance;
use App\Models\AvailabilityCheck;
use App\Models\AvailabilityUnit;
use App\Models\Dispatch;
use App\Models\EmsReport;
use App\Models\EmsAuditLog;
use App\Models\MileageReading;
use App\Models\User;
use App\Models\WeeklyActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmsWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_register_an_ambulance(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('ems.ambulances.store'), [
            'fleet_number' => 'ambulance-01',
            'registration_number' => 'gv 100-26',
            'base_location' => 'Main Clinic',
            'odometer_km' => 100,
        ])->assertRedirect(route('ems.ambulances'));

        $this->assertDatabaseHas('ambulances', [
            'fleet_number' => 'AMBU 1',
            'registration_number' => 'GV 100-26',
        ]);
    }

    public function test_invalid_or_duplicate_ambulance_number_is_rejected(): void
    {
        $user = User::factory()->create();
        $ambulance = $this->ambulance();

        $this->actingAs($user)->from(route('ems.ambulances'))->post(route('ems.ambulances.store'), [
            'fleet_number' => 'Vehicle One',
            'registration_number' => 'GV 200-26',
            'base_location' => 'Main Clinic',
            'odometer_km' => 0,
        ])->assertSessionHasErrors('fleet_number');

        $this->actingAs($user)->from(route('ems.ambulances'))->post(route('ems.ambulances.store'), [
            'fleet_number' => strtolower($ambulance->fleet_number),
            'registration_number' => 'GV 201-26',
            'base_location' => 'Main Clinic',
            'odometer_km' => 0,
        ])->assertSessionHasErrors('fleet_number');
    }

    public function test_ambulance_registration_year_location_and_expiry_are_strictly_validated(): void
    {
        $user=User::factory()->create();
        $registrationField = Blade::render('<x-ems.ambulance-form action="/ambulances" />');
        $this->assertStringContainsString('registrationValid()', $registrationField);
        $this->assertStringContainsString('Valid registration format.', $registrationField);
        $this->assertStringContainsString('Enter a valid registration number such as GV 1234-26.', $registrationField);

        $this->actingAs($user)->post(route('ems.ambulances.store'),[
            'fleet_number'=>'AMBU 8','registration_number'=>'DVFG1233','year'=>now()->year+1,
            'base_location'=>'Unknown Base','odometer_km'=>-1,'roadworthy_expires_at'=>today()->subDay()->toDateString(),
        ])->assertSessionHasErrors(['registration_number','year','base_location','odometer_km','roadworthy_expires_at']);

        $this->actingAs($user)->post(route('ems.ambulances.store'),[
            'fleet_number'=>'AMBU 8','registration_number'=>'GV 1234-26','year'=>now()->year,
            'base_location'=>'Main Clinic','odometer_km'=>0,
        ])->assertRedirect();
        $this->assertDatabaseHas('ambulances',['fleet_number'=>'AMBU 8','registration_number'=>'GV 1234-26','year'=>now()->year]);
    }

    public function test_successful_ambulance_update_redirects_to_the_ambulance_list(): void
    {
        $user = User::factory()->create();
        $ambulance = $this->ambulance();

        $this->actingAs($user)->put(route('ems.ambulances.update', $ambulance), [
            'fleet_number' => $ambulance->fleet_number,
            'registration_number' => $ambulance->registration_number,
            'make' => 'Mercedes-Benz',
            'base_location' => $ambulance->base_location,
            'odometer_km' => $ambulance->odometer_km,
        ])->assertRedirect(route('ems.ambulances'));

        $this->assertDatabaseHas('ambulances', [
            'id' => $ambulance->id,
            'make' => 'Mercedes-Benz',
        ]);
    }

    public function test_initial_ambulance_odometer_can_be_reduced_until_a_mileage_reading_exists(): void
    {
        $user = User::factory()->create();
        $ambulance = $this->ambulance(['odometer_km' => 5000]);
        $payload = [
            'fleet_number' => $ambulance->fleet_number,
            'registration_number' => $ambulance->registration_number,
            'base_location' => $ambulance->base_location,
            'odometer_km' => 2000,
        ];

        $this->actingAs($user)->put(route('ems.ambulances.update', $ambulance), $payload)
            ->assertRedirect(route('ems.ambulances'));
        $this->assertSame(2000, (int) $ambulance->fresh()->odometer_km);

        MileageReading::create([
            'ambulance_id' => $ambulance->id,
            'reading_date' => today()->toDateString(),
            'odometer_km' => 2000,
            'source' => 'weekly',
            'recorded_by' => $user->id,
        ]);

        $this->actingAs($user)->from(route('ems.ambulances.edit', $ambulance))
            ->put(route('ems.ambulances.update', $ambulance), [...$payload, 'odometer_km' => 1900])
            ->assertSessionHasErrors('odometer_km');
        $this->assertSame(2000, (int) $ambulance->fresh()->odometer_km);
    }

    public function test_ambulance_can_be_soft_deleted_without_removing_its_history_but_not_during_an_active_movement(): void
    {
        $user = User::factory()->create();
        $ambulance = $this->ambulance(['status' => 'available']);
        $movement = Dispatch::create([
            'uuid' => (string) Str::uuid(),
            'reference' => 'EMS-DELETE-001',
            'ambulance_id' => $ambulance->id,
            'priority' => 'routine',
            'status' => 'completed',
            'origin' => 'Main Clinic',
            'destination' => 'Clinic B',
            'purpose' => 'Medical coverage',
            'requested_at' => now()->subHour(),
            'completed_at' => now(),
        ]);
        $reading = MileageReading::create([
            'ambulance_id' => $ambulance->id,
            'reading_date' => today(),
            'odometer_km' => 2000,
            'source' => 'weekly',
            'recorded_by' => $user->id,
        ]);

        $this->actingAs($user)->withSession([
            'sso.permissions' => ['ambulancefleet' => ['view', 'manage']],
        ])->get(route('ems.ambulances'))
            ->assertOk()
            ->assertSee(route('ems.ambulances.destroy', $ambulance), false)
            ->assertSee('Delete Ambulance?');

        $this->actingAs($user)->delete(route('ems.ambulances.destroy', $ambulance))
            ->assertRedirect(route('ems.ambulances'));

        $this->assertSoftDeleted('ambulances', ['id' => $ambulance->id]);
        $this->assertDatabaseHas('dispatches', ['id' => $movement->id, 'ambulance_id' => $ambulance->id]);
        $this->assertDatabaseHas('mileage_readings', ['id' => $reading->id, 'ambulance_id' => $ambulance->id]);
        $this->assertDatabaseHas('ems_audit_logs', ['action' => 'ambulance.deleted', 'subject_id' => $ambulance->id]);

        $activeAmbulance = $this->ambulance(['fleet_number' => 'AMBU 9', 'registration_number' => 'GV 900-26', 'status' => 'dispatched']);
        Dispatch::create([
            'uuid' => (string) Str::uuid(),
            'reference' => 'EMS-DELETE-002',
            'ambulance_id' => $activeAmbulance->id,
            'priority' => 'emergency',
            'status' => 'requested',
            'origin' => 'Main Clinic',
            'destination' => 'Tema General Hospital',
            'purpose' => 'Emergency response',
            'requested_at' => now(),
        ]);

        $this->actingAs($user)->from(route('ems.ambulances'))
            ->delete(route('ems.ambulances.destroy', $activeAmbulance))
            ->assertRedirect(route('ems.ambulances'))
            ->assertSessionHasErrors('ambulance');
        $this->assertNotSoftDeleted('ambulances', ['id' => $activeAmbulance->id]);
    }

    public function test_movement_datetime_and_status_are_validated_and_completed_history_does_not_dispatch_the_ambulance(): void
    {
        $user=User::factory()->create();
        $ambulance=$this->ambulance();
        $payload=['ambulance_id'=>$ambulance->id,'priority'=>'routine','origin'=>'Main Clinic','destination'=>'Clinic B','purpose'=>'Patient transfer'];

        $this->actingAs($user)->post(route('ems.dispatches.store'),$payload+['requested_at'=>now()->addHour()->format('Y-m-d H:i:s'),'status'=>'unknown'])
            ->assertSessionHasErrors(['requested_at','status']);
        $occurredAt=now()->subHour()->startOfMinute();
        $this->actingAs($user)->post(route('ems.dispatches.store'),$payload+['requested_at'=>$occurredAt->format('Y-m-d H:i:s'),'status'=>'completed'])
            ->assertRedirect();

        $movement=Dispatch::latest('id')->firstOrFail();
        $this->assertSame('completed',$movement->status);
        $this->assertTrue($movement->requested_at->equalTo($occurredAt));
        $this->assertDatabaseHas('ambulances',['id'=>$ambulance->id,'status'=>'available']);
    }

    public function test_ambulance_can_be_marked_unavailable_and_viewed_with_movements(): void
    {
        $user = User::factory()->create();
        $ambulance = $this->ambulance();
        Dispatch::create([
            'reference' => 'EMS-260720-TEST1',
            'ambulance_id' => $ambulance->id,
            'origin' => 'Main Clinic',
            'destination' => 'Tema General Hospital',
            'purpose' => 'Patient transfer',
            'status' => 'completed',
            'requested_at' => now(),
        ]);

        $this->actingAs($user)->patch(route('ems.ambulances.status', $ambulance), [
            'status' => 'unavailable',
        ])->assertRedirect();

        $this->assertDatabaseHas('ambulances', ['id' => $ambulance->id, 'status' => 'unavailable']);
        $this->actingAs($user)->get(route('ems.ambulances.show', $ambulance))
            ->assertOk()
            ->assertSee('Movement History')
            ->assertDontSee('Next Service')
            ->assertDontSee('Distance')
            ->assertDontSee('Crew Lead')
            ->assertSee('EMS-260720-TEST1');
    }

    public function test_movement_must_use_predefined_locations_and_case_category(): void
    {
        $user = User::factory()->create();
        $ambulance = $this->ambulance();

        $this->actingAs($user)->post(route('ems.dispatches.store'), [
            'ambulance_id' => $ambulance->id,
            'priority' => 'routine',
            'requested_at' => now()->format('Y-m-d H:i:s'),
            'status' => 'requested',
            'origin' => 'Unknown Location',
            'destination' => 'Tema General Hospital',
            'purpose' => 'Unknown Case',
        ])->assertSessionHasErrors(['origin', 'purpose']);

        $this->actingAs($user)->post(route('ems.dispatches.store'), [
            'ambulance_id' => $ambulance->id,
            'priority' => 'non_emergency',
            'requested_at' => now()->format('Y-m-d H:i:s'),
            'status' => 'requested',
            'origin' => 'Main Clinic',
            'destination' => 'Tema General Hospital',
            'purpose' => 'Patient transfer',
        ])->assertRedirect();

        $this->assertDatabaseHas('dispatches', [
            'ambulance_id' => $ambulance->id,
            'origin' => 'Main Clinic',
            'destination' => 'Tema General Hospital',
            'purpose' => 'Patient transfer',
        ]);
        $this->assertDatabaseHas('ambulances', ['id' => $ambulance->id, 'status' => 'dispatched']);

        $movement = Dispatch::where('ambulance_id', $ambulance->id)->latest('id')->firstOrFail();
        $this->actingAs($user)->get(route('ems.dispatches.show', $movement))->assertOk()->assertSee($movement->reference);
        $this->actingAs($user)->patch(route('ems.dispatches.complete', $movement))->assertRedirect();
        $this->assertDatabaseHas('dispatches', ['id' => $movement->id, 'status' => 'completed']);
        $this->assertDatabaseHas('ambulances', ['id' => $ambulance->id, 'status' => 'available', 'current_location' => 'Tema General Hospital']);
    }

    public function test_movement_locations_are_searchable_and_other_origin_and_destination_are_saved_as_text(): void
    {
        $user = User::factory()->create();
        $ambulance = $this->ambulance();

        $this->actingAs($user)->withSession([
            'sso.permissions' => ['dispatchandmovement' => ['view', 'manage']],
        ])->get(route('ems.dispatches', ['new' => 1]))
            ->assertOk()
            ->assertSee('name="origin" x-model="selected"', false)
            ->assertSee('name="destination" x-model="selected"', false)
            ->assertSee('Search and select origin')
            ->assertSee('Search and select destination')
            ->assertSee('Other Destination')
            ->assertSee('No matching option')
            ->assertSee('Berth')
            ->assertSee('Anchorage')
            ->assertSee('Fishing Harbour Clinic')
            ->assertSeeText('Movement Date & Time')
            ->assertDontSee('24-hour');

        $this->actingAs($user)->post(route('ems.dispatches.store'), [
            'ambulance_id' => $ambulance->id,
            'priority' => 'routine',
            'requested_at' => now()->subMinute()->format('Y-m-d H:i:s'),
            'status' => 'completed',
            'origin' => 'Other',
            'origin_other' => 'Community Event Grounds',
            'destination' => 'Fishing Harbour Clinic',
            'purpose' => 'Medical coverage',
        ])->assertRedirect();

        $this->assertDatabaseHas('dispatches', [
            'ambulance_id' => $ambulance->id,
            'origin' => 'Community Event Grounds',
            'destination' => 'Fishing Harbour Clinic',
        ]);

        $secondAmbulance = $this->ambulance([
            'fleet_number' => 'AMBU 8',
            'registration_number' => 'GV 800-26',
        ]);
        $this->actingAs($user)->post(route('ems.dispatches.store'), [
            'ambulance_id' => $secondAmbulance->id,
            'priority' => 'non_emergency',
            'requested_at' => now()->subMinute()->format('Y-m-d H:i:s'),
            'status' => 'completed',
            'origin' => 'Main Clinic',
            'destination' => 'Other',
            'destination_other' => 'Community Sports Grounds',
            'purpose' => 'Medical coverage',
        ])->assertRedirect();

        $this->assertDatabaseHas('dispatches', [
            'ambulance_id' => $secondAmbulance->id,
            'origin' => 'Main Clinic',
            'destination' => 'Community Sports Grounds',
        ]);
    }

    public function test_completed_movement_can_be_edited_and_soft_deleted_with_a_detailed_audit_trail(): void
    {
        $user=User::factory()->create();
        $ambulance=$this->ambulance();
        $movement=Dispatch::create(['uuid'=>(string)Str::uuid(),'reference'=>'EMS-AUDIT-001','ambulance_id'=>$ambulance->id,'priority'=>'routine','status'=>'completed','origin'=>'Main Clinic','destination'=>'Clinic B','purpose'=>'Patient transfer','requested_at'=>now(),'completed_at'=>now(),'created_by'=>$user->id]);

        $this->actingAs($user)->get(route('ems.dispatches.show',$movement))->assertOk()->assertDontSee('Crew Lead')->assertDontSee('Odometer')->assertDontSee('Distance:');
        $this->actingAs($user)->put(route('ems.dispatches.update',$movement),['ambulance_id'=>$ambulance->id,'priority'=>'non_emergency','requested_at'=>$movement->requested_at->format('Y-m-d H:i:s'),'status'=>'completed','origin'=>'Main Clinic','destination'=>'Clinic B','purpose'=>'Emergency response','notes'=>'Corrected after review.'])->assertRedirect(route('ems.dispatches'));
        $this->assertDatabaseHas('dispatches',['id'=>$movement->id,'priority'=>'non_emergency','purpose'=>'Emergency response']);
        $updatedAudit=EmsAuditLog::where('action','movement.updated')->where('subject_reference','EMS-AUDIT-001')->latest('id')->firstOrFail();
        $this->assertSame('routine',$updatedAudit->old_values['priority']);
        $this->assertSame('non_emergency',$updatedAudit->new_values['priority']);

        $this->actingAs($user)->delete(route('ems.dispatches.destroy',$movement))->assertRedirect(route('ems.dispatches'));
        $this->assertSoftDeleted('dispatches',['id'=>$movement->id]);
        $this->assertDatabaseHas('ems_audit_logs',['action'=>'movement.deleted','subject_reference'=>'EMS-AUDIT-001','user_id'=>$user->id]);
    }

    public function test_operational_reports_show_metrics_and_export_filtered_movements(): void
    {
        $user = User::factory()->create();
        $ambulance = $this->ambulance();
        Dispatch::create([
            'reference' => 'EMS-REPORT-001',
            'ambulance_id' => $ambulance->id,
            'origin' => 'Main Clinic',
            'destination' => 'Tema General Hospital',
            'purpose' => 'Patient transfer',
            'priority' => 'non_emergency',
            'status' => 'completed',
            'requested_at' => now(),
            'completed_at' => now(),
            'odometer_start' => 100,
            'odometer_end' => 125,
        ]);

        $filters = ['period_start' => today()->toDateString(), 'period_end' => today()->toDateString()];
        $this->actingAs($user)->get(route('ems.reports', $filters))
            ->assertOk()
            ->assertSee('Operational Reports')
            ->assertSee('Management Analytics')
            ->assertSee('Download Snapshot')
            ->assertSee('data-download-analytics-snapshot', false)
            ->assertSee('Movement Trend')
            ->assertSee('Fleet Movement Load')
            ->assertSee('Priority Mix')
            ->assertSee('Readiness Performance')
            ->assertDontSee('Activity Mix')
            ->assertSee('Fleet Utilisation')
            ->assertSee('Open Follow-ups')
            ->assertSee('Ambulances Used')
            ->assertDontSee('Recorded Distance')
            ->assertDontSee('Fleet Performance')
            ->assertDontSee('Movement Details')
            ->assertDontSee('Generated Printable Reports');

        $export=$this->actingAs($user)->get(route('ems.reports.operations.export', $filters))->assertDownload();
        $this->assertStringNotContainsString('Distance (km)',$export->streamedContent());
        $this->assertStringNotContainsString('Crew Lead',$export->streamedContent());
    }

    public function test_entire_reports_dashboard_uses_easy_reporting_period_filters(): void
    {
        $user=User::factory()->create();
        $ambulance=$this->ambulance();
        \Carbon\Carbon::setTestNow('2026-08-19 10:00:00');
        try{
            Dispatch::create(['reference'=>'EMS-JULY','ambulance_id'=>$ambulance->id,'origin'=>'Main Clinic','destination'=>'Clinic B','purpose'=>'Patient transfer','priority'=>'routine','status'=>'completed','requested_at'=>'2026-07-15 09:00:00','completed_at'=>'2026-07-15 10:00:00','odometer_start'=>100,'odometer_end'=>125]);
            Dispatch::create(['reference'=>'EMS-AUGUST','ambulance_id'=>$ambulance->id,'origin'=>'Main Clinic','destination'=>'Clinic B','purpose'=>'Patient transfer','priority'=>'routine','status'=>'completed','requested_at'=>'2026-08-10 09:00:00','completed_at'=>'2026-08-10 10:00:00','odometer_start'=>125,'odometer_end'=>165]);
            AvailabilityCheck::create(['session_uuid'=>(string)Str::uuid(),'check_date'=>'2026-07-16','period'=>'morning','unit_name'=>'Main Clinic','responded'=>true]);
            AvailabilityCheck::create(['session_uuid'=>(string)Str::uuid(),'check_date'=>'2026-08-16','period'=>'morning','unit_name'=>'Main Clinic','responded'=>true]);
            WeeklyActivity::create(['activity_date'=>'2026-07-17','category'=>'training','title'=>'July training','description'=>'July training']);
            WeeklyActivity::create(['activity_date'=>'2026-08-17','category'=>'training','title'=>'August training','description'=>'August training']);

            $this->actingAs($user)->get(route('ems.reports'))->assertOk()
                ->assertViewHas('filters',fn($filters)=>$filters['period_preset']==='this_week'&&$filters['period_start']==='2026-08-16'&&$filters['period_end']==='2026-08-19');

            $this->actingAs($user)->get(route('ems.reports',['period_preset'=>'last_month']))
                ->assertOk()
                ->assertViewHas('filters',fn($filters)=>$filters['period_start']==='2026-07-01'&&$filters['period_end']==='2026-07-31'&&$filters['period_label']==='Last Month')
                ->assertViewHas('totalMovements',1)
                ->assertViewHas('ambulancesUsed',1)
                ->assertViewHas('totalAmbulances',1)
                ->assertViewHas('availabilityChecks',1)
                ->assertViewHas('activityCount',1)
                ->assertViewHas('fleetUtilizationRate',100.0)
                ->assertViewHas('priorityCounts',fn($counts)=>$counts->get('routine')===1)
                ->assertViewHas('availabilityStatusCounts',fn($counts)=>$counts->get('responded')===1&&$counts->get('no_response')===0)
                ->assertViewHas('openFollowUps',0)
                ->assertSee('About dashboard reporting periods')
                ->assertSee('positionHelp')
                ->assertSee('fixed z-[200]',false)
                ->assertSee('Last Month')
                ->assertSee('01 Jul 2026, 00:00 – 31 Jul 2026, 23:59');
        }finally{
            \Carbon\Carbon::setTestNow();
        }
    }

    public function test_movement_list_can_be_filtered_by_operational_fields(): void
    {
        $user=User::factory()->create();
        $ambulance=$this->ambulance();
        Dispatch::create(['reference'=>'EMS-FILTER-NONEMERGENCY','ambulance_id'=>$ambulance->id,'origin'=>'Main Clinic','destination'=>'Tema General Hospital','purpose'=>'Patient transfer','priority'=>'non_emergency','status'=>'completed','requested_at'=>now()]);
        Dispatch::create(['reference'=>'EMS-FILTER-ROUTINE','ambulance_id'=>$ambulance->id,'origin'=>'Main Clinic','destination'=>'KUT Terminal','purpose'=>'Routine operational movement','priority'=>'routine','status'=>'requested','requested_at'=>now()]);

        $this->actingAs($user)->get(route('ems.dispatches',['priority'=>'non_emergency','status'=>'completed','purpose'=>'Patient transfer']))
            ->assertOk()->assertSee('EMS-FILTER-NONEMERGENCY')->assertDontSee('EMS-FILTER-ROUTINE')->assertSee('Apply Filters');
    }

    public function test_all_operational_lists_use_fifteen_items_per_page(): void
    {
        $user=User::factory()->create();
        $ambulance=$this->ambulance();

        $this->actingAs($user)->get(route('ems.ambulances'))->assertOk()
            ->assertViewHas('fleet',fn($rows)=>$rows->perPage()===15);
        $this->actingAs($user)->get(route('ems.dispatches'))->assertOk()
            ->assertViewHas('dispatches',fn($rows)=>$rows->perPage()===15);
        $this->actingAs($user)->get(route('ems.mileage'))->assertOk()
            ->assertViewHas('readings',fn($rows)=>$rows->perPage()===15);
        $this->actingAs($user)->get(route('ems.availability'))->assertOk()
            ->assertViewHas('checks',fn($rows)=>$rows->perPage()===15);
        $this->actingAs($user)->get(route('ems.activities'))->assertOk()
            ->assertViewHas('activities',fn($rows)=>$rows->perPage()===15);
        $this->actingAs($user)->get(route('ems.ambulances.show',$ambulance))->assertOk()
            ->assertViewHas('movements',fn($rows)=>$rows->perPage()===15);
        $this->actingAs($user)->get(route('ems.audit'))->assertOk()
            ->assertViewHas('logs',fn($rows)=>$rows->perPage()===15)
            ->assertSee('Close navigation');

        foreach([route('ems.dispatches'),route('ems.mileage'),route('ems.availability'),route('ems.activities'),route('ems.ambulances.show',$ambulance),route('ems.reports')] as $filteredPage){
            $this->actingAs($user)->get($filteredPage)->assertOk()->assertSee('Show Filters');
        }
    }

    public function test_mileage_list_can_be_filtered_and_summarises_movement_per_ambulance(): void
    {
        $user=User::factory()->create();
        $firstAmbulance=$this->ambulance(['fleet_number'=>'AMBU 1','registration_number'=>'GV 100-26','odometer_km'=>1350]);
        $secondAmbulance=$this->ambulance(['fleet_number'=>'AMBU 2','registration_number'=>'GV 200-26','odometer_km'=>2200]);
        $longNotes='An unusually high odometer reading was recorded after several emergency movements between the terminals, Main Clinic, and the receiving hospital.';
        foreach([['2026-07-01',1000],['2026-07-08',1125],['2026-07-15',1350]] as [$date,$odometer]){
            MileageReading::create(['ambulance_id'=>$firstAmbulance->id,'reading_date'=>$date,'odometer_km'=>$odometer,'source'=>'weekly','notes'=>$date==='2026-07-15'?$longNotes:null]);
        }
        foreach([['2026-07-01',2000],['2026-07-15',2200]] as [$date,$odometer]){
            MileageReading::create(['ambulance_id'=>$secondAmbulance->id,'reading_date'=>$date,'odometer_km'=>$odometer,'source'=>'weekly']);
        }
        MileageReading::create(['ambulance_id'=>$firstAmbulance->id,'reading_date'=>'2026-07-20','odometer_km'=>1400,'source'=>'service']);

        $this->actingAs($user)->get(route('ems.mileage',[
            'ambulance_id'=>$firstAmbulance->id,
            'source'=>'weekly',
            'date_from'=>'2026-07-01',
            'date_to'=>'2026-07-15',
        ]))->assertOk()
            ->assertSee('Movement Summary')
            ->assertSee('Total fleet movement')
            ->assertSee('350 km')
            ->assertSee('1,350')
            ->assertSee('1,000')
            ->assertSee((string)str($longNotes)->squish()->limit(120))
            ->assertSee('title="'.$longNotes.'"',false)
            ->assertDontSee('2,200 km')
            ->assertDontSee('1,400 km');
    }

    public function test_availability_and_weekly_activity_lists_can_be_filtered(): void
    {
        $user=User::factory()->create();
        $readySession=(string)Str::uuid();
        $issueSession=(string)Str::uuid();
        AvailabilityCheck::create(['session_uuid'=>$readySession,'check_date'=>'2026-07-10','period'=>'morning','checked_at'=>'07:30','unit_name'=>'Main Clinic','responded'=>true]);
        AvailabilityCheck::create(['session_uuid'=>$readySession,'check_date'=>'2026-07-10','period'=>'morning','checked_at'=>'07:30','unit_name'=>'KUT Terminal','responded'=>true]);
        AvailabilityCheck::create(['session_uuid'=>$issueSession,'check_date'=>'2026-07-11','period'=>'afternoon','checked_at'=>'14:30','unit_name'=>'Main Clinic','responded'=>true]);
        AvailabilityCheck::create(['session_uuid'=>$issueSession,'check_date'=>'2026-07-11','period'=>'afternoon','checked_at'=>'14:30','unit_name'=>'KUT Terminal','responded'=>false]);
        WeeklyActivity::create(['activity_date'=>'2026-07-10','category'=>'training','title'=>'BLS refresher','description'=>'<p>BLS refresher training completed.</p>','requires_follow_up'=>false]);
        WeeklyActivity::create(['activity_date'=>'2026-07-11','category'=>'inspection','title'=>'Radio inspection','description'=>'<p>Radio inspection completed.</p>','requires_follow_up'=>true,'follow_up_action'=>'Replace radio battery','follow_up_owner'=>'Fleet Lead']);

        $this->actingAs($user)->get(route('ems.availability',[
            'period'=>'afternoon','response_status'=>'has_no_response',
        ]))->assertOk()->assertSee('11 Jul 2026')->assertDontSee('10 Jul 2026');
        $this->actingAs($user)->get(route('ems.availability',['response_status'=>'all_responded']))
            ->assertOk()->assertSee('10 Jul 2026')->assertDontSee('11 Jul 2026');

        $this->actingAs($user)->get(route('ems.activities',[
            'search'=>'battery','category'=>'inspection','requires_follow_up'=>'1',
            'date_from'=>'2026-07-11','date_to'=>'2026-07-11',
        ]))->assertOk()
            ->assertSee('Radio inspection completed.')
            ->assertSee('Replace radio battery')
            ->assertDontSee('BLS refresher training completed.');
    }

    public function test_complete_availability_session_and_activity_follow_up_are_captured(): void
    {
        $user=User::factory()->create();
        $this->actingAs($user)->post(route('ems.availability.store'),[
            'check_date'=>today()->toDateString(),'period'=>'morning','checked_at'=>'07:30',
            'checks'=>[
                ['unit_name'=>'Main Clinic','responded'=>'1','response_location'=>'Main Clinic','observation'=>'Ready'],
                ['unit_name'=>'KUT Terminal','responded'=>'0','response_location'=>'','observation'=>'Radio unavailable'],
            ],
        ])->assertRedirect();
        $this->assertDatabaseCount('availability_checks',2);
        $this->assertDatabaseHas('availability_checks',['unit_name'=>'KUT Terminal','responded'=>false,'checked_at'=>'07:30']);

        $this->actingAs($user)->post(route('ems.activities.store'),[
            'activity_date'=>today()->toDateString(),'category'=>'meeting','description'=>'Met Transport management to plan training.','outcome'=>'Training requested for drivers.','requires_follow_up'=>'1','follow_up_action'=>'Prepare BLS training schedule','follow_up_owner'=>'EMS Training Lead','follow_up_due_date'=>today()->addWeek()->toDateString(),
        ])->assertRedirect();
        $this->assertDatabaseHas('weekly_activities',['description'=>'Met Transport management to plan training.','follow_up_owner'=>'EMS Training Lead']);
    }

    public function test_availability_form_excludes_transport_pool_and_accepts_the_full_24_hour_clock(): void
    {
        $user = User::factory()->create();

        $this->assertDatabaseMissing('availability_units', ['name' => 'Transport Pool']);

        $this->actingAs($user)->withSession([
            'sso.permissions' => ['readinessandactivities' => ['view', 'manage']],
        ])->get(route('ems.availability'))
            ->assertOk()
            ->assertSee('Check Time')
            ->assertSee('Select only the units included in this check session')
            ->assertSee('Select All Units')
            ->assertSee('aria-label="Include Main Clinic"', false)
            ->assertSee('min="00:00"', false)
            ->assertSee('max="23:59"', false)
            ->assertDontSee('24-hour');

        $this->actingAs($user)->post(route('ems.availability.store'), [
            'check_date' => today()->toDateString(),
            'period' => 'afternoon',
            'checked_at' => '23:59',
            'checks' => [
                ['unit_name' => 'Main Clinic', 'responded' => '1'],
            ],
        ])->assertRedirect();

        $this->assertDatabaseHas('availability_checks', [
            'unit_name' => 'Main Clinic',
            'checked_at' => '23:59',
        ]);
    }

    public function test_availability_units_are_managed_without_changing_previous_checks_or_reports(): void
    {
        $user = User::factory()->create();
        $session = [
            'sso.permissions' => [
                'readinessandactivities' => ['view', 'manage'],
                'emsreports' => ['view', 'manage'],
            ],
        ];

        $this->assertDatabaseHas('availability_units', [
            'name' => 'Fishing Harbour Clinic',
            'is_active' => true,
        ]);

        $this->actingAs($user)->withSession($session)->get(route('ems.availability', ['new' => 1]))
            ->assertOk()
            ->assertSee('Availability Check Units')
            ->assertSee('x-show="!unitPanelOpen"', false)
            ->assertSee('@click="unitPanelOpen=true"', false)
            ->assertSee('@click="unitPanelOpen=false"', false)
            ->assertSee('>Close</button>', false)
            ->assertSee('Fishing Harbour Clinic')
            ->assertSee('aria-label="Include Fishing Harbour Clinic"', false);

        $this->actingAs($user)->withSession($session)->post(route('ems.availability.units.store'), [
            'name' => 'Harbour Master Office',
        ])->assertRedirect(route('ems.availability'));

        $unit = AvailabilityUnit::where('name', 'Harbour Master Office')->firstOrFail();
        $this->actingAs($user)->withSession($session)->post(route('ems.availability.store'), [
            'check_date' => today()->toDateString(),
            'period' => 'morning',
            'checked_at' => '06:15',
            'checks' => [
                ['unit_name' => $unit->name, 'responded' => '1'],
            ],
        ])->assertRedirect();

        $this->actingAs($user)->withSession($session)->post(route('ems.reports.store'), [
            'type' => 'availability',
            'period_preset' => 'today',
        ])->assertRedirect();
        $report = EmsReport::where('type', 'availability')->latest('id')->firstOrFail();
        $this->assertSame('Harbour Master Office', $report->snapshot['checks'][0]['unit']);

        $this->actingAs($user)->withSession($session)->delete(route('ems.availability.units.destroy', $unit))
            ->assertRedirect(route('ems.availability'));

        $this->assertDatabaseHas('availability_units', [
            'id' => $unit->id,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('availability_checks', ['unit_name' => 'Harbour Master Office']);
        $this->assertSame('Harbour Master Office', $report->fresh()->snapshot['checks'][0]['unit']);

        $this->actingAs($user)->withSession($session)->get(route('ems.availability', ['new' => 1]))
            ->assertOk()
            ->assertViewHas('availabilityUnits', fn ($units) => ! $units->contains('Harbour Master Office'));
    }

    public function test_availability_checks_are_grouped_and_the_session_can_be_viewed_edited_and_soft_deleted(): void
    {
        $user=User::factory()->create();
        $this->actingAs($user)->post(route('ems.availability.store'),[
            'check_date'=>today()->toDateString(),'period'=>'morning','checked_at'=>'07:30',
            'checks'=>[
                ['unit_name'=>'Main Clinic','responded'=>'1','response_location'=>'Main Clinic','observation'=>'Ready'],
                ['unit_name'=>'KUT Terminal','responded'=>'0','response_location'=>'','observation'=>'Radio unavailable'],
            ],
        ])->assertRedirect();
        $session=AvailabilityCheck::firstOrFail()->session_uuid;

        $this->actingAs($user)->get(route('ems.availability'))->assertOk()->assertSee('Check Sessions')->assertSee('Units Checked')->assertSee('Check session actions');
        $this->actingAs($user)->get(route('ems.availability.sessions.show',$session))->assertOk()->assertSee('Main Clinic')->assertSee('KUT Terminal');
        $this->actingAs($user)->get(route('ems.availability.sessions.edit',$session))->assertOk()->assertSee('Mark All Responded')->assertSee('href="'.route('ems.availability').'"',false)->assertSee('>Back</a>',false);
        $checks=AvailabilityCheck::where('session_uuid',$session)->orderBy('id')->get();
        $this->actingAs($user)->put(route('ems.availability.sessions.update',$session),[
            'check_date'=>today()->toDateString(),'period'=>'morning','checked_at'=>'07:45',
            'checks'=>$checks->map(fn($check)=>['id'=>$check->id,'responded'=>'1','response_location'=>'Main Clinic','observation'=>'Confirmed'])->all(),
        ])->assertRedirect(route('ems.availability'));
        $this->assertDatabaseMissing('availability_checks',['session_uuid'=>$session,'responded'=>false]);

        $this->actingAs($user)->delete(route('ems.availability.sessions.destroy',$session))->assertRedirect(route('ems.availability'));
        $this->assertSame(2,AvailabilityCheck::withTrashed()->where('session_uuid',$session)->whereNotNull('deleted_at')->count());
        $this->assertDatabaseHas('ems_audit_logs',['action'=>'availability_check.deleted','user_id'=>$user->id]);
    }

    public function test_activity_can_be_viewed_edited_and_soft_deleted_with_audit_history(): void
    {
        $user=User::factory()->create();
        $activity=WeeklyActivity::create(['activity_date'=>today(),'category'=>'operations','title'=>'Morning briefing','description'=>'<p>Morning briefing held.</p>','created_by'=>$user->id]);

        $this->actingAs($user)->withSession([
            'sso.permissions' => ['readinessandactivities' => ['view', 'manage']],
        ])->get(route('ems.activities',['new'=>1]))->assertOk()
            ->assertSee('Activity actions')
            ->assertSeeText('Activities for the Day')
            ->assertSee('type="date" name="activity_date"',false)
            ->assertDontSee('name="category"',false)
            ->assertDontSee('Date / Time');
        $this->actingAs($user)->get(route('ems.activities.show',$activity))->assertOk()->assertSee('Morning briefing held.')->assertDontSee('hrs');
        $this->actingAs($user)->put(route('ems.activities.update',$activity),[
            'activity_date'=>today()->toDateString(),'description'=>'<p><strong>Ambulance inspected.</strong></p>','outcome'=>'<p>Ready for service.</p>',
        ])->assertRedirect(route('ems.activities'));
        $this->assertDatabaseHas('weekly_activities',['id'=>$activity->id,'activity_date'=>today()->startOfDay()->format('Y-m-d H:i:s'),'category'=>'operations','title'=>'Ambulance inspected']);
        $this->assertDatabaseHas('ems_audit_logs',['action'=>'weekly_activity.updated','subject_id'=>$activity->id]);

        $this->actingAs($user)->delete(route('ems.activities.destroy',$activity))->assertRedirect(route('ems.activities'));
        $this->assertSoftDeleted('weekly_activities',['id'=>$activity->id]);
        $this->assertDatabaseHas('ems_audit_logs',['action'=>'weekly_activity.deleted','subject_id'=>$activity->id]);
    }

    public function test_weekly_mileage_report_uses_consecutive_odometer_readings(): void
    {
        $user=User::factory()->create();
        $ambulance=$this->ambulance(['odometer_km'=>60430]);
        foreach([['2026-06-01',60182],['2026-06-08',60277],['2026-06-15',60430]] as [$date,$reading])MileageReading::create(['ambulance_id'=>$ambulance->id,'reading_date'=>$date,'odometer_km'=>$reading,'source'=>'weekly']);

        $this->actingAs($user)->post(route('ems.reports.store'),['type'=>'mileage','period_start'=>'2026-06-01','period_end'=>'2026-06-30'])->assertRedirect();
        $report=EmsReport::where('type','mileage')->latest('id')->firstOrFail();
        $this->assertSame(95,$report->snapshot['weekly_summaries'][0]['distance_km']);
        $this->assertSame(153,$report->snapshot['weekly_summaries'][1]['distance_km']);
        $this->assertSame(248,$report->snapshot['total_distance_km']);
    }

    public function test_all_three_formal_reports_can_be_generated_and_printed(): void
    {
        $user = User::factory()->create(['name' => 'EMS Report Officer', 'job_title' => 'EMS Officer']);
        $ambulance = $this->ambulance();
        Dispatch::create([
            'reference' => 'EMS-PRINT-001',
            'ambulance_id' => $ambulance->id,
            'origin' => 'Main Clinic',
            'destination' => 'Tema General Hospital',
            'purpose' => 'Patient transfer',
            'priority' => 'non_emergency',
            'status' => 'completed',
            'requested_at' => now(),
            'completed_at' => now(),
            'odometer_start' => 100,
            'odometer_end' => 125,
        ]);
        AvailabilityCheck::create([
            'check_date' => today(),
            'period' => 'morning',
            'checked_at' => '07:30:00',
            'unit_name' => 'Main Clinic',
            'responded' => true,
        ]);
        WeeklyActivity::create([
            'activity_date' => today(),
            'category' => 'inspection',
            'title' => 'Routine ambulance readiness check',
            'description' => 'Vehicle and equipment readiness confirmed.',
        ]);

        $expectedTitles = [
            'mileage' => 'Daily Ambulance Mileage Report',
            'weekly_activity' => 'Daily Operational Activities Report',
            'availability' => 'Daily Radio & Availability Report',
        ];

        foreach ($expectedTitles as $type => $title) {
            $response = $this->actingAs($user)->post(route('ems.reports.store'), [
                'type' => $type,
                'period_start' => today()->toDateString(),
                'period_end' => today()->toDateString(),
            ]);
            $report = EmsReport::where('type', $type)->latest('id')->firstOrFail();
            $response->assertRedirect(route('ems.reports.print', $report));
            $this->assertNotEmpty($report->fresh()->summary);
            $this->assertNotEmpty($report->fresh()->recommendations);
            if ($type === 'availability') {
                $this->assertCount(1, $report->fresh()->snapshot['checks']);
            }
            if ($type === 'weekly_activity') {
                $this->assertSame(1, $report->fresh()->snapshot['total_activities']);
            }
            $printResponse=$this->actingAs($user)->get(route('ems.reports.print', $report))
                ->assertOk()
                ->assertSee($title)
                ->assertSee('Summary of Findings')
                ->assertSee('Recommendations')
                ->assertSee('Print / Save PDF')
                ->assertSee('SAEMT')
                ->assertSee('.table-wrap table{border:1px solid #7e95b4}',false)
                ->assertSee('class="print-table-footer"',false)
                ->assertDontSee('Report ID:')
                ->assertDontSee('Awaiting approval')
                ->assertDontSee('Draft');
            if($type==='weekly_activity')$printResponse->assertDontSee('Category')->assertDontSee('class="activity-category"',false);
            if($type==='availability')$printResponse->assertSee('Unit Responses')->assertSee('Main Clinic');
        }
    }

    public function test_each_report_type_can_use_every_easy_reporting_period(): void
    {
        $user=User::factory()->create();
        \Carbon\Carbon::setTestNow('2026-08-19 10:00:00');
        try{
            $expectedPeriods=[
                'today'=>['2026-08-19','2026-08-19','Today','Daily'],
                'yesterday'=>['2026-08-18','2026-08-18','Yesterday','Daily'],
                'this_week'=>['2026-08-16','2026-08-19','This Week','Weekly'],
                'last_week'=>['2026-08-09','2026-08-15','Last Week','Weekly'],
                'this_month'=>['2026-08-01','2026-08-19','This Month','Monthly'],
                'last_month'=>['2026-07-01','2026-07-31','Last Month','Monthly'],
                'this_quarter'=>['2026-07-01','2026-08-19','This Quarter','Quarterly'],
                'last_quarter'=>['2026-04-01','2026-06-30','Last Quarter','Quarterly'],
                'last_six_months'=>['2026-02-01','2026-07-31','Last 6 Months','Six-Month'],
                'this_year'=>['2026-01-01','2026-08-19','This Year','Annual'],
                'last_year'=>['2025-01-01','2025-12-31','Last Year','Annual'],
            ];

            foreach(['mileage','weekly_activity','availability'] as $type){
                foreach($expectedPeriods as $preset=>[$expectedStart,$expectedEnd,$expectedLabel,$expectedCadence]){
                    $this->actingAs($user)->post(route('ems.reports.store'),[
                        'type'=>$type,
                        'period_preset'=>$preset,
                    ])->assertRedirect();
                    $report=EmsReport::where('type',$type)->latest('id')->firstOrFail();
                    $this->assertSame($expectedStart,$report->period_start->toDateString());
                    $this->assertSame($expectedEnd,$report->period_end->toDateString());
                    $this->assertSame($expectedLabel,$report->snapshot['reporting_period_label']);
                    $this->assertSame($expectedCadence,$report->snapshot['reporting_period_cadence']);
                    if($type==='weekly_activity'&&$preset==='last_quarter'){
                        $this->actingAs($user)->get(route('ems.reports.print',$report))->assertOk()
                            ->assertSee('Quarterly Operational Activities Report')
                            ->assertSee('01 Apr 2026, 00:00')
                            ->assertSee('30 Jun 2026, 23:59')
                            ->assertSee('Quarterly reporting period:');
                    }
                }
            }

            $this->assertSame(33,EmsReport::count());
        }finally{
            \Carbon\Carbon::setTestNow();
        }
    }

    public function test_mileage_cannot_move_backwards(): void
    {
        $user = User::factory()->create();
        $ambulance = $this->ambulance(['odometer_km' => 500]);

        $this->actingAs($user)->from(route('ems.mileage'))->post(route('ems.mileage.store'), [
            'ambulance_id' => $ambulance->id,
            'reading_date' => today()->toDateString(),
            'odometer_km' => 499,
            'source' => 'weekly',
        ])->assertRedirect(route('ems.mileage'))->assertSessionHasErrors('odometer_km');
    }

    public function test_unchanged_mileage_can_be_recorded_on_a_later_date(): void
    {
        $user = User::factory()->create();
        $ambulance = $this->ambulance(['odometer_km' => 2000]);

        MileageReading::create([
            'ambulance_id' => $ambulance->id,
            'reading_date' => today()->subWeek()->toDateString(),
            'odometer_km' => 2000,
            'source' => 'weekly',
            'recorded_by' => $user->id,
        ]);

        $this->actingAs($user)->post(route('ems.mileage.store'), [
            'ambulance_id' => $ambulance->id,
            'reading_date' => today()->toDateString(),
            'odometer_km' => 2000,
            'source' => 'weekly',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue(MileageReading::where('ambulance_id', $ambulance->id)
            ->whereDate('reading_date', today())
            ->where('odometer_km', 2000)
            ->exists());
        $this->assertSame(2000, (int) $ambulance->fresh()->odometer_km);
    }

    public function test_weekly_readings_for_different_historical_weeks_are_saved_with_the_selected_dates(): void
    {
        $user = User::factory()->create();
        $ambulance = $this->ambulance(['odometer_km' => 1200]);

        foreach ([['2026-07-27', 1000], ['2026-08-03', 1100], ['2026-08-10', 1200]] as [$date, $odometer]) {
            $this->actingAs($user)->post(route('ems.mileage.store'), [
                'ambulance_id' => $ambulance->id,
                'reading_date' => $date,
                'odometer_km' => $odometer,
                'source' => 'weekly',
            ])->assertSessionHasNoErrors();
        }

        $this->assertSame(
            ['2026-07-27', '2026-08-03', '2026-08-10'],
            MileageReading::oldest('reading_date')->get()->map(fn (MileageReading $reading) => $reading->reading_date->toDateString())->all(),
        );
    }

    public function test_backdated_mileage_is_validated_against_the_immediately_previous_and_next_dates(): void
    {
        $user = User::factory()->create();
        $ambulance = $this->ambulance(['odometer_km' => 1400]);
        MileageReading::create([
            'ambulance_id' => $ambulance->id,
            'reading_date' => '2026-07-27',
            'odometer_km' => 1000,
            'source' => 'weekly',
            'recorded_by' => $user->id,
        ]);
        MileageReading::create([
            'ambulance_id' => $ambulance->id,
            'reading_date' => '2026-08-10',
            'odometer_km' => 1400,
            'source' => 'weekly',
            'recorded_by' => $user->id,
        ]);

        $this->actingAs($user)->post(route('ems.mileage.store'), [
            'ambulance_id' => $ambulance->id,
            'reading_date' => '2026-08-03',
            'odometer_km' => 1200,
            'source' => 'weekly',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(MileageReading::where('ambulance_id', $ambulance->id)
            ->whereDate('reading_date', '2026-08-03')
            ->where('odometer_km', 1200)
            ->exists());

        $this->actingAs($user)->from(route('ems.mileage'))->post(route('ems.mileage.store'), [
            'ambulance_id' => $ambulance->id,
            'reading_date' => '2026-07-30',
            'odometer_km' => 999,
            'source' => 'weekly',
        ])->assertSessionHasErrors('odometer_km');

        $this->actingAs($user)->from(route('ems.mileage'))->post(route('ems.mileage.store'), [
            'ambulance_id' => $ambulance->id,
            'reading_date' => '2026-08-06',
            'odometer_km' => 1401,
            'source' => 'weekly',
        ])->assertSessionHasErrors('odometer_km');

        $this->assertSame(1400, (int) $ambulance->fresh()->odometer_km);
    }

    public function test_reentering_a_soft_deleted_weekly_reading_restores_it_instead_of_violating_the_unique_key(): void
    {
        $user = User::factory()->create();
        $ambulance = $this->ambulance(['odometer_km' => 1200]);
        $deleted = MileageReading::create([
            'ambulance_id' => $ambulance->id,
            'reading_date' => '2026-08-10',
            'odometer_km' => 1100,
            'source' => 'weekly',
            'recorded_by' => $user->id,
        ]);
        $deleted->delete();

        $this->actingAs($user)->post(route('ems.mileage.store'), [
            'ambulance_id' => $ambulance->id,
            'reading_date' => '2026-08-10',
            'odometer_km' => 1200,
            'source' => 'weekly',
            'notes' => 'Corrected reading.',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('mileage_readings', [
            'id' => $deleted->id,
            'odometer_km' => 1200,
            'notes' => 'Corrected reading.',
            'deleted_at' => null,
        ]);
        $this->assertSame(1, MileageReading::withTrashed()->count());
    }

    public function test_mileage_reading_can_be_viewed_edited_and_soft_deleted_with_audit_history(): void
    {
        $user=User::factory()->create();
        $ambulance=$this->ambulance(['odometer_km'=>3000]);
        MileageReading::create(['ambulance_id'=>$ambulance->id,'reading_date'=>'2026-07-06','odometer_km'=>2000,'source'=>'weekly','recorded_by'=>$user->id]);
        $reading=MileageReading::create(['ambulance_id'=>$ambulance->id,'reading_date'=>'2026-07-13','odometer_km'=>2500,'source'=>'weekly','recorded_by'=>$user->id]);
        $latest=MileageReading::create(['ambulance_id'=>$ambulance->id,'reading_date'=>'2026-07-20','odometer_km'=>3000,'source'=>'weekly','recorded_by'=>$user->id]);

        $this->actingAs($user)->get(route('ems.mileage.show',$reading))->assertOk()->assertSee('Mileage Reading')->assertSee('2,500 km');
        $this->actingAs($user)->put(route('ems.mileage.update',$reading),[
            'ambulance_id'=>$ambulance->id,'reading_date'=>'2026-07-13','odometer_km'=>2600,'source'=>'weekly','notes'=>'Verified correction.',
        ])->assertRedirect(route('ems.mileage'));
        $this->assertDatabaseHas('mileage_readings',['id'=>$reading->id,'odometer_km'=>2600,'notes'=>'Verified correction.']);

        $this->actingAs($user)->from(route('ems.mileage.edit',$reading))->put(route('ems.mileage.update',$reading),[
            'ambulance_id'=>$ambulance->id,'reading_date'=>'2026-07-13','odometer_km'=>3100,'source'=>'weekly',
        ])->assertSessionHasErrors('odometer_km');

        $this->actingAs($user)->delete(route('ems.mileage.destroy',$reading))->assertRedirect(route('ems.mileage'));
        $this->assertSoftDeleted('mileage_readings',['id'=>$reading->id]);
        $this->assertDatabaseHas('ems_audit_logs',['action'=>'mileage_reading.deleted','subject_id'=>$reading->id]);

        $this->actingAs($user)->delete(route('ems.mileage.destroy',$latest))->assertRedirect(route('ems.mileage'));
        $this->assertSame(2000,(int)$ambulance->fresh()->odometer_km);
        $this->actingAs($user)->post(route('ems.reports.store'),[
            'type'=>'mileage','period_start'=>'2026-07-01','period_end'=>'2026-07-31',
        ])->assertRedirect();
        $report=EmsReport::where('type','mileage')->latest('id')->firstOrFail();
        $this->assertCount(1,$report->snapshot['readings']);
        $this->assertSame(0,$report->snapshot['total_distance_km']);
    }

    private function ambulance(array $attributes = []): Ambulance
    {
        return Ambulance::create($attributes + [
            'uuid' => (string) Str::uuid(),
            'fleet_number' => 'AMBU 1',
            'registration_number' => 'GV 100-26',
            'base_location' => 'Main Clinic',
            'odometer_km' => 100,
        ]);
    }
}
