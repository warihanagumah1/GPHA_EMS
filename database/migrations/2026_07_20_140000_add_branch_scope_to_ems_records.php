<?php
use Illuminate\Database\Migrations\Migration;use Illuminate\Database\Schema\Blueprint;use Illuminate\Support\Facades\Schema;
return new class extends Migration { private array $tables=['ambulances','dispatches','mileage_readings','availability_checks','weekly_activities','ems_reports']; public function up():void{foreach($this->tables as $name)Schema::table($name,fn(Blueprint $t)=>$t->string('branch_code',40)->nullable()->index());} public function down():void{foreach($this->tables as $name)Schema::table($name,fn(Blueprint $t)=>$t->dropColumn('branch_code'));} };
