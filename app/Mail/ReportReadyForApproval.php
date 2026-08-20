<?php

namespace App\Mail;

use App\Models\EmsReport;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class ReportReadyForApproval extends Mailable
{
    use Queueable, SerializesModels;

    public string $approvalUrl;
    public int $approvalLinkHours;
    public string $approvalExpiresAt;

    public function __construct(
        public EmsReport $report,
        public string $approverName,
        public string $approverEmail,
    )
    {
        $this->approvalLinkHours = max(1, (int) config('ems.report_approval_link_hours', 72));
        $expiresAt = now()->addHours($this->approvalLinkHours);
        $this->approvalExpiresAt = $expiresAt->format('d M Y, H:i');
        $signedPath = URL::temporarySignedRoute(
            'ems.reports.guest-approval',
            $expiresAt,
            ['report' => $report, 'approver' => $this->approverEmail],
            absolute: false,
        );
        $this->approvalUrl = rtrim((string) config('app.url'), '/').'/'.ltrim($signedPath, '/');
    }

    public function envelope(): Envelope
    {
        $type = match ($this->report->type) {
            'mileage' => 'Ambulance Mileage Report',
            'availability' => 'Radio & Availability Report',
            default => 'Operational Activities Report',
        };

        return new Envelope(subject: 'Approval Required — '.$type);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.ems-report-ready');
    }
}
