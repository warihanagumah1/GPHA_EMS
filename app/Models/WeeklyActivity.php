<?php
namespace App\Models;
use App\Models\Concerns\BelongsToActiveBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class WeeklyActivity extends Model { use BelongsToActiveBranch, SoftDeletes; protected $guarded=[]; protected function casts(): array{return ['activity_date'=>'date','requires_follow_up'=>'boolean','follow_up_due_date'=>'date'];} }
