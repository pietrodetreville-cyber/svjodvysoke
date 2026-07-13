<?php
function loadEnv(string $path): void {
    if (!is_readable($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        putenv(trim($key) . '=' . trim($value));
    }
}
loadEnv(__DIR__ . '/.env');

define('DB_HOST', getenv('DB_HOST'));
define('DB_NAME', getenv('DB_NAME'));
define('DB_USER', getenv('DB_USER'));
define('DB_PASS', getenv('DB_PASS'));

define('SITE_NAME', getenv('SITE_NAME') ?: 'SVJ Od Vysoké – Rozhled');
define('SITE_URL',  getenv('SITE_URL')  ?: 'https://odvysoke.drymtym.cz');

define('SECRET_KEY', getenv('SECRET_KEY'));

// Nahrané dokumenty: lokálně/v defaultu vedle projektu, na produkci
// přebito v .env.production (viz poznámka tam k ověření správné cesty).
define('UPLOAD_DIR', getenv('UPLOAD_DIR') ?: __DIR__ . '/uploads/documents/');
define('UPLOAD_URL', getenv('UPLOAD_URL') ?: rtrim(SITE_URL, '/') . '/uploads/documents/');

date_default_timezone_set('Europe/Prague');

// Gmail SMTP konfigurace
define('MAIL_FROM',     'vybor.vysoka@gmail.com');
define('MAIL_FROM_NAME','SVJ Od Vysoké – Rozhled – výbor');
define('GMAIL_USER',    getenv('GMAIL_USER'));
define('GMAIL_PASS',    getenv('GMAIL_PASS'));
