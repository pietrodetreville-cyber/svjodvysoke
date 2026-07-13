<?php
require_once __DIR__ . '/includes/functions.php';
startSession();

// Přihlášený → přesměrovat do portálu
$u = currentUser();
if ($u) {
    header('Location: ' . ($u['role'] === 'admin' ? '/admin/dashboard.php' : '/owner/dashboard.php'));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username && $password) {
        $stmt = db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $row = $stmt->fetch();

        if ($row && password_verify($password, $row['password_hash'])) {
            $_SESSION['user'] = [
                'id'      => $row['id'],
                'username'=> $row['username'],
                'role'    => $row['role'],
                'unit_id' => $row['unit_id'],
            ];
            session_regenerate_id(true);
            header('Location: ' . ($row['role'] === 'admin' ? '/admin/dashboard.php' : '/owner/dashboard.php'));
            exit;
        }
        $error = 'Nesprávné jméno nebo heslo.';
    } else {
        $error = 'Vyplňte přihlašovací jméno i heslo.';
    }
}

// Načti veřejné příspěvky
$posts = db()->query("SELECT * FROM posts WHERE visibility='verejny' ORDER BY pinned DESC, created_at DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SVJ Od Vysoké – Rozhled</title>
<link rel="shortcut icon" href="/favicon-32.png">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/favicon-16.png">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --blue:#185FA5;--blue-dk:#0C447C;--blue-lt:#E6F1FB;
  --border:#e0dfd8;--bg:#f7f6f2;--card:#fff;
  --text:#1a1a18;--muted:#6b6a65;
  --red-lt:#FCEBEB;--red:#A32D2D;
  --radius:10px;--radius-sm:6px;
}
body{font-family:system-ui,-apple-system,sans-serif;font-size:15px;color:var(--text);background:var(--bg);line-height:1.6;background-image:url("/dum.jpg");background-size:cover;background-position:center;background-attachment:fixed;background-repeat:no-repeat}
body::before{content:"";position:fixed;inset:0;background:rgba(247,246,242,0.0);z-index:0;pointer-events:none}
.topbar,.container{position:relative;z-index:1}

/* Topbar */
.topbar{background:#fff;border-bottom:1px solid var(--border);padding:0 1.5rem;height:54px;display:flex;align-items:center;gap:1rem}
.topbar-brand{font-weight:600;font-size:15px;color:var(--text);display:flex;align-items:center;gap:8px}
.topbar-brand svg{color:var(--blue)}
.topbar-right{margin-left:auto}
.btn-login-top{background:var(--blue);color:#fff;border:none;padding:6px 18px;border-radius:var(--radius-sm);font-size:14px;font-weight:500;cursor:pointer}
.btn-login-top:hover{background:var(--blue-dk)}

/* Layout */
.container{max-width:900px;margin:0 auto;padding:2rem 1rem}
.layout{display:grid;grid-template-columns:1fr 340px;gap:1.5rem;align-items:start}
@media(max-width:700px){.layout{grid-template-columns:1fr}}

/* Nástěnka */
.section-title{font-size:20px;font-weight:600;margin-bottom:1.25rem;color:var(--text)}
.card{background:#fff;border:2px solid var(--border);border-radius:var(--radius);padding:1.25rem;box-shadow:0 8px 32px rgba(0,0,0,0.18)}
.post-item{padding:.85rem 0;border-bottom:1px solid var(--border)}
.post-item:last-child{border-bottom:none}
.post-meta{font-size:12px;color:var(--muted);margin-bottom:3px}
.post-title{font-size:15px;font-weight:600;color:var(--text)}
.post-body{font-size:13px;color:var(--muted);margin-top:4px;line-height:1.55}
.badge-pinned{display:inline-block;font-size:11px;font-weight:600;padding:2px 8px;border-radius:99px;background:#E6F1FB;color:#185FA5;margin-bottom:4px}
.empty{color:var(--muted);font-size:14px;padding:1rem 0}

/* Login box */
.login-box{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:1.5rem;position:sticky;top:70px}
.login-title{font-size:16px;font-weight:600;margin-bottom:.25rem}
.login-sub{font-size:13px;color:var(--muted);margin-bottom:1.25rem}
.form-group{margin-bottom:.9rem}
.form-group label{display:block;font-size:13px;font-weight:500;color:var(--muted);margin-bottom:4px}
.form-group input{width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:14px;color:var(--text);background:#fff}
.form-group input:focus{outline:none;border-color:var(--blue);box-shadow:0 0 0 3px rgba(24,95,165,.15)}
.btn-submit{width:100%;padding:8px;background:var(--blue);color:#fff;border:none;border-radius:var(--radius-sm);font-size:14px;font-weight:500;cursor:pointer;margin-top:.25rem}
.btn-submit:hover{background:var(--blue-dk)}
.error{background:var(--red-lt);color:var(--red);padding:.6rem .9rem;border-radius:var(--radius-sm);font-size:13px;margin-bottom:1rem;font-weight:500}
.login-hint{font-size:12px;color:var(--muted);text-align:center;margin-top:.9rem}
.divider{border:none;border-top:1px solid var(--border);margin:1.25rem 0}
.feature-list{list-style:none;font-size:13px;color:var(--muted)}
.feature-list li{padding:4px 0;display:flex;align-items:center;gap:6px}
.feature-list li::before{content:'→';color:var(--blue);font-weight:600}

footer{text-align:center;padding:1.5rem;font-size:12px;color:var(--muted);border-top:1px solid var(--border);margin-top:2rem}
</style>
</head>
<body>

<nav class="topbar">
  <div class="topbar-brand">
    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
      <path d="M3 21h18M3 7l9-4 9 4M4 7v14M20 7v14M8 11v4m4-4v4m4-4v4"/>
    </svg>
    SVJ Od Vysoké – Rozhled
  </div>
  <div class="topbar-right">
    <button class="btn-login-top" onclick="document.getElementById('login-form').scrollIntoView({behavior:'smooth'})">Přihlásit se</button>
  </div>
</nav>

<div class="container">
  <div class="layout">

    <!-- NÁSTĚNKA (veřejná) -->
    <div>
      <div class="section-title">📋 Nástěnka výboru</div>
      <div class="card">
        <?php if ($posts): foreach ($posts as $p): ?>
          <div class="post-item">
            <?php if ($p['pinned']): ?><span class="badge-pinned">Připnutý</span><br><?php endif; ?>
            <div class="post-meta"><?= date('j. n. Y', strtotime($p['created_at'])) ?></div>
            <div class="post-title"><?= htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8') ?></div>
            <div class="post-body"><?= nl2br(htmlspecialchars($p['body'], ENT_QUOTES, 'UTF-8')) ?></div>
          </div>
        <?php endforeach; else: ?>
          <p class="empty">Zatím žádné příspěvky.</p>
        <?php endif; ?>
      </div>
    </div>

    <!-- LOGIN + INFO -->
    <div id="login-form">
      <div class="login-box">
        <div class="login-title">Přihlášení vlastníků</div>
        <div class="login-sub">Pro vyplnění karty a hlasování v anketách</div>

        <?php if ($error): ?>
          <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="POST" action="">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <div class="form-group">
            <label for="username">Přihlašovací jméno</label>
            <input type="text" id="username" name="username" autocomplete="username" required
                   value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES) ?>">
          </div>
          <div class="form-group">
            <label for="password">Heslo</label>
            <input type="password" id="password" name="password" autocomplete="current-password" required>
          </div>
          <button type="submit" class="btn-submit">Přihlásit se</button>
          <div style="text-align:center;margin-top:.75rem">
            <a href="/forgot_password.php" style="font-size:12px;color:var(--muted)">Zapomenuté heslo?</a>
          </div>
        </form>

        <hr class="divider">

        <ul class="feature-list">
          <li>Vyplnění kontaktní karty</li>
          <li>Hlasování v anketách</li>
          <li>Zobrazení všech aktualit</li>
        </ul>

        <p class="login-hint">Přihlašovací údaje obdržíte od výboru SVJ.</p>
      </div>
    </div>

  </div>
</div>

<footer>SVJ Od Vysoké – Rozhled &nbsp;·&nbsp; <?= date('Y') ?></footer>
</body>
</html>
