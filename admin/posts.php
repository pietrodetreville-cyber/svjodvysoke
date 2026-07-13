<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
$user = requireAdmin();
$pageTitle = 'Nástěnka';
$db = db();

define('ATTACH_DIR', '/home/html/drymtym.cz/public_html/uploads/documents/');

// Smazat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrfCheck();
    $db->prepare('DELETE FROM posts WHERE id=?')->execute([(int)$_POST['id']]);
    flash('Příspěvek smazán.', 'success');
    header('Location: /admin/posts.php'); exit;
}

// Uložit + odeslat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['add','edit'])) {
    csrfCheck();
    $title     = trim($_POST['title'] ?? '');
    $body      = trim($_POST['body'] ?? '');
    $pinned    = isset($_POST['pinned']) ? 1 : 0;
    $visibility = in_array($_POST['visibility'] ?? '', ['verejny','prihlaseni','skryty']) ? $_POST['visibility'] : 'verejny';
    $sendMail  = isset($_POST['send_mail']);
    $bcc       = isset($_POST['bcc']);
    $mailTarget= $_POST['mail_target'] ?? 'all';
    $selectedIds = $_POST['selected_owners'] ?? [];

    // Příloha
    $attachment = null;
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['attachment'];
        $origName = basename($file['name']);
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $allowed = ['pdf','jpg','jpeg','png','doc','docx'];
        if (in_array($ext, $allowed) && $file['size'] <= 10*1024*1024) {
            $newName = 'mail_' . date('Ymd_His') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $origName);
            if (move_uploaded_file($file['tmp_name'], ATTACH_DIR . $newName)) {
                $attachment = [
                    'path' => ATTACH_DIR . $newName,
                    'name' => $origName,
                    'mime' => $file['type'],
                ];
            }
        }
    }

    if ($title && $body) {
        if ($_POST['action'] === 'edit' && (int)($_POST['id'] ?? 0)) {
            $db->prepare('UPDATE posts SET title=?,body=?,pinned=?,visibility=? WHERE id=?')
               ->execute([$title,$body,$pinned,$visibility,(int)$_POST['id']]);
            flash('Příspěvek upraven.', 'success');
        } else {
            $db->prepare('INSERT INTO posts (title,body,pinned,visibility,author_id) VALUES (?,?,?,?,?)')
               ->execute([$title,$body,$pinned,$visibility,$user['id']]);
            flash('Příspěvek přidán.', 'success');
        }

        if ($sendMail) {
            $emails = [];
            if ($mailTarget === 'all') {
                // Oba emaily - hlavní i druhý
                $rows = $db->query("SELECT email, email2 FROM owners WHERE email IS NOT NULL AND email != ''")->fetchAll();
                $emails = [];
                foreach ($rows as $r) {
                    if ($r['email']) $emails[] = $r['email'];
                    if ($r['email2']) $emails[] = $r['email2'];
                }
                $emails = array_unique(array_filter($emails));
            } else {
                // Skupiny
                if (!empty($_POST['group_all_owners'])) {
                    $e = $db->query("SELECT DISTINCT email FROM owners WHERE email IS NOT NULL AND email != ''")->fetchAll(PDO::FETCH_COLUMN);
                    $emails = array_merge($emails, $e);
                }
                if (!empty($_POST['group_tenants'])) {
                    $e = $db->query("SELECT DISTINCT email FROM tenants WHERE email IS NOT NULL AND email != ''")->fetchAll(PDO::FETCH_COLUMN);
                    $emails = array_merge($emails, $e);
                }
                // Majitelé garáží
                if (!empty($_POST['group_garages'])) {
                    $e = $db->query(
                        "SELECT DISTINCT o.email FROM owners o
                         JOIN units u ON o.unit_id=u.id
                         JOIN units g ON g.linked_unit_id=u.id
                         WHERE g.type != 'byt' AND o.email IS NOT NULL AND o.email != ''"
                    )->fetchAll(PDO::FETCH_COLUMN);
                    $emails = array_merge($emails, $e);
                }

                // Jednotlivci - vlastníci
                $selectedIds = $_POST['selected_owners'] ?? [];
                if ($selectedIds) {
                    $in = implode(',', array_map('intval', $selectedIds));
                    $e = $db->query("SELECT email FROM owners WHERE id IN ($in) AND email IS NOT NULL AND email != ''")->fetchAll(PDO::FETCH_COLUMN);
                    $emails = array_merge($emails, $e);
                }
                // Jednotlivci - nájemníci
                $selectedTenants = $_POST['selected_tenants'] ?? [];
                if ($selectedTenants) {
                    $in = implode(',', array_map('intval', $selectedTenants));
                    $e = $db->query("SELECT email FROM tenants WHERE id IN ($in) AND email IS NOT NULL AND email != ''")->fetchAll(PDO::FETCH_COLUMN);
                    $emails = array_merge($emails, $e);
                }
                $emails = array_unique(array_filter($emails));
            }

            if ($emails) {
                $html = mailTemplate($title, $body);
                $atts = $attachment ? [$attachment] : [];
                $ok = sendMail($emails, '[SVJ Od Vysoké – Rozhled] ' . $title, $html, $atts, $bcc);
                $msg = $ok ? 'E-mail odeslán ' : 'E-mail se nepodařilo odeslat — ';
                $msg .= count($emails) . ' příjemcům';
                $msg .= $bcc ? ' (skrytě — příjemci se navzájem nevidí).' : '.';
                if ($attachment) $msg .= ' Příloha: ' . $origName;
                flash($msg, $ok ? 'success' : 'warning');
            }
        }
    }
    header('Location: /admin/posts.php'); exit;
}

$posts = $db->query(
    'SELECT p.*,u.username AS author FROM posts p JOIN users u ON p.author_id=u.id ORDER BY p.pinned DESC, p.created_at DESC'
)->fetchAll();

$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare('SELECT * FROM posts WHERE id=?');
    $stmt->execute([(int)$_GET['edit']]);
    $editing = $stmt->fetch();
}

$owners = $db->query(
    "SELECT o.id, o.full_name, o.email, u.label FROM owners o JOIN units u ON o.unit_id=u.id WHERE o.email IS NOT NULL AND o.email != '' ORDER BY u.label"
)->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<style>
@media(max-width:900px){.posts-grid{grid-template-columns:1fr!important}}
</style>
<div class="page-hd"><h1>Nástěnka výboru</h1></div>

<div class="card" style="max-width:720px;margin-bottom:1.5rem">
  <div class="card-title"><?= $editing ? 'Upravit příspěvek' : 'Nový příspěvek' ?></div>
  <form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="action" value="<?= $editing ? 'edit' : 'add' ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= $editing['id'] ?>"><?php endif; ?>

    <div class="form-group">
      <label>Nadpis *</label>
      <input type="text" name="title" required value="<?= e($editing['title'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label>Text příspěvku *</label>
      <textarea name="body" style="min-height:140px" required><?= e($editing['body'] ?? '') ?></textarea>
    </div>

    <div style="display:flex;gap:1.5rem;align-items:center;margin-bottom:1rem;flex-wrap:wrap">
      <div class="check-row">
        <input type="checkbox" id="pinned" name="pinned" <?= !empty($editing['pinned']) ? 'checked' : '' ?>>
        <label for="pinned">Připnout nahoře</label>
      </div>
      <div style="display:flex;align-items:center;gap:8px">
        <label style="font-size:13px;font-weight:500;color:var(--muted)">Viditelnost:</label>
        <select name="visibility" style="font-size:13px;padding:5px 10px;border:1px solid var(--border);border-radius:var(--radius-sm)">
          <option value="verejny" <?= ($editing['visibility'] ?? 'verejny')==='verejny' ? 'selected' : '' ?>>🌐 Veřejný</option>
          <option value="prihlaseni" <?= ($editing['visibility'] ?? '')==='prihlaseni' ? 'selected' : '' ?>>🔒 Jen přihlášení</option>
          <option value="skryty" <?= ($editing['visibility'] ?? '')==='skryty' ? 'selected' : '' ?>>👁 Skrytý (jen výbor)</option>
        </select>
      </div>
    </div>

    <!-- E-mail sekce -->
    <div style="border:1px solid var(--border);border-radius:var(--radius-sm);padding:1rem;margin-bottom:1rem;background:var(--gray-lt)">
      <div class="check-row" style="margin-bottom:.75rem">
        <input type="checkbox" id="send_mail" name="send_mail"
               onchange="document.getElementById('mail-opts').style.display=this.checked?'block':'none'">
        <label for="send_mail" style="font-weight:500;color:var(--text)">📧 Rozeslat e-mailem vlastníkům</label>
      </div>

      <div id="mail-opts" style="display:none">

        <!-- BCC přepínač -->
        <div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-sm);padding:.75rem;margin-bottom:.75rem">
          <div class="check-row">
            <input type="checkbox" id="bcc" name="bcc" checked>
            <label for="bcc" style="font-size:13px">
              <strong>Skrytá kopie (BCC)</strong> — příjemci se navzájem nevidí
              <span style="color:var(--muted);font-size:11px;display:block">Doporučeno pro ochranu soukromí vlastníků (GDPR)</span>
            </label>
          </div>
        </div>

        <!-- Příloha -->
        <div class="form-group" style="margin-bottom:.75rem">
          <label>Příloha (PDF, JPG, PNG, DOC — max. 10 MB)</label>
          <input type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                 style="font-size:13px">
        </div>

        <!-- Příjemci -->
        <div style="display:flex;gap:1rem;margin-bottom:.75rem;flex-wrap:wrap">
          <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
            <input type="radio" name="mail_target" value="all" checked
                   onchange="document.getElementById('owner-select').style.display='none'">
            Všem vlastníkům (<?= count($owners) ?>)
          </label>
          <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
            <input type="radio" name="mail_target" value="selected"
                   onchange="document.getElementById('owner-select').style.display='block'">
            Výběr skupin / jednotlivců
          </label>
        </div>

        <!-- Skupina příjemců -->
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:.75rem">
          <label style="display:flex;align-items:center;gap:5px;font-size:13px;cursor:pointer;background:#fff;border:1px solid var(--border);padding:5px 12px;border-radius:99px">
            <input type="checkbox" name="group_owners" id="g-owners" onchange="rebuildList()"> 👤 Vlastníci (trvalý pobyt)
          </label>
          <label style="display:flex;align-items:center;gap:5px;font-size:13px;cursor:pointer;background:#fff;border:1px solid var(--border);padding:5px 12px;border-radius:99px">
            <input type="checkbox" name="group_all_owners" id="g-all" onchange="rebuildList()"> 👥 Všichni vlastníci
          </label>
          <label style="display:flex;align-items:center;gap:5px;font-size:13px;cursor:pointer;background:#fff;border:1px solid var(--border);padding:5px 12px;border-radius:99px">
            <input type="checkbox" name="group_tenants" id="g-tenants" onchange="rebuildList()"> 🏠 Nájemníci
          </label>
          <label style="display:flex;align-items:center;gap:5px;font-size:13px;cursor:pointer;background:#fff;border:1px solid var(--border);padding:5px 12px;border-radius:99px">
            <input type="checkbox" name="group_garages" id="g-garages" onchange="rebuildList()"> 🚗 Majitelé garáží
          </label>
        </div>

        <div id="owner-select" style="display:none;max-height:220px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius-sm);background:#fff;padding:.5rem">
          <div style="padding:4px 8px;margin-bottom:4px;display:flex;gap:6px">
            <button type="button" class="btn btn-secondary btn-sm" onclick="toggleAll(true)">Vybrat vše</button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="toggleAll(false)">Zrušit vše</button>
          </div>
          <?php foreach ($owners as $o): ?>
          <label style="display:flex;align-items:center;gap:8px;padding:4px 8px;font-size:13px;cursor:pointer" class="owner-row" data-residence="<?= e($o['email'] ? 'has-email' : '') ?>">
            <input type="checkbox" name="selected_owners[]" value="<?= $o['id'] ?>" class="owner-chk">
            <span>
              <strong><?= e($o['label']) ?></strong> – <?= e($o['full_name']) ?>
              <span style="color:var(--muted);font-size:11px">&lt;<?= e($o['email']) ?>&gt;</span>
            </span>
          </label>
          <?php endforeach; ?>
          <?php
            $tenantList = $db->query("SELECT t.id, t.full_name, t.email, u.label FROM tenants t JOIN units u ON t.unit_id=u.id WHERE t.email IS NOT NULL AND t.email != '' ORDER BY u.label")->fetchAll();
          ?>
          <?php foreach ($tenantList as $t): ?>
          <label style="display:flex;align-items:center;gap:8px;padding:4px 8px;font-size:13px;cursor:pointer;background:#f0fff4" class="tenant-row">
            <input type="checkbox" name="selected_tenants[]" value="<?= $t['id'] ?>" class="tenant-chk">
            <span>
              🏠 <strong><?= e($t['label']) ?></strong> – <?= e($t['full_name']) ?>
              <span style="color:var(--muted);font-size:11px">&lt;<?= e($t['email']) ?>&gt;</span>
            </span>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div style="display:flex;gap:8px">
      <button type="submit" class="btn btn-primary">Uložit</button>
      <?php if ($editing): ?><a class="btn btn-secondary" href="/admin/posts.php">Zrušit</a><?php endif; ?>
    </div>
  </form>
</div>

<!-- Seznam příspěvků -->
<div class="card">
  <div class="card-title">Všechny příspěvky</div>
  <?php if (!$posts): ?>
    <p style="color:var(--muted);font-size:14px">Zatím žádné příspěvky.</p>
  <?php else: foreach ($posts as $p): ?>
    <div class="post-item">
      <div style="display:flex;align-items:flex-start;gap:8px">
        <div style="flex:1">
          <?php if ($p['pinned']): ?><span class="badge badge-blue" style="margin-bottom:4px">Připnutý</span><?php endif; ?>
          <?php
            $visLabel = match($p['visibility'] ?? 'verejny') {
              'prihlaseni' => ['🔒 Jen přihlášení', 'badge-partial'],
              'skryty'     => ['👁 Skrytý', 'badge-miss'],
              default      => ['🌐 Veřejný', 'badge-ok'],
            };
          ?>
          <span class="badge <?= $visLabel[1] ?>" style="margin-bottom:4px"><?= $visLabel[0] ?></span><br>
          <div class="post-meta"><?= date('j. n. Y H:i', strtotime($p['created_at'])) ?> · <?= e($p['author']) ?></div>
          <div class="post-title"><?= e($p['title']) ?></div>
          <div class="post-body"><?= nl2br(e($p['body'])) ?></div>
        </div>
        <div style="display:flex;gap:6px;flex-shrink:0">
          <a class="btn btn-secondary btn-sm" href="?edit=<?= $p['id'] ?>">Upravit</a>
          <form method="POST" style="display:inline" onsubmit="return confirm('Smazat?')">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $p['id'] ?>">
            <button type="submit" class="btn btn-danger btn-sm">Smazat</button>
          </form>
        </div>
      </div>
    </div>
  <?php endforeach; endif; ?>
</div>

<script>
function toggleAll(state) {
  document.querySelectorAll('.owner-chk, .tenant-chk').forEach(c => c.checked = state);
}
function rebuildList() {
  const gAll     = document.getElementById('g-all').checked;
  const gTenants  = document.getElementById('g-tenants').checked;
  const gGarages  = document.getElementById('g-garages').checked;

  // Zaškrtni/odškrtni vlastníky
  document.querySelectorAll('.owner-chk').forEach(c => { if (gAll) c.checked = true; });
  // Zaškrtni/odškrtni nájemníky
  document.querySelectorAll('.tenant-chk').forEach(c => { if (gTenants) c.checked = true; });

  // Zobraz seznam pokud je vybrána skupina nebo "vybraným"
  const showList = gAll || gTenants || gGarages ||
    document.querySelector('input[name="mail_target"][value="selected"]').checked;
  document.getElementById('owner-select').style.display = showList ? 'block' : 'none';

  // Přepni radio na "selected"
  if (gAll || gTenants || gGarages) {
    document.querySelector('input[name="mail_target"][value="selected"]').checked = true;
  }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?><div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;align-items:start" class="posts-grid">
<div>

</div>
<div>
<!-- Seznam příspěvků -->
<div class="card">
  <div class="card-title">Všechny příspěvky</div>
  <?php if (!$posts): ?>
    <p style="color:var(--muted);font-size:14px">Zatím žádné příspěvky.</p>
  <?php else: foreach ($posts as $p): ?>
    <div class="post-item">
      <div style="display:flex;align-items:flex-start;gap:8px">
        <div style="flex:1">
          <?php if ($p['pinned']): ?><span class="badge badge-blue" style="margin-bottom:4px">Připnutý</span><?php endif; ?>
          <?php
            $visLabel = match($p['visibility'] ?? 'verejny') {
              'prihlaseni' => ['🔒 Jen přihlášení', 'badge-partial'],
              'skryty'     => ['👁 Skrytý', 'badge-miss'],
              default      => ['🌐 Veřejný', 'badge-ok'],
            };
          ?>
          <span class="badge <?= $visLabel[1] ?>" style="margin-bottom:4px"><?= $visLabel[0] ?></span><br>
          <div class="post-meta"><?= date('j. n. Y H:i', strtotime($p['created_at'])) ?> · <?= e($p['author']) ?></div>
          <div class="post-title"><?= e($p['title']) ?></div>
          <div class="post-body"><?= nl2br(e($p['body'])) ?></div>
        </div>
        <div style="display:flex;gap:6px;flex-shrink:0">
          <a class="btn btn-secondary btn-sm" href="?edit=<?= $p['id'] ?>">Upravit</a>
          <form method="POST" style="display:inline" onsubmit="return confirm('Smazat?')">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $p['id'] ?>">
            <button type="submit" class="btn btn-danger btn-sm">Smazat</button>
          </form>
        </div>
      </div>
    </div>
  <?php endforeach; endif; ?>
</div>
</div>
</div>

<script>
function toggleAll(state) {
  document.querySelectorAll('.owner-chk, .tenant-chk').forEach(c => c.checked = state);
}
function rebuildList() {
  const gAll     = document.getElementById('g-all').checked;
  const gTenants  = document.getElementById('g-tenants').checked;
  const gGarages  = document.getElementById('g-garages').checked;

  // Zaškrtni/odškrtni vlastníky
  document.querySelectorAll('.owner-chk').forEach(c => { if (gAll) c.checked = true; });
  // Zaškrtni/odškrtni nájemníky
  document.querySelectorAll('.tenant-chk').forEach(c => { if (gTenants) c.checked = true; });

  // Zobraz seznam pokud je vybrána skupina nebo "vybraným"
  const showList = gAll || gTenants || gGarages ||
    document.querySelector('input[name="mail_target"][value="selected"]').checked;
  document.getElementById('owner-select').style.display = showList ? 'block' : 'none';

  // Přepni radio na "selected"
  if (gAll || gTenants || gGarages) {
    document.querySelector('input[name="mail_target"][value="selected"]').checked = true;
  }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
