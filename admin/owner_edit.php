<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
$user = requireAdmin();
$db = db();

$id = (int)($_GET['id'] ?? 0);
$owner = null;
if ($id) {
    $stmt = $db->prepare('SELECT o.*, u.label AS unit_label FROM owners o JOIN units u ON o.unit_id=u.id WHERE o.id=?');
    $stmt->execute([$id]);
    $owner = $stmt->fetch();
}
$pageTitle = $owner ? 'Upravit kartu' : 'Přidat vlastníka';

$units = $db->query('SELECT * FROM units ORDER BY label')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? 'save_owner') === 'save_owner') {
    csrfCheck();
    $data = [
        'unit_id'      => (int)$_POST['unit_id'],
        'full_name'    => trim($_POST['full_name'] ?? ''),
        'email'        => trim($_POST['email'] ?? ''),
        'phone'        => trim($_POST['phone'] ?? ''),
        'address'      => trim($_POST['address'] ?? ''),
        'residence'      => $_POST['residence'] ?? 'neuvedeno',
        'ownership_form' => $_POST['ownership_form'] ?? 'neuvedeno',
        'persons_count'  => !empty($_POST['persons_count']) ? (int)$_POST['persons_count'] : null,
        'email2'         => trim($_POST['email2'] ?? '') ?: null,
        'phone2'         => trim($_POST['phone2'] ?? '') ?: null,
        'primary_email'  => (int)($_POST['primary_email'] ?? 1),
        'primary_phone'  => (int)($_POST['primary_phone'] ?? 1),
        'note'         => trim($_POST['note'] ?? ''),
        'gdpr_consent' => isset($_POST['gdpr_consent']) ? 1 : 0,
        'gdpr_date'    => isset($_POST['gdpr_consent']) ? date('Y-m-d H:i:s') : null,
    ];
    $data['status'] = ownerStatus($data);

    // Hierarchie: výbor nemůže přepsat kartu vyplněnou vlastníkem
    if ($owner && ($owner['updated_by_role'] ?? '') === 'owner' && $user['role'] === 'admin') {
        flash('Tuto kartu vyplnil vlastník — nemáte oprávnění ji měnit. Kontaktujte superadmina.', 'error');
        header('Location: /admin/owners.php'); exit;
    }

    if ($owner) {
        $db->prepare(
            'UPDATE owners SET unit_id=?,full_name=?,email=?,phone=?,address=?,residence=?,ownership_form=?,note=?,gdpr_consent=?,gdpr_date=?,status=?,updated_by_role=?,persons_count=?,email2=?,phone2=?,primary_email=?,primary_phone=? WHERE id=?'
        )->execute([
            $data['unit_id'], $data['full_name'], $data['email'], $data['phone'],
            $data['address'], $data['residence'], $data['ownership_form'], $data['note'], $data['gdpr_consent'],
            $data['gdpr_date'], $data['status'],
            $user['role'], $data['persons_count'],
            $data['email2'], $data['phone2'], $data['primary_email'], $data['primary_phone'],
            $owner['id']
        ]);
        flash('Karta uložena.', 'success');
        header('Location: /admin/owner_edit.php?id=' . $owner['id']); exit;
    } else {
        $db->prepare(
            'INSERT INTO owners (unit_id,full_name,email,phone,address,residence,ownership_form,note,gdpr_consent,gdpr_date,status,updated_by_role,persons_count,email2,phone2,primary_email,primary_phone) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $data['unit_id'], $data['full_name'], $data['email'], $data['phone'],
            $data['address'], $data['residence'], $data['ownership_form'], $data['note'], $data['gdpr_consent'],
            $data['gdpr_date'], $data['status'],
            $user['role'], $data['persons_count'],
            $data['email2'], $data['phone2'], $data['primary_email'], $data['primary_phone']
        ]);
        flash('Vlastník přidán.', 'success');
        header('Location: /admin/owners.php'); exit;
    }
}

// Další vlastníci (owner_persons) — jen když karta už existuje
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_person' && $owner) {
    csrfCheck();
    $pid = (int)($_POST['person_id'] ?? 0);
    $pdata = [
        trim($_POST['p_full_name'] ?? ''),
        trim($_POST['p_email'] ?? '') ?: null,
        trim($_POST['p_phone'] ?? '') ?: null,
        trim($_POST['p_relation'] ?? '') ?: null,
        trim($_POST['p_address'] ?? '') ?: null,
        trim($_POST['p_note'] ?? '') ?: null,
    ];
    if ($pdata[0] !== '') {
        if ($pid) {
            $db->prepare('UPDATE owner_persons SET full_name=?,email=?,phone=?,relation=?,address=?,note=? WHERE id=? AND owner_id=?')
               ->execute([...$pdata, $pid, $owner['id']]);
        } else {
            $db->prepare('INSERT INTO owner_persons (owner_id,full_name,email,phone,relation,address,note) VALUES (?,?,?,?,?,?,?)')
               ->execute([$owner['id'], ...$pdata]);
        }
        flash('Další vlastník uložen.', 'success');
    }
    header('Location: /admin/owner_edit.php?id=' . $owner['id'] . '#dalsi-vlastnici'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_person' && $owner) {
    csrfCheck();
    $db->prepare('DELETE FROM owner_persons WHERE id=? AND owner_id=?')->execute([(int)$_POST['person_id'], $owner['id']]);
    flash('Další vlastník smazán.', 'success');
    header('Location: /admin/owner_edit.php?id=' . $owner['id'] . '#dalsi-vlastnici'); exit;
}

$ownerPersons = [];
$editPerson = null;
if ($owner) {
    $stmt = $db->prepare('SELECT * FROM owner_persons WHERE owner_id=? ORDER BY id');
    $stmt->execute([$owner['id']]);
    $ownerPersons = $stmt->fetchAll();
    if (isset($_GET['edit_person'])) {
        foreach ($ownerPersons as $pp) if ((int)$pp['id'] === (int)$_GET['edit_person']) { $editPerson = $pp; break; }
    }
}

$ownershipLabels = ['bezpodílové' => 'Jednoduché (jeden vlastník)', 'společné jmění manželů' => 'SJM (manželé)', 'podílové' => 'Podílové (více vlastníků)', 'neuvedeno' => 'Neuvedeno'];

include __DIR__ . '/../includes/header.php';
$o = $owner ?? [];
?>

<div class="page-hd">
  <h1><?= $owner ? 'Upravit kartu vlastníka' : 'Přidat vlastníka' ?></h1>
  <a class="btn btn-secondary" href="/admin/owners.php">← Zpět</a>
</div>

<div class="card" style="max-width:640px">
<form method="POST">
  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
  <input type="hidden" name="action" value="save_owner">

  <div class="form-group">
    <label>Jednotka *</label>
    <select name="unit_id" required>
      <option value="">— vyberte —</option>
      <?php foreach ($units as $u): ?>
        <option value="<?= $u['id'] ?>" <?= ($o['unit_id'] ?? 0) == $u['id'] ? 'selected' : '' ?>>
          <?= e($u['label']) ?> (<?= e($u['type']) ?>)
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="form-row">
    <div class="form-group">
      <label>Vlastnictví</label>
      <select name="ownership_form">
        <?php foreach ($ownershipLabels as $val => $lbl): ?>
          <option value="<?= e($val) ?>" <?= ($o['ownership_form'] ?? 'neuvedeno') === $val ? 'selected' : '' ?>><?= e($lbl) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label>Způsob užívání</label>
      <select name="residence">
        <?php foreach (['vlastní','pronájem','věcné břemeno','neuvedeno'] as $opt): ?>
          <option value="<?= $opt ?>" <?= ($o['residence'] ?? '') === $opt ? 'selected' : '' ?>><?= $opt ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="form-row">
    <div class="form-group">
      <label>Jméno a příjmení (hlavní vlastník) *</label>
      <input type="text" name="full_name" required value="<?= e($o['full_name'] ?? '') ?>">
    </div>
    <div class="form-group"></div>
  </div>

  <div class="form-row">
    <div class="form-group">
      <label>Počet osob v jednotce</label>
      <input type="number" name="persons_count" min="0" max="20" placeholder="0"
             value="<?= e($o['persons_count'] ?? '') ?>">
    </div>
    <div class="form-group"></div>
  </div>
  <!-- Kontakty -->
  <div style="border:1px solid var(--border);border-radius:var(--radius-sm);padding:1rem;margin-bottom:1rem;background:var(--gray-lt)">
    <div style="font-size:13px;font-weight:600;color:var(--muted);margin-bottom:.75rem">Kontaktní údaje</div>
    <div class="form-row">
      <div class="form-group">
        <label>E-mail 1</label>
        <input type="email" name="email" value="<?= e($o['email'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>E-mail 2</label>
        <input type="email" name="email2" value="<?= e($o['email2'] ?? '') ?>">
      </div>
    </div>
    <div style="margin-bottom:.75rem;font-size:13px">
      <label style="font-weight:500;color:var(--muted);margin-right:.75rem">Hlavní e-mail:</label>
      <label style="cursor:pointer;margin-right:1rem"><input type="radio" name="primary_email" value="1" <?= ($o['primary_email'] ?? 1) == 1 ? 'checked' : '' ?>> E-mail 1</label>
      <label style="cursor:pointer"><input type="radio" name="primary_email" value="2" <?= ($o['primary_email'] ?? 1) == 2 ? 'checked' : '' ?>> E-mail 2</label>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Telefon 1</label>
        <input type="tel" name="phone" value="<?= e($o['phone'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Telefon 2</label>
        <input type="tel" name="phone2" value="<?= e($o['phone2'] ?? '') ?>">
      </div>
    </div>
    <div style="font-size:13px">
      <label style="font-weight:500;color:var(--muted);margin-right:.75rem">Hlavní telefon:</label>
      <label style="cursor:pointer;margin-right:1rem"><input type="radio" name="primary_phone" value="1" <?= ($o['primary_phone'] ?? 1) == 1 ? 'checked' : '' ?>> Telefon 1</label>
      <label style="cursor:pointer"><input type="radio" name="primary_phone" value="2" <?= ($o['primary_phone'] ?? 1) == 2 ? 'checked' : '' ?>> Telefon 2</label>
    </div>
  </div>

  <div class="form-group">
    <label>Korespondenční adresa (pokud se liší od jednotky)</label>
    <input type="text" name="address" value="<?= e($o['address'] ?? '') ?>">
  </div>

  <div class="form-group">
    <label>Interní poznámka (jen pro výbor)</label>
    <textarea name="note"><?= e($o['note'] ?? '') ?></textarea>
  </div>


  <?php if ($owner && ($owner['updated_by_role'] ?? '') === 'owner' && $user['role'] === 'admin'): ?>
  <div style="background:var(--amber-lt);border:1px solid #FAC775;border-radius:var(--radius-sm);padding:.75rem 1rem;margin-bottom:1rem;font-size:13px;color:var(--amber)">
    ⚠️ <strong>Tuto kartu vyplnil vlastník.</strong> Nemáte oprávnění ji měnit. Pouze superadmin může zasáhnout.
  </div>
  <?php elseif ($owner && ($owner['updated_by_role'] ?? '') === 'owner' && $user['role'] === 'superadmin'): ?>
  <div style="background:#f0e6fb;border:1px solid #c9a0e0;border-radius:var(--radius-sm);padding:.75rem 1rem;margin-bottom:1rem;font-size:13px;color:#6b11a5">
    🔑 <strong>Karta vyplněna vlastníkem.</strong> Jako superadmin ji můžete upravit — vlastníkovi bude automaticky odeslán e-mail o provedené změně.
  </div>
  <?php endif; ?>
  <?php if ($owner && $owner['updated_by_role']): ?>
  <div style="font-size:12px;color:var(--muted);margin-bottom:1rem;padding:.5rem .75rem;background:var(--gray-lt);border-radius:var(--radius-sm)">
    Naposledy upravil:
    <?php
      echo match($owner['updated_by_role']) {
        'owner'      => '👤 Vlastník',
        'admin'      => '⚙ Výbor',
        'superadmin' => '🔑 Admin',
        default      => $owner['updated_by_role'],
      };
    ?>
    — <?= date('j. n. Y H:i', strtotime($owner['updated_at'])) ?>
  </div>
  <?php endif; ?>

  <div class="check-row" style="margin-bottom:1.25rem">
    <input type="checkbox" id="gdpr" name="gdpr_consent" <?= !empty($o['gdpr_consent']) ? 'checked' : '' ?>>
    <label for="gdpr">Souhlas se zpracováním osobních údajů (GDPR) byl udělen
      <?php if (!empty($o['gdpr_date'])): ?>
        <span style="color:var(--muted)"> — <?= date('j. n. Y', strtotime($o['gdpr_date'])) ?></span>
      <?php endif; ?>
    </label>
  </div>

  <button type="submit" class="btn btn-primary">Uložit kartu</button>
</form>
</div>

<?php if ($owner): ?>
<!-- ══ DALŠÍ VLASTNÍCI (SJM / podílové) ═══════════════════════════════════ -->
<div class="card" id="dalsi-vlastnici" style="max-width:640px;margin-top:1.25rem;border-top:4px solid var(--blue)">
  <div style="font-size:14px;font-weight:600;color:var(--blue);margin-bottom:.25rem">👥 Další vlastníci</div>
  <p style="font-size:12px;color:var(--muted);margin-bottom:1rem">
    Použijte u SJM (manžel/ka) nebo podílového vlastnictví (další spoluvlastníci). Hlavní vlastník zůstává ve formuláři výše.
  </p>

  <?php if ($ownerPersons): ?>
  <table class="tbl tbl-edit" style="margin-bottom:1rem">
    <thead><tr><th>Jméno</th><th>Vztah</th><th>Kontakt</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($ownerPersons as $p): ?>
    <?php if ($editPerson && (int)$editPerson['id'] === (int)$p['id']): ?>
    <tr style="background:var(--blue-lt)">
      <td colspan="4">
        <form method="POST" style="padding:.5rem 0">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="action" value="save_person">
          <input type="hidden" name="person_id" value="<?= $p['id'] ?>">
          <div class="form-row">
            <div class="form-group"><label>Jméno a příjmení *</label><input type="text" name="p_full_name" required value="<?= e($p['full_name']) ?>"></div>
            <div class="form-group"><label>Vztah</label><input type="text" name="p_relation" placeholder="manžel/ka, spoluvlastník..." value="<?= e($p['relation'] ?? '') ?>"></div>
          </div>
          <div class="form-row">
            <div class="form-group"><label>E-mail</label><input type="email" name="p_email" value="<?= e($p['email'] ?? '') ?>"></div>
            <div class="form-group"><label>Telefon</label><input type="tel" name="p_phone" value="<?= e($p['phone'] ?? '') ?>"></div>
          </div>
          <div class="form-group"><label>Adresa</label><input type="text" name="p_address" value="<?= e($p['address'] ?? '') ?>"></div>
          <div class="form-group"><label>Poznámka</label><input type="text" name="p_note" value="<?= e($p['note'] ?? '') ?>"></div>
          <div style="display:flex;gap:8px">
            <button type="submit" class="btn btn-primary btn-sm">Uložit</button>
            <a class="btn btn-secondary btn-sm" href="/admin/owner_edit.php?id=<?= $owner['id'] ?>#dalsi-vlastnici">Zrušit</a>
          </div>
        </form>
      </td>
    </tr>
    <?php else: ?>
    <tr>
      <td>
        <strong><?= e($p['full_name']) ?></strong>
        <?php if ($p['address']): ?><br><small style="color:var(--muted)">🏡 <?= e($p['address']) ?></small><?php endif; ?>
        <?php if ($p['note']): ?><br><small style="color:var(--muted)"><?= e($p['note']) ?></small><?php endif; ?>
      </td>
      <td style="font-size:13px;color:var(--muted)"><?= e($p['relation'] ?? '–') ?></td>
      <td style="font-size:13px">
        <?= $p['email'] ? e($p['email']) . '<br>' : '' ?>
        <?= $p['phone'] ? e($p['phone']) : '' ?>
      </td>
      <td style="white-space:nowrap">
        <a class="btn btn-secondary btn-sm" href="?id=<?= $owner['id'] ?>&edit_person=<?= $p['id'] ?>#dalsi-vlastnici">✏</a>
        <form method="POST" style="display:inline" onsubmit="return confirm('Smazat?')">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="action" value="delete_person">
          <input type="hidden" name="person_id" value="<?= $p['id'] ?>">
          <button type="submit" class="btn btn-danger btn-sm">✕</button>
        </form>
      </td>
    </tr>
    <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <p style="color:var(--muted);font-size:13px;margin-bottom:1rem">Zatím žádní další vlastníci.</p>
  <?php endif; ?>

  <details <?= !$ownerPersons ? 'open' : '' ?>>
    <summary style="font-size:13px;color:var(--blue);font-weight:600;cursor:pointer;margin-bottom:.5rem">+ Přidat dalšího vlastníka</summary>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="save_person">
      <input type="hidden" name="person_id" value="0">
      <div class="form-row">
        <div class="form-group"><label>Jméno a příjmení *</label><input type="text" name="p_full_name" required placeholder="Jana Nováková"></div>
        <div class="form-group"><label>Vztah</label><input type="text" name="p_relation" placeholder="manžel/ka, spoluvlastník..."></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>E-mail</label><input type="email" name="p_email"></div>
        <div class="form-group"><label>Telefon</label><input type="tel" name="p_phone"></div>
      </div>
      <div class="form-group"><label>Adresa</label><input type="text" name="p_address" placeholder="pokud se liší od hlavního vlastníka"></div>
      <div class="form-group"><label>Poznámka</label><input type="text" name="p_note"></div>
      <button type="submit" class="btn btn-primary btn-sm">Přidat</button>
    </form>
  </details>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
