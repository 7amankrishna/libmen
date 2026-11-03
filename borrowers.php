<?php
/** Borrowers — member directory with search, filters and pagination. */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$pdo = db();

$q     = trim($_GET['q'] ?? '');
$mem   = $_GET['membership'] ?? '';
$stat  = $_GET['status'] ?? '';
$page  = max(1, (int)($_GET['page'] ?? 1));
$per   = 8;

$stats = $pdo->query("
    SELECT
      (SELECT COUNT(*) FROM members WHERE status='Active') AS active_members,
      (SELECT COUNT(*) FROM loans WHERE status='issued') AS current_loans,
      (SELECT COUNT(*) FROM members WHERE status='Active' AND valid_till BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)) AS expiring
")->fetch();

$where = []; $args = [];
if ($q !== '') {
    $where[] = '(m.name LIKE ? OR m.email LIKE ? OR m.phone LIKE ? OR m.member_code LIKE ?)';
    $like = '%' . $q . '%';
    array_push($args, $like, $like, $like, $like);
}
if (in_array($mem, ['Student', 'Faculty', 'General'], true)) { $where[] = 'm.membership = ?'; $args[] = $mem; }
if (in_array($stat, ['Active', 'Suspended'], true))          { $where[] = 'm.status = ?';     $args[] = $stat; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$st = $pdo->prepare("SELECT COUNT(*) FROM members m $whereSql");
$st->execute($args);
$total = (int)$st->fetchColumn();

$st = $pdo->prepare("
    SELECT m.*,
      (SELECT COUNT(*) FROM loans l WHERE l.member_id = m.id AND l.status='issued') AS borrowed,
      (SELECT MIN(l.due_date) FROM loans l WHERE l.member_id = m.id AND l.status='issued') AS next_due
    FROM members m
    $whereSql
    ORDER BY m.name ASC
    LIMIT $per OFFSET " . (($page - 1) * $per)
);
$st->execute($args);
$members = $st->fetchAll();

$base = 'borrowers.php?' . http_build_query(['q' => $q, 'membership' => $mem, 'status' => $stat]);

$page_title = 'Borrowers';
$active = 'borrowers';
include __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <div>
    <h1 class="page-title">People behind the pages</h1>
    <p class="page-sub">Members, memberships and reading journeys.</p>
  </div>
  <div class="spacer"></div>
  <div class="page-actions">
    <a class="btn" href="member_form.php"><?= icon('plus', 18) ?> Register Member</a>
  </div>
</div>

<div class="grid-3" style="grid-template-columns:repeat(3,1fr)">
  <div class="card stat">
    <span class="stat-ic purple"><?= icon('users', 24) ?></span>
    <div><div class="stat-label">Active members</div><div class="stat-value"><?= number_format((int)$stats['active_members']) ?></div>
      <div class="stat-delta"><span class="delta-note">of <?= (int)$pdo->query('SELECT COUNT(*) FROM members')->fetchColumn() ?> total</span></div></div>
  </div>
  <div class="card stat">
    <span class="stat-ic blue"><?= icon('book', 24) ?></span>
    <div><div class="stat-label">Current loans</div><div class="stat-value"><?= number_format((int)$stats['current_loans']) ?></div>
      <div class="stat-delta"><span class="delta-note">books out right now</span></div></div>
  </div>
  <div class="card stat">
    <span class="stat-ic amber"><?= icon('cal', 24) ?></span>
    <div><div class="stat-label">Expiring memberships</div><div class="stat-value"><?= number_format((int)$stats['expiring']) ?></div>
      <div class="stat-delta"><span class="delta-note">in the next 30 days</span></div></div>
  </div>
</div>

<div class="toolbar mt">
  <form class="searchbar" method="get" action="borrowers.php">
    <?= icon('search', 17) ?>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search member by name, ID or email…">
    <?php if ($mem !== ''): ?><input type="hidden" name="membership" value="<?= e($mem) ?>"><?php endif; ?>
    <?php if ($stat !== ''): ?><input type="hidden" name="status" value="<?= e($stat) ?>"><?php endif; ?>
  </form>
  <form method="get" action="borrowers.php" data-autosubmit style="display:flex;gap:8px;align-items:center">
    <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
    <select class="input" name="membership">
      <option value="">All memberships</option>
      <?php foreach (['Student', 'Faculty', 'General'] as $m): ?>
        <option value="<?= $m ?>" <?= $mem === $m ? 'selected' : '' ?>><?= $m ?></option>
      <?php endforeach; ?>
    </select>
    <select class="input" name="status">
      <option value="">All statuses</option>
      <?php foreach (['Active', 'Suspended'] as $s): ?>
        <option value="<?= $s ?>" <?= $stat === $s ? 'selected' : '' ?>><?= $s ?></option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<div class="card">
  <div class="card-h" style="display:flex;align-items:center">
    <div><h3>Member Directory</h3><div class="sub">Click a member to see their details and loans.</div></div>
  </div>
  <div class="card-b" style="padding-top:6px">
    <?php if (!$members): ?>
      <div class="empty"><span class="big">🧑‍🤝‍🧑</span><strong>No members found</strong>
        <?= $total === 0 && $q === '' && $mem === '' && $stat === '' ? 'Register your first member to get started.' : 'Try a different search.' ?>
        <div style="margin-top:14px"><a class="btn" href="member_form.php"><?= icon('plus', 17) ?> Register Member</a></div>
      </div>
    <?php else: ?>
    <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Member</th><th>Member ID</th><th>Email</th><th>Membership</th><th>Borrowed</th><th>Next due</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($members as $m): ?>
        <tr>
          <td><a class="row-member" href="member_details.php?id=<?= (int)$m['id'] ?>" style="color:var(--ink)"><?= avatar_html($m['name'], 34) ?> <?= e($m['name']) ?></a></td>
          <td><span class="badge b-gray"><?= e($m['member_code']) ?></span></td>
          <td class="muted"><?= e($m['email']) ?></td>
          <td><span class="badge b-purple"><?= e($m['membership']) ?></span></td>
          <td class="num"><?= (int)$m['borrowed'] ?> / <?= (int)$m['max_books'] ?></td>
          <td class="muted"><?= $m['next_due'] ? fmt_date($m['next_due']) : '—' ?></td>
          <td><span class="badge <?= $m['status'] === 'Active' ? 'b-green' : 'b-red' ?>"><?= e($m['status']) ?></span></td>
          <td>
            <a class="btn ghost sm" href="member_details.php?id=<?= (int)$m['id'] ?>"><?= icon('eye', 14) ?> View</a>
            <a class="btn ghost sm" href="member_form.php?id=<?= (int)$m['id'] ?>"><?= icon('edit', 14) ?> Edit</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="table-foot">
      <span class="showing">Showing <?= (($page - 1) * $per + 1) ?>–<?= min($total, $page * $per) ?> of <?= $total ?> members</span>
      <?= page_links($base, $page, max(1, (int)ceil($total / $per))) ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
