<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('locations')) {
            return;
        }

        $sources = [
            ['ambulances', 'base_location'],
            ['dispatches', 'origin'],
            ['dispatches', 'destination'],
            ['availability_checks', 'response_location'],
        ];

        $existingNames = DB::table('locations')
            ->pluck('name')
            ->mapWithKeys(fn (string $name): array => [mb_strtolower(trim($name)) => true])
            ->all();

        foreach ($sources as [$table, $column]) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
                continue;
            }

            $names = DB::table($table)
                ->whereNotNull($column)
                ->where($column, '<>', '')
                ->distinct()
                ->pluck($column);

            foreach ($names as $name) {
                $name = trim((string) $name);
                $key = mb_strtolower($name);

                if ($name === '' || isset($existingNames[$key])) {
                    continue;
                }

                DB::table('locations')->insert([
                    'name' => $name,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $existingNames[$key] = true;
            }
        }
    }

    public function down(): void
    {
        // Intentionally preserve imported locations and all historical records.
    }
};
