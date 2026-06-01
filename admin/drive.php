<?php
require_once __DIR__ . '/../includes/functions.php';
$user = requireAdmin();
$isSuperAdmin = ($user['role'] === 'superadmin');
$pageTitle = 'Google Drive';
$db = db();

// Pouze superadmin může editovat
if (in_array($_POST['action'] ?? '', ['add','edit','delete']) && !$isSuperAdmin) {
    flash('Nemáte oprávnění.', 'error');
    header('Location: /admin/drive.php'); exit;
}

// Přidat odkaz
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    csrfCheck();
    $db->prepare('INSERT INTO drive_links (title,url,description,icon,visible_to_owners,order_num) VALUES (?,?,?,?,?,?)')
       ->execute([
           trim($_POST['title']),
           trim($_POST['url']),
           trim($_POST['description']) ?: null,
           trim($_POST['icon']) ?: '📁',
           isset($_POST['visible_to_owners']) ? 1 : 0,
           (int)($_POST['order_num'] ?? 0),
       ]);
    flash('Odkaz přidán.', 'success');
    header('Location: /admin/drive.php'); exit;
}

// Upravit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    csrfCheck();
    $db->prepare('UPDATE drive_links SET title=?,url=?,description=?,icon=?,visible_to_owners=?,order_num=? WHERE id=?')
       ->execute([
           trim($_POST['title']),
           trim($_POST['url']),
           trim($_POST['description']) ?: null,
           trim($_POST['icon']) ?: '📁',
           isset($_POST['visible_to_owners']) ? 1 : 0,
           (int)($_POST['order_num'] ?? 0),
           (int)$_POST['id'],
       ]);
    flash('Odkaz upraven.', 'success');
    header('Location: /admin/drive.php'); exit;
}

// Smazat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrfCheck();
    $db->prepare('DELETE FROM drive_links WHERE id=?')->execute([(int)$_POST['id']]);
    flash('Odkaz smazán.', 'success');
    header('Location: /admin/drive.php'); exit;
}

$editing = null;
if (isset($_GET['edit'])) {
    $s = $db->prepare('SELECT * FROM drive_links WHERE id=?');
    $s->execute([(int)$_GET['edit']]);
    $editing = $s->fetch();
}

$links = $db->query('SELECT * FROM drive_links ORDER BY order_num, title')->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="page-hd"><h1>🔗 Google Drive – Správa odkazů</h1></div>

<div style="display:grid;grid-template-columns:<?= $isSuperAdmin ? '1fr 1fr' : '1fr' ?>;gap:1.25rem;align-items:start">

<?php if ($isSuperAdmin): ?>
<!-- Formulář pouze pro superadmin -->
<div class="card">
  <div class="card-title"><?= $editing ? 'Upravit odkaz' : 'Přidat odkaz na Drive' ?></div>
  <p style="font-size:13px;color:var(--muted);margin-bottom:1rem">
    V Google Drive klikněte pravým na složku → <strong>Sdílet</strong> → <strong>Získat odkaz</strong> → zkopírujte URL.
  </p>
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="action" value="<?= $editing ? 'edit' : 'add' ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= $editing['id'] ?>"><?php endif; ?>

    <div class="form-row">
      <div class="form-group">
        <label>Název složky *</label>
        <input type="text" name="title" required placeholder="Faktury 2026" value="<?= e($editing['title'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Ikona</label>
        <input type="text" name="icon" placeholder="📁" maxlength="5" value="<?= e($editing['icon'] ?? '📁') ?>"
               style="font-size:20px;text-align:center">
      </div>
    </div>

    <div class="form-group">
      <label>Google Drive URL *</label>
      <input type="url" name="url" required placeholder="https://drive.google.com/drive/folders/..."
             value="<?= e($editing['url'] ?? '') ?>">
    </div>

    <div class="form-group">
      <label>Popis (volitelný)</label>
      <input type="text" name="description" placeholder="Faktury za služby a dodavatele"
             value="<?= e($editing['description'] ?? '') ?>">
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Pořadí</label>
        <input type="number" name="order_num" value="<?= e($editing['order_num'] ?? 0) ?>" min="0">
      </div>
      <div class="form-group" style="display:flex;align-items:flex-end;padding-bottom:1rem">
        <div class="check-row">
          <input type="checkbox" id="visible" name="visible_to_owners" <?= !empty($editing['visible_to_owners']) ? 'checked' : '' ?>>
          <label for="visible">Zobrazit vlastníkům</label>
        </div>
      </div>
    </div>

    <div style="display:flex;gap:8px">
      <button type="submit" class="btn btn-primary">Uložit</button>
      <?php if ($editing): ?><a class="btn btn-secondary" href="/admin/drive.php">Zrušit</a><?php endif; ?>
    </div>
  </form>
</div>

<?php endif; ?>
<!-- Seznam odkazů -->
<div>
  <?php if (!$links): ?>
    <div class="card">
      <p style="color:var(--muted);font-size:14px">Zatím žádné odkazy. Přidejte první složku z Google Drive.</p>
    </div>
  <?php else: ?>
  <div class="card">
    <div class="card-title">Přidané složky (<?= count($links) ?>)</div>
    <?php foreach ($links as $l): ?>
    <div style="display:flex;align-items:center;gap:12px;padding:.75rem 0;border-bottom:1px solid var(--border)">
      <div style="font-size:28px;flex-shrink:0"><?= e($l['icon']) ?></div>
      <div style="flex:1;min-width:0">
        <div style="font-weight:600"><?= e($l['title']) ?></div>
        <?php if ($l['description']): ?>
          <div style="font-size:12px;color:var(--muted)"><?= e($l['description']) ?></div>
        <?php endif; ?>
        <div style="font-size:11px;color:var(--muted);margin-top:2px">
          <?= $l['visible_to_owners'] ? '<span class="badge badge-ok" style="font-size:10px">Viditelné vlastníkům</span>' : '<span class="badge badge-miss" style="font-size:10px">Jen výbor</span>' ?>
        </div>
      </div>
      <div style="display:flex;gap:4px;flex-shrink:0">
        <a class="btn btn-primary btn-sm" href="<?= e($l['url']) ?>" target="_blank">🔗 Otevřít</a>
        <?php if ($isSuperAdmin): ?>
        <a class="btn btn-secondary btn-sm" href="?edit=<?= $l['id'] ?>">Upravit</a>
        <form method="POST" style="display:inline" onsubmit="return confirm('Smazat?')">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= $l['id'] ?>">
          <button type="submit" class="btn btn-danger btn-sm">✕</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
