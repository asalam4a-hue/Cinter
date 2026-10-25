<?php
// إعدادات قاعدة البيانات - الافتراضي SQLite (لا يحتاج إعداد). لاستخدام MySQL غيّر 'driver' واملأ البيانات.
return [
    'driver'   => 'sqlite',                       // 'sqlite' أو 'mysql'
    'sqlite'   => __DIR__ . '/data/contracts.sqlite',
    'mysql'    => ['host' => 'localhost', 'port' => 3306, 'dbname' => 'contracts', 'user' => 'root', 'pass' => ''],
    'timezone' => 'Africa/Cairo',
    'session_ttl' => 12 * 3600,
];
