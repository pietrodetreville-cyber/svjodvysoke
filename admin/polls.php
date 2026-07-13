<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
$user = requireAdmin();
$pageTitle = 'Ankety';
$db = db();

// Nová anketa
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    csrfCheck();
    $q       = trim($_POST['question'] ?? '');
    $opts    = array_filter(array_map('trim', $_POST['options'] ?? []));
    $closes  = $_POST['closes_at'] ?: null;
    $sendMail= isset($_POST['send_mail']);
    $bcc     = isset($_POST['bcc']);
    $mailTarget  = $_POST['mail_target'] ?? 'all';
    $selectedIds = $_POST['selected_owners'] ?? [];

    if ($q && count($opts) >= 2) {
        $db->prepare('INSERT INTO polls (question,closes_at,created_by) VALUES (?,?,?)')
           ->execute([$q, $closes, $user['id']]);
        $pid = $db->lastInsertId();
        $ins = $db->prepare('INSERT INTO poll_options (poll_id,option_text) VALUES (?,?)');
        foreach ($opts as $opt) $ins->execute([$pid, $opt]);

        flash('Anketa vytvořena.', 'success');

        // Rozeslat e-mail
        if ($sendMail) {
            if ($mailTarget === 'all') {
                $emails = $db->query("SELECT DISTINCT email FROM owners WHERE email IS NOT NULL AND email != '' AND notify_email = 1")->fetchAll(PDO::FETCH_COLUMN);
            } else {
                $in = implode(',', array_map('intval', $selectedIds));
                $emails = $in ? $db->query("SELECT email FROM owners WHERE id IN ($in) AND email IS NOT NULL AND email != '' AND notify_email = 1")->fetchAll(PDO::FETCH_COLUMN) : [];
            }
            if ($emails) {
                $link = 'https://odvysoke.drymtym.cz/owner/polls.php';
                $html = mailTemplatePoll($q, array_values($opts), $closes);
                $ok = sendMail($emails, '[SVJ Od Vysoke] Nova anketa: ' . $q, $html, [], $bcc);
                flash(($ok ? 'E-mail odeslán ' : 'Anketa vytvořena, e-mail se nepodařilo odeslat — ') . count($emails) . ' příjemcům.', $ok ? 'success' : 'warning');
            }
        }
    } else {
        flash('Zadejte otázku a alespoň 2 možnosti.', 'error');
    }
    header('Location: /admin/polls.php'); exit;
}

// Toggle active
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    csrfCheck();
    $db->prepare('UPDATE polls SET active = 1 - active WHERE id=?')->execute([(int)$_POST['id']]);
    header('Location: /admin/polls.php'); exit;
}

// Smazat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrfCheck();
    $db->prepare('DELETE FROM polls WHERE id=?')->execute([(int)$_POST['id']]);
    flash('Anketa smazána.', 'success');
    header('Location: /admin/polls.php'); exit;
}

$polls = $db->query('SELECT * FROM polls ORDER BY created_at DESC')->fetchAll();
$owners = $db->query("SELECT o.id, o.full_name, o.email, u.label FROM owners o JOIN units u ON o.unit_id=u.id WHERE o.email IS NOT NULL AND o.email != '' ORDER BY u.label")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="page-hd"><h1>Ankety</h1></div>

<div class="card" style="max-width:680px;margin-bottom:1.5rem">
  <div class="card-title">Nová anketa</div>
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="action" value="add">

    <div class="form-group">
      <label>Otázka *</label>
      <input type="text" name="question" required placeholder="Jak hlasovat o …?">
    </div>

    <div class="form-group">
      <label>Možnosti (min. 2) *</label>
      <input type="text" name="options[]" placeholder="Možnost 1" style="margin-bottom:6px" required>
      <input type="text" name="options[]" placeholder="Možnost 2" style="margin-bottom:6px" required>
      <input type="text" name="options[]" placeholder="Možnost 3 (volitelná)" style="margin-bottom:6px">
      <input type="text" name="options[]" placeholder="Možnost 4 (volitelná)">
    </div>

    <div class="form-group" style="max-width:220px">
      <label>Uzavřít anketu k datu (volitelné)</label>
      <input type="date" name="closes_at">
    </div>

    <!-- E-mail sekce -->
    <div style="border:1px solid var(--border);border-radius:var(--radius-sm);padding:1rem;margin-bottom:1rem;background:var(--gray-lt)">
      <div class="check-row" style="margin-bottom:.75rem">
        <input type="checkbox" id="send_mail" name="send_mail"
               onchange="document.getElementById('mail-opts').style.display=this.checked?'block':'none'">
        <label for="send_mail" style="font-weight:500;color:var(--text)">📧 Upozornit vlastníky e-mailem</label>
      </div>

      <div id="mail-opts" style="display:none">
        <div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-sm);padding:.75rem;margin-bottom:.75rem">
          <div class="check-row">
            <input type="checkbox" id="bcc" name="bcc" checked>
            <label for="bcc" style="font-size:13px">
              <strong>Skrytá kopie (BCC)</strong> — příjemci se navzájem nevidí
            </label>
          </div>
        </div>

        <div style="display:flex;gap:1rem;margin-bottom:.75rem">
          <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
            <input type="radio" name="mail_target" value="all" checked
                   onchange="document.getElementById('owner-select').style.display='none'">
            Všem vlastníkům (<?= count($owners) ?> e-mailů)
          </label>
          <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
            <input type="radio" name="mail_target" value="selected"
                   onchange="document.getElementById('owner-select').style.display='block'">
            Vybraným vlastníkům
          </label>
        </div>

        <div id="owner-select" style="display:none;max-height:200px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius-sm);background:#fff;padding:.5rem">
          <div style="padding:4px 8px;margin-bottom:4px;display:flex;gap:6px">
            <button type="button" class="btn btn-secondary btn-sm" onclick="toggleAll(true)">Vybrat vše</button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="toggleAll(false)">Zrušit vše</button>
          </div>
          <?php foreach ($owners as $o): ?>
          <label style="display:flex;align-items:center;gap:8px;padding:4px 8px;font-size:13px;cursor:pointer">
            <input type="checkbox" name="selected_owners[]" value="<?= $o['id'] ?>" class="owner-chk">
            <span><strong><?= e($o['label']) ?></strong> – <?= e($o['full_name']) ?>
            <span style="color:var(--muted);font-size:11px">&lt;<?= e($o['email']) ?>&gt;</span></span>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <button type="submit" class="btn btn-primary">Vytvořit anketu</button>
  </form>
</div>

<!-- Seznam anket -->
<?php foreach ($polls as $poll): ?>
<?php
  $opts = $db->prepare('SELECT po.*, COUNT(pv.id) AS votes FROM poll_options po LEFT JOIN poll_votes pv ON pv.option_id=po.id WHERE po.poll_id=? GROUP BY po.id');
  $opts->execute([$poll['id']]);
  $options = $opts->fetchAll();
  $total = array_sum(array_column($options, 'votes'));
?>
<div class="card" style="margin-bottom:1rem;opacity:<?= $poll['active'] ? 1 : 0.65 ?>">
  <div style="display:flex;align-items:flex-start;gap:8px">
    <div style="flex:1">
      <div style="font-size:13px;color:var(--muted);margin-bottom:3px">
        <?= $poll['active'] ? '<span class="badge badge-ok">Aktivní</span>' : '<span class="badge badge-miss">Uzavřená</span>' ?>
        <?php if ($poll['closes_at']): ?>&nbsp;· uzavírá se <?= date('j. n. Y', strtotime($poll['closes_at'])) ?><?php endif; ?>
        &nbsp;· <?= (int)$total ?> hlasů
      </div>
      <div style="font-size:15px;font-weight:600;margin-bottom:10px"><?= e($poll['question']) ?></div>
      <?php foreach ($options as $opt): ?>
        <?php $pct = $total ? round($opt['votes']/$total*100) : 0; ?>
        <div class="poll-row">
          <span style="min-width:160px"><?= e($opt['option_text']) ?></span>
          <div class="poll-bar-wrap"><div class="poll-bar" style="width:<?= $pct ?>%"></div></div>
          <span style="min-width:50px;text-align:right;color:var(--muted)"><?= $pct ?> % (<?= (int)$opt['votes'] ?>)</span>
        </div>
      <?php endforeach; ?>
    </div>
    <div style="display:flex;flex-direction:column;gap:6px;flex-shrink:0">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="toggle">
        <input type="hidden" name="id" value="<?= $poll['id'] ?>">
        <button type="submit" class="btn btn-secondary btn-sm"><?= $poll['active'] ? 'Uzavřít' : 'Znovu otevřít' ?></button>
      </form>
      <form method="POST" onsubmit="return confirm('Smazat anketu?')">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= $poll['id'] ?>">
        <button type="submit" class="btn btn-danger btn-sm">Smazat</button>
      </form>
    </div>
  </div>
</div>
<?php endforeach; ?>

<?php if (!$polls): ?>
<div class="card"><p style="color:var(--muted);font-size:14px">Zatím žádné ankety.</p></div>
<?php endif; ?>

<script>
function toggleAll(state) {
  document.querySelectorAll('.owner-chk').forEach(c => c.checked = state);
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
