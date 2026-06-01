<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
$user = requireAdmin();
$pageTitle = 'Uživatelé';
$db = db();

// Přidat uživatele
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    csrfCheck();
    $uname  = trim($_POST['username'] ?? '');
    $pass   = $_POST['password'] ?? '';
    $role   = $_POST['role'] === 'admin' ? 'admin' : 'owner';
    $unitId = $role === 'owner' && !empty($_POST['unit_id']) ? (int)$_POST['unit_id'] : null;

    if (strlen($uname) < 3) {
        flash('Jméno musí mít alespoň 3 znaky.', 'error');
    } elseif (strlen($pass) < 6) {
        flash('Heslo musí mít alespoň 6 znaků.', 'error');
    } else {
        try {
            $tenantId = $role === 'tenant' && !empty($_POST['tenant_id']) ? (int)$_POST['tenant_id'] : null;
            $db->prepare('INSERT INTO users (username, password_hash, role, unit_id, tenant_id) VALUES (?,?,?,?,?)')
               ->execute([$uname, password_hash($pass, PASSWORD_BCRYPT), $role, $unitId, $tenantId]);
            flash('Účet "' . $uname . '" byl vytvořen.', 'success');

            // Uvítací e-mail
            if (isset($_POST['send_welcome']) && $role === 'owner') {
                $ownerEmail = null;
                $unitLabel  = '';
                if ($unitId) {
                    $stmt = $db->prepare('SELECT email FROM owners WHERE unit_id=? LIMIT 1');
                    $stmt->execute([$unitId]);
                    $ownerEmail = $stmt->fetchColumn();
                    $stmt2 = $db->prepare('SELECT label FROM units WHERE id=? LIMIT 1');
                    $stmt2->execute([$unitId]);
                    $unitLabel = $stmt2->fetchColumn() ?: '';
                }
                if (!$ownerEmail && !empty($_POST['welcome_email'])) {
                    $ownerEmail = trim($_POST['welcome_email']);
                }
                if ($ownerEmail) {
                    $html = mailTemplateWelcome($uname, $pass, $unitLabel);
                    $ok = sendMail([$ownerEmail], '[SVJ Od Vysoke] Vas ucet na portalu', $html, [], false);
                    flash('Účet vytvořen' . ($ok ? ' a uvítací e-mail odeslán na ' . $ownerEmail : ', e-mail se nepodařilo odeslat') . '.', $ok ? 'success' : 'warning');
                }
            }
        } catch (\PDOException $e) {
            flash('Přihlašovací jméno již existuje.', 'error');
        }
    }
    header('Location: /admin/users.php'); exit;
}

// Reset hesla
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset') {
    csrfCheck();
    $newPass = $_POST['new_password'] ?? '';
    if (strlen($newPass) < 6) {
        flash('Heslo musí mít alespoň 6 znaků.', 'error');
    } else {
        $db->prepare('UPDATE users SET password_hash=? WHERE id=?')
           ->execute([password_hash($newPass, PASSWORD_BCRYPT), (int)$_POST['id']]);
        flash('Heslo bylo změněno.', 'success');
    }
    header('Location: /admin/users.php'); exit;
}

// Smazat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrfCheck();
    if ((int)$_POST['id'] !== $user['id']) {
        $db->prepare('DELETE FROM users WHERE id=?')->execute([(int)$_POST['id']]);
        flash('Účet byl smazán.', 'success');
    } else {
        flash('Nemůžete smazat vlastní účet.', 'error');
    }
    header('Location: /admin/users.php'); exit;
}

$users = $db->query(
    'SELECT u.*, un.label AS unit_label
     FROM users u
     LEFT JOIN units un ON u.unit_id = un.id
     ORDER BY u.role DESC, u.username ASC'
)->fetchAll();

$units = $db->query('SELECT id, label, type FROM units ORDER BY label')->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="page-hd"><h1>Správa uživatelů</h1></div>

<!-- Formulář nový účet -->
<div class="card" style="max-width:560px;margin-bottom:1.5rem">
  <div class="card-title">Nový účet</div>
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="action" value="add">
    <div class="form-row">
      <div class="form-group">
        <label>Přihlašovací jméno *</label>
        <input type="text" name="username" required minlength="3" placeholder="novak.jan">
      </div>
      <div class="form-group">
        <label>Heslo * (min. 6 znaků)</label>
        <input type="password" name="password" required minlength="6">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Role</label>
        <select name="role" id="role-sel" onchange="toggleRoleFields(this.value)">
          <option value="owner">Vlastník</option>
          <option value="tenant">Nájemník</option>
          <option value="admin">Výbor (admin)</option>
        </select>
      </div>
      <div class="form-group" id="unit-wrap">
        <label>Přiřadit jednotku</label>
        <select name="unit_id">
          <option value="">— nepřiřazovat —</option>
          <?php foreach ($units as $u): ?>
            <option value="<?= $u['id'] ?>"><?= e($u['label']) ?> (<?= e($u['type']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" id="tenant-wrap" style="display:none">
        <label>Přiřadit nájemníka</label>
        <select name="tenant_id">
          <option value="">— nepřiřazovat —</option>
          <?php
            $tenantList = $db->query("SELECT t.id, t.full_name, u.label FROM tenants t JOIN units u ON t.unit_id=u.id ORDER BY u.label")->fetchAll();
            foreach ($tenantList as $t):
          ?>
            <option value="<?= $t['id'] ?>"><?= e($t['label']) ?> – <?= e($t['full_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <!-- Uvítací e-mail -->
    <div style="border:1px solid var(--border);border-radius:var(--radius-sm);padding:.85rem;margin-bottom:1rem;background:var(--gray-lt)" id="welcome-wrap">
      <div class="check-row" style="margin-bottom:.5rem">
        <input type="checkbox" id="send_welcome" name="send_welcome" checked
               onchange="document.getElementById('welcome-email-row').style.display=this.checked?'block':'none'">
        <label for="send_welcome" style="font-weight:500;color:var(--text)">📧 Odeslat uvítací e-mail s přihlašovacími údaji</label>
      </div>
      <div id="welcome-email-row">
        <div class="form-group" style="margin:0">
          <label style="font-size:12px">E-mail (automaticky z kartotéky, nebo zadejte ručně)</label>
          <input type="email" name="welcome_email" placeholder="Vyplní se z kartotéky, nebo zadejte ručně">
        </div>
      </div>
    </div>
    <button type="submit" class="btn btn-primary">Vytvořit účet</button>
  </form>
</div>

<!-- Seznam účtů -->
<div class="card">
  <div class="card-title">Všechny účty (<?= count($users) ?>)</div>
  <table class="tbl">
    <thead>
      <tr>
        <th>Jméno</th>
        <th>Role</th>
        <th>Jednotka</th>
        <th>Vytvořen</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($users as $u): ?>
    <tr>
      <td><strong><?= e($u['username']) ?></strong></td>
      <td>
        <span class="badge <?= $u['role'] === 'admin' ? 'badge-blue' : '' ?>">
          <?= $u['role'] === 'admin' ? 'Výbor' : 'Vlastník' ?>
        </span>
      </td>
      <td style="font-size:13px;color:var(--muted)"><?= e($u['unit_label'] ?: '–') ?></td>
      <td style="font-size:13px;color:var(--muted)"><?= date('j. n. Y', strtotime($u['created_at'])) ?></td>
      <td style="white-space:nowrap">
        <!-- Změna hesla -->
        <button type="button" class="btn btn-secondary btn-sm"
                onclick="document.getElementById('reset-<?= $u['id'] ?>').style.display=document.getElementById('reset-<?= $u['id'] ?>').style.display==='none'?'block':'none'">
          Heslo
        </button>
        <!-- Smazat -->
        <?php if ($u['id'] !== $user['id']): ?>
        <form method="POST" style="display:inline" onsubmit="return confirm('Smazat účet <?= e($u['username']) ?>?')">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= $u['id'] ?>">
          <button type="submit" class="btn btn-danger btn-sm">Smazat</button>
        </form>
        <?php endif; ?>
        <!-- Reset hesla formulář -->
        <div id="reset-<?= $u['id'] ?>" style="display:none;margin-top:8px">
          <form method="POST" style="display:flex;gap:6px;align-items:center">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="reset">
            <input type="hidden" name="id" value="<?= $u['id'] ?>">
            <input type="password" name="new_password" placeholder="Nové heslo" minlength="6"
                   style="width:150px;font-size:13px;padding:5px 8px;border:1px solid var(--border);border-radius:var(--radius-sm)">
            <button type="submit" class="btn btn-primary btn-sm">Uložit</button>
          </form>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
