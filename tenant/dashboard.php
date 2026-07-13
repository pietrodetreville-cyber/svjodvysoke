<?php
require_once __DIR__ . '/../includes/functions.php';
$user = requireLogin();
if ($user['role'] !== 'tenant') {
    header('Location: ' . ($user['role']==='admin'||$user['role']==='superadmin' ? '/admin/dashboard.php' : '/owner/dashboard.php'));
    exit;
}
$pageTitle = 'Domů';
$db = db();

$tenant = null;
if ($user['tenant_id']) {
    $stmt = $db->prepare('SELECT t.*, u.label AS unit_label FROM tenants t JOIN units u ON t.unit_id=u.id WHERE t.id=?');
    $stmt->execute([$user['tenant_id']]);
    $tenant = $stmt->fetch();
}

$posts = $db->query("SELECT * FROM posts WHERE visibility IN ('verejny','prihlaseni') ORDER BY pinned DESC, created_at DESC LIMIT 6")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="page-hd"><h1>Vítejte na portálu SVJ Od Vysoké</h1></div>

<?php if ($tenant): ?>
<div style="background:var(--blue-lt);border-radius:var(--radius-sm);padding:.75rem 1rem;font-size:13px;color:var(--blue);margin-bottom:1.25rem;display:flex;align-items:center;gap:12px">
  <span style="font-size:20px">🏠</span>
  <div>
    <strong>Jste přihlášen jako nájemník</strong><br>
    Jednotka: <strong><?= e($tenant['unit_label']) ?></strong>
    <?php if ($tenant['rent_from'] || $tenant['rent_until']): ?>
      &nbsp;·&nbsp; Nájem:
      <?= $tenant['rent_from'] ? date('j. n. Y', strtotime($tenant['rent_from'])) : '' ?>
      <?= $tenant['rent_until'] ? '– '.date('j. n. Y', strtotime($tenant['rent_until'])) : '(neurčito)' ?>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div style="background:var(--amber-lt);border:1px solid #FAC775;border-radius:var(--radius-sm);padding:.75rem 1rem;font-size:13px;color:var(--amber);margin-bottom:1.25rem">
  ℹ️ Nájemníci mají přístup k nástěnce a dokumentům. Hlasování a ankety jsou určeny vlastníkům jednotek.
</div>

<div class="card">
  <div class="card-title">📋 Aktuality výboru</div>
  <?php if ($posts): foreach ($posts as $p): ?>
    <div class="post-item">
      <?php if ($p['pinned']): ?><span class="badge badge-blue">Připnutý</span><br><?php endif; ?>
      <div class="post-meta"><?= date('j. n. Y', strtotime($p['created_at'])) ?></div>
      <div class="post-title"><?= e($p['title']) ?></div>
      <div class="post-body"><?= nl2br(e(mb_substr($p['body'],0,200))) ?><?= mb_strlen($p['body'])>200?'…':'' ?></div>
    </div>
  <?php endforeach; else: ?>
    <p style="color:var(--muted);font-size:14px">Žádné aktuality.</p>
  <?php endif; ?>
  <div style="margin-top:1rem"><a class="btn btn-secondary btn-sm" href="/owner/posts.php">Všechny příspěvky →</a></div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
