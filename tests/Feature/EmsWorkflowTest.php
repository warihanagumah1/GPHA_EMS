<?php

namespace Tests\Feature;

use App\Models\Ambulance;
use App\Models\AvailabilityCheck;
use App\Models\Dispatch;
use App\Models\EmsReport;
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
            'origin' => 'Unknown Location',
            'destination' => 'Tema General Hospital',
            'purpose' => 'Unknown Case',
        ])->assertSessionHasErrors(['origin', 'purpose']);

        $this->actingAs($user)->post(route('ems.dispatches.store'), [
            'ambulance_id' => $ambulance->id,
            'priority' => 'urgent',
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
            $this->actingAs($user)->get(route('ems.reports.print', $report))
                ->assertOk()
                ->assertSee($title)
                ->assertSee('Summary of Findings')
                ->assertSee('Recommendations')
                ->assertSee('Print / Save PDF');
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
