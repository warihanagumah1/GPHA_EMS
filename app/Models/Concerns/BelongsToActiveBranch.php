<?php
namespace App\Models\Concerns;
use Illuminate\Database\Eloquent\Builder;
trait BelongsToActiveBranch { protected static function bootBelongsToActiveBranch():void{static::addGlobalScope('active_branch',function(Builder $query){$branch=session('sso.active_branch_code');if(auth()->check()&&auth()->user()?->sso_user_id&&is_string($branch)&&$branch!=='')$query->where($query->qualifyColumn('branch_code'),$branch);});static::creating(function($model){$branch=session('sso.active_branch_code');if(!$model->branch_code&&is_string($branch)&&$branch!=='')$model->branch_code=$branch;});} }
