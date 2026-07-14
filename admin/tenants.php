<?php
require_once __DIR__ . '/../includes/functions.php';
$user = requireAdmin();
$pageTitle = 'Uživatelé jednotky';
$db = db();
$typLabels = ['najem' => 'Nájem', 'vecne_bremeno' => 'Věcné břemeno'];

// Přidat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    csrfCheck();
    $unitId = (int)$_POST['unit_id'];
    $name   = trim($_POST['full_name'] ?? '');
    $typ    = in_array($_POST['typ'] ?? '', ['najem','vecne_bremeno']) ? $_POST['typ'] : 'najem';
    if ($unitId && $name) {
        $db->prepare(
            'INSERT INTO tenants (unit_id,typ,full_name,email,email_verified,notify_email,phone,whatsapp,rent_from,rent_until,persons_count,note) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $unitId, $typ, $name,
            trim($_POST['email'] ?? '') ?: null,
            isset($_POST['email_verified']) ? 1 : 0,
            isset($_POST['notify_email']) ? 1 : 0,
            trim($_POST['phone'] ?? '') ?: null,
            isset($_POST['whatsapp']) ? 1 : 0,
            $_POST['rent_from'] ?: null,
            $_POST['rent_until'] ?: null,
            !empty($_POST['persons_count']) ? (int)$_POST['persons_count'] : null,
            trim($_POST['note'] ?? '') ?: null,
        ]);
        flash('Uživatel přidán.', 'success');
    }
    header('Location: /admin/tenants.php'); exit;
}

// Upravit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    csrfCheck();
    $typ = in_array($_POST['typ'] ?? '', ['najem','vecne_bremeno']) ? $_POST['typ'] : 'najem';
    $db->prepare(
        'UPDATE tenants SET unit_id=?,typ=?,full_name=?,email=?,email_verified=?,notify_email=?,phone=?,whatsapp=?,rent_from=?,rent_until=?,persons_count=?,note=? WHERE id=?'
    )->execute([
        (int)$_POST['unit_id'], $typ,
        trim($_POST['full_name']),
        trim($_POST['email'] ?? '') ?: null,
        isset($_POST['email_verified']) ? 1 : 0,
        isset($_POST['notify_email']) ? 1 : 0,
        trim($_POST['phone'] ?? '') ?: null,
        isset($_POST['whatsapp']) ? 1 : 0,
        $_POST['rent_from'] ?: null,
        $_POST['rent_until'] ?: null,
        !empty($_POST['persons_count']) ? (int)$_POST['persons_count'] : null,
        trim($_POST['note'] ?? '') ?: null,
        (int)$_POST['id'],
    ]);
    flash('Uživatel upraven.', 'success');
    header('Location: /admin/tenants.php'); exit;
}

// Smazat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrfCheck();
    $db->prepare('DELETE FROM tenants WHERE id=?')->execute([(int)$_POST['id']]);
    flash('Uživatel smazán.', 'success');
    header('Location: /admin/tenants.php'); exit;
}

// Editace?
$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare('SELECT * FROM tenants WHERE id=?');
    $stmt->execute([(int)$_GET['edit']]);
    $editing = $stmt->fetch();
}

$tenants = $db->query(
    'SELECT t.*, u.label AS unit_label, u.type AS unit_type
     FROM tenants t JOIN units u ON t.unit_id=u.id
     ORDER BY u.label, t.full_name'
)->fetchAll();

$units = $db->query("SELECT id, label, type FROM units WHERE type='byt' ORDER BY label")->fetchAll();

$total = count($tenants);

include __DIR__ . '/../includes/header.php';
?>

<p style="font-size:13px;color:var(--muted);margin:0 0 1rem">Nájemníci a osoby s věcným břemenem — jednotka nemusí být ve vlastním užívání vlastníka.</p>

<div class="page-hd" style="justify-content:flex-end">
  <div style="display:flex;gap:8px">
    <button type="button" class="btn btn-primary" onclick="toggleTenantForm()">+ Přidat</button>
  </div>
</div>

<!-- Formulář -->
<style>
.form-row-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem}
@media(max-width:700px){.form-row-3{grid-template-columns:1fr}}
</style>
<div id="tenant-form-panel" style="display:<?= $editing ? 'block' : 'none' ?>;margin-bottom:1.5rem">
<div class="card" style="border-top:4px solid var(--blue)">
  <div style="font-size:14px;font-weight:600;color:var(--blue);margin-bottom:1rem">👤 <?= $editing ? 'Upravit uživatele' : 'Přidat uživatele' ?></div>
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="action" value="<?= $editing ? 'edit' : 'add' ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= $editing['id'] ?>"><?php endif; ?>

    <div class="form-row-3">
      <div class="form-group">
        <label>Bytová jednotka *</label>
        <select name="unit_id" required>
          <option value="">— vyberte byt —</option>
          <?php foreach ($units as $u): ?>
            <option value="<?= $u['id'] ?>" <?= ($editing['unit_id'] ?? 0) == $u['id'] ? 'selected' : '' ?>>
              <?= e($u['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Typ</label>
        <select name="typ">
          <?php foreach ($typLabels as $val => $lbl): ?>
            <option value="<?= $val ?>" <?= ($editing['typ'] ?? 'najem') === $val ? 'selected' : '' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Počet osob</label>
        <input type="number" name="persons_count" min="1" max="20" value="<?= e($editing['persons_count'] ?? '') ?>">
      </div>
    </div>

    <div class="form-row-3">
      <div class="form-group">
        <label>Jméno a příjmení *</label>
        <input type="text" name="full_name" required value="<?= e($editing['full_name'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>E-mail</label>
        <input type="email" name="email" value="<?= e($editing['email'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Telefon</label>
        <input type="tel" name="phone" value="<?= e($editing['phone'] ?? '') ?>">
      </div>
    </div>
    <div style="display:flex;gap:1.25rem;font-size:13px;margin-bottom:1rem">
      <label style="cursor:pointer"><input type="checkbox" name="email_verified" <?= !empty($editing['email_verified']) ? 'checked' : '' ?>> E-mail ověřen</label>
      <label style="cursor:pointer"><input type="checkbox" name="notify_email" <?= !isset($editing['notify_email']) || $editing['notify_email'] ? 'checked' : '' ?>> E-mail pro informace</label>
      <label style="cursor:pointer"><input type="checkbox" name="whatsapp" <?= !empty($editing['whatsapp']) ? 'checked' : '' ?>> WhatsApp</label>
    </div>

    <div class="form-row-3">
      <div class="form-group">
        <label>Platnost od</label>
        <input type="date" name="rent_from" value="<?= e($editing['rent_from'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Platnost do</label>
        <input type="date" name="rent_until" value="<?= e($editing['rent_until'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Poznámka</label>
        <input type="text" name="note" value="<?= e($editing['note'] ?? '') ?>">
      </div>
    </div>

    <div style="display:flex;gap:8px">
      <button type="submit" class="btn btn-primary">Uložit</button>
      <?php if ($editing): ?>
        <a class="btn btn-secondary" href="/admin/tenants.php">Zrušit</a>
      <?php else: ?>
        <button type="button" class="btn btn-secondary" onclick="toggleTenantForm()">Zrušit</button>
      <?php endif; ?>
    </div>
  </form>
</div>
</div>

<!-- Seznam -->
<div class="card" style="border-top:4px solid var(--green)">
  <div style="font-size:14px;font-weight:600;color:var(--green);margin-bottom:1rem">📋 Seznam uživatelů (<?= $total ?>)</div>
  <?php if (!$tenants): ?>
    <p style="color:var(--muted);font-size:14px">Zatím žádní uživatelé.</p>
  <?php else: ?>
  <div class="tbl-wrap">
  <table class="tbl">
    <thead>
      <tr>
        <th>Jednotka</th>
        <th style="text-align:center">Typ</th>
        <th>Jméno</th>
        <th>E-mail</th>
        <th>Telefon</th>
        <th>Osoby</th>
        <th>Nájem od</th>
        <th>Nájem do</th>
        <th>Stav</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($tenants as $t):
      $isActive  = !$t['rent_until'] || strtotime($t['rent_until']) >= time();
      $isExpiring= $t['rent_until'] && strtotime($t['rent_until']) < strtotime('+30 days') && strtotime($t['rent_until']) >= time();
      $daysLeft  = $t['rent_until'] ? ceil((strtotime($t['rent_until']) - time()) / 86400) : null;
    ?>
    <tr>
      <td><strong><?= e($t['unit_label']) ?></strong><br><small style="color:var(--muted)"><?= e($t['unit_type']) ?></small></td>
      <td style="text-align:center"><span class="badge <?= $t['typ']==='vecne_bremeno' ? 'badge-partial' : 'badge-blue' ?>" style="text-align:center;white-space:normal;max-width:90px"><?= e($typLabels[$t['typ']] ?? $t['typ']) ?></span></td>
      <td>
        <?= e($t['full_name']) ?>
        <?php if ($t['note']): ?><br><small style="color:var(--muted)"><?= e($t['note']) ?></small><?php endif; ?>
      </td>
      <td style="font-size:13px"><?= $t['email'] ? '<a href="mailto:'.e($t['email']).'">'.e($t['email']).'</a>'.(!empty($t['email_verified']) ? ' ✓' : '') : '–' ?></td>
      <td style="font-size:13px;white-space:nowrap"><?= e($t['phone'] ?: '–') ?><?= !empty($t['whatsapp']) ? ' 💬' : '' ?></td>
      <td style="text-align:center"><?= $t['persons_count'] ?? '–' ?></td>
      <td style="font-size:13px"><?= $t['rent_from'] ? date('j. n. Y', strtotime($t['rent_from'])) : '–' ?></td>
      <td style="font-size:13px">
        <?php if ($t['rent_until']): ?>
          <?= date('j. n. Y', strtotime($t['rent_until'])) ?>
          <?php if ($daysLeft !== null && $daysLeft <= 30 && $daysLeft >= 0): ?>
            <br><small style="color:var(--amber)">za <?= $daysLeft ?> dní</small>
          <?php elseif ($daysLeft !== null && $daysLeft < 0): ?>
            <br><small style="color:var(--red)">prošlé</small>
          <?php endif; ?>
        <?php else: ?>
          <span style="color:var(--muted)">neurčito</span>
        <?php endif; ?>
      </td>
      <td>
        <?php if (!$isActive): ?>
          <span class="badge badge-miss">Prošlý</span>
        <?php elseif ($isExpiring): ?>
          <span class="badge badge-partial">Končí brzy</span>
        <?php else: ?>
          <span class="badge badge-ok">Aktivní</span>
        <?php endif; ?>
      </td>
      <td style="white-space:nowrap">
        <a class="btn btn-secondary btn-sm" href="?edit=<?= $t['id'] ?>">Upravit</a>
        <form method="POST" style="display:inline" onsubmit="return confirm('Smazat nájemníka?')">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= $t['id'] ?>">
          <button type="submit" class="btn btn-danger btn-sm">Smazat</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<script>
function toggleTenantForm() {
    var panel = document.getElementById('tenant-form-panel');
    panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
