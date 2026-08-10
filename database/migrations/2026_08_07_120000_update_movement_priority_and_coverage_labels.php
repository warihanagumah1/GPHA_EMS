<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('dispatches')->where('priority', 'urgent')->update(['priority' => 'non_emergency']);
        DB::table('dispatches')->where('priority', 'critical')->update(['priority' => 'emergency']);
        DB::table('dispatches')->where('purpose', 'Standby coverage')->update(['purpose' => 'Medical coverage']);
    }

    public function down(): void
    {
        DB::table('dispatches')->where('priority', 'non_emergency')->update(['priority' => 'urgent']);
        DB::table('dispatches')->where('priority', 'emergency')->update(['priority' => 'critical']);
        DB::table('dispatches')->where('purpose', 'Medical coverage')->update(['purpose' => 'Standby coverage']);
    }
};
