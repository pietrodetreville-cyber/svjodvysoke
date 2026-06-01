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

$editingId = isset($_GET['edit']) ? (int)$_GET['edit'] : null;

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
  <a class="btn btn-primary" href="?add=1">+ Přidat</a>
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
