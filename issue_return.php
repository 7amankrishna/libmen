<?php
/** Issue / Return — lend books to members and take them back. */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$pdo = db();

$q        = trim($_GET['q'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$per      = 8;
$memberId = (int)($_GET['member_id'] ?? 0);
$bookId   = (int)($_GET['book_id'] ?? 0);

/* members that can still borrow (Active, quota left) */
$members = $pdo->query("
    SELECT m.*, (SELECT COUNT(*) FROM loans l WHERE l.member_id=m.id AND l.status='issued') AS borrowed
    FROM members m
    WHERE m.status='Active'
    ORDER BY m.name
")->fetchAll();

/* books with at least one free copy */
$books = $pdo->query("
    SELECT b.*, b.copies - (SELECT COUNT(*) FROM loans l WHERE l.book_id=b.id AND l.status='issued') AS available
    FROM books b
    HAVING available > 0
    ORDER BY b.title
")->fetchAll();

/* active loans table */
$where = ["l.status = 'issued'"]; $args = [];
if ($q !== '') {
    $where[] = '(b.title LIKE ? OR b.author LIKE ? OR m.name LIKE ? OR m.member_code LIKE ? OR m.email LIKE ?)';
    $like = '%' . $q . '%';
    array_push($args, $like, $like, $like, $like, $like);
}
if ($memberId > 0) { $where[] = 'l.member_id = ?'; $args[] = $memberId; }
if ($bookId > 0)   { $where[] = 'l.book_id = ?';   $args[] = $bookId; }
$whereSql = 'WHERE ' . implode(' AND ', $where);

$st = $pdo->prepare("SELECT COUNT(*) FROM loans l JOIN books b ON b.id=l.book_id JOIN members m ON m.id=l.member_id $whereSql");
$st->execute($args);
$total = (int)$st->fetchColumn();

$st = $pdo->prepare("
    SELECT l.*, b.title, b.author, b.cover, m.name AS member_name, m.member_code, m.email
    FROM loans l
    JOIN books b ON b.id = l.book_id
    JOIN members m ON m.id = l.member_id
    $whereSql
    ORDER BY (l.due_date < CURDATE()) DESC, l.due_date ASC
    LIMIT $per OFFSET " . (($page - 1) * $per)
);
$st->execute($args);
$activeLoans = $st->fetchAll();

/* recent returns */
$recentReturns = $pdo->query("
    SELECT l.*, b.title, m.name AS member_name
    FROM loans l JOIN books b ON b.id=l.book_id JOIN members m ON m.id=l.member_id
    WHERE l.status='returned'
    ORDER BY l.return_date DESC, l.id DESC LIMIT 6
")->fetchAll();

$base = 'issue_return.php?' . http_build_query(['q' => $q, 'member_id' => $memberId ?: '', 'book_id' => $bookId ?: '']);

$page_title = 'Issue / Return';
$active = 'issues';
include __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <div>
    <h1 class="page-title">Issue / Return</h1>
    <p class="page-sub">Lend books to members and record returns.</p>
  </div>
</div>

<div class="grid-2" style="grid-template-columns:minmax(0,1fr) minmax(0,1.4fr);align-items:start">
  <!-- ======================= ISSUE ======================= -->
  <div class="card" id="issue">
    <div class="card-h"><h3><?= icon('swap', 18) ?> Issue a book</h3><div class="sub">Lend a copy to a member. Default loan period: <?= LOAN_DAYS_DEFAULT ?> days.</div></div>
    <div class="card-b">
      <?php if (!$members || !$books): ?>
        <div class="empty" style="padding:22px">
          <span class="big">🚧</span>
          <strong>Cannot issue yet</strong>
          <?= !$members ? 'There is no active member with free loan quota — <a href="member_form.php">register a member</a> first.' : '' ?>
          <?= !$books ? 'There is no book with an available copy — <a href="book_form.php">add a book</a> first.' : '' ?>
        </div>
      <?php else: ?>
      <form method="post" action="actions/issue.php">
        <?= csrf_field() ?>
        <div style="display:flex;flex-direction:column;gap:15px">
          <div class="field">
            <label>Member *</label>
            <select class="input" name="member_id" required>
              <option value="">Select member…</option>
              <?php foreach ($members as $m):
                  $left = (int)$m['max_books'] - (int)$m['borrowed'];
                  if ($left <= 0) continue; ?>
                <option value="<?= (int)$m['id'] ?>" <?= $memberId === (int)$m['id'] ? 'selected' : '' ?>>
                  <?= e($m['name']) ?> (<?= e($m['member_code']) ?>) — <?= (int)$m['borrowed'] ?>/<?= (int)$m['max_books'] ?> borrowed
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>Book *</label>
            <select class="input" name="book_id" required>
              <option value="">Select book…</option>
              <?php foreach ($books as $b): ?>
                <option value="<?= (int)$b['id'] ?>" <?= $bookId === (int)$b['id'] ? 'selected' : '' ?>>
                  <?= e($b['title']) ?> — <?= e($b['author']) ?> (<?= (int)$b['available'] ?> available)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-grid">
            <div class="field">
              <label>Issue date *</label>
              <input class="input" type="date" id="issue_date" name="issue_date" required max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="field">
              <label>Loan period (days) *</label>
              <input class="input" type="number" id="loan_days" name="days" required min="1" max="60" value="<?= LOAN_DAYS_DEFAULT ?>">
            </div>
          </div>
          <div class="field">
            <label>Due date (auto)</label>
            <div class="input" style="background:#faf8fe;color:var(--primary-dark);font-weight:700" id="due_preview">—</div>
          </div>
          <button class="btn block" type="submit"><?= icon('swap', 17) ?> Issue Book</button>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- ======================= ACTIVE LOANS ======================= -->
  <div class="card" id="return">
    <div class="card-h" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
      <div><h3>Active loans</h3><div class="sub">Books currently checked out — overdue first.</div></div>
      <form class="searchbar" style="margin-left:auto;max-width:260px;box-shadow:none" method="get" action="issue_return.php#return">
        <?= icon('search', 15) ?>
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search book or member…">
        <?php if ($memberId): ?><input type="hidden" name="member_id" value="<?= $memberId ?>"><?php endif; ?>
        <?php if ($bookId): ?><input type="hidden" name="book_id" value="<?= $bookId ?>"><?php endif; ?>
      </form>
    </div>
    <div class="card-b" style="padding-top:6px">
      <?php if (!$activeLoans): ?>
        <div class="empty"><span class="big">🎉</span><strong>No active loans</strong>Nothing is checked out right now.</div>
      <?php else: ?>
      <div class="tbl-wrap">
      <table class="tbl">
        <thead><tr><th>Loan</th><th>Book</th><th>Member</th><th>Issued</th><th>Due</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($activeLoans as $l):
            $late = days_late($l['due_date']);
            $overdue = loan_is_overdue($l); ?>
          <tr class="<?= $overdue ? 'overdue-row' : '' ?>">
            <td class="muted">#<?= (int)$l['id'] ?></td>
            <td><div class="row-book"><?= cover_html($l, 30, 44) ?><div><div class="row-title" style="font-size:13px"><?= e($l['title']) ?></div><div class="row-sub"><?= e($l['author']) ?></div></div></div></td>
            <td><a class="row-member" style="color:var(--ink);font-size:13px" href="member_details.php?id=<?= (int)$l['member_id'] ?>"><?= avatar_html($l['member_name'], 26) ?> <?= e($l['member_name']) ?></a></td>
            <td class="muted" style="font-size:12.5px"><?= fmt_date($l['issue_date']) ?></td>
            <td class="muted" style="font-size:12.5px"><?= fmt_date($l['due_date']) ?></td>
            <td><span class="badge <?= $overdue ? 'b-red' : 'b-purple' ?>"><?= $overdue ? 'Overdue · ' . $late . 'd' : 'Issued' ?></span></td>
            <td>
              <form class="loan-row-form" method="post" action="actions/return.php">
                <?= csrf_field() ?>
                <input type="hidden" name="loan_id" value="<?= (int)$l['id'] ?>">
                <button class="btn soft-green sm" type="submit"><?= icon('check', 13) ?> Return</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <div class="table-foot">
        <span class="showing">Showing <?= (($page - 1) * $per + 1) ?>–<?= min($total, $page * $per) ?> of <?= $total ?> active loans</span>
        <?= page_links($base . '#return', $page, max(1, (int)ceil($total / $per))) ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($recentReturns): ?>
<div class="card mt">
  <div class="card-h"><h3>Recent returns</h3><div class="sub">Latest books that came back.</div></div>
  <div class="card-b" style="padding-top:6px">
    <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Book</th><th>Member</th><th>Due</th><th>Returned</th><th>Fine</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($recentReturns as $l): ?>
        <tr>
          <td><div class="row-title" style="font-size:13px"><?= e($l['title']) ?></div></td>
          <td class="muted" style="font-size:13px"><?= e($l['member_name']) ?></td>
          <td class="muted" style="font-size:12.5px"><?= fmt_date($l['due_date']) ?></td>
          <td class="muted" style="font-size:12.5px"><?= fmt_date($l['return_date']) ?></td>
          <td class="num"><?= (float)$l['fine'] > 0 ? money($l['fine']) : '—' ?></td>
          <td><span class="badge <?= (float)$l['fine'] > 0 ? 'b-amber' : 'b-green' ?>"><?= (float)$l['fine'] > 0 ? 'Returned late' : 'Returned' ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
