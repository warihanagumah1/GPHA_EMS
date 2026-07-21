<?php
namespace App\Models;
use App\Models\Concerns\BelongsToActiveBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class AvailabilityCheck extends Model { use BelongsToActiveBranch, SoftDeletes; protected $guarded=[]; protected function casts(): array{return ['check_date'=>'date','responded'=>'boolean'];} }
