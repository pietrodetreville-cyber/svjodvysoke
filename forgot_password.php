<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mailer.php';

if (currentUser()) { header('Location: /owner/dashboard.php'); exit; }

$pageTitle = 'Zapomenuté heslo';
$msg = null;
$msgType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $db = db();

    if (!$email) {
        $msg = 'Zadejte e-mailovou adresu.';
        $msgType = 'error';
    } else {
        // Najdi vlastníka podle e-mailu
        $stmt = $db->prepare(
            'SELECT o.*, u2.id AS user_id, u2.username
             FROM owners o
             JOIN users u2 ON u2.unit_id=o.unit_id
             WHERE o.email=? AND u2.role="owner"
             LIMIT 1'
        );
        $stmt->execute([$email]);
        $owner = $stmt->fetch();

        if ($owner) {
            // Vygeneruj nové dočasné heslo
            $newPass = substr(str_shuffle('abcdefghjkmnpqrstuvwxyz23456789'), 0, 8);
            $db->prepare('UPDATE users SET password_hash=? WHERE id=?')
               ->execute([password_hash($newPass, PASSWORD_BCRYPT), $owner['user_id']]);

            // Pošli e-mail
            $html = '<!DOCTYPE html>
<html lang="cs"><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;background:#f7f6f2;margin:0;padding:20px">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;margin:0 auto">
<tr><td>
  <table width="100%" style="background:#185FA5;border-radius:10px 10px 0 0"><tr><td style="padding:20px 28px">
    <p style="color:#fff;font-size:18px;font-weight:bold;margin:0">SVJ Od Vysoké – Rozhled</p>
    <p style="color:rgba(255,255,255,0.8);font-size:12px;margin:4px 0 0">Obnova hesla</p>
  </td></tr></table>
  <table width="100%" style="background:#fff"><tr><td style="padding:28px">
    <h2 style="color:#185FA5;margin:0 0 16px">Nové dočasné heslo</h2>
    <p style="color:#1a1a18;margin:0 0 16px">Byl vám vygenerován nový přístup k portálu SVJ Od Vysoké – Rozhled.</p>
    <table width="100%" style="background:#E6F1FB;border-radius:8px;margin-bottom:20px"><tr><td style="padding:16px 20px">
      <p style="margin:0 0 6px;font-size:13px;font-weight:bold;color:#185FA5">Přihlašovací údaje</p>
      <p style="margin:0 0 6px;font-size:14px"><strong>Jméno:</strong> ' . htmlspecialchars($owner['username']) . '</p>
      <p style="margin:0;font-size:14px"><strong>Dočasné heslo:</strong>
        <span style="font-family:monospace;background:#fff;border:1px solid #b5d0f0;border-radius:4px;padding:2px 10px;font-size:16px">' . $newPass . '</span>
      </p>
    </td></tr></table>
    <table width="100%" style="background:#FAEEDA;border-radius:8px;margin-bottom:20px"><tr><td style="padding:14px 20px">
      <p style="margin:0;color:#854F0B;font-size:13px">⚠️ <strong>Po přihlášení si změňte heslo v sekci Moje karta.</strong></p>
    </td></tr></table>
    <table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">
      <a href="https://odvysoke.drymtym.cz" style="display:inline-block;background:#185FA5;color:#fff;text-decoration:none;padding:12px 28px;border-radius:6px;font-size:15px;font-weight:bold">
        Přihlásit se →
      </a>
    </td></tr></table>
  </td></tr></table>
  <table width="100%" style="background:#f1efe8;border-radius:0 0 10px 10px;border-top:1px solid #e0dfd8"><tr><td style="padding:12px 28px;font-size:11px;color:#6b6a65">
    SVJ Od Vysoké – Rozhled · <a href="https://odvysoke.drymtym.cz" style="color:#185FA5">odvysoke.drymtym.cz</a><br>
    Systém vytvořil © ' . date('Y') . ' Medusoft
  </td></tr></table>
</td></tr></table>
</body></html>';

            $ok = sendMail([$email], '[SVJ Od Vysoké – Rozhled] Nove docasne heslo', $html, [], false);
            $msg = $ok
                ? 'Nové dočasné heslo bylo odesláno na váš e-mail.'
                : 'Nepodařilo se odeslat e-mail. Kontaktujte výbor SVJ.';
            $msgType = $ok ? 'success' : 'error';
        } else {
            // Bezpečnostně neříkáme zda e-mail existuje
            $msg = 'Pokud je e-mail evidován v systému, obdržíte nové heslo.';
            $msgType = 'info';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Zapomenuté heslo – SVJ Od Vysoké – Rozhled</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,sans-serif;background:#f7f6f2;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1rem}
.card{background:#fff;border:1px solid #e0dfd8;border-radius:10px;padding:2rem;max-width:400px;width:100%;box-shadow:0 4px 20px rgba(0,0,0,.08)}
.logo{color:#185FA5;font-weight:700;font-size:16px;margin-bottom:1.5rem;display:flex;align-items:center;gap:8px}
h1{font-size:20px;font-weight:600;margin-bottom:.5rem}
p.sub{font-size:13px;color:#6b6a65;margin-bottom:1.5rem}
label{display:block;font-size:13px;font-weight:500;color:#6b6a65;margin-bottom:4px}
input{width:100%;padding:9px 12px;border:1px solid #e0dfd8;border-radius:6px;font-size:14px;margin-bottom:1rem}
input:focus{outline:none;border-color:#185FA5;box-shadow:0 0 0 3px rgba(24,95,165,.15)}
.btn{width:100%;background:#185FA5;color:#fff;border:none;padding:10px;border-radius:6px;font-size:15px;font-weight:500;cursor:pointer}
.btn:hover{background:#0C447C}
.msg{padding:.75rem 1rem;border-radius:6px;font-size:14px;margin-bottom:1rem}
.msg-success{background:#EAF3DE;color:#3B6D11}
.msg-error{background:#FCEBEB;color:#A32D2D}
.msg-info{background:#E6F1FB;color:#185FA5}
.back{display:block;text-align:center;margin-top:1rem;font-size:13px;color:#6b6a65;text-decoration:none}
.back:hover{color:#185FA5}
::placeholder{color:#b8b5b0;font-style:italic}
</style>
</head>
<body>
<div class="card">
  <div class="logo">
    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
      <path d="M3 21h18M3 7l9-4 9 4M4 7v14M20 7v14M8 11v4m4-4v4m4-4v4"/>
    </svg>
    SVJ Od Vysoké – Rozhled
  </div>
  <h1>Zapomenuté heslo</h1>
  <p class="sub">Zadejte e-mail z kartotéky vlastníků — pošleme vám nové dočasné heslo.</p>

  <?php if ($msg): ?>
    <div class="msg msg-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>

  <?php if (!$msg || $msgType === 'error'): ?>
  <form method="POST">
    <label>E-mailová adresa</label>
    <input type="email" name="email" required placeholder="vas@email.cz" autofocus>
    <button type="submit" class="btn">Odeslat nové heslo</button>
  </form>
  <?php endif; ?>

  <a href="/index.php" class="back">← Zpět na přihlášení</a>
</div>
</body>
</html>
