<?php
namespace App\Models;
use App\Models\Concerns\BelongsToActiveBranch;
use Illuminate\Database\Eloquent\Model;
class MileageReading extends Model { use BelongsToActiveBranch; protected $guarded=[]; public function ambulance(){return $this->belongsTo(Ambulance::class);} protected function casts(): array{return ['reading_date'=>'date'];} }
