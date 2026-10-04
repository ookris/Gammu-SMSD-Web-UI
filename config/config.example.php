<?php
// Wartości domyślne konfiguracji panelu. Lokalne zmiany – w config/config.php
// (tablica z tymi samymi kluczami, nakładana na ten plik). Ścieżki, dane bazy i polecenia systemowe
// są tylko tutaj – nie da się ich zmienić z panelu (polecenia systemowe i ścieżki – zabezpieczenie przed ich podmianą z poziomu WWW).
return [
    'db' => [
        'dsn' => 'mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=smsgui;charset=utf8mb4',
        'user' => 'smsgui',
        'password' => '',
    ],
    'gammu_db' => 'gammu',

    'log_path' => '/var/lib/smsgui/smsgui.log',
    'backup_dir' => '/var/lib/smsgui/backups',
    'session_path' => '/var/lib/smsgui/sessions',
    'gammu_conf' => '/etc/gammu-smsdrc',
    'blocklist_file' => '/var/lib/smsgui/exclude-numbers.txt',
    'gammu_log' => null,                 // null = parametr logfile z gammu-smsdrc
    'serial_dir' => '/dev/serial/by-id', // lista portów w formularzu konfiguracji

    'creator_id' => 'smsgui',
    'gammu_rows' => 'keep',              // keep / delete
    'worker_interval' => 3,
    'default_country_code' => '48',
    'national_number_length' => 9,
    'max_recipients' => 500,

    'service' => [
        'name' => 'gammu-smsd',
        'status_cmd' => 'systemctl is-active gammu-smsd',
        'reload_cmd' => 'sudo -n /usr/bin/systemctl reload gammu-smsd',
        'restart_cmd' => 'sudo -n /usr/bin/systemctl restart gammu-smsd',
    ],

    // Hook połączeń (RunOnIncomingCall): polecenie wpisywane do gammu-smsdrc i dane konta smsgui_hook
    'hook' => [
        'command' => '/usr/bin/php /opt/smsgui/bin/smsgui hook call',
        'cnf' => '/etc/smsgui/hook.cnf',
    ],

    'timezone' => 'Europe/Warsaw',
    'debug' => false,
];
