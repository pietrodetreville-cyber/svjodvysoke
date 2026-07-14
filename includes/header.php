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
$sidebarLabel = $isSuperAdmin ? 'Superadmin' : ($isAdmin ? 'Výbor' : ($isTenant ? 'Nájemník' : 'Vlastník'));
$sidebarName = $isAdmin ? ($user['username'] ?? '') : ($displayName ?: ($user['username'] ?? ''));
$initials = '·';
if ($sidebarName) {
    $parts = preg_split('/\s+/', trim($sidebarName));
    $initials = mb_strtoupper(mb_substr($parts[0], 0, 1) . (isset($parts[1]) ? mb_substr($parts[1], 0, 1) : (mb_substr($parts[0], 1, 1) ?: '')));
}
$uri = $_SERVER['REQUEST_URI'] ?? '';
function navActive(string $uri, string $needle): string { return str_contains($uri, $needle) ? 'active' : ''; }
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<script>
(function(){
  try {
    var t = localStorage.getItem('svj-theme');
    if (t === 'dark' || t === 'light') document.documentElement.setAttribute('data-theme', t);
    if (localStorage.getItem('svj-sidebar-collapsed') === '1') document.documentElement.classList.add('sb-collapsed');
  } catch (e) {}
})();
</script>
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
  --gray:#5F5E5A;--gray-lt:#F1EFE8;--muted-2:#93908A;
  --border:#e0dfd8;--border-soft:#ECEAE2;--bg:#f7f6f2;--card:#fff;--sidebar-bg:#fff;
  --text:#1a1a18;--muted:#6b6a65;
  --topbar-bg:#26425C;--topbar-text:#F2F5F8;
  --radius:10px;--radius-sm:6px;
  --shadow:0 1px 2px rgba(20,18,14,.04), 0 8px 24px rgba(20,18,14,.05);
}
@media(prefers-color-scheme:dark){
  :root{
    --blue:#6BAAEA;--blue-dk:#8FC1F5;--blue-lt:#1E3348;
    --red:#E48783;--red-lt:#3B211F;
    --green:#8CC152;--green-lt:#26331C;
    --amber:#E2B764;--amber-lt:#3A2E17;
    --gray:#A29E93;--gray-lt:#2B2924;--muted-2:#6E6A61;
    --border:#37342C;--border-soft:#2B2924;--bg:#181714;--card:#211F1B;--sidebar-bg:#1C1B18;
    --text:#EDEBE5;--muted:#A29E93;
    --shadow:0 1px 2px rgba(0,0,0,.3), 0 8px 24px rgba(0,0,0,.35);
  }
}
:root[data-theme="dark"]{
  --blue:#6BAAEA;--blue-dk:#8FC1F5;--blue-lt:#1E3348;
  --red:#E48783;--red-lt:#3B211F;
  --green:#8CC152;--green-lt:#26331C;
  --amber:#E2B764;--amber-lt:#3A2E17;
  --gray:#A29E93;--gray-lt:#2B2924;--muted-2:#6E6A61;
  --border:#37342C;--border-soft:#2B2924;--bg:#181714;--card:#211F1B;--sidebar-bg:#1C1B18;
  --text:#EDEBE5;--muted:#A29E93;
  --shadow:0 1px 2px rgba(0,0,0,.3), 0 8px 24px rgba(0,0,0,.35);
}
:root[data-theme="light"]{
  --blue:#185FA5;--blue-dk:#0C447C;--blue-lt:#E6F1FB;
  --red:#A32D2D;--red-lt:#FCEBEB;
  --green:#3B6D11;--green-lt:#EAF3DE;
  --amber:#854F0B;--amber-lt:#FAEEDA;
  --gray:#5F5E5A;--gray-lt:#F1EFE8;--muted-2:#93908A;
  --border:#e0dfd8;--border-soft:#ECEAE2;--bg:#f7f6f2;--card:#fff;--sidebar-bg:#fff;
  --text:#1a1a18;--muted:#6b6a65;
  --shadow:0 1px 2px rgba(20,18,14,.04), 0 8px 24px rgba(20,18,14,.05);
}
html,body{height:100%}
body{font-family:system-ui,-apple-system,sans-serif;font-size:15px;color:var(--text);background:var(--bg);line-height:1.6;display:flex;flex-direction:column;min-height:100vh}
a{color:var(--blue);text-decoration:none}
a:hover{text-decoration:underline}

/* ── Horní lišta (přes celou šířku) ──────────────────────────────── */
.topbar{height:48px;flex-shrink:0;display:flex;align-items:center;gap:9px;padding:0 16px;background:var(--topbar-bg);color:var(--topbar-text);position:sticky;top:0;z-index:210}
.topbar-brand{display:flex;align-items:center;gap:9px;white-space:nowrap;color:inherit;text-decoration:none}
.topbar-brand svg{color:var(--topbar-text);flex-shrink:0;opacity:.9}
.topbar-brand-text{font-weight:700;font-size:14px;letter-spacing:-.01em}

/* ── Layout kostra: postranní panel + obsah ─────────────────────── */
.shell{display:flex;flex:1;min-height:0}
.sidebar{width:250px;flex-shrink:0;background:var(--sidebar-bg);border-right:1px solid var(--border);display:flex;flex-direction:column;transition:width .18s ease;overflow:hidden}
html.sb-collapsed .sidebar{width:0;border-right-color:transparent}
.sb-scroll{flex:1;overflow-y:auto;padding:14px 10px 14px;white-space:nowrap}
.sb-group-label{font-size:10.5px;font-weight:700;color:var(--muted-2);text-transform:uppercase;letter-spacing:.07em;padding:14px 10px 6px}
.sb-group-label:first-child{padding-top:6px}
.sb-link{display:flex;align-items:center;gap:10px;padding:7px 10px;margin:1px 0;border-radius:var(--radius-sm);color:var(--text);text-decoration:none;font-size:13.5px;font-weight:500;position:relative}
.sb-link svg{color:var(--muted);flex-shrink:0}
.sb-link:hover{background:var(--gray-lt);text-decoration:none}
.sb-link.active{background:var(--blue-lt);color:var(--blue);font-weight:600}
.sb-link.active svg{color:var(--blue)}
.sb-link.active::before{content:"";position:absolute;left:-10px;top:6px;bottom:6px;width:3px;background:var(--blue);border-radius:0 3px 3px 0}
.sb-link.sb-logout-link{color:var(--red)}
.sb-link.sb-logout-link svg{color:var(--red)}
.sb-footer{border-top:1px solid var(--border);padding:12px;flex-shrink:0;display:flex;align-items:center;gap:9px;white-space:nowrap}
.sb-avatar{width:30px;height:30px;border-radius:99px;background:var(--blue-lt);color:var(--blue);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12px;flex-shrink:0}
.sb-user{min-width:0;flex:1;overflow:hidden}
.sb-user-name{font-size:13px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.sb-user-role{font-size:11px;color:var(--muted)}

.content{flex:1;min-width:0;display:flex;flex-direction:column}
.content-topbar{display:flex;align-items:center;gap:12px;padding:12px 20px;border-bottom:1px solid var(--border);background:var(--card);position:sticky;top:48px;z-index:100}
.sb-toggle{background:none;border:1px solid var(--border);color:var(--muted);width:32px;height:32px;border-radius:var(--radius-sm);cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.sb-toggle:hover{background:var(--gray-lt)}
.crumb{font-size:13px;color:var(--muted);font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.content-topbar .spacer{flex:1}
.theme-toggle{background:none;border:1px solid var(--border);color:var(--muted);width:32px;height:32px;border-radius:var(--radius-sm);cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.theme-toggle:hover{background:var(--gray-lt)}
.theme-toggle .icon-moon{display:none}
:root[data-theme="dark"] .theme-toggle .icon-sun{display:none}
:root[data-theme="dark"] .theme-toggle .icon-moon{display:block}
@media(prefers-color-scheme:dark){
  .theme-toggle .icon-sun{display:none}
  .theme-toggle .icon-moon{display:block}
  :root[data-theme="light"] .theme-toggle .icon-sun{display:block}
  :root[data-theme="light"] .theme-toggle .icon-moon{display:none}
}

.sidebar-backdrop{display:none;position:fixed;top:48px;left:0;right:0;bottom:0;background:rgba(15,13,10,.4);z-index:290}
.sidebar-backdrop.show{display:block}
@media(max-width:880px){
  .sidebar{position:fixed;top:48px;left:0;bottom:0;width:270px;transform:translateX(-100%);z-index:300;box-shadow:var(--shadow);transition:transform .2s ease}
  html.sb-collapsed .sidebar{width:270px}
  .sidebar.mobile-open{transform:translateX(0)}
}

.container{width:100%;padding:1.25rem 1.5rem}
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
.form-group input,.form-group select,.form-group textarea{width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:14px;font-family:inherit;color:var(--text);background:var(--card)}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{outline:none;border-color:var(--blue);box-shadow:0 0 0 3px var(--blue-lt)}
.form-group textarea{min-height:90px;resize:vertical}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
@media(max-width:600px){.form-row{grid-template-columns:1fr}}
.btn{display:inline-block;padding:7px 16px;border-radius:var(--radius-sm);font-size:14px;font-weight:500;cursor:pointer;border:1px solid transparent;transition:background .15s;text-align:center}
.btn-primary{background:var(--blue);color:#fff;border-color:var(--blue)}
.btn-primary:hover{background:var(--blue-dk);text-decoration:none;color:#fff}
.btn-secondary{background:var(--card);color:var(--text);border-color:var(--border)}
.btn-secondary:hover{background:var(--gray-lt);text-decoration:none}
.btn-danger{background:var(--card);color:var(--red);border-color:var(--red)}
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
::placeholder{color:var(--muted-2);font-style:italic}
::-webkit-input-placeholder{color:var(--muted-2);font-style:italic}
::-moz-placeholder{color:var(--muted-2);font-style:italic}
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
<div style="background:var(--amber);color:#fff;text-align:center;padding:5px;font-size:12px;font-weight:600;letter-spacing:.05em">
  ⚠️ TESTOVACÍ PROSTŘEDÍ — změny se neprojeví na produkci
</div>
<?php endif; ?>

<header class="topbar">
  <a href="<?= $isAdmin ? '/admin/dashboard.php' : ($isTenant ? '/tenant/dashboard.php' : '/owner/dashboard.php') ?>" class="topbar-brand">
    <svg width="19" height="19" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 21h18M3 7l9-4 9 4M4 7v14M20 7v14M8 11v4m4-4v4m4-4v4"/></svg>
    <span class="topbar-brand-text"><?= e(SITE_NAME) ?></span>
  </a>
</header>
<div class="shell">
<?php if ($user): ?>
<div class="sidebar-backdrop" id="sidebarBackdrop" onclick="closeSidebarMobile()"></div>
<aside class="sidebar" id="sidebar">
  <nav class="sb-scroll" id="sbNav">
  <?php if ($isAdmin): ?>
    <a href="/admin/dashboard.php" class="sb-link <?= navActive($uri,'dashboard') ?>"><svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg>Přehled</a>
    <a href="/admin/posts.php" class="sb-link <?= navActive($uri,'posts') ?>"><svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M3 4h18M3 4v3l6 6v6l6-2v-4l6-6V4"/></svg>Nástěnka</a>

    <div class="sb-group-label">Vlastníci a jednotky</div>
    <a href="/admin/units.php" class="sb-link <?= (navActive($uri,'units') || navActive($uri,'unit_detail')) ?: '' ?>"><svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M4 21V9l8-6 8 6v12M4 21h16M9 21v-6h6v6"/></svg>Jednotky</a>
    <a href="/admin/owners.php" class="sb-link <?= (navActive($uri,'owners') || navActive($uri,'owner_edit') || navActive($uri,'owner_detail')) ?: '' ?>"><svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M6 17c0-2 1.5-3 3-3s3 1 3 3M14 9h4M14 13h4"/></svg>Kartotéka</a>
    <a href="/admin/tenants.php" class="sb-link <?= navActive($uri,'tenants') ?>"><svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>Uživatelé jednotky</a>
    <a href="/admin/residents.php" class="sb-link <?= navActive($uri,'residents') ?>"><svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7"/></svg>Obyvatelé</a>

    <div class="sb-group-label">Správa SVJ</div>
    <a href="/admin/committee.php" class="sb-link <?= navActive($uri,'committee') ?>"><svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M12 3 4 6v6c0 4.5 3.4 7.7 8 9 4.6-1.3 8-4.5 8-9V6l-8-3Z"/></svg>Orgány SVJ</a>
    <a href="/admin/meetings.php" class="sb-link <?= navActive($uri,'meeting') ?>"><svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10h18"/></svg>Shromáždění</a>
    <a href="/admin/perrollam.php" class="sb-link <?= navActive($uri,'perrollam') ?>"><svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="m9 12 2 2 4-4M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Z"/></svg>Per rollam</a>
    <a href="/admin/polls.php" class="sb-link <?= navActive($uri,'polls') ?>"><svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M3 21V10M10 21V3M17 21v-7"/></svg>Ankety</a>

    <div class="sb-group-label">Dokumenty</div>
    <a href="/admin/documents.php" class="sb-link <?= navActive($uri,'documents') ?>"><svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6Z"/><path d="M14 2v6h6"/></svg>Archiv dokumentů</a>
    <a href="/admin/drive.php" class="sb-link <?= navActive($uri,'drive') ?>"><svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1.5 1.5M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1.5-1.5"/></svg>Sdílené odkazy</a>

    <div class="sb-group-label">Administrace</div>
    <a href="/admin/users.php" class="sb-link <?= navActive($uri,'users') ?>"><svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/></svg>Uživatelé</a>
    <?php if ($isSuperAdmin): ?>
    <a href="/admin/sql_console.php" class="sb-link <?= navActive($uri,'sql_console') ?>"><svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="m8 9 3 3-3 3M13 15h4"/></svg>SQL konzole</a>
    <?php endif; ?>

  <?php elseif ($isTenant): ?>
    <a href="/tenant/dashboard.php" class="sb-link <?= navActive($uri,'tenant/dashboard') ?>"><svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M4 21V9l8-6 8 6v12M4 21h16M9 21v-6h6v6"/></svg>Domů</a>
    <a href="/owner/posts.php" class="sb-link <?= navActive($uri,'posts') ?>"><svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M3 4h18M3 4v3l6 6v6l6-2v-4l6-6V4"/></svg>Nástěnka</a>
    <a href="/owner/documents.php" class="sb-link <?= navActive($uri,'owner/documents') ?>"><svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6Z"/><path d="M14 2v6h6"/></svg>Dokumenty</a>
    <a href="/owner/drive.php" class="sb-link <?= navActive($uri,'owner/drive') ?>"><svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1.5 1.5M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1.5-1.5"/></svg>Sdílené odkazy</a>

  <?php else: ?>
    <a href="/owner/dashboard.php" class="sb-link <?= navActive($uri,'dashboard') ?>"><svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M4 21V9l8-6 8 6v12M4 21h16M9 21v-6h6v6"/></svg>Domů</a>
    <a href="/owner/profile.php" class="sb-link <?= navActive($uri,'profile') ?>"><svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M6 17c0-2 1.5-3 3-3s3 1 3 3M14 9h4M14 13h4"/></svg>Moje karta</a>
    <a href="/owner/committee.php" class="sb-link <?= navActive($uri,'owner/committee') ?>"><svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M12 3 4 6v6c0 4.5 3.4 7.7 8 9 4.6-1.3 8-4.5 8-9V6l-8-3Z"/></svg>Orgány SVJ</a>
    <a href="/owner/posts.php" class="sb-link <?= navActive($uri,'owner/posts') ?>"><svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M3 4h18M3 4v3l6 6v6l6-2v-4l6-6V4"/></svg>Nástěnka</a>
    <a href="/owner/perrollam.php" class="sb-link <?= navActive($uri,'perrollam') ?>"><svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="m9 12 2 2 4-4M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Z"/></svg>Per rollam</a>
    <a href="/owner/polls.php" class="sb-link <?= navActive($uri,'polls') ?>"><svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M3 21V10M10 21V3M17 21v-7"/></svg>Ankety</a>
    <a href="/owner/documents.php" class="sb-link <?= navActive($uri,'owner/documents') ?>"><svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6Z"/><path d="M14 2v6h6"/></svg>Dokumenty</a>
    <a href="/owner/drive.php" class="sb-link <?= navActive($uri,'owner/drive') ?>"><svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1.5 1.5M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1.5-1.5"/></svg>Sdílené odkazy</a>
    <a href="/owner/message.php" class="sb-link <?= navActive($uri,'owner/message') ?>"><svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 6 10 7 10-7"/></svg>Zpráva sousedům</a>
  <?php endif; ?>
    <a href="/logout.php" class="sb-link sb-logout-link"><svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>Odhlásit</a>
  </nav>

  <div class="sb-footer">
    <div class="sb-avatar"><?= e($initials) ?></div>
    <div class="sb-user">
      <div class="sb-user-name" title="<?= e($sidebarName) ?>"><?= e($sidebarName ?: $sidebarLabel) ?></div>
      <div class="sb-user-role"><?= e($sidebarLabel) ?></div>
    </div>
  </div>
</aside>
<?php endif; ?>

<div class="content">
  <?php if ($user): ?>
  <div class="content-topbar">
    <button class="sb-toggle" id="sbToggleBtn" aria-label="Přepnout panel" title="Přepnout postranní panel">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16"/></svg>
    </button>
    <div class="crumb"><?= e($pageTitle ?? SITE_NAME) ?></div>
    <div class="spacer"></div>
    <button class="theme-toggle" id="themeToggleBtn" aria-label="Přepnout světlý/tmavý režim" title="Přepnout světlý/tmavý režim">
      <svg class="icon-sun" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
      <svg class="icon-moon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8Z"/></svg>
    </button>
  </div>
  <?php endif; ?>
  <div class="container">
<?php if ($flash): ?>
  <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div>
<?php endif; ?>
<script>
function closeSidebarMobile(){
  document.getElementById('sidebar').classList.remove('mobile-open');
  document.getElementById('sidebarBackdrop').classList.remove('show');
}
(function(){
  var toggleBtn = document.getElementById('sbToggleBtn');
  if (toggleBtn) {
    toggleBtn.addEventListener('click', function(){
      var sb = document.getElementById('sidebar');
      if (window.innerWidth <= 880) {
        sb.classList.toggle('mobile-open');
        document.getElementById('sidebarBackdrop').classList.toggle('show');
      } else {
        var collapsed = document.documentElement.classList.toggle('sb-collapsed');
        try { localStorage.setItem('svj-sidebar-collapsed', collapsed ? '1' : '0'); } catch (e) {}
      }
    });
  }
  document.querySelectorAll('#sbNav a').forEach(function(a){
    a.addEventListener('click', function(){
      if (window.innerWidth <= 880) closeSidebarMobile();
    });
  });
  var themeBtn = document.getElementById('themeToggleBtn');
  if (themeBtn) {
    themeBtn.addEventListener('click', function(){
      var root = document.documentElement;
      var current = root.getAttribute('data-theme');
      var systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
      var isDark = current ? current === 'dark' : systemDark;
      var next = isDark ? 'light' : 'dark';
      root.setAttribute('data-theme', next);
      try { localStorage.setItem('svj-theme', next); } catch (e) {}
    });
  }
})();
</script>
