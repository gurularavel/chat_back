<?php

return [
    'limit_exceeded' => 'Plan limit reached: :limit (max :max). Please upgrade.',
    'limits' => [
        'seats' => 'seats',
        'widgets' => 'widgets',
        'documents' => 'documents',
        'max_pdf_mb' => 'PDF size (MB)',
        'total_storage_mb' => 'storage (MB)',
        'conversations_per_month' => 'conversations per month',
    ],
    'needs_active_subscription' => 'An active paid subscription is required.',
    'charge_failed' => 'The card could not be charged.',
    'invalid_seats' => 'Seats must be between :min and :max.',
    'seats_below_members' => 'Seats cannot be fewer than current members (:members).',
    'workspace_suspended' => 'This workspace is suspended. Please contact support.',
    'invoice_line' => ['month' => ':plan plan — monthly subscription', 'year' => ':plan plan — yearly subscription', 'prorated' => ':plan plan — extra seats (rest of the period)'],
    'interval_unavailable' => 'This plan is not sold yearly.',
    'invoice_not_payable' => 'This invoice is already paid or void.',
    'sla_not_in_plan' => 'Response-time SLA is not included in your plan.',
];
