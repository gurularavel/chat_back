<?php

return [
    'invitation' => [
        'subject' => ':workspace komandasına dəvət',
        'line' => 'Sizi :workspace komandasına :role kimi dəvət ediblər.',
        'action' => 'Dəvəti qəbul et',
        'expires' => 'Dəvət :date tarixinədək etibarlıdır.',
    ],
    'test' => [
        'subject' => 'Test məktubu',
        'body' => ':app mail ayarları düzgün işləyir. Bu məktub superadmin panelindən göndərilib.',
        'failed' => 'Mail göndərilmədi: :error',
    ],
    'billing' => [
        'greeting' => 'Salam, :name!',
        'interval' => ['month' => 'aylıq', 'year' => 'illik', 'prorated' => 'əlavə yer'],
        'issued' => [
            'subject' => 'Ödəniş vaxtıdır: :interval abunə — :number',
            'intro' => ':workspace hesabınızın :interval ödəniş vaxtı yaxınlaşır. Faktura hazırdır, son ödəniş tarixi :date.',
        ],
        'reminder' => [
            'subject' => 'Xatırlatma: ödənişə :days gün qalıb — :number',
            'intro' => 'Abunə ödənişinə :days gün qalıb (son tarix :date).',
        ],
        'due' => [
            'subject' => 'Bu gün ödəniş günüdür — :number',
            'intro' => 'Bu gün abunə ödənişinin son günüdür.',
        ],
        'failed' => [
            'subject' => 'Ödəniş alınmadı — :number',
            'intro' => ':amount məbləğində ödəniş alınmadı. Xidmətin dayanmaması üçün :grace tarixinədək ödəniş edin.',
        ],
        'paid' => [
            'subject' => 'Ödəniş qəbul edildi — :number',
            'intro' => 'Təşəkkür edirik! :amount məbləğində ödənişiniz qəbul edildi.',
        ],
        'invoice' => 'Faktura',
        'service' => 'Xidmət',
        'period' => 'Dövr',
        'seats' => 'Yer sayı',
        'amount' => 'Məbləğ',
        'due' => 'Son ödəniş tarixi',
        'autocharge' => 'Son tarixdə məbləğ yadda saxlanmış kartınızdan (:card) avtomatik çıxılacaq. İstəsəniz, indi də ödəyə bilərsiniz.',
        'pay_manually' => 'Xidmətin fasiləsiz davam etməsi üçün son tarixədək ödəniş edin.',
        'pay' => 'Daxil olun və ödəyin',
        'view' => 'Fakturaya baxın',
        'footer' => 'Sualınız varsa, bu məktuba cavab yazın.',
    ],
];
