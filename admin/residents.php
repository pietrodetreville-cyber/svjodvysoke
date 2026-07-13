<?php
require_once __DIR__ . '/../includes/functions.php';
$user = requireAdmin();
$pageTitle = 'Obyvatelé';
$db = db();

// Vlastníci s trvalým pobytem
$owners = $db->query(
    "SELECT u.label AS unit_label, o.full_name, o.email, o.phone,
            o.residence, o.persons_count,
            'vlastník' AS typ
     FROM owners o
     JOIN units u ON o.unit_id=u.id
     WHERE u.type='byt' AND o.residence='trvalé'
     ORDER BY CAST(SUBSTRING_INDEX(u.label,'/',1) AS UNSIGNED),
              CAST(SUBSTRING_INDEX(u.label,'/',-1) AS UNSIGNED)"
)->fetchAll();

// Nájemníci
$tenants = $db->query(
    "SELECT u.label AS unit_label, t.full_name, t.email, t.phone,
            t.rent_from, t.rent_until, t.persons_count,
            'nájemník' AS typ
     FROM tenants t
     JOIN units u ON t.unit_id=u.id
     ORDER BY CAST(SUBSTRING_INDEX(u.label,'/',1) AS UNSIGNED),
              CAST(SUBSTRING_INDEX(u.label,'/',-1) AS UNSIGNED)"
)->fetchAll();

// Sloučit a seřadit dle jednotky
$all = [];
foreach ($owners as $r) $all[$r['unit_label']]['vlastnik'] = $r;
foreach ($tenants as $r) $all[$r['unit_label']]['najemnik'][] = $r;
ksort($all);

// Statistiky
$totalOwners  = count($owners);
$totalTenants = count($tenants);
$totalPersons = array_sum(array_column($owners, 'persons_count'))
              + array_sum(array_column($tenants, 'persons_count'));

include __DIR__ . '/../includes/header.php';
?>

<div class="page-hd">
  <h1>Obyvatelé</h1>
  <div style="display:flex;gap:8px">
    <a class="btn btn-secondary btn-sm" href="/admin/residents.php?export=csv">⬇ Export CSV</a>
  </div>
</div>

<?php
// Export CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="obyvatele.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
    fputcsv($out, ['Jednotka','Jméno','Typ','E-mail','Telefon','Osob'], ';');
    foreach ($all as $unit => $rows) {
        if (!empty($rows['vlastnik'])) {
            $r = $rows['vlastnik'];
            fputcsv($out, [$unit, $r['full_name'], 'vlastník', $r['email'], $r['phone'], $r['persons_count']], ';');
        }
        foreach ($rows['najemnik'] ?? [] as $r) {
            $expires = $r['rent_until'] && strtotime($r['rent_until']) < time() ? ' (prošlý)' : '';
            fputcsv($out, [$unit, $r['full_name'], 'nájemník'.$expires, $r['email'], $r['phone'], $r['persons_count']], ';');
        }
    }
    fclose($out);
    exit;
}
?>

<!-- Statistiky -->
<div class="metrics" style="margin-bottom:1.25rem">
  <div class="metric">
    <div class="metric-num"><?= count($all) ?></div>
    <div class="metric-lbl">Obsazených bytů</div>
  </div>
  <div class="metric">
    <div class="metric-num" style="color:var(--blue)"><?= $totalOwners ?></div>
    <div class="metric-lbl">Vlastníků (trvalý pobyt)</div>
  </div>
  <div class="metric">
    <div class="metric-num" style="color:var(--green)"><?= $totalTenants ?></div>
    <div class="metric-lbl">Nájemníků</div>
  </div>
  <div class="metric">
    <div class="metric-num"><?= $totalPersons ?: '–' ?></div>
    <div class="metric-lbl">Osob celkem</div>
  </div>
</div>

<!-- Přehled -->
<div class="card">
  <div class="tbl-wrap">
  <table class="tbl">
    <thead>
      <tr>
        <th>Jednotka</th>
        <th>Jméno</th>
        <th>Typ</th>
        <th>E-mail</th>
        <th>Telefon</th>
        <th style="text-align:center">Osob</th>
        <th>Poznámka</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($all as $unit => $rows): ?>

      <?php if (!empty($rows['vlastnik'])): ?>
      <?php $r = $rows['vlastnik']; ?>
      <tr style="background:#F0F5FB">
        <td><strong><?= e($unit) ?></strong></td>
        <td style="font-weight:500"><?= e($r['full_name']) ?></td>
        <td>
          <span class="badge badge-blue">👤 Vlastník</span>
        </td>
        <td style="font-size:13px">
          <?= $r['email'] ? '<a href="mailto:'.e($r['email']).'">'.e($r['email']).'</a>' : '<span style="color:var(--muted)">–</span>' ?>
        </td>
        <td style="font-size:13px;white-space:nowrap"><?= e($r['phone'] ?: '–') ?></td>
        <td style="text-align:center;font-size:13px"><?= $r['persons_count'] ?: '–' ?></td>
        <td style="font-size:12px;color:var(--muted)">trvalý pobyt</td>
      </tr>
      <?php endif; ?>

      <?php foreach ($rows['najemnik'] ?? [] as $t): ?>
      <?php
        $isActive  = !$t['rent_until'] || strtotime($t['rent_until']) >= time();
        $isExpiring= $t['rent_until'] && strtotime($t['rent_until']) < strtotime('+30 days') && $isActive;
        $daysLeft  = $t['rent_until'] ? ceil((strtotime($t['rent_until']) - time()) / 86400) : null;
      ?>
      <tr style="background:<?= $isActive ? '#F0FFF4' : '#FFF8F8' ?>">
        <td><strong><?= e($unit) ?></strong></td>
        <td style="font-weight:500"><?= e($t['full_name']) ?></td>
        <td>
          <?php if (!$isActive): ?>
            <span class="badge badge-miss">🏠 Prošlý nájem</span>
          <?php elseif ($isExpiring): ?>
            <span class="badge badge-partial">🏠 Nájemník</span>
          <?php else: ?>
            <span class="badge badge-ok">🏠 Nájemník</span>
          <?php endif; ?>
        </td>
        <td style="font-size:13px">
          <?= $t['email'] ? '<a href="mailto:'.e($t['email']).'">'.e($t['email']).'</a>' : '<span style="color:var(--muted)">–</span>' ?>
        </td>
        <td style="font-size:13px;white-space:nowrap"><?= e($t['phone'] ?: '–') ?></td>
        <td style="text-align:center;font-size:13px"><?= $t['persons_count'] ?: '–' ?></td>
        <td style="font-size:12px;color:var(--muted)">
          nájem
          <?= $t['rent_from'] ? 'od '.date('j.n.Y', strtotime($t['rent_from'])) : '' ?>
          <?php if ($t['rent_until']): ?>
            do <?= date('j.n.Y', strtotime($t['rent_until'])) ?>
            <?php if ($isExpiring): ?>
              <span style="color:var(--amber)">(za <?= $daysLeft ?> dní)</span>
            <?php elseif (!$isActive): ?>
              <span style="color:var(--red)">(prošlé)</span>
            <?php endif; ?>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>

    <?php endforeach; ?>

    <?php if (!$all): ?>
    <tr><td colspan="7" style="color:var(--muted);font-size:14px;padding:1rem">
      Žádní obyvatelé — doplňte trvalé pobyty v kartotéce a nájemníky v sekci Nájemníci.
    </td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
  <div style="font-size:12px;color:var(--muted);margin-top:8px">
    <?= $totalOwners + $totalTenants ?> záznamů &nbsp;·&nbsp;
    Zobrazeni vlastníci s trvalým pobytem a všichni nájemníci
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
