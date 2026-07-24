@props(['label' => 'More information'])
<span class="inline-flex" x-data="{helpOpen:false,helpTop:0,helpLeft:0,positionHelp(){const r=this.$refs.trigger.getBoundingClientRect(),w=Math.min(288,window.innerWidth-16);this.helpLeft=Math.max(8,Math.min(r.left,window.innerWidth-w-8));this.helpTop=r.bottom+8}}" @mouseenter="positionHelp();helpOpen=true" @mouseleave="helpOpen=false" @focusin="positionHelp();helpOpen=true" @focusout="helpOpen=false" @click.outside="helpOpen=false" @resize.window="helpOpen&&positionHelp()" @scroll.window="helpOpen&&positionHelp()">
    <button x-ref="trigger" type="button" @click.prevent.stop="positionHelp();helpOpen=!helpOpen" :aria-expanded="helpOpen" aria-label="{{ $label }}" class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-gpha-primary text-xs font-black leading-none text-white shadow-sm hover:bg-blue-800 focus:outline-none focus:ring-2 focus:ring-gpha-secondary focus:ring-offset-2">?</button>
    <template x-teleport="body">
        <span x-cloak x-show="helpOpen" x-transition role="tooltip" :style="`top:${helpTop}px;left:${helpLeft}px`" class="fixed z-[200] w-72 max-w-[calc(100vw-1rem)] rounded-lg bg-slate-900 px-3 py-2 text-left text-sm font-semibold normal-case tracking-normal text-white shadow-xl">
            {{ $slot }}
        </span>
    </template>
</span>
