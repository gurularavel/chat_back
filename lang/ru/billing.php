<?php

return [
    'limit_exceeded' => 'Достигнут лимит тарифа: :limit (макс. :max). Повысьте тариф.',
    'limits' => [
        'seats' => 'места (операторы)',
        'widgets' => 'виджеты',
        'documents' => 'документы',
        'max_pdf_mb' => 'размер PDF (МБ)',
        'total_storage_mb' => 'хранилище (МБ)',
        'conversations_per_month' => 'диалогов в месяц',
    ],
    'needs_active_subscription' => 'Требуется активная платная подписка.',
    'charge_failed' => 'Не удалось списать оплату с карты.',
    'invalid_seats' => 'Количество мест должно быть от :min до :max.',
    'seats_below_members' => 'Мест не может быть меньше текущих участников (:members).',
    'workspace_suspended' => 'Это рабочее пространство приостановлено. Свяжитесь с поддержкой.',
    'invoice_line' => ['month' => 'Тариф :plan — ежемесячная подписка', 'year' => 'Тариф :plan — годовая подписка', 'prorated' => 'Тариф :plan — доп. места (остаток периода)'],
    'invoice_not_payable' => 'Этот счёт уже оплачен или аннулирован.',
];
