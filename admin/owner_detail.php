// Výbor<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
$user = requireAdmin();
$db = db();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: /admin/owners.php'); exit; }

$stmt = $db->prepare('SELECT o.*, u.label AS unit_label, u.type AS unit_type, u.area_m2 FROM owners o JOIN units u ON o.unit_id=u.id WHERE o.id=?');
$stmt->execute([$id]);
$owner = $stmt->fetch();
if (!$owner) { header('Location: /admin/owners.php'); exit; }

$pageTitle = 'Karta – ' . $owner['full_name'];
$o = $owner;

// Garáž
$gs = $db->prepare("SELECT * FROM units WHERE linked_unit_id=? AND type != 'byt' LIMIT 1");
$gs->execute([$owner['unit_id']]);
$garage = $gs->fetch();

$garageOwner = null;
if ($garage) {
    $go = $db->prepare('SELECT * FROM owners WHERE unit_id=? LIMIT 1');
    $go->execute([$garage['id']]);
    $garageOwner = $go->fetch();
}

// Nájemník
$ts = $db->prepare('SELECT * FROM tenants WHERE unit_id=? ORDER BY created_at DESC LIMIT 1');
$ts->execute([$owner['unit_id']]);
$tenant = $ts->fetch();
$t = $tenant ?? [];

// === SUPERADMIN AKCE ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($isSuperAdmin || $canEdit)) {
    csrfCheck();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_owner') {
        $data = [
            'full_name'     => trim($_POST['full_name'] ?? ''),
            'residence'     => $_POST['residence'] ?? 'neuvedeno',
            'persons_count' => !empty($_POST['persons_count']) ? (int)$_POST['persons_count'] : null,
            'email'         => trim($_POST['email'] ?? '') ?: null,
            'email2'        => trim($_POST['email2'] ?? '') ?: null,
            'primary_email' => (int)($_POST['primary_email'] ?? 1),
            'phone'         => trim($_POST['phone'] ?? '') ?: null,
            'phone2'        => trim($_POST['phone2'] ?? '') ?: null,
            'primary_phone' => (int)($_POST['primary_phone'] ?? 1),
            'address'       => trim($_POST['address'] ?? '') ?: null,
            'note'          => trim($_POST['note'] ?? '') ?: null,
            'gdpr_consent'  => isset($_POST['gdpr_consent']) ? 1 : 0,
        ];
        $db->prepare('UPDATE owners SET full_name=?,residence=?,persons_count=?,email=?,email2=?,primary_email=?,phone=?,phone2=?,primary_phone=?,address=?,note=?,gdpr_consent=?,updated_by_role=? WHERE id=?')
           ->execute([$data['full_name'],$data['residence'],$data['persons_count'],$data['email'],$data['email2'],$data['primary_email'],$data['phone'],$data['phone2'],$data['primary_phone'],$data['address'],$data['note'],$data['gdpr_consent'],'superadmin',$owner['id']]);
        // E-mail vlastníkovi
        if ($owner['email']) {
            $html = mailTemplate('Vaše karta vlastníka byla upravena správcem', "Vážený vlastníku,\n\nváš záznam v kartotéce SVJ Od Vysoké – Rozhled byl upraven správcem systému.\n\nPokud máte dotazy, kontaktujte výbor SVJ.\n\nVýbor SVJ Od Vysoké – Rozhled");
            sendMail([$owner['email']], '[SVJ Od Vysoké – Rozhled] Úprava vaší karty správcem', $html, [], false);
        }
        flash('Karta uložena. Vlastníkovi byl odeslán e-mail.', 'success');
        header("Location: /admin/owner_detail.php?id=$id"); exit;
    }

    if ($action === 'save_tenant') {
        $tname = trim($_POST['t_full_name'] ?? '');
        $tPersons = !empty($_POST['t_persons_count']) ? (int)$_POST['t_persons_count'] : null;
        if ($tPersons) $db->prepare('UPDATE owners SET persons_count=? WHERE id=?')->execute([$tPersons, $owner['id']]);
        if ($tname) {
            $tdata = [$tname, trim($_POST['t_email']??'')?: null, trim($_POST['t_email2']??'')?: null, (int)($_POST['t_primary_email']??1), trim($_POST['t_phone']??'')?: null, trim($_POST['t_phone2']??'')?: null, (int)($_POST['t_primary_phone']??1), $_POST['t_rent_from']?: null, $_POST['t_rent_until']?: null, $tPersons];
            if ($tenant) {
                $db->prepare('UPDATE tenants SET full_name=?,email=?,email2=?,primary_email=?,phone=?,phone2=?,primary_phone=?,rent_from=?,rent_until=?,persons_count=? WHERE id=?')
                   ->execute([...$tdata, $tenant['id']]);
            } else {
                $db->prepare('INSERT INTO tenants (full_name,email,email2,primary_email,phone,phone2,primary_phone,rent_from,rent_until,persons_count,unit_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                   ->execute([...$tdata, $owner['unit_id']]);
            }
        }
        flash('Nájemník uložen.', 'success');
        header("Location: /admin/owner_detail.php?id=$id"); exit;
    }
}

// Výbor může editovat jen pokud vlastník ještě kartu nevyplnil
$canEdit = $isSuperAdmin || ($o['updated_by_role'] ?? '') !== 'owner';

include __DIR__ . '/../includes/header.php';
?>

<style>
.profile-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;align-items:start}
.summary-block{background:#fff;border:2px solid var(--border);border-radius:var(--radius);padding:1.25rem;box-shadow:0 4px 16px rgba(0,0,0,.08)}
.summary-block-owner{border-top:4px solid #185FA5}
.summary-block-tenant{border-top:4px solid #3B6D11}
.summary-block-garage{border-top:4px solid #854F0B}
.card-owner{border-top:4px solid #A8C8E8}
.card-tenant{border-top:4px solid #A8CC88}
.card-garage{border-top:4px solid #DDB870}
.card-password{border-top:4px solid #C8C8C8}
.summary-label{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:.75rem;padding-bottom:.5rem;border-bottom:1px solid var(--border)}
.summary-val{font-size:14px;color:var(--text);margin-bottom:4px}
.primary-badge{font-size:10px;background:var(--blue-lt);color:var(--blue);padding:1px 6px;border-radius:99px;font-weight:600;margin-left:4px}
.card{border:2px solid var(--border);box-shadow:0 4px 16px rgba(0,0,0,.07)}
@media(max-width:700px){.profile-grid{grid-template-columns:1fr}}
</style>

<div class="page-hd">
  <div>
    <h1><?= e($o['full_name']) ?></h1>
    <div style="font-size:13px;color:var(--muted);margin-top:2px">
      🏠 <?= e($o['unit_label']) ?>
      <?php if ($garage): ?> &nbsp;·&nbsp; 🚗 <?= e($garage['label']) ?><?php endif; ?>
      &nbsp;·&nbsp;
      <span class="badge <?= $o['status']==='úplná'?'badge-ok':($o['status']==='neúplná'?'badge-partial':'badge-miss') ?>"><?= e($o['status']) ?></span>
    </div>
  </div>
  <a class="btn btn-secondary" href="/admin/owners.php">← Zpět</a>
</div>

<!-- Stavový banner -->
<?php if (($o['updated_by_role'] ?? '') === 'owner'): ?>
<div style="background:var(--green-lt);border:1px solid #b5d97a;border-radius:var(--radius-sm);padding:.75rem 1rem;margin-bottom:1.25rem;font-size:13px;color:var(--green)">
  ✓ Karta byla vyplněna vlastníkem<?= $isSuperAdmin ? ' — jako superadmin ji můžete upravit níže.' : ' — pouze superadmin může provést změny.' ?>
</div>
<?php elseif (($o['updated_by_role'] ?? '') === 'superadmin'): ?>
<div style="background:#f0e6fb;border:1px solid #c9a0e0;border-radius:var(--radius-sm);padding:.75rem 1rem;margin-bottom:1.25rem;font-size:13px;color:#6b11a5">
  🔑 Karta byla naposledy upravena superadminem.
</div>
<?php elseif (($o['updated_by_role'] ?? '') === 'admin'): ?>
<div style="background:var(--blue-lt);border-radius:var(--radius-sm);padding:.75rem 1rem;margin-bottom:1.25rem;font-size:13px;color:var(--blue)">
  ⚙ Karta byla naposledy upravena výborem.
</div>
<?php endif; ?>

<!-- Info o jednotce -->
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:1.25rem">
  <div style="background:var(--blue-lt);border-radius:var(--radius-sm);padding:.6rem 1rem;font-size:13px;color:var(--blue);font-weight:500">
    🏠 <strong><?= e($o['unit_label']) ?></strong> (<?= e($o['unit_type']) ?>)<?= $o['area_m2'] ? ' · '.$o['area_m2'].' m²' : '' ?>
  </div>
  <?php if ($garage): ?>
  <div style="background:#FFF8E6;border-radius:var(--radius-sm);padding:.6rem 1rem;font-size:13px;color:var(--amber);font-weight:500">
    🚗 Garáž: <strong><?= e($garage['label']) ?></strong><?= $garage['area_m2'] ? ' · '.$garage['area_m2'].' m²' : '' ?>
  </div>
  <?php endif; ?>
</div>

<!-- SUMARIZAČNÍ BLOKY -->
<div class="profile-grid" style="margin-bottom:1.25rem">
  <div class="summary-block summary-block-owner">
    <div class="summary-label">👤 Vlastník</div>
    <div class="summary-val"><strong><?= e($o['full_name']) ?></strong></div>
    <div class="summary-val" style="color:var(--muted)"><?= e($o['residence'] ?? '') ?><?= $o['persons_count'] ? ' · '.$o['persons_count'].' os.' : '' ?></div>
    <?php
      $mainEmail = ($o['primary_email'] ?? 1) == 2 && $o['email2'] ? $o['email2'] : $o['email'];
      $mainPhone = ($o['primary_phone'] ?? 1) == 2 && $o['phone2'] ? $o['phone2'] : $o['phone'];
    ?>
    <?php if ($mainEmail): ?><div class="summary-val">✉️ <a href="mailto:<?= e($mainEmail) ?>"><?= e($mainEmail) ?></a><?php if ($o['email'] && $o['email2']): ?><span class="primary-badge">+1</span><?php endif; ?></div><?php endif; ?>
    <?php if ($mainPhone): ?><div class="summary-val">📞 <?= e($mainPhone) ?><?php if ($o['phone'] && $o['phone2']): ?><span class="primary-badge">+1</span><?php endif; ?></div><?php endif; ?>
    <?php if ($o['address']): ?><div class="summary-val" style="color:var(--muted);font-size:13px">🏡 <?= e($o['address']) ?></div><?php endif; ?>
    <?php if ($o['note']): ?><div class="summary-val" style="color:var(--muted);font-size:13px">💬 <?= e($o['note']) ?></div><?php endif; ?>
    <div class="summary-val" style="margin-top:6px"><?= $o['gdpr_consent'] ? '<span class="badge badge-ok">GDPR ✓</span>' : '<span class="badge badge-miss">GDPR ✗</span>' ?></div>
    <?php if ($o['updated_at']): ?><div style="font-size:11px;color:var(--muted);margin-top:6px">Upraveno <?= date('j. n. Y H:i', strtotime($o['updated_at'])) ?></div><?php endif; ?>
  </div>

  <div class="summary-block summary-block-tenant">
    <div class="summary-label">🏠 Nájemník</div>
    <?php if ($tenant): ?>
      <div class="summary-val"><strong><?= e($t['full_name']) ?></strong></div>
      <?php if ($t['persons_count']): ?><div class="summary-val" style="color:var(--muted)"><?= $t['persons_count'] ?> os. <span style="font-size:11px;color:var(--blue)">(nadřazený)</span></div><?php endif; ?>
      <?php $tEmail = ($t['primary_email']??1)==2&&$t['email2']?$t['email2']:$t['email']; ?>
      <?php if ($tEmail): ?><div class="summary-val">✉️ <a href="mailto:<?= e($tEmail) ?>"><?= e($tEmail) ?></a></div><?php endif; ?>
      <?php $tPhone = ($t['primary_phone']??1)==2&&$t['phone2']?$t['phone2']:$t['phone']; ?>
      <?php if ($tPhone): ?><div class="summary-val">📞 <?= e($tPhone) ?></div><?php endif; ?>
      <?php if ($t['rent_from']||$t['rent_until']): ?>
        <div class="summary-val" style="font-size:13px;color:var(--muted)">
          <?= $t['rent_from']?'od '.date('j.n.Y',strtotime($t['rent_from'])):'' ?>
          <?= $t['rent_until']?' do '.date('j.n.Y',strtotime($t['rent_until'])):'' ?>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <div style="color:var(--muted);font-size:13px">Žádný nájemník evidován.</div>
    <?php endif; ?>
  </div>
</div>

<!-- Garáž -->
<?php if ($garage): ?>
<div class="summary-block summary-block-garage" style="margin-bottom:1.25rem">
  <div class="summary-label">🚗 Garáž <?= e($garage['label']) ?></div>
  <?php if ($garageOwner && $garageOwner['full_name']): ?>
    <div class="summary-val"><strong><?= e($garageOwner['full_name']) ?></strong></div>
    <?php if ($garageOwner['email']): ?><div class="summary-val">✉️ <?= e($garageOwner['email']) ?></div><?php endif; ?>
    <?php if ($garageOwner['phone']): ?><div class="summary-val">📞 <?= e($garageOwner['phone']) ?></div><?php endif; ?>
  <?php else: ?>
    <div style="color:var(--muted);font-size:13px">Údaje garáže nejsou vyplněny.</div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$canEdit && !$isSuperAdmin): ?>
<!-- Výbor nemůže editovat — karta vyplněna vlastníkem -->
<div style="background:var(--gray-lt);border-radius:var(--radius-sm);padding:1rem;text-align:center;color:var(--muted);font-size:13px">
  Karta vyplněna vlastníkem — editace není povolena.
</div>

<?php elseif ($canEdit): ?>
<!-- EDITAČNÍ BLOKY — výbor nebo superadmin -->
<?php if ($isSuperAdmin && ($o['updated_by_role']??'') === 'owner'): ?>
<div style="background:#f0e6fb;border:1px solid #c9a0e0;border-radius:var(--radius-sm);padding:.75rem 1rem;margin-bottom:1.25rem;font-size:13px;color:#6b11a5">
  🔑 Editujete kartu vyplněnou vlastníkem. Po uložení bude vlastníkovi odeslán e-mail.
</div>
<?php endif; ?>

<div class="profile-grid">

<div class="card card-owner">
  <div class="card-title">✏ Údaje vlastníka</div>
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="action" value="save_owner">
    <div class="form-group"><label>Jméno a příjmení *</label><input type="text" name="full_name" required value="<?= e($o['full_name']??'') ?>"></div>
    <div class="form-row">
      <div class="form-group">
        <label>Způsob užívání</label>
        <select name="residence">
          <?php foreach (['trvalé','pronájem','druhé bydliště','neuvedeno'] as $opt): ?>
            <option value="<?= $opt ?>" <?= ($o['residence']??'neuvedeno')===$opt?'selected':'' ?>><?= $opt ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Počet osob</label><input type="number" name="persons_count" min="0" max="20" value="<?= e($o['persons_count']??'') ?>"></div>
    </div>
    <div style="border:1px solid var(--border);border-radius:var(--radius-sm);padding:1rem;margin-bottom:1rem;background:var(--gray-lt)">
      <div style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.75rem">Kontaktní údaje</div>
      <div class="form-row">
        <div class="form-group"><label>E-mail 1</label><input type="email" name="email" value="<?= e($o['email']??'') ?>"></div>
        <div class="form-group"><label>E-mail 2</label><input type="email" name="email2" value="<?= e($o['email2']??'') ?>"></div>
      </div>
      <div style="font-size:13px;margin-bottom:.75rem">
        <span style="color:var(--muted);font-weight:500;margin-right:.5rem">Primární:</span>
        <label style="cursor:pointer;margin-right:1rem"><input type="radio" name="primary_email" value="1" <?= ($o['primary_email']??1)==1?'checked':'' ?>> E-mail 1</label>
        <label style="cursor:pointer"><input type="radio" name="primary_email" value="2" <?= ($o['primary_email']??1)==2?'checked':'' ?>> E-mail 2</label>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Telefon 1</label><input type="tel" name="phone" value="<?= e($o['phone']??'') ?>"></div>
        <div class="form-group"><label>Telefon 2</label><input type="tel" name="phone2" value="<?= e($o['phone2']??'') ?>"></div>
      </div>
      <div style="font-size:13px">
        <span style="color:var(--muted);font-weight:500;margin-right:.5rem">Primární:</span>
        <label style="cursor:pointer;margin-right:1rem"><input type="radio" name="primary_phone" value="1" <?= ($o['primary_phone']??1)==1?'checked':'' ?>> Telefon 1</label>
        <label style="cursor:pointer"><input type="radio" name="primary_phone" value="2" <?= ($o['primary_phone']??1)==2?'checked':'' ?>> Telefon 2</label>
      </div>
    </div>
    <div class="form-group"><label>Korespondenční adresa</label><input type="text" name="address" value="<?= e($o['address']??'') ?>"></div>
    <div class="form-group"><label>Vzkaz pro výbor</label><textarea name="note" style="min-height:70px"><?= e($o['note']??'') ?></textarea></div>
    <div class="check-row" style="margin-bottom:1rem">
      <input type="checkbox" id="gdpr" name="gdpr_consent" <?= !empty($o['gdpr_consent'])?'checked':'' ?>>
      <label for="gdpr" style="font-size:13px">Souhlas GDPR</label>
    </div>
    <button type="submit" class="btn btn-primary">Uložit kartu</button>
  </form>
</div>

<div class="card card-tenant">
  <div class="card-title">✏ Nájemník</div>
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="action" value="save_tenant">
    <div class="form-group"><label>Jméno a příjmení</label><input type="text" name="t_full_name" value="<?= e($t['full_name']??'') ?>"></div>
    <div class="form-group">
      <label>Počet osob <span style="color:var(--blue);font-size:11px">(nadřazený)</span></label>
      <input type="number" name="t_persons_count" min="0" max="20" value="<?= e($t['persons_count']??'') ?>">
    </div>
    <div style="border:1px solid var(--border);border-radius:var(--radius-sm);padding:1rem;margin-bottom:1rem;background:var(--gray-lt)">
      <div style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.75rem">Kontaktní údaje</div>
      <div class="form-row">
        <div class="form-group"><label>E-mail 1</label><input type="email" name="t_email" value="<?= e($t['email']??'') ?>"></div>
        <div class="form-group"><label>E-mail 2</label><input type="email" name="t_email2" value="<?= e($t['email2']??'') ?>"></div>
      </div>
      <div style="font-size:13px;margin-bottom:.75rem">
        <span style="color:var(--muted);font-weight:500;margin-right:.5rem">Primární:</span>
        <label style="cursor:pointer;margin-right:1rem"><input type="radio" name="t_primary_email" value="1" <?= ($t['primary_email']??1)==1?'checked':'' ?>> E-mail 1</label>
        <label style="cursor:pointer"><input type="radio" name="t_primary_email" value="2" <?= ($t['primary_email']??1)==2?'checked':'' ?>> E-mail 2</label>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Telefon 1</label><input type="tel" name="t_phone" value="<?= e($t['phone']??'') ?>"></div>
        <div class="form-group"><label>Telefon 2</label><input type="tel" name="t_phone2" value="<?= e($t['phone2']??'') ?>"></div>
      </div>
      <div style="font-size:13px">
        <span style="color:var(--muted);font-weight:500;margin-right:.5rem">Primární:</span>
        <label style="cursor:pointer;margin-right:1rem"><input type="radio" name="t_primary_phone" value="1" <?= ($t['primary_phone']??1)==1?'checked':'' ?>> Telefon 1</label>
        <label style="cursor:pointer"><input type="radio" name="t_primary_phone" value="2" <?= ($t['primary_phone']??1)==2?'checked':'' ?>> Telefon 2</label>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group"><label>Nájem od</label><input type="date" name="t_rent_from" value="<?= e($t['rent_from']??'') ?>"></div>
      <div class="form-group"><label>Nájem do</label><input type="date" name="t_rent_until" value="<?= e($t['rent_until']??'') ?>"></div>
    </div>
    <button type="submit" class="btn btn-primary">Uložit nájemníka</button>
  </form>
</div>

</div>



<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
