<?php

declare(strict_types=1);

// config/app.php

return [
    'name' => $_ENV['APP_NAME'] ?? 'Email Marketing System',
    'env' => $_ENV['APP_ENV'] ?? 'production',
    'debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
    'url' => rtrim($_ENV['APP_URL'] ?? '', '/'),
    'timezone' => $_ENV['APP_TIMEZONE'] ?? 'Europe/Berlin',
    'key' => $_ENV['APP_KEY'] ?? '',

    'mail' => [
        'driver' => $_ENV['MAIL_DRIVER'] ?? 'smtp',
        'host' => $_ENV['MAIL_HOST'] ?? '',
        'port' => (int) ($_ENV['MAIL_PORT'] ?? 587),
        'encryption' => $_ENV['MAIL_ENCRYPTION'] ?? 'tls',
        'username' => $_ENV['MAIL_USERNAME'] ?? '',
        'password' => $_ENV['MAIL_PASSWORD'] ?? '',
        'from_address' => $_ENV['MAIL_FROM_ADDRESS'] ?? '',
        'from_name' => $_ENV['MAIL_FROM_NAME'] ?? '',
        'bounce_address' => $_ENV['MAIL_BOUNCE_ADDRESS'] ?? '',
    ],

    'dkim' => [
        'enabled' => filter_var($_ENV['DKIM_ENABLED'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'domain' => $_ENV['DKIM_DOMAIN'] ?? '',
        'selector' => $_ENV['DKIM_SELECTOR'] ?? 'newsletter',
        'private_key_path' => $_ENV['DKIM_PRIVATE_KEY_PATH'] ?? 'storage/dkim/private.key',
    ],

    'api' => [
        'token' => $_ENV['API_TOKEN'] ?? '',
    ],

    'admin' => [
        'username' => $_ENV['ADMIN_USERNAME'] ?? 'admin',
        'password_hash' => $_ENV['ADMIN_PASSWORD_HASH'] ?? '',
    ],

    'compliance' => [
        'impressum_url' => $_ENV['IMPRESSUM_URL'] ?? '',
        'datenschutz_url' => $_ENV['DATENSCHUTZ_URL'] ?? '',
        'double_opt_in' => filter_var($_ENV['DOUBLE_OPT_IN'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'audit_log_retention_months' => (int) ($_ENV['AUDIT_LOG_RETENTION_MONTHS'] ?? 24),
    ],
];
