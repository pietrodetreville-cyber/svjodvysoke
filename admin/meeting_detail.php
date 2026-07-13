<?php
require_once __DIR__ . '/../includes/functions.php';
$user = requireAdmin();
$db = db();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: /admin/meetings.php'); exit; }

$meeting = $db->prepare('SELECT * FROM meetings WHERE id=?');
$meeting->execute([$id]);
$meeting = $meeting->fetch();
if (!$meeting) { header('Location: /admin/meetings.php'); exit; }

$pageTitle = $meeting['title'];

// === AKCE ===

// Přidat docházku
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'attend') {
    csrfCheck();
    try {
        $db->prepare('INSERT INTO meeting_attendance (meeting_id,owner_id,unit_id,type,proxy_name,arrived_at) VALUES (?,?,?,?,?,NOW())')
           ->execute([$id, (int)$_POST['owner_id'], (int)$_POST['unit_id'], $_POST['attend_type'], trim($_POST['proxy_name'] ?? '') ?: null]);
    } catch (\PDOException $e) {
        $db->prepare('UPDATE meeting_attendance SET type=?,proxy_name=?,arrived_at=NOW() WHERE meeting_id=? AND unit_id=?')
           ->execute([$_POST['attend_type'], trim($_POST['proxy_name'] ?? '') ?: null, $id, (int)$_POST['unit_id']]);
    }
    header("Location: /admin/meeting_detail.php?id=$id"); exit;
}

// Odebrat docházku
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_attend') {
    csrfCheck();
    $db->prepare('DELETE FROM meeting_attendance WHERE meeting_id=? AND unit_id=?')
       ->execute([$id, (int)$_POST['unit_id']]);
    header("Location: /admin/meeting_detail.php?id=$id"); exit;
}

// Upravit bod programu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_item') {
    csrfCheck();
    $db->prepare('UPDATE meeting_agenda_items SET title=?,description=?,general_description=?,resolution_proposal=?,vote_type=? WHERE id=? AND meeting_id=?')
       ->execute([trim($_POST['item_title']), trim($_POST['item_desc']), trim($_POST['general_description']??''), trim($_POST['resolution_proposal']??''), $_POST['vote_type'], (int)$_POST['item_id'], $id]);
    header("Location: /admin/meeting_detail.php?id=$id#agenda"); exit;
}

// Smazat bod programu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_item') {
    csrfCheck();
    $db->prepare('DELETE FROM meeting_agenda_items WHERE id=? AND meeting_id=?')->execute([(int)$_POST['item_id'], $id]);
    header("Location: /admin/meeting_detail.php?id=$id#agenda"); exit;
}

// Přidat bod programu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_agenda') {
    csrfCheck();
    $maxOrder = $db->prepare('SELECT COALESCE(MAX(order_num),0)+1 FROM meeting_agenda_items WHERE meeting_id=?');
    $maxOrder->execute([$id]);
    $db->prepare('INSERT INTO meeting_agenda_items (meeting_id,order_num,title,description,general_description,resolution_proposal,vote_type) VALUES (?,?,?,?,?,?,?)')
       ->execute([$id, $maxOrder->fetchColumn(), trim($_POST['item_title']), trim($_POST['item_desc']), trim($_POST['general_description']??''), trim($_POST['resolution_proposal']??''), $_POST['vote_type']]);
    header("Location: /admin/meeting_detail.php?id=$id#agenda"); exit;
}

// Uložit individuální hlasy
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_votes') {
    csrfCheck();
    $itemId = (int)$_POST['item_id'];
    $votes  = $_POST['votes'] ?? []; // [unit_id => 'pro'|'proti'|'zdrzelo'|'']

    // Smaž staré hlasy pro tento bod
    $db->prepare('DELETE FROM meeting_item_votes WHERE agenda_item_id=?')->execute([$itemId]);

    // Vlož nové
    $ins = $db->prepare('INSERT INTO meeting_item_votes (agenda_item_id,unit_id,owner_id,vote) VALUES (?,?,?,?)');
    foreach ($votes as $unitId => $vote) {
        if (!in_array($vote, ['pro','proti','zdrzelo'])) continue;
        // Najdi owner_id
        $o = $db->prepare('SELECT id FROM owners WHERE unit_id=? LIMIT 1');
        $o->execute([(int)$unitId]);
        $ownerId = $o->fetchColumn();
        if ($ownerId) $ins->execute([$itemId, (int)$unitId, $ownerId, $vote]);
    }

    // Přepočítej agregát do meeting_votes
    $agg = $db->prepare(
        'SELECT vote,
                COUNT(*) as cnt,
                COALESCE(SUM(ROUND(u.share_numerator/u.share_denominator*100,4)),0) as pct
         FROM meeting_item_votes miv
         JOIN units u ON miv.unit_id=u.id
         WHERE miv.agenda_item_id=?
         GROUP BY vote'
    );
    $agg->execute([$itemId]);
    $aggs = [];
    foreach ($agg->fetchAll() as $r) $aggs[$r['vote']] = $r;

    $result = $_POST['result'] ?? '';
    $note   = trim($_POST['vote_note'] ?? '');

    $db->prepare('DELETE FROM meeting_votes WHERE agenda_item_id=?')->execute([$itemId]);
    $db->prepare('INSERT INTO meeting_votes (agenda_item_id,vote_pro,vote_proti,vote_zdrzelo,vote_pro_count,vote_proti_count,vote_zdrzelo_count,result,note) VALUES (?,?,?,?,?,?,?,?,?)')
       ->execute([
           $itemId,
           $aggs['pro']['pct'] ?? 0,
           $aggs['proti']['pct'] ?? 0,
           $aggs['zdrzelo']['pct'] ?? 0,
           $aggs['pro']['cnt'] ?? 0,
           $aggs['proti']['cnt'] ?? 0,
           $aggs['zdrzelo']['cnt'] ?? 0,
           $result,
           $note,
       ]);

    flash('Hlasování uloženo.', 'success');
    header("Location: /admin/meeting_detail.php?id=$id#agenda"); exit;
}

// === DATA ===

$allUnits = $db->query(
    "SELECT u.id AS unit_id, u.label, u.share_numerator, u.share_denominator,
            CASE WHEN u.share_numerator IS NOT NULL AND u.share_denominator > 0
                 THEN ROUND(u.share_numerator/u.share_denominator*100,4) ELSE 0 END AS share_pct,
            o.id AS owner_id, o.full_name
     FROM units u LEFT JOIN owners o ON o.unit_id=u.id
     WHERE u.type='byt' ORDER BY u.label"
)->fetchAll();

$attendStmt = $db->prepare(
    'SELECT ma.*, u.label, o.full_name,
            un.share_numerator, un.share_denominator,
            CASE WHEN un.share_numerator IS NOT NULL AND un.share_denominator > 0
                 THEN ROUND(un.share_numerator/un.share_denominator*100,4) ELSE 0 END AS share_pct
     FROM meeting_attendance ma
     JOIN units un ON ma.unit_id=un.id
     JOIN owners o ON ma.owner_id=o.id
     JOIN units u ON ma.unit_id=u.id
     WHERE ma.meeting_id=? ORDER BY u.label'
);
$attendStmt->execute([$id]);
$attendance = $attendStmt->fetchAll();

$totalPct    = (float)$db->query("SELECT COALESCE(SUM(ROUND(share_numerator/share_denominator*100,4)),0) FROM units WHERE type='byt' AND share_numerator IS NOT NULL")->fetchColumn();
$presentPct  = array_sum(array_column($attendance, 'share_pct'));
$presentCount= count($attendance);
$quorumOk    = $presentPct >= $meeting['quorum_pct'];
$presentUnitIds = array_column($attendance, 'unit_id');

// Hranice většin
$maj50pres = round($presentPct * 0.50, 4);
$maj75pres = round($presentPct * 0.75, 4);
$maj50tot  = round($totalPct * 0.50, 4);
$maj75tot  = round($totalPct * 0.75, 4);

$agendaItems = $db->prepare(
    'SELECT ai.*, mv.vote_pro, mv.vote_proti, mv.vote_zdrzelo,
            mv.vote_pro_count, mv.vote_proti_count, mv.vote_zdrzelo_count,
            mv.result, mv.note AS vote_note
     FROM meeting_agenda_items ai
     LEFT JOIN meeting_votes mv ON mv.agenda_item_id=ai.id
     WHERE ai.meeting_id=? ORDER BY ai.order_num'
);
$agendaItems->execute([$id]);
$agendaItems = $agendaItems->fetchAll();

// Individuální hlasy pro každý bod
$itemVotes = [];
if ($agendaItems) {
    $itemIds = array_column($agendaItems, 'id');
    $in = implode(',', array_map('intval', $itemIds));
    $ivq = $db->query("SELECT * FROM meeting_item_votes WHERE agenda_item_id IN ($in)");
    foreach ($ivq->fetchAll() as $v) {
        $itemVotes[$v['agenda_item_id']][$v['unit_id']] = $v['vote'];
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-hd">
  <div>
    <h1><?= e($meeting['title']) ?></h1>
    <div style="font-size:13px;color:var(--muted);margin-top:2px">
      <?= date('j. n. Y', strtotime($meeting['meeting_date'])) ?>
      <?= $meeting['meeting_time'] ? ' v '.substr($meeting['meeting_time'],0,5) : '' ?>
      <?= $meeting['location'] ? ' · '.e($meeting['location']) : '' ?>
    </div>
  </div>
  <div style="display:flex;gap:8px">
    <a class="btn btn-primary btn-sm" href="/admin/meeting_print.php?id=<?= $id ?>" target="_blank">🖨 Zápis / PDF</a>
    <a class="btn btn-secondary btn-sm" href="/admin/export_prezence.php?meeting_id=<?= $id ?>">📋 Prezenčka</a>
    <a class="btn btn-secondary btn-sm" href="/admin/export_prezence.php?meeting_id=<?= $id ?>&only_present=1">📋 Přítomní</a>
    <a class="btn btn-secondary" href="/admin/meetings.php">← Zpět</a>
  </div>
</div>

<!-- USNÁŠENÍSCHOPNOST -->
<div style="background:<?= $quorumOk ? '#EAF3DE' : '#FAEEDA' ?>;border:1px solid <?= $quorumOk ? '#b5d97a' : '#FAC775' ?>;border-radius:var(--radius);padding:1rem 1.25rem;margin-bottom:1.25rem">
  <div style="display:flex;align-items:center;gap:1.5rem;flex-wrap:wrap">
    <div style="text-align:center">
      <div style="font-size:28px;font-weight:700;color:<?= $quorumOk ? 'var(--green)' : 'var(--amber)' ?>"><?= number_format($presentPct,2,',','') ?> %</div>
      <div style="font-size:12px;color:var(--muted)">přítomných podílů</div>
    </div>
    <div style="text-align:center">
      <div style="font-size:28px;font-weight:700"><?= $presentCount ?></div>
      <div style="font-size:12px;color:var(--muted)">přítomných jednotek</div>
    </div>
    <div style="flex:1;min-width:200px">
      <div style="background:rgba(0,0,0,.08);border-radius:99px;height:14px;overflow:hidden">
        <div style="height:100%;background:<?= $quorumOk ? '#3B6D11' : '#854F0B' ?>;border-radius:99px;width:<?= min(round($presentPct/$meeting['quorum_pct']*100),100) ?>%"></div>
      </div>
      <div style="font-size:13px;font-weight:600;margin-top:6px;color:<?= $quorumOk ? 'var(--green)' : 'var(--amber)' ?>">
        <?= $quorumOk ? '✓ Usnášeníschopné' : '⚠ Není usnášeníschopné' ?>
        &nbsp;·&nbsp; Kvórum: <?= $meeting['quorum_pct'] ?> %
      </div>
    </div>
  </div>

  <?php if ($quorumOk): ?>
  <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid rgba(0,0,0,.1)">
    <div style="font-size:11px;font-weight:600;color:var(--muted);margin-bottom:8px;text-transform:uppercase;letter-spacing:.05em">Hranice pro hlasování (PRO musí přesáhnout):</div>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <div style="background:rgba(255,255,255,0.7);border-radius:6px;padding:7px 12px;border-left:3px solid var(--green)">
        <div style="font-size:11px;color:var(--green);font-weight:600">Nadpoloviční z přítomných</div>
        <div style="font-size:17px;font-weight:700">> <?= number_format($maj50pres,2,',','') ?> %</div>
      </div>
      <div style="background:rgba(255,255,255,0.7);border-radius:6px;padding:7px 12px;border-left:3px solid var(--green)">
        <div style="font-size:11px;color:var(--green);font-weight:600">Kvalifikovaná z přítomných (75 %)</div>
        <div style="font-size:17px;font-weight:700">> <?= number_format($maj75pres,2,',','') ?> %</div>
      </div>
      <div style="background:rgba(255,255,255,0.7);border-radius:6px;padding:7px 12px;border-left:3px solid var(--blue)">
        <div style="font-size:11px;color:var(--blue);font-weight:600">Absolutní nadpoloviční ze všech</div>
        <div style="font-size:17px;font-weight:700">> <?= number_format($maj50tot,2,',','') ?> %</div>
      </div>
      <div style="background:rgba(255,255,255,0.7);border-radius:6px;padding:7px 12px;border-left:3px solid var(--blue)">
        <div style="font-size:11px;color:var(--blue);font-weight:600">Kvalifikovaná ze všech (75 %)</div>
        <div style="font-size:17px;font-weight:700">> <?= number_format($maj75tot,2,',','') ?> %</div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.25rem">

<!-- PREZENČNÍ LISTINA -->
<div class="card">
  <div class="card-title">📋 Prezenční listina</div>
  <form method="POST" style="margin-bottom:1rem;padding-bottom:1rem;border-bottom:1px solid var(--border)">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="action" value="attend">
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
      <div style="flex:1;min-width:140px">
        <label style="font-size:12px;color:var(--muted);display:block;margin-bottom:3px">Vlastník</label>
        <select name="owner_id" style="width:100%;font-size:13px;padding:6px 8px;border:1px solid var(--border);border-radius:var(--radius-sm)" onchange="setUnit(this)">
          <option value="">— vyberte —</option>
          <?php foreach ($allUnits as $u): if (!$u['owner_id']) continue; ?>
            <option value="<?= $u['owner_id'] ?>" data-unit="<?= $u['unit_id'] ?>" <?= in_array($u['unit_id'], $presentUnitIds) ? 'disabled style="color:#aaa"' : '' ?>>
              <?= e($u['label']) ?> – <?= e($u['full_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <input type="hidden" name="unit_id" id="unit-id-hidden">
      <div>
        <label style="font-size:12px;color:var(--muted);display:block;margin-bottom:3px">Účast</label>
        <select name="attend_type" style="font-size:13px;padding:6px 8px;border:1px solid var(--border);border-radius:var(--radius-sm)" onchange="toggleProxy(this)">
          <option value="osobně">Osobně</option>
          <option value="plná moc">Plná moc</option>
          <option value="online">Online</option>
        </select>
      </div>
      <div id="proxy-wrap" style="display:none;flex:1;min-width:100px">
        <label style="font-size:12px;color:var(--muted);display:block;margin-bottom:3px">Zástupce</label>
        <input type="text" name="proxy_name" style="font-size:13px;padding:6px 8px;border:1px solid var(--border);border-radius:var(--radius-sm);width:100%">
      </div>
      <button type="submit" class="btn btn-primary btn-sm">+ Přidat</button>
    </div>
  </form>
  <?php if ($attendance): ?>
  <table class="tbl">
    <thead><tr><th>Jednotka</th><th>Vlastník</th><th>%</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($attendance as $a): ?>
    <tr>
      <td><strong><?= e($a['label']) ?></strong></td>
      <td style="font-size:13px"><?= e($a['full_name']) ?><?php if ($a['proxy_name']): ?><br><small style="color:var(--muted)"><?= e($a['proxy_name']) ?></small><?php endif; ?></td>
      <td style="font-weight:600"><?= $a['share_pct'] ?></td>
      <td>
        <form method="POST" style="display:inline" onsubmit="return confirm('Odebrat?')">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="action" value="remove_attend">
          <input type="hidden" name="unit_id" value="<?= $a['unit_id'] ?>">
          <button type="submit" class="btn btn-danger btn-sm">✕</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    <tr style="background:var(--blue-lt)">
      <td colspan="2" style="font-weight:600;color:var(--blue)">Celkem přítomno</td>
      <td style="font-weight:700;color:var(--blue)"><?= number_format($presentPct,4,',','') ?> %</td>
      <td></td>
    </tr>
    </tbody>
  </table>
  <?php else: ?><p style="color:var(--muted);font-size:14px">Zatím nikdo nepřihlášen.</p><?php endif; ?>
</div>

<!-- NEPŘÍTOMNÍ -->
<div class="card">
  <div class="card-title">❌ Nepřítomní (<?= count($allUnits) - $presentCount ?>)</div>
  <div style="max-height:380px;overflow-y:auto">
  <table class="tbl">
    <thead><tr><th>Jednotka</th><th>Vlastník</th><th style="text-align:right">%</th></tr></thead>
    <tbody>
    <?php foreach ($allUnits as $u): if (in_array($u['unit_id'], $presentUnitIds)) continue; ?>
    <tr>
      <td><strong><?= e($u['label']) ?></strong></td>
      <td style="font-size:13px;color:var(--muted)"><?= e($u['full_name'] ?: '—') ?></td>
      <td style="font-size:13px;text-align:right"><?= $u['share_pct'] ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
</div>

<!-- BODY PROGRAMU -->
<div class="card" id="agenda">
  <div class="card-title">🗳️ Program a hlasování</div>

  <!-- Přidat bod -->
  <form method="POST" style="background:var(--gray-lt);border-radius:var(--radius-sm);padding:1rem;margin-bottom:1.5rem">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="action" value="add_agenda">
    <div style="display:grid;grid-template-columns:1fr auto;gap:8px;margin-bottom:8px">
      <div>
        <label style="font-size:12px;color:var(--muted);display:block;margin-bottom:3px">Název bodu *</label>
        <input type="text" name="item_title" required style="width:100%;font-size:13px;padding:6px 8px;border:1px solid var(--border);border-radius:var(--radius-sm)">
      </div>
      <div>
        <label style="font-size:12px;color:var(--muted);display:block;margin-bottom:3px">Hlasování</label>
        <select name="vote_type" style="font-size:13px;padding:6px 8px;border:1px solid var(--border);border-radius:var(--radius-sm)">
          <option value="pro/proti/zdržel se">Pro / Proti / Zdržel se</option>
          <option value="ano/ne">Ano / Ne</option>
          <option value="žádné">Bez hlasování</option>
        </select>
      </div>
    </div>
    <div style="margin-bottom:8px">
      <label style="font-size:12px;color:var(--muted);display:block;margin-bottom:3px">Obecný popis</label>
      <textarea name="general_description" style="width:100%;font-size:13px;padding:6px 8px;border:1px solid var(--border);border-radius:var(--radius-sm);min-height:60px;resize:vertical" placeholder="Podrobný popis projednávaného bodu..."></textarea>
    </div>
    <div style="margin-bottom:8px">
      <label style="font-size:12px;color:var(--muted);display:block;margin-bottom:3px">Návrh usnesení</label>
      <textarea name="resolution_proposal" style="width:100%;font-size:13px;padding:6px 8px;border:1px solid var(--border);border-radius:var(--radius-sm);min-height:60px;resize:vertical" placeholder="Shromáždění schvaluje..."></textarea>
    </div>
    <button type="submit" class="btn btn-primary btn-sm">+ Přidat bod</button>
  </form>

  <?php foreach ($agendaItems as $item): ?>
  <?php
    $votes = $itemVotes[$item['id']] ?? [];
    $proCount = 0; $protiCount = 0; $zdrzCount = 0;
    $proPct = 0; $protiPct = 0; $zdrzPct = 0;
    // Sestavíme přítomné s hlasem
    $attendanceMap = [];
    foreach ($attendance as $a) $attendanceMap[$a['unit_id']] = $a;
    foreach ($votes as $uid => $v) {
      $pct = $attendanceMap[$uid]['share_pct'] ?? 0;
      if ($v === 'pro')    { $proCount++;   $proPct   += $pct; }
      if ($v === 'proti')  { $protiCount++; $protiPct += $pct; }
      if ($v === 'zdrzelo'){ $zdrzCount++;  $zdrzPct  += $pct; }
    }
    $nezhlasovalo = $presentCount - count($votes);
  ?>
  <div style="border:1px solid var(--border);border-radius:var(--radius-sm);margin-bottom:1.25rem;overflow:hidden">
    <div style="background:var(--gray-lt);padding:.75rem 1rem;display:flex;align-items:center;gap:8px">
      <span style="font-size:13px;font-weight:700;color:var(--muted);min-width:20px"><?= $item['order_num'] ?>.</span>
      <div style="flex:1">
        <div style="font-weight:600;font-size:15px"><?= e($item['title']) ?></div>
        <?php if ($item['general_description'] ?? ''): ?>
          <div style="font-size:13px;color:var(--text);margin-top:6px;padding:6px 10px;background:#fff;border-radius:4px;border-left:3px solid var(--border)">
            <?= nl2br(e($item['general_description'])) ?>
          </div>
        <?php endif; ?>
        <?php if ($item['resolution_proposal'] ?? ''): ?>
          <div style="font-size:13px;margin-top:6px;padding:6px 10px;background:#EAF3DE;border-radius:4px;border-left:3px solid var(--green)">
            <span style="font-size:10px;font-weight:700;color:var(--green);text-transform:uppercase;letter-spacing:.05em">Návrh usnesení: </span>
            <strong><?= nl2br(e($item['resolution_proposal'])) ?></strong>
          </div>
        <?php endif; ?>
      </div>
      <span class="badge badge-blue" style="align-self:flex-start"><?= e($item['vote_type']) ?></span>
      <button type="button" class="btn btn-secondary btn-sm" style="align-self:flex-start" onclick="toggleEditItem(<?= $item['id'] ?>)">✏</button>
      <form method="POST" style="display:inline" onsubmit="return confirm('Smazat bod?')">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="delete_item">
        <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
        <button type="submit" class="btn btn-danger btn-sm">✕</button>
      </form>
    </div>
    <!-- Inline edit formulář -->
    <div id="edit-item-<?= $item['id'] ?>" style="display:none;padding:1rem;background:#fff;border-top:1px solid var(--border)">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="edit_item">
        <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
        <div style="display:grid;grid-template-columns:1fr auto;gap:8px;margin-bottom:8px">
          <div>
            <label style="font-size:11px;color:var(--muted);display:block;margin-bottom:3px">Název bodu *</label>
            <input type="text" name="item_title" required value="<?= e($item['title']) ?>" style="width:100%;font-size:13px;padding:6px 8px;border:1px solid var(--border);border-radius:var(--radius-sm)">
          </div>
          <div>
            <label style="font-size:11px;color:var(--muted);display:block;margin-bottom:3px">Hlasování</label>
            <select name="vote_type" style="font-size:13px;padding:6px 8px;border:1px solid var(--border);border-radius:var(--radius-sm)">
              <option value="pro/proti/zdržel se" <?= $item['vote_type']==='pro/proti/zdržel se'?'selected':'' ?>>Pro / Proti / Zdržel se</option>
              <option value="ano/ne" <?= $item['vote_type']==='ano/ne'?'selected':'' ?>>Ano / Ne</option>
              <option value="žádné" <?= $item['vote_type']==='žádné'?'selected':'' ?>>Bez hlasování</option>
            </select>
          </div>
        </div>
        <div style="margin-bottom:8px">
          <label style="font-size:11px;color:var(--muted);display:block;margin-bottom:3px">Obecný popis</label>
          <textarea name="general_description" style="width:100%;font-size:13px;padding:6px 8px;border:1px solid var(--border);border-radius:var(--radius-sm);min-height:60px;resize:vertical"><?= e($item['general_description'] ?? '') ?></textarea>
        </div>
        <div style="margin-bottom:8px">
          <label style="font-size:11px;color:var(--muted);display:block;margin-bottom:3px">Návrh usnesení</label>
          <textarea name="resolution_proposal" style="width:100%;font-size:13px;padding:6px 8px;border:1px solid var(--border);border-radius:var(--radius-sm);min-height:60px;resize:vertical"><?= e($item['resolution_proposal'] ?? '') ?></textarea>
        </div>
        <div style="display:flex;gap:8px">
          <button type="submit" class="btn btn-primary btn-sm">Uložit</button>
          <button type="button" class="btn btn-secondary btn-sm" onclick="toggleEditItem(<?= $item['id'] ?>)">Zrušit</button>
        </div>
      </form>
    </div>

    <?php if ($item['vote_type'] !== 'žádné' && $attendance): ?>
    <div style="padding:1rem">

      <!-- Výsledky pokud existují -->
      <?php if (count($votes) > 0): ?>
      <div style="display:flex;gap:1rem;margin-bottom:1rem;flex-wrap:wrap">
        <div style="background:var(--green-lt);border-radius:var(--radius-sm);padding:.6rem 1rem;min-width:120px;text-align:center">
          <div style="font-size:20px;font-weight:700;color:var(--green)"><?= number_format($proPct,2,',','') ?> %</div>
          <div style="font-size:12px;color:var(--green)">✓ PRO (<?= $proCount ?>×)</div>
        </div>
        <div style="background:var(--red-lt);border-radius:var(--radius-sm);padding:.6rem 1rem;min-width:120px;text-align:center">
          <div style="font-size:20px;font-weight:700;color:var(--red)"><?= number_format($protiPct,2,',','') ?> %</div>
          <div style="font-size:12px;color:var(--red)">✗ PROTI (<?= $protiCount ?>×)</div>
        </div>
        <div style="background:var(--gray-lt);border-radius:var(--radius-sm);padding:.6rem 1rem;min-width:120px;text-align:center">
          <div style="font-size:20px;font-weight:700;color:var(--muted)"><?= number_format($zdrzPct,2,',','') ?> %</div>
          <div style="font-size:12px;color:var(--muted)">— ZDRŽEL (<?= $zdrzCount ?>×)</div>
        </div>
        <?php if ($nezhlasovalo > 0): ?>
        <div style="background:#fff3cd;border-radius:var(--radius-sm);padding:.6rem 1rem;min-width:120px;text-align:center">
          <div style="font-size:20px;font-weight:700;color:#856404"><?= $nezhlasovalo ?></div>
          <div style="font-size:12px;color:#856404">⚠ Nehlasovalo</div>
        </div>
        <?php endif; ?>

        <!-- Indikátory většin -->
        <div style="flex:1;min-width:200px">
          <?php
            $m50p = $proPct > $maj50pres;
            $m75p = $proPct >= $maj75pres;
            $m50t = $proPct > $maj50tot;
            $m75t = $proPct >= $maj75tot;
          ?>
          <div style="font-size:11px;display:flex;gap:6px;flex-wrap:wrap">
            <span style="background:<?= $m50p?'var(--green-lt)':'var(--gray-lt)' ?>;color:<?= $m50p?'var(--green)':'var(--muted)' ?>;padding:2px 8px;border-radius:99px;font-weight:600">
              <?= $m50p?'✓':'✗' ?> Nadpolov. z přítomných
            </span>
            <span style="background:<?= $m50t?'var(--blue-lt)':'var(--gray-lt)' ?>;color:<?= $m50t?'var(--blue)':'var(--muted)' ?>;padding:2px 8px;border-radius:99px;font-weight:600">
              <?= $m50t?'✓':'✗' ?> Nadpolov. z celku
            </span>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Hlasovací formulář — seznam přítomných -->
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="save_votes">
        <input type="hidden" name="item_id" value="<?= $item['id'] ?>">

        <div style="border:1px solid var(--border);border-radius:var(--radius-sm);overflow:hidden;margin-bottom:1rem">
          <table style="width:100%;border-collapse:collapse;font-size:13px">
            <thead>
              <tr style="background:var(--gray-lt)">
                <th style="padding:7px 10px;text-align:left;font-size:11px;color:var(--muted);font-weight:600">Jednotka</th>
                <th style="padding:7px 10px;text-align:left;font-size:11px;color:var(--muted);font-weight:600">Vlastník</th>
                <th style="padding:7px 10px;text-align:right;font-size:11px;color:var(--muted);font-weight:600">%</th>
                <th style="padding:7px 10px;text-align:center;font-size:11px;color:var(--green);font-weight:600">✓ PRO</th>
                <th style="padding:7px 10px;text-align:center;font-size:11px;color:var(--red);font-weight:600">✗ PROTI</th>
                <th style="padding:7px 10px;text-align:center;font-size:11px;color:var(--muted);font-weight:600">— ZDRŽEL</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($attendance as $a):
              $currentVote = $votes[$a['unit_id']] ?? '';
            ?>
            <tr style="border-top:1px solid var(--border);background:<?= $currentVote==='pro'?'#f0fff4':($currentVote==='proti'?'#fff0f0':($currentVote==='zdrzelo'?'#f8f8f8':'#fff')) ?>">
              <td style="padding:7px 10px"><strong><?= e($a['label']) ?></strong></td>
              <td style="padding:7px 10px;color:var(--muted)"><?= e($a['full_name']) ?><?php if ($a['proxy_name']): ?><br><small><?= e($a['proxy_name']) ?></small><?php endif; ?></td>
              <td style="padding:7px 10px;text-align:right;font-weight:600"><?= $a['share_pct'] ?></td>
              <td style="padding:7px 10px;text-align:center">
                <input type="radio" name="votes[<?= $a['unit_id'] ?>]" value="pro" <?= $currentVote==='pro'?'checked':'' ?> style="accent-color:var(--green);width:18px;height:18px;cursor:pointer">
              </td>
              <td style="padding:7px 10px;text-align:center">
                <input type="radio" name="votes[<?= $a['unit_id'] ?>]" value="proti" <?= $currentVote==='proti'?'checked':'' ?> style="accent-color:var(--red);width:18px;height:18px;cursor:pointer">
              </td>
              <td style="padding:7px 10px;text-align:center">
                <input type="radio" name="votes[<?= $a['unit_id'] ?>]" value="zdrzelo" <?= $currentVote==='zdrzelo'?'checked':'' ?> style="accent-color:var(--muted);width:18px;height:18px;cursor:pointer">
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <!-- Výsledek a uložení -->
        <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
          <div>
            <label style="font-size:12px;color:var(--muted);font-weight:600;display:block;margin-bottom:3px">Výsledek bodu</label>
            <select name="result" style="font-size:13px;padding:6px 10px;border:1px solid var(--border);border-radius:var(--radius-sm)">
              <option value="schváleno" <?= ($item['result']??'')==='schváleno'?'selected':'' ?>>✓ Schváleno</option>
              <option value="neschváleno" <?= ($item['result']??'')==='neschváleno'?'selected':'' ?>>✗ Neschváleno</option>
              <option value="odloženo" <?= ($item['result']??'')==='odloženo'?'selected':'' ?>>— Odloženo</option>
            </select>
          </div>
          <div style="flex:1;min-width:180px">
            <label style="font-size:12px;color:var(--muted);font-weight:600;display:block;margin-bottom:3px">Poznámka</label>
            <input type="text" name="vote_note" value="<?= e($item['vote_note'] ?? '') ?>" style="width:100%;font-size:13px;padding:6px 10px;border:1px solid var(--border);border-radius:var(--radius-sm)">
          </div>
          <button type="submit" class="btn btn-primary">💾 Uložit hlasování</button>
        </div>
      </form>
    </div>
    <?php elseif ($item['vote_type'] !== 'žádné'): ?>
    <div style="padding:1rem;color:var(--muted);font-size:14px">⚠ Nejprve přidejte přítomné do prezenční listiny.</div>
    <?php else: ?>
    <div style="padding:.75rem 1rem;color:var(--muted);font-size:13px">Informační bod — bez hlasování.</div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<script>
function toggleEditItem(id) {
  const el = document.getElementById('edit-item-' + id);
  el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
function setUnit(sel) {
    document.getElementById('unit-id-hidden').value = sel.options[sel.selectedIndex].dataset.unit || '';
}
function toggleProxy(sel) {
    document.getElementById('proxy-wrap').style.display = sel.value === 'plná moc' ? 'block' : 'none';
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
