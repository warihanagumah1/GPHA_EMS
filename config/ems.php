<?php

return [
    'report_approver_email' => env('EMS_REPORT_APPROVER_EMAIL', 'emasiedu@ghanaports.gov.gov.gh'),
    'report_approvers' => env('EMS_REPORT_APPROVERS', 'Dr. Emile <'.env('EMS_REPORT_APPROVER_EMAIL', 'emasiedu@ghanaports.gov.gov.gh').'>'),
    'report_approval_link_hours' => (int) env('EMS_REPORT_APPROVAL_LINK_HOURS', 72),

    'movement_priorities' => [
        'routine' => 'Routine',
        'non_emergency' => 'Non-emergency',
        'emergency' => 'Emergency',
    ],

    'case_categories' => [
        'Emergency response',
        'Patient transfer',
        'Medical evacuation',
        'Staff medical transport',
        'Accident response',
        'Medical coverage',
        'Equipment or supply movement',
        'Routine operational movement',
        'Maintenance or servicing',
        'Training or drill',
    ],

];
