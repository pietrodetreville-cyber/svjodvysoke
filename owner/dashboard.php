<?php
require_once __DIR__ . '/../includes/functions.php';
$user = requireLogin();
if ($user['role'] === 'admin' || $user['role'] === 'superadmin') { header('Location: /admin/dashboard.php'); exit; }
$pageTitle = 'Domů';
$db = db();

$posts = $db->query("SELECT * FROM posts WHERE visibility IN ('verejny','prihlaseni') ORDER BY pinned DESC, created_at DESC LIMIT 5")->fetchAll();
$polls = $db->query('SELECT * FROM polls WHERE active=1 ORDER BY created_at DESC LIMIT 3')->fetchAll();

$myOwner = null;
if ($user['unit_id']) {
    $stmt = $db->prepare('SELECT * FROM owners WHERE unit_id=? LIMIT 1');
    $stmt->execute([$user['unit_id']]);
    $myOwner = $stmt->fetch();
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-hd"><h1>Vítejte na portálu SVJ Od Vysoké – Rozhled</h1></div>

<?php if (!$myOwner || $myOwner['status'] === 'chybí'): ?>
<div style="background:var(--amber-lt);border:1px solid #FAC775;border-radius:var(--radius);padding:1rem 1.25rem;margin-bottom:1rem;display:flex;align-items:center;gap:12px">
  <span style="font-size:22px">⚠️</span>
  <div>
    <strong>Vaše karta vlastníka není vyplněna.</strong><br>
    <span style="font-size:13px;color:var(--muted)">Prosíme, vyplňte základní kontaktní údaje.</span>
    <br><a class="btn btn-primary btn-sm" href="/owner/profile.php" style="margin-top:6px;display:inline-block">Vyplnit kartu →</a>
  </div>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">

<div class="card">
  <div class="card-title">📋 Aktuality výboru</div>
  <?php if ($posts): foreach ($posts as $p): ?>
    <div class="post-item">
      <?php if ($p['pinned']): ?><span class="badge badge-blue">Připnutý</span> <?php endif; ?>
      <?php if ($p['visibility']==='prihlaseni'): ?><span class="badge badge-partial" style="font-size:10px">🔒 Jen pro členy</span> <?php endif; ?>
      <div class="post-meta"><?= date('j. n. Y', strtotime($p['created_at'])) ?></div>
      <div class="post-title"><?= e($p['title']) ?></div>
      <div class="post-body"><?= nl2br(e(mb_substr($p['body'],0,160))) ?><?= mb_strlen($p['body'])>160?'…':'' ?></div>
    </div>
  <?php endforeach; else: ?><p style="color:var(--muted);font-size:14px">Žádné aktuality.</p>
  <?php endif; ?>
  <div style="margin-top:1rem"><a class="btn btn-secondary btn-sm" href="/owner/posts.php">Všechny příspěvky →</a></div>
</div>

<div class="card">
  <div class="card-title">🗳️ Aktivní ankety</div>
  <?php if ($polls): foreach ($polls as $poll): ?>
    <div class="post-item">
      <div class="post-title"><?= e($poll['question']) ?></div>
      <?php if ($poll['closes_at']): ?><div class="post-meta">do <?= date('j. n. Y', strtotime($poll['closes_at'])) ?></div><?php endif; ?>
      <a class="btn btn-primary btn-sm" href="/owner/polls.php" style="margin-top:6px;display:inline-block">Hlasovat →</a>
    </div>
  <?php endforeach; else: ?><p style="color:var(--muted);font-size:14px">Žádné aktivní ankety.</p>
  <?php endif; ?>
</div>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
