<?php
namespace App\Models;
use App\Models\Concerns\BelongsToActiveBranch;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class Ambulance extends Model { use BelongsToActiveBranch, HasFactory, HasUuids, SoftDeletes; protected $guarded=[]; public function uniqueIds(): array{return ['uuid'];} public function dispatches(){return $this->hasMany(Dispatch::class);} public function mileageReadings(){return $this->hasMany(MileageReading::class);} protected function casts(): array{return ['roadworthy_expires_at'=>'date','insurance_expires_at'=>'date'];} }
