<?php
require_once __DIR__ . '/../includes/functions.php';
$user = requireAdmin();
$db = db();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: /admin/meetings.php'); exit; }

$meeting = $db->prepare('SELECT * FROM meetings WHERE id=?');
$meeting->execute([$id]);
$meeting = $meeting->fetch();
if (!$meeting) { header('Location: /admin/meetings.php'); exit; }

$attendance = $db->prepare(
    'SELECT ma.type, ma.proxy_name, o.full_name, u.label,
            ROUND(un.share_numerator/un.share_denominator*100,4) AS share_pct
     FROM meeting_attendance ma
     JOIN units un ON ma.unit_id=un.id
     JOIN owners o ON ma.owner_id=o.id
     JOIN units u ON ma.unit_id=u.id
     WHERE ma.meeting_id=? ORDER BY u.label'
);
$attendance->execute([$id]);
$attendance = $attendance->fetchAll();

$presentPct   = array_sum(array_column($attendance, 'share_pct'));
$presentCount = count($attendance);
$quorumOk     = $presentPct >= $meeting['quorum_pct'];
$totalUnits   = $db->query("SELECT COUNT(*) FROM units WHERE type='byt'")->fetchColumn();

$agendaItems = $db->prepare(
    'SELECT ai.*, mv.vote_pro, mv.vote_proti, mv.vote_zdrzelo,
            mv.vote_pro_count, mv.vote_proti_count, mv.vote_zdrzelo_count,
            mv.result, mv.note AS vote_note
     FROM meeting_agenda_items ai
     LEFT JOIN meeting_votes mv ON mv.agenda_item_id=ai.id
     WHERE ai.meeting_id=? ORDER BY ai.order_num'
);
$agendaItems->execute([$id]);
$agendaItems = $agendaItems->fetchAll();
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<title>Zapis – <?= htmlspecialchars($meeting['title']) ?></title>
<style>
  @page {
    size: A4 portrait;
    margin: 2.5cm 2cm 2cm 2cm;
  }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: Arial, sans-serif;
    font-size: 10.5pt;
    color: #111;
    background: #fff;
    padding: 2.5cm 2cm 2cm 2cm;
    max-width: 21cm;
    margin: 0 auto;
    line-height: 1.55;
  }

  /* HLAVIČKA */
  .svj-header {
    display: flex;
    align-items: stretch;
    border: 2px solid #185FA5;
    border-radius: 6px;
    margin-bottom: 20px;
    overflow: hidden;
  }
  .svj-header-left {
    background: #185FA5;
    color: white;
    padding: 12px 18px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    min-width: 160px;
  }
  .svj-header-left .svj-name {
    font-size: 13pt;
    font-weight: bold;
    line-height: 1.2;
    margin-bottom: 4px;
  }
  .svj-header-left .svj-sub {
    font-size: 8pt;
    opacity: 0.85;
  }
  .svj-header-right {
    padding: 10px 16px;
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 3px;
    background: #f0f5fb;
  }
  .svj-header-right p {
    font-size: 9pt;
    color: #333;
  }
  .svj-header-right strong {
    color: #185FA5;
  }

  /* NADPIS DOKUMENTU */
  .doc-title {
    text-align: center;
    margin: 18px 0 6px;
  }
  .doc-title h1 {
    font-size: 15pt;
    color: #185FA5;
    text-transform: uppercase;
    letter-spacing: 1px;
  }
  .doc-title p {
    font-size: 9.5pt;
    color: #555;
    margin-top: 3px;
  }
  .doc-divider {
    border: none;
    border-top: 2px solid #185FA5;
    margin: 10px 0 16px;
  }

  /* META BOX */
  .meta-box {
    display: flex;
    gap: 0;
    border: 1px solid #ccc;
    border-radius: 4px;
    margin-bottom: 14px;
    overflow: hidden;
    font-size: 9.5pt;
  }
  .meta-col {
    flex: 1;
    padding: 8px 12px;
    border-right: 1px solid #ccc;
  }
  .meta-col:last-child { border-right: none; }
  .meta-col .lbl { color: #666; font-size: 8.5pt; margin-bottom: 2px; }
  .meta-col .val { font-weight: bold; color: #111; }

  /* USNÁŠENÍSCHOPNOST */
  .quorum-box {
    padding: 10px 14px;
    border-radius: 4px;
    margin-bottom: 14px;
    display: flex;
    align-items: center;
    gap: 16px;
  }
  .quorum-ok  { background: #EAF3DE; border: 1px solid #8bc34a; }
  .quorum-no  { background: #FAEEDA; border: 1px solid #FAC775; }
  .quorum-num { font-size: 20pt; font-weight: bold; }
  .quorum-ok .quorum-num  { color: #3B6D11; }
  .quorum-no .quorum-num  { color: #854F0B; }
  .quorum-text { font-size: 10pt; font-weight: bold; }
  .quorum-ok .quorum-text { color: #3B6D11; }
  .quorum-no .quorum-text { color: #854F0B; }

  /* SEKCE */
  h2 {
    font-size: 11pt;
    color: #185FA5;
    border-bottom: 1.5px solid #185FA5;
    padding-bottom: 3px;
    margin: 18px 0 10px;
  }

  /* TABULKY */
  table { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 9.5pt; }
  th { background: #185FA5; color: white; padding: 5px 8px; text-align: left; font-size: 8.5pt; }
  td { padding: 4px 8px; border-bottom: 1px solid #e0e0e0; vertical-align: middle; }
  tr:nth-child(even) td { background: #f7f7f5; }
  .total-row td { font-weight: bold; background: #ddeeff !important; color: #185FA5; }

  /* HLASOVÁNÍ */
  .vote-box { border: 1px solid #ccc; border-radius: 4px; margin-bottom: 10px; }
  .vote-header { background: #f0f0ee; padding: 6px 12px; font-weight: bold; font-size: 10pt; border-bottom: 1px solid #ccc; }
  .vote-body { padding: 8px 12px; }
  .vote-table td { border-bottom: none; padding: 3px 8px; }
  .result-ok { color: #3B6D11; font-weight: bold; font-size: 11pt; margin-top: 6px; }
  .result-no { color: #A32D2D; font-weight: bold; font-size: 11pt; margin-top: 6px; }
  .result-other { color: #854F0B; font-weight: bold; font-size: 11pt; margin-top: 6px; }
  .note-text { font-size: 8.5pt; color: #666; font-style: italic; }

  /* PODPISY */
  .sig-section { margin-top: 35px; }
  .sig-date { font-size: 9pt; color: #555; margin-bottom: 24px; }
  .sig-row { display: flex; gap: 25px; }
  .sig-line { flex: 1; border-top: 1px solid #333; padding-top: 5px; font-size: 8.5pt; color: #555; }

  /* TISK */
  .print-btn {
    position: fixed; top: 15px; right: 15px;
    background: #185FA5; color: white; border: none;
    padding: 10px 20px; border-radius: 6px; font-size: 13px;
    cursor: pointer; box-shadow: 0 2px 8px rgba(0,0,0,.2);
    z-index: 999;
  }
  .print-btn:hover { background: #0C447C; }
  .page-break { page-break-before: always; }

  @media print {
    .print-btn { display: none !important; }
    body { padding: 0; }
    .svj-header { margin-top: 10px; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .quorum-box { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .vote-header { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  }
</style>
</head>
<body>
<div style="padding: 8mm 0;">

<button class="print-btn" onclick="window.print()">🖨 Tisk / PDF</button>

<!-- HLAVIČKA SVJ -->
<div class="svj-header">
  <div class="svj-header-left">
    <div class="svj-name">SVJ<br>Rozhled</div>
    <div class="svj-sub">Od Vysoké</div>
  </div>
  <div class="svj-header-right">
    <p><strong>Název:</strong> Spolecenstvi vlastniku jednotek Rozhled – Od Vysoke 271, 272, 273, 274, 275</p>
    <p><strong>Sidlo:</strong> Od Vysoke 275/2, Radlice, 150 00 Praha 5</p>
    <p><strong>ICO:</strong> 047 65 435 &nbsp;&nbsp; <strong>Datova schranka:</strong> dle rejstriku</p>
  </div>
</div>

<!-- NADPIS -->
<div class="doc-title">
  <h1>Zapis ze shromazdeni vlastniku</h1>
  <p><?= htmlspecialchars($meeting['title']) ?></p>
</div>
<hr class="doc-divider">

<!-- META INFORMACE -->
<div class="meta-box">
  <div class="meta-col">
    <div class="lbl">Datum konani</div>
    <div class="val"><?= date('j. n. Y', strtotime($meeting['meeting_date'])) ?><?= $meeting['meeting_time'] ? ', v '.substr($meeting['meeting_time'],0,5).' hod.' : '' ?></div>
  </div>
  <div class="meta-col">
    <div class="lbl">Misto konani</div>
    <div class="val"><?= htmlspecialchars($meeting['location'] ?: '—') ?></div>
  </div>
  <div class="meta-col">
    <div class="lbl">Pritomno jednotek</div>
    <div class="val"><?= $presentCount ?> z <?= $totalUnits ?></div>
  </div>
  <div class="meta-col">
    <div class="lbl">Pritomno podilu</div>
    <div class="val"><?= number_format($presentPct,4,',','') ?> %</div>
  </div>
  <div class="meta-col">
    <div class="lbl">Kvorum</div>
    <div class="val"><?= $meeting['quorum_pct'] ?> %</div>
  </div>
</div>

<!-- USNÁŠENÍSCHOPNOST -->
<div class="quorum-box <?= $quorumOk ? 'quorum-ok' : 'quorum-no' ?>">
  <div class="quorum-num"><?= number_format($presentPct,2,',','') ?> %</div>
  <div>
    <div class="quorum-text"><?= $quorumOk ? '✓ Shromazdeni je usnesenieschopne' : '⚠ Shromazdeni neni usnesenieschopne' ?></div>
    <div style="font-size:9pt;color:#555;margin-top:2px">Pritomno <?= $presentCount ?> z <?= $totalUnits ?> jednotek · Pozadovane kvorum <?= $meeting['quorum_pct'] ?> %</div>
  </div>
</div>

<!-- PROGRAM -->
<?php if ($meeting['agenda']): ?>
<h2>Program shromazdeni</h2>
<p style="font-size:9.5pt;line-height:1.7"><?= nl2br(htmlspecialchars($meeting['agenda'])) ?></p>
<?php endif; ?>

<!-- PREZENČNÍ LISTINA -->
<h2>Prezencni listina</h2>
<table>
  <thead>
    <tr><th style="width:30px">#</th><th style="width:70px">Jednotka</th><th>Vlastnik</th><th style="width:80px">Ucast</th><th style="width:70px;text-align:right">Podil %</th></tr>
  </thead>
  <tbody>
  <?php $i=1; foreach ($attendance as $a): ?>
  <tr>
    <td><?= $i++ ?></td>
    <td><strong><?= htmlspecialchars($a['label']) ?></strong></td>
    <td><?= htmlspecialchars($a['full_name']) ?><?php if ($a['proxy_name']): ?><br><span class="note-text">zastupuje: <?= htmlspecialchars($a['proxy_name']) ?></span><?php endif; ?></td>
    <td><?= htmlspecialchars($a['type']) ?></td>
    <td style="text-align:right"><?= number_format($a['share_pct'],4,',','') ?></td>
  </tr>
  <?php endforeach; ?>
  <tr class="total-row">
    <td colspan="4">CELKEM pritomno</td>
    <td style="text-align:right"><?= number_format($presentPct,4,',','') ?> %</td>
  </tr>
  </tbody>
</table>

<!-- VÝSLEDKY HLASOVÁNÍ -->
<?php if ($agendaItems): ?>
<h2 class="page-break">Vysledky hlasovani k bodum programu</h2>
<?php foreach ($agendaItems as $item): ?>
<div class="vote-box">
  <div class="vote-header">Bod <?= $item['order_num'] ?>. &nbsp; <?= htmlspecialchars($item['title']) ?></div>
  <div class="vote-body">
    <?php if ($item['description']): ?>
      <p class="note-text" style="margin-bottom:6px"><?= htmlspecialchars($item['description']) ?></p>
    <?php endif; ?>
    <?php if ($item['general_description'] ?? ''): ?>
      <div style="font-size:9pt;color:#333;margin-bottom:8px;padding:6px 10px;background:#f5f5f5;border-left:3px solid #ccc;border-radius:2px">
        <?= nl2br(htmlspecialchars($item['general_description'])) ?>
      </div>
    <?php endif; ?>
    <?php if ($item['resolution_proposal'] ?? ''): ?>
      <div style="font-size:9pt;margin-bottom:8px;padding:6px 10px;background:#EAF3DE;border-left:3px solid #3B6D11;border-radius:2px">
        <span style="font-size:8pt;font-weight:bold;color:#3B6D11;text-transform:uppercase;letter-spacing:.05em">Návrh usnesení: </span>
        <strong><?= nl2br(htmlspecialchars($item['resolution_proposal'])) ?></strong>
      </div>
    <?php endif; ?>

    <?php if ($item['vote_type'] === 'žádné'): ?>
      <p class="note-text">Informacni bod – bez hlasovani.</p>

    <?php elseif ($item['result']): ?>
      <?php $total = ($item['vote_pro']+$item['vote_proti']+$item['vote_zdrzelo']); ?>
      <table class="vote-table">
        <thead><tr><th>Vysledek hlasovani</th><th style="text-align:right">Podily %</th><th style="text-align:right">Pocet hlasu</th></tr></thead>
        <tbody>
          <tr><td>✓ PRO</td><td style="text-align:right;color:#3B6D11;font-weight:bold"><?= number_format($item['vote_pro'],2,',','') ?> %</td><td style="text-align:right"><?= $item['vote_pro_count'] ?>×</td></tr>
          <tr><td>✗ PROTI</td><td style="text-align:right;color:#A32D2D;font-weight:bold"><?= number_format($item['vote_proti'],2,',','') ?> %</td><td style="text-align:right"><?= $item['vote_proti_count'] ?>×</td></tr>
          <tr><td>— ZDRŽEL SE</td><td style="text-align:right;color:#666;font-weight:bold"><?= number_format($item['vote_zdrzelo'],2,',','') ?> %</td><td style="text-align:right"><?= $item['vote_zdrzelo_count'] ?>×</td></tr>
        </tbody>
      </table>
      <?php
        $resClass = match($item['result']) {
          'schváleno'   => 'result-ok',
          'neschváleno' => 'result-no',
          default       => 'result-other'
        };
        $resLabel = match($item['result']) {
          'schváleno'   => '✓ SCHVALENO',
          'neschváleno' => '✗ NESCHVALENO',
          default       => '— ODLOZENO'
        };
      ?>
      <p class="<?= $resClass ?>">→ <?= $resLabel ?></p>
      <?php if ($item['vote_note']): ?><p class="note-text"><?= htmlspecialchars($item['vote_note']) ?></p><?php endif; ?>

    <?php else: ?>
      <p class="note-text" style="color:#854F0B">⚠ Vysledek hlasovani nebyl zaznamenan.</p>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<!-- PODPISY -->
<div class="sig-section">
  <p class="sig-date">Zapis byl porizen dne <?= date('j. n. Y') ?> v <?= date('H:i') ?> hod.</p>
  <div class="sig-row">
    <div class="sig-line">Predseda shromazdeni<br><br><br>Jmeno a podpis</div>
    <div class="sig-line">Zapisovatel<br><br><br>Jmeno a podpis</div>

  </div>
</div>

<div style="margin-top:30px;padding-top:10px;border-top:1px solid #ddd;text-align:right;font-size:7.5pt;color:#aaa;font-style:italic">
  Systém vytvořil &copy; <?= date('Y') ?> Medusoft
</div>

</div>
</body>
</html>
