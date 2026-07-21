@props(['ambulance', 'canManage' => false])

<td><a class="font-extrabold text-gpha-primary hover:underline" href="{{ route('ems.ambulances.show', $ambulance) }}">{{ $ambulance->fleet_number }}</a></td>
<td>{{ $ambulance->registration_number }}</td>
<td>{{ $ambulance->current_location ?: $ambulance->base_location }}</td>
<td>{{ number_format($ambulance->odometer_km) }} km</td>
<td><x-ems.status-badge :status="$ambulance->status" /></td>
<td class="gpha-actions-cell">
    <div class="relative inline-block text-left" x-data="{ open:false, menuTop:0, menuLeft:0, positionMenu(){ const rect=this.$refs.trigger.getBoundingClientRect(); const width=224; const height=190; const pad=8; const right=rect.right+pad; const left=rect.left-width-pad; this.menuTop=Math.max(pad,Math.min(rect.top,window.innerHeight-height-pad)); this.menuLeft=right+width<=window.innerWidth-pad?right:Math.max(pad,left); } }" @resize.window="open && positionMenu()" @scroll.window="open && positionMenu()" @keydown.escape.window="open=false">
        <x-ems.action-trigger x-ref="trigger" x-bind:class="{ 'is-open': open }" @click.stop="positionMenu(); open=!open" label="Ambulance actions" />
        <div x-cloak x-show="open" x-transition @click.outside="open=false" :style="`top:${menuTop}px;left:${menuLeft}px`" class="gpha-floating-action-menu">
            <a href="{{ route('ems.ambulances.show', $ambulance) }}" @click="open=false" class="block w-full px-4 py-2 text-left font-semibold text-slate-700 hover:bg-slate-50 hover:text-gpha-primary">View</a>
            @if($canManage)
                <a href="{{ route('ems.ambulances.edit', $ambulance) }}" @click="open=false" class="block w-full px-4 py-2 text-left font-semibold text-gpha-primary hover:bg-blue-50">Edit</a>
                @if($ambulance->status !== 'dispatched')
                    <form method="POST" action="{{ route('ems.ambulances.status', $ambulance) }}" data-confirm-title="Change Ambulance Status?" data-confirm-message="{{ $ambulance->fleet_number }} will be marked {{ $ambulance->status === 'available' ? 'unavailable' : 'available' }}." data-confirm-label="Yes, Mark {{ $ambulance->status === 'available' ? 'Unavailable' : 'Available' }}" data-confirm-tone="{{ $ambulance->status === 'available' ? 'danger' : 'success' }}">
                        @csrf @method('PATCH')
                        <input type="hidden" name="status" value="{{ $ambulance->status === 'available' ? 'unavailable' : 'available' }}">
                        <button type="submit" class="block min-h-0 w-full px-4 py-2 text-left font-semibold {{ $ambulance->status === 'available' ? 'text-red-600 hover:bg-red-50' : 'text-emerald-700 hover:bg-emerald-50' }}">
                            {{ $ambulance->status === 'available' ? 'Mark Unavailable' : 'Mark Available' }}
                        </button>
                    </form>
                @endif
            @endif
        </div>
    </div>
</td>
