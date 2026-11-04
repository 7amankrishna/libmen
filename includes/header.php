<?php
/**
 * Page shell: <head>, sidebar navigation and top bar.
 * Expects: $page_title, $active (nav key) set by the including page.
 * Requires bootstrap.php to have been loaded and the user to be logged in.
 */
$page_title = $page_title ?? 'Library Management System';
$active     = $active ?? '';
$user       = current_user();

$overdue_count = 0;
try {
    $overdue_count = (int) db()->query(
        "SELECT COUNT(*) FROM loans WHERE status='issued' AND due_date < CURDATE()"
    )->fetchColumn();
} catch (Throwable $t) { /* db not ready yet (install) */ }

$nav = [
    'dashboard' => ['index.php',          'Dashboard',      'home'],
    'books'     => ['books.php',          'Books',          'book'],
    'borrowers' => ['borrowers.php',      'Borrowers',      'users'],
    'issues'    => ['issue_return.php',   'Issue / Return', 'swap'],
    'reports'   => ['reports.php',        'Reports',        'chart'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($page_title) ?> · Library Management System</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Playfair+Display:ital,wght@0,600;0,700;0,800;1,500;1,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css?v=1">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Crect width='24' height='24' rx='6' fill='%237c3aed'/%3E%3Cpath d='M6 18V6a2 2 0 0 1 2-2h8v14H8a2 2 0 0 0-2 2zm0 0a2 2 0 0 0 2 2h8' stroke='white' stroke-width='1.6' fill='none' stroke-linecap='round'/%3E%3C/svg%3E">
</head>
<body>
<div class="app">
  <aside class="sidebar" id="sidebar">
    <div class="brand">
      <span class="brand-logo"><?= icon('books', 26) ?></span>
      <span class="brand-name">Library<br><small>Management System</small></span>
    </div>
    <nav class="nav">
      <?php foreach ($nav as $key => [$href, $label, $ic]): ?>
        <a class="<?= $active === $key ? 'on' : '' ?>" href="<?= $href ?>"><?= icon($ic) ?><span><?= e($label) ?></span></a>
      <?php endforeach; ?>
    </nav>
    <div class="side-art" aria-hidden="true">
      <svg viewBox="0 0 220 130" fill="none">
        <g opacity=".9">
          <rect x="18" y="52" width="16" height="66" rx="3" fill="#8b5cf6"/>
          <rect x="38" y="40" width="16" height="78" rx="3" fill="#a78bfa"/>
          <rect x="58" y="58" width="16" height="60" rx="3" fill="#7c3aed"/>
          <rect x="78" y="30" width="18" height="88" rx="3" fill="#c4b5fd"/>
          <rect x="100" y="48" width="16" height="70" rx="3" fill="#8b5cf6" transform="rotate(8 108 83)"/>
          <rect x="122" y="60" width="14" height="58" rx="3" fill="#a78bfa" transform="rotate(16 129 89)"/>
        </g>
        <g stroke="#a78bfa" stroke-width="2" stroke-linecap="round" opacity=".8">
          <path d="M170 110c0-18 10-30 26-34"/>
          <path d="M196 76c-8 0-14 5-15 12 8 1 14-4 15-12z" fill="#8b5cf6" stroke="none"/>
          <path d="M182 96c-9-1-16 3-18 10 9 2 16-2 18-10z" fill="#7c3aed" stroke="none"/>
        </g>
      </svg>
    </div>
    <div class="side-user">
      <?= avatar_html($user['name'] ?? 'User', 34) ?>
      <div class="side-user-meta">
        <strong><?= e($user['name'] ?? 'User') ?></strong>
        <small><?= e(ucfirst($user['role'] ?? 'librarian')) ?></small>
      </div>
      <a href="logout.php" title="Logout" class="side-logout"><?= icon('logout', 18) ?></a>
    </div>
  </aside>

  <div class="main">
    <header class="topbar">
      <button class="icon-btn only-mobile" id="navToggle" aria-label="Menu"><?= icon('menu', 22) ?></button>
      <nav class="crumb">
        <a href="index.php" class="crumb-home"><?= icon('home', 18) ?></a>
        <span class="crumb-sep">/</span>
        <span class="crumb-page"><?= e($page_title) ?></span>
      </nav>
      <div class="topbar-right">
        <form class="topsearch" action="books.php" method="get">
          <?= icon('search', 17) ?>
          <input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Search books, members, or reports…">
        </form>
        <a class="icon-btn bell" href="reports.php#overdue" title="Overdue items">
          <?= icon('bell', 20) ?>
          <?php if ($overdue_count > 0): ?><span class="bell-badge"><?= $overdue_count ?></span><?php endif; ?>
        </a>
        <details class="usermenu">
          <summary>
            <?= avatar_html($user['name'] ?? 'User', 36) ?>
            <span class="um-name"><?= e($user['name'] ?? 'User') ?><small><?= e(ucfirst($user['role'] ?? '')) ?></small></span>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="m6 9 6 6 6-6"/></svg>
          </summary>
          <div class="um-drop">
            <div class="um-head">
              <strong><?= e($user['name'] ?? '') ?></strong>
              <small><?= e($user['email'] ?? '') ?></small>
            </div>
            <a href="logout.php"><?= icon('logout', 17) ?> Logout</a>
          </div>
        </details>
      </div>
    </header>

    <main class="content">
