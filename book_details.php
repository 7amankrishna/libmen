<?php
/** Book details: full info + loan history for this title. */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$pdo = db();
$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT * FROM books WHERE id = ?');
$st->execute([$id]);
$book = $st->fetch();
if (!$book) { fail('Book not found.', 'books.php'); }

$available = book_available($pdo, $id);
$st = $pdo->prepare("SELECT COUNT(*) FROM loans WHERE book_id=? AND status='issued'");
$st->execute([$id]);
$onLoanNow = (int)$st->fetchColumn();
$st = $pdo->prepare('SELECT COUNT(*) FROM loans WHERE book_id=?');
$st->execute([$id]);
$timesBorrowed = (int)$st->fetchColumn();

$history = $pdo->prepare("
    SELECT l.*, m.name AS member_name, m.member_code
    FROM loans l JOIN members m ON m.id = l.member_id
    WHERE l.book_id = ?
    ORDER BY l.created_at DESC, l.id DESC
    LIMIT 30
");
$history->execute([$id]);
$history = $history->fetchAll();

$page_title = 'Book Details';
$active = 'books';
include __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <div>
    <h1 class="page-title">Book Details</h1>
    <p class="page-sub">Full record and borrowing history.</p>
  </div>
  <div class="spacer"></div>
  <div class="page-actions">
    <a class="btn ghost" href="books.php"><?= icon('arrow', 16) ?> Back to Books</a>
    <a class="btn ghost" href="book_form.php?id=<?= (int)$book['id'] ?>"><?= icon('edit', 16) ?> Edit</a>
    <form method="post" action="actions/book_delete.php" data-confirm="Delete “<?= e($book['title']) ?>”? This cannot be undone.">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)$book['id'] ?>">
      <button class="btn soft-red" type="submit"><?= icon('trash', 16) ?> Delete</button>
    </form>
  </div>
</div>

<div class="grid-2" style="grid-template-columns:minmax(0,1.4fr) minmax(0,1fr)">
  <div class="card">
    <div class="card-b detail-head">
      <div class="detail-cover"><?= cover_html($book, 150, 216) ?></div>
      <div style="flex:1;min-width:220px">
        <h2 style="font-size:24px;font-weight:800"><?= e($book['title']) ?></h2>
        <div style="color:var(--muted);font-weight:600;margin:2px 0 14px"><?= e($book['author']) ?></div>
        <dl class="kv">
          <dt><?= icon('barcode', 15) ?> Call No.</dt><dd><?= e($book['call_no'] ?: '—') ?></dd>
          <dt><?= icon('id', 15) ?> ISBN</dt><dd><?= e($book['isbn'] ?: '—') ?></dd>
          <dt><?= icon('grid', 15) ?> Category</dt><dd><span class="badge b-purple"><?= e($book['category']) ?></span></dd>
          <dt><?= icon('books', 15) ?> Copies</dt><dd><?= (int)$book['copies'] ?> total · <strong><?= $available ?></strong> available · <?= $onLoanNow ?> on loan</dd>
          <dt><?= icon('swap', 15) ?> Times borrowed</dt><dd><?= $timesBorrowed ?></dd>
          <dt><?= icon('cal', 15) ?> Added</dt><dd><?= fmt_dt($book['created_at']) ?></dd>
        </dl>
        <div style="margin-top:14px">
          <?php if ($available > 0): ?>
            <a class="btn" href="issue_return.php?book_id=<?= (int)$book['id'] ?>#issue"><?= icon('swap', 16) ?> Issue this book</a>
          <?php else: ?>
            <span class="badge b-amber"><?= icon('clock', 12) ?> All copies are on loan</span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-h"><h3>Availability</h3><div class="sub">Right now, for this title.</div></div>
    <div class="card-b">
      <div class="progress" style="height:12px"><span style="width:<?= ((int)$book['copies'] > 0) ? round($available / (int)$book['copies'] * 100) : 0 ?>%"></span></div>
      <p style="color:var(--muted);font-size:13px;margin:10px 0 0">
        <strong style="color:var(--ink)"><?= $available ?></strong> of <?= (int)$book['copies'] ?> copies available
        (<?= $onLoanNow ?> currently out).
      </p>
    </div>
  </div>
</div>

<div class="card mt">
  <div class="card-h"><h3>Loan history</h3><div class="sub">Every issue/return record for “<?= e($book['title']) ?>”.</div></div>
  <div class="card-b" style="padding-top:8px">
    <?php if (!$history): ?>
      <div class="empty"><span class="big">🔄</span><strong>Never borrowed</strong>This title has no loans yet.</div>
    <?php else: ?>
    <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Member</th><th>Issue Date</th><th>Due Date</th><th>Returned</th><th>Status</th><th>Fine</th></tr></thead>
      <tbody>
      <?php foreach ($history as $l): [$label, $cls] = loan_badge($l); ?>
        <tr class="<?= loan_is_overdue($l) ? 'overdue-row' : '' ?>">
          <td><a class="row-member" href="member_details.php?id=<?= (int)$l['member_id'] ?>" style="color:var(--ink)"><?= avatar_html($l['member_name'], 28) ?> <?= e($l['member_name']) ?> <span class="muted" style="font-weight:600;font-size:11.5px"><?= e($l['member_code']) ?></span></a></td>
          <td class="muted"><?= fmt_date($l['issue_date']) ?></td>
          <td class="muted"><?= fmt_date($l['due_date']) ?></td>
          <td class="muted"><?= fmt_date($l['return_date']) ?></td>
          <td><span class="badge <?= $cls ?>"><?= $label ?><?= loan_is_overdue($l) ? ' · ' . days_late($l['due_date']) . 'd late' : '' ?></span></td>
          <td class="num"><?= (float)$l['fine'] > 0 ? money($l['fine']) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
