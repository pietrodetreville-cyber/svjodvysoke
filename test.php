<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Test 1: Config</h2>";
try {
    require_once __DIR__ . '/config.php';
    echo "✓ OK — DB: " . DB_NAME . " na " . DB_HOST . "<br>";
} catch (Throwable $e) { die("❌ " . $e->getMessage()); }

echo "<h2>Test 2: Databáze</h2>";
try {
    $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo "✓ Připojeno<br>";
} catch (Throwable $e) { die("❌ " . $e->getMessage()); }

echo "<h2>Test 3: Tabulky</h2>";
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $t) echo "✓ $t<br>";
if (!$tables) echo "❌ Žádné tabulky — importujte SQL!<br>";

echo "<h2>Test 4: Users</h2>";
try {
    $users = $pdo->query("SELECT id, username, role FROM users")->fetchAll();
    foreach ($users as $u) echo "• {$u['id']} | {$u['username']} | {$u['role']}<br>";
} catch (Throwable $e) { echo "❌ " . $e->getMessage() . "<br>"; }

echo "<h2>Test 5: Functions</h2>";
try {
    require_once __DIR__ . '/includes/functions.php';
    echo "✓ OK<br>";
} catch (Throwable $e) { die("❌ " . $e->getMessage()); }

echo "<h2>Test 6: Přihlášení</h2>";
try {
    require_once __DIR__ . '/includes/functions.php';
    startSession();
    echo "✓ Session OK<br>";
    $u = currentUser();
    echo "Aktuální uživatel: " . ($u ? $u['username'].' ('.$u['role'].')' : 'nepřihlášen') . "<br>";
} catch (Throwable $e) { echo "❌ " . $e->getMessage() . "<br>"; }

echo "<h2>Hotovo!</h2>";
