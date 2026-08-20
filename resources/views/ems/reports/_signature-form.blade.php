<section class="workflow">
    <h2>{{ $role === 'submitter' ? 'Sign and Submit Report' : 'Approve Report' }}</h2>
    <p>{{ $role === 'submitter' ? 'The person who prepared this report must sign it before submission. Draw below or upload a signature image.' : 'Draw your signature below or upload a signature image.' }}</p>
    <form method="POST" action="{{ $role === 'submitter' ? route('ems.reports.submit', $report) : ($guestApproveUrl ?? route('ems.reports.approve', $report)) }}" enctype="multipart/form-data" data-signature-form>
        @csrf
        @if($role === 'approver') @method('PATCH') @endif
        <div>
            <strong>Draw your signature</strong>
            <canvas width="700" height="180" data-signature-canvas aria-label="Signature drawing area"></canvas>
            <input type="hidden" name="signature_data" data-signature-data>
            <button type="button" class="secondary" data-clear-signature>Clear signature</button>
        </div>
        <div class="signature-choice-divider"><span>OR</span></div>
        <label><strong>Upload a signature image</strong><input type="file" name="signature_file" accept="image/png,image/jpeg" data-signature-file><small>PNG or JPG, maximum 2 MB.</small></label>
        <label class="confirmation"><input type="checkbox" name="signature_confirmation" value="1" required> I confirm that this is my signature and I accept responsibility for this {{ $role === 'submitter' ? 'submission' : 'approval' }}.</label>
        <button type="submit" class="primary">{{ $role === 'submitter' ? 'Sign & Submit for Approval' : 'Sign & Approve Report' }}</button>
    </form>
</section>
