<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160)->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        $now = now();
        DB::table('locations')->insert(array_map(fn (string $name): array => [
            'name' => $name,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], [
            'Main Clinic',
            'Clinic B',
            'Golden Jubilee Terminal',
            'Transit Terminal',
            'KUT Terminal',
            'Port Control',
            'Port Security',
            'Port Fire Station',
            'Transport Pool',
            'Berth',
            'Anchorage',
            'Fishing Harbour Clinic',
            'GJT',
            'Tema General Hospital',
            'International Maritime Hospital (IMaH)',
            'Tema Polyclinic',
            '37 Military Hospital',
            'Korle Bu Teaching Hospital',
        ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
