<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EMS Report Ready for Approval</title>
</head>
<body style="margin:0;padding:0;background:#eef3f8;color:#10213c;font-family:Arial,'Helvetica Neue',sans-serif;">
@php
    $reportType = match($report->type) {
        'mileage' => 'Ambulance Mileage Report',
        'availability' => 'Radio & Availability Report',
        default => 'Operational Activities Report',
    };
@endphp
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">A GPHA EMS report is ready for your review and approval.</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background:#eef3f8;">
    <tr>
        <td align="center" style="padding:30px 12px;">
            <table role="presentation" width="620" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:620px;background:#ffffff;border-collapse:separate;border-spacing:0;border-radius:14px;overflow:hidden;box-shadow:0 8px 28px rgba(9,47,109,.12);">
                <tr><td style="height:8px;background:#d51f26;font-size:0;line-height:0;">&nbsp;</td></tr>
                <tr>
                    <td align="center" style="padding:28px 30px 22px;background:#092f6d;color:#ffffff;">
                        <div style="font-size:13px;font-weight:800;letter-spacing:1.4px;text-transform:uppercase;color:#ffffff;">Ghana Ports and Harbours Authority</div>
                        <div style="margin-top:8px;font-size:26px;line-height:1.2;font-weight:900;color:#ffffff;">Emergency Medical Services</div>
                        <div style="margin-top:15px;display:inline-block;padding:7px 14px;border-radius:999px;background:#d51f26;color:#ffffff;font-size:12px;font-weight:800;letter-spacing:1px;">APPROVAL REQUIRED</div>
                    </td>
                </tr>
                <tr>
                    <td style="padding:30px;">
                        <h1 style="margin:0 0 14px;color:#092f6d;font-size:25px;line-height:1.25;">EMS Report Ready for Approval</h1>
                        <p style="margin:0 0 14px;font-size:16px;line-height:1.65;">Dear {{ $approverName }},</p>
                        <p style="margin:0 0 22px;font-size:16px;line-height:1.65;">A formal GPHA Emergency Medical Services report has been prepared, signed, and submitted for your approval.</p>

                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border:1px solid #c7d2e0;border-left:5px solid #087a39;border-radius:9px;background:#f8fafc;">
                            <tr><td style="padding:18px 20px;">
                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;font-size:15px;line-height:1.5;">
                                    <tr><td style="padding:4px 10px 4px 0;color:#52647c;font-weight:700;">Report</td><td style="padding:4px 0;color:#10213c;font-weight:800;">{{ $reportType }}</td></tr>
                                    <tr><td style="padding:4px 10px 4px 0;color:#52647c;font-weight:700;">Reporting period</td><td style="padding:4px 0;color:#10213c;">{{ $report->period_start->format('d M Y') }} – {{ $report->period_end->format('d M Y') }}</td></tr>
                                    <tr><td style="padding:4px 10px 4px 0;color:#52647c;font-weight:700;">Prepared by</td><td style="padding:4px 0;color:#10213c;">{{ $report->preparedBy?->job_title ?: 'SAEMT' }} {{ $report->preparedBy?->name ?: 'EMS Report Officer' }}</td></tr>
                                    <tr><td style="padding:4px 10px 4px 0;color:#52647c;font-weight:700;">Submitted</td><td style="padding:4px 0;color:#10213c;">{{ $report->submitted_at?->format('d M Y, H:i') }}</td></tr>
                                </table>
                            </td></tr>
                        </table>

                        <p style="margin:22px 0 18px;font-size:16px;line-height:1.65;">Review the complete generated report, add your signature, and approve it using the button below.</p>
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td align="center" style="padding:2px 0 22px;">
                            <a href="{{ $approvalUrl }}" style="display:inline-block;padding:14px 24px;border-radius:7px;background:#00579b;color:#ffffff;text-decoration:none;font-size:16px;font-weight:900;box-shadow:0 4px 10px rgba(0,87,155,.2);">Review &amp; Sign Report</a>
                        </td></tr></table>

                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-radius:8px;background:#fff7ed;"><tr><td style="padding:14px 16px;color:#7c2d12;font-size:14px;line-height:1.55;">
                            <strong>Action required:</strong> This report must be reviewed and approved within <strong>{{ $approvalLinkHours }} hours</strong> of submission. No login or EMS permission is required. The secure link expires on <strong>{{ $approvalExpiresAt }}</strong>.
                        </td></tr></table>

                        <p style="margin:20px 0 5px;font-size:14px;line-height:1.55;color:#52647c;">This link authorizes its holder to review, sign, and approve this report. Please do not forward it outside the intended approval process.</p>
                        <p style="margin:10px 0 0;font-size:12px;line-height:1.5;color:#64748b;word-break:break-all;">If the button does not work, copy and paste this address into your browser:<br><a href="{{ $approvalUrl }}" style="color:#00579b;">{{ $approvalUrl }}</a></p>

                        <p style="margin:24px 0 0;font-size:15px;line-height:1.6;color:#10213c;">Regards,<br><strong style="color:#092f6d;">{{ $report->submittedBy?->name ?: $report->preparedBy?->name ?: 'EMS Report Officer' }}</strong><br><span style="color:#52647c;">{{ $report->submittedBy?->job_title ?: $report->preparedBy?->job_title ?: 'SAEMT' }}</span></p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:22px 30px;background:#092f6d;color:#ffffff;text-align:center;">
                        <div style="font-size:14px;font-weight:800;">GPHA Emergency Medical Services Department</div>
                        <div style="margin-top:5px;font-size:12px;color:#dce6f2;">Ghana Ports and Harbours Authority</div>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
