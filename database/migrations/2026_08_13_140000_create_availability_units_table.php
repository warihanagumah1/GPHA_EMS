<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('availability_units', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        $now = now();
        DB::table('availability_units')->insert(array_map(fn (string $name) => [
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
            'Port Operations',
            'Fishing Harbour Clinic',
        ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('availability_units');
    }
};
