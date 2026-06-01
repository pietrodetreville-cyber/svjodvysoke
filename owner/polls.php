<?php
require_once __DIR__ . '/../includes/functions.php';
$user = requireLogin();
$pageTitle = 'Ankety';
$db = db();

// Hlas
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user['unit_id']) {
    csrfCheck();
    $pollId   = (int)$_POST['poll_id'];
    $optionId = (int)$_POST['option_id'];
    $unitId   = $user['unit_id'];

    // Ověř, že anketa existuje a je aktivní
    $stmt = $db->prepare('SELECT id FROM polls WHERE id=? AND active=1');
    $stmt->execute([$pollId]);
    if ($stmt->fetch()) {
        // Ověř platnost možnosti
        $stmt2 = $db->prepare('SELECT id FROM poll_options WHERE id=? AND poll_id=?');
        $stmt2->execute([$optionId, $pollId]);
        if ($stmt2->fetch()) {
            try {
                $db->prepare('INSERT INTO poll_votes (poll_id,option_id,unit_id) VALUES (?,?,?)')->execute([$pollId,$optionId,$unitId]);
                flash('Váš hlas byl zaznamenán.', 'success');
            } catch (\PDOException $e) {
                flash('Za tuto anketu jste již hlasovali.', 'warning');
            }
        }
    }
    header('Location: /owner/polls.php'); exit;
}

$polls = $db->query('SELECT * FROM polls ORDER BY active DESC, created_at DESC')->fetchAll();
include __DIR__ . '/../includes/header.php';
?>

<div class="page-hd"><h1>Ankety</h1></div>

<?php if (!$polls): ?>
  <div class="card"><p style="color:var(--muted);font-size:14px">Zatím žádné ankety.</p></div>
<?php endif; ?>

<?php foreach ($polls as $poll):
  $opts = $db->prepare('SELECT po.*, COUNT(pv.id) AS votes FROM poll_options po LEFT JOIN poll_votes pv ON pv.option_id=po.id WHERE po.poll_id=? GROUP BY po.id');
  $opts->execute([$poll['id']]);
  $options = $opts->fetchAll();
  $total   = array_sum(array_column($options, 'votes'));

  // Hlasoval jsem?
  $alreadyVoted = false;
  if ($user['unit_id']) {
      $vst = $db->prepare('SELECT id FROM poll_votes WHERE poll_id=? AND unit_id=?');
      $vst->execute([$poll['id'], $user['unit_id']]);
      $alreadyVoted = (bool)$vst->fetch();
  }
?>
<div class="card" style="margin-bottom:1rem;opacity:<?= $poll['active'] ? 1 : 0.7 ?>">
  <div style="display:flex;align-items:center;gap:8px;margin-bottom:.75rem">
    <?= $poll['active'] ? '<span class="badge badge-ok">Aktivní</span>' : '<span class="badge badge-miss">Uzavřená</span>' ?>
    <?php if ($poll['closes_at']): ?><span style="font-size:12px;color:var(--muted)">do <?= date('j. n. Y', strtotime($poll['closes_at'])) ?></span><?php endif; ?>
    <span style="font-size:12px;color:var(--muted)">&nbsp;· <?= (int)$total ?> hlasů</span>
  </div>
  <div style="font-size:16px;font-weight:600;margin-bottom:12px"><?= e($poll['question']) ?></div>

  <?php if ($poll['active'] && !$alreadyVoted && $user['unit_id']): ?>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="poll_id" value="<?= $poll['id'] ?>">
      <?php foreach ($options as $opt): ?>
        <label style="display:flex;align-items:center;gap:8px;padding:7px 0;border-bottom:1px solid var(--border);cursor:pointer;font-size:14px">
          <input type="radio" name="option_id" value="<?= $opt['id'] ?>" required>
          <?= e($opt['option_text']) ?>
        </label>
      <?php endforeach; ?>
      <button type="submit" class="btn btn-primary" style="margin-top:12px">Odeslat hlas</button>
    </form>
  <?php else: ?>
    <?php foreach ($options as $opt):
      $pct = $total ? round($opt['votes'] / $total * 100) : 0; ?>
      <div class="poll-row">
        <span style="min-width:180px"><?= e($opt['option_text']) ?></span>
        <div class="poll-bar-wrap"><div class="poll-bar" style="width:<?= $pct ?>%"></div></div>
        <span style="min-width:55px;text-align:right;color:var(--muted);font-size:13px"><?= $pct ?> %</span>
      </div>
    <?php endforeach; ?>
    <?php if ($alreadyVoted): ?><p style="font-size:12px;color:var(--green);margin-top:8px">✓ Váš hlas byl zaznamenán</p><?php endif; ?>
  <?php endif; ?>
</div>
<?php endforeach; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
