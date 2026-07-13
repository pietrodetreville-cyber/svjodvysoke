<?php
require_once __DIR__ . '/../includes/functions.php';
$user = requireAdmin();
$db = db();

$meetingId = (int)($_GET['meeting_id'] ?? 0);
$meeting = null;
if ($meetingId) {
    $s = $db->prepare('SELECT * FROM meetings WHERE id=?');
    $s->execute([$meetingId]);
    $meeting = $s->fetch();
}

$onlyPresent = !empty($_GET['only_present']) && $meetingId;

if ($onlyPresent) {
    $s = $db->prepare(
        "SELECT u.label, u.share_numerator, u.share_denominator,
                ROUND(u.share_numerator/u.share_denominator*100,4) AS share_pct,
                o.full_name, o.email, o.email2, o.primary_email,
                o.phone, o.phone2, o.primary_phone,
                o.persons_count, o.residence,
                ma.type AS attend_type, ma.proxy_name
         FROM meeting_attendance ma
         JOIN units u ON ma.unit_id = u.id
         LEFT JOIN owners o ON o.unit_id = u.id
         WHERE ma.meeting_id = ?
         ORDER BY CAST(SUBSTRING_INDEX(u.label,'/',1) AS UNSIGNED),
                  CAST(SUBSTRING_INDEX(u.label,'/',-1) AS UNSIGNED)"
    );
    $s->execute([$meetingId]);
    $owners = $s->fetchAll();
} else {
    $owners = $db->query(
        "SELECT u.label, u.share_numerator, u.share_denominator,
                ROUND(u.share_numerator/u.share_denominator*100,4) AS share_pct,
                o.full_name, o.email, o.email2, o.primary_email,
                o.phone, o.phone2, o.primary_phone,
                o.persons_count, o.residence,
                NULL AS attend_type, NULL AS proxy_name
         FROM units u
         LEFT JOIN owners o ON o.unit_id = u.id
         WHERE u.type = 'byt' AND u.share_numerator IS NOT NULL
         ORDER BY CAST(SUBSTRING_INDEX(u.label,'/',1) AS UNSIGNED),
                  CAST(SUBSTRING_INDEX(u.label,'/',-1) AS UNSIGNED)"
    )->fetchAll();
}

$format = $_GET['format'] ?? 'docx';

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="prezencni_listina.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['Č.','Jednotka','Vlastník','E-mail','Telefon','Počet osob','Přítomen/Zmocněnec','Podpis','Poznámka'], ';');
    $i = 1;
    foreach ($owners as $o) {
        $mainEmail = ($o['primary_email']??1)==2&&$o['email2'] ? $o['email2'] : ($o['email']??'');
        $mainPhone = ($o['primary_phone']??1)==2&&$o['phone2'] ? $o['phone2'] : ($o['phone']??'');
        fputcsv($out, [$i, $o['label'], $o['full_name']??'', $mainEmail, $mainPhone, $o['persons_count']??'','','',''], ';');
        fputcsv($out, ['','','↑ oprava','','','','','',''], ';');
        $i++;
    }
    fclose($out);
    exit;
}

// === DOCX ===
// Použij PhpWord nebo vygeneruj přes Node.js na serveru
// Jednodušší: přesměruj na Node script
// Protože nemáme Node na sdíleném hostingu, použijeme HTML→print

$nazev = $meeting ? $meeting['title'] : 'Shromáždění vlastníků';
if ($onlyPresent) $nazev .= ' — PŘÍTOMNÍ';
$datum = $meeting ? date('j. n. Y', strtotime($meeting['meeting_date'])) : '________________';
$misto = $meeting['location'] ?? '________________';

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<title>Prezenční listina</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: Arial, sans-serif; font-size: 9pt; color: #000; }
@page { size: A4 landscape; margin: 10mm 8mm; }
@media print { body { margin: 0; } .no-print { display: none; } }

h1 { font-size: 13pt; text-align: center; margin-bottom: 4mm; }
.meta { display: flex; gap: 10mm; margin-bottom: 4mm; font-size: 9pt; }
.meta span { border-bottom: 1px solid #000; min-width: 60mm; display: inline-block; }

table { width: 100%; border-collapse: collapse; }
thead { display: table-header-group; }
th {
    background: #1F497D;
    color: #fff;
    font-size: 8pt;
    font-weight: bold;
    padding: 2mm 1.5mm;
    border: 0.5pt solid #000;
    text-align: center;
    vertical-align: middle;
}
td {
    border: 0.5pt solid #999;
    padding: 1mm 1.5mm;
    vertical-align: middle;
    font-size: 8pt;
}
tr.data-row { background: #fff; }
tr.data-row:nth-child(4n+1) { background: #E8F0FB; }
tr.empty-row td {
    background: #FFFDE7;
    color: #aaa;
    font-style: italic;
    font-size: 7pt;
    height: 5mm;
    border-top: none;
}
tr.empty-row td:first-child { border-top: 0.5pt solid #999; }
.num { text-align: center; }
.pct { text-align: center; font-size: 7.5pt; color: #555; }
tfoot td { background: #1F497D; color: #fff; font-weight: bold; font-size: 8pt; padding: 2mm; border: 0.5pt solid #000; }

.sig { margin-top: 8mm; display: flex; justify-content: space-between; }
.sig div { width: 80mm; border-top: 1pt solid #000; padding-top: 2mm; font-size: 8pt; }

.btn-print { display: inline-block; margin: 5mm; padding: 8px 20px; background: #1F497D; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 11pt; }
</style>
</head>
<body>

<div class="no-print" style="text-align:center;padding:5mm;background:#f0f0f0;margin-bottom:5mm">
  <button class="btn-print" onclick="window.print()">🖨 Tisknout / Uložit jako PDF</button>
  <a href="?<?= $meetingId ? "meeting_id=$meetingId&" : "" ?>format=csv" style="margin-left:10px;font-size:10pt">⬇ Stáhnout CSV</a>
  <a href="/admin/meetings.php" style="margin-left:10px;font-size:10pt">← Zpět</a>
</div>

<h1>PREZENČNÍ LISTINA — SVJ Od Vysoké – Rozhled</h1>

<div class="meta">
  <div>Shromáždění: <span><?= htmlspecialchars($nazev) ?></span></div>
  <div>Datum: <span><?= htmlspecialchars($datum) ?></span></div>
  <div>Místo: <span><?= htmlspecialchars($misto) ?></span></div>
</div>

<table>
  <thead>
    <tr>
      <th style="width:6mm">č.</th>
      <th style="width:14mm">Jednotka</th>
      <th style="width:60mm">Vlastník / Spoluvlastník</th>
      <th style="width:44mm">E-mail</th>
      <th style="width:26mm">Telefon</th>
      <th style="width:10mm">Poč. osob</th>
      <th style="width:10mm">Váha %</th>
      <th style="width:22mm">Přítomen / Zmocněnec</th>
      <th style="width:26mm">Podpis</th>
      <th style="width:30mm">Poznámka</th>
    </tr>
  </thead>
  <tbody>
<?php
$totalPct = 0;
$i = 1;
foreach ($owners as $o):
    $mainEmail = ($o['primary_email']??1)==2&&$o['email2'] ? $o['email2'] : ($o['email']??'');
    $mainPhone = ($o['primary_phone']??1)==2&&$o['phone2'] ? $o['phone2'] : ($o['phone']??'');
    $totalPct += (float)$o['share_pct'];
?>
    <tr class="data-row">
      <td class="num"><?= $i ?></td>
      <td class="num"><strong><?= htmlspecialchars($o['label']) ?></strong></td>
      <td><?= htmlspecialchars($o['full_name']??'') ?></td>
      <td><?= htmlspecialchars($mainEmail) ?></td>
      <td><?= htmlspecialchars($mainPhone) ?></td>
      <td class="num"><?= $o['persons_count'] ?? '' ?></td>
      <td class="pct"><?= $o['share_pct'] ?>%</td>
      <td><?php
        if ($o['attend_type'] === 'zmocnenec') echo htmlspecialchars($o['proxy_name'] ?? 'zmocněnec');
        elseif ($o['attend_type'] === 'pritomen') echo '✓ osobně';
      ?></td>
      <td></td>
      <td></td>
    </tr>
    <tr class="empty-row">
      <td></td>
      <td></td>
      <td>oprava / doplnění</td>
      <td></td>
      <td></td>
      <td></td>
      <td></td>
      <td></td>
      <td></td>
      <td></td>
    </tr>
<?php $i++; endforeach; ?>
  </tbody>
  <tfoot>
    <tr>
      <td colspan="5">CELKEM: <?= count($owners) ?> hlasovacích jednotek</td>
      <td></td>
      <td class="num"><?= round($totalPct, 2) ?>%</td>
      <td colspan="3"></td>
    </tr>
  </tfoot>
</table>

<div class="sig">
  <div>Předseda výboru: <?= htmlspecialchars($meeting['title'] ?? '') ?><br>&nbsp;</div>
  <div>Zapisovatel:<br>&nbsp;</div>
  <div>Datum: <?= htmlspecialchars($datum) ?><br>&nbsp;</div>
</div>

</body>
</html>
