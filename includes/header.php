<?php
$flash = getFlash();
$isAdmin = in_array($user['role'] ?? '', ['admin','superadmin']);
$isSuperAdmin = ($user['role'] ?? '') === 'superadmin';
$isTenant = ($user['role'] ?? '') === 'tenant';

// Načti jméno vlastníka/nájemníka
$displayName = '';
if (!$isAdmin) {
    $db2 = db();
    if ($isTenant && !empty($user['tenant_id'])) {
        $sn = $db2->prepare('SELECT full_name FROM tenants WHERE id=?');
        $sn->execute([$user['tenant_id']]);
        $displayName = $sn->fetchColumn() ?: '';
    } elseif (!$isTenant && !empty($user['unit_id'])) {
        $sn = $db2->prepare('SELECT full_name FROM owners WHERE unit_id=? LIMIT 1');
        $sn->execute([$user['unit_id']]);
        $displayName = $sn->fetchColumn() ?: '';
    }
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="theme-color" content="#185FA5">
<link rel="manifest" href="/manifest.json">
<link rel="shortcut icon" href="/favicon-32.png">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/favicon-16.png">
<link rel="apple-touch-icon" href="/icons/icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="SVJ Rozhled">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle ?? SITE_NAME) ?> – <?= e(SITE_NAME) ?></title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --blue:#185FA5;--blue-dk:#0C447C;--blue-lt:#E6F1FB;
  --red:#A32D2D;--red-lt:#FCEBEB;
  --green:#3B6D11;--green-lt:#EAF3DE;
  --amber:#854F0B;--amber-lt:#FAEEDA;
  --gray:#5F5E5A;--gray-lt:#F1EFE8;
  --border:#e0dfd8;--bg:#f7f6f2;--card:#fff;
  --text:#1a1a18;--muted:#6b6a65;
  --radius:10px;--radius-sm:6px;
}
body{font-family:system-ui,-apple-system,sans-serif;font-size:15px;color:var(--text);background:var(--bg);line-height:1.6}
a{color:var(--blue);text-decoration:none}
a:hover{text-decoration:underline}
.topbar{background:#fff;border-bottom:1px solid var(--border);padding:0 1rem;height:54px;display:flex;align-items:center;gap:.5rem;position:sticky;top:0;z-index:200}
.topbar-brand{font-weight:700;font-size:14px;color:var(--text);display:flex;align-items:center;gap:6px;white-space:nowrap;flex-shrink:0;text-decoration:none}
.topbar-brand svg{color:var(--blue)}
.topbar-right{margin-left:auto;display:flex;align-items:center;gap:8px;flex-shrink:0}
.role-pill{font-size:11px;padding:2px 8px;border-radius:99px;background:var(--blue-lt);color:var(--blue);font-weight:500;white-space:nowrap}
.btn-logout{font-size:12px;color:var(--muted);border:1px solid var(--border);border-radius:var(--radius-sm);padding:4px 10px;background:#fff;white-space:nowrap;text-decoration:none}
.btn-logout:hover{background:var(--gray-lt)}
.hamburger{display:none;flex-direction:column;gap:5px;cursor:pointer;padding:6px;border:none;background:none;flex-shrink:0}
.hamburger span{display:block;width:22px;height:2px;background:var(--text);border-radius:2px;transition:all .25s}
.hamburger.open span:nth-child(1){transform:translateY(7px) rotate(45deg)}
.hamburger.open span:nth-child(2){opacity:0}
.hamburger.open span:nth-child(3){transform:translateY(-7px) rotate(-45deg)}
.topbar-nav{display:flex;gap:2px;margin-left:.5rem;flex-wrap:nowrap;overflow-x:auto;scrollbar-width:none}
.topbar-nav::-webkit-scrollbar{display:none}
.topbar-nav a{padding:5px 10px;border-radius:var(--radius-sm);font-size:12px;color:var(--muted);font-weight:500;white-space:nowrap;text-decoration:none}
.topbar-nav a:hover,.topbar-nav a.active{background:var(--blue-lt);color:var(--blue)}
@media(max-width:700px){
  .hamburger{display:flex}
  .topbar-nav{display:none;position:fixed;top:54px;left:0;right:0;bottom:0;background:#fff;z-index:199;flex-direction:column;gap:0;padding:1rem;overflow-y:auto;border-top:1px solid var(--border)}
  .topbar-nav.open{display:flex}
  .topbar-nav a{font-size:15px;padding:12px 14px;border-radius:var(--radius-sm);color:var(--text);border-bottom:1px solid var(--border)}
  .topbar-nav a:last-child{border-bottom:none}
  .topbar-nav a.active{background:var(--blue-lt);color:var(--blue)}
  .role-pill{display:none}
}
.container{max-width:1100px;margin:0 auto;padding:1.25rem 1rem}
.flash{padding:.75rem 1rem;border-radius:var(--radius-sm);margin-bottom:1rem;font-size:14px;font-weight:500}
.flash-info{background:var(--blue-lt);color:var(--blue)}
.flash-success{background:var(--green-lt);color:var(--green)}
.flash-error{background:var(--red-lt);color:var(--red)}
.flash-warning{background:var(--amber-lt);color:var(--amber)}
.card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:1.25rem}
.card-title{font-size:16px;font-weight:600;margin-bottom:1rem;color:var(--text)}
.metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:8px;margin-bottom:1.25rem}
.metric{background:var(--gray-lt);border-radius:var(--radius-sm);padding:.75rem;text-align:center}
.metric-num{font-size:24px;font-weight:600;color:var(--text)}
.metric-lbl{font-size:11px;color:var(--muted);margin-top:1px}
.tbl-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
.tbl{width:100%;border-collapse:collapse;font-size:14px;min-width:500px}
.tbl th{text-align:left;font-size:11px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.04em;padding:6px 10px;border-bottom:1px solid var(--border)}
.tbl td{padding:8px 10px;border-bottom:1px solid var(--border);vertical-align:middle}
.tbl tr:last-child td{border-bottom:none}
.tbl tr:hover td{background:var(--gray-lt)}
.badge{display:inline-block;font-size:11px;font-weight:600;padding:2px 8px;border-radius:99px}
.badge-ok{background:var(--green-lt);color:var(--green)}
.badge-partial{background:var(--amber-lt);color:var(--amber)}
.badge-miss{background:var(--red-lt);color:var(--red)}
.badge-blue{background:var(--blue-lt);color:var(--blue)}
.form-group{margin-bottom:1rem}
.form-group label{display:block;font-size:13px;font-weight:500;color:var(--muted);margin-bottom:4px}
.form-group input,.form-group select,.form-group textarea{width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:14px;font-family:inherit;color:var(--text);background:#fff}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{outline:none;border-color:var(--blue);box-shadow:0 0 0 3px rgba(24,95,165,.15)}
.form-group textarea{min-height:90px;resize:vertical}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
@media(max-width:600px){.form-row{grid-template-columns:1fr}}
.btn{display:inline-block;padding:7px 16px;border-radius:var(--radius-sm);font-size:14px;font-weight:500;cursor:pointer;border:1px solid transparent;transition:background .15s;text-align:center}
.btn-primary{background:var(--blue);color:#fff;border-color:var(--blue)}
.btn-primary:hover{background:var(--blue-dk);text-decoration:none;color:#fff}
.btn-secondary{background:#fff;color:var(--text);border-color:var(--border)}
.btn-secondary:hover{background:var(--gray-lt);text-decoration:none}
.btn-danger{background:#fff;color:var(--red);border-color:var(--red)}
.btn-danger:hover{background:var(--red-lt);text-decoration:none}
.btn-sm{padding:4px 10px;font-size:12px}
.poll-bar-wrap{background:var(--gray-lt);border-radius:99px;height:10px;overflow:hidden;flex:1;min-width:60px}
.poll-bar{height:100%;background:var(--blue);border-radius:99px}
.poll-row{display:flex;align-items:center;gap:8px;margin-bottom:6px;font-size:13px;flex-wrap:wrap}
.post-item{padding:.85rem 0;border-bottom:1px solid var(--border)}
.post-item:last-child{border-bottom:none}
.post-meta{font-size:12px;color:var(--muted);margin-bottom:3px}
.post-title{font-size:15px;font-weight:600;color:var(--text)}
.post-body{font-size:13px;color:var(--muted);margin-top:4px;line-height:1.5}
.check-row{display:flex;align-items:flex-start;gap:8px;font-size:13px;color:var(--muted)}
.check-row input{margin-top:3px;flex-shrink:0}
.page-hd{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:8px}
.page-hd h1{font-size:20px;font-weight:600}
::placeholder{color:#b8b5b0;font-style:italic}
::-webkit-input-placeholder{color:#b8b5b0;font-style:italic}
::-moz-placeholder{color:#b8b5b0;font-style:italic}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
@media(max-width:700px){.grid2{grid-template-columns:1fr}}
</style>
<script>
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js').catch(()=>{});
}
</script>
</head>
<body>
<?php if (defined('SITE_NAME') && str_contains(SITE_NAME, '[TEST]')): ?>
<div style="background:#854F0B;color:#fff;text-align:center;padding:5px;font-size:12px;font-weight:600;letter-spacing:.05em;position:sticky;top:0;z-index:999">
  ⚠️ TESTOVACÍ PROSTŘEDÍ — změny se neprojeví na produkci
</div>
<?php endif; ?>
<nav class="topbar">
  <a href="<?= $isAdmin ? '/admin/dashboard.php' : ($isTenant ? '/tenant/dashboard.php' : '/owner/dashboard.php') ?>" class="topbar-brand">
    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
      <path d="M3 21h18M3 7l9-4 9 4M4 7v14M20 7v14M8 11v4m4-4v4m4-4v4"/>
    </svg>
    <?= e(SITE_NAME) ?>
  </a>
  <?php if ($user): ?>
  <button class="hamburger" id="ham" onclick="toggleMenu()" aria-label="Menu">
    <span></span><span></span><span></span>
  </button>
  <div class="topbar-nav" id="nav">
    <?php if ($isAdmin): ?>
      <a href="/admin/dashboard.php" class="<?= str_contains($_SERVER['REQUEST_URI'],'dashboard') ? 'active' : '' ?>">Přehled</a>
      <a href="/admin/committee.php" class="<?= str_contains($_SERVER['REQUEST_URI'],'committee') ? 'active' : '' ?>">Orgány SVJ</a>
      <a href="/admin/owners.php" class="<?= str_contains($_SERVER['REQUEST_URI'],'owners') || str_contains($_SERVER['REQUEST_URI'],'owner_edit') ? 'active' : '' ?>">Kartotéka</a>
      <a href="/admin/units.php" class="<?= str_contains($_SERVER['REQUEST_URI'],'units') ? 'active' : '' ?>">Jednotky</a>
      <a href="/admin/posts.php" class="<?= str_contains($_SERVER['REQUEST_URI'],'posts') ? 'active' : '' ?>">Nástěnka</a>
      <a href="/admin/meetings.php" class="<?= str_contains($_SERVER['REQUEST_URI'],'meeting') ? 'active' : '' ?>">Shromáždění</a>
      <a href="/admin/perrollam.php" class="<?= str_contains($_SERVER['REQUEST_URI'],'perrollam') ? 'active' : '' ?>">Per rollam</a>
      <a href="/admin/polls.php" class="<?= str_contains($_SERVER['REQUEST_URI'],'polls') ? 'active' : '' ?>">Ankety</a>
      <a href="/admin/documents.php" class="<?= str_contains($_SERVER['REQUEST_URI'],'documents') ? 'active' : '' ?>">Dokumenty</a>
      <a href="/admin/drive.php" class="<?= str_contains($_SERVER['REQUEST_URI'],'drive') ? 'active' : '' ?>">🔗 Drive</a>
      <?php if ($isSuperAdmin): ?>
      <a href="/admin/sql_console.php" class="<?= str_contains($_SERVER['REQUEST_URI'],'sql_console') ? 'active' : '' ?>">SQL</a>
      <?php endif; ?>
      <a href="/admin/tenants.php" class="<?= str_contains($_SERVER['REQUEST_URI'],'tenants') ? 'active' : '' ?>">Uživatelé jednotky</a>
      <a href="/admin/residents.php" class="<?= str_contains($_SERVER['REQUEST_URI'],'residents') ? 'active' : '' ?>">Obyvatelé</a>
      <a href="/admin/users.php" class="<?= str_contains($_SERVER['REQUEST_URI'],'users') ? 'active' : '' ?>">Uživatelé</a>
    <?php elseif ($isTenant): ?>
      <a href="/tenant/dashboard.php" class="<?= str_contains($_SERVER['REQUEST_URI'],'tenant/dashboard') ? 'active' : '' ?>">Domů</a>
      <a href="/owner/posts.php" class="<?= str_contains($_SERVER['REQUEST_URI'],'posts') ? 'active' : '' ?>">Nástěnka</a>
      <a href="/owner/documents.php" class="<?= str_contains($_SERVER['REQUEST_URI'],'owner/documents') ? 'active' : '' ?>">Dokumenty</a>
      <a href="/owner/drive.php" class="<?= str_contains($_SERVER['REQUEST_URI'],'owner/drive') ? 'active' : '' ?>">🔗 Drive</a>
    <?php else: ?>
      <a href="/owner/dashboard.php" class="<?= str_contains($_SERVER['REQUEST_URI'],'dashboard') ? 'active' : '' ?>">Domů</a>
      <a href="/owner/committee.php" class="<?= str_contains($_SERVER['REQUEST_URI'],'owner/committee') ? 'active' : '' ?>">Orgány SVJ</a>
      <a href="/owner/profile.php" class="<?= str_contains($_SERVER['REQUEST_URI'],'profile') ? 'active' : '' ?>">Moje karta</a>
      <a href="/owner/posts.php" class="<?= str_contains($_SERVER['REQUEST_URI'],'owner/posts') ? 'active' : '' ?>">Nástěnka</a>
      <a href="/owner/perrollam.php" class="<?= str_contains($_SERVER['REQUEST_URI'],'perrollam') ? 'active' : '' ?>">Per rollam</a>
      <a href="/owner/polls.php" class="<?= str_contains($_SERVER['REQUEST_URI'],'polls') ? 'active' : '' ?>">Ankety</a>
      <a href="/owner/documents.php" class="<?= str_contains($_SERVER['REQUEST_URI'],'owner/documents') ? 'active' : '' ?>">Dokumenty</a>
      <a href="/owner/drive.php" class="<?= str_contains($_SERVER['REQUEST_URI'],'owner/drive') ? 'active' : '' ?>">🔗 Drive</a>
      <a href="/owner/message.php" class="<?= str_contains($_SERVER['REQUEST_URI'],'owner/message') ? 'active' : '' ?>">✉️ Zpráva sousedům</a>
    <?php endif; ?>
    <a href="/logout.php" style="color:var(--red)">Odhlásit</a>
  </div>
  <div class="topbar-right">
    <?php if ($isSuperAdmin): ?>
      <span class="role-pill">🔑 Super</span>
    <?php elseif ($isAdmin): ?>
      <span class="role-pill">⚙ Výbor</span>
    <?php elseif ($isTenant): ?>
      <span class="role-pill" title="<?= e($displayName) ?>">🏠 <?= $displayName ? e(mb_substr($displayName,0,18)) : 'Nájemník' ?></span>
    <?php else: ?>
      <span class="role-pill" title="<?= e($displayName) ?>">👤 <?= $displayName ? e(mb_substr($displayName,0,18)) : e($user['username']) ?></span>
    <?php endif; ?>
    <a href="/logout.php" class="btn-logout">Odhlásit</a>
  </div>
  <?php endif; ?>
</nav>
<div class="container">
<?php if ($flash): ?>
  <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div>
<?php endif; ?>
<script>
function toggleMenu(){
  document.getElementById('ham').classList.toggle('open');
  document.getElementById('nav').classList.toggle('open');
}
document.addEventListener('DOMContentLoaded',function(){
  document.querySelectorAll('#nav a').forEach(function(a){
    a.addEventListener('click',function(){
      document.getElementById('ham').classList.remove('open');
      document.getElementById('nav').classList.remove('open');
    });
  });
});
</script>
