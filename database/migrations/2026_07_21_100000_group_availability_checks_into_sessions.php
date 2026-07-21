<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('availability_checks', function (Blueprint $table) {
            $table->uuid('session_uuid')->nullable()->after('id')->index();
            $table->softDeletes();
        });

        $groups = DB::table('availability_checks')
            ->select(['check_date', 'period', 'checked_at', 'branch_code'])
            ->distinct()
            ->get();

        foreach ($groups as $group) {
            $query = DB::table('availability_checks')
                ->where('check_date', $group->check_date)
                ->where('period', $group->period);

            $group->checked_at === null ? $query->whereNull('checked_at') : $query->where('checked_at', $group->checked_at);
            $group->branch_code === null ? $query->whereNull('branch_code') : $query->where('branch_code', $group->branch_code);
            $query->update(['session_uuid' => (string) Str::uuid()]);
        }
    }

    public function down(): void
    {
        Schema::table('availability_checks', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn('session_uuid');
        });
    }
};
