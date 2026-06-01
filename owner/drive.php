<?php
require_once __DIR__ . '/../includes/functions.php';
$user = requireLogin();
$pageTitle = 'Google Drive';
$db = db();

$links = $db->query('SELECT * FROM drive_links WHERE visible_to_owners=1 ORDER BY order_num, title')->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="page-hd"><h1>🔗 Dokumenty na Google Drive</h1></div>

<?php if (!$links): ?>
<div class="card">
  <p style="color:var(--muted);font-size:14px">Zatím žádné sdílené složky.</p>
</div>
<?php else: ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:1rem">
  <?php foreach ($links as $l): ?>
  <a href="<?= e($l['url']) ?>" target="_blank" style="text-decoration:none">
    <div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius);padding:1.25rem;display:flex;align-items:center;gap:12px;transition:box-shadow .15s;cursor:pointer"
         onmouseover="this.style.boxShadow='0 4px 16px rgba(0,0,0,.1)'"
         onmouseout="this.style.boxShadow='none'">
      <div style="font-size:36px;flex-shrink:0"><?= e($l['icon']) ?></div>
      <div>
        <div style="font-weight:600;color:var(--text);font-size:15px"><?= e($l['title']) ?></div>
        <?php if ($l['description']): ?>
          <div style="font-size:12px;color:var(--muted);margin-top:3px"><?= e($l['description']) ?></div>
        <?php endif; ?>
        <div style="font-size:11px;color:var(--blue);margin-top:4px">Otevřít na Google Drive →</div>
      </div>
    </div>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
