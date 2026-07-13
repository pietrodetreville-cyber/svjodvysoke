<?php
require_once __DIR__ . '/../includes/functions.php';
$user = requireAdmin();
$db   = db();

$unitId = (int)($_GET['id'] ?? 0);
if (!$unitId) { header('Location: /admin/units.php'); exit; }

$unit = $db->prepare("SELECT u.*, o.full_name AS owner_name FROM units u LEFT JOIN owners o ON o.unit_id=u.id WHERE u.id=?");
$unit->execute([$unitId]);
$unit = $unit->fetch();
if (!$unit) { header('Location: /admin/units.php'); exit; }

$pageTitle = 'Popis jednotky — ' . $unit['label'];

// ── POST handlery ─────────────────────────────────────────────────────────

// Uložit základní parametry (sloučeno: identifikace/podíl z units.php + technické np/dispozice/výměra)
// Pozn.: floor/area_m2 jsou opuštěné duplicity np/vymera_m2 - už se tu needitují (viz migrace dat).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_info') {
    csrfCheck();
    $type = $_POST['type'];
    $isGarage = ($type !== 'byt');

    if ($isGarage) {
        $db->prepare("UPDATE units SET label=?, type=?, share_numerator=NULL, share_denominator=NULL WHERE id=?")
           ->execute([trim($_POST['label']), $type, $unitId]);
    } else {
        $db->prepare('UPDATE units SET linked_unit_id=NULL WHERE linked_unit_id=?')->execute([$unitId]);
        if (!empty($_POST['garage_unit_id'])) {
            $db->prepare('UPDATE units SET linked_unit_id=? WHERE id=?')->execute([$unitId, (int)$_POST['garage_unit_id']]);
        }
        $db->prepare("UPDATE units SET label=?, type=?, share_numerator=?, share_denominator=? WHERE id=?")
           ->execute([
               trim($_POST['label']), $type,
               $_POST['share_num'] !== '' ? (int)$_POST['share_num'] : null,
               $_POST['share_den'] !== '' ? (int)$_POST['share_den'] : null,
               $unitId,
           ]);
    }

    $db->prepare("UPDATE units SET np=?, dispozice=?, vymera_m2=?, vymera_pozn=? WHERE id=?")
       ->execute([
           $_POST['np'] !== '' ? (int)$_POST['np'] : null,
           trim($_POST['dispozice'] ?? '') ?: null,
           $_POST['vymera_m2'] !== '' ? (float)$_POST['vymera_m2'] : null,
           trim($_POST['vymera_pozn'] ?? '') ?: null,
           $unitId,
       ]);

    flash('Základní parametry uloženy.', 'success');
    header("Location: /admin/unit_detail.php?id=$unitId"); exit;
}

// Přidat / upravit místnost
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_room') {
    csrfCheck();
    $rid = (int)($_POST['room_id'] ?? 0);
    if ($rid) {
        $db->prepare("UPDATE unit_rooms SET nazev=?, vymera_m2=?, poznamka=?, order_num=? WHERE id=? AND unit_id=?")
           ->execute([trim($_POST['nazev']), $_POST['vymera_m2'] !== '' ? (float)$_POST['vymera_m2'] : null, trim($_POST['poznamka'] ?? '') ?: null, (int)($_POST['order_num'] ?? 0), $rid, $unitId]);
    } else {
        $db->prepare("INSERT INTO unit_rooms (unit_id, nazev, vymera_m2, poznamka, order_num) VALUES (?,?,?,?,?)")
           ->execute([$unitId, trim($_POST['nazev']), $_POST['vymera_m2'] !== '' ? (float)$_POST['vymera_m2'] : null, trim($_POST['poznamka'] ?? '') ?: null, (int)($_POST['order_num'] ?? 0)]);
    }
    header("Location: /admin/unit_detail.php?id=$unitId#mistnosti"); exit;
}

// Smazat místnost
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_room') {
    csrfCheck();
    $db->prepare("DELETE FROM unit_rooms WHERE id=? AND unit_id=?")->execute([(int)$_POST['room_id'], $unitId]);
    header("Location: /admin/unit_detail.php?id=$unitId#mistnosti"); exit;
}

// Přidat / upravit vybavení
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_eq') {
    csrfCheck();
    $eid = (int)($_POST['eq_id'] ?? 0);
    if ($eid) {
        $db->prepare("UPDATE unit_equipment SET polozka=?, pocet=?, poznamka=?, order_num=? WHERE id=? AND unit_id=?")
           ->execute([trim($_POST['polozka']), max(1,(int)$_POST['pocet']), trim($_POST['poznamka'] ?? '') ?: null, (int)($_POST['order_num'] ?? 0), $eid, $unitId]);
    } else {
        $db->prepare("INSERT INTO unit_equipment (unit_id, polozka, pocet, poznamka, order_num) VALUES (?,?,?,?,?)")
           ->execute([$unitId, trim($_POST['polozka']), max(1,(int)$_POST['pocet']), trim($_POST['poznamka'] ?? '') ?: null, (int)($_POST['order_num'] ?? 0)]);
    }
    header("Location: /admin/unit_detail.php?id=$unitId#vybaveni"); exit;
}

// Smazat vybavení
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_eq') {
    csrfCheck();
    $db->prepare("DELETE FROM unit_equipment WHERE id=? AND unit_id=?")->execute([(int)$_POST['eq_id'], $unitId]);
    header("Location: /admin/unit_detail.php?id=$unitId#vybaveni"); exit;
}

// ── Načtení dat ───────────────────────────────────────────────────────────
$rooms = $db->prepare("SELECT * FROM unit_rooms WHERE unit_id=? ORDER BY order_num, id");
$rooms->execute([$unitId]);
$rooms = $rooms->fetchAll();

$equipment = $db->prepare("SELECT * FROM unit_equipment WHERE unit_id=? ORDER BY order_num, id");
$equipment->execute([$unitId]);
$equipment = $equipment->fetchAll();

$editRoom = isset($_GET['edit_room']) ? (int)$_GET['edit_room'] : null;
$editEq   = isset($_GET['edit_eq'])   ? (int)$_GET['edit_eq']   : null;

// Celková výměra místností
$totalVymera = array_sum(array_column($rooms, 'vymera_m2'));

$npLabels = [1=>'1. NP (přízemí)',2=>'2. NP (1. patro)',3=>'3. NP (2. patro)',4=>'4. NP (3. patro)',5=>'5. NP (4. patro)',6=>'6. NP (5. patro)',7=>'7. NP (6. patro)',8=>'8. NP (7. patro)'];

// Identifikace/podíl/garáž (dřív editovatelné jen z units.php, teď sloučeno sem)
$isGarage = ($unit['type'] !== 'byt');
$allUnits = $db->query("SELECT id, label, type, linked_unit_id FROM units ORDER BY label")->fetchAll();
$linkedGarage = null;
foreach ($allUnits as $uu) {
    if ($uu['linked_unit_id'] == $unitId && $uu['type'] !== 'byt') { $linkedGarage = $uu; break; }
}
$sharePct = ($unit['share_numerator'] && $unit['share_denominator'] > 0)
    ? round($unit['share_numerator'] / $unit['share_denominator'] * 100, 4) : null;

include __DIR__ . '/../includes/header.php';
?>

<style>
.detail-grid { display:grid; grid-template-columns:1fr 1fr; gap:1.25rem; align-items:start; }
@media(max-width:800px){ .detail-grid{ grid-template-columns:1fr; } }
.section-card { margin-bottom:1.25rem; }
.inline-form { display:flex; gap:6px; flex-wrap:wrap; align-items:flex-end; background:var(--gray-lt); padding:.75rem; border-radius:var(--radius-sm); margin-top:.75rem; border:1px solid var(--border); }
.inline-form .form-group { margin:0; }
.inline-form label { font-size:11px; }
.tbl-edit td { vertical-align:middle; }
</style>

<div class="page-hd">
  <div>
    <h1>🏠 <?= e($unit['label']) ?> — technický popis</h1>
    <div style="font-size:13px;color:var(--muted);margin-top:2px">
      <?= e($unit['type']) ?>
      <?php if ($unit['owner_name']): ?>
        &nbsp;·&nbsp; <?= e($unit['owner_name']) ?>
      <?php endif; ?>
      <?php if ($unit['dispozice']): ?>
        &nbsp;·&nbsp; <strong><?= e($unit['dispozice']) ?></strong>
      <?php endif; ?>
      <?php if ($unit['vymera_m2']): ?>
        &nbsp;·&nbsp; <?= $unit['vymera_m2'] ?> m²
      <?php endif; ?>
    </div>
  </div>
  <div style="display:flex;gap:8px">
    <a class="btn btn-secondary btn-sm" href="/admin/units.php">← Jednotky</a>
    <?php if ($unit['owner_name']): ?>
      <a class="btn btn-secondary btn-sm" href="/admin/owners.php">Kartotéka</a>
    <?php endif; ?>
  </div>
</div>

<!-- ══ ZÁKLADNÍ PARAMETRY ═════════════════════════════════════════════════ -->
<div class="card section-card" style="border-top:4px solid var(--blue)">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem">
    <div style="font-size:14px;font-weight:600;color:var(--blue)">📋 Základní parametry</div>
    <button class="btn btn-secondary btn-sm" onclick="document.getElementById('info-form').style.display=document.getElementById('info-form').style.display==='none'?'block':'none'">✏ Upravit</button>
  </div>

  <!-- Zobrazení -->
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:1rem;margin-bottom:.5rem">
    <div style="background:var(--gray-lt);border-radius:8px;padding:10px 14px">
      <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em">Označení / typ</div>
      <div style="font-size:1.2rem;font-weight:700;color:var(--blue)"><?= e($unit['label']) ?></div>
      <div style="font-size:11px;color:var(--muted)"><?= e($unit['type']) ?></div>
    </div>
    <?php if (!$isGarage): ?>
    <div style="background:var(--gray-lt);border-radius:8px;padding:10px 14px">
      <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em">Podíl na SVJ</div>
      <div style="font-size:1.2rem;font-weight:700;color:var(--blue)">
        <?= $unit['share_numerator'] ? e($unit['share_numerator']).'/'.e($unit['share_denominator']) : '—' ?>
      </div>
      <?php if ($sharePct !== null): ?><div style="font-size:11px;color:var(--muted)"><?= $sharePct ?> %</div><?php endif; ?>
    </div>
    <div style="background:var(--gray-lt);border-radius:8px;padding:10px 14px">
      <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em">Garáž</div>
      <div style="font-size:1.2rem;font-weight:700;color:var(--blue)"><?= $linkedGarage ? e($linkedGarage['label']) : '—' ?></div>
    </div>
    <div style="background:var(--gray-lt);border-radius:8px;padding:10px 14px">
      <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em">Podlaží</div>
      <div style="font-size:1.2rem;font-weight:700;color:var(--blue)"><?= $unit['np'] ? $npLabels[$unit['np']] ?? $unit['np'].'. NP' : '—' ?></div>
    </div>
    <div style="background:var(--gray-lt);border-radius:8px;padding:10px 14px">
      <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em">Dispozice</div>
      <div style="font-size:1.2rem;font-weight:700;color:var(--blue)"><?= e($unit['dispozice'] ?: '—') ?></div>
    </div>
    <div style="background:var(--gray-lt);border-radius:8px;padding:10px 14px">
      <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em">Výměra (bez lodžie/sklepu)</div>
      <div style="font-size:1.2rem;font-weight:700;color:var(--blue)"><?= $unit['vymera_m2'] ? $unit['vymera_m2'].' m²' : '—' ?></div>
      <?php if ($unit['vymera_pozn']): ?>
        <div style="font-size:11px;color:var(--muted)"><?= e($unit['vymera_pozn']) ?></div>
      <?php endif; ?>
    </div>
    <?php if ($totalVymera > 0): ?>
    <div style="background:var(--gray-lt);border-radius:8px;padding:10px 14px">
      <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em">Součet místností</div>
      <div style="font-size:1.2rem;font-weight:700;color:var(--green)"><?= number_format($totalVymera, 2, ',', ' ') ?> m²</div>
    </div>
    <?php endif; ?>
    <?php else: ?>
    <div style="background:#FFF8E6;border-radius:8px;padding:10px 14px;grid-column:1/-1">
      <div style="font-size:12px;color:var(--amber)">🚗 Evidenční jednotka — podíl, výměra a technický popis se neevidují.</div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Editační formulář (skrytý) -->
  <div id="info-form" style="display:none;margin-top:1rem">
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="save_info">
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:.75rem">
        <div class="form-group" style="min-width:140px">
          <label>Označení *</label>
          <input type="text" name="label" required value="<?= e($unit['label']) ?>">
        </div>
        <div class="form-group" style="min-width:120px">
          <label>Typ</label>
          <select name="type" id="detail-type" onchange="toggleDetailFields()">
            <?php foreach (['byt','garáž','sklep','jiné'] as $t): ?>
              <option value="<?= $t ?>" <?= $unit['type']===$t?'selected':'' ?>><?= $t ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div id="detail-byt-fields" style="<?= $isGarage ? 'display:none' : '' ?>">
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:.75rem">
          <div class="form-group" style="min-width:100px">
            <label>Podíl – čitatel</label>
            <input type="number" name="share_num" value="<?= e($unit['share_numerator'] ?? '') ?>">
          </div>
          <div class="form-group" style="min-width:100px">
            <label>Podíl – jmenovatel</label>
            <input type="number" name="share_den" value="<?= e($unit['share_denominator'] ?? '') ?>">
          </div>
          <div class="form-group" style="min-width:160px">
            <label>Přiřazená garáž</label>
            <select name="garage_unit_id">
              <option value="">— bez garáže —</option>
              <?php foreach ($allUnits as $gu): if ($gu['type']==='byt') continue; ?>
                <option value="<?= $gu['id'] ?>" <?= ($linkedGarage && $linkedGarage['id']==$gu['id'])?'selected':'' ?>>🚗 <?= e($gu['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
          <div class="form-group" style="min-width:180px">
            <label>Podlaží (NP)</label>
            <select name="np">
              <option value="">— neuvedeno —</option>
              <?php foreach ($npLabels as $n => $lbl): ?>
                <option value="<?= $n ?>" <?= ($unit['np'] ?? '') == $n ? 'selected' : '' ?>><?= $lbl ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="min-width:100px">
            <label>Dispozice</label>
            <input type="text" name="dispozice" placeholder="1+kk" maxlength="20" value="<?= e($unit['dispozice'] ?? '') ?>">
          </div>
          <div class="form-group" style="min-width:100px">
            <label>Výměra (m²)</label>
            <input type="number" step="0.01" name="vymera_m2" placeholder="54.40" value="<?= e($unit['vymera_m2'] ?? '') ?>">
          </div>
          <div class="form-group" style="min-width:200px;flex:1">
            <label>Poznámka k výměře</label>
            <input type="text" name="vymera_pozn" placeholder="mimo lodžie a sklep" value="<?= e($unit['vymera_pozn'] ?? '') ?>">
          </div>
        </div>
      </div>
      <div id="detail-garage-info" style="<?= $isGarage ? '' : 'display:none' ?>;background:#FFF8E6;border-radius:var(--radius-sm);padding:.6rem .75rem;font-size:12px;color:var(--amber);margin-bottom:.75rem">
        🚗 Garáž/sklep/jiné — podíl, výměra a technický popis se neevidují.
      </div>

      <button type="submit" class="btn btn-primary">Uložit</button>
    </form>
  </div>
</div>

<script>
function toggleDetailFields() {
    var isByt = document.getElementById('detail-type').value === 'byt';
    document.getElementById('detail-byt-fields').style.display = isByt ? '' : 'none';
    document.getElementById('detail-garage-info').style.display = isByt ? 'none' : '';
}
</script>

<!-- ══ MÍSTNOSTI ══════════════════════════════════════════════════════════ -->
<div class="card section-card" id="mistnosti" style="border-top:4px solid var(--green)">
  <div style="font-size:14px;font-weight:600;color:var(--green);margin-bottom:1rem">🚪 Místnosti</div>

  <?php if ($rooms): ?>
  <table class="tbl tbl-edit" style="margin-bottom:1rem">
    <thead>
      <tr>
        <th>Místnost</th>
        <th style="text-align:right">Výměra (m²)</th>
        <th>Poznámka</th>
        <th style="width:30px">Pořadí</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rooms as $r): ?>
    <?php if ($editRoom === (int)$r['id']): ?>
    <!-- Inline editace -->
    <tr style="background:#EAF3DE">
      <td colspan="5">
        <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;padding:.25rem 0">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="action" value="save_room">
          <input type="hidden" name="room_id" value="<?= $r['id'] ?>">
          <div class="form-group" style="margin:0;flex:2;min-width:140px">
            <label style="font-size:11px">Název místnosti</label>
            <input type="text" name="nazev" required value="<?= e($r['nazev']) ?>">
          </div>
          <div class="form-group" style="margin:0;min-width:90px">
            <label style="font-size:11px">Výměra m²</label>
            <input type="number" step="0.01" name="vymera_m2" value="<?= e($r['vymera_m2'] ?? '') ?>">
          </div>
          <div class="form-group" style="margin:0;flex:2;min-width:120px">
            <label style="font-size:11px">Poznámka</label>
            <input type="text" name="poznamka" value="<?= e($r['poznamka'] ?? '') ?>">
          </div>
          <div class="form-group" style="margin:0;min-width:60px">
            <label style="font-size:11px">Pořadí</label>
            <input type="number" name="order_num" value="<?= $r['order_num'] ?>" style="width:55px">
          </div>
          <button type="submit" class="btn btn-primary btn-sm">Uložit</button>
          <a href="/admin/unit_detail.php?id=<?= $unitId ?>#mistnosti" class="btn btn-secondary btn-sm">Zrušit</a>
        </form>
      </td>
    </tr>
    <?php else: ?>
    <tr>
      <td><strong><?= e($r['nazev']) ?></strong></td>
      <td style="text-align:right;font-weight:600"><?= $r['vymera_m2'] !== null ? number_format($r['vymera_m2'], 2, ',', ' ').' m²' : '—' ?></td>
      <td style="font-size:12px;color:var(--muted)"><?= e($r['poznamka'] ?? '') ?></td>
      <td style="text-align:center;font-size:12px;color:var(--muted)"><?= $r['order_num'] ?></td>
      <td style="white-space:nowrap">
        <a class="btn btn-secondary btn-sm" href="?id=<?= $unitId ?>&edit_room=<?= $r['id'] ?>#mistnosti">✏</a>
        <form method="POST" style="display:inline" onsubmit="return confirm('Smazat místnost?')">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="action" value="delete_room">
          <input type="hidden" name="room_id" value="<?= $r['id'] ?>">
          <button type="submit" class="btn btn-danger btn-sm">✕</button>
        </form>
      </td>
    </tr>
    <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr style="font-weight:700;background:var(--gray-lt)">
        <td>Celkem</td>
        <td style="text-align:right;color:var(--green)"><?= number_format($totalVymera, 2, ',', ' ') ?> m²</td>
        <td colspan="3"></td>
      </tr>
    </tfoot>
  </table>
  <?php else: ?>
  <p style="color:var(--muted);font-size:13px;margin-bottom:1rem">Zatím nejsou evidovány žádné místnosti.</p>
  <?php endif; ?>

  <!-- Přidat místnost -->
  <details <?= !$rooms ? 'open' : '' ?>>
    <summary style="font-size:13px;color:var(--green);font-weight:600;cursor:pointer;margin-bottom:.5rem">+ Přidat místnost</summary>
    <form method="POST" class="inline-form">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="save_room">
      <input type="hidden" name="room_id" value="0">
      <div class="form-group" style="flex:2;min-width:140px">
        <label>Název místnosti *</label>
        <input type="text" name="nazev" required placeholder="pokoj, kuchyň, koupelna...">
      </div>
      <div class="form-group" style="min-width:90px">
        <label>Výměra (m²)</label>
        <input type="number" step="0.01" name="vymera_m2" placeholder="28.40">
      </div>
      <div class="form-group" style="flex:2;min-width:120px">
        <label>Poznámka</label>
        <input type="text" name="poznamka" placeholder="volitelná poznámka">
      </div>
      <div class="form-group" style="min-width:60px">
        <label>Pořadí</label>
        <input type="number" name="order_num" value="<?= count($rooms) + 1 ?>" style="width:55px">
      </div>
      <button type="submit" class="btn btn-primary btn-sm">Přidat</button>
    </form>
  </details>
</div>

<!-- ══ VYBAVENÍ ═══════════════════════════════════════════════════════════ -->
<div class="card section-card" id="vybaveni" style="border-top:4px solid var(--amber)">
  <div style="font-size:14px;font-weight:600;color:var(--amber);margin-bottom:1rem">🔧 Vybavení bytové jednotky</div>

  <?php if ($equipment): ?>
  <table class="tbl tbl-edit" style="margin-bottom:1rem">
    <thead>
      <tr>
        <th>Položka</th>
        <th style="text-align:center">Počet</th>
        <th>Poznámka</th>
        <th style="width:30px">Pořadí</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($equipment as $eq): ?>
    <?php if ($editEq === (int)$eq['id']): ?>
    <tr style="background:#FAEEDA">
      <td colspan="5">
        <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;padding:.25rem 0">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="action" value="save_eq">
          <input type="hidden" name="eq_id" value="<?= $eq['id'] ?>">
          <div class="form-group" style="margin:0;flex:2;min-width:160px">
            <label style="font-size:11px">Položka</label>
            <input type="text" name="polozka" required value="<?= e($eq['polozka']) ?>">
          </div>
          <div class="form-group" style="margin:0;min-width:70px">
            <label style="font-size:11px">Počet ks</label>
            <input type="number" name="pocet" min="1" value="<?= $eq['pocet'] ?>" style="width:65px">
          </div>
          <div class="form-group" style="margin:0;flex:2;min-width:120px">
            <label style="font-size:11px">Poznámka</label>
            <input type="text" name="poznamka" value="<?= e($eq['poznamka'] ?? '') ?>">
          </div>
          <div class="form-group" style="margin:0;min-width:60px">
            <label style="font-size:11px">Pořadí</label>
            <input type="number" name="order_num" value="<?= $eq['order_num'] ?>" style="width:55px">
          </div>
          <button type="submit" class="btn btn-primary btn-sm">Uložit</button>
          <a href="/admin/unit_detail.php?id=<?= $unitId ?>#vybaveni" class="btn btn-secondary btn-sm">Zrušit</a>
        </form>
      </td>
    </tr>
    <?php else: ?>
    <tr>
      <td><strong><?= e($eq['polozka']) ?></strong></td>
      <td style="text-align:center;font-weight:600"><?= $eq['pocet'] ?> ks</td>
      <td style="font-size:12px;color:var(--muted)"><?= e($eq['poznamka'] ?? '') ?></td>
      <td style="text-align:center;font-size:12px;color:var(--muted)"><?= $eq['order_num'] ?></td>
      <td style="white-space:nowrap">
        <a class="btn btn-secondary btn-sm" href="?id=<?= $unitId ?>&edit_eq=<?= $eq['id'] ?>#vybaveni">✏</a>
        <form method="POST" style="display:inline" onsubmit="return confirm('Smazat položku?')">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="action" value="delete_eq">
          <input type="hidden" name="eq_id" value="<?= $eq['id'] ?>">
          <button type="submit" class="btn btn-danger btn-sm">✕</button>
        </form>
      </td>
    </tr>
    <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <p style="color:var(--muted);font-size:13px;margin-bottom:1rem">Zatím není evidováno žádné vybavení.</p>
  <?php endif; ?>

  <!-- Přidat vybavení -->
  <details <?= !$equipment ? 'open' : '' ?>>
    <summary style="font-size:13px;color:var(--amber);font-weight:600;cursor:pointer;margin-bottom:.5rem">+ Přidat položku vybavení</summary>
    <form method="POST" class="inline-form">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="save_eq">
      <input type="hidden" name="eq_id" value="0">
      <div class="form-group" style="flex:2;min-width:160px">
        <label>Položka *</label>
        <input type="text" name="polozka" required placeholder="vana, sporák elektro...">
      </div>
      <div class="form-group" style="min-width:70px">
        <label>Počet ks</label>
        <input type="number" name="pocet" min="1" value="1" style="width:65px">
      </div>
      <div class="form-group" style="flex:2;min-width:120px">
        <label>Poznámka</label>
        <input type="text" name="poznamka" placeholder="volitelná poznámka">
      </div>
      <div class="form-group" style="min-width:60px">
        <label>Pořadí</label>
        <input type="number" name="order_num" value="<?= count($equipment) + 1 ?>" style="width:55px">
      </div>
      <button type="submit" class="btn btn-primary btn-sm">Přidat</button>
    </form>
  </details>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
