<?php

namespace Database\Seeders;

use App\Models\Ambulance;
use App\Models\AvailabilityCheck;
use App\Models\MileageReading;
use App\Models\WeeklyActivity;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class EmsDemoSeeder extends Seeder
{
    public function run(): void
    {
        $ambu1 = Ambulance::firstOrCreate(['fleet_number' => 'AMBU 1'], ['uuid' => (string) Str::uuid(), 'registration_number' => 'GPHA-EMS-01', 'make' => 'Mercedes-Benz', 'model' => 'Sprinter', 'base_location' => 'Main Clinic', 'current_location' => 'Main Clinic', 'odometer_km' => 60182, 'status' => 'available']);
        $ambu2 = Ambulance::firstOrCreate(['fleet_number' => 'AMBU 2'], ['uuid' => (string) Str::uuid(), 'registration_number' => 'GPHA-EMS-02', 'make' => 'Mercedes-Benz', 'model' => 'Sprinter', 'base_location' => 'Transit Terminal', 'current_location' => 'Transit Terminal', 'odometer_km' => 45723, 'status' => 'available']);

        foreach ([[$ambu1, 60182], [$ambu2, 45723]] as [$ambulance, $reading]) MileageReading::firstOrCreate(['ambulance_id' => $ambulance->id, 'reading_date' => today()->startOfWeek(), 'source' => 'weekly'], ['odometer_km' => $reading]);
        foreach (['AMBU 1','AMBU 2','Main Clinic','Clinic B','Golden Jubilee','Transit Terminal','KUT','Port Control','Port Security','Port Fire'] as $unit) AvailabilityCheck::firstOrCreate(['check_date' => today(), 'period' => 'morning', 'unit_name' => $unit], ['checked_at' => '07:30:00', 'responded' => true]);
        WeeklyActivity::firstOrCreate(['activity_date' => today(), 'title' => 'Routine ambulance readiness checks'], ['category' => 'inspection', 'description' => 'Daily vehicle, equipment, radio, fuel, and documentation readiness checks completed.', 'location' => 'Main Clinic']);
    }
}
