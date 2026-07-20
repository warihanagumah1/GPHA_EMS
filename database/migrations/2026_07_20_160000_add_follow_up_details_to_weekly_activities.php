<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('weekly_activities', function (Blueprint $table) {
            $table->text('outcome')->nullable()->after('participants');
            $table->text('follow_up_action')->nullable()->after('requires_follow_up');
            $table->string('follow_up_owner', 160)->nullable()->after('follow_up_action');
            $table->date('follow_up_due_date')->nullable()->after('follow_up_owner');
        });
    }

    public function down(): void
    {
        Schema::table('weekly_activities', fn (Blueprint $table) => $table->dropColumn([
            'outcome','follow_up_action','follow_up_owner','follow_up_due_date',
        ]));
    }
};
