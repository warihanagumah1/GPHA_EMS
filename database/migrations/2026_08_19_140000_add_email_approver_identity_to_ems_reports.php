<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ems_reports', function (Blueprint $table) {
            $table->string('approved_by_name')->nullable()->after('approved_by');
            $table->string('approved_by_email')->nullable()->after('approved_by_name');
        });
    }

    public function down(): void
    {
        Schema::table('ems_reports', function (Blueprint $table) {
            $table->dropColumn(['approved_by_name', 'approved_by_email']);
        });
    }
};
