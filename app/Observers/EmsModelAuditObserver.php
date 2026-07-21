<?php

namespace App\Observers;

use App\Models\Ambulance;
use App\Models\AvailabilityCheck;
use App\Models\Dispatch;
use App\Models\EmsAuditLog;
use App\Models\EmsReport;
use App\Models\MileageReading;
use App\Models\WeeklyActivity;
use Illuminate\Database\Eloquent\Model;

class EmsModelAuditObserver
{
    private const EXCLUDED=['created_at','updated_at','uuid'];

    public function created(Model $model): void
    {
        $this->record($model,$this->type($model).'.created',null,$this->values($model->getAttributes()));
    }

    public function updated(Model $model): void
    {
        $changes=$this->values($model->getChanges());
        if($changes===[])return;
        $old=[];
        foreach(array_keys($changes) as $key)$old[$key]=$model->getRawOriginal($key);
        $this->record($model,$this->type($model).'.updated',$old,$changes);
    }

    public function deleted(Model $model): void
    {
        $this->record($model,$this->type($model).'.deleted',$this->values($model->getAttributes()),['deleted_at'=>now()->toIso8601String()]);
    }

    private function values(array $values): array
    {
        return array_diff_key($values,array_flip(self::EXCLUDED));
    }

    private function type(Model $model): string
    {
        return match(true){
            $model instanceof Dispatch=>'movement',
            $model instanceof Ambulance=>'ambulance',
            $model instanceof MileageReading=>'mileage_reading',
            $model instanceof AvailabilityCheck=>'availability_check',
            $model instanceof WeeklyActivity=>'weekly_activity',
            $model instanceof EmsReport=>'report',
            default=>str($model->getTable())->singular()->toString(),
        };
    }

    private function reference(Model $model): string
    {
        return match(true){
            $model instanceof Dispatch=>(string)$model->reference,
            $model instanceof Ambulance=>(string)$model->fleet_number,
            $model instanceof EmsReport=>(string)$model->uuid,
            $model instanceof AvailabilityCheck=>implode(' / ',[$model->check_date?->format('Y-m-d')??$model->check_date,$model->period,$model->unit_name]),
            $model instanceof MileageReading=>implode(' / ',[$model->ambulance_id,$model->reading_date?->format('Y-m-d')??$model->reading_date]),
            $model instanceof WeeklyActivity=>(string)$model->title,
            default=>(string)$model->getKey(),
        };
    }

    private function record(Model $model,string $action,?array $old,?array $new): void
    {
        EmsAuditLog::withoutGlobalScopes()->create([
            'user_id'=>auth()->id(),
            'branch_code'=>$model->getAttribute('branch_code') ?: session('sso.active_branch_code'),
            'action'=>$action,
            'subject_type'=>$this->type($model),
            'subject_id'=>$model->getKey(),
            'subject_reference'=>$this->reference($model),
            'old_values'=>$old,
            'new_values'=>$new,
            'metadata'=>['model'=>$model::class],
            'route'=>request()->route()?->getName(),
            'method'=>request()->method() ?: 'MODEL',
            'path'=>request()->path(),
            'ip_address'=>request()->ip(),
            'response_status'=>200,
        ]);
    }
}

