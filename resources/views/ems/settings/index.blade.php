<x-app-layout>
    <div class="gpha-page-shell space-y-6">
        <div>
            <p class="font-extrabold text-gpha-primary">Administration</p>
            <h1 class="text-3xl font-black tracking-tight text-slate-950">Settings</h1>
            <p class="mt-1 font-semibold text-slate-500">Manage the locations and departments or units available in new EMS records.</p>
        </div>

        @if(session('success'))<x-dismissible-alert>{{ session('success') }}</x-dismissible-alert>@endif
        @if($errors->any())<x-dismissible-alert type="error"><p class="font-extrabold">Please correct the following:</p><ul class="mt-1 list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-dismissible-alert>@endif

        <section id="locations" class="gpha-panel scroll-mt-28 overflow-hidden">
            <div class="border-b border-slate-200 px-5 py-5">
                <h2 class="text-xl font-black text-slate-950">Locations</h2>
                <p class="mt-1 font-semibold text-slate-500">Active locations appear in ambulance, movement and response-location fields.</p>
                <form method="POST" action="{{ route('ems.settings.locations.store') }}" class="mt-4 flex w-full flex-col gap-2 sm:flex-row lg:max-w-2xl">@csrf
                    <label class="flex-1"><span class="gpha-label">New Location</span><input name="location_name" value="{{ old('location_name') }}" class="gpha-input" maxlength="160" placeholder="Enter location name" required></label>
                    <div class="flex items-end"><button class="gpha-button-primary w-full whitespace-nowrap">Add Location</button></div>
                </form>
            </div>
            <div class="overflow-x-auto"><table class="gpha-table"><thead><tr><th>Location Name</th><th>Status</th><th class="gpha-actions-heading">Actions</th></tr></thead><tbody>
                @forelse($locations as $location)<tr x-data="{editing:false}">
                    <td>
                        <span x-show="!editing" class="font-extrabold">{{ $location->name }}</span>
                        <form x-cloak x-show="editing" id="location-edit-{{ $location->id }}" method="POST" action="{{ route('ems.settings.locations.update',$location) }}" class="min-w-64">@csrf @method('PUT')<input name="location_name" value="{{ $location->name }}" class="gpha-input" maxlength="160" required></form>
                    </td>
                    <td><span class="gpha-status {{ $location->is_active?'bg-emerald-100 text-emerald-700':'bg-slate-100 text-slate-600' }}">{{ $location->is_active?'Active':'Inactive' }}</span></td>
                    <td class="gpha-actions-cell">
                        <div class="flex flex-wrap justify-end gap-2">
                            <button x-show="!editing" type="button" @click="editing=true" class="gpha-button-secondary">Edit</button>
                            <button x-cloak x-show="editing" type="button" @click="editing=false" class="gpha-button-secondary">Cancel</button>
                            <button x-cloak x-show="editing" form="location-edit-{{ $location->id }}" class="gpha-button-primary">Save</button>
                            <form x-show="!editing" method="POST" action="{{ route('ems.settings.locations.status',$location) }}" @if($location->is_active) data-confirm-title="Deactivate Location?" data-confirm-message="{{ $location->name }} will be removed from new records. Historical records will remain unchanged." data-confirm-label="Yes, Deactivate" @endif>@csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $location->is_active?0:1 }}"><button class="{{ $location->is_active?'gpha-button-danger':'gpha-button-secondary' }}">{{ $location->is_active?'Deactivate':'Restore' }}</button></form>
                        </div>
                    </td>
                </tr>@empty<tr><td colspan="3" class="py-10 text-center text-slate-500">No locations found.</td></tr>@endforelse
            </tbody></table></div>
            <div class="border-t border-slate-200 px-5 py-4">{{ $locations->links() }}</div>
        </section>

        <section id="units" class="gpha-panel scroll-mt-28 overflow-hidden">
            <div class="border-b border-slate-200 px-5 py-5">
                <h2 class="text-xl font-black text-slate-950">Departments / Units</h2>
                <p class="mt-1 font-semibold text-slate-500">Active entries appear in new radio communication and availability checks.</p>
                <form method="POST" action="{{ route('ems.settings.units.store') }}" class="mt-4 flex w-full flex-col gap-2 sm:flex-row lg:max-w-2xl">@csrf
                    <label class="flex-1"><span class="gpha-label">New Department / Unit</span><input name="unit_name" value="{{ old('unit_name') }}" class="gpha-input" maxlength="120" placeholder="Enter department or unit name" required></label>
                    <div class="flex items-end"><button class="gpha-button-primary w-full whitespace-nowrap">Add Unit</button></div>
                </form>
            </div>
            <div class="overflow-x-auto"><table class="gpha-table"><thead><tr><th>Department / Unit Name</th><th>Status</th><th class="gpha-actions-heading">Actions</th></tr></thead><tbody>
                @forelse($units as $unit)<tr x-data="{editing:false}">
                    <td>
                        <span x-show="!editing" class="font-extrabold">{{ $unit->name }}</span>
                        <form x-cloak x-show="editing" id="unit-edit-{{ $unit->id }}" method="POST" action="{{ route('ems.settings.units.update',$unit) }}" class="min-w-64">@csrf @method('PUT')<input name="unit_name" value="{{ $unit->name }}" class="gpha-input" maxlength="120" required></form>
                    </td>
                    <td><span class="gpha-status {{ $unit->is_active?'bg-emerald-100 text-emerald-700':'bg-slate-100 text-slate-600' }}">{{ $unit->is_active?'Active':'Inactive' }}</span></td>
                    <td class="gpha-actions-cell">
                        <div class="flex flex-wrap justify-end gap-2">
                            <button x-show="!editing" type="button" @click="editing=true" class="gpha-button-secondary">Edit</button>
                            <button x-cloak x-show="editing" type="button" @click="editing=false" class="gpha-button-secondary">Cancel</button>
                            <button x-cloak x-show="editing" form="unit-edit-{{ $unit->id }}" class="gpha-button-primary">Save</button>
                            <form x-show="!editing" method="POST" action="{{ route('ems.settings.units.status',$unit) }}" @if($unit->is_active) data-confirm-title="Deactivate Department or Unit?" data-confirm-message="{{ $unit->name }} will be removed from new check sessions. Historical records and reports will remain unchanged." data-confirm-label="Yes, Deactivate" @endif>@csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $unit->is_active?0:1 }}"><button class="{{ $unit->is_active?'gpha-button-danger':'gpha-button-secondary' }}">{{ $unit->is_active?'Deactivate':'Restore' }}</button></form>
                        </div>
                    </td>
                </tr>@empty<tr><td colspan="3" class="py-10 text-center text-slate-500">No departments or units found.</td></tr>@endforelse
            </tbody></table></div>
            <div class="border-t border-slate-200 px-5 py-4">{{ $units->links() }}</div>
        </section>
    </div>
</x-app-layout>
