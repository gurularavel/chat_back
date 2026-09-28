<?php

return [

    // fake | kapitalbank
    'default' => env('PAYMENT_GATEWAY', 'fake'),

    'currency' => 'AZN',

    // Renewal retry policy
    'max_renewal_attempts' => 3,
    'retry_interval_days' => 2,
    'grace_days' => 7,

    // Renewal invoices: issued this many days before the period ends (by plan interval),
    // then reminders are emailed when this many days are left (0 = due today).
    'invoice_lead_days' => ['month' => 7, 'year' => 30],
    'reminder_days' => [3, 1, 0],

    'gateways' => [
        'kapitalbank' => [
            'base_url' => env('KAPITAL_BANK_BASE_URL', 'https://txpgtst.kapitalbank.az/api'),
            'username' => env('KAPITAL_BANK_USERNAME'),
            'password' => env('KAPITAL_BANK_PASSWORD'),
            'language' => 'az',
        ],
    ],
];
