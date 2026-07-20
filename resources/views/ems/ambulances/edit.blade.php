<x-app-layout>
    <div class="gpha-page-shell space-y-5">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div><p class="font-extrabold text-gpha-primary">Ambulance Fleet</p><h1 class="text-3xl font-black text-slate-950">Edit {{ $ambulance->fleet_number }}</h1></div>
            <a href="{{ route('ems.ambulances.show', $ambulance) }}" class="gpha-button-secondary">Back</a>
        </div>
        @if($errors->any())
            <x-dismissible-alert type="error"><p class="font-extrabold">Please correct the following:</p><ul class="mt-1 list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-dismissible-alert>
        @endif
        <section class="gpha-panel p-5">
            <x-ems.ambulance-form :ambulance="$ambulance" :action="route('ems.ambulances.update', $ambulance)" method="PUT" submit-label="Update Ambulance" />
        </section>
    </div>
</x-app-layout>
