<?php
namespace App\Models;
use App\Models\Concerns\BelongsToActiveBranch;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class EmsReport extends Model { protected $guarded=[]; use BelongsToActiveBranch, HasUuids; public function uniqueIds(): array{return ['uuid'];} protected function casts(): array{return ['period_start'=>'date','period_end'=>'date','summary'=>'array','recommendations'=>'array','snapshot'=>'array','submitted_at'=>'datetime','approved_at'=>'datetime'];} public function preparedBy():BelongsTo{return $this->belongsTo(User::class,'prepared_by');} public function approvedBy():BelongsTo{return $this->belongsTo(User::class,'approved_by');} }
