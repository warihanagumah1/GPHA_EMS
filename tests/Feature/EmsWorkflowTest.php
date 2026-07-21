<?php

namespace Tests\Feature;

use App\Models\Ambulance;
use App\Models\AvailabilityCheck;
use App\Models\Dispatch;
use App\Models\EmsReport;
use App\Models\EmsAuditLog;
use App\Models\MileageReading;
use App\Models\User;
use App\Models\WeeklyActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        ])->assertRedirect();

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
            'priority' => 'urgent',
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

    public function test_completed_movement_can_be_edited_and_soft_deleted_with_a_detailed_audit_trail(): void
    {
        $user=User::factory()->create();
        $ambulance=$this->ambulance();
        $movement=Dispatch::create(['uuid'=>(string)Str::uuid(),'reference'=>'EMS-AUDIT-001','ambulance_id'=>$ambulance->id,'priority'=>'routine','status'=>'completed','origin'=>'Main Clinic','destination'=>'Clinic B','purpose'=>'Patient transfer','requested_at'=>now(),'completed_at'=>now(),'created_by'=>$user->id]);

        $this->actingAs($user)->get(route('ems.dispatches.show',$movement))->assertOk()->assertDontSee('Crew Lead')->assertDontSee('Odometer')->assertDontSee('Distance:');
        $this->actingAs($user)->put(route('ems.dispatches.update',$movement),['ambulance_id'=>$ambulance->id,'priority'=>'urgent','requested_at'=>$movement->requested_at->format('Y-m-d H:i:s'),'status'=>'completed','origin'=>'Main Clinic','destination'=>'Clinic B','purpose'=>'Emergency response','notes'=>'Corrected after review.'])->assertRedirect(route('ems.dispatches.show',$movement));
        $this->assertDatabaseHas('dispatches',['id'=>$movement->id,'priority'=>'urgent','purpose'=>'Emergency response']);
        $updatedAudit=EmsAuditLog::where('action','movement.updated')->where('subject_reference','EMS-AUDIT-001')->latest('id')->firstOrFail();
        $this->assertSame('routine',$updatedAudit->old_values['priority']);
        $this->assertSame('urgent',$updatedAudit->new_values['priority']);

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
            'priority' => 'urgent',
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
            ->assertSee('Movement Trend')
            ->assertSee('25 km')
            ->assertDontSee('Fleet Performance')
            ->assertDontSee('Movement Details')
            ->assertDontSee('Generated Printable Reports');

        $this->actingAs($user)->get(route('ems.reports.operations.export', $filters))->assertDownload();
    }

    public function test_movement_list_can_be_filtered_by_operational_fields(): void
    {
        $user=User::factory()->create();
        $ambulance=$this->ambulance();
        Dispatch::create(['reference'=>'EMS-FILTER-URGENT','ambulance_id'=>$ambulance->id,'origin'=>'Main Clinic','destination'=>'Tema General Hospital','purpose'=>'Patient transfer','priority'=>'urgent','status'=>'completed','requested_at'=>now()]);
        Dispatch::create(['reference'=>'EMS-FILTER-ROUTINE','ambulance_id'=>$ambulance->id,'origin'=>'Main Clinic','destination'=>'KUT Terminal','purpose'=>'Routine operational movement','priority'=>'routine','status'=>'requested','requested_at'=>now()]);

        $this->actingAs($user)->get(route('ems.dispatches',['priority'=>'urgent','status'=>'completed','purpose'=>'Patient transfer']))
            ->assertOk()->assertSee('EMS-FILTER-URGENT')->assertDontSee('EMS-FILTER-ROUTINE')->assertSee('Apply Filters');
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
        $this->actingAs($user)->get(route('ems.availability.sessions.edit',$session))->assertOk()->assertSee('Mark All Responded');
        $checks=AvailabilityCheck::where('session_uuid',$session)->orderBy('id')->get();
        $this->actingAs($user)->put(route('ems.availability.sessions.update',$session),[
            'check_date'=>today()->toDateString(),'period'=>'morning','checked_at'=>'07:45',
            'checks'=>$checks->map(fn($check)=>['id'=>$check->id,'responded'=>'1','response_location'=>'Main Clinic','observation'=>'Confirmed'])->all(),
        ])->assertRedirect(route('ems.availability.sessions.show',$session));
        $this->assertDatabaseMissing('availability_checks',['session_uuid'=>$session,'responded'=>false]);

        $this->actingAs($user)->delete(route('ems.availability.sessions.destroy',$session))->assertRedirect(route('ems.availability'));
        $this->assertSame(2,AvailabilityCheck::withTrashed()->where('session_uuid',$session)->whereNotNull('deleted_at')->count());
        $this->assertDatabaseHas('ems_audit_logs',['action'=>'availability_check.deleted','user_id'=>$user->id]);
    }

    public function test_activity_can_be_viewed_edited_and_soft_deleted_with_audit_history(): void
    {
        $user=User::factory()->create();
        $activity=WeeklyActivity::create(['activity_date'=>today(),'category'=>'meeting','title'=>'Morning briefing','description'=>'<p>Morning briefing held.</p>','created_by'=>$user->id]);

        $this->actingAs($user)->get(route('ems.activities'))->assertOk()->assertSee('Activity actions');
        $this->actingAs($user)->get(route('ems.activities.show',$activity))->assertOk()->assertSee('Morning briefing held.');
        $this->actingAs($user)->put(route('ems.activities.update',$activity),[
            'activity_date'=>today()->toDateString(),'category'=>'inspection','description'=>'<p><strong>Ambulance inspected.</strong></p>','outcome'=>'<p>Ready for service.</p>',
        ])->assertRedirect(route('ems.activities.show',$activity));
        $this->assertDatabaseHas('weekly_activities',['id'=>$activity->id,'category'=>'inspection','title'=>'Ambulance inspected']);
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
            'priority' => 'urgent',
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
            'mileage' => 'Weekly Mileage Report',
            'weekly_activity' => 'Weekly Report',
            'availability' => 'EMS Weekly Availability Report',
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
                ->assertDontSee('Report ID:')
                ->assertDontSee('Awaiting approval')
                ->assertDontSee('Draft');
            if($type==='availability')$printResponse->assertSee('Unit Responses')->assertSee('Main Clinic');
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

    public function test_mileage_reading_can_be_viewed_edited_and_soft_deleted_with_audit_history(): void
    {
        $user=User::factory()->create();
        $ambulance=$this->ambulance(['odometer_km'=>3000]);
        MileageReading::create(['ambulance_id'=>$ambulance->id,'reading_date'=>'2026-07-06','odometer_km'=>2000,'source'=>'weekly','recorded_by'=>$user->id]);
        $reading=MileageReading::create(['ambulance_id'=>$ambulance->id,'reading_date'=>'2026-07-13','odometer_km'=>2500,'source'=>'weekly','recorded_by'=>$user->id]);
        MileageReading::create(['ambulance_id'=>$ambulance->id,'reading_date'=>'2026-07-20','odometer_km'=>3000,'source'=>'weekly','recorded_by'=>$user->id]);

        $this->actingAs($user)->get(route('ems.mileage.show',$reading))->assertOk()->assertSee('Mileage Reading')->assertSee('2,500 km');
        $this->actingAs($user)->put(route('ems.mileage.update',$reading),[
            'ambulance_id'=>$ambulance->id,'reading_date'=>'2026-07-13','odometer_km'=>2600,'source'=>'weekly','notes'=>'Verified correction.',
        ])->assertRedirect(route('ems.mileage.show',$reading));
        $this->assertDatabaseHas('mileage_readings',['id'=>$reading->id,'odometer_km'=>2600,'notes'=>'Verified correction.']);

        $this->actingAs($user)->from(route('ems.mileage.edit',$reading))->put(route('ems.mileage.update',$reading),[
            'ambulance_id'=>$ambulance->id,'reading_date'=>'2026-07-13','odometer_km'=>3100,'source'=>'weekly',
        ])->assertSessionHasErrors('odometer_km');

        $this->actingAs($user)->delete(route('ems.mileage.destroy',$reading))->assertRedirect(route('ems.mileage'));
        $this->assertSoftDeleted('mileage_readings',['id'=>$reading->id]);
        $this->assertDatabaseHas('ems_audit_logs',['action'=>'mileage_reading.deleted','subject_id'=>$reading->id]);
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
