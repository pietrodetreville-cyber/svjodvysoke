<?php
require_once __DIR__ . '/../includes/functions.php';
$user = requireAdmin();
$pageTitle = 'Kartotéka vlastníků';
$db = db();

// Smazání
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrfCheck();
    $db->prepare('DELETE FROM owners WHERE id=?')->execute([(int)$_POST['id']]);
    flash('Karta smazána.', 'success');
    header('Location: /admin/owners.php'); exit;
}

// Filtr
$filter = $_GET['filter'] ?? 'vše';
$search = trim($_GET['q'] ?? '');
$sort   = $_GET['sort'] ?? 'unit';
$where  = '1=1';
$params = [];

if (in_array($filter, ['úplná','neúplná','chybí'])) {
    $where .= ' AND o.status=?'; $params[] = $filter;
}
if ($search) {
    $where .= ' AND (o.full_name LIKE ? OR u.label LIKE ?)';
    $params[] = "%$search%"; $params[] = "%$search%";
}

$orderBy = match($sort) {
    'updated_desc' => 'o.updated_at DESC',
    'updated_asc'  => 'o.updated_at ASC',
    'name'         => 'o.full_name ASC',
    default        => 'CAST(SUBSTRING_INDEX(u.label, "/", 1) AS UNSIGNED) ASC, CAST(SUBSTRING_INDEX(u.label, "/", -1) AS UNSIGNED) ASC',
};

$stmt = $db->prepare(
    "SELECT o.*, u.label AS unit_label, u.type AS unit_type,
            u.share_numerator, u.share_denominator,
            CASE WHEN u.share_numerator IS NOT NULL AND u.share_denominator > 0
                 THEN ROUND(u.share_numerator / u.share_denominator * 100, 4)
                 ELSE NULL END AS share_pct,
            lb.label AS linked_byt
     FROM owners o JOIN units u ON o.unit_id=u.id
     LEFT JOIN users us ON us.unit_id=o.unit_id AND us.role='owner'
     LEFT JOIN (
         SELECT linked_unit_id, GROUP_CONCAT(label ORDER BY label SEPARATOR ', ') AS garaze
         FROM units WHERE type != 'byt' AND linked_unit_id IS NOT NULL
         GROUP BY linked_unit_id
     ) g ON g.linked_unit_id=u.id
     LEFT JOIN units lb ON lb.id=u.linked_unit_id AND u.type != 'byt'
     WHERE $where ORDER BY $orderBy"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Statistiky
$stats = $db->query(
    "SELECT
        SUM(CASE WHEN status='úplná' THEN 1 ELSE 0 END) as uplna,
        SUM(CASE WHEN status='neúplná' THEN 1 ELSE 0 END) as neuplna,
        SUM(CASE WHEN status='chybí' THEN 1 ELSE 0 END) as chybi,
        COUNT(*) as celkem
     FROM owners"
)->fetch();

// Poslední aktualizace
$lastUpdated = $db->query(
    "SELECT o.full_name, o.updated_at, u.label
     FROM owners o JOIN units u ON o.unit_id=u.id
     LEFT JOIN users us ON us.unit_id=o.unit_id AND us.role='owner'
     LEFT JOIN (
         SELECT linked_unit_id, GROUP_CONCAT(label ORDER BY label SEPARATOR ', ') AS garaze
         FROM units WHERE type != 'byt' AND linked_unit_id IS NOT NULL
         GROUP BY linked_unit_id
     ) g ON g.linked_unit_id=u.id
     LEFT JOIN units lb ON lb.id=u.linked_unit_id AND u.type != 'byt'
     WHERE o.updated_at IS NOT NULL
     ORDER BY o.updated_at DESC LIMIT 1"
)->fetch();

include __DIR__ . '/../includes/header.php';
?>

<div class="page-hd">
  <h1>Kartotéka vlastníků</h1>
  <div style="display:flex;gap:8px">
    <a class="btn btn-secondary btn-sm" href="/admin/export.php?format=csv">⬇ Export CSV</a>
    <a class="btn btn-secondary btn-sm" href="/admin/export_prezence.php">📋 Prezenční listina</a>
    <a class="btn btn-primary" href="/admin/owner_edit.php">+ Přidat</a>
  </div>
</div>

<!-- Statistiky -->
<div class="metrics" style="margin-bottom:1rem">
  <div class="metric"><div class="metric-num"><?= $stats['celkem'] ?></div><div class="metric-lbl">Celkem karet</div></div>
  <div class="metric"><div class="metric-num" style="color:var(--green)"><?= $stats['uplna'] ?></div><div class="metric-lbl">Úplných</div></div>
  <div class="metric"><div class="metric-num" style="color:var(--amber)"><?= $stats['neuplna'] ?></div><div class="metric-lbl">Neúplných</div></div>
  <div class="metric"><div class="metric-num" style="color:var(--red)"><?= $stats['chybi'] ?></div><div class="metric-lbl">Chybí</div></div>
  <?php if ($lastUpdated): ?>
  <div class="metric" style="background:var(--blue-lt)">
    <div style="font-size:12px;font-weight:600;color:var(--blue)"><?= e($lastUpdated['label']) ?></div>
    <div style="font-size:11px;color:var(--blue);margin-top:2px"><?= e(mb_substr($lastUpdated['full_name'],0,16)) ?></div>
    <div class="metric-lbl" style="color:var(--blue)">Naposledy vyplnil <?= date('j. n. H:i', strtotime($lastUpdated['updated_at'])) ?></div>
  </div>
  <?php endif; ?>
</div>

<!-- Filtr -->
<div class="card" style="margin-bottom:1rem">
  <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <input type="text" name="q" placeholder="Hledat jméno nebo jednotku…" value="<?= e($search) ?>"
           style="flex:1;min-width:160px;padding:7px 10px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:14px">
    <?php foreach (['vše','úplná','neúplná','chybí'] as $f): ?>
      <a href="?filter=<?= $f ?>&q=<?= urlencode($search) ?>&sort=<?= $sort ?>"
         style="padding:5px 12px;border-radius:99px;font-size:13px;font-weight:500;border:1px solid var(--border);text-decoration:none;
                background:<?= $filter===$f ? 'var(--blue)' : '#fff' ?>;
                color:<?= $filter===$f ? '#fff' : 'var(--muted)' ?>">
        <?= ucfirst($f) ?>
      </a>
    <?php endforeach; ?>
    <select name="sort" onchange="this.form.submit()" style="font-size:13px;padding:6px 10px;border:1px solid var(--border);border-radius:var(--radius-sm)">
      <option value="unit"         <?= $sort==='unit'         ? 'selected' : '' ?>>Řadit: Jednotka</option>
      <option value="name"         <?= $sort==='name'         ? 'selected' : '' ?>>Řadit: Jméno</option>
      <option value="updated_desc" <?= $sort==='updated_desc' ? 'selected' : '' ?>>Řadit: Naposledy upravené</option>
      <option value="updated_asc"  <?= $sort==='updated_asc'  ? 'selected' : '' ?>>Řadit: Nejdéle neupravené</option>
    </select>
    <input type="hidden" name="filter" value="<?= e($filter) ?>">
    <button type="submit" class="btn btn-secondary btn-sm">Hledat</button>
  </form>
</div>

<!-- Tabulka -->
<div class="card">
  <?php if (!$rows): ?>
    <p style="color:var(--muted);font-size:14px">Žádné záznamy neodpovídají filtru.</p>
  <?php else: ?>
  <div class="tbl-wrap">
  <table class="tbl">
    <thead>
      <tr>
        <th>Jednotka</th>
        <th>Vlastník</th>
        <th>E-mail</th>
        <th>Telefon</th>
        <th>Osoby</th>
        <th>Stav</th>
        <th>Kdo upravil</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r):
      $badge = match($r['status']) {
        'úplná'   => 'badge-ok',
        'neúplná' => 'badge-partial',
        default   => 'badge-miss',
      };

      // Jak dávno byla karta upravena
      $daysAgo = null;
      $updatedLabel = '–';
      $updatedColor = 'var(--muted)';
      if ($r['updated_at']) {
          $daysAgo = (int)((time() - strtotime($r['updated_at'])) / 86400);
          $updatedLabel = date('j. n. Y', strtotime($r['updated_at']));
          if ($daysAgo === 0) { $updatedLabel = 'dnes'; $updatedColor = 'var(--green)'; }
          elseif ($daysAgo <= 7)  { $updatedLabel .= ' ('.($daysAgo===1?'včera':$daysAgo.' dní').')'; $updatedColor = 'var(--green)'; }
          elseif ($daysAgo <= 30) { $updatedLabel .= ' ('.$daysAgo.' dní)'; $updatedColor = 'var(--amber)'; }
          else { $updatedColor = 'var(--red)'; }
      }
    ?>
    <tr>
      <td>
        <strong>
          <?= $r['unit_type']==='byt' && !empty($r['garaze']) ? '🚗 ' : '' ?><?= e($r['unit_label']) ?>
        </strong><br>
        <small style="color:var(--muted)"><?= e($r['unit_type']) ?></small>
        <?php if ($r['unit_type']==='byt' && !empty($r['garaze'])): ?>
          <br><small style="color:var(--amber);font-size:11px">garáž: <?= e($r['garaze']) ?></small>
        <?php elseif ($r['unit_type']!=='byt' && !empty($r['linked_byt'])): ?>
          <br><small style="color:#185FA5;font-size:11px">byt: <?= e($r['linked_byt']) ?></small>
        <?php endif; ?>
      </td>
      <td>
        <?= e($r['full_name']) ?>
        <?php if ($r['share_pct'] !== null): ?>
          <br><small style="color:var(--muted)"><?= $r['share_pct'] ?> %</small>
        <?php endif; ?>
      </td>
      <td style="font-size:13px">
        <?php
          $mainEmail = ($r['primary_email'] ?? 1) == 2 && $r['email2'] ? $r['email2'] : $r['email'];
          $hasExtra = $r['email2'] && $r['email'];
        ?>
        <?= $mainEmail ? '<a href="mailto:'.e($mainEmail).'">'.e($mainEmail).'</a>' : '<span style="color:var(--muted)">–</span>' ?>
        <?php if ($hasExtra): ?><span style="font-size:10px;color:var(--muted)"> +1</span><?php endif; ?>
      </td>
      <td style="font-size:13px;white-space:nowrap">
        <?php $mainPhone = ($r['primary_phone'] ?? 1) == 2 && $r['phone2'] ? $r['phone2'] : $r['phone']; ?>
        <?= e($mainPhone ?: '–') ?>
        <?php if ($r['phone2'] && $r['phone']): ?><span style="font-size:10px;color:var(--muted)"> +1</span><?php endif; ?>
      </td>
      <td style="text-align:center;font-size:13px">
        <?= $r['persons_count'] ? '<span style="font-weight:600">'.$r['persons_count'].'</span><span style="color:var(--muted);font-size:11px"> os.</span>' : '<span style="color:var(--muted)">–</span>' ?>
      </td>
      <td>
        <?php if ($r['status']): ?>
          <span class="badge <?= $badge ?>"><?= e($r['status']) ?></span>
        <?php else: ?>
          <span class="badge badge-miss">chybí</span>
        <?php endif; ?>

      </td>
      <td style="font-size:12px;white-space:nowrap">
        <?php
          $role = $r['updated_by_role'] ?? null;
          $when = $r['updated_at'] ? date('j.n.Y', strtotime($r['updated_at'])) : '';
          if ($role === 'owner') echo '<span class="badge" style="background:#E6F1FB;color:#185FA5">👤 Vlastník</span>';
          elseif ($role === 'admin') echo '<span class="badge badge-partial">⚙ Výbor</span>';
          elseif ($role === 'superadmin') echo '<span class="badge" style="background:#f0e6fb;color:#6b11a5">🔑 Admin</span>';
          else echo '<span style="color:var(--muted)">–</span>';
          if ($when && $role) echo '<br><span style="color:var(--muted);font-size:11px">'.$when.'</span>';
        ?>
      </td>
      <td style="white-space:nowrap">
        <a class="btn btn-secondary btn-sm" href="/admin/owner_detail.php?id=<?= $r['id'] ?>">Detail</a>
        <form method="POST" style="display:inline" onsubmit="return confirm('Opravdu smazat?')">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= $r['id'] ?>">
          <button type="submit" class="btn btn-danger btn-sm">✕</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <div style="font-size:12px;color:var(--muted);margin-top:8px"><?= count($rows) ?> záznamů &nbsp;·&nbsp; <a href="/admin/export.php?format=csv">⬇ Export CSV</a></div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
