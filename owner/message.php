<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
$user = requireLogin();
if ($user['role'] === 'admin' || $user['role'] === 'superadmin') {
    header('Location: /admin/posts.php'); exit;
}
$pageTitle = 'Zpráva sousedům';
$db = db();

// Načti vlastní info
$myOwner = null;
$myUnit  = null;
if ($user['unit_id']) {
    $stmt = $db->prepare('SELECT o.*, u.label FROM owners o JOIN units u ON o.unit_id=u.id WHERE o.unit_id=? LIMIT 1');
    $stmt->execute([$user['unit_id']]);
    $myOwner = $stmt->fetch();
    $myUnit  = $myOwner['label'] ?? '';
}

// Všichni vlastníci a nájemníci (bez e-mailů)
$residents = $db->query(
    "SELECT 'owner' AS typ, u.id AS unit_id, u.label, o.full_name,
            o.email, o.residence
     FROM owners o JOIN units u ON o.unit_id=u.id
     WHERE u.type='byt' AND (o.email IS NOT NULL AND o.email != '')
     UNION
     SELECT 'tenant' AS typ, u.id AS unit_id, u.label, t.full_name,
            t.email, 'nájemník' AS residence
     FROM tenants t JOIN units u ON t.unit_id=u.id
     WHERE t.email IS NOT NULL AND t.email != ''
     ORDER BY CAST(SUBSTRING_INDEX(label,'/',1) AS UNSIGNED),
              CAST(SUBSTRING_INDEX(label,'/',-1) AS UNSIGNED)"
)->fetchAll();

// Odeslat zprávu
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $subject = trim($_POST['subject'] ?? '');
    $body    = trim($_POST['body'] ?? '');
    $target  = $_POST['target'] ?? 'selected';
    $selected = $_POST['selected_units'] ?? [];

    if (!$subject || !$body) {
        flash('Vyplňte předmět a text zprávy.', 'error');
        header('Location: /owner/message.php'); exit;
    }

    // Sestavit seznam příjemců
    $emails = [];
    if ($target === 'all') {
        foreach ($residents as $r) {
            if ($r['unit_id'] != ($user['unit_id'] ?? 0)) $emails[] = $r['email'];
        }
    } else {
        foreach ($residents as $r) {
            if (in_array($r['unit_id'], $selected) && $r['unit_id'] != ($user['unit_id'] ?? 0)) {
                $emails[] = $r['email'];
            }
        }
    }
    $emails = array_unique(array_filter($emails));

    if (empty($emails)) {
        flash('Vyberte alespoň jednoho příjemce.', 'error');
        header('Location: /owner/message.php'); exit;
    }

    $fromName  = $myOwner['full_name'] ?? $user['username'];
    $fromUnit  = $myUnit ? "byt $myUnit" : '';
    $bodyFull  = "Zpráva od souseda ($fromName, $fromUnit):\n\n$body\n\n---\nTato zpráva byla odeslána přes portál SVJ Od Vysoké. Pro odpověď kontaktujte odesílatele přímo.";

    $senderName = $myOwner['full_name'] ?? $user['username'];
    $senderUnit = $myUnit ? "byt $myUnit" : '';
    $html = mailTemplateSoused($subject, $body, $senderName, $senderUnit);
    $ok = sendMail($emails, '[SVJ Od Vysoké] ' . $subject, $html, [], true);

    flash(($ok ? 'Zpráva odeslána ' : 'Zprávu se nepodařilo odeslat — ') . count($emails) . ' příjemcům.', $ok ? 'success' : 'error');
    header('Location: /owner/message.php'); exit;
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-hd"><h1>✉️ Zpráva sousedům</h1></div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;align-items:start">

<!-- Formulář -->
<div class="card">
  <div class="card-title">Napsat zprávu</div>
  <p style="font-size:13px;color:var(--muted);margin-bottom:1rem">
    Zpráva bude odeslána e-mailem vybraným sousedům. Příjemci uvidí pouze vaše jméno a číslo bytu, ne e-mailové adresy ostatních.
  </p>
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

    <div class="form-group">
      <label>Předmět *</label>
      <input type="text" name="subject" required placeholder="Hluk, parkování, balíček...">
    </div>
    <div class="form-group">
      <label>Text zprávy *</label>
      <textarea name="body" required style="min-height:160px" placeholder="Napište svou zprávu..."></textarea>
    </div>

    <!-- Příjemci -->
    <div style="border:1px solid var(--border);border-radius:var(--radius-sm);padding:1rem;margin-bottom:1rem;background:var(--gray-lt)">
      <div style="display:flex;gap:1rem;margin-bottom:.75rem">
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
          <input type="radio" name="target" value="all" onchange="toggleList(false)">
          Všem sousedům (<?= count($residents) - 1 ?>)
        </label>
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
          <input type="radio" name="target" value="selected" checked onchange="toggleList(true)">
          Vybraným sousedům
        </label>
      </div>

      <div id="unit-list" style="max-height:280px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius-sm);background:#fff">
        <div style="padding:6px 10px;border-bottom:1px solid var(--border);display:flex;gap:6px">
          <button type="button" class="btn btn-secondary btn-sm" onclick="selectAll(true)">Vybrat vše</button>
          <button type="button" class="btn btn-secondary btn-sm" onclick="selectAll(false)">Zrušit vše</button>
        </div>
        <?php foreach ($residents as $r):
          if ($r['unit_id'] == ($user['unit_id'] ?? 0)) continue; // nezobrazuj sebe
        ?>
        <label style="display:flex;align-items:center;gap:10px;padding:8px 12px;font-size:13px;cursor:pointer;border-bottom:1px solid var(--border);<?= $r['typ']==='tenant'?'background:#f0fff4':'' ?>">
          <input type="checkbox" name="selected_units[]" value="<?= $r['unit_id'] ?>" class="unit-chk">
          <span>
            <strong><?= e($r['label']) ?></strong>
            <span style="color:var(--muted)">– <?= e($r['full_name']) ?></span>
            <?php if ($r['typ']==='tenant'): ?><span style="font-size:11px;color:var(--green)"> (nájemník)</span><?php endif; ?>
          </span>
        </label>
        <?php endforeach; ?>
      </div>
    </div>

    <button type="submit" class="btn btn-primary">📨 Odeslat zprávu</button>
  </form>
</div>

<!-- Info panel -->
<div>
  <div class="card" style="margin-bottom:1rem">
    <div class="card-title">ℹ️ Jak to funguje</div>
    <div style="font-size:13px;color:var(--muted);line-height:1.7">
      <p>✓ Příjemci <strong>nevidí e-maily</strong> ostatních sousedů</p>
      <p>✓ V e-mailu uvidí vaše <strong>jméno a číslo bytu</strong></p>
      <p>✓ Každý dostane zprávu <strong>samostatně</strong></p>
      <p>✓ Pro odpověď vám napíší přímo — e-mail výboru není zahrnut</p>
    </div>
  </div>
  <?php if ($myOwner): ?>
  <div class="card" style="background:var(--blue-lt);border-color:#b5d0f0">
    <div style="font-size:13px;color:var(--blue)">
      <strong>Odesílatel:</strong><br>
      <?= e($myOwner['full_name']) ?><br>
      <span style="color:var(--muted)">Byt <?= e($myUnit) ?></span>
    </div>
  </div>
  <?php endif; ?>
</div>

</div>

<script>
function toggleList(show) {
  document.getElementById('unit-list').style.opacity = show ? '1' : '0.4';
  document.querySelectorAll('.unit-chk').forEach(c => c.disabled = !show);
}
function selectAll(state) {
  document.querySelectorAll('.unit-chk').forEach(c => c.checked = state);
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
