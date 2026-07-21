<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('dispatches',fn(Blueprint $table)=>$table->softDeletes());
        Schema::table('ems_audit_logs',function(Blueprint $table){
            $table->string('subject_type')->nullable()->after('action');
            $table->unsignedBigInteger('subject_id')->nullable()->after('subject_type');
            $table->string('subject_reference')->nullable()->after('subject_id')->index();
            $table->json('old_values')->nullable()->after('subject_reference');
            $table->json('new_values')->nullable()->after('old_values');
            $table->json('metadata')->nullable()->after('new_values');
        });
    }

    public function down(): void
    {
        Schema::table('ems_audit_logs',fn(Blueprint $table)=>$table->dropColumn(['subject_type','subject_id','subject_reference','old_values','new_values','metadata']));
        Schema::table('dispatches',fn(Blueprint $table)=>$table->dropSoftDeletes());
    }
};
