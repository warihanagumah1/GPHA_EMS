<?php
namespace App\Models;
use App\Models\Concerns\BelongsToActiveBranch;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class Dispatch extends Model { use BelongsToActiveBranch, HasUuids, SoftDeletes; protected $guarded=[]; public function uniqueIds(): array{return ['uuid'];} public function ambulance(){return $this->belongsTo(Ambulance::class);} protected function casts(): array{return ['requested_at'=>'datetime','dispatched_at'=>'datetime','arrived_at'=>'datetime','completed_at'=>'datetime'];} public function getDistanceKmAttribute(): ?int{return $this->odometer_start!==null&&$this->odometer_end!==null ? $this->odometer_end-$this->odometer_start:null;} }
