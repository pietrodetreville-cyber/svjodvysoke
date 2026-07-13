<?php
require_once __DIR__ . '/../includes/functions.php';
$user = requireAdmin();
$pageTitle = 'Orgány SVJ';
$db = db();

$roles = [
    'predseda'      => 'Předseda výboru',
    'mistopredseda' => 'Místopředseda výboru',
    'clen_vyboru'   => 'Člen výboru',
    'predseda_kk'   => 'Předseda kontrolní komise',
    'clen_kk'       => 'Člen kontrolní komise',
];
$roleGroups = [
    'Výbor SVJ'        => ['predseda','mistopredseda','clen_vyboru'],
    'Kontrolní komise' => ['predseda_kk','clen_kk'],
];
$roleIcons = [
    'predseda'      => '👑',
    'mistopredseda' => '⭐',
    'clen_vyboru'   => '👤',
    'predseda_kk'   => '👑',
    'clen_kk'       => '👤',
];
$roleColors = [
    'predseda'      => '#185FA5',
    'mistopredseda' => '#3B6D11',
    'clen_vyboru'   => '#185FA5',
    'predseda_kk'   => '#854F0B',
    'clen_kk'       => '#854F0B',
];

// Akce
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    csrfCheck();
    $personType = $_POST['person_type'] ?? 'owner';
    $ownerId  = ($personType === 'owner'  && !empty($_POST['owner_source_id']))  ? (int)$_POST['owner_source_id']  : null;
    $tenantId = ($personType === 'tenant' && !empty($_POST['tenant_source_id'])) ? (int)$_POST['tenant_source_id'] : null;
    $sourceId = $ownerId ?? $tenantId ?? 0;
    $fullName = trim($_POST['full_name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    if ($sourceId && !$fullName) {
        $tbl = $personType === 'owner' ? 'owners' : 'tenants';
        $s = $db->prepare("SELECT full_name, email, phone FROM $tbl WHERE id=?");
        $s->execute([$sourceId]);
        $src = $s->fetch();
        $fullName = $src['full_name'] ?? '';
        $email    = $email ?: ($src['email'] ?? '');
        $phone    = $phone ?: ($src['phone'] ?? '');
    }
    $db->prepare('INSERT INTO committee (person_type,owner_id,tenant_id,full_name,email,phone,role,valid_from,valid_until,note) VALUES (?,?,?,?,?,?,?,?,?,?)')
       ->execute([$personType, $ownerId, $tenantId, $fullName, $email ?: null, $phone ?: null, $_POST['role'], $_POST['valid_from'] ?: null, $_POST['valid_until'] ?: null, trim($_POST['note'] ?? '') ?: null]);
    flash('Člen přidán.', 'success');
    header('Location: /admin/committee.php'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    csrfCheck();
    $db->prepare('UPDATE committee SET full_name=?,email=?,phone=?,role=?,valid_from=?,valid_until=?,note=? WHERE id=?')
       ->execute([trim($_POST['full_name']), trim($_POST['email']) ?: null, trim($_POST['phone']) ?: null, $_POST['role'], $_POST['valid_from'] ?: null, $_POST['valid_until'] ?: null, trim($_POST['note'] ?? '') ?: null, (int)$_POST['id']]);
    flash('Člen upraven.', 'success');
    header('Location: /admin/committee.php'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrfCheck();
    $db->prepare('DELETE FROM committee WHERE id=?')->execute([(int)$_POST['id']]);
    flash('Člen odebrán.', 'success');
    header('Location: /admin/committee.php'); exit;
}

$editing = null;
if (isset($_GET['edit'])) {
    $s = $db->prepare('SELECT * FROM committee WHERE id=?');
    $s->execute([(int)$_GET['edit']]);
    $editing = $s->fetch();
}

$owners  = $db->query("SELECT o.id, o.full_name, o.email, o.phone, u.label FROM owners o JOIN units u ON o.unit_id=u.id ORDER BY CAST(SUBSTRING_INDEX(u.label,'/',1) AS UNSIGNED), CAST(SUBSTRING_INDEX(u.label,'/',-1) AS UNSIGNED)")->fetchAll();
$tenants = $db->query("SELECT t.id, t.full_name, t.email, t.phone, u.label FROM tenants t JOIN units u ON t.unit_id=u.id ORDER BY u.label")->fetchAll();

$members = $db->query(
    "SELECT c.*, u.label AS unit_label FROM committee c
     LEFT JOIN owners o ON c.owner_id=o.id
     LEFT JOIN units u ON o.unit_id=u.id
     ORDER BY FIELD(c.role,'predseda','mistopredseda','clen_vyboru','predseda_kk','clen_kk'), c.valid_from DESC"
)->fetchAll();

$grouped = [];
foreach ($members as $m) $grouped[$m['role']][] = $m;

// Owners jako JSON pro JS autocomplete
$ownersJson = json_encode(array_map(fn($o) => ['id'=>$o['id'],'name'=>$o['full_name'],'email'=>$o['email'],'phone'=>$o['phone'],'label'=>$o['label']], $owners));
$tenantsJson = json_encode(array_map(fn($t) => ['id'=>$t['id'],'name'=>$t['full_name'],'email'=>$t['email'],'phone'=>$t['phone'],'label'=>$t['label']], $tenants));

include __DIR__ . '/../includes/header.php';
?>

<style>
.member-card{background:#fff;border:1px solid var(--border);border-radius:12px;padding:1rem;display:flex;align-items:center;gap:12px;transition:box-shadow .15s}
.member-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.08)}
.member-avatar{width:48px;height:48px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0}
.member-role{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em}
.group-header{font-size:13px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:.75rem;padding-bottom:.5rem;border-bottom:2px solid var(--border)}
.committee-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;align-items:start;margin-bottom:1.5rem}
@media(max-width:700px){.committee-grid{grid-template-columns:1fr}}
</style>

<div class="page-hd"><h1>Orgány SVJ</h1></div>

<!-- Horní část: Výbor vlevo, KK vpravo -->
<div class="grid2" style="margin-bottom:1.5rem">
<?php foreach ($roleGroups as $groupName => $groupRoles):
  $isVybor = $groupName === 'Výbor SVJ';
  $accentColor = $isVybor ? 'var(--blue)' : 'var(--amber)';
?>
<div>
  <div class="group-header" style="color:<?= $accentColor ?>"><?= $isVybor ? '⚙️' : '🔍' ?> <?= $groupName ?></div>
  <div style="display:flex;flex-direction:column;gap:8px">
  <?php
    $hasAny = false;
    foreach ($groupRoles as $roleKey):
      if (empty($grouped[$roleKey])) continue;
      foreach ($grouped[$roleKey] as $m):
        $hasAny = true;
        $color = $roleColors[$m['role']];
        $icon  = $roleIcons[$m['role']];
        $isActive = !$m['valid_until'] || strtotime($m['valid_until']) >= time();
        $daysLeft = $m['valid_until'] ? ceil((strtotime($m['valid_until']) - time()) / 86400) : null;
  ?>
  <div class="member-card" style="opacity:<?= $isActive ? 1 : 0.55 ?>">
    <div class="member-avatar" style="background:<?= $color ?>1A;color:<?= $color ?>"><?= $icon ?></div>
    <div style="flex:1;min-width:0">
      <div class="member-role" style="color:<?= $color ?>"><?= e($roles[$m['role']]) ?></div>
      <div style="font-weight:600;font-size:15px;margin:2px 0"><?= e($m['full_name']) ?></div>
      <div style="font-size:12px;color:var(--muted)">
        <?= $m['unit_label'] ? '🏠 '.e($m['unit_label']) : '' ?>
        <?= $m['email'] ? ($m['unit_label']?' · ':'').'<a href="mailto:'.e($m['email']).'">'.e($m['email']).'</a>' : '' ?>
        <?= $m['phone'] ? ' · 📞 '.e($m['phone']) : '' ?>
      </div>
      <?php if ($m['valid_from'] || $m['valid_until']): ?>
      <div style="font-size:11px;color:var(--muted);margin-top:2px">
        <?= $m['valid_from'] ? 'od '.date('j.n.Y',strtotime($m['valid_from'])) : '' ?>
        <?= $m['valid_until'] ? ' do '.date('j.n.Y',strtotime($m['valid_until'])) : '' ?>
        <?php if ($daysLeft !== null && $daysLeft <= 30 && $isActive): ?>
          <span style="color:var(--amber);font-weight:600">(končí za <?= $daysLeft ?> dní)</span>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
    <div style="display:flex;gap:4px;flex-shrink:0">
      <a class="btn btn-secondary btn-sm" href="?edit=<?= $m['id'] ?>">Upravit</a>
      <form method="POST" style="display:inline" onsubmit="return confirm('Odebrat?')">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= $m['id'] ?>">
        <button type="submit" class="btn btn-danger btn-sm">✕</button>
      </form>
    </div>
  </div>
  <?php endforeach; endforeach; ?>
  <?php if (!$hasAny): ?>
    <div style="color:var(--muted);font-size:13px;padding:.5rem 0">Zatím žádní členové.</div>
  <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
</div>

<!-- Editační formulář dole -->
<div class="card" style="max-width:700px">
  <div class="card-title"><?= $editing ? '✏ Upravit člena' : '+ Přidat člena' ?></div>
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="action" value="<?= $editing ? 'edit' : 'add' ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= $editing['id'] ?>"><?php endif; ?>

    <?php if (!$editing): ?>
    <div class="form-group">
      <label>Vybrat z kartotéky</label>
      <div style="display:flex;gap:1rem;margin-bottom:8px">
        <label style="display:flex;align-items:center;gap:5px;font-size:13px;cursor:pointer">
          <input type="radio" name="person_type" value="owner" checked onchange="switchType('owner')"> 👤 Vlastník
        </label>
        <label style="display:flex;align-items:center;gap:5px;font-size:13px;cursor:pointer">
          <input type="radio" name="person_type" value="tenant" onchange="switchType('tenant')"> 🏠 Nájemník
        </label>
      </div>
      <div id="owner-sel">
        <select id="owner-select" name="owner_source_id" onchange="fillFrom('owner',this)" style="width:100%;font-size:13px;padding:7px 9px;border:1px solid var(--border);border-radius:var(--radius-sm)">
          <option value="">— vyberte vlastníka —</option>
          <?php foreach ($owners as $o): ?>
            <option value="<?= $o['id'] ?>"><?= e($o['label']) ?> – <?= e($o['full_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div id="tenant-sel" style="display:none">
        <select id="tenant-select" name="tenant_source_id" onchange="fillFrom('tenant',this)" style="width:100%;font-size:13px;padding:7px 9px;border:1px solid var(--border);border-radius:var(--radius-sm)">
          <option value="">— vyberte nájemníka —</option>
          <?php foreach ($tenants as $t): ?>
            <option value="<?= $t['id'] ?>"><?= e($t['label']) ?> – <?= e($t['full_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <?php endif; ?>

    <div class="form-row">
      <div class="form-group">
        <label>Jméno a příjmení *</label>
        <input type="text" name="full_name" id="full-name" required value="<?= e($editing['full_name'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Funkce *</label>
        <select name="role" required>
          <?php foreach ($roles as $key => $label): ?>
            <option value="<?= $key ?>" <?= ($editing['role'] ?? '') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>E-mail</label>
        <input type="email" name="email" id="email-f" value="<?= e($editing['email'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Telefon</label>
        <input type="tel" name="phone" id="phone-f" value="<?= e($editing['phone'] ?? '') ?>">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group"><label>Ve funkci od</label><input type="date" name="valid_from" value="<?= e($editing['valid_from'] ?? '') ?>"></div>
      <div class="form-group"><label>Ve funkci do</label><input type="date" name="valid_until" value="<?= e($editing['valid_until'] ?? '') ?>"></div>
    </div>
    <div class="form-group">
      <label>Poznámka</label>
      <input type="text" name="note" value="<?= e($editing['note'] ?? '') ?>">
    </div>
    <div style="display:flex;gap:8px">
      <button type="submit" class="btn btn-primary">Uložit</button>
      <?php if ($editing): ?><a class="btn btn-secondary" href="/admin/committee.php">Zrušit</a><?php endif; ?>
    </div>
  </form>
</div>

<script>
var ownersData  = <?= $ownersJson ?>;
var tenantsData = <?= $tenantsJson ?>;

function switchType(type) {
    document.getElementById('owner-sel').style.display  = type === 'owner'  ? 'block' : 'none';
    document.getElementById('tenant-sel').style.display = type === 'tenant' ? 'block' : 'none';
    document.getElementById('full-name').value = '';
    document.getElementById('email-f').value = '';
    document.getElementById('phone-f').value = '';
}
function fillFrom(type, sel) {
    var id = parseInt(sel.value);
    var data = type === 'owner' ? ownersData : tenantsData;
    var item = data.find(function(d){ return d.id === id; });
    if (item) {
        document.getElementById('full-name').value = item.name || '';
        document.getElementById('email-f').value   = item.email || '';
        document.getElementById('phone-f').value   = item.phone || '';
    }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
