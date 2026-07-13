<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
$user = requireAdmin();
$pageTitle = 'Per rollam';
$db = db();

$db->query("UPDATE perrollam SET status='uzavreno' WHERE closes_at < NOW() AND status='aktivni'");

// Přidat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    csrfCheck();
    $title    = trim($_POST['title'] ?? '');
    $desc     = trim($_POST['description'] ?? '');
    $closes   = $_POST['closes_at'] ?? '';
    $sendMail = isset($_POST['send_mail']);
    $bcc      = isset($_POST['bcc']);
    $mailTarget  = $_POST['mail_target'] ?? 'all';
    $selectedIds = $_POST['selected_owners'] ?? [];

    if ($title && $closes) {
        $db->prepare('INSERT INTO perrollam (title, description, closes_at, created_by) VALUES (?,?,?,?)')
           ->execute([$title, $desc, $closes, $user['id']]);
        $pid = $db->lastInsertId();

        $items = array_filter(array_map('trim', $_POST['items'] ?? []));
        $descs = $_POST['item_descs'] ?? [];
        $ins = $db->prepare('INSERT INTO perrollam_items (perrollam_id, order_num, title, description) VALUES (?,?,?,?)');
        $itemsData = [];
        foreach (array_values($items) as $i => $item) {
            $ins->execute([$pid, $i+1, $item, $descs[$i] ?? '']);
            $itemsData[] = ['title' => $item, 'description' => $descs[$i] ?? ''];
        }

        flash('Per rollam hlasování vytvořeno.', 'success');

        // Rozeslat e-mail
        if ($sendMail && !empty($itemsData)) {
            if ($mailTarget === 'all') {
                $emails = $db->query("SELECT DISTINCT email FROM owners WHERE email IS NOT NULL AND email != '' AND notify_email = 1")->fetchAll(PDO::FETCH_COLUMN);
            } else {
                $in = implode(',', array_map('intval', $selectedIds));
                $emails = $in ? $db->query("SELECT email FROM owners WHERE id IN ($in) AND email IS NOT NULL AND email != '' AND notify_email = 1")->fetchAll(PDO::FETCH_COLUMN) : [];
            }
            if ($emails) {
                $html = mailTemplatePerRollam($title, $desc, $itemsData, $closes);
                $ok = sendMail($emails, '[SVJ Od Vysoke] Per rollam: ' . $title, $html, [], $bcc);
                flash(($ok ? 'E-mail odeslán ' : 'Per rollam vytvořeno, e-mail se nepodařilo odeslat — ') . count($emails) . ' příjemcům.', $ok ? 'success' : 'warning');
            }

            // Upozornění na vlastníky bez e-mailu
            $withoutEmail = $db->query(
                "SELECT o.full_name, u.label FROM owners o JOIN units u ON o.unit_id=u.id
                 WHERE (o.email IS NULL OR o.email='') AND u.type='byt' ORDER BY u.label"
            )->fetchAll();

            if ($withoutEmail) {
                $_SESSION['perrollam_no_email'] = $withoutEmail;
                $_SESSION['perrollam_id'] = $pid;
            }
        }
    }
    header('Location: /admin/perrollam.php'); exit;
}

// Uzavřít
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'close') {
    csrfCheck();
    $db->prepare("UPDATE perrollam SET status='uzavreno' WHERE id=?")->execute([(int)$_POST['id']]);
    header('Location: /admin/perrollam.php'); exit;
}

// Smazat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrfCheck();
    $db->prepare('DELETE FROM perrollam WHERE id=?')->execute([(int)$_POST['id']]);
    flash('Hlasování smazáno.', 'success');
    header('Location: /admin/perrollam.php'); exit;
}

$list = $db->query(
    'SELECT p.*, u.username AS author,
     (SELECT COUNT(DISTINCT pv.unit_id) FROM perrollam_votes pv JOIN perrollam_items pi ON pv.item_id=pi.id WHERE pi.perrollam_id=p.id) AS voted_count
     FROM perrollam p JOIN users u ON p.created_by=u.id
     ORDER BY p.created_at DESC'
)->fetchAll();

$totalUnits = $db->query("SELECT COUNT(*) FROM units WHERE type='byt'")->fetchColumn();
$owners = $db->query("SELECT o.id, o.full_name, o.email, u.label FROM owners o JOIN units u ON o.unit_id=u.id WHERE o.email IS NOT NULL AND o.email != '' ORDER BY u.label")->fetchAll();

// Vlastníci bez e-mailu
$withoutEmail = $db->query(
    "SELECT o.full_name, u.label FROM owners o JOIN units u ON o.unit_id=u.id
     WHERE (o.email IS NULL OR o.email='') AND u.type='byt' ORDER BY u.label"
)->fetchAll();

// Zpráva o vlastnících bez e-mailu po odeslání
$noEmailAlert = null;
if (!empty($_SESSION['perrollam_no_email'])) {
    $noEmailAlert = $_SESSION['perrollam_no_email'];
    $noEmailPid   = $_SESSION['perrollam_id'] ?? null;
    unset($_SESSION['perrollam_no_email'], $_SESSION['perrollam_id']);
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-hd"><h1>Per rollam hlasování</h1></div>

<?php if ($noEmailAlert): ?>
<div class="card" style="margin-bottom:1.25rem;border-color:#FAC775;background:var(--amber-lt)">
  <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
    <span style="font-size:20px">⚠️</span>
    <div>
      <strong style="color:var(--amber)">Tito vlastníci nemají e-mail — nedostali pozvánku:</strong>
    </div>
    <?php if ($noEmailPid): ?>
      <a class="btn btn-secondary btn-sm" href="/admin/perrollam_print.php?id=<?= $noEmailPid ?>" target="_blank" style="margin-left:auto">
        🖨 Tisk PDF pro doručení
      </a>
    <?php endif; ?>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php foreach ($noEmailAlert as $n): ?>
      <span style="background:#fff;border:1px solid #FAC775;padding:3px 10px;border-radius:99px;font-size:13px">
        <?= e($n['label']) ?> – <?= e($n['full_name']) ?>
      </span>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Vlastníci bez e-mailu - trvalé upozornění -->
<?php if ($withoutEmail): ?>
<div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-sm);padding:.75rem 1rem;margin-bottom:1.25rem;font-size:13px;color:var(--muted);display:flex;align-items:center;gap:10px;flex-wrap:wrap">
  <span>⚠️ <strong style="color:var(--text)"><?= count($withoutEmail) ?> vlastníků</strong> nemá e-mail v kartotéce:</span>
  <?php foreach ($withoutEmail as $n): ?>
    <span style="background:var(--amber-lt);color:var(--amber);padding:2px 8px;border-radius:99px;font-size:12px">
      <?= e($n['label']) ?>
    </span>
  <?php endforeach; ?>
  <a href="/admin/owners.php?filter=chybí" class="btn btn-secondary btn-sm" style="margin-left:auto">Doplnit e-maily →</a>
</div>
<?php endif; ?>

<!-- Nové hlasování -->
<div class="card" style="max-width:700px;margin-bottom:1.5rem">
  <div class="card-title">Nové per rollam hlasování</div>
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="action" value="add">

    <div class="form-group">
      <label>Název hlasování *</label>
      <input type="text" name="title" required placeholder="Souhlas s opravou střechy">
    </div>
    <div class="form-group">
      <label>Popis / důvod hlasování</label>
      <textarea name="description" placeholder="Stručný popis o čem se hlasuje a proč..."></textarea>
    </div>
    <div class="form-group" style="max-width:280px">
      <label>Uzavřít hlasování k *</label>
      <input type="datetime-local" name="closes_at" required>
    </div>

    <div class="form-group">
      <label>Body hlasování (každý bod = Pro/Proti/Zdržel se)</label>
      <div id="items-wrap">
        <div style="display:flex;gap:8px;margin-bottom:8px">
          <div style="flex:2"><input type="text" name="items[]" placeholder="Bod 1 – název" style="width:100%;font-size:13px;padding:7px 9px;border:1px solid var(--border);border-radius:var(--radius-sm);margin-bottom:4px"><input type="text" name="item_descs[]" placeholder="Popis (volitelné)" style="width:100%;font-size:12px;padding:5px 9px;border:1px solid var(--border);border-radius:var(--radius-sm);color:var(--muted)"></div>
        </div>
        <div style="display:flex;gap:8px;margin-bottom:8px">
          <div style="flex:2"><input type="text" name="items[]" placeholder="Bod 2 – název (volitelné)" style="width:100%;font-size:13px;padding:7px 9px;border:1px solid var(--border);border-radius:var(--radius-sm);margin-bottom:4px"><input type="text" name="item_descs[]" placeholder="Popis (volitelné)" style="width:100%;font-size:12px;padding:5px 9px;border:1px solid var(--border);border-radius:var(--radius-sm);color:var(--muted)"></div>
        </div>
      </div>
      <button type="button" class="btn btn-secondary btn-sm" onclick="addItem()">+ Přidat bod</button>
    </div>

    <!-- E-mail sekce -->
    <div style="border:1px solid var(--border);border-radius:var(--radius-sm);padding:1rem;margin-bottom:1rem;background:var(--gray-lt)">
      <div class="check-row" style="margin-bottom:.75rem">
        <input type="checkbox" id="send_mail" name="send_mail"
               onchange="document.getElementById('mail-opts').style.display=this.checked?'block':'none'">
        <label for="send_mail" style="font-weight:500;color:var(--text)">
          📧 Upozornit vlastníky e-mailem
          <?php if ($withoutEmail): ?>
            <span style="color:var(--amber);font-size:12px">(<?= count($withoutEmail) ?> bez e-mailu — pro ně vytiskněte PDF)</span>
          <?php endif; ?>
        </label>
      </div>

      <div id="mail-opts" style="display:none">
        <div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius-sm);padding:.75rem;margin-bottom:.75rem">
          <div class="check-row">
            <input type="checkbox" id="bcc" name="bcc" checked>
            <label for="bcc" style="font-size:13px"><strong>Skrytá kopie (BCC)</strong> — příjemci se navzájem nevidí</label>
          </div>
        </div>
        <div style="display:flex;gap:1rem;margin-bottom:.75rem">
          <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
            <input type="radio" name="mail_target" value="all" checked
                   onchange="document.getElementById('owner-select').style.display='none'">
            Všem s e-mailem (<?= count($owners) ?>)
          </label>
          <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
            <input type="radio" name="mail_target" value="selected"
                   onchange="document.getElementById('owner-select').style.display='block'">
            Vybraným vlastníkům
          </label>
        </div>
        <div id="owner-select" style="display:none;max-height:200px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius-sm);background:#fff;padding:.5rem">
          <div style="padding:4px 8px;margin-bottom:4px;display:flex;gap:6px">
            <button type="button" class="btn btn-secondary btn-sm" onclick="toggleAll(true)">Vybrat vše</button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="toggleAll(false)">Zrušit vše</button>
          </div>
          <?php foreach ($owners as $o): ?>
          <label style="display:flex;align-items:center;gap:8px;padding:4px 8px;font-size:13px;cursor:pointer">
            <input type="checkbox" name="selected_owners[]" value="<?= $o['id'] ?>" class="owner-chk">
            <span><strong><?= e($o['label']) ?></strong> – <?= e($o['full_name']) ?> <span style="color:var(--muted);font-size:11px">&lt;<?= e($o['email']) ?>&gt;</span></span>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <button type="submit" class="btn btn-primary">Vytvořit hlasování</button>
  </form>
</div>

<!-- Seznam hlasování -->
<?php foreach ($list as $p):
  $isActive = $p['status'] === 'aktivni';
  $closes = new DateTime($p['closes_at']);
  $diff = (new DateTime())->diff($closes);
  $remainStr = $isActive ? ($diff->days > 0 ? $diff->days.' dní' : $diff->h.' hod.') : 'uzavřeno';
?>
<div class="card" style="margin-bottom:1rem;opacity:<?= $isActive ? 1 : 0.75 ?>">
  <div style="display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap">
    <div style="flex:1;min-width:200px">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
        <span style="font-weight:600;font-size:15px"><?= e($p['title']) ?></span>
        <span class="badge <?= $isActive ? 'badge-ok' : 'badge-miss' ?>"><?= $isActive ? 'Aktivní' : 'Uzavřeno' ?></span>
      </div>
      <?php if ($p['description']): ?><div style="font-size:13px;color:var(--muted);margin-bottom:6px"><?= e($p['description']) ?></div><?php endif; ?>
      <div style="font-size:12px;color:var(--muted)">
        📅 <?= date('j. n. Y H:i', strtotime($p['closes_at'])) ?>
        <?php if ($isActive): ?>&nbsp;·&nbsp; ⏳ <?= $remainStr ?><?php endif; ?>
        &nbsp;·&nbsp; Hlasovalo: <strong><?= $p['voted_count'] ?></strong> z <?= $totalUnits ?>
      </div>
    </div>
    <div style="display:flex;flex-direction:column;gap:6px;flex-shrink:0">
      <a class="btn btn-primary btn-sm" href="/admin/perrollam_detail.php?id=<?= $p['id'] ?>">Výsledky →</a>
      <a class="btn btn-secondary btn-sm" href="/admin/perrollam_print.php?id=<?= $p['id'] ?>" target="_blank">🖨 PDF</a>
      <?php if ($isActive): ?>
      <form method="POST"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="close"><input type="hidden" name="id" value="<?= $p['id'] ?>">
        <button type="submit" class="btn btn-secondary btn-sm">■ Uzavřít</button>
      </form>
      <?php endif; ?>
      <form method="POST" onsubmit="return confirm('Smazat?')"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $p['id'] ?>">
        <button type="submit" class="btn btn-danger btn-sm">Smazat</button>
      </form>
    </div>
  </div>
</div>
<?php endforeach; ?>
<?php if (!$list): ?><div class="card"><p style="color:var(--muted);font-size:14px">Zatím žádné per rollam hlasování.</p></div><?php endif; ?>

<script>
let itemCount = 2;
function addItem() {
  itemCount++;
  const wrap = document.getElementById('items-wrap');
  const div = document.createElement('div');
  div.style.cssText = 'display:flex;gap:8px;margin-bottom:8px';
  div.innerHTML = `<div style="flex:2"><input type="text" name="items[]" placeholder="Bod ${itemCount} – název" style="width:100%;font-size:13px;padding:7px 9px;border:1px solid var(--border);border-radius:var(--radius-sm);margin-bottom:4px"><input type="text" name="item_descs[]" placeholder="Popis (volitelné)" style="width:100%;font-size:12px;padding:5px 9px;border:1px solid var(--border);border-radius:var(--radius-sm);color:var(--muted)"></div>`;
  wrap.appendChild(div);
}
function toggleAll(state) { document.querySelectorAll('.owner-chk').forEach(c => c.checked = state); }
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
