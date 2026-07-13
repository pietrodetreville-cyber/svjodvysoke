<?php
require_once __DIR__ . '/../includes/functions.php';
$user = requireLogin();
if ($user['role'] === 'admin') { header('Location: /admin/documents.php'); exit; }
$pageTitle = 'Dokumenty';
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

$filterCat = $_GET['cat'] ?? '';
$where = 'visible_to_owners=1';
$params = [];
if ($filterCat && array_key_exists($filterCat, $categories)) {
    $where .= ' AND category=?'; $params[] = $filterCat;
}

$docs = $db->prepare("SELECT * FROM documents WHERE $where ORDER BY category, created_at DESC");
$docs->execute($params);
$docs = $docs->fetchAll();

// Skupiny dle kategorie
$grouped = [];
foreach ($docs as $d) $grouped[$d['category']][] = $d;

include __DIR__ . '/../includes/header.php';
?>

<div class="page-hd"><h1>Dokumenty SVJ</h1></div>

<!-- Filtr kategorií -->
<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:1.25rem">
  <a href="/owner/documents.php"
     class="btn btn-sm <?= !$filterCat ? 'btn-primary' : 'btn-secondary' ?>">Vše</a>
  <?php foreach ($categories as $key => $label):
    $cnt = count(array_filter($docs, fn($d) => $d['category'] === $key));
    if (!$cnt && $filterCat !== $key) continue;
  ?>
  <a href="?cat=<?= $key ?>"
     class="btn btn-sm <?= $filterCat===$key ? 'btn-primary' : 'btn-secondary' ?>">
    <?= $catIcons[$key] ?> <?= e($label) ?> (<?= $cnt ?>)
  </a>
  <?php endforeach; ?>
</div>

<?php if (!$docs): ?>
<div class="card">
  <p style="color:var(--muted);font-size:14px">Zatím nejsou k dispozici žádné dokumenty.</p>
</div>
<?php endif; ?>

<?php foreach ($grouped as $cat => $catDocs): ?>
<div class="card" style="margin-bottom:1rem">
  <div class="card-title"><?= $catIcons[$cat] ?> <?= e($categories[$cat]) ?></div>
  <table class="tbl">
    <thead>
      <tr><th>Dokument</th><th>Platnost</th><th>Datum</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($catDocs as $d): ?>
    <?php
      $ext = strtolower(pathinfo($d['original_name'], PATHINFO_EXTENSION));
      $icon = match($ext) {
        'pdf' => '📄', 'doc','docx' => '📝',
        'xls','xlsx' => '📊', 'jpg','jpeg','png' => '🖼️', default => '📁'
      };
      $validStr = '';
      if ($d['valid_from'] || $d['valid_until']) {
        $validStr = ($d['valid_from'] ? 'od '.date('j. n. Y', strtotime($d['valid_from'])) : '');
        $validStr .= ($d['valid_until'] ? ($validStr?' ':'').'do '.date('j. n. Y', strtotime($d['valid_until'])) : '');
        if ($d['valid_until'] && strtotime($d['valid_until']) < time()) {
          $validStr .= ' <span style="color:var(--red);font-size:11px">(prošlé)</span>';
        }
      }
    ?>
    <tr>
      <td>
        <span style="font-size:16px"><?= $icon ?></span>
        <strong><?= e($d['title']) ?></strong>
        <?php if ($d['description']): ?>
          <br><small style="color:var(--muted)"><?= e($d['description']) ?></small>
        <?php endif; ?>
      </td>
      <td style="font-size:12px;color:var(--muted)"><?= $validStr ?: '–' ?></td>
      <td style="font-size:12px;color:var(--muted);white-space:nowrap"><?= date('j. n. Y', strtotime($d['created_at'])) ?></td>
      <td>
        <a class="btn btn-primary btn-sm" href="<?= UPLOAD_URL . e($d['filename']) ?>" target="_blank">⬇ Stáhnout</a>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endforeach; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
