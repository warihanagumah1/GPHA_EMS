<?php

namespace App\Providers;

use App\Models\Ambulance;
use App\Models\AvailabilityCheck;
use App\Models\Dispatch;
use App\Models\EmsReport;
use App\Models\MileageReading;
use App\Models\WeeklyActivity;
use App\Observers\EmsModelAuditObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach([Ambulance::class,Dispatch::class,MileageReading::class,AvailabilityCheck::class,WeeklyActivity::class,EmsReport::class] as $model){
            $model::observe(EmsModelAuditObserver::class);
        }
    }
}
