<x-app-layout>
@php($canManage=app(\App\Application\Sso\PermissionService::class)->allows('ReadinessAndActivities','Manage'))
<div class="gpha-page-shell space-y-6">
    @if(session('success'))<x-dismissible-alert>{{ session('success') }}</x-dismissible-alert>@endif
    <section class="gpha-top-pipe rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div><p class="font-extrabold text-gpha-primary">Operations Logs</p><h1 class="text-3xl font-black text-slate-950">Activity Details</h1><p class="mt-2 font-semibold text-slate-500">{{ $activity->activity_date->format('d M Y') }} · {{ str($activity->category)->headline() }}</p></div>
            <div class="flex flex-wrap gap-2">@if($canManage)<a href="{{ route('ems.activities.edit',$activity) }}" class="gpha-button-secondary">Edit</a><form method="POST" action="{{ route('ems.activities.destroy',$activity) }}" class="inline-flex" data-confirm-title="Delete Activity?" data-confirm-message="This activity will be removed from operational lists but retained in the audit trail." data-confirm-label="Yes, Delete Activity" data-confirm-tone="danger">@csrf @method('DELETE')<button class="gpha-button-danger">Delete</button></form>@endif<a href="{{ route('ems.activities') }}" class="gpha-button-primary">Back</a></div>
        </div>
        <div class="mt-6 rounded-xl border border-slate-200 p-5"><h2 class="text-xl font-black">What happened?</h2><div class="report-rich-text mt-3">{!! \App\Support\RichText::clean($activity->description) !!}</div>@if($activity->outcome)<div class="mt-5 border-t border-slate-200 pt-5"><h2 class="text-xl font-black">Outcome / Decision</h2><div class="report-rich-text mt-3">{!! \App\Support\RichText::clean($activity->outcome) !!}</div></div>@endif</div>
        @if($activity->requires_follow_up)<div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-5"><h2 class="text-xl font-black text-amber-900">Follow-up Required</h2><div class="mt-3 grid gap-4 md:grid-cols-3"><p><b>Action:</b><br>{{ $activity->follow_up_action }}</p><p><b>Owner:</b><br>{{ $activity->follow_up_owner }}</p><p><b>Due:</b><br>{{ $activity->follow_up_due_date?->format('d M Y')?:'Not set' }}</p></div></div>@endif
    </section>
</div>
</x-app-layout>
