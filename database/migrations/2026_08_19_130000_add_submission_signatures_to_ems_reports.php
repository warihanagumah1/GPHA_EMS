<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ems_reports', function (Blueprint $table) {
            $table->foreignId('submitted_by')->nullable()->after('prepared_by')->constrained('users')->nullOnDelete();
            $table->string('submitter_signature_method', 20)->nullable()->after('submitted_at');
            $table->string('submitter_signature_path')->nullable()->after('submitter_signature_method');
            $table->string('approver_signature_method', 20)->nullable()->after('approved_at');
            $table->string('approver_signature_path')->nullable()->after('approver_signature_method');
            $table->string('signed_report_path')->nullable()->after('approver_signature_path');
        });
    }

    public function down(): void
    {
        Schema::table('ems_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropColumn([
                'submitter_signature_method',
                'submitter_signature_path',
                'approver_signature_method',
                'approver_signature_path',
                'signed_report_path',
            ]);
        });
    }
};
