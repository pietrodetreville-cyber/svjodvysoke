<?php
require_once __DIR__ . '/../config.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
    return $pdo;
}

function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', 1);
        session_name('SVJ_SESSION');
        session_start();
    }
}

function currentUser(): ?array {
    startSession();
    return $_SESSION['user'] ?? null;
}

function requireLogin(): array {
    $u = currentUser();
    if (!$u) { header('Location: /index.php'); exit; }
    return $u;
}

function isTenant(): bool {
    $u = currentUser();
    return ($u['role'] ?? '') === 'tenant';
}

function requireTenant(): array {
    $u = requireLogin();
    if ($u['role'] !== 'tenant') { header('Location: /owner/dashboard.php'); exit; }
    return $u;
}

function requireAdmin(): array {
    $u = requireLogin();
    if (!in_array($u['role'], ['admin','superadmin'])) {
        if ($u['role'] === 'tenant') { header('Location: /tenant/dashboard.php'); exit; }
        header('Location: /owner/dashboard.php'); exit;
    }
    return $u;
}

function requireSuperAdmin(): array {
    $u = requireLogin();
    if ($u['role'] !== 'superadmin') {
        header('Location: /admin/dashboard.php'); exit;
    }
    return $u;
}

function isSuperAdmin(): bool {
    $u = currentUser();
    return ($u['role'] ?? '') === 'superadmin';
}

function isAdmin(): bool {
    $u = currentUser();
    return in_array($u['role'] ?? '', ['admin','superadmin']);
}

function csrfToken(): string {
    startSession();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf'];
}

function csrfCheck(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf_token'] ?? '')) {
        http_response_code(403); die('Neplatný token.');
    }
}

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function flash(string $msg, string $type = 'info'): void {
    startSession();
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
}

function getFlash(): ?array {
    startSession();
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function ownerStatus(array $o): string {
    if (!($o['full_name'] ?? '') || !($o['gdpr_consent'] ?? 0)) return 'chybí';
    if (!($o['email'] ?? '') && !($o['phone'] ?? '')) return 'neúplná';
    return 'úplná';
}
