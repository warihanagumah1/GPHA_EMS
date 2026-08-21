<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmsReportApprovalLink extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(EmsReport::class, 'report_id');
    }
}
