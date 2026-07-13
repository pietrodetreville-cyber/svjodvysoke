<?php
require_once __DIR__ . '/../includes/functions.php';
$user = requireAdmin();
$db = db();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: /admin/owners.php'); exit; }

// Načti vlastníka
$stmt = $db->prepare(
    "SELECT o.*, u.label AS unit_label, u.type AS unit_type,
            u.share_numerator, u.share_denominator, u.id AS unit_id,
            CASE WHEN u.share_numerator IS NOT NULL AND u.share_denominator > 0
                 THEN ROUND(u.share_numerator / u.share_denominator * 100, 4)
                 ELSE NULL END AS share_pct
     FROM owners o JOIN units u ON o.unit_id = u.id
     WHERE o.id = ?"
);
$stmt->execute([$id]);
$owner = $stmt->fetch();
if (!$owner) { header('Location: /admin/owners.php'); exit; }

$pageTitle = 'Detail vlastníka – ' . ($owner['unit_label'] ?? '');
$isSuperAdmin = ($user['role'] === 'superadmin');
$isLockedByOwner = ($owner['updated_by_role'] ?? '') === 'owner';
$canEdit = $isSuperAdmin || !$isLockedByOwner;

// Garáže napojené na tento byt
$garaze = $db->prepare(
    "SELECT u2.label, u2.id FROM units u2
     WHERE u2.linked_unit_id = ? AND u2.type != 'byt'"
);
$garaze->execute([$owner['unit_id']]);
$garaze = $garaze->fetchAll();

// Nájemníci v jednotce
$najemnici = $db->prepare(
    "SELECT * FROM tenants WHERE unit_id = ? ORDER BY full_name"
);
$najemnici->execute([$owner['unit_id']]);
$najemnici = $najemnici->fetchAll();

// Další vlastníci (SJM / podílové)
$dalsiVlastnici = $db->prepare("SELECT * FROM owner_persons WHERE owner_id = ? ORDER BY id");
$dalsiVlastnici->execute([$owner['id']]);
$dalsiVlastnici = $dalsiVlastnici->fetchAll();
$ownershipLabels = ['bezpodílové' => 'Jednoduché', 'společné jmění manželů' => 'SJM (manželé)', 'podílové' => 'Podílové', 'neuvedeno' => 'Neuvedeno'];

// Uživatelský účet vlastníka
try {
    $ucet = $db->prepare(
        "SELECT username, created_at FROM users WHERE unit_id = ? AND role = 'owner' LIMIT 1"
    );
    $ucet->execute([$owner['unit_id']]);
    $ucet = $ucet->fetch();
} catch (\PDOException $e) {
    $ucet = null;
}

// ── Spotřeby ──────────────────────────────────────────────────────────────
$cons_roky = [];
$cons_rows = [];

try {
    $q1 = $db->prepare(
        "SELECT DISTINCT rok FROM consumption WHERE unit_id = ? ORDER BY rok DESC"
    );
    $q1->execute([$owner['unit_id']]);
    $cons_roky = $q1->fetchAll(PDO::FETCH_COLUMN);
} catch (\PDOException $e) {
    $cons_roky = [];
}

// Výchozí rok = z URL, nebo nejnovější s daty, nebo aktuální rok
$cons_rok = (int)($_GET['cons_rok'] ?? ($cons_roky[0] ?? (int)date('Y')));

try {
    $q2 = $db->prepare(
        "SELECT rok, mesic, typ, jednotka, hodnota_zacatek, hodnota_konec, spotreba
         FROM consumption WHERE unit_id = ? AND rok = ? ORDER BY mesic ASC, typ ASC"
    );
    $q2->execute([$owner['unit_id'], $cons_rok]);
    $cons_rows = $q2->fetchAll(PDO::FETCH_ASSOC);
} catch (\PDOException $e) {
    $cons_rows = [];
}

$mesice_nazvy = [
    1=>'Leden',2=>'Únor',3=>'Březen',4=>'Duben',5=>'Květen',6=>'Červen',
    7=>'Červenec',8=>'Srpen',9=>'Září',10=>'Říjen',11=>'Listopad',12=>'Prosinec'
];

$pivot = [];
$soucty = ['SV' => 0, 'TV' => 0, 'ITN' => 0];
foreach ($cons_rows as $r) {
    $pivot[$r['mesic']][$r['typ']] = $r;
    $soucty[$r['typ']] += $r['spotreba'];
}

// ── Flash po uložení (redirect sem po POST) ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_note') {
    csrfCheck();
    if ($canEdit) {
        $db->prepare("UPDATE owners SET board_note = ? WHERE id = ?")
           ->execute([trim($_POST['board_note'] ?? ''), $id]);
        flash('Poznámka uložena.', 'success');
    }
    header("Location: /admin/owner_detail.php?id=$id"); exit;
}

include __DIR__ . '/../includes/header.php';

// Helper: status badge
$badge = match($owner['status']) {
    'úplná'   => 'badge-ok',
    'neúplná' => 'badge-partial',
    default   => 'badge-miss',
};
$mainEmail = $owner['email'];
$mainPhone = $owner['phone'];
?>

<div class="page-hd">
  <div>
    <h1>
      <?= e($owner['unit_label']) ?>
      <?php if ($garaze): ?>
        <span style="font-size:14px;color:var(--amber);font-weight:400">
          🚗 <?= implode(', ', array_column($garaze, 'label')) ?>
        </span>
      <?php endif; ?>
    </h1>
    <div style="font-size:13px;color:var(--muted);margin-top:2px;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
      <span class="badge <?= $badge ?>"><?= $owner['status'] ?: 'chybí' ?></span>
      <?php if ($owner['share_pct'] !== null): ?>
        <span>Podíl: <strong><?= $owner['share_pct'] ?> %</strong>
          (<?= $owner['share_numerator'] ?>/<?= $owner['share_denominator'] ?>)</span>
      <?php endif; ?>
      <?php if ($isLockedByOwner && !$isSuperAdmin): ?>
        <span style="background:#E6F1FB;color:#185FA5;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:600">
          👤 Vyplněno vlastníkem — pouze pro čtení
        </span>
      <?php endif; ?>
    </div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php if ($canEdit): ?>
      <a class="btn btn-primary btn-sm" href="/admin/owner_edit.php?id=<?= $id ?>">✏ Upravit kartu</a>
    <?php endif; ?>
    <a class="btn btn-secondary btn-sm" href="/admin/owners.php">← Kartotéka</a>
  </div>
</div>

<?php if ($isSuperAdmin && $isLockedByOwner): ?>
<div style="background:#f0e6fb;border:1px solid #c9a0e0;border-radius:var(--radius-sm);padding:.75rem 1rem;margin-bottom:1rem;font-size:13px;color:#6b11a5">
  🔑 <strong>Karta vyplněna vlastníkem.</strong> Jako superadmin ji můžete upravit — vlastníkovi bude automaticky odeslán e-mail o provedené změně.
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════
     GRID: levý sloupec (karta) + pravý (meta)
     ══════════════════════════════════════════════════════ -->
<div class="od-grid">

<!-- ── LEVÝ SLOUPEC ──────────────────────────────────── -->
<div class="od-main">

  <!-- BLOK: Vlastník -->
  <div class="card" style="border-top:4px solid #185FA5;margin-bottom:1rem">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:1rem">
      <div style="font-size:28px">👤</div>
      <div>
        <div style="font-size:18px;font-weight:700">
          <?= e($owner['full_name'] ?: '—') ?>
          <?php if ($owner['unit_share_pct'] !== null): ?><span style="font-size:13px;font-weight:600;color:var(--blue)"> — <?= e($owner['unit_share_pct']) ?> %</span><?php endif; ?>
        </div>
        <div style="font-size:12px;color:var(--muted)">
          Vlastnictví: <strong><?= e($ownershipLabels[$owner['ownership_form'] ?? 'neuvedeno'] ?? $owner['ownership_form']) ?></strong>
          &nbsp;·&nbsp; Způsob užívání: <strong><?= e($owner['residence'] ?: 'neuvedeno') ?></strong>
          <?php if ($owner['persons_count']): ?>
            &nbsp;·&nbsp; <?= $owner['persons_count'] ?> os. v jednotce
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php if ($dalsiVlastnici): ?>
    <div style="margin-bottom:1rem;padding-bottom:.75rem;border-bottom:1px solid var(--border)">
      <div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:6px">Další vlastníci</div>
      <?php foreach ($dalsiVlastnici as $p): ?>
        <div style="font-size:13px;margin-bottom:2px">
          👥 <?= e($p['full_name']) ?><?= $p['relation'] ? ' — '.e($p['relation']) : '' ?>
          <?php if ($p['unit_share_pct'] !== null): ?><strong style="color:var(--blue)"> <?= e($p['unit_share_pct']) ?> %</strong><?php endif; ?>
          <?php if ($p['email'] || $p['phone']): ?>
            <span style="color:var(--muted)">(<?= e($p['email'] ?: '') ?><?= $p['email'] && $p['phone'] ? ', ' : '' ?><?= e($p['phone'] ?: '') ?>)</span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Kontakty -->
    <div class="od-contacts">
      <!-- E-mail -->
      <div>
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:6px">E-mail</div>
        <?php if ($owner['email']): ?>
          <div style="font-size:13px">
            ✉️ <a href="mailto:<?= e($owner['email']) ?>"><?= e($owner['email']) ?></a>
            <?php if (!empty($owner['email_verified'])): ?><span style="font-size:10px;color:var(--green)">ověřeno</span><?php endif; ?>
            <?php if (empty($owner['notify_email'])): ?><span style="font-size:10px;color:var(--muted)">(nezasílat info)</span><?php endif; ?>
          </div>
        <?php else: ?>
          <span style="color:var(--muted);font-size:13px">—</span>
        <?php endif; ?>
      </div>

      <!-- Telefon -->
      <div>
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:6px">Telefon</div>
        <?php if ($owner['phone']): ?>
          <div style="font-size:13px">
            📞 <?= e($owner['phone']) ?>
            <?php if (!empty($owner['whatsapp'])): ?><span style="font-size:10px;color:var(--green)">💬 WhatsApp</span><?php endif; ?>
          </div>
        <?php else: ?>
          <span style="color:var(--muted);font-size:13px">—</span>
        <?php endif; ?>
      </div>
    </div>

    <!-- Adresa -->
    <?php if ($owner['address']): ?>
    <div style="font-size:13px;color:var(--muted);padding:.5rem .75rem;background:var(--gray-lt);border-radius:var(--radius-sm);margin-bottom:.75rem">
      📬 Korespondenční adresa: <strong style="color:var(--text)"><?= e($owner['address']) ?></strong>
    </div>
    <?php endif; ?>

    <!-- GDPR -->
    <div style="font-size:12px;color:<?= $owner['gdpr_consent'] ? 'var(--green)' : 'var(--amber)' ?>">
      <?= $owner['gdpr_consent']
        ? '✓ GDPR souhlas udělen' . ($owner['gdpr_date'] ? ' — ' . date('j. n. Y', strtotime($owner['gdpr_date'])) : '')
        : '⚠ GDPR souhlas nebyl udělen' ?>
    </div>
  </div>

  <!-- BLOK: Poznámka výboru -->
  <div class="card" style="border-top:4px solid var(--muted);margin-bottom:1rem">
    <div style="font-size:13px;font-weight:600;color:var(--muted);margin-bottom:.75rem">📋 Interní poznámka výboru</div>
    <?php if ($canEdit): ?>
    <form method="POST" style="margin:0">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="save_note">
      <textarea name="board_note" style="width:100%;min-height:80px;font-size:13px;padding:8px 10px;border:1px solid var(--border);border-radius:var(--radius-sm);resize:vertical;font-family:inherit"
                placeholder="Interní poznámky pro výbor (nezobrazí se vlastníkovi)..."><?= e($owner['board_note'] ?? '') ?></textarea>
      <button type="submit" class="btn btn-secondary btn-sm" style="margin-top:6px">Uložit poznámku</button>
    </form>
    <?php else: ?>
      <p style="font-size:13px;color:var(--muted)"><?= $owner['board_note'] ? nl2br(e($owner['board_note'])) : '—' ?></p>
    <?php endif; ?>
  </div>

  <!-- BLOK: Uživatelé jednotky -->
  <?php if ($najemnici): ?>
  <div class="card" style="border-top:4px solid #3B6D11;margin-bottom:1rem">
    <div style="font-size:13px;font-weight:600;color:#3B6D11;margin-bottom:.75rem">🏠 Uživatelé jednotky</div>
    <?php foreach ($najemnici as $t):
      $isActive  = !$t['rent_until'] || strtotime($t['rent_until']) >= time();
      $isExpiring= $t['rent_until'] && strtotime($t['rent_until']) < strtotime('+30 days') && $isActive;
    ?>
    <div style="display:flex;align-items:center;gap:10px;padding:.6rem 0;border-bottom:1px solid var(--border)">
      <div style="flex:1">
        <div style="font-weight:500">
          <?= e($t['full_name']) ?>
          <?php if (($t['typ'] ?? 'najem') === 'vecne_bremeno'): ?>
            <span class="badge badge-partial" style="margin-left:4px">Věcné břemeno</span>
          <?php endif; ?>
        </div>
        <div style="font-size:12px;color:var(--muted)">
          <?= $t['email'] ? '<a href="mailto:'.e($t['email']).'">'.e($t['email']).'</a>' : '' ?>
          <?= $t['phone'] ? ($t['email']?' · ':'').'📞 '.e($t['phone']) : '' ?>
          <?php if ($t['rent_from'] || $t['rent_until']): ?>
            <br>Nájem:
            <?= $t['rent_from'] ? 'od '.date('j. n. Y', strtotime($t['rent_from'])) : '' ?>
            <?= $t['rent_until'] ? ' do '.date('j. n. Y', strtotime($t['rent_until'])) : '' ?>
            <?php if ($isExpiring): ?>
              <span style="color:var(--amber)">(končí brzy)</span>
            <?php elseif (!$isActive): ?>
              <span style="color:var(--red)">(prošlý)</span>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
      <span class="badge <?= $isActive ? 'badge-ok' : 'badge-miss' ?>"><?= $isActive ? 'Aktivní' : 'Prošlý' ?></span>
      <a class="btn btn-secondary btn-sm" href="/admin/tenants.php?edit=<?= $t['id'] ?>">Upravit</a>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- ══ SPOTŘEBY ═══════════════════════════════════════ -->
  <div class="card" style="border-top:4px solid var(--blue);margin-bottom:1rem">

    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:1rem">
      <div style="font-size:14px;font-weight:600">📊 Spotřeby
        <span style="font-weight:400;color:var(--muted)"><?= $cons_rok ?></span>
      </div>
      <?php if (count($cons_roky) > 1): ?>
      <div style="display:flex;gap:6px;flex-wrap:wrap">
        <?php foreach (array_reverse($cons_roky) as $r): ?>
        <a href="?id=<?= $id ?>&cons_rok=<?= $r ?>"
           class="btn btn-sm <?= $r == $cons_rok ? 'btn-primary' : 'btn-secondary' ?>">
          <?= $r ?>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <?php if (!$pivot): ?>
      <p style="color:var(--muted);font-size:13px">
        Pro tuto jednotku nejsou evidovány žádné spotřeby za rok <?= $cons_rok ?>.
        <?php if (!$cons_roky): ?>
          <br><span style="font-size:12px">Import dat z CSV: spusťte <code>import_consumption.php</code>.</span>
        <?php endif; ?>
      </p>
    <?php else: ?>

    <!-- Souhrnné boxy -->
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:1rem">
      <div style="background:var(--gray-lt);border-radius:8px;padding:10px 16px;min-width:120px;flex:1">
        <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em">🚿 Studená voda</div>
        <div style="font-size:1.4rem;font-weight:700;color:var(--blue)"><?= number_format($soucty['SV'], 3, ',', ' ') ?>
          <span style="font-size:12px;font-weight:400">m³</span>
        </div>
      </div>
      <div style="background:var(--gray-lt);border-radius:8px;padding:10px 16px;min-width:120px;flex:1">
        <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em">🌡️ Teplá voda</div>
        <div style="font-size:1.4rem;font-weight:700;color:var(--red)"><?= number_format($soucty['TV'], 3, ',', ' ') ?>
          <span style="font-size:12px;font-weight:400">m³</span>
        </div>
      </div>
      <div style="background:var(--gray-lt);border-radius:8px;padding:10px 16px;min-width:120px;flex:1">
        <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em">🔥 Teplo</div>
        <div style="font-size:1.4rem;font-weight:700;color:var(--amber)"><?= number_format($soucty['ITN'], 0, ',', ' ') ?>
          <span style="font-size:12px;font-weight:400">dílků</span>
        </div>
      </div>
    </div>

    <!-- Měsíční tabulka -->
    <div style="overflow-x:auto">
    <table class="tbl" style="font-size:12px">
      <thead>
        <tr>
          <th style="min-width:80px">Měsíc</th>
          <th colspan="2" style="text-align:center;color:var(--blue)">🚿 St. voda (m³)</th>
          <th colspan="2" style="text-align:center;color:var(--red)">🌡️ Teplá voda (m³)</th>
          <th colspan="2" style="text-align:center;color:var(--amber)">🔥 Teplo (dílků)</th>
        </tr>
        <tr style="font-size:11px;color:var(--muted)">
          <th></th>
          <th style="text-align:right">Spotřeba</th>
          <th class="od-stav" style="text-align:right;color:var(--muted)">Stav</th>
          <th style="text-align:right">Spotřeba</th>
          <th class="od-stav" style="text-align:right;color:var(--muted)">Stav</th>
          <th style="text-align:right">Spotřeba</th>
          <th class="od-stav" style="text-align:right;color:var(--muted)">Stav</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($mesice_nazvy as $m => $nazev):
          if (!isset($pivot[$m])) continue;
          $sv  = $pivot[$m]['SV']  ?? null;
          $tv  = $pivot[$m]['TV']  ?? null;
          $itn = $pivot[$m]['ITN'] ?? null;
      ?>
      <tr>
        <td style="font-weight:500"><?= $nazev ?></td>
        <td style="text-align:right;font-weight:600;color:var(--blue)">
          <?= $sv ? number_format($sv['spotreba'], 3, ',', ' ') : '–' ?>
        </td>
        <td class="od-stav" style="text-align:right;font-size:11px;color:var(--muted)">
          <?= $sv ? number_format($sv['hodnota_konec'], 3, ',', ' ') : '–' ?>
        </td>
        <td style="text-align:right;font-weight:600;color:var(--red)">
          <?= $tv ? number_format($tv['spotreba'], 3, ',', ' ') : '–' ?>
        </td>
        <td class="od-stav" style="text-align:right;font-size:11px;color:var(--muted)">
          <?= $tv ? number_format($tv['hodnota_konec'], 3, ',', ' ') : '–' ?>
        </td>
        <td style="text-align:right;font-weight:600;color:var(--amber)">
          <?= $itn ? number_format($itn['spotreba'], 0, ',', ' ') : '–' ?>
        </td>
        <td class="od-stav" style="text-align:right;font-size:11px;color:var(--muted)">
          <?= $itn ? number_format($itn['hodnota_konec'], 0, ',', ' ') : '–' ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="font-weight:700;background:var(--gray-lt)">
          <td>Celkem</td>
          <td style="text-align:right;color:var(--blue)"><?= number_format($soucty['SV'], 3, ',', ' ') ?></td>
          <td class="od-stav"></td>
          <td style="text-align:right;color:var(--red)"><?= number_format($soucty['TV'], 3, ',', ' ') ?></td>
          <td class="od-stav"></td>
          <td style="text-align:right;color:var(--amber)"><?= number_format($soucty['ITN'], 0, ',', ' ') ?></td>
          <td class="od-stav"></td>
        </tr>
      </tfoot>
    </table>
    </div>
    <?php endif; ?>
  </div>

</div><!-- /levý sloupec -->

<!-- ── PRAVÝ SLOUPEC ─────────────────────────────────── -->
<div class="od-side">

  <!-- Meta karta -->
  <div class="card" style="margin-bottom:1rem">
    <div style="font-size:13px;font-weight:600;color:var(--muted);margin-bottom:.75rem">Jednotka</div>
    <div style="font-size:13px;line-height:1.9">
      <div><span style="color:var(--muted)">Typ:</span> <strong><?= e($owner['unit_type']) ?></strong></div>
      <?php if ($owner['share_pct'] !== null): ?>
      <div><span style="color:var(--muted)">Podíl:</span>
        <strong><?= $owner['share_pct'] ?> %</strong>
        <span style="color:var(--muted);font-size:11px">(<?= $owner['share_numerator'] ?>/<?= $owner['share_denominator'] ?>)</span>
      </div>
      <?php endif; ?>
      <?php if ($garaze): ?>
      <div><span style="color:var(--muted)">Garáže:</span>
        <span style="color:var(--amber)">🚗 <?= implode(', ', array_column($garaze, 'label')) ?></span>
      </div>
      <?php endif; ?>
      <?php if ($najemnici): ?>
      <div><span style="color:var(--muted)">Nájemníků:</span>
        <span style="color:var(--green)"><?= count($najemnici) ?></span>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Účet vlastníka -->
  <div class="card" style="margin-bottom:1rem">
    <div style="font-size:13px;font-weight:600;color:var(--muted);margin-bottom:.75rem">🔑 Přihlašovací účet</div>
    <?php if ($ucet): ?>
      <div style="font-size:13px;line-height:1.9">
        <div><span style="color:var(--muted)">Uživatel:</span> <strong><?= e($ucet['username']) ?></strong></div>
        <div><span style="color:var(--muted)">Vytvořen:</span> <?= date('j. n. Y', strtotime($ucet['created_at'])) ?></div>

      </div>
      <div style="margin-top:.75rem">
        <a class="btn btn-secondary btn-sm" href="/admin/users.php">Správa účtů</a>
      </div>
    <?php else: ?>
      <p style="font-size:13px;color:var(--muted)">Vlastník nemá účet na portálu.</p>
      <a class="btn btn-primary btn-sm" href="/admin/users.php" style="margin-top:.5rem;display:inline-block">Vytvořit účet →</a>
    <?php endif; ?>
  </div>

  <!-- Stav karty -->
  <div class="card" style="margin-bottom:1rem">
    <div style="font-size:13px;font-weight:600;color:var(--muted);margin-bottom:.75rem">Stav karty</div>
    <div style="margin-bottom:.5rem">
      <span class="badge <?= $badge ?>" style="font-size:13px"><?= $owner['status'] ?: 'chybí' ?></span>
    </div>
    <?php if ($owner['updated_at']): ?>
    <div style="font-size:12px;color:var(--muted);margin-bottom:.5rem">
      Naposledy upraveno:<br>
      <strong><?= date('j. n. Y H:i', strtotime($owner['updated_at'])) ?></strong>
    </div>
    <?php endif; ?>
    <?php if ($owner['updated_by_role']): ?>
    <div style="font-size:12px">
      Upravil:
      <?php echo match($owner['updated_by_role']) {
        'owner'      => '<span class="badge" style="background:#E6F1FB;color:#185FA5">👤 Vlastník</span>',
        'admin'      => '<span class="badge badge-partial">⚙ Výbor</span>',
        'superadmin' => '<span class="badge" style="background:#f0e6fb;color:#6b11a5">🔑 Admin</span>',
        default      => e($owner['updated_by_role']),
      }; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Akce -->
  <div class="card">
    <div style="font-size:13px;font-weight:600;color:var(--muted);margin-bottom:.75rem">Akce</div>
    <div style="display:flex;flex-direction:column;gap:6px">
      <?php if ($canEdit): ?>
        <a class="btn btn-primary btn-sm" href="/admin/owner_edit.php?id=<?= $id ?>">✏ Upravit kartu vlastníka</a>
      <?php endif; ?>
      <?php if ($mainEmail): ?>
        <a class="btn btn-secondary btn-sm" href="mailto:<?= e($mainEmail) ?>">✉️ Napsat e-mail</a>
      <?php endif; ?>
      <a class="btn btn-secondary btn-sm" href="/admin/tenants.php">🏠 Správa nájemníků</a>
      <a class="btn btn-secondary btn-sm" href="/admin/owners.php">← Zpět na kartotéku</a>
    </div>
  </div>

</div><!-- /pravý sloupec -->
</div><!-- /grid -->

<style>
/* ── Owner detail layout ───────────────────── */
.od-grid {
  display: grid;
  grid-template-columns: 1fr 340px;
  grid-template-areas: "main side";
  gap: 1.25rem;
  align-items: start;
}
.od-main { grid-area: main; }
.od-side  { grid-area: side; }

.od-contacts {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
  margin-bottom: 1rem;
}

/* ── Responsivita ──────────────────────────── */
@media (max-width: 800px) {
  .od-grid {
    grid-template-columns: 1fr;
    grid-template-areas:
      "side"
      "main";
  }
  /* Kontakty pod sebou */
  .od-contacts {
    grid-template-columns: 1fr;
  }
  /* Souhrnné boxy spotřeb — 1 sloupec */
  .od-grid .card div[style*="display:flex;gap:10px"] {
    flex-direction: column;
  }
  /* Tabulka spotřeb — skrýt sloupce Stav */
  .od-stav {
    display: none;
  }
}
</style>

<?php include __DIR__ . '/../includes/footer.php'; ?>
