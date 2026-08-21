<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ems_report_approval_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('ems_reports')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('approver_name');
            $table->string('approver_email');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['report_id', 'approver_email']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ems_report_approval_links');
    }
};
