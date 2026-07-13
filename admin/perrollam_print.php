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

$totalUnits = $db->query("SELECT COUNT(*) FROM units WHERE type='byt'")->fetchColumn();
$totalPct   = $db->query("SELECT COALESCE(SUM(ROUND(share_numerator/share_denominator*100,4)),0) FROM units WHERE type='byt' AND share_numerator IS NOT NULL")->fetchColumn();

$items = $db->prepare('SELECT * FROM perrollam_items WHERE perrollam_id=? ORDER BY order_num');
$items->execute([$id]);
$items = $items->fetchAll();
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<title>Per rollam – <?= htmlspecialchars($pr['title']) ?></title>
<style>
  @page { size: A4 portrait; margin: 2.5cm 2cm 2cm 2cm; }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: Arial, sans-serif; font-size: 10.5pt; color: #111; background: #fff; padding: 2.5cm 2cm 2cm 2cm; max-width: 21cm; margin: 0 auto; line-height: 1.55; }
  .svj-header { display: flex; align-items: stretch; border: 2px solid #185FA5; border-radius: 6px; margin-bottom: 20px; overflow: hidden; }
  .svj-header-left { background: #185FA5; color: white; padding: 12px 18px; min-width: 150px; display: flex; flex-direction: column; justify-content: center; }
  .svj-header-left .name { font-size: 13pt; font-weight: bold; line-height: 1.2; }
  .svj-header-left .sub { font-size: 8pt; opacity: .85; margin-top: 3px; }
  .svj-header-right { padding: 10px 16px; flex: 1; background: #f0f5fb; display: flex; flex-direction: column; justify-content: center; gap: 3px; }
  .svj-header-right p { font-size: 9pt; color: #333; }
  h1 { font-size: 14pt; color: #185FA5; text-align: center; text-transform: uppercase; margin: 16px 0 4px; }
  .subtitle { text-align: center; font-size: 9.5pt; color: #555; margin-bottom: 6px; }
  hr { border: none; border-top: 2px solid #185FA5; margin: 10px 0 16px; }
  .meta { display: flex; gap: 0; border: 1px solid #ccc; border-radius: 4px; margin-bottom: 14px; font-size: 9pt; overflow: hidden; }
  .meta-col { flex: 1; padding: 7px 10px; border-right: 1px solid #ccc; }
  .meta-col:last-child { border-right: none; }
  .meta-col .lbl { color: #666; font-size: 8pt; margin-bottom: 2px; }
  .meta-col .val { font-weight: bold; }
  h2 { font-size: 11pt; color: #185FA5; border-bottom: 1.5px solid #185FA5; padding-bottom: 3px; margin: 16px 0 10px; }
  .item-box { border: 1px solid #ccc; border-radius: 4px; margin-bottom: 14px; overflow: hidden; }
  .item-header { background: #f0f0ee; padding: 7px 12px; font-weight: bold; border-bottom: 1px solid #ccc; }
  .item-body { padding: 10px 12px; }
  table { width: 100%; border-collapse: collapse; font-size: 9.5pt; margin: 8px 0; }
  th { background: #185FA5; color: white; padding: 5px 8px; text-align: left; font-size: 8.5pt; }
  td { padding: 4px 8px; border-bottom: 1px solid #eee; }
  .maj-grid { display: flex; gap: 8px; margin-top: 10px; }
  .maj-box { flex: 1; border: 1px solid #ccc; border-radius: 4px; padding: 7px 10px; text-align: center; }
  .maj-ok { border-color: #8bc34a; background: #EAF3DE; }
  .maj-no { background: #f5f5f3; }
  .maj-icon { font-size: 14pt; font-weight: bold; }
  .maj-ok .maj-icon { color: #3B6D11; }
  .maj-no .maj-icon { color: #999; }
  .maj-name { font-size: 8pt; color: #555; margin-bottom: 3px; }
  .maj-detail { font-size: 7.5pt; color: #777; margin-top: 3px; }
  .votes-list { font-size: 8.5pt; color: #444; margin-top: 8px; }
  .sig-section { margin-top: 35px; }
  .sig-date { font-size: 9pt; color: #555; margin-bottom: 20px; }
  .sig-row { display: flex; gap: 25px; }
  .sig-line { flex: 1; border-top: 1px solid #333; padding-top: 5px; font-size: 8.5pt; color: #555; }
  .copyright { margin-top: 20px; padding-top: 8px; border-top: 1px solid #ddd; text-align: right; font-size: 7.5pt; color: #bbb; font-style: italic; }
  .print-btn { position: fixed; top: 15px; right: 15px; background: #185FA5; color: white; border: none; padding: 10px 20px; border-radius: 6px; font-size: 13px; cursor: pointer; }
  @media print {
    .print-btn { display: none !important; }
    body { padding: 0; }
    .svj-header, th, .maj-ok, .item-header { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  }
</style>
</head>
<body>

<button class="print-btn" onclick="window.print()">🖨 Tisk / PDF</button>

<div class="svj-header">
  <div class="svj-header-left">
    <div class="name">SVJ Rozhled</div>
    <div class="sub">Od Vysoke</div>
  </div>
  <div class="svj-header-right">
    <p><strong>Nazev:</strong> Spolecenstvi vlastniku jednotek Rozhled – Od Vysoke 271, 272, 273, 274, 275</p>
    <p><strong>Sidlo:</strong> Od Vysoke 275/2, Radlice, 150 00 Praha 5 &nbsp;&nbsp; <strong>ICO:</strong> 047 65 435</p>
  </div>
</div>

<h1>Vysledky per rollam hlasovani</h1>
<p class="subtitle"><?= htmlspecialchars($pr['title']) ?></p>
<hr>

<div class="meta">
  <div class="meta-col"><div class="lbl">Zahajeni</div><div class="val"><?= date('j. n. Y', strtotime($pr['created_at'])) ?></div></div>
  <div class="meta-col"><div class="lbl">Uzavreni</div><div class="val"><?= date('j. n. Y H:i', strtotime($pr['closes_at'])) ?></div></div>
  <div class="meta-col"><div class="lbl">Stav</div><div class="val"><?= $pr['status']==='uzavreno' ? 'Uzavreno' : 'Aktivni' ?></div></div>
  <div class="meta-col"><div class="lbl">Celkem jednotek</div><div class="val"><?= $totalUnits ?></div></div>
</div>

<?php if ($pr['description']): ?>
<p style="font-size:9.5pt;color:#444;margin-bottom:14px"><?= nl2br(htmlspecialchars($pr['description'])) ?></p>
<?php endif; ?>

<h2>Vysledky hlasovani</h2>

<?php foreach ($items as $item):
  $voteData = [];
  $vq = $db->prepare('SELECT pv.vote, COUNT(*) as pocet, COALESCE(SUM(ROUND(u.share_numerator/u.share_denominator*100,4)),0) as pct FROM perrollam_votes pv JOIN units u ON pv.unit_id=u.id WHERE pv.item_id=? GROUP BY pv.vote');
  $vq->execute([$item['id']]);
  foreach ($vq->fetchAll() as $r) $voteData[$r['vote']] = $r;
  $proPct    = $voteData['pro']['pct'] ?? 0;
  $protiPct  = $voteData['proti']['pct'] ?? 0;
  $zdrzPct   = $voteData['zdrzelse']['pct'] ?? 0;
  $proCount  = $voteData['pro']['pocet'] ?? 0;
  $protiCount= $voteData['proti']['pocet'] ?? 0;
  $zdrzCount = $voteData['zdrzelse']['pocet'] ?? 0;
  $totalVotedPct = $proPct + $protiPct + $zdrzPct;
  $totalVoted = $proCount + $protiCount + $zdrzCount;
  $prostaMaj  = $totalVotedPct > 0 && $proPct > ($totalVotedPct / 2);
  $absolutMaj = $totalPct > 0 && $proPct > ($totalPct / 2);
  $kvalifMaj  = $totalPct > 0 && $proPct >= ($totalPct * 0.75);

  $detail = $db->prepare('SELECT pv.vote, o.full_name, u.label FROM perrollam_votes pv JOIN units u ON pv.unit_id=u.id JOIN owners o ON pv.owner_id=o.id WHERE pv.item_id=? ORDER BY u.label');
  $detail->execute([$item['id']]);
  $detail = $detail->fetchAll();
?>
<div class="item-box">
  <div class="item-header">Bod <?= $item['order_num'] ?>. <?= htmlspecialchars($item['title']) ?></div>
  <div class="item-body">
    <?php if ($item['description']): ?><p style="font-size:8.5pt;color:#666;margin-bottom:8px"><?= htmlspecialchars($item['description']) ?></p><?php endif; ?>
    <table>
      <thead><tr><th>Hlas</th><th style="text-align:right">Podily %</th><th style="text-align:right">Pocet hlasu</th></tr></thead>
      <tbody>
        <tr><td style="color:#3B6D11;font-weight:bold">✓ PRO</td><td style="text-align:right"><strong><?= number_format($proPct,2,',','') ?> %</strong></td><td style="text-align:right"><?= $proCount ?>×</td></tr>
        <tr><td style="color:#A32D2D;font-weight:bold">✗ PROTI</td><td style="text-align:right"><strong><?= number_format($protiPct,2,',','') ?> %</strong></td><td style="text-align:right"><?= $protiCount ?>×</td></tr>
        <tr><td style="color:#666">— ZDRŽEL SE</td><td style="text-align:right"><strong><?= number_format($zdrzPct,2,',','') ?> %</strong></td><td style="text-align:right"><?= $zdrzCount ?>×</td></tr>
        <tr style="background:#f0f5fb"><td><strong>CELKEM hlasovalo</strong></td><td style="text-align:right"><strong><?= number_format($totalVotedPct,2,',','') ?> %</strong></td><td style="text-align:right"><?= $totalVoted ?>×</td></tr>
      </tbody>
    </table>

    <div class="maj-grid">
      <?php foreach ([
        ['Prosta vetsina', 'hlasujicich', $prostaMaj, $proPct, $totalVotedPct/2],
        ['Absolutni vetsina', 'vsech podilu >50 %', $absolutMaj, $proPct, $totalPct/2],
        ['Kvalifikovana vetsina', 'vsech podilu 75 %', $kvalifMaj, $proPct, $totalPct*0.75],
      ] as [$name, $sub, $ok, $has, $need]): ?>
      <div class="maj-box <?= $ok ? 'maj-ok' : 'maj-no' ?>">
        <div class="maj-name"><?= $name ?></div>
        <div class="maj-icon"><?= $ok ? '✓' : '✗' ?></div>
        <div class="maj-detail">Potreba: <?= number_format($need,2,',','') ?> %<br>Ziskano: <?= number_format($has,2,',','') ?> %</div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if ($detail): ?>
    <table style="margin-top:10px;font-size:8pt">
      <thead><tr><th>Jednotka</th><th>Vlastnik</th><th>Hlas</th></tr></thead>
      <tbody>
      <?php foreach ($detail as $d): ?>
        <tr>
          <td><?= htmlspecialchars($d['label']) ?></td>
          <td><?= htmlspecialchars($d['full_name']) ?></td>
          <td style="font-weight:bold"><?= match($d['vote']) { 'pro' => 'PRO', 'proti' => 'PROTI', default => 'ZDRŽEL SE' } ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>

<div class="sig-section">
  <p class="sig-date">Dokument vygenerovan dne <?= date('j. n. Y') ?> v <?= date('H:i') ?> hod.</p>
  <div class="sig-row">
    <div class="sig-line">Predseda vyboru SVJ<br><br><br>Jmeno a podpis</div>
    <div class="sig-line">Clen vyboru SVJ<br><br><br>Jmeno a podpis</div>
    <div class="sig-line">Clen kontrolni komise<br><br><br>Jmeno a podpis</div>
  </div>
</div>

<div class="copyright">System vytvoril &copy; <?= date('Y') ?> Medusoft</div>

</body>
</html>
