<?php
require_once __DIR__ . '/../includes/functions.php';
$user = requireLogin();
if ($user['role'] === 'admin') { header('Location: /admin/perrollam.php'); exit; }
$pageTitle = 'Per rollam hlasování';
$db = db();

$db->query("UPDATE perrollam SET status='uzavreno' WHERE closes_at < NOW() AND status='aktivni'");

$unitId  = $user['unit_id'];
$ownerId = null;
if ($unitId) {
    $o = $db->prepare('SELECT id FROM owners WHERE unit_id=? LIMIT 1');
    $o->execute([$unitId]);
    $ownerId = $o->fetchColumn();
}

// Hlasovat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'vote' && $unitId && $ownerId) {
    csrfCheck();
    $itemId = (int)$_POST['item_id'];
    $vote   = $_POST['vote'] ?? '';
    if (in_array($vote, ['pro','proti','zdrzelse'])) {
        // Ověř že hlasování je aktivní
        $check = $db->prepare(
            'SELECT p.status FROM perrollam p
             JOIN perrollam_items pi ON pi.perrollam_id=p.id
             WHERE pi.id=? AND p.status="aktivni"'
        );
        $check->execute([$itemId]);
        if ($check->fetch()) {
            try {
                $db->prepare('INSERT INTO perrollam_votes (item_id,unit_id,owner_id,vote) VALUES (?,?,?,?)')
                   ->execute([$itemId, $unitId, $ownerId, $vote]);
            } catch (\PDOException $e) {
                // Již hlasoval
            }
        }
    }
    header('Location: /owner/perrollam.php'); exit;
}

$list = $db->query(
    "SELECT * FROM perrollam WHERE status='aktivni' ORDER BY closes_at ASC"
)->fetchAll();

$closed = $db->query(
    "SELECT * FROM perrollam WHERE status='uzavreno' ORDER BY closes_at DESC LIMIT 5"
)->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="page-hd"><h1>Per rollam hlasování</h1></div>

<?php if (!$unitId): ?>
<div class="card" style="background:var(--amber-lt);border-color:#FAC775">
  <p style="color:var(--amber)">⚠ Nemáte přiřazenou jednotku. Kontaktujte výbor SVJ.</p>
</div>
<?php endif; ?>

<!-- AKTIVNÍ HLASOVÁNÍ -->
<?php if ($list): ?>
<div style="margin-bottom:1.5rem">
<?php foreach ($list as $pr):
  $items = $db->prepare('SELECT * FROM perrollam_items WHERE perrollam_id=? ORDER BY order_num');
  $items->execute([$pr['id']]);
  $items = $items->fetchAll();
  $closes = new DateTime($pr['closes_at']);
  $diff = (new DateTime())->diff($closes);
  $remain = $diff->days > 0 ? $diff->days.' dní' : $diff->h.' hod.';
?>
<div class="card" style="margin-bottom:1rem;border-color:#b5d0f0">
  <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
    <span style="font-weight:600;font-size:15px"><?= e($pr['title']) ?></span>
    <span class="badge badge-ok">Aktivní</span>
    <span style="font-size:12px;color:var(--muted)">⏳ Zbývá: <?= $remain ?></span>
  </div>
  <?php if ($pr['description']): ?>
    <p style="font-size:13px;color:var(--muted);margin-bottom:12px"><?= nl2br(e($pr['description'])) ?></p>
  <?php endif; ?>
  <div style="font-size:12px;color:var(--muted);margin-bottom:12px">
    Uzavření: <strong><?= date('j. n. Y H:i', strtotime($pr['closes_at'])) ?></strong>
  </div>

  <?php foreach ($items as $item):
    // Hlasoval už?
    $myVote = null;
    if ($unitId) {
      $mv = $db->prepare('SELECT vote FROM perrollam_votes WHERE item_id=? AND unit_id=?');
      $mv->execute([$item['id'], $unitId]);
      $myVote = $mv->fetchColumn();
    }
  ?>
  <div style="border:1px solid var(--border);border-radius:var(--radius-sm);padding:1rem;margin-bottom:10px;background:<?= $myVote ? 'var(--green-lt)' : '#fff' ?>">
    <div style="font-weight:600;margin-bottom:4px">Bod <?= $item['order_num'] ?>. <?= e($item['title']) ?></div>
    <?php if ($item['description']): ?>
      <p style="font-size:12px;color:var(--muted);margin-bottom:8px"><?= e($item['description']) ?></p>
    <?php endif; ?>

    <?php if ($myVote): ?>
      <div style="color:var(--green);font-weight:600;font-size:14px">
        ✓ Váš hlas byl zaznamenán:
        <?= match($myVote) { 'pro' => 'PRO', 'proti' => 'PROTI', default => 'ZDRŽEL JSEM SE' } ?>
      </div>
    <?php elseif ($unitId): ?>
      <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="vote">
        <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
        <button type="submit" name="vote" value="pro"
                class="btn btn-sm" style="background:var(--green-lt);color:var(--green);border-color:#b5d97a;font-weight:600">
          ✓ PRO
        </button>
        <button type="submit" name="vote" value="proti"
                class="btn btn-sm" style="background:var(--red-lt);color:var(--red);border-color:#f5b0b0;font-weight:600">
          ✗ PROTI
        </button>
        <button type="submit" name="vote" value="zdrzelse"
                class="btn btn-secondary btn-sm" style="font-weight:600">
          — ZDRŽÍM SE
        </button>
      </form>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>
</div>
<?php else: ?>
<div class="card" style="margin-bottom:1.5rem">
  <p style="color:var(--muted);font-size:14px">Žádné aktivní per rollam hlasování.</p>
</div>
<?php endif; ?>

<!-- UZAVŘENÁ -->
<?php if ($closed): ?>
<div style="font-size:14px;font-weight:600;color:var(--muted);margin-bottom:8px">Uzavřená hlasování</div>
<?php foreach ($closed as $pr): ?>
<div class="card" style="margin-bottom:8px;opacity:0.7">
  <div style="display:flex;align-items:center;gap:8px">
    <span style="font-weight:500"><?= e($pr['title']) ?></span>
    <span class="badge badge-miss">Uzavřeno</span>
    <span style="font-size:12px;color:var(--muted)"><?= date('j. n. Y', strtotime($pr['closes_at'])) ?></span>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
