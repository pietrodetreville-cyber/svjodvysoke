<?php
require_once __DIR__ . '/../includes/functions.php';
$user = requireAdmin();
$pageTitle = 'Jednotky';
$db = db();

// Přidat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    csrfCheck();
    $type = $_POST['type'];
    $db->prepare('INSERT INTO units (label,type,floor,area_m2,share_numerator,share_denominator) VALUES (?,?,?,?,?,?)')
       ->execute([
           trim($_POST['label']), $type,
           $_POST['floor'] !== '' ? (int)$_POST['floor'] : null,
           $type === 'byt' && $_POST['area_m2'] !== '' ? (float)$_POST['area_m2'] : null,
           $type === 'byt' && $_POST['share_num'] !== '' ? (int)$_POST['share_num'] : null,
           $type === 'byt' && $_POST['share_den'] !== '' ? (int)$_POST['share_den'] : null,
       ]);
    flash('Jednotka přidána.', 'success');
    header('Location: /admin/units.php'); exit;
}

// Upravit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    csrfCheck();
    $type = $_POST['type'];
    $isGarage = ($type !== 'byt');
    if ($isGarage) {
        $db->prepare('UPDATE units SET label=?,type=?,floor=NULL,area_m2=NULL,share_numerator=NULL,share_denominator=NULL WHERE id=?')
           ->execute([trim($_POST['label']), $type, (int)$_POST['id']]);
    } else {
        if (isset($_POST['garage_unit_id'])) {
            $db->prepare('UPDATE units SET linked_unit_id=NULL WHERE linked_unit_id=?')->execute([(int)$_POST['id']]);
            if (!empty($_POST['garage_unit_id']))
                $db->prepare('UPDATE units SET linked_unit_id=? WHERE id=?')->execute([(int)$_POST['id'], (int)$_POST['garage_unit_id']]);
        }
        $db->prepare('UPDATE units SET label=?,type=?,floor=?,area_m2=?,share_numerator=?,share_denominator=? WHERE id=?')
           ->execute([trim($_POST['label']), $type,
               $_POST['floor'] !== '' ? (int)$_POST['floor'] : null,
               $_POST['area_m2'] !== '' ? (float)$_POST['area_m2'] : null,
               $_POST['share_num'] !== '' ? (int)$_POST['share_num'] : null,
               $_POST['share_den'] !== '' ? (int)$_POST['share_den'] : null,
               (int)$_POST['id']]);
    }
    flash('Jednotka uložena.', 'success');
    header('Location: /admin/units.php' . (isset($_POST['return_id']) ? '#row-'.$_POST['return_id'] : '')); exit;
}

// Smazat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrfCheck();
    $db->prepare('DELETE FROM units WHERE id=?')->execute([(int)$_POST['id']]);
    flash('Jednotka smazána.', 'success');
    header('Location: /admin/units.php'); exit;
}

// ── Import XLSX spotřeb (Techem Bi-Weekly) ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_csv') {
    csrfCheck();
    $file = $_FILES['csv_file'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        flash('Vyberte XLSX soubor.', 'error');
        header('Location: /admin/units.php'); exit;
    }

    // Uložit dočasně
    $tmpPath = sys_get_temp_dir() . '/techem_import_' . uniqid() . '.xlsx';
    move_uploaded_file($file['tmp_name'], $tmpPath);

    // Zpracovat PHP skriptem přes exec — Webglobe nemá PhpSpreadsheet
    // Alternativa: Python zpracování přímo v PHP přes system call
    // Použijeme vlastní PHP parser pro XLSX (ZIP + XML)
    $inserted = 0;
    $skipped  = 0;
    $errors   = [];

    try {
        // XLSX je ZIP archiv — rozbalíme shared strings a sheet data
        $zip = new ZipArchive();
        if ($zip->open($tmpPath) !== true) throw new Exception('Nelze otevřít XLSX soubor.');

        // Shared strings
        $sharedStrings = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml) {
            $ss = new SimpleXMLElement($ssXml);
            foreach ($ss->si as $si) {
                $val = '';
                if (isset($si->t)) { $val = (string)$si->t; }
                else { foreach ($si->r as $r) { if (isset($r->t)) $val .= (string)$r->t; } }
                $sharedStrings[] = $val;
            }
        }

        // Sheet XML — zkus oba varianty (sheet1.xml i Sheet1.xml)
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if (!$sheetXml) $sheetXml = $zip->getFromName('xl/worksheets/Sheet1.xml');
        // Pokud stále nenalezen, zkus workbook.xml.rels pro skutečný název
        if (!$sheetXml) {
            $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
            if ($relsXml) {
                preg_match_all('/Target="worksheets\/([^"]+)"/', $relsXml, $m);
                foreach ($m[1] ?? [] as $sheetFile) {
                    $sheetXml = $zip->getFromName('xl/worksheets/' . $sheetFile);
                    if ($sheetXml) break;
                }
            }
        }
        $zip->close();
        if (!$sheetXml) throw new Exception('Sheet nenalezen v XLSX souboru.');

        $sheet = new SimpleXMLElement($sheetXml);
        $ns = $sheet->getNamespaces(true);
        $rows_data = [];

        foreach ($sheet->sheetData->row as $row) {
            $rowArr = [];
            foreach ($row->c as $cell) {
                $t = (string)($cell['t'] ?? '');
                $v = isset($cell->v) ? (string)$cell->v : null;
                if ($t === 's' && $v !== null) { $v = $sharedStrings[(int)$v] ?? ''; }
                elseif ($v !== null && is_numeric($v)) { $v = $v + 0; }
                $rowArr[] = $v;
            }
            $rows_data[] = $rowArr;
        }

        if (empty($rows_data)) throw new Exception('Soubor neobsahuje data.');

        $header = $rows_data[0];
        $numCols = count($header);

        // Najdi datumové sloupce (Excel serial date > 40000 = po roce 2009)
        // a rozlišíme konec měsíce
        $monthEndCols = []; // (rok, mesic) => col_index
        $allDateCols  = []; // col_index => (rok, mesic, den)

        for ($i = 10; $i < $numCols; $i++) {
            $val = $header[$i];
            if (!is_numeric($val) || $val < 40000) continue;
            // Excel serial date -> datum
            $unix = ($val - 25569) * 86400;
            $dt   = new DateTime('@' . (int)$unix);
            $dt->setTimezone(new DateTimeZone('Europe/Prague'));
            $rok  = (int)$dt->format('Y');
            $mesic= (int)$dt->format('n');
            $den  = (int)$dt->format('j');
            $lastDay = (int)(new DateTime("$rok-$mesic-01"))->format('t');
            $allDateCols[$i] = [$rok, $mesic, $den];
            if ($den === $lastDay) {
                $monthEndCols["$rok-$mesic"] = $i;
            }
        }

        if (empty($monthEndCols)) throw new Exception('Nebyly nalezeny datumové sloupce s konci měsíců.');

        // Seřadit měsíce
        uksort($monthEndCols, function($a, $b) { return strcmp($a, $b); });
        $sortedMonths = array_keys($monthEndCols);

        // Načíst mapování techem_id → units.id
        $techemMap = [];
        try {
            $tm = $db->query("SELECT id, techem_id FROM units WHERE type='byt' AND techem_id IS NOT NULL");
            foreach ($tm->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $techemMap[(int)$row['techem_id']] = (int)$row['id'];
            }
        } catch (\PDOException $e) {}

        if (empty($techemMap)) {
            throw new Exception('Tabulka units neobsahuje sloupec techem_id. Spusťte nejprve add_techem_id.sql v SQL konzoli.');
        }

        // Agregace: (unit_id, rok, mesic, typ) => {zacatek, konec, spotreba}
        $agg = [];

        foreach (array_slice($rows_data, 1) as $row) {
            $bytStr = $row[3] ?? null;
            $typ    = strtoupper(trim($row[7] ?? ''));
            if (!$bytStr || !in_array($typ, ['SV','TV','ITN'])) continue;
            $techemId = (int)ltrim((string)$bytStr, '0') ?: 1;
            $unitId   = $techemMap[$techemId] ?? null;
            if (!$unitId) continue; // neznámý byt — přeskočit

            foreach ($sortedMonths as $idx => $monthKey) {
                $colKonec = $monthEndCols[$monthKey];
                $valKonec = isset($row[$colKonec]) ? (float)$row[$colKonec] : null;
                if ($valKonec === null) continue;

                // Stav začátek = konec předchozího měsíce
                $valZac = null;
                if ($idx > 0) {
                    $prevKey  = $sortedMonths[$idx - 1];
                    $colPrev  = $monthEndCols[$prevKey];
                    $valZac   = isset($row[$colPrev]) ? (float)$row[$colPrev] : null;
                }

                // Přeskočit první měsíc bez předchozího stavu (nelze počítat spotřebu)
                if ($valZac === null) continue;
                $spotreba = $valKonec - $valZac;
                if ($spotreba < 0) continue; // přeskočit záporné (reset měřidla)

                [$rok, $mesic] = explode('-', $monthKey);
                $key = "$unitId-$rok-$mesic-$typ";

                if (!isset($agg[$key])) {
                    $agg[$key] = ['unit_id'=>(int)$unitId,'rok'=>(int)$rok,'mesic'=>(int)$mesic,'typ'=>$typ,'zac'=>$valZac,'kon'=>$valKonec,'spo'=>$spotreba];
                } else {
                    // Sečíst více přístrojů stejného typu (ITN má 2+)
                    $agg[$key]['kon'] += $valKonec;
                    $agg[$key]['zac'] += $valZac;
                    $agg[$key]['spo'] += $spotreba;
                }
            }
        }

        // Uložit do DB
        $stmt = $db->prepare("
            INSERT INTO consumption (unit_id, rok, mesic, typ, jednotka, hodnota_zacatek, hodnota_konec, spotreba)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE hodnota_zacatek=VALUES(hodnota_zacatek), hodnota_konec=VALUES(hodnota_konec), spotreba=VALUES(spotreba)
        ");

        foreach ($agg as $rec) {
            $jednotka = $rec['typ'] === 'ITN' ? 'dily' : 'm3';
            try {
                $stmt->execute([$rec['unit_id'], $rec['rok'], $rec['mesic'], $rec['typ'], $jednotka,
                                round($rec['zac'], 3), round($rec['kon'], 3), round($rec['spo'], 3)]);
                $inserted++;
            } catch (\PDOException $e) {
                $errors[] = "Byt {$rec['unit_id']} / {$rec['rok']}-{$rec['mesic']} / {$rec['typ']}";
            }
        }

        $msg = "Import dokončen: $inserted záznamů uloženo";
        $msg .= ' (' . count($sortedMonths) . ' měsíců, ' . count(array_unique(array_column(array_values($agg), 'unit_id'))) . ' bytů).';
        if ($errors) $msg .= ' Chyby: ' . implode(', ', array_slice($errors, 0, 5));
        flash($msg, $errors ? 'warning' : 'success');

    } catch (Exception $e) {
        flash('Chyba importu: ' . $e->getMessage(), 'error');
    }

    @unlink($tmpPath);
    header('Location: /admin/units.php'); exit;
}

// ── Uložit spotřebu ručně ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_consumption') {
    csrfCheck();
    $unit_id  = (int)$_POST['cons_unit_id'];
    $rok      = (int)$_POST['cons_rok'];
    $mesic    = (int)$_POST['cons_mesic'];
    $typ      = $_POST['cons_typ'];
    $jednotka = $typ === 'ITN' ? 'dily' : 'm3';
    $zac      = $_POST['cons_zacatek'] !== '' ? (float)$_POST['cons_zacatek'] : null;
    $kon      = $_POST['cons_konec']   !== '' ? (float)$_POST['cons_konec']   : null;
    $spo      = (float)$_POST['cons_spotreba'];
    if ($unit_id && $rok && $mesic && in_array($typ, ['SV','TV','ITN'])) {
        try {
            $db->prepare("
                INSERT INTO consumption (unit_id, rok, mesic, typ, jednotka, hodnota_zacatek, hodnota_konec, spotreba)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE hodnota_zacatek=VALUES(hodnota_zacatek), hodnota_konec=VALUES(hodnota_konec), spotreba=VALUES(spotreba)
            ")->execute([$unit_id, $rok, $mesic, $typ, $jednotka, $zac, $kon, $spo]);
            flash('Spotřeba uložena.', 'success');
        } catch (\PDOException $e) { flash('Chyba: ' . $e->getMessage(), 'error'); }
    }
    header("Location: /admin/units.php?cons={$unit_id}&cons_rok={$rok}"); exit;
}

// ── Smazat spotřebu ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_consumption') {
    csrfCheck();
    $cid     = (int)$_POST['cons_id'];
    $unit_id = (int)$_POST['cons_unit_id'];
    $rok     = (int)$_POST['cons_rok'];
    $db->prepare("DELETE FROM consumption WHERE id=?")->execute([$cid]);
    flash('Záznam smazán.', 'success');
    header("Location: /admin/units.php?cons={$unit_id}&cons_rok={$rok}"); exit;
}

// ── Smazat VŠECHNY spotřeby ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_all_consumption') {
    csrfCheck();
    $db->query("DELETE FROM consumption");
    flash('Všechny záznamy spotřeb byly smazány.', 'success');
    header('Location: /admin/units.php'); exit;
}

$editingId = isset($_GET['edit']) ? (int)$_GET['edit'] : null;

$consUnitId = isset($_GET['cons']) ? (int)$_GET['cons'] : null;
$consRok     = isset($_GET['cons_rok']) ? (int)$_GET['cons_rok'] : (int)date('Y');

$units = $db->query(
    "SELECT u.*, o.full_name AS owner_name,
     g.id AS garage_id, g.label AS garage_label,
     CASE WHEN u.share_numerator IS NOT NULL AND u.share_denominator > 0
          THEN ROUND(u.share_numerator / u.share_denominator * 100, 4)
          ELSE NULL END AS share_pct
     FROM units u
     LEFT JOIN owners o ON o.unit_id=u.id
     LEFT JOIN units g ON g.linked_unit_id=u.id AND g.type != 'byt'
     ORDER BY CASE WHEN u.type = 'byt' THEN 0 ELSE 1 END,
              CAST(SUBSTRING_INDEX(u.label,'/',1) AS UNSIGNED),
              CAST(SUBSTRING_INDEX(u.label,'/',-1) AS UNSIGNED)"
)->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<style>
/* === DESKTOP === */
.units-desktop{display:block}
.units-mobile{display:none}

/* === MOBILE === */
@media(max-width:700px){
  .units-desktop{display:none}
  .units-mobile{display:block}
  .unit-card{background:#fff;border:1px solid var(--border);border-radius:var(--radius);padding:.75rem 1rem;margin-bottom:.5rem;cursor:pointer;transition:box-shadow .15s;display:flex;align-items:center;justify-content:space-between}
  .unit-card:active{box-shadow:0 2px 8px rgba(0,0,0,.15)}
  .unit-card.is-garage{border-left:3px solid var(--amber)}
  .unit-card-label{font-weight:700;font-size:15px}
  .unit-card-sub{font-size:12px;color:var(--muted);margin-top:2px}
  .unit-card-right{font-size:11px;color:var(--muted);text-align:right}
  .unit-drawer{display:none;background:var(--gray-lt);border:1px solid #A8C8E8;border-top:3px solid #A8C8E8;border-radius:0 0 var(--radius) var(--radius);padding:1rem;margin-top:-6px;margin-bottom:.5rem}
  .unit-drawer.open{display:block}
  .unit-card.active{border-radius:var(--radius) var(--radius) 0 0;border-bottom-color:transparent;box-shadow:0 2px 8px rgba(0,0,0,.1)}
  .form-row{flex-direction:column;gap:.5rem}
}

/* Inline edit desktop */
.inline-edit-row{display:none}
.inline-edit-row.open{display:table-row}
tr.editing-row{background:#f0f7ff!important}
.sticky-edit-banner{display:none;position:sticky;top:54px;z-index:20;background:#E6F1FB;border-bottom:2px solid #A8C8E8;padding:6px 16px;font-size:13px;font-weight:600;color:#185FA5}
</style>

<div class="page-hd">
  <h1>Jednotky domu</h1>
  <div style="display:flex;gap:8px">
    <button class="btn btn-secondary btn-sm" onclick="document.getElementById('import-panel').style.display=document.getElementById('import-panel').style.display==='none'?'block':'none'">⬆ Import CSV</button>
    <a class="btn btn-primary" href="?add=1">+ Přidat</a>
  </div>
</div>

<!-- IMPORT XLSX PANEL -->
<div id="import-panel" style="display:none;margin-bottom:1rem">
<div class="card" style="border-top:3px solid var(--blue);max-width:720px">

  <div style="font-size:14px;font-weight:600;color:var(--blue);margin-bottom:.75rem">📥 Import spotřeb — Techem XLSX</div>

  <!-- Stav databáze -->
  <?php
  $cons_stats = [];
  try {
      $cs = $db->query("SELECT rok, COUNT(DISTINCT unit_id) as bytu, COUNT(*) as zaznamu FROM consumption GROUP BY rok ORDER BY rok DESC");
      $cons_stats = $cs->fetchAll(PDO::FETCH_ASSOC);
  } catch (\PDOException $e) {}
  ?>
  <?php if ($cons_stats): ?>
  <div style="margin-bottom:1rem">
    <div style="font-size:12px;font-weight:600;color:var(--muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em">Aktuálně v databázi</div>
    <div style="display:flex;gap:6px;flex-wrap:wrap">
      <?php foreach ($cons_stats as $cs): ?>
      <div style="background:var(--blue-lt);border:1px solid #b5d0f0;border-radius:6px;padding:6px 12px;font-size:13px">
        <strong style="color:var(--blue)"><?= $cs['rok'] ?></strong>
        <span style="color:var(--muted);margin-left:4px"><?= $cs['bytu'] ?> bytů · <?= $cs['zaznamu'] ?> záznamů</span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php else: ?>
  <div style="background:var(--amber-lt);border:1px solid #FAC775;border-radius:6px;padding:8px 12px;font-size:13px;color:var(--amber);margin-bottom:1rem">
    ⚠ Databáze neobsahuje žádné záznamy spotřeb.
  </div>
  <?php endif; ?>

  <p style="font-size:13px;color:var(--muted);margin-bottom:1rem">
    Formát: <strong>XLSX</strong> — Techem export „Bi-Weekly Reading Values".
    Systém spočítá měsíční spotřeby z dvoutýdenních odečtů, sečte více přístrojů (ITN) a uloží výsledky.
    <strong style="color:var(--green)">Existující záznamy jsou bezpečně přepsány</strong> — importovat lze opakovaně a přesahy mezi exporty nevadí.
    Najednou lze nahrát jeden soubor, pro více let opakuj import pro každý rok zvlášť.
  </p>

  <form method="POST" enctype="multipart/form-data" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="action" value="import_csv">
    <div class="form-group" style="margin:0;flex:1;min-width:240px">
      <label>XLSX soubor (Techem export) *</label>
      <input type="file" name="csv_file" accept=".xlsx" required>
    </div>
    <button type="submit" class="btn btn-primary">⬆ Importovat</button>
  </form>

  <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border);display:flex;align-items:center;gap:12px;flex-wrap:wrap">
    <form method="POST" onsubmit="return confirm('Opravdu smazat VŠECHNY záznamy spotřeb ze všech jednotek a všech let? Tuto akci nelze vrátit.')">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="delete_all_consumption">
      <button type="submit" class="btn btn-danger btn-sm">🗑 Vymazat všechna data spotřeb</button>
    </form>
    <span style="font-size:12px;color:var(--muted)">Smaže celou tabulku všech let — nelze vrátit</span>
  </div>
</div>
</div>

<?php if (isset($_GET['add'])): ?>
<!-- Desktop: přidávací řádek nahoře v tabulce -->
<?php endif; ?>

<!-- ============ DESKTOP ============ -->
<div class="units-desktop">
  <?php if ($editingId): ?>
  <div class="sticky-edit-banner" style="display:block" id="edit-banner">
    ✏ Editujete: <strong><?php foreach($units as $u) if((int)$u['id']===$editingId) echo e($u['label']); ?></strong>
    <a href="/admin/units.php" style="margin-left:1rem;font-size:12px;color:var(--muted)">✕ Zavřít</a>
  </div>
  <?php endif; ?>

  <div class="card" style="padding:0;overflow:hidden">
    <table class="tbl" style="margin:0">
      <thead><tr>
        <th>Jednotka</th><th>Typ</th><th>Patro</th><th>m²</th>
        <th>Podíl</th><th>% váha</th><th>Vlastník</th><th>Garáž</th><th></th>
      </tr></thead>
      <tbody>
      <?php if (isset($_GET['add'])): ?>
      <tr style="background:#f0fff4">
        <td colspan="9" style="padding:0">
          <div style="background:#EAF3DE;border-top:3px solid #A8CC88;padding:1.25rem">
            <div style="font-size:13px;font-weight:600;color:var(--green);margin-bottom:.75rem">+ Nová jednotka</div>
            <form method="POST">
              <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
              <input type="hidden" name="action" value="add">
              <div class="form-row">
                <div class="form-group"><label>Označení *</label><input type="text" name="label" required placeholder="271/1" autofocus></div>
                <div class="form-group"><label>Typ</label>
                  <select name="type" id="add-type" onchange="toggleAddFields()">
                    <option value="byt">byt</option>
                    <option value="garáž">garáž</option>
                    <option value="sklep">sklep</option>
                    <option value="jiné">jiné</option>
                  </select>
                </div>
              </div>
              <div id="add-byt-fields">
                <div class="form-row">
                  <div class="form-group"><label>Patro</label><input type="number" name="floor"></div>
                  <div class="form-group"><label>m²</label><input type="number" step="0.01" name="area_m2"></div>
                </div>
                <div class="form-row">
                  <div class="form-group"><label>Podíl – čitatel</label><input type="number" name="share_num"></div>
                  <div class="form-group"><label>Podíl – jmenovatel</label><input type="number" name="share_den"></div>
                </div>
              </div>
              <div id="add-garage-info" style="display:none;background:#FFF8E6;border-radius:var(--radius-sm);padding:.6rem .75rem;font-size:12px;color:var(--amber);margin-bottom:.75rem">
                🚗 Garáž — evidenční jednotka, podíl a výměra se neevidují.
              </div>
              <div style="display:flex;gap:8px">
                <button type="submit" class="btn btn-primary">Přidat</button>
                <a class="btn btn-secondary" href="/admin/units.php">Zrušit</a>
              </div>
            </form>
          </div>
        </td>
      </tr>
      <?php endif; ?>
      <?php foreach ($units as $u):
        $isGarage = ($u['type'] !== 'byt');
        $isEditing = ($editingId === (int)$u['id']);
        $linkedGarage = null;
        foreach ($units as $uu) {
            if ($uu['linked_unit_id'] == $u['id'] && $uu['type'] !== 'byt') { $linkedGarage = $uu; break; }
        }
      ?>
      <tr id="row-<?= $u['id'] ?>" class="<?= $isEditing ? 'editing-row' : '' ?>">
        <td><strong><?= e($u['label']) ?></strong></td>
        <td><?= e($u['type']) ?></td>
        <td><?= $u['floor'] !== null ? $u['floor'].'. p.' : '–' ?></td>
        <td><?= $u['area_m2'] ?? '–' ?></td>
        <td><?= $u['share_numerator'] ? e($u['share_numerator']).'/'.$u['share_denominator'] : '–' ?></td>
        <td><?= $u['share_pct'] !== null ? $u['share_pct'].' %' : '–' ?></td>
        <td style="color:var(--muted);font-size:13px"><?= e($u['owner_name'] ?: '—') ?></td>
        <td>
          <?php if (!$isGarage && $linkedGarage): ?>
            <span style="background:#FFF8E6;color:var(--amber);padding:2px 8px;border-radius:99px;font-size:12px;font-weight:600">🚗 <?= e($linkedGarage['label']) ?></span>
          <?php elseif ($isGarage && $u['linked_unit_id']): ?>
            <span style="background:#E6F1FB;color:#185FA5;padding:2px 8px;border-radius:99px;font-size:12px">🔗</span>
          <?php else: ?><span style="color:var(--muted)">–</span><?php endif; ?>
        </td>
        <td style="white-space:nowrap">
          <?php if ($isEditing): ?>
            <a class="btn btn-secondary btn-sm" href="/admin/units.php">✕</a>
          <?php else: ?>
            <a class="btn btn-secondary btn-sm" href="?edit=<?= $u['id'] ?>">Editovat</a>
          <?php endif; ?>
          <?php if ($u['type'] === 'byt'): ?>
            <a class="btn btn-secondary btn-sm" href="?cons=<?= $u['id'] ?>&cons_rok=<?= $consRok ?>#cons-<?= $u['id'] ?>"
               style="color:var(--blue)">📊</a>
          <?php endif; ?>
          <form method="POST" style="display:inline" onsubmit="return confirm('Smazat <?= e($u['label']) ?>?')">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $u['id'] ?>">
            <button type="submit" class="btn btn-danger btn-sm">✕</button>
          </form>
        </td>
      </tr>
      <?php if ($isEditing): ?>
      <tr class="inline-edit-row open">
        <td colspan="9" style="padding:0">
          <div style="background:var(--gray-lt);border-top:3px solid #A8C8E8;padding:1.25rem">
            <?= editForm($u, $units) ?>
          </div>
        </td>
      </tr>
      <?php endif; ?>

      <?php if ($consUnitId === (int)$u['id'] && $u['type'] === 'byt'):
        // Načti spotřeby pro tuto jednotku
        $consRows = [];
        $consRoky = [];
        try {
            $cq1 = $db->prepare("SELECT DISTINCT rok FROM consumption WHERE unit_id=? ORDER BY rok DESC");
            $cq1->execute([$u['id']]); $consRoky = $cq1->fetchAll(PDO::FETCH_COLUMN);
            $cq2 = $db->prepare("SELECT id, mesic, typ, jednotka, hodnota_zacatek, hodnota_konec, spotreba FROM consumption WHERE unit_id=? AND rok=? ORDER BY mesic,typ");
            $cq2->execute([$u['id'], $consRok]); $consRows = $cq2->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {}
        $consMesice = [1=>'Leden',2=>'Únor',3=>'Březen',4=>'Duben',5=>'Květen',6=>'Červen',7=>'Červenec',8=>'Srpen',9=>'Září',10=>'Říjen',11=>'Listopad',12=>'Prosinec'];
        $consPivot = []; $consSoucty = ['SV'=>0,'TV'=>0,'ITN'=>0];
        foreach ($consRows as $r) { $consPivot[$r['mesic']][$r['typ']] = $r; $consSoucty[$r['typ']] += $r['spotreba']; }
      ?>
      <tr id="cons-<?= $u['id'] ?>">
        <td colspan="9" style="padding:0;background:#F0F5FF">
          <div style="padding:1.25rem;border-top:3px solid var(--blue)">

            <!-- Hlavička -->
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:1rem;flex-wrap:wrap">
              <span style="font-size:14px;font-weight:600;color:var(--blue)">📊 Spotřeby — <?= e($u['label']) ?></span>
              <!-- Rok filtr -->
              <div style="display:flex;gap:4px;flex-wrap:wrap">
                <?php
                  // Zobrazit všechny roky co mají data + aktuální rok vždy
                  $rokySBtn = array_unique(array_merge($consRoky, [(int)date('Y')]));
                  sort($rokySBtn);
                  foreach ($rokySBtn as $ry):
                    $maData = in_array($ry, $consRoky);
                ?>
                <a href="?cons=<?= $u['id'] ?>&cons_rok=<?= $ry ?>#cons-<?= $u['id'] ?>"
                   class="btn btn-sm <?= $ry==$consRok?'btn-primary':'btn-secondary' ?>"
                   style="<?= !$maData ? 'opacity:.5' : '' ?>">
                  <?= $ry ?><?= !$maData ? ' ·' : '' ?>
                </a>
                <?php endforeach; ?>
              </div>
              <a href="/admin/units.php" class="btn btn-secondary btn-sm" style="margin-left:auto">✕ Zavřít</a>
            </div>

            <!-- Souhrnné boxy -->
            <?php if ($consPivot): ?>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:1rem">
              <?php foreach ([['SV','🚿 St. voda','m³','var(--blue)'],['TV','🌡️ Teplá voda','m³','var(--red)'],['ITN','🔥 Teplo','dílků','var(--amber)']] as [$typ,$lbl,$jed,$clr]): ?>
              <div style="background:#fff;border:1px solid var(--border);border-radius:8px;padding:8px 14px;min-width:120px;flex:1">
                <div style="font-size:10px;color:var(--muted);text-transform:uppercase"><?= $lbl ?></div>
                <div style="font-size:1.2rem;font-weight:700;color:<?= $clr ?>">
                  <?= $typ==='ITN' ? number_format($consSoucty[$typ],0,',','&nbsp;') : number_format($consSoucty[$typ],3,',','&nbsp;') ?>
                  <span style="font-size:11px;font-weight:400"><?= $jed ?></span>
                </div>
              </div>
              <?php endforeach; ?>
            </div>

            <!-- Tabulka spotřeb s inline smazáním -->
            <div style="overflow-x:auto;margin-bottom:1rem">
            <table class="tbl" style="font-size:12px">
              <thead>
                <tr>
                  <th>Měsíc</th>
                  <th colspan="3" style="text-align:center;color:var(--blue)">🚿 St. voda (m³)</th>
                  <th colspan="3" style="text-align:center;color:var(--red)">🌡️ Teplá voda (m³)</th>
                  <th colspan="3" style="text-align:center;color:var(--amber)">🔥 Teplo (dílků)</th>
                </tr>
                <tr style="font-size:10px;color:var(--muted)">
                  <th></th>
                  <th style="text-align:right">Spotřeba</th><th style="text-align:right">Stav</th><th></th>
                  <th style="text-align:right">Spotřeba</th><th style="text-align:right">Stav</th><th></th>
                  <th style="text-align:right">Spotřeba</th><th style="text-align:right">Stav</th><th></th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($consMesice as $m => $nazev):
                $sv  = $consPivot[$m]['SV']  ?? null;
                $tv  = $consPivot[$m]['TV']  ?? null;
                $itn = $consPivot[$m]['ITN'] ?? null;
                if (!$sv && !$tv && !$itn) continue;
              ?>
              <tr>
                <td style="font-weight:500"><?= $nazev ?></td>
                <!-- SV -->
                <td style="text-align:right;font-weight:600;color:var(--blue)"><?= $sv ? number_format($sv['spotreba'],3,',','&nbsp;') : '–' ?></td>
                <td style="text-align:right;font-size:11px;color:var(--muted)"><?= $sv ? number_format($sv['hodnota_konec'],3,',','&nbsp;') : '' ?></td>
                <td><?php if ($sv): ?><form method="POST" style="display:inline" onsubmit="return confirm('Smazat?')"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="delete_consumption"><input type="hidden" name="cons_id" value="<?= $sv['id'] ?>"><input type="hidden" name="cons_unit_id" value="<?= $u['id'] ?>"><input type="hidden" name="cons_rok" value="<?= $consRok ?>"><button class="btn btn-danger btn-sm" style="padding:1px 6px;font-size:10px">✕</button></form><?php endif; ?></td>
                <!-- TV -->
                <td style="text-align:right;font-weight:600;color:var(--red)"><?= $tv ? number_format($tv['spotreba'],3,',','&nbsp;') : '–' ?></td>
                <td style="text-align:right;font-size:11px;color:var(--muted)"><?= $tv ? number_format($tv['hodnota_konec'],3,',','&nbsp;') : '' ?></td>
                <td><?php if ($tv): ?><form method="POST" style="display:inline" onsubmit="return confirm('Smazat?')"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="delete_consumption"><input type="hidden" name="cons_id" value="<?= $tv['id'] ?>"><input type="hidden" name="cons_unit_id" value="<?= $u['id'] ?>"><input type="hidden" name="cons_rok" value="<?= $consRok ?>"><button class="btn btn-danger btn-sm" style="padding:1px 6px;font-size:10px">✕</button></form><?php endif; ?></td>
                <!-- ITN -->
                <td style="text-align:right;font-weight:600;color:var(--amber)"><?= $itn ? number_format($itn['spotreba'],0,',','&nbsp;') : '–' ?></td>
                <td style="text-align:right;font-size:11px;color:var(--muted)"><?= $itn ? number_format($itn['hodnota_konec'],0,',','&nbsp;') : '' ?></td>
                <td><?php if ($itn): ?><form method="POST" style="display:inline" onsubmit="return confirm('Smazat?')"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="delete_consumption"><input type="hidden" name="cons_id" value="<?= $itn['id'] ?>"><input type="hidden" name="cons_unit_id" value="<?= $u['id'] ?>"><input type="hidden" name="cons_rok" value="<?= $consRok ?>"><button class="btn btn-danger btn-sm" style="padding:1px 6px;font-size:10px">✕</button></form><?php endif; ?></td>
              </tr>
              <?php endforeach; ?>
              </tbody>
              <tfoot>
                <tr style="font-weight:700;background:var(--gray-lt)">
                  <td>Celkem</td>
                  <td style="text-align:right;color:var(--blue)"><?= number_format($consSoucty['SV'],3,',','&nbsp;') ?></td><td></td><td></td>
                  <td style="text-align:right;color:var(--red)"><?= number_format($consSoucty['TV'],3,',','&nbsp;') ?></td><td></td><td></td>
                  <td style="text-align:right;color:var(--amber)"><?= number_format($consSoucty['ITN'],0,',','&nbsp;') ?></td><td></td><td></td>
                </tr>
              </tfoot>
            </table>
            </div>
            <?php endif; ?>

            <!-- Přidat / upravit záznam ručně -->
            <details style="margin-top:.5rem">
              <summary style="font-size:13px;color:var(--blue);cursor:pointer;font-weight:600;margin-bottom:.75rem">+ Přidat / upravit záznam ručně</summary>
              <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;background:#fff;padding:.75rem;border-radius:var(--radius-sm);border:1px solid var(--border)">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="save_consumption">
                <input type="hidden" name="cons_unit_id" value="<?= $u['id'] ?>">
                <div class="form-group" style="margin:0;min-width:80px">
                  <label style="font-size:11px">Rok</label>
                  <input type="number" name="cons_rok" value="<?= $consRok ?>" min="2020" max="2099" style="width:70px">
                </div>
                <div class="form-group" style="margin:0;min-width:100px">
                  <label style="font-size:11px">Měsíc</label>
                  <select name="cons_mesic" style="font-size:13px;padding:6px 8px;border:1px solid var(--border);border-radius:var(--radius-sm)">
                    <?php foreach ($consMesice as $mn => $mn_name): ?>
                      <option value="<?= $mn ?>"><?= $mn_name ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="form-group" style="margin:0;min-width:90px">
                  <label style="font-size:11px">Typ</label>
                  <select name="cons_typ" style="font-size:13px;padding:6px 8px;border:1px solid var(--border);border-radius:var(--radius-sm)">
                    <option value="SV">🚿 SV</option>
                    <option value="TV">🌡️ TV</option>
                    <option value="ITN">🔥 ITN</option>
                  </select>
                </div>
                <div class="form-group" style="margin:0;min-width:90px">
                  <label style="font-size:11px">Stav začátek</label>
                  <input type="number" step="0.001" name="cons_zacatek" placeholder="0.000" style="width:90px">
                </div>
                <div class="form-group" style="margin:0;min-width:90px">
                  <label style="font-size:11px">Stav konec</label>
                  <input type="number" step="0.001" name="cons_konec" placeholder="0.000" style="width:90px">
                </div>
                <div class="form-group" style="margin:0;min-width:90px">
                  <label style="font-size:11px">Spotřeba *</label>
                  <input type="number" step="0.001" name="cons_spotreba" placeholder="0.000" required style="width:90px">
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Uložit</button>
              </form>
            </details>

          </div>
        </td>
      </tr>
      <?php endif; ?>

      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ============ MOBIL ============ -->
<div class="units-mobile">
  <?php if (isset($_GET['add'])): ?>
  <div class="unit-card" style="border-top:3px solid #A8CC88;background:#EAF3DE;cursor:default">
    <div style="width:100%">
      <div class="unit-card-label" style="color:var(--green)">+ Nová jednotka</div>
    </div>
  </div>
  <div class="unit-drawer open" style="border-top-color:#A8CC88">
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="add">
      <div class="form-row">
        <div class="form-group"><label>Označení *</label><input type="text" name="label" required placeholder="271/1" autofocus></div>
        <div class="form-group"><label>Typ</label>
          <select name="type" onchange="
            var byt = this.value === 'byt';
            this.closest('form').querySelector('.mob-byt-fields').style.display = byt ? '' : 'none';
            this.closest('form').querySelector('.mob-garage-info').style.display = byt ? 'none' : 'block';
          ">
            <option value="byt">byt</option>
            <option value="garáž">garáž</option>
            <option value="sklep">sklep</option>
            <option value="jiné">jiné</option>
          </select>
        </div>
      </div>
      <div class="mob-byt-fields">
        <div class="form-row">
          <div class="form-group"><label>Patro</label><input type="number" name="floor"></div>
          <div class="form-group"><label>m²</label><input type="number" step="0.01" name="area_m2"></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label>Podíl – čitatel</label><input type="number" name="share_num"></div>
          <div class="form-group"><label>Podíl – jmenovatel</label><input type="number" name="share_den"></div>
        </div>
      </div>
      <div class="mob-garage-info" style="display:none;background:#FFF8E6;border-radius:var(--radius-sm);padding:.6rem .75rem;font-size:12px;color:var(--amber);margin-bottom:.75rem">
        🚗 Garáž — evidenční jednotka.
      </div>
      <div style="display:flex;gap:8px">
        <button type="submit" class="btn btn-primary">Přidat</button>
        <a class="btn btn-secondary" href="/admin/units.php">Zrušit</a>
      </div>
    </form>
  </div>
  <?php endif; ?>
  <?php foreach ($units as $u):
    $isGarage = ($u['type'] !== 'byt');
    $linkedGarage = null;
    foreach ($units as $uu) {
        if ($uu['linked_unit_id'] == $u['id'] && $uu['type'] !== 'byt') { $linkedGarage = $uu; break; }
    }
  ?>
  <div class="unit-card <?= $isGarage ? 'is-garage' : '' ?>" id="card-<?= $u['id'] ?>" onclick="toggleDrawer(<?= $u['id'] ?>)">
    <div>
      <div class="unit-card-label"><?= e($u['label']) ?></div>
      <div class="unit-card-sub">
        <?= e($u['type']) ?>
        <?php if (!$isGarage && $linkedGarage): ?> &nbsp;🚗 <?= e($linkedGarage['label']) ?><?php endif; ?>
        <?php if (!$isGarage && $u['owner_name']): ?> &nbsp;· <?= e($u['owner_name']) ?><?php endif; ?>
      </div>
    </div>
    <div class="unit-card-right">
      <?= $u['share_pct'] !== null ? $u['share_pct'].' %' : '' ?>
      <div style="font-size:18px;color:var(--muted)">›</div>
    </div>
  </div>
  <div class="unit-drawer" id="drawer-<?= $u['id'] ?>">
    <div style="font-size:12px;color:var(--muted);margin-bottom:.75rem">
      <?php if ($u['floor'] !== null): ?>Patro: <?= $u['floor'] ?> &nbsp;<?php endif; ?>
      <?php if ($u['area_m2']): ?>m²: <?= $u['area_m2'] ?> &nbsp;<?php endif; ?>
      <?php if ($u['share_numerator']): ?>Podíl: <?= $u['share_numerator'].'/'.$u['share_denominator'] ?><?php endif; ?>
    </div>
    <?= editForm($u, $units) ?>
    <form method="POST" style="margin-top:.75rem" onsubmit="return confirm('Smazat <?= e($u['label']) ?>?')">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="<?= $u['id'] ?>">
      <button type="submit" class="btn btn-danger btn-sm">🗑 Smazat jednotku</button>
    </form>
  </div>
  <?php endforeach; ?>
</div>

<script>
function toggleAddFields() {
    var type = document.getElementById('add-type').value;
    var isByt = type === 'byt';
    document.getElementById('add-byt-fields').style.display = isByt ? '' : 'none';
    document.getElementById('add-garage-info').style.display = isByt ? 'none' : 'block';
}

function toggleDrawer(id) {
    var card = document.getElementById('card-' + id);
    var drawer = document.getElementById('drawer-' + id);
    var isOpen = drawer.classList.contains('open');
    // Zavři ostatní
    document.querySelectorAll('.unit-drawer.open').forEach(function(d){ d.classList.remove('open'); });
    document.querySelectorAll('.unit-card.active').forEach(function(c){ c.classList.remove('active'); });
    if (!isOpen) {
        drawer.classList.add('open');
        card.classList.add('active');
        setTimeout(function(){ card.scrollIntoView({behavior:'smooth', block:'start'}); }, 100);
    }
}

// Desktop scroll na editovaný řádek
document.addEventListener('DOMContentLoaded', function() {
    var editRow = document.querySelector('.editing-row');
    if (editRow) {
        setTimeout(function(){
            var offset = editRow.getBoundingClientRect().top + window.scrollY - 100;
            window.scrollTo({top: offset, behavior: 'smooth'});
        }, 100);
    }
});
</script>

<?php
function editForm(array $u, array $units): string {
    $isGarage = ($u['type'] !== 'byt');
    $linkedGarage = null;
    foreach ($units as $uu) {
        if ($uu['linked_unit_id'] == $u['id'] && $uu['type'] !== 'byt') { $linkedGarage = $uu; break; }
    }
    ob_start(); ?>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="id" value="<?= $u['id'] ?>">
      <div class="form-row">
        <div class="form-group"><label>Označení *</label><input type="text" name="label" required value="<?= e($u['label']) ?>"></div>
        <div class="form-group"><label>Typ</label>
          <select name="type">
            <?php foreach (['byt','garáž','sklep','jiné'] as $t): ?>
              <option value="<?= $t ?>" <?= $u['type']===$t?'selected':'' ?>><?= $t ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <?php if (!$isGarage): ?>
      <div class="form-row">
        <div class="form-group"><label>Patro</label><input type="number" name="floor" value="<?= e($u['floor'] ?? '') ?>"></div>
        <div class="form-group"><label>m²</label><input type="number" step="0.01" name="area_m2" value="<?= e($u['area_m2'] ?? '') ?>"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Podíl – čitatel</label><input type="number" name="share_num" value="<?= e($u['share_numerator'] ?? '') ?>"></div>
        <div class="form-group"><label>Podíl – jmenovatel</label><input type="number" name="share_den" value="<?= e($u['share_denominator'] ?? '') ?>"></div>
      </div>
      <div class="form-group"><label>Přiřazená garáž</label>
        <select name="garage_unit_id">
          <option value="">— bez garáže —</option>
          <?php foreach ($units as $gu): if ($gu['type']==='byt') continue; ?>
            <option value="<?= $gu['id'] ?>" <?= ($linkedGarage && $linkedGarage['id']==$gu['id'])?'selected':'' ?>>🚗 <?= e($gu['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php else: ?>
      <div style="background:#FFF8E6;border-radius:var(--radius-sm);padding:.6rem .75rem;font-size:12px;color:var(--amber);margin-bottom:.75rem">
        🚗 Garáž — evidenční jednotka, podíl a výměra se neevidují.
      </div>
      <?php endif; ?>
      <div style="display:flex;gap:8px">
        <button type="submit" class="btn btn-primary">Uložit</button>
        <a class="btn btn-secondary" href="/admin/units.php">Zrušit</a>
      </div>
    </form>
    <?php return ob_get_clean();
}
?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
