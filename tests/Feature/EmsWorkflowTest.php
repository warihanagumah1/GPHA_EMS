<?php

namespace Tests\Feature;

use App\Mail\ReportReadyForApproval;
use App\Models\Ambulance;
use App\Models\AvailabilityCheck;
use App\Models\AvailabilityUnit;
use App\Models\Dispatch;
use App\Models\EmsReport;
use App\Models\EmsAuditLog;
use App\Models\MileageReading;
use App\Models\Location;
use App\Models\User;
use App\Models\WeeklyActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
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
        $registrationField = Blade::render(
            '<x-ems.ambulance-form :locations="$locations" action="/ambulances" />',
            ['locations' => collect(['Main Clinic'])],
        );
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

    public function test_locations_are_managed_in_settings_without_changing_historical_records(): void
    {
        $user = User::factory()->create();
        $session = ['sso.permissions' => ['emssettings' => ['manage']]];

        $this->assertDatabaseHas('locations', ['name' => 'Main Clinic', 'is_active' => true]);

        $this->actingAs($user)->withSession($session)
            ->post(route('ems.settings.locations.store'), ['location_name' => 'Community Response Point'])
            ->assertRedirect(route('ems.settings').'#locations');

        $location = Location::where('name', 'Community Response Point')->firstOrFail();
        $ambulance = $this->ambulance(['base_location' => $location->name]);

        $this->actingAs($user)->withSession($session)
            ->patch(route('ems.settings.locations.status', $location), ['is_active' => 0])
            ->assertRedirect(route('ems.settings').'#locations');

        $this->actingAs($user)->post(route('ems.ambulances.store'), [
            'fleet_number' => 'AMBU 2',
            'registration_number' => 'GV 200-26',
            'base_location' => $location->name,
            'odometer_km' => 0,
        ])->assertSessionHasErrors('base_location');

        $this->actingAs($user)->put(route('ems.ambulances.update', $ambulance), [
            'fleet_number' => $ambulance->fleet_number,
            'registration_number' => $ambulance->registration_number,
            'base_location' => $ambulance->base_location,
            'odometer_km' => $ambulance->odometer_km,
        ])->assertRedirect(route('ems.ambulances'));

        $this->actingAs($user)->withSession($session)
            ->put(route('ems.settings.locations.update', $location), ['location_name' => 'Community Response Centre'])
            ->assertRedirect(route('ems.settings').'#locations');

        $this->assertSame('Community Response Point', $ambulance->fresh()->base_location);
        $this->assertDatabaseHas('ems_audit_logs', ['action' => 'location.updated', 'user_id' => $user->id]);

        $this->actingAs($user)->withSession($session)
            ->post(route('ems.settings.locations.store'), ['location_name' => 'Community Response Centre'])
            ->assertRedirect(route('ems.settings').'#locations');
        $this->assertTrue($location->fresh()->is_active);
    }

    public function test_existing_operational_locations_are_imported_without_changing_historical_records(): void
    {
        $user = User::factory()->create();
        $ambulance = $this->ambulance(['base_location' => 'Legacy Ambulance Bay']);
        $dispatch = Dispatch::create([
            'ambulance_id' => $ambulance->id,
            'reference' => 'EMS-LEGACY-LOCATION',
            'priority' => 'routine',
            'requested_at' => now(),
            'status' => 'requested',
            'origin' => 'Old Port Gate',
            'destination' => 'Legacy Treatment Centre',
            'purpose' => 'Patient transfer',
            'created_by' => $user->id,
        ]);
        $check = AvailabilityCheck::create([
            'session_uuid' => (string) Str::uuid(),
            'check_date' => today(),
            'period' => 'morning',
            'checked_at' => '08:00',
            'unit_name' => 'Main Clinic',
            'responded' => true,
            'response_location' => 'Former Response Point',
            'recorded_by' => $user->id,
        ]);

        $migration = require database_path('migrations/2026_08_19_150000_import_existing_operational_locations.php');
        $migration->up();

        foreach (['Legacy Ambulance Bay', 'Old Port Gate', 'Legacy Treatment Centre', 'Former Response Point'] as $name) {
            $this->assertDatabaseHas('locations', ['name' => $name, 'is_active' => true]);
        }
        $this->assertSame('Old Port Gate', $dispatch->fresh()->origin);
        $this->assertSame('Legacy Treatment Centre', $dispatch->fresh()->destination);
        $this->assertSame('Former Response Point', $check->fresh()->response_location);
    }

    public function test_settings_requires_the_dedicated_manage_permission(): void
    {
        $user = User::factory()->create(['sso_user_id' => (string) Str::uuid()]);
        $syncedAt = now()->timestamp;

        $this->actingAs($user)->withSession([
            'sso.permissions' => ['ambulancefleet' => ['view']],
            'sso.permissions_synced_at' => $syncedAt,
        ])->get(route('ems.settings'))->assertForbidden();

        $this->actingAs($user)->withSession([
            'sso.permissions' => ['emssettings' => ['manage']],
            'sso.permissions_synced_at' => $syncedAt,
        ])->get(route('ems.settings'))
            ->assertOk()
            ->assertSee('Locations')
            ->assertSee('Departments / Units');
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
        $this->actingAs($user)->get(route('dashboard', $filters))
            ->assertOk()
            ->assertDontSee('Emergency Operations Centre')
            ->assertDontSee('Operational Overview')
            ->assertDontSee('Period-based movement, fleet, readiness, and activity performance.')
            ->assertSee('Add Movement')
            ->assertSee('Manage Reports')
            ->assertDontSee('Export CSV')
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
            ->assertDontSee('Recent Movements')
            ->assertDontSee('Fleet Readiness');

        $this->actingAs($user)->get(route('ems.reports'))
            ->assertOk()
            ->assertSee('Formal EMS Reporting')
            ->assertSee('Generate Report')
            ->assertSee('All Reports')
            ->assertDontSee('Management Analytics')
            ->assertDontSee('Download Snapshot');

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

            $this->actingAs($user)->get(route('dashboard'))->assertOk()
                ->assertViewHas('filters',fn($filters)=>$filters['period_preset']==='this_week'&&$filters['period_start']==='2026-08-16'&&$filters['period_end']==='2026-08-19');

            $this->actingAs($user)->get(route('dashboard',['period_preset'=>'last_month']))
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

    public function test_dashboard_fleet_movement_load_only_lists_the_selected_ambulance(): void
    {
        $user = User::factory()->create();
        $first = $this->ambulance();
        $second = $this->ambulance([
            'fleet_number' => 'AMBU 2',
            'registration_number' => 'GV 200-26',
        ]);
        Dispatch::create([
            'reference' => 'EMS-FIRST-ONLY',
            'ambulance_id' => $first->id,
            'origin' => 'Main Clinic',
            'destination' => 'Clinic B',
            'purpose' => 'Patient transfer',
            'priority' => 'routine',
            'status' => 'completed',
            'requested_at' => now(),
            'completed_at' => now(),
        ]);

        $this->actingAs($user)->get(route('dashboard', [
            'period_start' => today()->toDateString(),
            'period_end' => today()->toDateString(),
            'ambulance_id' => $second->id,
        ]))
            ->assertOk()
            ->assertViewHas('ambulanceMovementCounts', fn ($counts) => $counts->all() === ['AMBU 2' => 0])
            ->assertSee('Movement volume handled by the selected ambulance.')
            ->assertDontSee('No fleet movement load for this period.');

        $this->actingAs($user)->get(route('dashboard', [
            'period_start' => today()->toDateString(),
            'period_end' => today()->toDateString(),
        ]))
            ->assertOk()
            ->assertViewHas('ambulanceMovementCounts', fn ($counts) => $counts->all() === ['AMBU 1' => 1, 'AMBU 2' => 0])
            ->assertSee('Movement volume handled by each ambulance.')
            ->assertDontSee('Movement volume handled by the selected ambulance.');
    }

    public function test_dashboard_displays_the_current_users_normalized_permission_dump(): void
    {
        $user = User::factory()->create();
        $permissions = [
            'emsreports' => ['view', 'manage'],
            'ambulancefleet' => ['view'],
        ];

        $this->actingAs($user)->withSession(['sso.permissions' => $permissions])->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-user-permissions', false)
            ->assertSee('Current User Permissions')
            ->assertSee('&quot;emsreports&quot;', false)
            ->assertSee('&quot;manage&quot;', false)
            ->assertDontSee('&quot;approve&quot;', false);
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
            ->assertSee('type="text" name="checked_at"', false)
            ->assertSee('placeholder="HH:MM"', false)
            ->assertSee('pattern="(?:[01][0-9]|2[0-3]):[0-5][0-9]"', false)
            ->assertSee('data-time-picker', false)
            ->assertSee('aria-label="Open 24-hour clock selector"', false)
            ->assertSee('Hour in 24-hour format')
            ->assertSee('Set Time')
            ->assertSee('24-hour format (HH:MM)')
            ->assertDontSee('type="time" name="checked_at"', false);

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

        $this->actingAs($user)->post(route('ems.availability.store'), [
            'check_date' => today()->toDateString(),
            'period' => 'afternoon',
            'checked_at' => '02:48 PM',
            'checks' => [
                ['unit_name' => 'Main Clinic', 'responded' => '1'],
            ],
        ])->assertSessionHasErrors('checked_at');
    }

    public function test_evening_availability_sessions_can_be_created_edited_and_filtered(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('ems.availability', ['new' => 1]))
            ->assertOk()
            ->assertSee('value="evening"', false)
            ->assertSee('Evening');

        $this->actingAs($user)->post(route('ems.availability.store'), [
            'check_date' => today()->toDateString(),
            'period' => 'evening',
            'checked_at' => '19:30',
            'checks' => [
                ['unit_name' => 'Main Clinic', 'responded' => '1', 'response_location' => 'Main Clinic'],
            ],
        ])->assertRedirect();

        $check = AvailabilityCheck::where('period', 'evening')->firstOrFail();

        $this->actingAs($user)->get(route('ems.availability', ['period' => 'evening']))
            ->assertOk()
            ->assertSee('Evening');

        $this->actingAs($user)->get(route('ems.availability.sessions.edit', $check->session_uuid))
            ->assertOk()
            ->assertSee('value="evening"', false)
            ->assertSee('type="text" name="checked_at" value="19:30"', false)
            ->assertSee('aria-label="Open 24-hour clock selector"', false)
            ->assertSee('24-hour format (HH:MM)');

        $this->assertDatabaseHas('availability_checks', [
            'id' => $check->id,
            'period' => 'evening',
            'checked_at' => '19:30',
        ]);
    }

    public function test_availability_units_are_managed_in_settings_without_changing_previous_checks_or_reports(): void
    {
        $user = User::factory()->create();
        $session = [
            'sso.permissions' => [
                'readinessandactivities' => ['view', 'manage'],
                'emsreports' => ['view', 'manage'],
                'emssettings' => ['manage'],
            ],
        ];

        $this->assertDatabaseHas('availability_units', [
            'name' => 'Fishing Harbour Clinic',
            'is_active' => true,
        ]);

        $this->actingAs($user)->withSession($session)->get(route('ems.settings'))
            ->assertOk()
            ->assertSee('Departments / Units')
            ->assertSee('Fishing Harbour Clinic');

        $this->actingAs($user)->withSession($session)->get(route('ems.availability', ['new' => 1]))
            ->assertOk()
            ->assertDontSee('Manage Units')
            ->assertSee('aria-label="Include Fishing Harbour Clinic"', false);

        $this->actingAs($user)->withSession($session)->post(route('ems.settings.units.store'), [
            'unit_name' => 'Harbour Master Office',
        ])->assertRedirect(route('ems.settings').'#units');

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

        $this->actingAs($user)->withSession($session)->patch(route('ems.settings.units.status', $unit), [
            'is_active' => 0,
        ])->assertRedirect(route('ems.settings').'#units');

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
                ->assertSeeInOrder(['EMS Report Officer', 'EMS Officer', 'Prepared:'])
                ->assertSee('Sign and Submit Report')
                ->assertSee('.table-wrap table{border:1px solid #7e95b4}',false)
                ->assertSee('class="print-table-footer"',false)
                ->assertDontSee('Report ID:')
                ->assertSee('Awaiting approval')
                ->assertSee('Draft');
            if($type==='weekly_activity')$printResponse->assertDontSee('Category')->assertDontSee('class="activity-category"',false);
            if($type==='availability')$printResponse->assertSee('Unit Responses')->assertSee('Main Clinic');
        }
    }

    public function test_reports_require_submitter_and_approver_signatures_and_keep_a_complete_workflow_record(): void
    {
        Storage::fake('local');
        Mail::fake();
        config([
            'ems.report_approvers' => 'Dr. Emile <emasiedu@ghanaports.gov.gov.gh>, Dr. Ama Mensah <ama.mensah@ghanaports.gov.gh>',
        ]);
        $submitter = User::factory()->create(['name' => 'Warihana Gumah', 'job_title' => 'SAEMT']);
        $approver = User::factory()->create(['name' => 'EMS Director', 'job_title' => 'Director, Medical Services']);
        $signature = 'data:image/png;base64,'.base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));

        $this->actingAs($submitter)->post(route('ems.reports.store'), [
            'type' => 'availability',
            'period_preset' => 'today',
        ])->assertRedirect();
        $report = EmsReport::latest('id')->firstOrFail();

        $this->actingAs($submitter)->get(route('ems.reports.print', $report))
            ->assertOk()
            ->assertDontSee('Signature method')
            ->assertSee('Draw your signature')
            ->assertSee('Upload a signature image');

        $this->actingAs($submitter)->get(route('ems.reports'))
            ->assertOk()
            ->assertSee('All Reports')
            ->assertSee('Report Status')
            ->assertSee('Awaiting Approval')
            ->assertSee('Review & Sign')
            ->assertSee('Print / Download PDF')
            ->assertSee('href="'.route('ems.reports.print', ['report' => $report, 'print' => 1]).'"', false)
            ->assertSeeInOrder(['SAEMT', 'Warihana Gumah']);

        $this->actingAs($submitter)->get(route('ems.reports.print', ['report' => $report, 'print' => 1]))
            ->assertOk()
            ->assertSee('data-auto-print', false);

        $this->actingAs($submitter)->from(route('ems.reports.print', $report))->post(route('ems.reports.submit', $report), [
            'signature_confirmation' => '1',
        ])->assertSessionHasErrors('signature_data');

        $this->actingAs($submitter)->post(route('ems.reports.submit', $report), [
            'signature_data' => $signature,
            'signature_confirmation' => '1',
        ])->assertRedirect(route('ems.reports.print', $report));

        $report->refresh();
        $this->assertSame('submitted', $report->status);
        $this->assertSame($submitter->id, $report->submitted_by);
        $this->assertNotNull($report->submitted_at);
        Storage::disk('local')->assertExists($report->submitter_signature_path);
        Mail::assertSent(ReportReadyForApproval::class, function (ReportReadyForApproval $mail) use ($report, $submitter) {
            $html = $mail->render();
            $samePathOnInternalHost = preg_replace('#^https?://[^/]+#', 'http://172.16.0.81', $mail->approvalUrl);

            return $mail->hasTo('emasiedu@ghanaports.gov.gov.gh')
                && $mail->report->is($report)
                && str_contains($mail->approvalUrl, '/report-approval/'.$report->uuid)
                && URL::hasValidRelativeSignature(\Illuminate\Http\Request::create($mail->approvalUrl))
                && URL::hasValidRelativeSignature(\Illuminate\Http\Request::create($samePathOnInternalHost))
                && str_contains($html, 'EMS Report Ready for Approval')
                && str_contains($html, 'Dear Dr. Emile,')
                && str_contains($html, 'Review &amp; Sign Report')
                && str_contains($html, 'No login or EMS permission is required')
                && str_contains($html, 'must be reviewed and approved within')
                && str_contains($html, '<strong>72 hours</strong>')
                && str_contains($html, 'Regards,')
                && str_contains($html, e($submitter->name))
                && str_contains($html, 'background:#092f6d')
                && str_contains($html, 'background:#d51f26')
                && str_contains($html, 'background:#00579b');
        });
        Mail::assertSent(ReportReadyForApproval::class, 2);
        Mail::assertSent(ReportReadyForApproval::class, fn (ReportReadyForApproval $mail) => $mail->hasTo('ama.mensah@ghanaports.gov.gh') && str_contains($mail->render(), 'Dear Dr. Ama Mensah,'));

        $uploadedSignature = UploadedFile::fake()->createWithContent('director-signature.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
        $this->actingAs($approver)->withSession(['sso.permissions' => ['emsreports' => ['view', 'approve']]])->patch(route('ems.reports.approve', $report), [
            'signature_file' => $uploadedSignature,
            'signature_confirmation' => '1',
        ])->assertRedirect();

        $report->refresh();
        $this->assertSame('approved', $report->status);
        $this->assertSame($approver->id, $report->approved_by);
        $this->assertSame('upload', $report->approver_signature_method);
        $this->assertNotNull($report->approved_at);
        Storage::disk('local')->assertExists($report->approver_signature_path);

        $this->actingAs($approver)->get(route('ems.reports.print', $report))
            ->assertOk()
            ->assertSeeInOrder(['Warihana Gumah', 'SAEMT'])
            ->assertSeeInOrder(['EMS Director', 'Director, Medical Services'])
            ->assertSee('Approved')
            ->assertDontSee('Approve Report');
        $this->actingAs($approver)->get(route('ems.reports.file', [$report, 'approver-signature']))->assertOk();
    }

    public function test_a_temporary_shared_link_allows_guest_review_signing_and_private_file_access(): void
    {
        Storage::fake('local');
        config([
            'ems.report_approvers' => 'Dr. Emile <emasiedu@ghanaports.gov.gov.gh>',
            'ems.report_approval_link_hours' => 48,
        ]);
        $submitter = User::factory()->create(['name' => 'Warihana Gumah', 'job_title' => 'SAEMT']);
        $signaturePath = 'ems-report-signatures/shared/submitter.png';
        Storage::disk('local')->put($signaturePath, 'signature-image');
        $report = EmsReport::create([
            'type' => 'availability',
            'period_start' => today(),
            'period_end' => today(),
            'status' => 'submitted',
            'snapshot' => [],
            'prepared_by' => $submitter->id,
            'submitted_by' => $submitter->id,
            'submitted_at' => now(),
            'submitter_signature_method' => 'upload',
            'submitter_signature_path' => $signaturePath,
        ]);

        $this->get(route('ems.reports.guest-approval', $report))->assertForbidden();

        $approvalUrl = URL::temporarySignedRoute('ems.reports.guest-approval', now()->addHours(48), ['report' => $report], absolute: false);
        $review = $this->get($approvalUrl)
            ->assertOk()
            ->assertSee('Approve Report')
            ->assertSee('Sign & Approve Report')
            ->assertDontSee('Back to Reports');
        $this->assertGuest();

        $signatureUrl = URL::temporarySignedRoute('ems.reports.guest-file', now()->addHours(48), [
            'report' => $report,
            'file' => 'submitter-signature',
        ], absolute: false);
        $this->get($signatureUrl)->assertOk();
        $this->get(route('ems.reports.guest-file', [$report, 'submitter-signature']))->assertForbidden();

        $drawnSignature = 'data:image/png;base64,'.base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
        $approve = $this->patch($review->viewData('guestApproveUrl'), [
            'signature_data' => $drawnSignature,
            'signature_confirmation' => '1',
        ])->assertRedirect();

        $report->refresh();
        $this->assertSame('approved', $report->status);
        $this->assertNull($report->approved_by);
        $this->assertSame('Dr. Emile', $report->approved_by_name);
        $this->assertSame('emasiedu@ghanaports.gov.gov.gh', $report->approved_by_email);
        Storage::disk('local')->assertExists($report->approver_signature_path);
        $this->get($approve->headers->get('Location'))
            ->assertOk()
            ->assertSee('Dr. Emile')
            ->assertDontSee('Approve Report');
        $this->assertGuest();
    }

    public function test_a_submitter_with_approve_permission_can_review_sign_and_approve_their_report(): void
    {
        Storage::fake('local');
        $user = User::factory()->create([
            'sso_user_id' => (string) Str::uuid(),
            'name' => 'EMS Approver',
        ]);
        $report = EmsReport::withoutGlobalScopes()->create([
            'type' => 'mileage',
            'period_start' => today(),
            'period_end' => today(),
            'status' => 'submitted',
            'snapshot' => [],
            'prepared_by' => $user->id,
            'submitted_by' => $user->id,
            'submitted_at' => now(),
            'branch_code' => 'HQ',
        ]);
        $session = [
            'sso.permissions_synced_at' => now()->timestamp,
            'sso.active_branch_code' => 'HQ',
            'sso.branches.codes' => ['HQ'],
            'sso.permissions' => ['emsreports' => ['view','manage','approve']],
        ];

        $this->actingAs($user)->withSession($session)->get(route('ems.reports'))
            ->assertOk()
            ->assertSee('Review &amp; Approve', false)
            ->assertDontSee('Export Data CSV')
            ->assertViewHas('reports', fn ($reports) => $reports->pluck('id')->all() === [$report->id]);
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('ems.reports.export'));

        $this->actingAs($user)->withSession($session)->get(route('ems.reports.print', $report))
            ->assertOk()
            ->assertSee('Approve Report')
            ->assertSee('Sign & Approve Report');

        $signature = 'data:image/png;base64,'.base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
        $this->actingAs($user)->withSession($session)->patch(route('ems.reports.approve', $report), [
            'signature_data' => $signature,
            'signature_confirmation' => '1',
        ])->assertRedirect();

        $report->refresh();
        $this->assertSame('approved', $report->status);
        $this->assertSame($user->id, $report->approved_by);
        Storage::disk('local')->assertExists($report->approver_signature_path);
    }

    public function test_a_report_submitter_without_approve_permission_cannot_see_or_use_approval(): void
    {
        Storage::fake('local');
        $user = User::factory()->create([
            'sso_user_id' => (string) Str::uuid(),
            'name' => 'Report Submitter',
        ]);
        $report = EmsReport::withoutGlobalScopes()->create([
            'type' => 'mileage',
            'period_start' => today(),
            'period_end' => today(),
            'status' => 'submitted',
            'snapshot' => [],
            'prepared_by' => $user->id,
            'submitted_by' => $user->id,
            'submitted_at' => now(),
            'branch_code' => 'HQ',
        ]);
        $session = [
            'sso.permissions_synced_at' => now()->timestamp,
            'sso.active_branch_code' => 'HQ',
            'sso.branches.codes' => ['HQ'],
            'sso.permissions' => ['emsreports' => ['view', 'manage']],
        ];

        $this->actingAs($user)->withSession($session)->get(route('ems.reports.print', $report))
            ->assertOk()
            ->assertDontSee('Approve Report')
            ->assertDontSee('Sign &amp; Approve Report', false);

        $signature = 'data:image/png;base64,'.base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
        $this->actingAs($user)->withSession($session)->patch(route('ems.reports.approve', $report), [
            'signature_data' => $signature,
            'signature_confirmation' => '1',
        ])->assertForbidden();

        $report->refresh();
        $this->assertSame('submitted', $report->status);
        $this->assertNull($report->approved_at);
        $this->assertNull($report->approver_signature_path);
    }

    public function test_the_approval_form_has_only_one_upload_and_rejects_physical_report_pdfs(): void
    {
        Storage::fake('local');
        $submitter = User::factory()->create();
        $approver = User::factory()->create();
        $approveSession = ['sso.permissions' => ['emsreports' => ['view', 'approve']]];
        $report = EmsReport::create([
            'type' => 'mileage',
            'period_start' => today(),
            'period_end' => today(),
            'status' => 'submitted',
            'snapshot' => [],
            'prepared_by' => $submitter->id,
            'submitted_by' => $submitter->id,
            'submitted_at' => now(),
        ]);

        $this->actingAs($approver)->withSession($approveSession)->get(route('ems.reports.print', $report))
            ->assertOk()
            ->assertSee('name="signature_file"', false)
            ->assertDontSee('name="signed_report"', false)
            ->assertDontSee('Upload a physically signed report');

        $this->actingAs($approver)->withSession($approveSession)->patch(route('ems.reports.approve', $report), [
            'signed_report' => UploadedFile::fake()->create('signed-report.pdf', 100, 'application/pdf'),
            'signature_confirmation' => '1',
        ])->assertSessionHasErrors('signed_report');

        $report->refresh();
        $this->assertSame('submitted', $report->status);
        $this->assertNull($report->approver_signature_method);
        $this->assertNull($report->signed_report_path);
    }

    public function test_signature_images_are_cropped_for_display_and_scaled_to_fill_the_signature_area(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $path = 'ems-report-signatures/crop-test/submitter.png';
        $canvas = imagecreatetruecolor(700, 180);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 255, 255, 255, 127));
        imagealphablending($canvas, true);
        imagesetthickness($canvas, 4);
        imageline($canvas, 20, 70, 75, 95, imagecolorallocate($canvas, 10, 30, 60));
        ob_start();
        imagepng($canvas);
        Storage::disk('local')->put($path, ob_get_clean());
        imagedestroy($canvas);
        $report = EmsReport::create([
            'type' => 'mileage',
            'period_start' => today(),
            'period_end' => today(),
            'status' => 'submitted',
            'snapshot' => [],
            'prepared_by' => $user->id,
            'submitted_by' => $user->id,
            'submitted_at' => now(),
            'submitter_signature_method' => 'drawn',
            'submitter_signature_path' => $path,
        ]);

        $this->actingAs($user)->get(route('ems.reports.file', [$report, 'submitter-signature']))->assertOk();

        $normalizedPath = $path.'.normalized.png';
        Storage::disk('local')->assertExists($path);
        Storage::disk('local')->assertExists($normalizedPath);
        $dimensions = getimagesizefromstring(Storage::disk('local')->get($normalizedPath));
        $this->assertLessThan(150, $dimensions[0]);
        $this->assertLessThan(100, $dimensions[1]);
        $this->actingAs($user)->get(route('ems.reports.print', $report))
            ->assertOk()
            ->assertSee('width:100%;max-width:320px;height:100px', false);
    }

    public function test_submitted_reports_can_be_edited_or_deleted_by_the_preparer_but_approved_reports_are_locked(): void
    {
        Storage::fake('local');
        $preparer = User::factory()->create();
        $otherUser = User::factory()->create();
        $signaturePath = 'ems-report-signatures/test/submitter.png';
        Storage::disk('local')->put($signaturePath, 'signature');
        $submitted = EmsReport::create([
            'type' => 'mileage',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-07',
            'status' => 'submitted',
            'snapshot' => [],
            'prepared_by' => $preparer->id,
            'submitted_by' => $preparer->id,
            'submitted_at' => now(),
            'submitter_signature_method' => 'upload',
            'submitter_signature_path' => $signaturePath,
        ]);

        $this->actingAs($otherUser)->get(route('ems.reports.edit', $submitted))->assertForbidden();
        $this->actingAs($preparer)->get(route('ems.reports.edit', $submitted))
            ->assertOk()
            ->assertSee('Edit Submitted Report')
            ->assertSee('returns it to Draft')
            ->assertSee('href="'.route('ems.reports').'"', false)
            ->assertSee('<div class="gpha-page-shell space-y-6">', false)
            ->assertDontSee('gpha-page-shell max-w-5xl', false);

        $updateResponse = $this->actingAs($preparer)->put(route('ems.reports.update', $submitted), [
            'type' => 'availability',
            'period_preset' => 'custom',
            'period_start' => '2026-08-08',
            'period_end' => '2026-08-14',
        ]);
        $updateResponse->assertRedirect(route('ems.reports'))
            ->assertSessionHas('success', 'Report updated and returned to Draft.')
            ->assertSessionHas('success_report_uuid', $submitted->uuid);

        $this->get(route('ems.reports'))
            ->assertOk()
            ->assertSee('View Report')
            ->assertSee('href="'.route('ems.reports.print', $submitted).'"', false);

        $submitted->refresh();
        $this->assertSame('draft', $submitted->status);
        $this->assertSame('availability', $submitted->type);
        $this->assertNull($submitted->submitted_by);
        $this->assertNull($submitted->submitted_at);
        $this->assertNull($submitted->submitter_signature_path);
        Storage::disk('local')->assertMissing($signaturePath);

        $this->actingAs($preparer)->delete(route('ems.reports.destroy', $submitted))
            ->assertRedirect(route('ems.reports'));
        $this->assertDatabaseMissing('ems_reports', ['id' => $submitted->id]);

        $approved = EmsReport::create([
            'type' => 'weekly_activity',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-07',
            'status' => 'approved',
            'snapshot' => [],
            'prepared_by' => $preparer->id,
            'submitted_by' => $preparer->id,
            'submitted_at' => now(),
            'approved_by' => $otherUser->id,
            'approved_at' => now(),
        ]);

        $this->actingAs($preparer)->get(route('ems.reports.edit', $approved))->assertStatus(422);
        $this->actingAs($preparer)->delete(route('ems.reports.destroy', $approved))->assertStatus(422);
        $this->assertDatabaseHas('ems_reports', ['id' => $approved->id, 'status' => 'approved']);
    }

    public function test_report_approvers_can_filter_all_reports_while_preparers_only_see_their_own(): void
    {
        $firstPreparer = User::factory()->create(['sso_user_id' => (string) Str::uuid(), 'name' => 'First Preparer']);
        $secondPreparer = User::factory()->create(['sso_user_id' => (string) Str::uuid(), 'name' => 'Second Preparer']);
        $approver = User::factory()->create(['sso_user_id' => (string) Str::uuid(), 'name' => 'Report Approver']);
        $draft = EmsReport::withoutGlobalScopes()->create([
            'type' => 'mileage', 'period_start' => today(), 'period_end' => today(), 'status' => 'draft',
            'snapshot' => [], 'prepared_by' => $firstPreparer->id, 'branch_code' => 'HQ',
        ]);
        $submitted = EmsReport::withoutGlobalScopes()->create([
            'type' => 'availability', 'period_start' => today(), 'period_end' => today(), 'status' => 'submitted',
            'snapshot' => [], 'prepared_by' => $secondPreparer->id, 'submitted_by' => $secondPreparer->id,
            'submitted_at' => now(), 'branch_code' => 'HQ',
        ]);
        $approved = EmsReport::withoutGlobalScopes()->create([
            'type' => 'weekly_activity', 'period_start' => today(), 'period_end' => today(), 'status' => 'approved',
            'snapshot' => [], 'prepared_by' => $secondPreparer->id, 'submitted_by' => $secondPreparer->id,
            'submitted_at' => now(), 'approved_by' => $approver->id, 'approved_at' => now(), 'branch_code' => 'HQ',
        ]);
        $baseSession = [
            'sso.permissions_synced_at' => now()->timestamp,
            'sso.active_branch_code' => 'HQ',
            'sso.branches.codes' => ['HQ'],
        ];

        $this->actingAs($firstPreparer)->withSession($baseSession + [
            'sso.permissions' => ['emsreports' => ['view','manage']],
        ])->get(route('ems.reports'))
            ->assertOk()
            ->assertSee('Ambulance Mileage')
            ->assertViewHas('reports', fn ($reports) => $reports->pluck('id')->all() === [$draft->id]);

        $this->actingAs($approver)->withSession($baseSession + [
            'sso.permissions' => ['emsreports' => ['view','approve']],
        ])->get(route('ems.reports'))
            ->assertOk()
            ->assertSee('All Reports')
            ->assertSee('Submitted Reports')
            ->assertSee('Approved Reports')
            ->assertSee('Report Type')
            ->assertSee('Report Date From')
            ->assertSee('Report Date To')
            ->assertSee('Apply Filters')
            ->assertViewHas('activeReportTab', 'all')
            ->assertViewHas('reports', fn ($reports) => $reports->pluck('id')->sort()->values()->all() === collect([$draft->id,$submitted->id,$approved->id])->sort()->values()->all());

        $this->actingAs($approver)->withSession($baseSession + [
            'sso.permissions' => ['emsreports' => ['view','approve']],
        ])->get(route('ems.reports', ['report_tab' => 'submitted', 'report_type' => 'availability']))
            ->assertOk()
            ->assertSee('aria-current="page"', false)
            ->assertSee('Radio &amp; Availability', false)
            ->assertSee('Review &amp; Approve', false)
            ->assertDontSee('Export Data CSV')
            ->assertViewHas('activeReportTab', 'submitted')
            ->assertViewHas('reportFilters', fn ($filters) => $filters['report_type'] === 'availability')
            ->assertViewHas('reports', fn ($reports) => $reports->pluck('id')->all() === [$submitted->id]);

        $this->actingAs($approver)->withSession($baseSession + [
            'sso.permissions' => ['emsreports' => ['view','approve']],
        ])->get(route('ems.reports', ['report_tab' => 'approved']))
            ->assertOk()
            ->assertViewHas('activeReportTab', 'approved')
            ->assertViewHas('reports', fn ($reports) => $reports->pluck('id')->all() === [$approved->id]);
    }

    public function test_reports_list_can_be_filtered_by_a_report_generation_date_range(): void
    {
        $preparer = User::factory()->create();
        $older = EmsReport::create([
            'type' => 'mileage',
            'period_start' => '2026-08-10',
            'period_end' => '2026-08-10',
            'status' => 'draft',
            'snapshot' => [],
            'prepared_by' => $preparer->id,
            'created_at' => '2026-08-18 09:00:00',
        ]);
        EmsReport::create([
            'type' => 'availability',
            'period_start' => '2026-08-11',
            'period_end' => '2026-08-11',
            'status' => 'draft',
            'snapshot' => [],
            'prepared_by' => $preparer->id,
            'created_at' => '2026-08-19 09:00:00',
        ]);

        $this->actingAs($preparer)->get(route('ems.reports', [
            'report_date_from' => '2026-08-17',
            'report_date_to' => '2026-08-18',
        ]))
            ->assertOk()
            ->assertSee('Report Date From')
            ->assertSee('Report Date To')
            ->assertSee('value="2026-08-17"', false)
            ->assertSee('value="2026-08-18"', false)
            ->assertViewHas('reports', fn ($reports) => $reports->pluck('id')->all() === [$older->id]);

        $this->actingAs($preparer)->get(route('ems.reports', [
            'report_date_from' => '2026-08-19',
            'report_date_to' => '2026-08-18',
        ]))->assertSessionHasErrors('report_date_to');
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
