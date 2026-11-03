<?php
/** Member details: profile, reading activity, current loans, actions. */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$pdo = db();
$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT * FROM members WHERE id = ?');
$st->execute([$id]);
$m = $st->fetch();
if (!$m) { fail('Member not found.', 'borrowers.php'); }

$borrowed  = member_active_loans($pdo, $id);
$expiringSoon = ($m['status'] === 'Active') && strtotime($m['valid_till']) < strtotime('+30 days');

/* reading activity: loans in the last 6 months grouped by book category */
$act = $pdo->prepare("
    SELECT b.category, COUNT(*) AS c
    FROM loans l JOIN books b ON b.id = l.book_id
    WHERE l.member_id = ? AND l.issue_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY b.category ORDER BY c DESC
");
$act->execute([$id]);
$reading = $act->fetchAll();
$readingMax = $reading ? max(array_column($reading, 'c')) : 0;

/* current loans */
$loans = $pdo->prepare("
    SELECT l.*, b.title, b.author, b.cover, b.call_no
    FROM loans l JOIN books b ON b.id = l.book_id
    WHERE l.member_id = ? AND l.status = 'issued'
    ORDER BY l.due_date ASC
");
$loans->execute([$id]);
$loans = $loans->fetchAll();

$page_title = 'Member Details';
$active = 'borrowers';
include __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <div>
    <h1 class="page-title">Member Details</h1>
    <p class="page-sub">Profile, reading journey and current loans.</p>
  </div>
  <div class="spacer"></div>
  <div class="page-actions">
    <a class="btn ghost" href="borrowers.php"><?= icon('arrow', 16) ?> Directory</a>
    <a class="btn ghost" href="member_form.php?id=<?= (int)$m['id'] ?>"><?= icon('edit', 16) ?> Edit</a>
    <form method="post" action="actions/member_toggle.php">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
      <button class="btn <?= $m['status'] === 'Active' ? 'soft-red' : 'soft-green' ?>" type="submit">
        <?= $m['status'] === 'Active' ? 'Suspend' : 'Reactivate' ?></button>
    </form>
    <form method="post" action="actions/member_delete.php" data-confirm="Delete <?= e($m['name']) ?> and their history? This cannot be undone.">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
      <button class="btn soft-red" type="submit"><?= icon('trash', 16) ?></button>
    </form>
  </div>
</div>

<div class="grid-2" style="grid-template-columns:minmax(0,1.5fr) minmax(0,1fr)">
  <div style="display:flex;flex-direction:column;gap:22px">
    <div class="card">
      <div class="card-b detail-head">
        <?= avatar_html($m['name'], 84) ?>
        <div style="flex:1;min-width:230px">
          <h2 style="font-size:24px;font-weight:800"><?= e($m['name']) ?></h2>
          <div style="display:flex;gap:8px;margin:8px 0 14px;flex-wrap:wrap">
            <span class="badge b-gray"><?= e($m['member_code']) ?></span>
            <span class="badge <?= $m['status'] === 'Active' ? 'b-green' : 'b-red' ?>"><?= icon('check', 12) ?> <?= e($m['status']) ?> Member</span>
            <?php if ($expiringSoon): ?><span class="badge b-amber"><?= icon('alert', 12) ?> expires <?= fmt_date($m['valid_till']) ?></span><?php endif; ?>
          </div>
          <dl class="kv">
            <dt><?= icon('mail', 15) ?> Email</dt><dd><?= e($m['email']) ?></dd>
            <dt><?= icon('phone', 15) ?> Phone</dt><dd><?= e($m['phone'] ?: '—') ?></dd>
            <dt><?= icon('id', 15) ?> Membership</dt><dd><span class="badge b-purple"><?= e($m['membership']) ?></span></dd>
            <dt><?= icon('cal', 15) ?> Member since</dt><dd><?= fmt_date($m['member_since']) ?></dd>
            <dt><?= icon('cal', 15) ?> Valid till</dt><dd><?= fmt_date($m['valid_till']) ?></dd>
          </dl>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-h" style="display:flex;align-items:center">
        <div><h3>Currently borrowed</h3><div class="sub">Loan quota usage.</div></div>
        <strong style="margin-left:auto;font-size:20px"><?= $borrowed ?> / <?= (int)$m['max_books'] ?></strong>
      </div>
      <div class="card-b">
        <div class="progress"><span style="width:<?= min(100, ((int)$m['max_books'] > 0) ? round($borrowed / (int)$m['max_books'] * 100) : 0) ?>%"></span></div>
        <div style="margin-top:16px;display:flex;flex-direction:column;gap:4px">
          <?php if (!$loans): ?>
            <div class="empty" style="padding:18px"><span class="big" style="font-size:28px">📗</span><strong>No active loans</strong>This member has nothing checked out.</div>
          <?php else: foreach ($loans as $l): $late = days_late($l['due_date']); ?>
            <div class="watch-item">
              <div style="display:flex;gap:11px;align-items:center">
                <?= cover_html($l, 40, 58) ?>
                <div>
                  <div class="watch-title"><?= e($l['title']) ?></div>
                  <div class="watch-call"><?= e($l['author']) ?> · due <?= fmt_date($l['due_date']) ?></div>
                </div>
              </div>
              <div style="display:flex;gap:8px;align-items:center">
                <?php if (loan_is_overdue($l)): ?><span class="badge b-red"><?= $late ?>d late</span><?php endif; ?>
                <form class="loan-row-form" method="post" action="actions/return.php">
                  <?= csrf_field() ?>
                  <input type="hidden" name="loan_id" value="<?= (int)$l['id'] ?>">
                  <button class="btn soft-green sm" type="submit">Return</button>
                </form>
              </div>
            </div>
          <?php endforeach; endif; ?>
        </div>
        <div style="margin-top:16px">
          <a class="btn block" href="issue_return.php?member_id=<?= (int)$m['id'] ?>#issue"><?= icon('book', 17) ?> Issue a Book</a>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-h"><h3>Reading activity</h3><div class="sub">Loans in the last 6 months, by subject.</div></div>
    <div class="card-b">
      <?php if (!$reading): ?>
        <div class="empty" style="padding:18px"><span class="big" style="font-size:28px">📊</span><strong>No reading activity</strong>in the last 6 months.</div>
      <?php else: foreach ($reading as $r): ?>
        <div class="dist-row" style="grid-template-columns:92px 1fr auto">
          <span class="dist-name"><?= e($r['category']) ?></span>
          <span class="dist-track"><span class="dist-fill" style="width:<?= $readingMax > 0 ? max(6, round($r['c'] / $readingMax * 100)) : 0 ?>%"></span></span>
          <span class="dist-val"><?= (int)$r['c'] ?> books</span>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
