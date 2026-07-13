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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $data = [
        'unit_id'      => (int)$_POST['unit_id'],
        'full_name'    => trim($_POST['full_name'] ?? ''),
        'email'        => trim($_POST['email'] ?? ''),
        'phone'        => trim($_POST['phone'] ?? ''),
        'address'      => trim($_POST['address'] ?? ''),
        'residence'      => $_POST['residence'] ?? 'neuvedeno',
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
            'UPDATE owners SET unit_id=?,full_name=?,email=?,phone=?,address=?,residence=?,note=?,gdpr_consent=?,gdpr_date=?,status=?,updated_by_role=?,persons_count=?,email2=?,phone2=?,primary_email=?,primary_phone=? WHERE id=?'
        )->execute([
            $data['unit_id'], $data['full_name'], $data['email'], $data['phone'],
            $data['address'], $data['residence'], $data['note'], $data['gdpr_consent'],
            $data['gdpr_date'], $data['status'],
            $user['role'], $data['persons_count'],
            $data['email2'], $data['phone2'], $data['primary_email'], $data['primary_phone'],
            $owner['id']
        ]);
        flash('Karta uložena.', 'success');
    } else {
        $db->prepare(
            'INSERT INTO owners (unit_id,full_name,email,phone,address,residence,note,gdpr_consent,gdpr_date,status,updated_by_role,persons_count,email2,phone2,primary_email,primary_phone) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $data['unit_id'], $data['full_name'], $data['email'], $data['phone'],
            $data['address'], $data['residence'], $data['note'], $data['gdpr_consent'],
            $data['gdpr_date'], $data['status'],
            $user['role'], $data['persons_count'],
            $data['email2'], $data['phone2'], $data['primary_email'], $data['primary_phone']
        ]);
        flash('Vlastník přidán.', 'success');
    }
    header('Location: /admin/owners.php'); exit;
}

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
      <label>Jméno a příjmení *</label>
      <input type="text" name="full_name" required value="<?= e($o['full_name'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label>Způsob užívání</label>
      <select name="residence">
        <?php foreach (['trvalé','pronájem','druhé bydliště','neuvedeno'] as $opt): ?>
          <option value="<?= $opt ?>" <?= ($o['residence'] ?? '') === $opt ? 'selected' : '' ?>><?= $opt ?></option>
        <?php endforeach; ?>
      </select>
    </div>
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

<?php include __DIR__ . '/../includes/footer.php'; ?>
