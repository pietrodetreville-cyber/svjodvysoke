<?php
require_once __DIR__ . '/../includes/functions.php';
$user = requireLogin();
$pageTitle = 'Orgány SVJ';
$db = db();

$roles = [
    'predseda'      => 'Předseda výboru',
    'mistopredseda' => 'Místopředseda výboru',
    'clen_vyboru'   => 'Člen výboru',
    'predseda_kk'   => 'Předseda kontrolní komise',
    'clen_kk'       => 'Člen kontrolní komise',
];
$roleColors = [
    'predseda'      => ['bg'=>'#E6F1FB','color'=>'#185FA5'],
    'mistopredseda' => ['bg'=>'#EAF3DE','color'=>'#3B6D11'],
    'clen_vyboru'   => ['bg'=>'#f0f5fb','color'=>'#185FA5'],
    'predseda_kk'   => ['bg'=>'#FAEEDA','color'=>'#854F0B'],
    'clen_kk'       => ['bg'=>'#fff8e6','color'=>'#854F0B'],
];
$roleGroups = [
    'Výbor SVJ'        => ['predseda','mistopredseda','clen_vyboru'],
    'Kontrolní komise' => ['predseda_kk','clen_kk'],
];

$members = $db->query(
    "SELECT c.*, u.label AS unit_label
     FROM committee c
     LEFT JOIN owners o ON c.owner_id=o.id
     LEFT JOIN units u ON o.unit_id=u.id
     WHERE c.valid_until IS NULL OR c.valid_until >= CURDATE()
     ORDER BY FIELD(c.role,'predseda','mistopredseda','clen_vyboru','predseda_kk','clen_kk')"
)->fetchAll();

$grouped = [];
foreach ($members as $m) $grouped[$m['role']][] = $m;

include __DIR__ . '/../includes/header.php';
?>
<style>
.committee-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.25rem}
@media(max-width:700px){.committee-grid{grid-template-columns:1fr}}
</style>

<div class="page-hd"><h1>Orgány SVJ Od Vysoké – Rozhled</h1></div>

<div class="committee-grid">
<?php foreach ($roleGroups as $groupName => $groupRoles): ?>
<div class="card">
  <div class="card-title"><?= $groupName === 'Výbor SVJ' ? '⚙' : '🔍' ?> <?= $groupName ?></div>
  <?php
    $hasAny = false;
    foreach ($groupRoles as $roleKey):
      if (empty($grouped[$roleKey])) continue;
      $hasAny = true;
      foreach ($grouped[$roleKey] as $m):
        $rc = $roleColors[$m['role']];
  ?>
  <div style="display:flex;align-items:center;gap:12px;padding:.75rem 0;border-bottom:1px solid var(--border)">
    <div style="width:40px;height:40px;border-radius:50%;background:<?= $rc['bg'] ?>;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0">
      <?= $m['role']==='predseda'||$m['role']==='predseda_kk' ? '👑' : '👤' ?>
    </div>
    <div style="flex:1">
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <strong><?= e($m['full_name']) ?></strong>
        <span style="background:<?= $rc['bg'] ?>;color:<?= $rc['color'] ?>;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:600">
          <?= e($roles[$m['role']]) ?>
        </span>
      </div>
      <div style="font-size:13px;color:var(--muted);margin-top:3px">
        <?= $m['unit_label'] ? '🏠 '.e($m['unit_label']).'&nbsp;·&nbsp;' : '' ?>
        <?= $m['email'] ? '✉️ <a href="mailto:'.e($m['email']).'">'.e($m['email']).'</a>' : '' ?>
        <?= $m['phone'] ? '&nbsp;·&nbsp; 📞 '.e($m['phone']) : '' ?>
        <?php if ($m['valid_from']): ?>
          <br><span style="font-size:12px">Ve funkci od <?= date('j. n. Y', strtotime($m['valid_from'])) ?></span>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; endforeach; ?>
  <?php if (!$hasAny): ?>
    <p style="color:var(--muted);font-size:13px">Zatím není nikdo evidován.</p>
  <?php endif; ?>
</div>
<?php endforeach; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
