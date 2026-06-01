<?php
require_once __DIR__ . '/../includes/functions.php';
$user = requireLogin();
$pageTitle = 'Nástěnka';
$db = db();

// Vlastníci vidí veřejné + jen pro přihlášené
$posts = $db->query(
    "SELECT p.*, u.username AS author FROM posts p JOIN users u ON p.author_id=u.id
     WHERE p.visibility IN ('verejny','prihlaseni')
     ORDER BY p.pinned DESC, p.created_at DESC"
)->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="page-hd"><h1>Nástěnka výboru</h1></div>

<div class="card">
<?php if ($posts): foreach ($posts as $p): ?>
  <div class="post-item">
    <?php if ($p['pinned']): ?><span class="badge badge-blue">Připnutý</span><br><?php endif; ?>
    <?php if ($p['visibility']==='prihlaseni'): ?><span class="badge badge-partial" style="font-size:10px">🔒 Jen pro členy</span><br><?php endif; ?>
    <div class="post-meta"><?= date('j. n. Y', strtotime($p['created_at'])) ?></div>
    <div class="post-title"><?= e($p['title']) ?></div>
    <div class="post-body"><?= nl2br(e($p['body'])) ?></div>
  </div>
<?php endforeach; else: ?>
  <p style="color:var(--muted);font-size:14px">Žádné příspěvky.</p>
<?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
