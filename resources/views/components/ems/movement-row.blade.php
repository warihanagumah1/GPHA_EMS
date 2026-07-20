@props(['movement', 'canManage' => false, 'actionsOnly' => false])
@unless($actionsOnly)
<td><a href="{{ route('ems.dispatches.show', $movement) }}" class="font-extrabold text-gpha-primary hover:underline">{{ $movement->reference }}</a></td>
<td>{{ $movement->ambulance->fleet_number }}</td>
<td>{{ $movement->origin }} → {{ $movement->destination }}</td>
<td><x-ems.status-badge :status="$movement->priority" /></td>
<td><x-ems.status-badge :status="$movement->status" /></td>
@endunless
<td class="gpha-actions-cell">
    <div class="relative inline-block text-left" x-data="{open:false,completeOpen:false,menuTop:0,menuLeft:0,positionMenu(){const r=this.$refs.trigger.getBoundingClientRect(),w=224,h=190,p=8;this.menuTop=Math.max(p,Math.min(r.top,window.innerHeight-h-p));this.menuLeft=r.right+p+w<=window.innerWidth-p?r.right+p:Math.max(p,r.left-w-p)}}" @resize.window="open&&positionMenu()" @scroll.window="open&&positionMenu()" @keydown.escape.window="open=false;completeOpen=false">
        <x-ems.action-trigger x-ref="trigger" x-bind:class="{'is-open':open}" @click.stop="positionMenu();open=!open" label="Movement actions" />
        <div x-cloak x-show="open" x-transition @click.outside="open=false" :style="`top:${menuTop}px;left:${menuLeft}px`" class="gpha-floating-action-menu">
            <a href="{{ route('ems.dispatches.show', $movement) }}" class="block w-full px-4 py-2 text-left font-semibold text-slate-700 hover:bg-slate-50 hover:text-gpha-primary">View</a>
            @if($canManage && !in_array($movement->status,['completed','cancelled'],true))
                <a href="{{ route('ems.dispatches.edit', $movement) }}" class="block w-full px-4 py-2 text-left font-semibold text-gpha-primary hover:bg-blue-50">Edit</a>
                <button type="button" @click="open=false;completeOpen=true" class="block min-h-0 w-full px-4 py-2 text-left font-semibold text-emerald-700 hover:bg-emerald-50">Mark Complete</button>
            @endif
        </div>
        <div x-cloak x-show="completeOpen" x-transition.opacity class="fixed inset-0 z-[120] flex items-center justify-center bg-slate-950/55 p-4">
            <div @click.outside="completeOpen=false" class="w-full max-w-lg rounded-xl bg-white p-6 text-left shadow-2xl">
                <div class="flex items-start justify-between gap-4"><div><h2 class="text-xl font-black text-slate-950">Complete Movement</h2><p class="mt-1 font-semibold text-slate-500">{{ $movement->reference }} · {{ $movement->ambulance->fleet_number }}</p></div><button type="button" @click="completeOpen=false" class="min-h-0 p-0 text-2xl font-black text-slate-500 shadow-none">×</button></div>
                <p class="mt-5 rounded-lg bg-slate-50 p-4 font-semibold text-slate-600">Are you sure you want to mark this movement as complete? The ambulance will become available for another movement.</p>
                <form method="POST" action="{{ route('ems.dispatches.complete',$movement) }}" class="mt-5">@csrf @method('PATCH')<div class="flex justify-end gap-2"><button type="button" @click="completeOpen=false" class="gpha-button-secondary">Cancel</button><button class="rounded bg-emerald-600 px-4 py-2 font-extrabold text-white hover:bg-emerald-700">Yes, Complete Movement</button></div></form>
            </div>
        </div>
    </div>
</td>
