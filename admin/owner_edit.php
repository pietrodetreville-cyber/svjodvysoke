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
        'email'        => trim($_POST['email'] ?? '') ?: null,
        'email_verified' => isset($_POST['email_verified']) ? 1 : 0,
        'notify_email' => isset($_POST['notify_email']) ? 1 : 0,
        'phone'        => trim($_POST['phone'] ?? '') ?: null,
        'whatsapp'     => isset($_POST['whatsapp']) ? 1 : 0,
        'address'      => trim($_POST['address'] ?? ''),
        'residence'      => $_POST['residence'] ?? 'neuvedeno',
        'ownership_form' => $_POST['ownership_form'] ?? 'neuvedeno',
        'unit_share_pct' => $_POST['unit_share_pct'] !== '' && isset($_POST['unit_share_pct']) ? (float)$_POST['unit_share_pct'] : null,
        'persons_count'  => !empty($_POST['persons_count']) ? (int)$_POST['persons_count'] : null,
        'note'         => trim($_POST['note'] ?? ''),
        'gdpr_consent' => isset($_POST['gdpr_consent']) ? 1 : 0,
        'gdpr_date'    => isset($_POST['gdpr_consent']) ? date('Y-m-d H:i:s') : null,
    ];
    $data['status'] = ownerStatus($data);
    if (!in_array($data['ownership_form'], ['podílové', 'společné jmění manželů'])) $data['unit_share_pct'] = null;

    // Hierarchie: výbor nemůže přepsat kartu vyplněnou vlastníkem
    if ($owner && ($owner['updated_by_role'] ?? '') === 'owner' && $user['role'] === 'admin') {
        flash('Tuto kartu vyplnil vlastník — nemáte oprávnění ji měnit. Kontaktujte superadmina.', 'error');
        header('Location: /admin/owners.php'); exit;
    }

    if ($owner) {
        $db->prepare(
            'UPDATE owners SET unit_id=?,full_name=?,email=?,email_verified=?,notify_email=?,phone=?,whatsapp=?,address=?,residence=?,ownership_form=?,unit_share_pct=?,note=?,gdpr_consent=?,gdpr_date=?,status=?,updated_by_role=?,persons_count=? WHERE id=?'
        )->execute([
            $data['unit_id'], $data['full_name'], $data['email'], $data['email_verified'], $data['notify_email'],
            $data['phone'], $data['whatsapp'],
            $data['address'], $data['residence'], $data['ownership_form'], $data['unit_share_pct'], $data['note'], $data['gdpr_consent'],
            $data['gdpr_date'], $data['status'],
            $user['role'], $data['persons_count'],
            $owner['id']
        ]);
        flash('Karta uložena.', 'success');
        header('Location: /admin/owner_edit.php?id=' . $owner['id']); exit;
    } else {
        $db->prepare(
            'INSERT INTO owners (unit_id,full_name,email,email_verified,notify_email,phone,whatsapp,address,residence,ownership_form,unit_share_pct,note,gdpr_consent,gdpr_date,status,updated_by_role,persons_count) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $data['unit_id'], $data['full_name'], $data['email'], $data['email_verified'], $data['notify_email'],
            $data['phone'], $data['whatsapp'],
            $data['address'], $data['residence'], $data['ownership_form'], $data['unit_share_pct'], $data['note'], $data['gdpr_consent'],
            $data['gdpr_date'], $data['status'],
            $user['role'], $data['persons_count']
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
        isset($_POST['p_email_verified']) ? 1 : 0,
        isset($_POST['p_notify_email']) ? 1 : 0,
        trim($_POST['p_phone'] ?? '') ?: null,
        isset($_POST['p_whatsapp']) ? 1 : 0,
        trim($_POST['p_relation'] ?? '') ?: null,
        ($_POST['p_unit_share_pct'] ?? '') !== '' ? (float)$_POST['p_unit_share_pct'] : null,
        trim($_POST['p_address'] ?? '') ?: null,
        trim($_POST['p_note'] ?? '') ?: null,
    ];
    if ($pdata[0] !== '') {
        if ($pid) {
            $db->prepare('UPDATE owner_persons SET full_name=?,email=?,email_verified=?,notify_email=?,phone=?,whatsapp=?,relation=?,unit_share_pct=?,address=?,note=? WHERE id=? AND owner_id=?')
               ->execute([...$pdata, $pid, $owner['id']]);
        } else {
            $db->prepare('INSERT INTO owner_persons (owner_id,full_name,email,email_verified,notify_email,phone,whatsapp,relation,unit_share_pct,address,note) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
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

// Rychlé přidání nájemníka / osoby s věcným břemenem (zobrazí se, když způsob užívání = pronájem nebo věcné břemeno)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_tenant_inline' && $owner) {
    csrfCheck();
    $tname = trim($_POST['t_full_name'] ?? '');
    $tTyp  = in_array($_POST['t_typ'] ?? '', ['najem','vecne_bremeno']) ? $_POST['t_typ'] : 'najem';
    if ($tname) {
        $db->prepare('INSERT INTO tenants (unit_id,typ,full_name,persons_count,email,notify_email,phone,whatsapp) VALUES (?,?,?,?,?,?,?,?)')
           ->execute([
               $owner['unit_id'], $tTyp, $tname,
               !empty($_POST['t_persons_count']) ? (int)$_POST['t_persons_count'] : null,
               trim($_POST['t_email'] ?? '') ?: null, isset($_POST['t_notify_email']) ? 1 : 0,
               trim($_POST['t_phone'] ?? '') ?: null, isset($_POST['t_whatsapp']) ? 1 : 0,
           ]);
        flash('Uloženo. Další údaje (nájem od/do, počet osob) doplňte v Uživatelích jednotky.', 'success');
    }
    header('Location: /admin/owner_edit.php?id=' . $owner['id']); exit;
}

$ownerPersons = [];
$editPerson = null;
$unitTenants = [];
if ($owner) {
    $stmt = $db->prepare('SELECT * FROM owner_persons WHERE owner_id=? ORDER BY id');
    $stmt->execute([$owner['id']]);
    $ownerPersons = $stmt->fetchAll();
    if (isset($_GET['edit_person'])) {
        foreach ($ownerPersons as $pp) if ((int)$pp['id'] === (int)$_GET['edit_person']) { $editPerson = $pp; break; }
    }
    $tstmt = $db->prepare("SELECT * FROM tenants WHERE unit_id=? ORDER BY full_name");
    $tstmt->execute([$owner['unit_id']]);
    $unitTenants = $tstmt->fetchAll();
}
$usageTypLabels = ['najem' => 'Nájem', 'vecne_bremeno' => 'Věcné břemeno'];

$ownershipLabels = ['bezpodílové' => 'Jednoduché (jeden vlastník)', 'společné jmění manželů' => 'SJM (manželé)', 'podílové' => 'Podílové (více vlastníků)', 'neuvedeno' => 'Neuvedeno'];

include __DIR__ . '/../includes/header.php';
$o = $owner ?? [];
?>

<style>
.owner-edit-grid { display:grid; grid-template-columns:1fr 1fr; gap:1.25rem; align-items:start; }
@media(max-width:900px){ .owner-edit-grid{ grid-template-columns:1fr; } }
</style>

<div class="page-hd">
  <h1><?= $owner ? 'Upravit kartu vlastníka' : 'Přidat vlastníka' ?></h1>
  <a class="btn btn-secondary" href="/admin/owners.php">← Zpět</a>
</div>

<div class="owner-edit-grid">

<div class="card" style="border-top:4px solid var(--blue)">
  <div style="font-size:14px;font-weight:600;color:var(--blue);margin-bottom:1rem">👤 Základní údaje</div>
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

  <?php $isSplitOwnership = in_array($o['ownership_form'] ?? 'neuvedeno', ['podílové', 'společné jmění manželů']); ?>
  <div class="form-row">
    <div class="form-group">
      <label>Vlastnictví</label>
      <select name="ownership_form" id="ownership-select" onchange="toggleSharePct(this.value)">
        <?php foreach ($ownershipLabels as $val => $lbl): ?>
          <option value="<?= e($val) ?>" <?= ($o['ownership_form'] ?? 'neuvedeno') === $val ? 'selected' : '' ?>><?= e($lbl) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label>Způsob užívání</label>
      <select name="residence" id="residence-select" onchange="toggleUsageBox(this.value)">
        <?php foreach (['vlastní','pronájem','věcné břemeno','neuvedeno'] as $opt): ?>
          <option value="<?= $opt ?>" <?= ($o['residence'] ?? '') === $opt ? 'selected' : '' ?>><?= $opt ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="form-group" id="share-pct-box" style="display:<?= $isSplitOwnership ? 'block' : 'none' ?>;max-width:220px">
    <label>Podíl hlavního vlastníka na jednotce (%)</label>
    <input type="number" step="0.01" min="0" max="100" name="unit_share_pct" placeholder="50.00" value="<?= e($o['unit_share_pct'] ?? '') ?>">
  </div>

  <?php $showUsageBox = in_array($o['residence'] ?? '', ['pronájem','věcné břemeno']); ?>

  <script>
  function toggleUsageBox(val) {
    var show = (val === 'pronájem' || val === 'věcné břemeno');
    document.getElementById('pronajem-box').style.display = show ? 'block' : 'none';
    var typField = document.getElementById('usage-typ-field');
    if (typField) typField.value = (val === 'věcné břemeno') ? 'vecne_bremeno' : 'najem';
  }
  function toggleSharePct(val) {
    var show = (val === 'podílové' || val === 'společné jmění manželů');
    document.getElementById('share-pct-box').style.display = show ? 'block' : 'none';
  }
  </script>

  <div class="form-row">
    <div class="form-group">
      <label>Jméno a příjmení (hlavní vlastník) *</label>
      <input type="text" name="full_name" required value="<?= e($o['full_name'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label>Počet osob v jednotce</label>
      <input type="number" name="persons_count" min="0" max="20" placeholder="0"
             value="<?= e($o['persons_count'] ?? '') ?>">
    </div>
  </div>

  <!-- Kontakty -->
  <div style="border:1px solid var(--border);border-radius:var(--radius-sm);padding:1rem;margin-bottom:1rem;background:var(--gray-lt)">
    <div style="font-size:13px;font-weight:600;color:var(--muted);margin-bottom:.75rem">Kontaktní údaje</div>
    <div class="form-row">
      <div class="form-group">
        <label>E-mail</label>
        <input type="email" name="email" value="<?= e($o['email'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Telefon</label>
        <input type="tel" name="phone" value="<?= e($o['phone'] ?? '') ?>">
      </div>
    </div>
    <div style="font-size:13px;display:flex;gap:1.25rem;flex-wrap:wrap">
      <label style="cursor:pointer"><input type="checkbox" name="email_verified" <?= !empty($o['email_verified']) ? 'checked' : '' ?>> E-mail ověřen</label>
      <label style="cursor:pointer"><input type="checkbox" name="notify_email" <?= !isset($o['notify_email']) || $o['notify_email'] ? 'checked' : '' ?>> Odesílat e-mail info</label>
      <label style="cursor:pointer"><input type="checkbox" name="whatsapp" <?= !empty($o['whatsapp']) ? 'checked' : '' ?>> Používat pro WhatsApp</label>
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

<div>

<?php if ($owner): ?>
<!-- ══ DALŠÍ VLASTNÍCI (SJM / podílové) ═══════════════════════════════════ -->
<div class="card" id="dalsi-vlastnici" style="border-top:4px solid var(--blue)">
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
          <?php if ($isSplitOwnership): ?>
          <div class="form-group" style="max-width:220px">
            <label>Podíl na jednotce (%)</label>
            <input type="number" step="0.01" min="0" max="100" name="p_unit_share_pct" placeholder="50.00" value="<?= e($p['unit_share_pct'] ?? '') ?>">
          </div>
          <?php endif; ?>
          <div style="display:flex;gap:1.25rem;font-size:13px;margin-bottom:.5rem">
            <label style="cursor:pointer"><input type="checkbox" name="p_email_verified" <?= !empty($p['email_verified']) ? 'checked' : '' ?>> Ověřeno</label>
            <label style="cursor:pointer"><input type="checkbox" name="p_notify_email" <?= !isset($p['notify_email']) || $p['notify_email'] ? 'checked' : '' ?>> E-mail pro informace</label>
            <label style="cursor:pointer"><input type="checkbox" name="p_whatsapp" <?= !empty($p['whatsapp']) ? 'checked' : '' ?>> WhatsApp</label>
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
      <td style="font-size:13px;color:var(--muted)">
        <?= e($p['relation'] ?? '–') ?>
        <?php if ($p['unit_share_pct'] !== null): ?><br><strong style="color:var(--text)"><?= e($p['unit_share_pct']) ?> %</strong><?php endif; ?>
      </td>
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
      <?php if ($isSplitOwnership): ?>
      <div class="form-group" style="max-width:220px">
        <label>Podíl na jednotce (%)</label>
        <input type="number" step="0.01" min="0" max="100" name="p_unit_share_pct" placeholder="50.00">
      </div>
      <?php endif; ?>
      <div style="display:flex;gap:1.25rem;font-size:13px;margin-bottom:.5rem">
        <label style="cursor:pointer"><input type="checkbox" name="p_email_verified"> Ověřeno</label>
        <label style="cursor:pointer"><input type="checkbox" name="p_notify_email" checked> E-mail pro informace</label>
        <label style="cursor:pointer"><input type="checkbox" name="p_whatsapp"> WhatsApp</label>
      </div>
      <div class="form-group"><label>Adresa</label><input type="text" name="p_address" placeholder="pokud se liší od hlavního vlastníka"></div>
      <div class="form-group"><label>Poznámka</label><input type="text" name="p_note"></div>
      <button type="submit" class="btn btn-primary btn-sm">Přidat</button>
    </form>
  </details>
</div>
<?php else: ?>
<div class="card" style="border-top:4px solid var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:13px;text-align:center;min-height:120px">
  Další vlastníky (SJM / podílové) půjde přidat po uložení karty.
</div>
<?php endif; ?>

<div id="pronajem-box" class="card" style="display:<?= $showUsageBox ? 'block' : 'none' ?>;border-top:4px solid var(--green);margin-top:1.25rem">
  <div style="font-size:14px;font-weight:600;color:var(--green);margin-bottom:1rem">🏠 Nájemníci / osoby s věcným břemenem</div>
  <?php if ($unitTenants): ?>
    <?php foreach ($unitTenants as $ut): ?>
      <div style="font-size:13px;margin-bottom:4px">
        <?= e($ut['full_name']) ?>
        <span class="badge <?= $ut['typ']==='vecne_bremeno' ? 'badge-partial' : 'badge-blue' ?>" style="font-size:10px"><?= e($usageTypLabels[$ut['typ']] ?? $ut['typ']) ?></span>
        <?php if ($ut['email'] || $ut['phone']): ?><span style="color:var(--muted)">— <?= e($ut['email'] ?: '') ?><?= $ut['email'] && $ut['phone'] ? ', ' : '' ?><?= e($ut['phone'] ?: '') ?></span><?php endif; ?>
        <?php if (!empty($ut['notify_email'])): ?><span style="font-size:10px;color:var(--green)">✉️ info</span><?php endif; ?>
        <?php if (!empty($ut['whatsapp'])): ?><span style="font-size:10px;color:var(--green)">💬 WA</span><?php endif; ?>
      </div>
    <?php endforeach; ?>
    <a href="/admin/tenants.php" style="font-size:12px" class="btn btn-secondary btn-sm">Spravovat v Uživatelích jednotky</a>
  <?php else: ?>
    <p style="font-size:12px;color:var(--muted);margin-bottom:.5rem" id="usage-empty-msg">Zatím žádný záznam u této jednotky.</p>
    <?php if ($owner): ?>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="save_tenant_inline">
      <input type="hidden" name="t_typ" id="usage-typ-field" value="<?= ($o['residence'] ?? '') === 'věcné břemeno' ? 'vecne_bremeno' : 'najem' ?>">
      <div class="form-row">
        <div class="form-group" style="margin:0"><label style="font-size:11px">Jméno a příjmení</label><input type="text" name="t_full_name"></div>
        <div class="form-group" style="margin:0"><label style="font-size:11px">Počet osob</label><input type="number" name="t_persons_count" min="1" max="20" style="max-width:100px"></div>
      </div>
      <div class="form-row" style="margin-top:.5rem">
        <div class="form-group" style="margin:0"><label style="font-size:11px">E-mail</label><input type="email" name="t_email"></div>
        <div class="form-group" style="margin:0"><label style="font-size:11px">Telefon</label><input type="tel" name="t_phone"></div>
      </div>
      <div style="display:flex;gap:1.25rem;align-items:center;flex-wrap:wrap;margin-top:.6rem">
        <label style="cursor:pointer;font-size:12px"><input type="checkbox" name="t_notify_email" checked> odesílat e-mail info</label>
        <label style="cursor:pointer;font-size:12px"><input type="checkbox" name="t_whatsapp"> WhatsApp</label>
        <button type="submit" class="btn btn-primary btn-sm">Přidat</button>
      </div>
    </form>
    <?php else: ?>
    <p style="font-size:12px;color:var(--muted)">Nejdřív uložte kartu vlastníka, pak půjde přidat záznam rovnou zde.</p>
    <?php endif; ?>
  <?php endif; ?>
</div>

</div>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
