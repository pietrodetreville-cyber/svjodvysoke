<?php
/**
 * SNIPPET: Sekce spotřeb pro admin/owner_detail.php
 * SVJ Rozhled – Od Vysoké
 *
 * Vložit do owner_detail.php za kartu vlastníka, před include footer.
 * Předpokládá: $unit['id'] je dostupné (ID jednotky).
 */

// ── Načtení spotřeb pro tuto jednotku ────────────────────────────────────────
$cons_rok = (int)($_GET['cons_rok'] ?? 2025);

$cons = $db->prepare("
    SELECT rok, mesic, typ, jednotka, hodnota_zacatek, hodnota_konec, spotreba
    FROM consumption
    WHERE unit_id = ? AND rok = ?
    ORDER BY mesic ASC, typ ASC
");
$cons->execute([$unit['id'], $cons_rok]);
$cons_rows = $cons->fetchAll(PDO::FETCH_ASSOC);

// Dostupné roky pro filtr
$cons_roky = $db->prepare("SELECT DISTINCT rok FROM consumption WHERE unit_id = ? ORDER BY rok DESC");
$cons_roky->execute([$unit['id']]);
$cons_roky = $cons_roky->fetchAll(PDO::FETCH_COLUMN);

// Pivot: [mesic][typ] => row
$pivot = [];
$mesice_nazvy = [
    1=>'Leden',2=>'Únor',3=>'Březen',4=>'Duben',5=>'Květen',6=>'Červen',
    7=>'Červenec',8=>'Srpen',9=>'Září',10=>'Říjen',11=>'Listopad',12=>'Prosinec'
];
foreach ($cons_rows as $r) {
    $pivot[$r['mesic']][$r['typ']] = $r;
}

// Součty
$soucty = ['SV' => 0, 'TV' => 0, 'ITN' => 0];
foreach ($cons_rows as $r) {
    $soucty[$r['typ']] += $r['spotreba'];
}
?>

<!-- ══════════════════════════════════════════════════════
     SEKCE: Měsíční přehled spotřeb
     ══════════════════════════════════════════════════════ -->
<div class="card" style="margin-top:1.5rem">

  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:1rem">
    <div class="card-title" style="margin-bottom:0">📊 Spotřeby <?= $cons_rok ?></div>
    <?php if (count($cons_roky) > 1): ?>
    <div style="display:flex;gap:6px;flex-wrap:wrap">
      <?php foreach ($cons_roky as $r): ?>
      <a href="?id=<?= $unit['id'] ?>&cons_rok=<?= $r ?>"
         class="btn btn-sm <?= $r == $cons_rok ? 'btn-primary' : 'btn-secondary' ?>">
        <?= $r ?>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <?php if (!$pivot): ?>
    <p style="color:var(--muted);font-size:14px">Pro tuto jednotku nejsou evidovány žádné spotřeby za rok <?= $cons_rok ?>.</p>
  <?php else: ?>

  <!-- Souhrn nahoře -->
  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:1rem">
    <div style="background:var(--gray-lt);border-radius:8px;padding:10px 16px;min-width:120px">
      <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em">🚿 Studená voda</div>
      <div style="font-size:1.3rem;font-weight:700;color:var(--blue)"><?= number_format($soucty['SV'], 3, ',', ' ') ?> <span style="font-size:12px;font-weight:400">m³</span></div>
    </div>
    <div style="background:var(--gray-lt);border-radius:8px;padding:10px 16px;min-width:120px">
      <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em">🌡️ Teplá voda</div>
      <div style="font-size:1.3rem;font-weight:700;color:var(--red)"><?= number_format($soucty['TV'], 3, ',', ' ') ?> <span style="font-size:12px;font-weight:400">m³</span></div>
    </div>
    <div style="background:var(--gray-lt);border-radius:8px;padding:10px 16px;min-width:120px">
      <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em">🔥 Teplo</div>
      <div style="font-size:1.3rem;font-weight:700;color:var(--amber)"><?= number_format($soucty['ITN'], 0, ',', ' ') ?> <span style="font-size:12px;font-weight:400">dílků</span></div>
    </div>
  </div>

  <!-- Tabulka měsíčních hodnot -->
  <div style="overflow-x:auto">
  <table class="tbl" style="font-size:13px">
    <thead>
      <tr>
        <th style="min-width:90px">Měsíc</th>
        <th colspan="2" style="text-align:center;color:var(--blue)">🚿 Stud. voda (m³)</th>
        <th colspan="2" style="text-align:center;color:var(--red)">🌡️ Teplá voda (m³)</th>
        <th colspan="2" style="text-align:center;color:var(--amber)">🔥 Teplo (dílků)</th>
      </tr>
      <tr style="font-size:11px;color:var(--muted)">
        <th></th>
        <th style="text-align:right">Spotřeba</th>
        <th style="text-align:right">Stav</th>
        <th style="text-align:right">Spotřeba</th>
        <th style="text-align:right">Stav</th>
        <th style="text-align:right">Spotřeba</th>
        <th style="text-align:right">Stav</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($mesice_nazvy as $m => $nazev):
        if (!isset($pivot[$m])) continue;
        $sv  = $pivot[$m]['SV']  ?? null;
        $tv  = $pivot[$m]['TV']  ?? null;
        $itn = $pivot[$m]['ITN'] ?? null;
    ?>
    <tr>
      <td style="font-weight:500"><?= $nazev ?></td>

      <!-- SV -->
      <td style="text-align:right;font-weight:600;color:var(--blue)">
        <?= $sv ? number_format($sv['spotreba'], 3, ',', ' ') : '–' ?>
      </td>
      <td style="text-align:right;font-size:11px;color:var(--muted)">
        <?= $sv ? number_format($sv['hodnota_konec'], 3, ',', ' ') : '–' ?>
      </td>

      <!-- TV -->
      <td style="text-align:right;font-weight:600;color:var(--red)">
        <?= $tv ? number_format($tv['spotreba'], 3, ',', ' ') : '–' ?>
      </td>
      <td style="text-align:right;font-size:11px;color:var(--muted)">
        <?= $tv ? number_format($tv['hodnota_konec'], 3, ',', ' ') : '–' ?>
      </td>

      <!-- ITN -->
      <td style="text-align:right;font-weight:600;color:var(--amber)">
        <?= $itn ? number_format($itn['spotreba'], 0, ',', ' ') : '–' ?>
      </td>
      <td style="text-align:right;font-size:11px;color:var(--muted)">
        <?= $itn ? number_format($itn['hodnota_konec'], 0, ',', ' ') : '–' ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr style="font-weight:700;background:var(--gray-lt)">
        <td>Celkem <?= $cons_rok ?></td>
        <td style="text-align:right;color:var(--blue)"><?= number_format($soucty['SV'], 3, ',', ' ') ?></td>
        <td></td>
        <td style="text-align:right;color:var(--red)"><?= number_format($soucty['TV'], 3, ',', ' ') ?></td>
        <td></td>
        <td style="text-align:right;color:var(--amber)"><?= number_format($soucty['ITN'], 0, ',', ' ') ?></td>
        <td></td>
      </tr>
    </tfoot>
  </table>
  </div>

  <?php endif; ?>
</div>
<!-- ══ konec sekce spotřeb ══ -->
