<?php
require_once __DIR__ . '/../includes/functions.php';
$user = requireLogin();
if ($user['role'] === 'admin' || $user['role'] === 'superadmin') { header('Location: /admin/dashboard.php'); exit; }
$pageTitle = 'Moje karta';
$db = db();

$owner = null; $unit = null; $garage = null; $tenant = null;
if ($user['unit_id']) {
    $s = $db->prepare('SELECT o.*, u.label AS unit_label, u.type AS unit_type, u.area_m2 FROM owners o JOIN units u ON o.unit_id=u.id WHERE o.unit_id=? LIMIT 1');
    $s->execute([$user['unit_id']]); $owner = $s->fetch();
    $s2 = $db->prepare('SELECT * FROM units WHERE id=? LIMIT 1');
    $s2->execute([$user['unit_id']]); $unit = $s2->fetch();
    $s3 = $db->prepare("SELECT * FROM units WHERE linked_unit_id=? AND type != 'byt' LIMIT 1");
    $s3->execute([$user['unit_id']]); $garage = $s3->fetch();
    $s4 = $db->prepare('SELECT * FROM tenants WHERE unit_id=? ORDER BY created_at DESC LIMIT 1');
    $s4->execute([$user['unit_id']]); $tenant = $s4->fetch();
}
$o = $owner ?? []; $t = $tenant ?? [];
$ownershipLabels = ['bezpodílové' => 'Jednoduché (jeden vlastník)', 'společné jmění manželů' => 'SJM (manželé)', 'podílové' => 'Podílové (více vlastníků)', 'neuvedeno' => 'Neuvedeno'];

// ── Technický popis jednotky ──────────────────────────────────────────────
$unit_rooms_data = [];
$unit_eq_data    = [];
$unit_info       = null;
if ($user['unit_id']) {
    try {
        $uq = $db->prepare("SELECT np, dispozice, vymera_m2, vymera_pozn FROM units WHERE id=?");
        $uq->execute([$user['unit_id']]);
        $unit_info = $uq->fetch();
        $rq = $db->prepare("SELECT nazev, vymera_m2, poznamka FROM unit_rooms WHERE unit_id=? ORDER BY order_num, id");
        $rq->execute([$user['unit_id']]);
        $unit_rooms_data = $rq->fetchAll();
        $eq = $db->prepare("SELECT polozka, pocet, poznamka FROM unit_equipment WHERE unit_id=? ORDER BY order_num, id");
        $eq->execute([$user['unit_id']]);
        $unit_eq_data = $eq->fetchAll();
    } catch (\PDOException $e) {}
}
$npLabels = [1=>'1. NP (přízemí)',2=>'2. NP (1. patro)',3=>'3. NP (2. patro)',4=>'4. NP (3. patro)',5=>'5. NP (4. patro)',6=>'6. NP (5. patro)',7=>'7. NP (6. patro)',8=>'8. NP (7. patro)'];

// ── Spotřeby ──────────────────────────────────────────────────────────────
$cons_roky = [];
$cons_rows = [];
if ($user['unit_id']) {
    try {
        $q1 = $db->prepare("SELECT DISTINCT rok FROM consumption WHERE unit_id = ? ORDER BY rok DESC");
        $q1->execute([$user['unit_id']]);
        $cons_roky = $q1->fetchAll(PDO::FETCH_COLUMN);
    } catch (\PDOException $e) {
        $cons_roky = [];
    }
}
// Výchozí rok = z URL, nebo nejnovější s daty, nebo aktuální rok
$cons_rok = (int)($_GET['cons_rok'] ?? ($cons_roky[0] ?? (int)date('Y')));
if ($user['unit_id']) {
    try {
        $q2 = $db->prepare("SELECT rok, mesic, typ, jednotka, hodnota_zacatek, hodnota_konec, spotreba FROM consumption WHERE unit_id = ? AND rok = ? ORDER BY mesic ASC, typ ASC");
        $q2->execute([$user['unit_id'], $cons_rok]);
        $cons_rows = $q2->fetchAll(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        $cons_rows = [];
    }
}
$cons_mesice = [1=>'Leden',2=>'Únor',3=>'Březen',4=>'Duben',5=>'Květen',6=>'Červen',7=>'Červenec',8=>'Srpen',9=>'Září',10=>'Říjen',11=>'Listopad',12=>'Prosinec'];
$cons_pivot  = [];
$cons_soucty = ['SV'=>0,'TV'=>0,'ITN'=>0];
foreach ($cons_rows as $r) { $cons_pivot[$r['mesic']][$r['typ']] = $r; $cons_soucty[$r['typ']] += $r['spotreba']; }

// Změna hesla
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    csrfCheck();
    $current = $_POST['current_password'] ?? ''; $new = $_POST['new_password'] ?? ''; $confirm = $_POST['confirm_password'] ?? '';
    $stmt = $db->prepare('SELECT password_hash FROM users WHERE id=?'); $stmt->execute([$user['id']]);
    $hash = $stmt->fetchColumn();
    if (!password_verify($current, $hash)) flash('Současné heslo není správné.', 'error');
    elseif (strlen($new) < 6) flash('Nové heslo musí mít alespoň 6 znaků.', 'error');
    elseif ($new !== $confirm) flash('Nová hesla se neshodují.', 'error');
    else { $db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($new, PASSWORD_BCRYPT), $user['id']]); flash('Heslo bylo změněno.', 'success'); }
    header('Location: /owner/profile.php'); exit;
}

// Uložit vlastníka
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_owner') {
    csrfCheck();
    $data = [
        'full_name'     => trim($_POST['full_name'] ?? ''),
        'residence'     => $_POST['residence'] ?? 'neuvedeno',
        'ownership_form'=> $_POST['ownership_form'] ?? 'neuvedeno',
        'unit_share_pct'=> ($_POST['unit_share_pct'] ?? '') !== '' ? (float)$_POST['unit_share_pct'] : null,
        'persons_count' => !empty($_POST['persons_count']) ? (int)$_POST['persons_count'] : null,
        'email'         => trim($_POST['email'] ?? '') ?: null,
        'email_verified'=> isset($_POST['email_verified']) ? 1 : 0,
        'notify_email'  => isset($_POST['notify_email']) ? 1 : 0,
        'phone'         => trim($_POST['phone'] ?? '') ?: null,
        'whatsapp'      => isset($_POST['whatsapp']) ? 1 : 0,
        'address'       => trim($_POST['address'] ?? '') ?: null,
        'note'          => trim($_POST['note'] ?? '') ?: null,
        'gdpr_consent'  => isset($_POST['gdpr_consent']) ? 1 : 0,
    ];
    $data['status'] = ownerStatus($data);
    if (!in_array($data['ownership_form'], ['podílové', 'společné jmění manželů'])) $data['unit_share_pct'] = null;

    $blockedPersonsCount = 0;
    if ($owner && !in_array($data['ownership_form'], ['podílové', 'společné jmění manželů'])) {
        $pc = $db->prepare('SELECT COUNT(*) FROM owner_persons WHERE owner_id=?');
        $pc->execute([$owner['id']]);
        $blockedPersonsCount = (int)$pc->fetchColumn();
    }

    if (!$data['full_name']) flash('Vyplňte jméno a příjmení.', 'error');
    elseif (!$data['gdpr_consent']) flash('Pro uložení je nutný souhlas se zpracováním osobních údajů.', 'error');
    elseif ($blockedPersonsCount > 0) flash('Nelze přepnout na "' . $ownershipLabels[$data['ownership_form']] . '" — máte stále evidované další vlastníky. Nejdřív je smažte níže.', 'error');
    else {
        if ($owner) {
            $db->prepare('UPDATE owners SET full_name=?,residence=?,ownership_form=?,unit_share_pct=?,persons_count=?,email=?,email_verified=?,notify_email=?,phone=?,whatsapp=?,address=?,note=?,gdpr_consent=?,status=?,updated_by_role=? WHERE id=?')
               ->execute([$data['full_name'],$data['residence'],$data['ownership_form'],$data['unit_share_pct'],$data['persons_count'],$data['email'],$data['email_verified'],$data['notify_email'],$data['phone'],$data['whatsapp'],$data['address'],$data['note'],$data['gdpr_consent'],$data['status'],'owner',$owner['id']]);
        } else {
            $db->prepare('INSERT INTO owners (unit_id,full_name,residence,ownership_form,unit_share_pct,persons_count,email,email_verified,notify_email,phone,whatsapp,address,note,gdpr_consent,gdpr_date,status,updated_by_role) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
               ->execute([$user['unit_id'],$data['full_name'],$data['residence'],$data['ownership_form'],$data['unit_share_pct'],$data['persons_count'],$data['email'],$data['email_verified'],$data['notify_email'],$data['phone'],$data['whatsapp'],$data['address'],$data['note'],$data['gdpr_consent'],date('Y-m-d H:i:s'),$data['status'],'owner']);
        }
        flash('Vaše karta byla uložena.', 'success');
    }
    header('Location: /owner/profile.php'); exit;
}

// Další vlastník (SJM / podílové)
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
    header('Location: /owner/profile.php#block-owner'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_person' && $owner) {
    csrfCheck();
    $db->prepare('DELETE FROM owner_persons WHERE id=? AND owner_id=?')->execute([(int)$_POST['person_id'], $owner['id']]);
    flash('Další vlastník smazán.', 'success');
    header('Location: /owner/profile.php#block-owner'); exit;
}

// Uložit nájemníka / osobu s věcným břemenem
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_tenant') {
    csrfCheck();
    $tname = trim($_POST['t_full_name'] ?? '');
    $tTyp  = in_array($_POST['t_typ'] ?? '', ['najem','vecne_bremeno']) ? $_POST['t_typ'] : 'najem';
    $tPersons = !empty($_POST['t_persons_count']) ? (int)$_POST['t_persons_count'] : null;
    if ($tPersons && $owner) $db->prepare('UPDATE owners SET persons_count=? WHERE id=?')->execute([$tPersons, $owner['id']]);
    if ($tname && $user['unit_id']) {
        $tdata = [$tTyp, $tname,
            trim($_POST['t_email']??'')?: null, isset($_POST['t_email_verified']) ? 1 : 0, isset($_POST['t_notify_email']) ? 1 : 0,
            trim($_POST['t_phone']??'')?: null, isset($_POST['t_whatsapp']) ? 1 : 0,
            $_POST['t_rent_from']?: null, $_POST['t_rent_until']?: null, $tPersons];
        if ($tenant) {
            $db->prepare('UPDATE tenants SET typ=?,full_name=?,email=?,email_verified=?,notify_email=?,phone=?,whatsapp=?,rent_from=?,rent_until=?,persons_count=? WHERE id=?')
               ->execute([...$tdata, $tenant['id']]);
        } else {
            $db->prepare('INSERT INTO tenants (unit_id,typ,full_name,email,email_verified,notify_email,phone,whatsapp,rent_from,rent_until,persons_count) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
               ->execute([$user['unit_id'], ...$tdata]);
        }
        flash('Uloženo.', 'success');
    }
    header('Location: /owner/profile.php'); exit;
}

$ownerPersons = [];
if ($owner) {
    $stmt = $db->prepare('SELECT * FROM owner_persons WHERE owner_id=? ORDER BY id');
    $stmt->execute([$owner['id']]);
    $ownerPersons = $stmt->fetchAll();
}

// Součet podílů na jednotce (hlavní vlastník + další vlastníci) — má být 100 %
$sharePctSum = null;
if ($owner && in_array($owner['ownership_form'] ?? 'neuvedeno', ['podílové', 'společné jmění manželů'])) {
    $sharePctSum = (float)($owner['unit_share_pct'] ?? 0);
    foreach ($ownerPersons as $pp) $sharePctSum += (float)($pp['unit_share_pct'] ?? 0);
}

include __DIR__ . '/../includes/header.php';
?>

<style>
.profile-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;align-items:start}
.block{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden}
.block-owner{border-top:4px solid var(--blue)}
.block-owner .block-label{color:var(--blue)}
.block-tenant{border-top:4px solid var(--green)}
.block-tenant .block-label{color:var(--green)}
.block-garage{border-top:4px solid var(--amber)}
.block-garage .block-label{color:var(--amber)}
.block-header{display:flex;justify-content:space-between;align-items:center;padding:1rem 1.25rem;cursor:default}
.block-label{font-size:14px;font-weight:600}
.summary-body{padding:0 1.25rem 1rem}
.summary-val{font-size:14px;color:var(--text);margin-bottom:4px}
.edit-form{display:none;padding:1.25rem;border-top:1px solid var(--border);background:var(--gray-lt)}
.edit-form.open{display:block}
.contact-box{border:1px solid var(--border);border-radius:var(--radius-sm);padding:1rem;margin-bottom:1rem;background:var(--card)}
.contact-box-label{font-size:13px;font-weight:600;color:var(--muted);margin-bottom:.75rem}
@media(max-width:700px){.profile-grid{grid-template-columns:1fr}}
</style>

<?php if ($owner): ?>
<div class="page-hd" style="justify-content:flex-end">
  <span class="badge <?= $owner['status']==='úplná'?'badge-ok':($owner['status']==='neúplná'?'badge-partial':'badge-miss') ?>"><?= e($owner['status']) ?></span>
</div>
<?php endif; ?>

<!-- Info o jednotce -->
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:1.25rem">
  <?php if ($unit): ?>
  <div style="background:var(--blue-lt);border-radius:var(--radius-sm);padding:.6rem 1rem;font-size:13px;color:var(--blue);font-weight:500">
    🏠 <strong><?= e($unit['label']) ?></strong> (<?= e($unit['type']) ?>)<?= $unit['vymera_m2'] ? ' · '.$unit['vymera_m2'].' m²' : '' ?>
  </div>
  <?php endif; ?>
  <?php if ($garage): ?>
  <div style="background:var(--amber-lt);border-radius:var(--radius-sm);padding:.6rem 1rem;font-size:13px;color:var(--amber);font-weight:500">
    🚗 Garáž: <strong><?= e($garage['label']) ?></strong><?= $garage['vymera_m2'] ? ' · '.$garage['vymera_m2'].' m²' : '' ?>
  </div>
  <?php endif; ?>
</div>

<div class="profile-grid" style="margin-bottom:1.25rem">

  <!-- BLOK VLASTNÍK -->
  <div class="block block-owner" id="block-owner">
    <div class="block-header">
      <span class="block-label">👤 Vlastník</span>
      <button type="button" class="btn btn-secondary btn-sm" onclick="toggleBlock('block-owner')">Editovat</button>
    </div>
    <div class="summary-body" id="summary-owner">
      <?php if ($o): ?>
        <div class="summary-val"><strong><?= e($o['full_name'] ?? '') ?></strong></div>
        <?php if ($o['residence'] ?? ''): ?><div class="summary-val" style="color:var(--muted)"><?= e($o['residence']) ?><?= ($o['persons_count'] ?? '') ? ' · '.$o['persons_count'].' os.' : '' ?></div><?php endif; ?>
        <?php if ($o['email'] ?? ''): ?><div class="summary-val">✉️ <?= e($o['email']) ?><?= !empty($o['email_verified']) ? ' ✓' : '' ?></div><?php endif; ?>
        <?php if ($o['phone'] ?? ''): ?><div class="summary-val">📞 <?= e($o['phone']) ?><?= !empty($o['whatsapp']) ? ' 💬' : '' ?></div><?php endif; ?>
        <?php if ($o['address'] ?? ''): ?><div class="summary-val" style="font-size:13px;color:var(--muted)">🏡 <?= e($o['address']) ?></div><?php endif; ?>
        <?php foreach ($ownerPersons as $p): ?>
          <div class="summary-val" style="font-size:13px;color:var(--muted)">👥 <?= e($p['full_name']) ?><?= $p['relation'] ? ' ('.e($p['relation']).')' : '' ?><?= $p['unit_share_pct'] !== null ? ' — '.e($p['unit_share_pct']).' %' : '' ?></div>
        <?php endforeach; ?>
      <?php else: ?>
        <div style="color:var(--muted);font-size:13px">Karta není vyplněna — klikněte Editovat</div>
      <?php endif; ?>
    </div>
    <div class="edit-form" id="edit-owner">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="save_owner">
        <div class="form-group"><label>Jméno a příjmení (hlavní vlastník) *</label><input type="text" name="full_name" required value="<?= e($o['full_name'] ?? '') ?>"></div>
        <?php $isSplitOwnership = in_array($o['ownership_form'] ?? 'neuvedeno', ['podílové', 'společné jmění manželů']); ?>
        <div class="form-row">
          <div class="form-group">
            <label>Vlastnictví</label>
            <select name="ownership_form" id="ownership-select" onchange="document.getElementById('share-pct-box').style.display = (this.value==='podílové'||this.value==='společné jmění manželů') ? 'block' : 'none'">
              <?php foreach ($ownershipLabels as $val => $lbl): ?>
                <option value="<?= e($val) ?>" <?= ($o['ownership_form']??'neuvedeno')===$val?'selected':'' ?>><?= e($lbl) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Způsob užívání jednotky</label>
            <select name="residence">
              <?php foreach (['vlastní','pronájem','věcné břemeno','neuvedeno'] as $opt): ?>
                <option value="<?= $opt ?>" <?= ($o['residence']??'neuvedeno')===$opt?'selected':'' ?>><?= $opt ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group"><label>Počet osob v jednotce</label><input type="number" name="persons_count" min="0" max="20" value="<?= e($o['persons_count'] ?? '') ?>"></div>
          <div class="form-group" id="share-pct-box" style="display:<?= $isSplitOwnership ? 'block' : 'none' ?>">
            <label>Můj podíl na jednotce (%)</label>
            <input type="number" step="0.01" min="0" max="100" name="unit_share_pct" placeholder="50.00" value="<?= e($o['unit_share_pct'] ?? '') ?>">
          </div>
        </div>
        <div class="form-group"><label>Korespondenční adresa</label><input type="text" name="address" value="<?= e($o['address'] ?? '') ?>"></div>
        <div class="contact-box">
          <div class="contact-box-label">Kontaktní údaje</div>
          <div class="form-group"><label>E-mail</label><input type="email" name="email" value="<?= e($o['email'] ?? '') ?>"></div>
          <div style="font-size:13px;margin-bottom:.75rem;display:flex;gap:1.25rem">
            <label style="cursor:pointer"><input type="checkbox" name="email_verified" <?= !empty($o['email_verified']) ? 'checked' : '' ?>> Ověřeno</label>
            <label style="cursor:pointer"><input type="checkbox" name="notify_email" <?= !isset($o['notify_email']) || $o['notify_email'] ? 'checked' : '' ?>> Používat pro odesílání informací</label>
          </div>
          <div class="form-group"><label>Telefon</label><input type="tel" name="phone" value="<?= e($o['phone'] ?? '') ?>"></div>
          <div style="font-size:13px">
            <label style="cursor:pointer"><input type="checkbox" name="whatsapp" <?= !empty($o['whatsapp']) ? 'checked' : '' ?>> Používat pro WhatsApp</label>
          </div>
        </div>
        <div class="form-group"><label>Vzkaz pro výbor</label><textarea name="note" style="min-height:70px"><?= e($o['note'] ?? '') ?></textarea></div>
        <div class="check-row" style="margin-bottom:1rem">
          <input type="checkbox" id="gdpr" name="gdpr_consent" required <?= !empty($o['gdpr_consent'])?'checked':'' ?>>
          <label for="gdpr" style="font-size:13px">Souhlasím se zpracováním osobních údajů (GDPR)</label>
        </div>
        <div style="display:flex;gap:8px">
          <button type="submit" class="btn btn-primary">Uložit</button>
          <button type="button" class="btn btn-secondary" onclick="toggleBlock('block-owner')">Zrušit</button>
        </div>
      </form>

      <?php if ($owner): ?>
      <!-- Další vlastníci (SJM / podílové) -->
      <div style="border-top:1px solid var(--border);margin-top:1.25rem;padding-top:1rem">
        <div style="font-size:13px;font-weight:600;color:var(--blue);margin-bottom:.5rem">👥 Další vlastníci (SJM / podílové)</div>
        <?php if ($sharePctSum !== null): ?>
        <div style="font-size:13px;font-weight:600;margin-bottom:.75rem;padding:.5rem .75rem;border-radius:var(--radius-sm);<?= abs($sharePctSum - 100) < 0.01 ? 'background:var(--green-lt);color:var(--green)' : 'background:var(--red-lt);color:var(--red)' ?>">
          <?= abs($sharePctSum - 100) < 0.01 ? '✓' : '⚠' ?> Součet podílů: <?= number_format($sharePctSum, 2, ',', ' ') ?> % <?= abs($sharePctSum - 100) < 0.01 ? '' : '(mělo by být 100 %)' ?>
        </div>
        <?php endif; ?>
        <?php if ($ownerPersons): ?>
          <?php foreach ($ownerPersons as $p): ?>
          <div style="display:flex;justify-content:space-between;align-items:center;background:var(--gray-lt);border-radius:var(--radius-sm);padding:.5rem .75rem;margin-bottom:.5rem;font-size:13px">
            <div>
              <strong><?= e($p['full_name']) ?></strong><?= $p['relation'] ? ' — '.e($p['relation']) : '' ?>
              <?php if ($p['unit_share_pct'] !== null): ?><strong style="color:var(--blue)"> <?= e($p['unit_share_pct']) ?> %</strong><?php endif; ?>
              <?php if ($p['email'] || $p['phone']): ?><br><span style="color:var(--muted)"><?= e($p['email'] ?: '') ?><?= $p['email'] && $p['phone'] ? ' · ' : '' ?><?= e($p['phone'] ?: '') ?></span><?php endif; ?>
            </div>
            <form method="POST" onsubmit="return confirm('Smazat?')">
              <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
              <input type="hidden" name="action" value="delete_person">
              <input type="hidden" name="person_id" value="<?= $p['id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm">✕</button>
            </form>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
        <details>
          <summary style="font-size:13px;color:var(--blue);font-weight:600;cursor:pointer;margin-bottom:.5rem">+ Přidat dalšího vlastníka</summary>
          <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="save_person">
            <input type="hidden" name="person_id" value="0">
            <div class="form-row">
              <div class="form-group"><label>Jméno a příjmení *</label><input type="text" name="p_full_name" required></div>
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
            <div class="form-group"><label>Adresa</label><input type="text" name="p_address" placeholder="pokud se liší"></div>
            <button type="submit" class="btn btn-primary btn-sm">Přidat</button>
          </form>
        </details>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- BLOK NÁJEMNÍK -->
  <div class="block block-tenant" id="block-tenant">
    <div class="block-header">
      <span class="block-label"><?= ($t['typ'] ?? 'najem') === 'vecne_bremeno' ? '⚖️ Věcné břemeno' : '🏠 Nájemník' ?></span>
      <button type="button" class="btn btn-secondary btn-sm" onclick="toggleBlock('block-tenant')">Editovat</button>
    </div>
    <div class="summary-body" id="summary-tenant">
      <?php if ($tenant): ?>
        <div class="summary-val"><strong><?= e($t['full_name']) ?></strong></div>
        <?php if ($t['persons_count']): ?><div class="summary-val" style="color:var(--muted)"><?= $t['persons_count'] ?> os.</div><?php endif; ?>
        <?php if ($t['email'] ?? ''): ?><div class="summary-val">✉️ <?= e($t['email']) ?><?= !empty($t['email_verified']) ? ' ✓' : '' ?></div><?php endif; ?>
        <?php if ($t['phone'] ?? ''): ?><div class="summary-val">📞 <?= e($t['phone']) ?><?= !empty($t['whatsapp']) ? ' 💬' : '' ?></div><?php endif; ?>
        <?php if ($t['rent_from']||$t['rent_until']): ?>
          <div class="summary-val" style="font-size:13px;color:var(--muted)">
            <?= ($t['rent_from']?'od '.date('j.n.Y',strtotime($t['rent_from'])):'') ?>
            <?= ($t['rent_until']?' do '.date('j.n.Y',strtotime($t['rent_until'])):'') ?>
          </div>
        <?php endif; ?>
      <?php else: ?>
        <div style="color:var(--muted);font-size:13px">Jednotka je ve vlastním užívání — klikněte Editovat pro zadání nájemníka nebo osoby s věcným břemenem</div>
      <?php endif; ?>
    </div>
    <div class="edit-form" id="edit-tenant">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="save_tenant">
        <div class="form-group">
          <label>Typ</label>
          <select name="t_typ">
            <option value="najem" <?= ($t['typ']??'najem')==='najem'?'selected':'' ?>>Nájem</option>
            <option value="vecne_bremeno" <?= ($t['typ']??'')==='vecne_bremeno'?'selected':'' ?>>Věcné břemeno</option>
          </select>
        </div>
        <div class="form-group"><label>Jméno a příjmení</label><input type="text" name="t_full_name" value="<?= e($t['full_name'] ?? '') ?>"></div>
        <div class="form-group">
          <label>Počet osob <span style="color:var(--blue);font-size:11px">(nadřazený)</span></label>
          <input type="number" name="t_persons_count" min="0" max="20" value="<?= e($t['persons_count'] ?? '') ?>">
        </div>
        <div class="contact-box">
          <div class="contact-box-label">Kontaktní údaje</div>
          <div class="form-group"><label>E-mail</label><input type="email" name="t_email" value="<?= e($t['email'] ?? '') ?>"></div>
          <div style="font-size:13px;margin-bottom:.75rem;display:flex;gap:1.25rem">
            <label style="cursor:pointer"><input type="checkbox" name="t_email_verified" <?= !empty($t['email_verified']) ? 'checked' : '' ?>> Ověřeno</label>
            <label style="cursor:pointer"><input type="checkbox" name="t_notify_email" <?= !isset($t['notify_email']) || $t['notify_email'] ? 'checked' : '' ?>> E-mail pro informace</label>
          </div>
          <div class="form-group"><label>Telefon</label><input type="tel" name="t_phone" value="<?= e($t['phone'] ?? '') ?>"></div>
          <div style="font-size:13px">
            <label style="cursor:pointer"><input type="checkbox" name="t_whatsapp" <?= !empty($t['whatsapp']) ? 'checked' : '' ?>> WhatsApp</label>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group"><label>Nájem od</label><input type="date" name="t_rent_from" value="<?= e($t['rent_from'] ?? '') ?>"></div>
          <div class="form-group"><label>Nájem do</label><input type="date" name="t_rent_until" value="<?= e($t['rent_until'] ?? '') ?>"></div>
        </div>
        <div style="display:flex;gap:8px">
          <button type="submit" class="btn btn-primary">Uložit</button>
          <button type="button" class="btn btn-secondary" onclick="toggleBlock('block-tenant')">Zrušit</button>
        </div>
      </form>
    </div>
  </div>

</div>

<!-- BLOK GARÁŽ (informativní) -->
<?php if ($garage): ?>
<?php
  $garageOwner = $db->prepare('SELECT * FROM owners WHERE unit_id=? LIMIT 1');
  $garageOwner->execute([$garage['id']]);
  $garageOwner = $garageOwner->fetch();
?>
<div class="block block-garage" style="margin-bottom:1.25rem">
  <div class="block-header">
    <span class="block-label">🚗 Garáž <?= e($garage['label']) ?></span>
  </div>
  <div class="summary-body">
    <?php if ($garageOwner && $garageOwner['full_name']): ?>
      <div class="summary-val"><strong><?= e($garageOwner['full_name']) ?></strong></div>
      <?php if ($garageOwner['email']): ?><div class="summary-val">✉️ <?= e($garageOwner['email']) ?></div><?php endif; ?>
    <?php else: ?>
      <div style="color:var(--muted);font-size:13px">Garáž je příslušenstvím vašeho bytu.</div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- BLOK TECHNICKÝ POPIS JEDNOTKY -->
<?php if ($unit_info && ($unit_info['dispozice'] || $unit_rooms_data || $unit_eq_data)): ?>
<div class="block" style="border-top:4px solid var(--blue);margin-bottom:1.25rem">
  <div class="block-header" style="cursor:default">
    <span class="block-label" style="color:var(--blue)">🏠 Technický popis jednotky</span>
    <?php if ($unit_info['dispozice'] || $unit_info['vymera_m2']): ?>
    <span style="font-size:13px;color:var(--muted)">
      <?= e($unit_info['dispozice'] ?? '') ?>
      <?= $unit_info['vymera_m2'] ? '&nbsp;·&nbsp;'.$unit_info['vymera_m2'].' m²' : '' ?>
      <?= $unit_info['np'] ? '&nbsp;·&nbsp;'.($npLabels[$unit_info['np']] ?? $unit_info['np'].'. NP') : '' ?>
    </span>
    <?php endif; ?>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;padding:0 1.25rem 1.25rem">

    <!-- Místnosti -->
    <?php if ($unit_rooms_data): ?>
    <div>
      <div style="font-size:12px;font-weight:600;color:var(--green);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.5rem">🚪 Místnosti</div>
      <table style="width:100%;border-collapse:collapse;font-size:13px">
        <?php foreach ($unit_rooms_data as $r): ?>
        <tr style="border-bottom:1px solid var(--border)">
          <td style="padding:4px 0"><?= e($r['nazev']) ?></td>
          <td style="text-align:right;font-weight:600;padding:4px 0;color:var(--green)">
            <?= $r['vymera_m2'] !== null ? number_format($r['vymera_m2'], 2, ',', ' ').' m²' : '' ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php $tv = array_sum(array_column($unit_rooms_data, 'vymera_m2')); if ($tv > 0): ?>
        <tr style="font-weight:700;background:var(--gray-lt)">
          <td style="padding:5px 0">Celkem</td>
          <td style="text-align:right;padding:5px 0;color:var(--green)"><?= number_format($tv, 2, ',', ' ') ?> m²</td>
        </tr>
        <?php endif; ?>
      </table>
    </div>
    <?php endif; ?>

    <!-- Vybavení -->
    <?php if ($unit_eq_data): ?>
    <div>
      <div style="font-size:12px;font-weight:600;color:var(--amber);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.5rem">🔧 Vybavení</div>
      <table style="width:100%;border-collapse:collapse;font-size:13px">
        <?php foreach ($unit_eq_data as $eq): ?>
        <tr style="border-bottom:1px solid var(--border)">
          <td style="padding:4px 0"><?= e($eq['polozka']) ?></td>
          <td style="text-align:right;font-weight:600;padding:4px 0;color:var(--amber)"><?= $eq['pocet'] ?> ks</td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
    <?php endif; ?>

  </div>
</div>
<?php endif; ?>

<!-- BLOK SPOTŘEBY -->
<?php if ($user['unit_id']): ?>
<div class="block" style="border-top:4px solid var(--blue);margin-bottom:1.25rem">
  <div class="block-header" style="cursor:default">
    <span class="block-label" style="color:var(--blue)">📊 Spotřeby</span>
    <?php if (count($cons_roky) > 1): ?>
    <div style="display:flex;gap:5px">
      <?php foreach (array_reverse($cons_roky) as $r): ?>
      <a href="?cons_rok=<?= $r ?>" class="btn btn-sm <?= $r==$cons_rok?'btn-primary':'btn-secondary' ?>"><?= $r ?></a>
      <?php endforeach; ?>
    </div>
    <?php elseif ($cons_roky): ?>
      <span style="font-size:12px;color:var(--muted)"><?= $cons_rok ?></span>
    <?php endif; ?>
  </div>

  <?php if (!$cons_pivot): ?>
  <div class="summary-body">
    <div style="color:var(--muted);font-size:13px">Spotřeby zatím nejsou k dispozici.</div>
  </div>
  <?php else: ?>

  <!-- Souhrnné boxy -->
  <div style="display:flex;gap:8px;flex-wrap:wrap;padding:0 1.25rem 1rem">
    <div style="background:var(--gray-lt);border-radius:8px;padding:8px 14px;flex:1;min-width:100px">
      <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em">🚿 St. voda</div>
      <div style="font-size:1.3rem;font-weight:700;color:var(--blue)"><?= number_format($cons_soucty['SV'],3,',','&nbsp;') ?> <span style="font-size:11px;font-weight:400">m³</span></div>
    </div>
    <div style="background:var(--gray-lt);border-radius:8px;padding:8px 14px;flex:1;min-width:100px">
      <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em">🌡️ Teplá voda</div>
      <div style="font-size:1.3rem;font-weight:700;color:var(--red)"><?= number_format($cons_soucty['TV'],3,',','&nbsp;') ?> <span style="font-size:11px;font-weight:400">m³</span></div>
    </div>
    <div style="background:var(--gray-lt);border-radius:8px;padding:8px 14px;flex:1;min-width:100px">
      <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em">🔥 Teplo</div>
      <div style="font-size:1.3rem;font-weight:700;color:var(--amber)"><?= number_format($cons_soucty['ITN'],0,',','&nbsp;') ?> <span style="font-size:11px;font-weight:400">dílků</span></div>
    </div>
  </div>

  <!-- Měsíční tabulka -->
  <div style="padding:0 1.25rem 1.25rem;overflow-x:auto">
  <table class="tbl" style="font-size:12px">
    <thead>
      <tr>
        <th>Měsíc</th>
        <th colspan="2" style="text-align:center;color:var(--blue)">🚿 St. voda (m³)</th>
        <th colspan="2" style="text-align:center;color:var(--red)">🌡️ Teplá voda (m³)</th>
        <th colspan="2" style="text-align:center;color:var(--amber)">🔥 Teplo (dílků)</th>
      </tr>
      <tr style="font-size:10px;color:var(--muted)">
        <th></th>
        <th style="text-align:right">Spotřeba</th><th style="text-align:right">Stav</th>
        <th style="text-align:right">Spotřeba</th><th style="text-align:right">Stav</th>
        <th style="text-align:right">Spotřeba</th><th style="text-align:right">Stav</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($cons_mesice as $m => $nazev):
        if (!isset($cons_pivot[$m])) continue;
        $sv  = $cons_pivot[$m]['SV']  ?? null;
        $tv  = $cons_pivot[$m]['TV']  ?? null;
        $itn = $cons_pivot[$m]['ITN'] ?? null;
    ?>
    <tr>
      <td style="font-weight:500"><?= $nazev ?></td>
      <td style="text-align:right;font-weight:600;color:var(--blue)"><?= $sv  ? number_format($sv['spotreba'],3,',','&nbsp;')  : '–' ?></td>
      <td style="text-align:right;font-size:11px;color:var(--muted)"><?= $sv  ? number_format($sv['hodnota_konec'],3,',','&nbsp;')  : '' ?></td>
      <td style="text-align:right;font-weight:600;color:var(--red)"><?= $tv  ? number_format($tv['spotreba'],3,',','&nbsp;')  : '–' ?></td>
      <td style="text-align:right;font-size:11px;color:var(--muted)"><?= $tv  ? number_format($tv['hodnota_konec'],3,',','&nbsp;')  : '' ?></td>
      <td style="text-align:right;font-weight:600;color:var(--amber)"><?= $itn ? number_format($itn['spotreba'],0,',','&nbsp;') : '–' ?></td>
      <td style="text-align:right;font-size:11px;color:var(--muted)"><?= $itn ? number_format($itn['hodnota_konec'],0,',','&nbsp;') : '' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr style="font-weight:700;background:var(--gray-lt)">
        <td>Celkem <?= $cons_rok ?></td>
        <td style="text-align:right;color:var(--blue)"><?= number_format($cons_soucty['SV'],3,',','&nbsp;') ?></td><td></td>
        <td style="text-align:right;color:var(--red)"><?= number_format($cons_soucty['TV'],3,',','&nbsp;') ?></td><td></td>
        <td style="text-align:right;color:var(--amber)"><?= number_format($cons_soucty['ITN'],0,',','&nbsp;') ?></td><td></td>
      </tr>
    </tfoot>
  </table>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- BLOK HESLO -->
<div class="block" style="max-width:600px;border-top:4px solid var(--gray);margin-bottom:1.25rem" id="block-password">
  <div class="block-header">
    <span class="block-label" style="color:var(--gray)">🔒 Změna hesla</span>
    <button type="button" class="btn btn-secondary btn-sm" onclick="toggleBlock('block-password')">Změnit</button>
  </div>
  <div class="edit-form" id="edit-password">
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="change_password">
      <div class="form-group">
        <label>Současné heslo *</label>
        <div style="position:relative">
          <input type="password" name="current_password" required id="pw1" style="padding-right:40px">
          <button type="button" onclick="togglePw('pw1')" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);border:none;background:none;cursor:pointer;font-size:16px;color:var(--muted)">👁</button>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Nové heslo *</label>
          <div style="position:relative">
            <input type="password" name="new_password" required minlength="6" id="pw2" style="padding-right:40px">
            <button type="button" onclick="togglePw('pw2')" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);border:none;background:none;cursor:pointer;font-size:16px;color:var(--muted)">👁</button>
          </div>
        </div>
        <div class="form-group">
          <label>Nové heslo znovu *</label>
          <div style="position:relative">
            <input type="password" name="confirm_password" required minlength="6" id="pw3" style="padding-right:40px">
            <button type="button" onclick="togglePw('pw3')" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);border:none;background:none;cursor:pointer;font-size:16px;color:var(--muted)">👁</button>
          </div>
        </div>
      </div>
      <div style="display:flex;gap:8px">
        <button type="submit" class="btn btn-primary">Změnit heslo</button>
        <button type="button" class="btn btn-secondary" onclick="toggleBlock('block-password')">Zrušit</button>
      </div>
    </form>
  </div>
</div>

<script>
function toggleBlock(id) {
  var block = document.getElementById(id);
  var editId = 'edit-' + id.replace('block-', '');
  var edit = document.getElementById(editId);
  if (edit) edit.classList.toggle('open');
}
function togglePw(id) {
  var i = document.getElementById(id);
  i.type = i.type === 'password' ? 'text' : 'password';
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
