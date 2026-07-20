<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('sso_user_id')->nullable()->unique();
            $table->string('job_title')->nullable();
            $table->string('department')->default('EMS');
        });

        Schema::create('ambulances', function (Blueprint $table) {
            $table->id(); $table->uuid('uuid')->unique();
            $table->string('fleet_number')->unique(); $table->string('registration_number')->unique();
            $table->string('make')->nullable(); $table->string('model')->nullable(); $table->unsignedSmallInteger('year')->nullable();
            $table->string('status')->default('available')->index(); $table->string('base_location'); $table->string('current_location')->nullable();
            $table->unsignedBigInteger('odometer_km')->default(0); $table->unsignedBigInteger('next_service_km')->nullable();
            $table->date('roadworthy_expires_at')->nullable(); $table->date('insurance_expires_at')->nullable(); $table->text('notes')->nullable();
            $table->timestamps(); $table->softDeletes();
        });

        Schema::create('dispatches', function (Blueprint $table) {
            $table->id(); $table->uuid('uuid')->unique(); $table->string('reference')->unique();
            $table->foreignId('ambulance_id')->constrained()->restrictOnDelete();
            $table->string('priority')->default('routine')->index(); $table->string('status')->default('requested')->index();
            $table->string('origin'); $table->string('destination'); $table->string('purpose'); $table->string('crew_lead')->nullable();
            $table->timestamp('requested_at'); $table->timestamp('dispatched_at')->nullable(); $table->timestamp('arrived_at')->nullable(); $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('odometer_start')->nullable(); $table->unsignedBigInteger('odometer_end')->nullable();
            $table->text('notes')->nullable(); $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamps();
        });

        Schema::create('mileage_readings', function (Blueprint $table) {
            $table->id(); $table->foreignId('ambulance_id')->constrained()->cascadeOnDelete();
            $table->date('reading_date')->index(); $table->unsignedBigInteger('odometer_km'); $table->string('source')->default('weekly');
            $table->text('notes')->nullable(); $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamps();
            $table->unique(['ambulance_id', 'reading_date', 'source']);
        });

        Schema::create('availability_checks', function (Blueprint $table) {
            $table->id(); $table->date('check_date')->index(); $table->string('period'); $table->time('checked_at')->nullable();
            $table->string('unit_name'); $table->boolean('responded')->default(false); $table->string('response_location')->nullable(); $table->text('observation')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamps();
            $table->unique(['check_date', 'period', 'unit_name']);
        });

        Schema::create('weekly_activities', function (Blueprint $table) {
            $table->id(); $table->date('activity_date')->index(); $table->string('category')->default('operations');
            $table->string('title'); $table->text('description'); $table->string('location')->nullable(); $table->string('participants')->nullable();
            $table->boolean('requires_follow_up')->default(false); $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamps();
        });

        Schema::create('ems_reports', function (Blueprint $table) {
            $table->id(); $table->uuid('uuid')->unique(); $table->string('type')->index(); $table->date('period_start'); $table->date('period_end');
            $table->string('status')->default('draft')->index(); $table->json('summary')->nullable(); $table->json('recommendations')->nullable(); $table->json('snapshot')->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete(); $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable(); $table->timestamp('approved_at')->nullable(); $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ems_reports'); Schema::dropIfExists('weekly_activities'); Schema::dropIfExists('availability_checks');
        Schema::dropIfExists('mileage_readings'); Schema::dropIfExists('dispatches'); Schema::dropIfExists('ambulances');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['sso_user_id', 'job_title', 'department']));
    }
};
