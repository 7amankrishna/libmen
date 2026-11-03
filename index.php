<?php
/** Dashboard — live overview of the whole library. */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$pdo = db();

$stats = $pdo->query("
    SELECT
      (SELECT COUNT(*) FROM books)                                            AS total_titles,
      (SELECT COALESCE(SUM(copies),0) FROM books)                              AS total_copies,
      (SELECT COUNT(*) FROM members)                                           AS total_members,
      (SELECT COUNT(*) FROM members WHERE status='Active')                     AS active_members,
      (SELECT COUNT(*) FROM loans WHERE status='issued')                       AS on_loan,
      (SELECT COUNT(*) FROM loans WHERE status='issued' AND due_date < CURDATE()) AS overdue
")->fetch();

$available = max(0, (int)$stats['total_copies'] - (int)$stats['on_loan']);

/* recent issue / return records */
$recent = $pdo->query("
    SELECT l.*, b.title, b.author, b.cover, m.name AS member_name, m.member_code
    FROM loans l
    JOIN books b   ON b.id = l.book_id
    JOIN members m ON m.id = l.member_id
    ORDER BY l.created_at DESC, l.id DESC
    LIMIT 8
")->fetchAll();

/* recent audit activity */
$activity = $pdo->query("
    SELECT a.*, u.name AS user_name FROM activity_log a
    LEFT JOIN users u ON u.id = a.user_id
    ORDER BY a.created_at DESC, a.id DESC LIMIT 7
")->fetchAll();

$page_title = 'Dashboard';
$active = 'dashboard';
$load_charts = true;
include __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <div>
    <h1 class="page-title">Dashboard</h1>
    <p class="page-sub">Welcome to Library Management System — manage books, borrowers, and issue/return records easily.</p>
  </div>
</div>

<div class="card" style="display:flex;align-items:center;gap:22px;padding:22px 26px;flex-wrap:wrap;
     background:linear-gradient(120deg,#f3efff, #faf8ff 55%, #eef2ff);border-color:#e6defa">
  <div style="flex:1;min-width:260px">
    <h2 style="font-size:22px;font-weight:800">Welcome to Library Management System</h2>
    <p style="margin:6px 0 0;color:var(--muted)">Manage books, borrowers, and issue/return records easily.</p>
  </div>
  <svg width="150" height="86" viewBox="0 0 150 86" aria-hidden="true">
    <g transform="rotate(-8 75 43)">
      <rect x="30" y="18" width="90" height="14" rx="4" fill="#f59e0b"/>
      <rect x="34" y="30" width="86" height="14" rx="4" fill="#8b5cf6"/>
      <rect x="28" y="42" width="94" height="14" rx="4" fill="#6d28d9"/>
      <rect x="34" y="56" width="86" height="14" rx="4" fill="#a78bfa"/>
      <rect x="112" y="22" width="6" height="46" rx="2" fill="#fff" opacity=".55"/>
    </g>
  </svg>
</div>

<div class="grid-4 mt">
  <div class="card stat">
    <span class="stat-ic purple"><?= icon('book', 24) ?></span>
    <div>
      <div class="stat-label">Total Books</div>
      <div class="stat-value"><?= number_format((int)$stats['total_titles']) ?></div>
      <div class="stat-delta"><span class="delta-note"><?= number_format((int)$stats['total_copies']) ?> copies</span></div>
    </div>
  </div>
  <div class="card stat">
    <span class="stat-ic blue"><?= icon('users', 24) ?></span>
    <div>
      <div class="stat-label">Total Borrowers</div>
      <div class="stat-value"><?= number_format((int)$stats['total_members']) ?></div>
      <div class="stat-delta"><span class="delta-note"><?= (int)$stats['active_members'] ?> active</span></div>
    </div>
  </div>
  <div class="card stat">
    <span class="stat-ic green"><?= icon('books', 24) ?></span>
    <div>
      <div class="stat-label">Books Issued</div>
      <div class="stat-value"><?= number_format((int)$stats['on_loan']) ?></div>
      <div class="stat-delta"><span class="delta-note"><?= $available ?> copies available</span></div>
    </div>
  </div>
  <div class="card stat">
    <span class="stat-ic <?= (int)$stats['overdue'] > 0 ? 'red' : 'green' ?>"><?= icon('clock', 24) ?></span>
    <div>
      <div class="stat-label">Overdue Books</div>
      <div class="stat-value"><?= number_format((int)$stats['overdue']) ?></div>
      <div class="stat-delta">
        <?php if ((int)$stats['overdue'] > 0): ?>
          <a class="delta down" href="reports.php#overdue" style="text-decoration:none">follow up →</a>
        <?php else: ?>
          <span class="delta up">none 🎉</span>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="card mt">
  <div class="card-h" style="border-bottom:0;padding-bottom:0"><h3>Quick Actions</h3></div>
  <div class="card-b qa-grid">
    <a class="btn" href="book_form.php" style="justify-content:center"><?= icon('plus', 18) ?> Add Book</a>
    <a class="btn ghost" href="member_form.php" style="justify-content:center;color:var(--blue)"><?= icon('users', 18) ?> Add Borrower</a>
    <a class="btn ghost" href="issue_return.php#issue" style="justify-content:center;color:var(--green)"><?= icon('swap', 18) ?> Issue Book</a>
    <a class="btn soft-red" href="issue_return.php#return" style="justify-content:center"><?= icon('swap', 18) ?> Return Book</a>
  </div>
</div>

<div class="grid-2 mt" style="grid-template-columns:minmax(0,1fr) 340px">
  <div class="card">
    <div class="card-h" style="display:flex;align-items:center">
      <div><h3>Recent Issue Records</h3><div class="sub">Latest loans and returns across the library.</div></div>
      <a href="issue_return.php" style="margin-left:auto;font-weight:700;font-size:13px">View All <?= icon('arrow', 15) ?></a>
    </div>
    <div class="card-b" style="padding-top:8px">
      <?php if (!$recent): ?>
        <div class="empty"><span class="big">📚</span><strong>No loans yet</strong>Issue your first book from <a href="issue_return.php">Issue / Return</a>.</div>
      <?php else: ?>
      <div class="tbl-wrap">
      <table class="tbl">
        <thead><tr><th>Book</th><th>Borrower</th><th>Issue Date</th><th>Due Date</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($recent as $l): [$label, $cls] = loan_badge($l); ?>
          <tr class="<?= loan_is_overdue($l) ? 'overdue-row' : '' ?>">
            <td>
              <div class="row-book">
                <?= cover_html($l, 34, 48) ?>
                <div><div class="row-title"><?= e($l['title']) ?></div><div class="row-sub"><?= e($l['author']) ?></div></div>
              </div>
            </td>
            <td><div class="row-member"><?= avatar_html($l['member_name'], 30) ?> <?= e($l['member_name']) ?></div></td>
            <td class="muted"><?= fmt_date($l['issue_date']) ?></td>
            <td class="muted"><?= fmt_date($l['due_date']) ?></td>
            <td><span class="badge <?= $cls ?>"><?= $label ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div style="display:flex;flex-direction:column;gap:22px">
    <div class="card">
      <div class="card-h"><h3>Books by Status</h3><div class="sub">Physical copies across the collection.</div></div>
      <div class="card-b">
        <div class="donut-wrap"><canvas id="statusDonut"></canvas></div>
        <div style="display:flex;flex-direction:column;gap:7px;margin-top:12px;font-size:12.5px;font-weight:600">
          <span style="display:flex;align-items:center;gap:8px"><i style="width:10px;height:10px;border-radius:3px;background:#7c3aed;display:inline-block"></i> Issued (<?= (int)$stats['on_loan'] - (int)$stats['overdue'] ?>)</span>
          <span style="display:flex;align-items:center;gap:8px"><i style="width:10px;height:10px;border-radius:3px;background:#34d399;display:inline-block"></i> Available (<?= $available ?>)</span>
          <span style="display:flex;align-items:center;gap:8px"><i style="width:10px;height:10px;border-radius:3px;background:#f87171;display:inline-block"></i> Overdue (<?= (int)$stats['overdue'] ?>)</span>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-h"><h3>Recent Activity</h3><div class="sub">What happened in the system lately.</div></div>
      <div class="card-b" style="padding-top:10px">
        <?php if (!$activity): ?>
          <div class="empty" style="padding:20px"><span class="big">🕘</span><strong>No activity yet</strong>Actions you take will appear here.</div>
        <?php else: ?>
          <?php foreach ($activity as $a): ?>
            <div style="display:flex;gap:10px;padding:7px 0;border-bottom:1px dashed #f0ecf9;font-size:12.8px">
              <span class="stat-ic purple" style="width:28px;height:28px;border-radius:8px"><?= icon('check', 15) ?></span>
              <div><strong><?= e($a['action']) ?></strong> <?= e($a['details']) ?><br><small class="muted" style="color:var(--muted)"><?= e($a['user_name'] ?: 'system') ?> · <?= fmt_dt($a['created_at']) ?></small></div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
window.STATUS_DATA = <?= json_encode([
    'labels' => ['Issued', 'Available', 'Overdue'],
    'values' => [(int)$stats['on_loan'] - (int)$stats['overdue'], $available, (int)$stats['overdue']],
    'colors' => ['#7c3aed', '#34d399', '#f87171'],
]) ?>;
LibCharts.donut('statusDonut', window.STATUS_DATA, <?= json_encode(number_format((int)$stats['total_copies'])) ?>, 'Copies');
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
