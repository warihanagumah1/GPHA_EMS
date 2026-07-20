<?php
namespace App\Http\Middleware;
use App\Application\Sso\PermissionService;use Closure;use Illuminate\Http\Request;
class RequireEmsPermission { public function handle(Request $request,Closure $next,string $component,string $permission='View'){if(app()->environment('testing')&&!$request->user()?->sso_user_id)return$next($request);if($component==='Module')$component=match((string)$request->route('module')){'ambulances','mileage'=>'AmbulanceFleet','dispatches'=>'DispatchAndMovement','availability','activities'=>'ReadinessAndActivities','reports'=>'EMSReports',default=>''};if($component===''||!app(PermissionService::class)->allows($component,$permission))abort(403,'You do not have permission to perform this EMS action.');return$next($request);} }
