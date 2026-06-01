<?php
require_once __DIR__ . '/includes/functions.php';
requireAdmin();

echo "<h2>Cesty na serveru</h2>";
echo "Document root: " . $_SERVER['DOCUMENT_ROOT'] . "<br>";
echo "Script: " . __FILE__ . "<br>";
echo "Admin dir: " . __DIR__ . "<br>";
echo "Web root: " . dirname(__DIR__) . "<br>";

$uploadDir = dirname(__DIR__) . '/uploads/documents/';
echo "<h2>Upload složka</h2>";
echo "Cesta: $uploadDir<br>";
echo "Existuje: " . (is_dir($uploadDir) ? 'ANO' : 'NE') . "<br>";
echo "Zapisovatelná: " . (is_writable($uploadDir) ? 'ANO' : 'NE - chyba!') . "<br>";

echo "<h2>Soubory v upload složce</h2>";
if (is_dir($uploadDir)) {
    $files = scandir($uploadDir);
    foreach ($files as $f) {
        if ($f !== '.' && $f !== '..') echo "$f<br>";
    }
} else {
    echo "Složka neexistuje!";
}

echo "<h2>URL test</h2>";
echo "UPLOAD_URL by mělo být: /uploads/documents/<br>";
echo "Plná URL: https://" . $_SERVER['HTTP_HOST'] . "/uploads/documents/<br>";
