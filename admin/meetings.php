<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
$user = requireAdmin();
$pageTitle = 'Shromáždění';
$db = db();

$db->query("UPDATE perrollam SET status='uzavreno' WHERE closes_at < NOW() AND status='aktivni'");

// Nové shromáždění
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    csrfCheck();
    $db->prepare('INSERT INTO meetings (title,meeting_date,meeting_time,location,agenda,quorum_pct,created_by) VALUES (?,?,?,?,?,?,?)')
       ->execute([
           trim($_POST['title']),
           $_POST['meeting_date'],
           $_POST['meeting_time'] ?: null,
           trim($_POST['location']),
           trim($_POST['agenda']),
           (float)($_POST['quorum_pct'] ?: 50),
           $user['id'],
       ]);
    flash('Shromáždění vytvořeno.', 'success');
    header('Location: /admin/meetings.php'); exit;
}

// Upravit shromáždění
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_meeting') {
    csrfCheck();
    $db->prepare('UPDATE meetings SET title=?,meeting_date=?,meeting_time=?,location=?,agenda=?,quorum_pct=? WHERE id=?')
       ->execute([
           trim($_POST['title']),
           $_POST['meeting_date'],
           $_POST['meeting_time'] ?: null,
           trim($_POST['location']),
           trim($_POST['agenda']),
           (float)$_POST['quorum_pct'],
           (int)$_POST['id'],
       ]);
    flash('Shromáždění upraveno.', 'success');
    header('Location: /admin/meetings.php'); exit;
}

// Změna statusu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'status') {
    csrfCheck();
    $db->prepare('UPDATE meetings SET status=? WHERE id=?')->execute([$_POST['status'], (int)$_POST['id']]);
    header('Location: /admin/meetings.php'); exit;
}

// Odeslat pozvánku
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_invitation') {
    csrfCheck();
    $mid = (int)$_POST['id'];
    $mtg = $db->prepare('SELECT * FROM meetings WHERE id=?');
    $mtg->execute([$mid]);
    $mtg = $mtg->fetch();
    if ($mtg) {
        $emails = $db->query("SELECT DISTINCT email FROM owners WHERE email IS NOT NULL AND email != '' AND notify_email = 1")->fetchAll(PDO::FETCH_COLUMN);
        $body = "Vazeni vlastnici,\n\nvybor SVJ Od Vysoké – Rozhled si Vas dovoluje pozvat na shromazdeni vlastniku.\n\n" .
                "Nazev: " . $mtg['title'] . "\n" .
                "Datum: " . date('j. n. Y', strtotime($mtg['meeting_date'])) . ($mtg['meeting_time'] ? ' v '.substr($mtg['meeting_time'],0,5).' hod.' : '') . "\n" .
                "Misto: " . ($mtg['location'] ?: 'viz pozvanka') . "\n\n" .
                "Program:\n" . ($mtg['agenda'] ?: 'viz pozvanka') . "\n\nTesime se na Vasi ucast.\nVybor SVJ Od Vysoké – Rozhled";
        $html = mailTemplate('Pozvanka: ' . $mtg['title'], $body);
        $ok = !empty($emails) ? sendMail($emails, '[SVJ] Pozvánka na shromáždění', $html, [], true) : false;
        $db->prepare('UPDATE meetings SET locked=1, invitation_sent_at=NOW() WHERE id=?')->execute([$mid]);
        flash('Pozvánka odeslána ' . count($emails) . ' vlastníkům. Shromáždění zamčeno.', $ok ? 'success' : 'warning');
    }
    header('Location: /admin/meetings.php'); exit;
}

// Smazat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrfCheck();
    $db->prepare('DELETE FROM meetings WHERE id=?')->execute([(int)$_POST['id']]);
    flash('Shromáždění smazáno.', 'success');
    header('Location: /admin/meetings.php'); exit;
}

$meetings = $db->query(
    'SELECT m.*, u.username AS author,
     (SELECT COUNT(*) FROM meeting_attendance ma WHERE ma.meeting_id=m.id) AS attendance_count
     FROM meetings m JOIN users u ON m.created_by=u.id
     ORDER BY m.meeting_date DESC'
)->fetchAll();

// Editace?
$editMtg = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare('SELECT * FROM meetings WHERE id=?');
    $stmt->execute([(int)$_GET['edit']]);
    $editMtg = $stmt->fetch();
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-hd"><h1>Shromáždění vlastníků</h1></div>

<!-- Nové shromáždění -->
<div class="card" style="max-width:700px;margin-bottom:1.5rem">
  <div class="card-title">Nové shromáždění</div>
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="action" value="add">
    <div class="form-group"><label>Název *</label><input type="text" name="title" required placeholder="Řádné shromáždění vlastníků 2026"></div>
    <div class="form-row">
      <div class="form-group"><label>Datum *</label><input type="date" name="meeting_date" required></div>
      <div class="form-group"><label>Čas</label><input type="time" name="meeting_time"></div>
    </div>
    <div class="form-group"><label>Místo konání</label><input type="text" name="location" placeholder="Suterén domu Od Vysoké 271/10"></div>
    <div class="form-group">
      <label>Program (každý bod na nový řádek)</label>
      <textarea name="agenda" placeholder="1. Zahájení&#10;2. Zpráva výboru&#10;3. Různé"></textarea>
    </div>
    <div class="form-group" style="max-width:200px"><label>Kvórum (%)</label><input type="number" name="quorum_pct" value="50" min="1" max="100" step="0.01"></div>
    <button type="submit" class="btn btn-primary">Vytvořit shromáždění</button>
  </form>
</div>

<!-- Seznam shromáždění -->
<?php foreach ($meetings as $m):
  $statusColor = match($m['status']) { 'probíhá' => '#185FA5', 'ukončeno' => '#3B6D11', default => '#854F0B' };
  $statusBg    = match($m['status']) { 'probíhá' => '#E6F1FB', 'ukončeno' => '#EAF3DE', default => '#FAEEDA' };
  $isLocked    = !empty($m['locked']);
?>
<div class="card" style="margin-bottom:1rem">
  <div style="display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap">
    <div style="flex:1;min-width:200px">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;flex-wrap:wrap">
        <span style="font-size:15px;font-weight:600"><?= e($m['title']) ?></span>
        <span style="font-size:11px;font-weight:600;padding:2px 9px;border-radius:99px;background:<?= $statusBg ?>;color:<?= $statusColor ?>"><?= e($m['status']) ?></span>
        <?php if ($isLocked): ?><span style="font-size:11px;color:var(--green)">🔒 pozvánka odeslána <?= $m['invitation_sent_at'] ? date('j.n.Y', strtotime($m['invitation_sent_at'])) : '' ?></span><?php endif; ?>
      </div>
      <div style="font-size:13px;color:var(--muted)">
        📅 <?= date('j. n. Y', strtotime($m['meeting_date'])) ?><?= $m['meeting_time'] ? ' v '.substr($m['meeting_time'],0,5) : '' ?>
        <?= $m['location'] ? ' &nbsp;·&nbsp; 📍 '.e($m['location']) : '' ?>
      </div>
      <?php if ($m['agenda']): ?>
      <div style="margin-top:6px;font-size:13px;color:var(--muted)">
        <strong style="color:var(--text)">Program:</strong><br><?= nl2br(e($m['agenda'])) ?>
      </div>
      <?php endif; ?>
      <div style="margin-top:6px;font-size:12px;color:var(--muted)">
        Přítomných: <strong><?= $m['attendance_count'] ?></strong> &nbsp;·&nbsp; Kvórum: <strong><?= $m['quorum_pct'] ?> %</strong>
      </div>
    </div>
    <div style="display:flex;flex-direction:column;gap:6px;flex-shrink:0">
      <a class="btn btn-primary btn-sm" href="/admin/meeting_detail.php?id=<?= $m['id'] ?>">Otevřít →</a>
      <a class="btn btn-secondary btn-sm" href="/admin/meetings.php?edit=<?= $m['id'] ?>">✏ Upravit</a>
      <a class="btn btn-secondary btn-sm" href="/admin/meeting_print.php?id=<?= $m['id'] ?>" target="_blank">🖨 PDF</a>
      <a class="btn btn-secondary btn-sm" href="/admin/export_prezence.php?meeting_id=<?= $m['id'] ?>">📋 Prezenčka</a>
      <?php if (!$isLocked): ?>
      <button type="button" class="btn btn-secondary btn-sm"
              onclick="showInv(<?= $m['id'] ?>)">
        📧 Odeslat pozvánku
      </button>
      <?php endif; ?>
      <?php if ($m['status'] !== 'ukončeno'): ?>
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="status">
        <input type="hidden" name="id" value="<?= $m['id'] ?>">
        <input type="hidden" name="status" value="<?= $m['status']==='připravuje se' ? 'probíhá' : 'ukončeno' ?>">
        <button type="submit" class="btn btn-secondary btn-sm"><?= $m['status']==='připravuje se' ? '▶ Zahájit' : '■ Ukončit' ?></button>
      </form>
      <?php endif; ?>
      <form method="POST" onsubmit="return confirm('Smazat shromáždění?')">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= $m['id'] ?>">
        <button type="submit" class="btn btn-danger btn-sm">Smazat</button>
      </form>
    </div>
  </div>
</div>
<?php endforeach; ?>

<?php if (!$meetings): ?>
<div class="card"><p style="color:var(--muted);font-size:14px">Zatím žádné shromáždění.</p></div>
<?php endif; ?>

<!-- Modaly pozvánky -->
<?php foreach ($meetings as $m): if (!empty($m['locked'])) continue; ?>
<div id="inv-<?= $m['id'] ?>" class="inv-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:500;align-items:center;justify-content:center;padding:1rem">
  <div style="background:#fff;border-radius:var(--radius);padding:1.5rem;max-width:620px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3)">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem">
      <h2 style="font-size:17px;font-weight:600">📧 Odeslat pozvánku</h2>
      <button type="button" onclick="hideInv(this)" style="border:none;background:none;font-size:22px;cursor:pointer;color:var(--muted)">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="send_invitation">
      <input type="hidden" name="id" value="<?= $m['id'] ?>">

      <!-- BCC -->
      <div style="background:var(--gray-lt);border-radius:var(--radius-sm);padding:.75rem;margin-bottom:.75rem">
        <div class="check-row">
          <input type="checkbox" name="bcc" id="bcc-<?= $m['id'] ?>" checked>
          <label for="bcc-<?= $m['id'] ?>" style="font-size:13px"><strong>Skrytá kopie (BCC)</strong> — příjemci se navzájem nevidí</label>
        </div>
      </div>

      <!-- Příjemci -->
      <div style="margin-bottom:.75rem">
        <div style="display:flex;gap:1rem;margin-bottom:.5rem">
          <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
            <input type="radio" name="mail_target" value="all" checked
                   onchange="document.getElementById('inv-sel-<?= $m['id'] ?>').style.display='none'">
            Všem vlastníkům
          </label>
          <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
            <input type="radio" name="mail_target" value="selected"
                   onchange="document.getElementById('inv-sel-<?= $m['id'] ?>').style.display='block'">
            Vybraným vlastníkům
          </label>
        </div>
        <div id="inv-sel-<?= $m['id'] ?>" style="display:none;max-height:220px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius-sm);background:#fff;padding:.5rem">
          <div style="padding:4px 8px;margin-bottom:4px;display:flex;gap:6px">
            <button type="button" class="btn btn-secondary btn-sm" onclick="this.closest('form').querySelectorAll('.inv-chk').forEach(c=>c.checked=true)">Vybrat vše</button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="this.closest('form').querySelectorAll('.inv-chk').forEach(c=>c.checked=false)">Zrušit vše</button>
          </div>
          <?php
            $ownersForInv = $db->query("SELECT o.id, o.full_name, o.email, u.label FROM owners o JOIN units u ON o.unit_id=u.id WHERE o.email IS NOT NULL AND o.email != '' ORDER BY CAST(SUBSTRING_INDEX(u.label,'/',1) AS UNSIGNED), CAST(SUBSTRING_INDEX(u.label,'/',-1) AS UNSIGNED)")->fetchAll();
          ?>
          <?php foreach ($ownersForInv as $o): ?>
          <label style="display:flex;align-items:center;gap:8px;padding:4px 8px;font-size:13px;cursor:pointer">
<input type="checkbox" name="selected_owners[]" value="<?= $o['id'] ?>" class="inv-chk" checked style="width:16px;height:16px;accent-color:var(--blue)">
            <span><strong><?= e($o['label']) ?></strong> – <?= e($o['full_name']) ?>
            <span style="color:var(--muted);font-size:11px">&lt;<?= e($o['email']) ?>&gt;</span></span>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div style="background:var(--amber-lt);border-radius:var(--radius-sm);padding:.6rem .75rem;margin-bottom:1rem;font-size:12px;color:var(--amber)">
        ⚠️ Po odeslání pozvánky bude shromáždění <strong>zamčeno</strong> — nelze ho dále editovat.
      </div>

      <div style="display:flex;gap:8px">
        <button type="submit" class="btn btn-primary">📨 Odeslat pozvánku a zamknout</button>
        <button type="button" class="btn btn-secondary" onclick="hideInv(this)">Zrušit</button>
      </div>
    </form>
  </div>
</div>
<?php endforeach; ?>

<!-- Modal pro editaci -->
<?php if ($editMtg): ?>
<div style="position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:500;display:flex;align-items:center;justify-content:center;padding:1rem">
  <div style="background:#fff;border-radius:var(--radius);padding:1.5rem;max-width:680px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3)">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem">
      <h2 style="font-size:18px;font-weight:600">Upravit shromáždění</h2>
      <a href="/admin/meetings.php" style="font-size:22px;color:var(--muted);text-decoration:none;line-height:1">✕</a>
    </div>
    <form method="POST" action="/admin/meetings.php">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="edit_meeting">
      <input type="hidden" name="id" value="<?= $editMtg['id'] ?>">
      <div class="form-group"><label>Název *</label><input type="text" name="title" required value="<?= e($editMtg['title']) ?>"></div>
      <div class="form-row">
        <div class="form-group"><label>Datum *</label><input type="date" name="meeting_date" required value="<?= e($editMtg['meeting_date']) ?>"></div>
        <div class="form-group"><label>Čas</label><input type="time" name="meeting_time" value="<?= e(substr($editMtg['meeting_time'] ?? '',0,5)) ?>"></div>
      </div>
      <div class="form-group"><label>Místo konání</label><input type="text" name="location" value="<?= e($editMtg['location'] ?? '') ?>"></div>
      <div class="form-group">
        <label>Program (každý bod na nový řádek)</label>
        <textarea name="agenda" style="min-height:160px"><?= e($editMtg['agenda'] ?? '') ?></textarea>
      </div>
      <div class="form-group" style="max-width:200px"><label>Kvórum (%)</label><input type="number" name="quorum_pct" value="<?= e($editMtg['quorum_pct']) ?>" min="1" max="100" step="0.01"></div>
      <div style="display:flex;gap:8px">
        <button type="submit" class="btn btn-primary">Uložit změny</button>
        <a href="/admin/meetings.php" class="btn btn-secondary">Zrušit</a>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
function showInv(id) {
    var el = document.getElementById('inv-' + id);
    el.style.display = 'flex';
}
function hideInv(btn) {
    btn.closest('.inv-modal').style.display = 'none';
}
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
