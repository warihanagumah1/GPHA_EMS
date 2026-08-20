@php
    $reportTypeLabels=['mileage'=>'Ambulance Mileage','weekly_activity'=>'Operational Activities','availability'=>'Radio & Availability'];
    $reportListTitle=$approverTabs
        ? match($activeReportTab){'submitted'=>'Submitted Reports','approved'=>'Approved Reports',default=>'All Reports'}
        : (filled($reportFilters['report_status']??null)?str($reportFilters['report_status'])->headline().' Reports':'All Reports');
@endphp
<section class="gpha-panel overflow-hidden">
    <div class="flex flex-col gap-2 border-b border-slate-200 p-5 sm:flex-row sm:items-center sm:justify-between"><div><h2 class="text-xl font-black text-slate-950">{{ $reportListTitle }}</h2><p class="font-semibold text-slate-500">@if($canApprove) You can review all reports and approve submitted reports. @else Reports prepared or submitted by you. @endif</p></div><span class="w-fit rounded-full bg-slate-100 px-3 py-1 text-sm font-black text-slate-700">{{ $reports->total() }}</span></div>
    <div class="overflow-x-auto">
        <table class="gpha-table min-w-[980px]">
            <thead><tr><th>Report</th><th>Status</th><th>Reporting Period</th><th>Prepared By</th><th>Submitted By / Date</th><th>Approved By / Date</th><th class="gpha-actions-heading">Actions</th></tr></thead>
            <tbody>
            @forelse($reports as $report)
                @php
                    $isPreparer=(int)$report->prepared_by===(int)auth()->id();
                    $isMutable=in_array($report->status,['draft','submitted'],true);
                @endphp
                <tr>
                    <td class="font-extrabold text-slate-950">{{ $reportTypeLabels[$report->type]??str($report->type)->headline() }}</td>
                    <td><span class="rounded-full px-3 py-1 text-xs font-black {{ match($report->status){'approved'=>'bg-emerald-100 text-emerald-800','submitted'=>'bg-amber-100 text-amber-800',default=>'bg-slate-100 text-slate-700'} }}">{{ $report->status==='submitted'?'Awaiting Approval':str($report->status)->headline() }}</span></td>
                    <td>{{ $report->period_start->format('d M Y') }} – {{ $report->period_end->format('d M Y') }}</td>
                    <td><span class="font-extrabold">{{ $report->preparedBy?->job_title?:'SAEMT' }}</span><br>{{ $report->preparedBy?->name??'EMS Report Officer' }}</td>
                    <td>@if($report->submitted_at)<span class="font-bold">{{ $report->submittedBy?->name??$report->preparedBy?->name }}</span><br>{{ $report->submitted_at->format('d M Y, H:i') }}@else<span class="text-slate-400">Not submitted</span>@endif</td>
                    <td>@if($report->approved_at)<span class="font-bold">{{ $report->approvedBy?->name??'EMS Manager' }}</span><br>{{ $report->approved_at->format('d M Y, H:i') }}@else<span class="text-slate-400">Not approved</span>@endif</td>
                    <td class="gpha-actions-cell"><div class="relative inline-block text-left" x-data="{open:false,menuTop:0,menuLeft:0,positionMenu(){const r=this.$refs.trigger.getBoundingClientRect(),w=224,h=230,p=8;this.menuTop=Math.max(p,Math.min(r.top,window.innerHeight-h-p));this.menuLeft=r.right+p+w<=window.innerWidth-p?r.right+p:Math.max(p,r.left-w-p)}}" @resize.window="open&&positionMenu()" @scroll.window="open&&positionMenu()" @keydown.escape.window="open=false"><x-ems.action-trigger x-ref="trigger" x-bind:class="{'is-open':open}" @click.stop="positionMenu();open=!open" label="Report actions" /><div x-cloak x-show="open" x-transition @click.outside="open=false" :style="`top:${menuTop}px;left:${menuLeft}px`" class="gpha-floating-action-menu">
                        @if($report->status==='draft'&&$isPreparer&&$canManage)<a href="{{ route('ems.reports.print',$report) }}" class="block w-full px-4 py-2 text-left font-semibold text-emerald-700 hover:bg-emerald-50">Review &amp; Sign</a>@elseif($report->status==='submitted'&&$canApprove)<a href="{{ route('ems.reports.print',$report) }}" class="block w-full px-4 py-2 text-left font-semibold text-emerald-700 hover:bg-emerald-50">Review &amp; Approve</a>@else<a href="{{ route('ems.reports.print',$report) }}" class="block w-full px-4 py-2 text-left font-semibold text-emerald-700 hover:bg-emerald-50">View Report</a>@endif
                        <a href="{{ route('ems.reports.print',['report'=>$report,'print'=>1]) }}" target="_blank" rel="noopener" class="block w-full px-4 py-2 text-left font-semibold text-slate-700 hover:bg-slate-50">Print / Download PDF</a>
                        @if($report->signed_report_path)<a href="{{ route('ems.reports.file',[$report,'signed-report']) }}" class="block w-full px-4 py-2 text-left font-semibold text-slate-700 hover:bg-slate-50">Download Signed PDF</a>@endif
                        @if($isMutable&&$isPreparer&&$canManage)<a href="{{ route('ems.reports.edit',$report) }}" class="block w-full px-4 py-2 text-left font-semibold text-gpha-primary hover:bg-blue-50">Edit</a><form method="POST" action="{{ route('ems.reports.destroy',$report) }}" data-confirm-title="Delete Report?" data-confirm-message="This {{ str($report->status)->headline() }} report and its signatures will be permanently deleted." data-confirm-label="Yes, Delete Report" data-confirm-tone="danger">@csrf @method('DELETE')<button type="submit" @click="open=false" class="block min-h-0 w-full px-4 py-2 text-left font-semibold text-red-600 hover:bg-red-50">Delete</button></form>@endif
                    </div></div></td>
                </tr>
            @empty<tr><td colspan="7" class="py-10 text-center font-semibold text-slate-500">No reports match the selected filters.</td></tr>@endforelse
            </tbody>
        </table>
    </div>
    @if($reports->hasPages())<div class="border-t border-slate-200 p-4">{{ $reports->links() }}</div>@endif
</section>
