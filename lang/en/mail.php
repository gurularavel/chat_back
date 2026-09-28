<?php

return [
    'invitation' => [
        'subject' => 'Invitation to join :workspace',
        'line' => 'You have been invited to join :workspace as :role.',
        'action' => 'Accept invitation',
        'expires' => 'This invitation expires on :date.',
    ],
    'test' => [
        'subject' => 'Test email',
        'body' => 'The :app mail settings work. This email was sent from the superadmin panel.',
        'failed' => 'The email could not be sent: :error',
    ],
    'billing' => [
        'greeting' => 'Hello, :name!',
        'interval' => ['month' => 'monthly', 'year' => 'yearly', 'prorated' => 'extra seats'],
        'issued' => [
            'subject' => 'Payment due: :interval subscription — :number',
            'intro' => 'The :interval payment for :workspace is coming up. Your invoice is ready, due on :date.',
        ],
        'reminder' => [
            'subject' => 'Reminder: payment due in :days day(s) — :number',
            'intro' => 'Your :interval subscription payment is due in :days day(s) (on :date).',
        ],
        'due' => [
            'subject' => 'Payment due today — :number',
            'intro' => 'Today is the due date of your :interval subscription payment.',
        ],
        'failed' => [
            'subject' => 'Payment failed — :number',
            'intro' => 'We could not collect :amount. Please pay by :grace to keep the service running.',
        ],
        'paid' => [
            'subject' => 'Payment received — :number',
            'intro' => 'Thank you! We received your payment of :amount.',
        ],
        'invoice' => 'Invoice',
        'service' => 'Service',
        'period' => 'Period',
        'seats' => 'Seats',
        'amount' => 'Amount',
        'due' => 'Due date',
        'autocharge' => 'On the due date the amount will be charged automatically to your saved card (:card). You can also pay now.',
        'pay_manually' => 'Please pay by the due date to keep the service running without interruption.',
        'pay' => 'Log in and pay',
        'view' => 'View invoice',
        'footer' => 'Questions? Just reply to this email.',
    ],
];
