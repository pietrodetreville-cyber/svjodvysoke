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

$stmt = $db->query(
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
     ORDER BY CAST(SUBSTRING_INDEX(u.label, '/', 1) AS UNSIGNED) ASC, CAST(SUBSTRING_INDEX(u.label, '/', -1) AS UNSIGNED) ASC"
);
$rows = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="page-hd" style="justify-content:flex-end">
  <div style="display:flex;gap:8px">
    <a class="btn btn-primary" href="/admin/owner_edit.php">+ Přidat</a>
  </div>
</div>

<!-- Tabulka -->
<div class="card" style="border-top:4px solid var(--green)">
  <div style="font-size:14px;font-weight:600;color:var(--green);margin-bottom:1rem">📋 Seznam vlastníků (<?= count($rows) ?>)</div>
  <?php if (!$rows): ?>
    <p style="color:var(--muted);font-size:14px">Zatím žádní vlastníci.</p>
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
        <?= $r['email'] ? '<a href="mailto:'.e($r['email']).'">'.e($r['email']).'</a>' : '<span style="color:var(--muted)">–</span>' ?>
        <?php if (!empty($r['email_verified'])): ?><span style="font-size:10px;color:var(--green)"> ✓</span><?php endif; ?>
      </td>
      <td style="font-size:13px;white-space:nowrap">
        <?= e($r['phone'] ?: '–') ?>
        <?php if (!empty($r['whatsapp'])): ?><span style="font-size:10px;color:var(--green)"> 💬</span><?php endif; ?>
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
  <div style="font-size:12px;color:var(--muted);margin-top:8px"><?= count($rows) ?> záznamů</div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
