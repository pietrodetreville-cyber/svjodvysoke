<?php
require_once __DIR__ . '/../includes/functions.php';
$user = requireAdmin();
$db = db();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: /admin/perrollam.php'); exit; }

$pr = $db->prepare('SELECT * FROM perrollam WHERE id=?');
$pr->execute([$id]);
$pr = $pr->fetch();
if (!$pr) { header('Location: /admin/perrollam.php'); exit; }

$pageTitle = $pr['title'];

// Uložit hlas od admina
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_vote') {
    csrfCheck();
    $itemId = (int)$_POST['item_id'];
    $unitId = (int)$_POST['unit_id'];
    $vote   = $_POST['vote'];
    if (in_array($vote, ['pro','proti','zdrzelo'])) {
        try {
            $db->prepare('INSERT INTO perrollam_votes (perrollam_id,item_id,unit_id,vote) VALUES (?,?,?,?)')
               ->execute([$id, $itemId, $unitId, $vote]);
        } catch (\PDOException $e) {
            $db->prepare('UPDATE perrollam_votes SET vote=? WHERE item_id=? AND unit_id=?')
               ->execute([$vote, $itemId, $unitId]);
        }
    }
    header("Location: /admin/perrollam_detail.php?id=$id"); exit;
}

$items = $db->prepare('SELECT * FROM perrollam_items WHERE perrollam_id=? ORDER BY order_num');
$items->execute([$id]);
$items = $items->fetchAll();

// Celkové podíly domu
$totalPct = (float)$db->query(
    "SELECT COALESCE(SUM(ROUND(share_numerator/share_denominator*100,4)),0)
     FROM units WHERE type='byt' AND share_numerator IS NOT NULL"
)->fetchColumn();

// Všechny jednotky s podíly
$units = $db->query(
    "SELECT u.id, u.label, o.full_name,
            ROUND(u.share_numerator/u.share_denominator*100,4) AS share_pct
     FROM units u LEFT JOIN owners o ON o.unit_id=u.id
     WHERE u.type='byt' AND u.share_numerator IS NOT NULL
     ORDER BY CAST(SUBSTRING_INDEX(u.label,'/',1) AS UNSIGNED),
              CAST(SUBSTRING_INDEX(u.label,'/',-1) AS UNSIGNED)"
)->fetchAll();

$totalUnits = count($units);

// Hlasy pro každý bod
$votesRaw = $db->prepare(
    'SELECT pv.item_id, pv.unit_id, pv.vote FROM perrollam_votes pv WHERE pv.perrollam_id=?'
);
$votesRaw->execute([$id]);
$votesMap = [];
foreach ($votesRaw->fetchAll() as $v) {
    $votesMap[$v['item_id']][$v['unit_id']] = $v['vote'];
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-hd">
  <div>
    <h1><?= e($pr['title']) ?></h1>
    <div style="font-size:13px;color:var(--muted);margin-top:2px">
      <?= $pr['status'] === 'aktivni' ? '<span class="badge badge-ok">Aktivní</span>' : '<span class="badge badge-miss">Uzavřeno</span>' ?>
      &nbsp;·&nbsp; Uzavírá se: <?= date('j. n. Y H:i', strtotime($pr['closes_at'])) ?>
      &nbsp;·&nbsp; Celkové podíly domu: <strong><?= number_format($totalPct,2,',','') ?> %</strong>
    </div>
  </div>
  <div style="display:flex;gap:8px">
    <a class="btn btn-secondary btn-sm" href="/admin/perrollam_print.php?id=<?= $id ?>" target="_blank">🖨 PDF</a>
    <a class="btn btn-secondary" href="/admin/perrollam.php">← Zpět</a>
  </div>
</div>

<?php if ($pr['description']): ?>
<div class="card" style="margin-bottom:1rem;background:var(--blue-lt);border-color:#b5d0f0">
  <p style="font-size:14px;color:var(--blue);margin:0"><?= nl2br(e($pr['description'])) ?></p>
</div>
<?php endif; ?>

<!-- Pravidlo per rollam -->
<div style="background:var(--amber-lt);border:1px solid #FAC775;border-radius:var(--radius-sm);padding:.75rem 1rem;margin-bottom:1.25rem;font-size:13px;color:var(--amber)">
  ⚖️ <strong>Pravidlo per rollam:</strong> Potřeba nadpoloviční většina z celku (> <?= number_format($totalPct/2,2,',','') ?> %).
  Vlastníci kteří nehlasují jsou <strong>počítáni jako zdržel se</strong>.
</div>

<?php foreach ($items as $item):
  $votes = $votesMap[$item['id']] ?? [];
  $proPct = 0; $protiPct = 0; $zdrzPct = 0;
  $proCount = 0; $protiCount = 0; $zdrzCount = 0;

  foreach ($units as $u) {
      $v = $votes[$u['unit_id']] ?? 'zdrzelo'; // nehlasující = zdržel se
      $pct = (float)$u['share_pct'];
      if ($v === 'pro')    { $proPct += $pct; $proCount++; }
      elseif ($v === 'proti') { $protiPct += $pct; $protiCount++; }
      else { $zdrzPct += $pct; $zdrzCount++; }
  }

  $hlasovalo   = count($votes);
  $nehlasovalo = $totalUnits - $hlasovalo;
  $potreba     = $totalPct / 2;
  $schvaleno   = $proPct > $potreba;
?>
<div class="card" style="margin-bottom:1.25rem">
  <div style="display:flex;align-items:center;gap:8px;margin-bottom:1rem">
    <span style="font-size:13px;font-weight:700;color:var(--muted)"><?= $item['order_num'] ?>.</span>
    <span style="font-size:16px;font-weight:600"><?= e($item['title']) ?></span>
    <?php if ($item['description']): ?>
      <span style="font-size:12px;color:var(--muted)">– <?= e($item['description']) ?></span>
    <?php endif; ?>
  </div>

  <!-- Výsledky -->
  <div style="display:flex;gap:1rem;margin-bottom:1rem;flex-wrap:wrap">
    <div style="background:var(--green-lt);border-radius:var(--radius-sm);padding:.75rem 1.25rem;min-width:130px;text-align:center">
      <div style="font-size:22px;font-weight:700;color:var(--green)"><?= number_format($proPct,2,',','') ?> %</div>
      <div style="font-size:12px;color:var(--green)">✓ PRO (<?= $proCount ?>×)</div>
    </div>
    <div style="background:var(--red-lt);border-radius:var(--radius-sm);padding:.75rem 1.25rem;min-width:130px;text-align:center">
      <div style="font-size:22px;font-weight:700;color:var(--red)"><?= number_format($protiPct,2,',','') ?> %</div>
      <div style="font-size:12px;color:var(--red)">✗ PROTI (<?= $protiCount ?>×)</div>
    </div>
    <div style="background:var(--gray-lt);border-radius:var(--radius-sm);padding:.75rem 1.25rem;min-width:130px;text-align:center">
      <div style="font-size:22px;font-weight:700;color:var(--muted)"><?= number_format($zdrzPct,2,',','') ?> %</div>
      <div style="font-size:12px;color:var(--muted)">— ZDRŽEL (<?= $zdrzCount ?>× vč. <?= $nehlasovalo ?> nehlasujících)</div>
    </div>
    <div style="background:<?= $schvaleno ? 'var(--green-lt)' : 'var(--red-lt)' ?>;border-radius:var(--radius-sm);padding:.75rem 1.25rem;min-width:160px;text-align:center;border:2px solid <?= $schvaleno ? 'var(--green)' : 'var(--red)' ?>">
      <div style="font-size:22px;font-weight:700;color:<?= $schvaleno ? 'var(--green)' : 'var(--red)' ?>"><?= $schvaleno ? '✓ SCHVÁLENO' : '✗ NESCHVÁLENO' ?></div>
      <div style="font-size:12px;color:var(--muted)">Potřeba > <?= number_format($potreba,2,',','') ?> % · Získáno <?= number_format($proPct,2,',','') ?> %</div>
    </div>
  </div>

  <!-- Průběžná lišta -->
  <div style="margin-bottom:1rem">
    <div style="display:flex;gap:2px;height:20px;border-radius:var(--radius-sm);overflow:hidden;background:var(--gray-lt)">
      <?php if ($proPct > 0): ?><div style="width:<?= min(round($proPct/$totalPct*100),100) ?>%;background:var(--green)"></div><?php endif; ?>
      <?php if ($protiPct > 0): ?><div style="width:<?= round($protiPct/$totalPct*100) ?>%;background:var(--red)"></div><?php endif; ?>
      <?php if ($zdrzPct > 0): ?><div style="width:<?= round($zdrzPct/$totalPct*100) ?>%;background:#ccc"></div><?php endif; ?>
    </div>
    <div style="display:flex;align-items:center;margin-top:4px">
      <div style="flex:1;font-size:11px;color:var(--muted)">0 %</div>
      <div style="font-size:11px;color:var(--muted);text-align:center">Potřeba: <?= number_format($potreba,2,',','') ?> %</div>
      <div style="flex:1;font-size:11px;color:var(--muted);text-align:right"><?= number_format($totalPct,2,',','') ?> %</div>
    </div>
    <!-- Ukazatel hranice -->
    <div style="position:relative;height:12px;margin-top:2px">
      <div style="position:absolute;left:50%;transform:translateX(-50%);font-size:18px;line-height:1;color:var(--amber)">▲</div>
    </div>
  </div>

  <!-- Přehled hlasů -->
  <details>
    <summary style="font-size:13px;color:var(--muted);cursor:pointer;margin-bottom:8px">
      Zobrazit hlasy jednotek (<?= $hlasovalo ?> hlasovalo, <?= $nehlasovalo ?> nehlasovalo = zdržel se)
    </summary>
    <div style="max-height:300px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius-sm)">
      <table style="width:100%;border-collapse:collapse;font-size:13px">
        <thead><tr style="background:var(--gray-lt)">
          <th style="padding:6px 10px;text-align:left">Jednotka</th>
          <th style="padding:6px 10px;text-align:left">Vlastník</th>
          <th style="padding:6px 10px;text-align:right">%</th>
          <th style="padding:6px 10px;text-align:center">Hlas</th>
        </tr></thead>
        <tbody>
        <?php foreach ($units as $u):
          $v = $votes[$u['unit_id']] ?? null;
          $vLabel = match($v) { 'pro' => '✓ Pro', 'proti' => '✗ Proti', 'zdrzelo' => '— Zdržel', null => '— Nehlasoval' };
          $vColor = match($v) { 'pro' => 'var(--green)', 'proti' => 'var(--red)', default => 'var(--muted)' };
          $bg = $v === null ? '#fff8e6' : ($v === 'pro' ? '#f0fff4' : ($v === 'proti' ? '#fff0f0' : '#f8f8f8'));
        ?>
        <tr style="border-top:1px solid var(--border);background:<?= $bg ?>">
          <td style="padding:6px 10px"><strong><?= e($u['label']) ?></strong></td>
          <td style="padding:6px 10px;color:var(--muted)"><?= e($u['full_name'] ?: '–') ?></td>
          <td style="padding:6px 10px;text-align:right;font-weight:600"><?= $u['share_pct'] ?></td>
          <td style="padding:6px 10px;text-align:center;color:<?= $vColor ?>;font-weight:600"><?= $vLabel ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </details>
</div>
<?php endforeach; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
