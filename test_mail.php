<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mailer.php';
requireAdmin();

$result = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $to = trim($_POST['to'] ?? '');
    if ($to) {
        $ok = sendMail([$to], 'Test e-mailu ze SVJ portalu', mailTemplate(
            'Test e-mailu',
            "Tento e-mail byl odeslán jako test z portálu SVJ Od Vysoké.\n\nPokud jste ho obdrželi, odesílání funguje správně."
        ));
        $result = $ok ? '✓ E-mail byl úspěšně odeslán!' : '✗ Odeslání se nezdařilo — zkontrolujte error log serveru.';
    }
}
?>
<!DOCTYPE html>
<html lang="cs">
<head><meta charset="UTF-8"><title>Test mailu</title></head>
<body style="font-family:sans-serif;padding:2rem;max-width:400px">
<h2>Test odesílání e-mailu</h2>
<?php if ($result): ?>
<p style="padding:1rem;border-radius:6px;background:<?= str_starts_with($result,'✓') ? '#EAF3DE' : '#FCEBEB' ?>;color:<?= str_starts_with($result,'✓') ? '#3B6D11' : '#A32D2D' ?>"><?= $result ?></p>
<?php endif; ?>
<form method="POST">
  <label style="display:block;margin-bottom:6px;font-size:14px">Testovací e-mail adresa:</label>
  <input type="email" name="to" required style="width:100%;padding:8px;margin-bottom:10px;border:1px solid #ddd;border-radius:4px;font-size:14px">
  <button type="submit" style="background:#185FA5;color:#fff;border:none;padding:8px 20px;border-radius:4px;font-size:14px;cursor:pointer">Odeslat test</button>
</form>
<p style="margin-top:1rem"><a href="/admin/posts.php">← Zpět</a></p>
</body>
</html>
