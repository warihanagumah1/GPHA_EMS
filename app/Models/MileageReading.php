<?php
namespace App\Models;
use App\Models\Concerns\BelongsToActiveBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MileageReading extends Model
{
    use BelongsToActiveBranch, SoftDeletes;

    protected $guarded = [];

    public function ambulance()
    {
        return $this->belongsTo(Ambulance::class);
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    protected function casts(): array
    {
        return ['reading_date' => 'date'];
    }
}
