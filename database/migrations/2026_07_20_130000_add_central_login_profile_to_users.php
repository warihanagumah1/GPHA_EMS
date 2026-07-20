<?php
use Illuminate\Database\Migrations\Migration;use Illuminate\Database\Schema\Blueprint;use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up():void{Schema::table('users',function(Blueprint $t){$t->string('sso_username')->nullable();$t->json('sso_claims')->nullable();$t->json('branch_ids')->nullable();$t->json('branch_codes')->nullable();$t->json('branch_names')->nullable();});} public function down():void{Schema::table('users',fn(Blueprint $t)=>$t->dropColumn(['sso_username','sso_claims','branch_ids','branch_codes','branch_names']));}};
