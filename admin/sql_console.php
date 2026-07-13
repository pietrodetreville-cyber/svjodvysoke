<?php
require_once __DIR__ . '/../includes/functions.php';
$user = requireSuperAdmin();
$pageTitle = 'SQL konzole';

$results = [];
$errors  = [];
$query   = $_POST['query'] ?? '';
$rowsAffected = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $query) {
    csrfCheck();

    // Bezpečnostní pojistka – jen pro admina, zakázat DROP/TRUNCATE databáze
    $forbidden = '/\b(DROP\s+DATABASE|DROP\s+TABLE|TRUNCATE\s+TABLE)\b/i';
    if (preg_match($forbidden, $query)) {
        $errors[] = 'Příkazy DROP DATABASE, DROP TABLE a TRUNCATE TABLE jsou v konzoli zakázány.';
    } else {
        try {
            $db = db();
            // Rozdělíme na jednotlivé příkazy podle středníku
            $statements = array_filter(array_map('trim', explode(';', $query)));

            foreach ($statements as $stmt) {
                if (!$stmt || str_starts_with(ltrim($stmt), '--')) continue;

                $st = $db->prepare($stmt);
                $st->execute();

                $upper = strtoupper(ltrim($stmt));
                if (str_starts_with($upper, 'SELECT') || str_starts_with($upper, 'SHOW')) {
                    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                    $results[] = ['sql' => $stmt, 'rows' => $rows, 'type' => 'select'];
                } else {
                    $affected = $st->rowCount();
                    $results[] = ['sql' => $stmt, 'affected' => $affected, 'type' => 'write'];
                }
            }
        } catch (\PDOException $e) {
            $errors[] = $e->getMessage();
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-hd">
  <h1>SQL konzole</h1>
  <span class="badge badge-miss" style="font-size:12px">⚠ Pouze pro správce</span>
</div>

<div class="card" style="margin-bottom:1.25rem">
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <div class="form-group" style="margin-bottom:.75rem">
      <label>SQL dotaz nebo příkaz</label>
      <textarea name="query" id="sql-input" style="font-family:monospace;font-size:13px;min-height:180px;background:#1e1e2e;color:#cdd6f4;border-color:#444;padding:12px;line-height:1.6"><?= htmlspecialchars($query) ?></textarea>
    </div>
    <div style="display:flex;gap:8px;align-items:center">
      <button type="submit" class="btn btn-primary">▶ Spustit SQL</button>
      <button type="button" class="btn btn-secondary" onclick="document.getElementById('sql-input').value=''">Vymazat</button>
      <span style="font-size:12px;color:var(--muted)">Více příkazů oddělte <strong>;</strong> &nbsp;·&nbsp; Ctrl+Enter = spustit &nbsp;·&nbsp; DROP TABLE a DROP DATABASE jsou zakázány.</span>
    </div>
  </form>
</div>

<!-- Rychlé šablony -->
<div class="card" style="margin-bottom:1.25rem">
  <div style="font-size:13px;font-weight:600;margin-bottom:8px;color:var(--muted)">Rychlé dotazy</div>
  <div style="display:flex;gap:6px;flex-wrap:wrap">
    <?php
    $templates = [
      'Všechny tabulky'       => 'SHOW TABLES',
      'Přehled vlastníků'     => 'SELECT u.label, o.full_name, o.email, o.phone, o.status FROM owners o JOIN units u ON o.unit_id=u.id ORDER BY u.label',
      'Statistika karet'      => "SELECT status, COUNT(*) as pocet FROM owners GROUP BY status",
      'Přehled jednotek'      => 'SELECT label, type, share_numerator, share_denominator FROM units ORDER BY label',
      'Aktivní ankety'        => 'SELECT id, question, closes_at FROM polls WHERE active=1',
      'Shromáždění'           => 'SELECT id, title, meeting_date, status FROM meetings ORDER BY meeting_date DESC',
      'Uživatelé'             => 'SELECT id, username, role, created_at FROM users ORDER BY role',
    ];
    foreach ($templates as $label => $sql):
    ?>
    <button type="button" class="btn btn-secondary btn-sm"
            onclick="document.getElementById('sql-input').value=<?= json_encode($sql) ?>">
      <?= htmlspecialchars($label) ?>
    </button>
    <?php endforeach; ?>
  </div>
</div>

<!-- Chyby -->
<?php if ($errors): ?>
  <div class="card" style="margin-bottom:1rem;border-color:#FAC775;background:#FAEEDA">
    <div style="font-size:13px;font-weight:600;color:var(--amber);margin-bottom:6px">⚠ Chyba</div>
    <?php foreach ($errors as $e): ?>
      <div style="font-family:monospace;font-size:12px;color:var(--red)"><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- Výsledky -->
<?php foreach ($results as $i => $res): ?>
<div class="card" style="margin-bottom:1rem">
  <div style="font-family:monospace;font-size:11px;color:var(--muted);margin-bottom:8px;background:var(--gray-lt);padding:6px 10px;border-radius:var(--radius-sm)">
    <?= htmlspecialchars(mb_substr($res['sql'], 0, 200)) ?><?= mb_strlen($res['sql']) > 200 ? '…' : '' ?>
  </div>

  <?php if ($res['type'] === 'write'): ?>
    <div style="color:var(--green);font-size:14px;font-weight:500">
      ✓ Úspěšně provedeno — ovlivněno <?= $res['affected'] ?> řádků
    </div>

  <?php elseif ($res['type'] === 'select' && $res['rows']): ?>
    <div style="overflow-x:auto">
    <table class="tbl">
      <thead>
        <tr>
          <?php foreach (array_keys($res['rows'][0]) as $col): ?>
            <th><?= htmlspecialchars($col) ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($res['rows'] as $row): ?>
        <tr>
          <?php foreach ($row as $val): ?>
            <td style="font-size:13px;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars((string)$val) ?>">
              <?= htmlspecialchars((string)($val ?? 'NULL')) ?>
            </td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div style="font-size:12px;color:var(--muted);margin-top:6px"><?= count($res['rows']) ?> řádků</div>

  <?php elseif ($res['type'] === 'select'): ?>
    <div style="color:var(--muted);font-size:13px">Dotaz vrátil prázdný výsledek.</div>
  <?php endif; ?>
</div>
<?php endforeach; ?>

<script>
// Ctrl+Enter = spustit
document.getElementById('sql-input').addEventListener('keydown', function(e) {
  if (e.ctrlKey && e.key === 'Enter') {
    this.closest('form').submit();
  }
});
// Tab = odsazení místo přeskočení pole
document.getElementById('sql-input').addEventListener('keydown', function(e) {
  if (e.key === 'Tab') {
    e.preventDefault();
    const s = this.selectionStart;
    this.value = this.value.substring(0, s) + '  ' + this.value.substring(this.selectionEnd);
    this.selectionStart = this.selectionEnd = s + 2;
  }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
