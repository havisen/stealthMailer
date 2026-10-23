<?php

declare(strict_types=1);

return [
    // Operators can add endpoints here or override them with
    // MAILPANEL_APPROVED_SMTP_ENDPOINTS. Certificate verification stays mandatory.
    'approved_endpoints' => [
        ['host' => 'smtp.protonmail.ch', 'port' => 587, 'tls' => 'starttls'],
        ['host' => 'mail.privateemail.com', 'port' => 465, 'tls' => 'implicit'],
        ['host' => 'mail.privateemail.com', 'port' => 587, 'tls' => 'starttls'],
    ],
    'connect_timeout_seconds' => 10,
    'operation_timeout_seconds' => 20,
    'retry_delays_seconds' => [60, 120, 240],
    'maximum_attempts' => 4,
    'authentication_pause_threshold' => 3,
    'authentication_failure_window_seconds' => 900,
];
