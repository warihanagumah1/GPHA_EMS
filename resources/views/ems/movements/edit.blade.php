<x-app-layout>
<div class="gpha-page-shell space-y-5">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><p class="font-extrabold text-gpha-primary">Dispatch & Movement</p><h1 class="text-3xl font-black text-slate-950">Edit {{ $dispatch->reference }}</h1></div><a href="{{ route('ems.dispatches.show',$dispatch) }}" class="gpha-button-secondary">Back</a></div>
    @if($errors->any())<x-dismissible-alert type="error"><p class="font-extrabold">Please correct the following:</p><ul class="mt-1 list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-dismissible-alert>@endif
    <section class="gpha-panel p-5"><x-ems.movement-form :ambulances="$ambulances" :movement="$dispatch" :action="route('ems.dispatches.update',$dispatch)" method="PUT" submit-label="Update Movement" /></section>
</div>
</x-app-layout>
