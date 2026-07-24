<?php
use App\Application\Sso\CentralLoginUrl;
use App\Application\Sso\PermissionService;
use App\Livewire\Actions\Logout;
use Livewire\Volt\Component;

new class extends Component {
    public function logout(Logout $logout): void
    {
        $logout();
        $this->redirect(app()->environment('testing') ? '/' : app(CentralLoginUrl::class)->loginUrl(), navigate: false);
    }
};
?>
@php
    $permissions = app(PermissionService::class);
    $items = [
        ['Dashboard','dashboard',null],
        ['Ambulances','ems.ambulances',['AmbulanceFleet','View']],
        ['Dispatch & Movement','ems.dispatches',['DispatchAndMovement','View']],
    ];
    $userName = auth()->user()?->name ?: 'GPHA Staff';
    $initials = str($userName)->explode(' ')->filter()->take(2)->map(fn($part) => str($part)->substr(0, 1))->join('');
@endphp
<nav x-data="{ open:false, logsOpen:false, logsCloseTimer:null, desktopLogs(){ return window.matchMedia('(min-width: 1024px)').matches }, openLogs(){ if(this.desktopLogs()){ clearTimeout(this.logsCloseTimer); this.logsOpen=true } }, closeLogs(){ if(this.desktopLogs()){ clearTimeout(this.logsCloseTimer); this.logsCloseTimer=setTimeout(() => this.logsOpen=false, 150) } } }" class="sticky top-0 z-40 border-t-[3px] border-gpha-secondary bg-gpha-primary text-white shadow-md">
    <div class="mx-auto max-w-7xl px-4 py-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between gap-5">
            <a href="{{ route('dashboard') }}" wire:navigate class="flex items-center gap-4">
                <span class="flex h-16 w-16 shrink-0 overflow-hidden rounded-full bg-white p-1.5"><x-application-logo class="h-full w-full object-contain" /></span>
                <span class="gpha-brand-title font-black tracking-tight">GPHA <span class="text-gpha-secondary">EMS</span></span>
            </a>
            <button type="button" @click="open=!open" :aria-expanded="open" :aria-label="open ? 'Close navigation' : 'Open navigation'" aria-controls="mobile-primary-navigation" class="inline-flex h-12 w-12 items-center justify-center rounded-lg bg-white/10 text-white transition hover:bg-white/20 focus:outline-none focus:ring-2 focus:ring-gpha-secondary focus:ring-offset-2 focus:ring-offset-gpha-primary lg:hidden">
                <svg x-show="!open" class="h-7 w-7" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" /></svg>
                <svg x-cloak x-show="open" class="h-7 w-7" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" /></svg>
            </button>
            <div class="hidden items-center gap-3 rounded-xl bg-white/10 px-5 py-3 lg:flex">
                <span class="gpha-user-avatar flex h-12 w-12 items-center justify-center rounded-full bg-white/15 font-black text-[#9dc3e8]">{{ strtoupper($initials) }}</span>
                <span><span class="gpha-user-name block font-black">{{ $userName }}</span><span class="gpha-user-role block font-semibold text-white/70">{{ auth()->user()?->job_title ?: 'GPHA Staff' }}</span></span>
                @if(count((array) session('sso.branches.codes', [])) > 1)
                    <form method="POST" action="{{ route('ems.branch.switch') }}" class="ml-2">@csrf
                        <select name="branch_code" onchange="this.form.submit()" class="rounded border-white/20 bg-white/10 px-2 py-1 text-white">@foreach((array) session('sso.branches.codes', []) as $branchCode)<option class="text-slate-900" value="{{ $branchCode }}" @selected(session('sso.active_branch_code') === $branchCode)>{{ $branchCode }}</option>@endforeach</select>
                    </form>
                @endif
            </div>
        </div>

        <div id="mobile-primary-navigation" :class="open ? 'flex' : 'hidden'" class="mt-4 flex-col gap-2 border-t border-white/20 pt-4 lg:flex lg:flex-row lg:items-center">
            @foreach($items as [$label,$route,$required])
                @if($required===null || $permissions->allows(...$required))
                    <a href="{{ route($route) }}" wire:navigate class="rounded-lg px-4 py-3 font-extrabold {{ request()->routeIs($route) ? 'bg-white text-gpha-primary' : 'bg-white/10 text-white hover:bg-white/20' }}">{{ $label }}</a>
                @endif
            @endforeach
            @if($permissions->allows('ReadinessAndActivities','View') || $permissions->allows('AmbulanceFleet','View'))
                <div class="relative" @mouseenter="openLogs()" @mouseleave="closeLogs()" @click.outside="logsOpen=false">
                    <button type="button" @click="logsOpen=!logsOpen" class="flex w-full items-center justify-between gap-2 rounded-lg px-4 py-3 font-extrabold {{ request()->routeIs('ems.mileage','ems.availability','ems.activities') ? 'bg-white text-gpha-primary' : 'bg-white/10 text-white hover:bg-white/20' }}">
                        Operations Logs
                        <svg class="h-4 w-4" :class="logsOpen?'rotate-180':''" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd"/></svg>
                    </button>
                    <div x-cloak x-show="logsOpen" x-transition @mouseenter="openLogs()" class="mt-2 min-w-64 overflow-hidden rounded-xl border border-slate-200 bg-white p-2 text-slate-800 shadow-xl lg:absolute lg:left-0 lg:top-full lg:z-50">
                        @if($permissions->allows('AmbulanceFleet','View'))<a href="{{ route('ems.mileage') }}" wire:navigate class="block rounded-lg px-4 py-3 font-bold hover:bg-slate-100">Mileage Readings</a>@endif
                        @if($permissions->allows('ReadinessAndActivities','View'))<a href="{{ route('ems.availability') }}" wire:navigate class="block rounded-lg px-4 py-3 font-bold hover:bg-slate-100">Availability Checks</a><a href="{{ route('ems.activities') }}" wire:navigate class="block rounded-lg px-4 py-3 font-bold hover:bg-slate-100">Weekly Activities</a>@endif
                    </div>
                </div>
            @endif
            @if($permissions->allows('EMSReports','View'))
                <a href="{{ route('ems.reports') }}" wire:navigate class="rounded-lg px-4 py-3 font-extrabold {{ request()->routeIs('ems.reports') ? 'bg-white text-gpha-primary' : 'bg-white/10 text-white hover:bg-white/20' }}">Reports</a>
            @endif
            <button type="button" @click="$dispatch('gpha-confirm', { title: 'Log Out of GPHA EMS?', message: 'Your current session will end and you will be returned to the Central Login portal.', confirmLabel: 'Yes, Log Out', tone: 'danger', onConfirm: () => $wire.logout() })" class="mt-2 inline-flex items-center gap-2 rounded-lg bg-white/10 px-4 py-3 font-extrabold text-[#ff5757] hover:bg-white/20 lg:ml-auto lg:mt-0">
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M14 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h7a2 2 0 0 0 2-2v-3M10 12h11m0 0-3-3m3 3-3 3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                Logout
            </button>
        </div>
    </div>
</nav>
