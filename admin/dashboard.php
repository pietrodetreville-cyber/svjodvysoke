<?php
require_once __DIR__ . '/../includes/functions.php';
$user = requireAdmin();
$pageTitle = 'Přehled';

$db = db();
$units_total  = $db->query('SELECT COUNT(*) FROM units')->fetchColumn();
$owners_ok    = $db->query("SELECT COUNT(*) FROM owners WHERE status='úplná'")->fetchColumn();
$owners_part  = $db->query("SELECT COUNT(*) FROM owners WHERE status='neúplná'")->fetchColumn();
$owners_miss  = $db->query("SELECT COUNT(*) FROM owners WHERE status='chybí'")->fetchColumn();
$polls_active = $db->query("SELECT COUNT(*) FROM polls WHERE active=1")->fetchColumn();

$recent = $db->query(
    "SELECT o.full_name, o.updated_at, u.label AS unit_label
     FROM owners o JOIN units u ON o.unit_id=u.id
     ORDER BY o.updated_at DESC LIMIT 5"
)->fetchAll();

$posts = $db->query(
    "SELECT p.title, p.created_at, us.username AS author
     FROM posts p JOIN users us ON p.author_id=us.id
     ORDER BY p.pinned DESC, p.created_at DESC LIMIT 4"
)->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="page-hd"><h1>Přehled</h1></div>

<div class="metrics">
  <div class="metric"><div class="metric-num"><?= (int)$units_total ?></div><div class="metric-lbl">Jednotek celkem</div></div>
  <div class="metric"><div class="metric-num" style="color:var(--green)"><?= (int)$owners_ok ?></div><div class="metric-lbl">Karet úplných</div></div>
  <div class="metric"><div class="metric-num" style="color:var(--amber)"><?= (int)$owners_part ?></div><div class="metric-lbl">Neúplných</div></div>
  <div class="metric"><div class="metric-num" style="color:var(--red)"><?= (int)$owners_miss ?></div><div class="metric-lbl">Chybí</div></div>
  <div class="metric"><div class="metric-num"><?= (int)$polls_active ?></div><div class="metric-lbl">Aktivní ankety</div></div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">

<div class="card">
  <div class="card-title">Naposledy upravené karty</div>
  <?php if ($recent): foreach ($recent as $r): ?>
  <div class="post-item">
    <div class="post-meta"><?= e($r['unit_label']) ?> &nbsp;·&nbsp; <?= date('j. n. Y', strtotime($r['updated_at'])) ?></div>
    <div class="post-title"><?= e($r['full_name']) ?></div>
  </div>
  <?php endforeach; else: ?>
  <p style="font-size:13px;color:var(--muted)">Zatím žádná data.</p>
  <?php endif; ?>
  <div style="margin-top:1rem"><a class="btn btn-secondary btn-sm" href="/admin/owners.php">Otevřít kartotéku →</a></div>
</div>

<div class="card">
  <div class="card-title">Poslední příspěvky na nástěnce</div>
  <?php if ($posts): foreach ($posts as $p): ?>
  <div class="post-item">
    <div class="post-meta"><?= date('j. n. Y', strtotime($p['created_at'])) ?> &nbsp;·&nbsp; <?= e($p['author']) ?></div>
    <div class="post-title"><?= e($p['title']) ?></div>
  </div>
  <?php endforeach; else: ?>
  <p style="font-size:13px;color:var(--muted)">Žádné příspěvky.</p>
  <?php endif; ?>
  <div style="margin-top:1rem"><a class="btn btn-secondary btn-sm" href="/admin/posts.php">Nástěnka →</a></div>
</div>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
