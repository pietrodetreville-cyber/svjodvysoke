<?php
require_once __DIR__ . '/../includes/functions.php';
$user = requireAdmin();
$pageTitle = 'Archiv dokumentů';
$db = db();

$categories = [
    'smlouvy'  => 'Smlouvy s dodavateli',
    'pojisteni'=> 'Pojištění',
    'bankovni' => 'Bankovní dokumenty',
    'revize'   => 'Revize a technické zprávy',
    'znalecke' => 'Znalecké posudky',
    'zapisy'   => 'Zápisy ze schůzí',
    'ostatni'  => 'Ostatní',
];
$catIcons = [
    'smlouvy'  => '📄','pojisteni'=> '🛡️','bankovni' => '🏦',
    'revize'   => '🔧','znalecke' => '📋','zapisy'   => '📝','ostatni'  => '📁',
];

function uploadFile(array $file): ?array {
    $origName = basename($file['name']);
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $allowed = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png'];
    if (!in_array($ext, $allowed)) return null;
    if ($file['size'] > 20 * 1024 * 1024) return null;
    $newName = date('Ymd_His') . '_' . uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $origName);
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $newName)) return null;
    return ['filename' => $newName, 'original_name' => $origName, 'filesize' => $file['size'], 'mime_type' => $file['type']];
}

// Nahrát
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    csrfCheck();
    $f1 = $_FILES['document'] ?? null;
    $f2 = $_FILES['document2'] ?? null;

    if (!$f1 || $f1['error'] !== UPLOAD_ERR_OK) {
        flash('Vyberte hlavní soubor.', 'error');
    } else {
        $att1 = uploadFile($f1);
        if (!$att1) { flash('Nepodporovaný formát nebo příliš velký soubor (max. 20 MB).', 'error'); }
        else {
            $att2 = ($f2 && $f2['error'] === UPLOAD_ERR_OK) ? uploadFile($f2) : null;
            $db->prepare(
                'INSERT INTO documents (title,category,description,filename,original_name,filesize,mime_type,filename2,original_name2,filesize2,mime_type2,visible_to_owners,valid_from,valid_until,uploaded_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                trim($_POST['title']),
                $_POST['category'],
                trim($_POST['description']),
                $att1['filename'], $att1['original_name'], $att1['filesize'], $att1['mime_type'],
                $att2['filename'] ?? null, $att2['original_name'] ?? null, $att2['filesize'] ?? null, $att2['mime_type'] ?? null,
                isset($_POST['visible_to_owners']) ? 1 : 0,
                $_POST['valid_from'] ?: null,
                $_POST['valid_until'] ?: null,
                $user['id'],
            ]);
            $msg = 'Dokument nahrán';
            if ($att2) $msg .= ' (včetně přílohy: ' . $att2['original_name'] . ')';
            flash($msg . '.', 'success');
        }
    }
    header('Location: /admin/documents.php'); exit;
}

// Smazat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrfCheck();
    $doc = $db->prepare('SELECT filename, filename2 FROM documents WHERE id=?');
    $doc->execute([(int)$_POST['id']]);
    $doc = $doc->fetch();
    if ($doc) {
        @unlink(UPLOAD_DIR . $doc['filename']);
        if ($doc['filename2']) @unlink(UPLOAD_DIR . $doc['filename2']);
        $db->prepare('DELETE FROM documents WHERE id=?')->execute([(int)$_POST['id']]);
        flash('Dokument smazán.', 'success');
    }
    header('Location: /admin/documents.php'); exit;
}

// Přepnout viditelnost
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_visible') {
    csrfCheck();
    $db->prepare('UPDATE documents SET visible_to_owners = 1 - visible_to_owners WHERE id=?')->execute([(int)$_POST['id']]);
    header('Location: /admin/documents.php'); exit;
}

// Filtr
$filterCat = $_GET['cat'] ?? '';
$search    = trim($_GET['q'] ?? '');
$where = '1=1'; $params = [];
if ($filterCat && array_key_exists($filterCat, $categories)) { $where .= ' AND category=?'; $params[] = $filterCat; }
if ($search) { $where .= ' AND (title LIKE ? OR description LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }

$docs = $db->prepare("SELECT d.*, u.username AS uploader FROM documents d JOIN users u ON d.uploaded_by=u.id WHERE $where ORDER BY d.created_at DESC");
$docs->execute($params);
$docs = $docs->fetchAll();

$stats = $db->query('SELECT category, COUNT(*) as cnt FROM documents GROUP BY category')->fetchAll(PDO::FETCH_KEY_PAIR);

include __DIR__ . '/../includes/header.php';
?>

<div class="page-hd"><h1>Archiv dokumentů</h1></div>

<!-- Nahrát -->
<div class="card" style="max-width:720px;margin-bottom:1.5rem">
  <div class="card-title">Nahrát dokument</div>
  <form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="action" value="upload">
    <div class="form-row">
      <div class="form-group">
        <label>Název dokumentu *</label>
        <input type="text" name="title" required placeholder="Smlouva s úklidovou firmou">
      </div>
      <div class="form-group">
        <label>Kategorie *</label>
        <select name="category" required>
          <?php foreach ($categories as $key => $label): ?>
            <option value="<?= $key ?>"><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-group">
      <label>Popis (volitelný)</label>
      <input type="text" name="description" placeholder="Stručný popis obsahu">
    </div>
    <div class="form-row">
      <div class="form-group"><label>Platnost od</label><input type="date" name="valid_from"></div>
      <div class="form-group"><label>Platnost do</label><input type="date" name="valid_until"></div>
    </div>

    <!-- Soubory -->
    <div style="border:1px solid var(--border);border-radius:var(--radius-sm);padding:1rem;margin-bottom:1rem;background:var(--gray-lt)">
      <div class="form-group" style="margin-bottom:.75rem">
        <label>📄 Hlavní soubor * <span style="font-size:11px;color:var(--muted)">(PDF, DOC, DOCX, XLS, XLSX, JPG, PNG – max. 20 MB)</span></label>
        <input type="file" name="document" required accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png">
      </div>
      <div class="form-group" style="margin-bottom:0">
        <label>📎 Příloha / druhý soubor <span style="font-size:11px;color:var(--muted)">(volitelné – např. podepsané PDF)</span></label>
        <input type="file" name="document2" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png">
      </div>
    </div>

    <div class="check-row" style="margin-bottom:1rem">
      <input type="checkbox" id="visible" name="visible_to_owners">
      <label for="visible">Zobrazit vlastníkům (viditelné po přihlášení)</label>
    </div>
    <button type="submit" class="btn btn-primary">⬆ Nahrát dokument</button>
  </form>
</div>

<!-- Kategorie -->
<div class="metrics" style="margin-bottom:1rem">
  <?php foreach ($categories as $key => $label): ?>
  <a href="?cat=<?= $key ?>" style="text-decoration:none">
    <div class="metric" style="<?= $filterCat===$key ? 'border:2px solid var(--blue);background:var(--blue-lt)' : '' ?>">
      <div style="font-size:20px"><?= $catIcons[$key] ?></div>
      <div class="metric-num" style="font-size:18px"><?= $stats[$key] ?? 0 ?></div>
      <div class="metric-lbl"><?= e($label) ?></div>
    </div>
  </a>
  <?php endforeach; ?>
</div>

<!-- Filtr -->
<div class="card" style="margin-bottom:1rem">
  <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <input type="text" name="q" placeholder="Hledat…" value="<?= e($search) ?>"
           style="flex:1;min-width:180px;padding:7px 10px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:14px">
    <input type="hidden" name="cat" value="<?= e($filterCat) ?>">
    <button type="submit" class="btn btn-secondary btn-sm">Hledat</button>
    <?php if ($filterCat || $search): ?><a href="/admin/documents.php" class="btn btn-secondary btn-sm">Zrušit filtr</a><?php endif; ?>
  </form>
</div>

<!-- Seznam -->
<div class="card">
  <?php if (!$docs): ?>
    <p style="color:var(--muted);font-size:14px">Žádné dokumenty.</p>
  <?php else: ?>
  <div class="tbl-wrap">
  <table class="tbl">
    <thead>
      <tr><th>Dokument</th><th>Kategorie</th><th>Soubory</th><th>Platnost</th><th>Vlastníci</th><th>Nahráno</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($docs as $d):
      $ext = strtolower(pathinfo($d['original_name'], PATHINFO_EXTENSION));
      $icon = match($ext) { 'pdf' => '📄', 'doc','docx' => '📝', 'xls','xlsx' => '📊', 'jpg','jpeg','png' => '🖼️', default => '📁' };
      $size = $d['filesize'] ? ($d['filesize'] > 1048576 ? round($d['filesize']/1048576,1).' MB' : round($d['filesize']/1024).' KB') : '–';
      $validClass = '';
      if ($d['valid_until']) {
        $days = (strtotime($d['valid_until']) - time()) / 86400;
        $validClass = $days < 0 ? 'color:var(--red)' : ($days < 30 ? 'color:var(--amber)' : 'color:var(--green)');
      }
    ?>
    <tr>
      <td>
        <a href="<?= UPLOAD_URL . e($d['filename']) ?>" target="_blank" style="font-weight:500">
          <?= $icon ?> <?= e($d['title']) ?>
        </a>
        <?php if ($d['description']): ?><br><small style="color:var(--muted)"><?= e($d['description']) ?></small><?php endif; ?>
      </td>
      <td><span class="badge badge-blue"><?= $catIcons[$d['category']] ?> <?= e($categories[$d['category']]) ?></span></td>
      <td style="font-size:12px">
        <!-- Hlavní soubor -->
        <a href="<?= UPLOAD_URL . e($d['filename']) ?>" target="_blank"
           style="display:inline-flex;align-items:center;gap:4px;background:var(--blue-lt);color:var(--blue);padding:3px 8px;border-radius:4px;font-size:11px;font-weight:500;text-decoration:none;margin-bottom:3px">
          <?= $icon ?> <?= e($d['original_name']) ?> <span style="color:var(--muted)">(<?= $size ?>)</span>
        </a>
        <?php if ($d['filename2']): ?>
          <?php
            $ext2 = strtolower(pathinfo($d['original_name2'], PATHINFO_EXTENSION));
            $icon2 = match($ext2) { 'pdf' => '📄', 'doc','docx' => '📝', 'xls','xlsx' => '📊', 'jpg','jpeg','png' => '🖼️', default => '📁' };
            $size2 = $d['filesize2'] ? ($d['filesize2'] > 1048576 ? round($d['filesize2']/1048576,1).' MB' : round($d['filesize2']/1024).' KB') : '–';
          ?>
          <br>
          <a href="<?= UPLOAD_URL . e($d['filename2']) ?>" target="_blank"
             style="display:inline-flex;align-items:center;gap:4px;background:#EAF3DE;color:var(--green);padding:3px 8px;border-radius:4px;font-size:11px;font-weight:500;text-decoration:none">
            <?= $icon2 ?> <?= e($d['original_name2']) ?> <span style="color:var(--muted)">(<?= $size2 ?>)</span>
          </a>
        <?php endif; ?>
      </td>
      <td style="font-size:12px;<?= $validClass ?>">
        <?= $d['valid_from'] ? 'od '.date('j.n.Y', strtotime($d['valid_from'])).'<br>' : '' ?>
        <?= $d['valid_until'] ? 'do '.date('j.n.Y', strtotime($d['valid_until'])) : ($d['valid_from'] ? '' : '–') ?>
      </td>
      <td>
        <form method="POST" style="display:inline">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="action" value="toggle_visible">
          <input type="hidden" name="id" value="<?= $d['id'] ?>">
          <button type="submit" class="badge <?= $d['visible_to_owners'] ? 'badge-ok' : 'badge-miss' ?>" style="cursor:pointer;border:none">
            <?= $d['visible_to_owners'] ? '✓ Viditelný' : '✗ Skrytý' ?>
          </button>
        </form>
      </td>
      <td style="font-size:12px;color:var(--muted)"><?= date('j. n. Y', strtotime($d['created_at'])) ?><br><?= e($d['uploader']) ?></td>
      <td style="white-space:nowrap">
        <a class="btn btn-secondary btn-sm" href="<?= UPLOAD_URL . e($d['filename']) ?>" target="_blank">⬇</a>
        <?php if ($d['filename2']): ?>
          <a class="btn btn-secondary btn-sm" href="<?= UPLOAD_URL . e($d['filename2']) ?>" target="_blank" title="Stáhnout přílohu">📎</a>
        <?php endif; ?>
        <form method="POST" style="display:inline" onsubmit="return confirm('Smazat dokument včetně příloh?')">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= $d['id'] ?>">
          <button type="submit" class="btn btn-danger btn-sm">Smazat</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <div style="font-size:12px;color:var(--muted);margin-top:8px"><?= count($docs) ?> dokumentů</div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
