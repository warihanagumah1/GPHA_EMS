<?php
namespace App\Models;
use App\Models\Concerns\BelongsToActiveBranch;
use Illuminate\Database\Eloquent\Model;
class WeeklyActivity extends Model { use BelongsToActiveBranch; protected $guarded=[]; protected function casts(): array{return ['activity_date'=>'date','requires_follow_up'=>'boolean','follow_up_due_date'=>'date'];} }
