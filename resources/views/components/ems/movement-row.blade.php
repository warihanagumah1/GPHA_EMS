@props(['movement', 'canManage' => false, 'actionsOnly' => false])
@unless($actionsOnly)
<td><a href="{{ route('ems.dispatches.show', $movement) }}" class="font-extrabold text-gpha-primary hover:underline">{{ $movement->reference }}</a></td>
<td>{{ $movement->ambulance->fleet_number }}</td>
<td>{{ $movement->origin }} → {{ $movement->destination }}</td>
<td><x-ems.status-badge :status="$movement->priority" /></td>
<td><x-ems.status-badge :status="$movement->status" /></td>
@endunless
@if($canManage)
<td class="gpha-actions-cell">
    <div class="relative inline-block text-left" x-data="{open:false,menuTop:0,menuLeft:0,positionMenu(){const r=this.$refs.trigger.getBoundingClientRect(),w=224,h=230,p=8;this.menuTop=Math.max(p,Math.min(r.top,window.innerHeight-h-p));this.menuLeft=r.right+p+w<=window.innerWidth-p?r.right+p:Math.max(p,r.left-w-p)}}" @resize.window="open&&positionMenu()" @scroll.window="open&&positionMenu()" @keydown.escape.window="open=false">
        <x-ems.action-trigger x-ref="trigger" x-bind:class="{'is-open':open}" @click.stop="positionMenu();open=!open" label="Movement actions" />
        <div x-cloak x-show="open" x-transition @click.outside="open=false" :style="`top:${menuTop}px;left:${menuLeft}px`" class="gpha-floating-action-menu">
            @if($canManage)
                <a href="{{ route('ems.dispatches.edit', $movement) }}" class="block w-full px-4 py-2 text-left font-semibold text-gpha-primary hover:bg-blue-50">Edit</a>
                @if($movement->status==='requested')
                    <form method="POST" action="{{ route('ems.dispatches.complete',$movement) }}" data-confirm-title="Complete Movement?" data-confirm-message="{{ $movement->reference }} will be completed and {{ $movement->ambulance->fleet_number }} will become available for another movement." data-confirm-label="Yes, Complete Movement" data-confirm-tone="success">@csrf @method('PATCH')<button type="submit" @click="open=false" class="block min-h-0 w-full px-4 py-2 text-left font-semibold text-emerald-700 hover:bg-emerald-50">Mark Complete</button></form>
                @endif
                <form method="POST" action="{{ route('ems.dispatches.destroy',$movement) }}" data-confirm-title="Delete Movement?" data-confirm-message="{{ $movement->reference }} will be removed from operational lists. Its deletion details will remain preserved in the audit trail." data-confirm-label="Yes, Delete Movement" data-confirm-tone="danger">@csrf @method('DELETE')<button type="submit" @click="open=false" class="block min-h-0 w-full px-4 py-2 text-left font-semibold text-red-600 hover:bg-red-50">Delete</button></form>
            @endif
        </div>
    </div>
</td>
@endif
