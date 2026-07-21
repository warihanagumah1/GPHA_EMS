<?php

namespace App\Models;

use App\Models\Concerns\BelongsToActiveBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmsAuditLog extends Model
{
    use BelongsToActiveBranch;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'old_values' => 'array', 'new_values' => 'array', 'metadata' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
