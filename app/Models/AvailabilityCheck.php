<?php
namespace App\Models;
use App\Models\Concerns\BelongsToActiveBranch;
use Illuminate\Database\Eloquent\Model;
class AvailabilityCheck extends Model { use BelongsToActiveBranch; protected $guarded=[]; protected function casts(): array{return ['check_date'=>'date','responded'=>'boolean'];} }
