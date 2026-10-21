<?php
// Copy to config.php outside the public directory. Never upload it into public/.
return [
    'development' => false,
    'timezone' => 'Asia/Novosibirsk',
    // Set a private random phrase of at least 32 characters for first setup.
    // After creating the administrator, this key is no longer accepted.
    'install_key' => '',
    'storage' => dirname(__DIR__) . '/storage',
    // Only for SMTP hosted on this same server; TLS still verifies the configured name.
    'smtp_loopback_hosts' => [],
    'db' => [
        'driver' => 'mysql',
        'host' => 'localhost',
        'port' => 3306,
        'database' => '',
        'username' => '',
        'password' => '',
    ],
];
