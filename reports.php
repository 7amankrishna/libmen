<?php
/**
 * Reports / Analytics — circulation trends, subjects, overdue follow-ups,
 * computed insights, CSV & PDF export. Everything is derived live from the
 * loans/books/members tables for the selected date range.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$pdo = db();

$range    = $_GET['range'] ?? '30';
[$rangeLabel, $start, $days] = range_bounds($range);
$today    = date('Y-m-d');

$issueWhere = $start ? 'issue_date >= ?' : '1=1';
$retWhere   = $start ? 'return_date >= ?' : '1=1';

/* ---------- headline stats for the selected range ---------- */
$circulation = (int)$pdo->prepare("SELECT COUNT(*) FROM loans WHERE $issueWhere");
$circulation->execute($start ? [$start] : []);
$circulation = (int)$circulation->fetchColumn();

$returnsSt = $pdo->prepare("SELECT COUNT(*) FROM loans WHERE status='returned' AND $retWhere");
$returnsSt->execute($start ? [$start] : []);
$returns = (int)$returnsSt->fetchColumn();

/* loans in range that are overdue now or were returned late */
$overdueSt = $pdo->prepare("
    SELECT COUNT(*) FROM loans
    WHERE $issueWhere
      AND ( (status='issued'   AND due_date < CURDATE())
         OR (status='returned' AND return_date > due_date) )
");
$overdueSt->execute($start ? [$start] : []);
$overdueInRange = (int)$overdueSt->fetchColumn();
$overdueRate = $circulation > 0 ? round($overdueInRange / $circulation * 100, 1) : 0.0;

$activeMembers = (int)$pdo->query("SELECT COUNT(*) FROM members WHERE status='Active'")->fetchColumn();

/* previous equal-length window for deltas */
$prev = null;
if ($start && $days) {
    $prevStart = date('Y-m-d', strtotime($start . " -$days days"));
    $prevEnd   = date('Y-m-d', strtotime("$start -1 day"));
    $pCirc = (int)$pdo->prepare("SELECT COUNT(*) FROM loans WHERE issue_date BETWEEN ? AND ?");
    $pCirc->execute([$prevStart, $prevEnd]); $pCirc = (int)$pCirc->fetchColumn();
    $pRet = (int)$pdo->prepare("SELECT COUNT(*) FROM loans WHERE status='returned' AND return_date BETWEEN ? AND ?");
    $pRet->execute([$prevStart, $prevEnd]); $pRet = (int)$pRet->fetchColumn();
    $pOver = (int)$pdo->prepare("
        SELECT COUNT(*) FROM loans WHERE issue_date BETWEEN ? AND ?
          AND ( (status='issued' AND due_date < CURDATE()) OR (status='returned' AND return_date > due_date) )");
    $pOver->execute([$prevStart, $prevEnd]); $pOver = (int)$pOver->fetchColumn();
    $pRate = $pCirc > 0 ? round($pOver / $pCirc * 100, 1) : 0.0;
    $pNew = (int)$pdo->prepare("SELECT COUNT(*) FROM members WHERE member_since BETWEEN ? AND ?");
    $pNew->execute([$prevStart, $prevEnd]); $pNew = (int)$pNew->fetchColumn();
    $cNew = (int)$pdo->prepare("SELECT COUNT(*) FROM members WHERE member_since BETWEEN ? AND ?");
    $cNew->execute([$start, $today]); $cNew = (int)$cNew->fetchColumn();
    $prev = ['circ' => $pCirc, 'ret' => $pRet, 'rate' => $pRate, 'new' => $pNew, 'cnew' => $cNew];
}

function pct_delta(int $now, int $before): ?array {
    if ($before <= 0) return $now > 0 ? ['+100%', 'up'] : null;
    $d = round(($now - $before) / $before * 100, 1);
    return [($d >= 0 ? '+' : '') . $d . '%', $d >= 0 ? 'up' : 'down'];
}

/* ---------- daily series (loans & returns) ---------- */
$loanRows = $pdo->prepare("SELECT issue_date AS d, COUNT(*) AS c FROM loans WHERE $issueWhere GROUP BY d");
$loanRows->execute($start ? [$start] : []);
$loanMap = [];
foreach ($loanRows->fetchAll() as $r) $loanMap[$r['d']] = (int)$r['c'];

$retRows = $pdo->prepare("SELECT return_date AS d, COUNT(*) AS c FROM loans WHERE status='returned' AND $retWhere GROUP BY d");
$retRows->execute($start ? [$start] : []);
$retMap = [];
foreach ($retRows->fetchAll() as $r) $retMap[$r['d']] = (int)$r['c'];

$labels = []; $loansSeries = []; $returnsSeries = [];
if ($start) {
    $cursor = strtotime($start);
    $end = strtotime($today);
    while ($cursor <= $end) {
        $d = date('Y-m-d', $cursor);
        $labels[] = date('j M', $cursor);
        $loansSeries[] = $loanMap[$d] ?? 0;
        $returnsSeries[] = $retMap[$d] ?? 0;
        $cursor = strtotime('+1 day', $cursor);
    }
} else {
    $all = array_unique(array_merge(array_keys($loanMap), array_keys($retMap)));
    sort($all);
    foreach ($all as $d) {
        $labels[] = date('j M', strtotime($d));
        $loansSeries[] = $loanMap[$d] ?? 0;
        $returnsSeries[] = $retMap[$d] ?? 0;
    }
}

/* ---------- most-read subjects ---------- */
$subjSt = $pdo->prepare("
    SELECT b.category, COUNT(*) AS c
    FROM loans l JOIN books b ON b.id = l.book_id
    WHERE $issueWhere
    GROUP BY b.category ORDER BY c DESC
");
$subjSt->execute($start ? [$start] : []);
$subjects = $subjSt->fetchAll();

/* ---------- overdue follow-ups ---------- */
$overdueList = $pdo->query("
    SELECT l.*, b.title, b.author, m.name AS member_name, m.member_code, m.email, m.phone
    FROM loans l
    JOIN books b ON b.id = l.book_id
    JOIN members m ON m.id = l.member_id
    WHERE l.status='issued' AND l.due_date < CURDATE()
    ORDER BY l.due_date ASC
")->fetchAll();

/* ---------- actionable insights (computed) ---------- */
$insights = [];

/* 1. peak checkout weekday */
$wdSt = $pdo->prepare("SELECT DAYOFWEEK(issue_date) AS wd, COUNT(*) AS c FROM loans WHERE $issueWhere GROUP BY wd");
$wdSt->execute($start ? [$start] : []);
$wdCounts = [];
foreach ($wdSt->fetchAll() as $r) $wdCounts[(int)$r['wd']] = (int)$r['c'];   // 1=Sun..7=Sat
$dayNames = [2 => 'Mondays', 3 => 'Tuesdays', 4 => 'Wednesdays', 5 => 'Thursdays', 6 => 'Fridays', 7 => 'Saturdays', 1 => 'Sundays'];
if ($start && $days && $circulation > 0) {
    // occurrences of each weekday inside the range
    $occ = array_fill(1, 7, 0);
    $cursor = strtotime($start); $end = strtotime($today);
    while ($cursor <= $end) {
        $dow = (int)date('N', $cursor) + 1;          // date('N'): 1=Mon..7=Sun
        if ($dow > 7) $dow = 1;                       // Sunday -> DAYOFWEEK()=1
        $occ[$dow]++;
        $cursor = strtotime('+1 day', $cursor);
    }
    $bestWd = null; $bestAvg = 0;
    foreach ($wdCounts as $wd => $c) {
        $avg = $occ[$wd] > 0 ? $c / $occ[$wd] : 0;
        if ($avg > $bestAvg) { $bestAvg = $avg; $bestWd = $wd; }
    }
    $dailyAvg = $circulation / max(1, $days);
    if ($bestWd && $dailyAvg > 0) {
        $pct = round(($bestAvg - $dailyAvg) / $dailyAvg * 100);
        $insights[] = ['cal', 'Peak checkouts on ' . rtrim($dayNames[$bestWd], 's') . 's',
            ucfirst(rtrim($dayNames[$bestWd], 's')) . 's average ' . round($bestAvg, 1) . ' loans, ' .
            ($pct >= 0 ? '~' . $pct . '% higher' : abs($pct) . '% lower') . ' than the daily average (' . round($dailyAvg, 1) . ').'];
    }
}

/* 2. top subject share */
if ($subjects && $circulation > 0) {
    $top = $subjects[0];
    $share = round($top['c'] / $circulation * 100);
    $insights[] = ['laptop', $top['category'] . ' leads ' . $share . '%',
        $top['category'] . ' accounts for ' . (int)$top['c'] . ' of ' . $circulation . ' checkouts (' . $share . '%).'];
}

/* 3. overdue now */
$activeLoans = (int)$pdo->query("SELECT COUNT(*) FROM loans WHERE status='issued'")->fetchColumn();
$overdueNow  = count($overdueList);
if ($activeLoans > 0) {
    $rate = round($overdueNow / $activeLoans * 100, 1);
    $insights[] = ['alert', 'Overdue rate is ' . $rate . '%',
        $overdueNow . ' of ' . $activeLoans . ' active loans are past their due date. Send reminders below.'];
}

/* 4. expiring memberships */
$expiring = (int)$pdo->query(
    "SELECT COUNT(*) FROM members WHERE status='Active' AND valid_till BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)"
)->fetchColumn();
if ($expiring > 0) {
    $insights[] = ['cal', $expiring . ' memberships expire soon',
        $expiring . ' active memberships end within the next 30 days — renew them from the Borrowers page.'];
}

/* 5. low stock */
$lowStock = (int)$pdo->query("
    SELECT COUNT(*) FROM books b
    WHERE b.copies - (SELECT COUNT(*) FROM loans l WHERE l.book_id=b.id AND l.status='issued') <= " . LOW_STOCK_LIMIT
)->fetchColumn();
if ($lowStock > 0) {
    $insights[] = ['books', $lowStock . ' titles are low on copies',
        $lowStock . ' titles have ' . LOW_STOCK_LIMIT . ' or fewer available copies — consider ordering more.'];
}
$insights = array_slice($insights, 0, 4);

$page_title = 'Reports / Analytics';
$active = 'reports';
$load_charts = true;
include __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <div>
    <h1 class="page-title">The library, in perspective</h1>
    <p class="page-sub">From daily movement to meaningful patterns.</p>
  </div>
  <div class="spacer"></div>
  <div class="page-actions">
    <form method="get" data-autosubmit style="display:flex;align-items:center;gap:8px">
      <span class="select-wrap"><?= icon('cal', 16) ?>
        <select class="input" name="range">
          <?php foreach (['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days', '365' => 'Last 365 days', 'all' => 'All time'] as $k => $v): ?>
            <option value="<?= $k ?>" <?= $range === $k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </span>
    </form>
    <a class="btn ghost" href="actions/export_csv.php?range=<?= e($range) ?>&type=loans"><?= icon('csv', 17) ?> Export CSV</a>
    <a class="btn" href="actions/export_pdf.php?range=<?= e($range) ?>"><?= icon('pdf', 17) ?> Download PDF</a>
  </div>
</div>

<div class="grid-4">
  <div class="card stat">
    <span class="stat-ic purple"><?= icon('book', 24) ?></span>
    <div><div class="stat-label">Circulation</div><div class="stat-value"><?= number_format($circulation) ?></div>
      <div class="stat-delta"><?php $d = $prev ? pct_delta($circulation, $prev['circ']) : null;
        if ($d): ?><span class="delta <?= $d[1] ?>"><?= $d[0] ?></span><span class="delta-note">vs. previous period</span><?php endif; ?></div></div>
  </div>
  <div class="card stat">
    <span class="stat-ic green"><?= icon('swap', 24) ?></span>
    <div><div class="stat-label">Returns</div><div class="stat-value"><?= number_format($returns) ?></div>
      <div class="stat-delta"><?php $d = $prev ? pct_delta($returns, $prev['ret']) : null;
        if ($d): ?><span class="delta <?= $d[1] ?>"><?= $d[0] ?></span><span class="delta-note">vs. previous period</span><?php endif; ?></div></div>
  </div>
  <div class="card stat">
    <span class="stat-ic red"><?= icon('alert', 24) ?></span>
    <div><div class="stat-label">Overdue rate</div><div class="stat-value"><?= $overdueRate ?>%</div>
      <div class="stat-delta"><?php if ($prev): $pp = round($overdueRate - $prev['rate'], 1); ?>
        <span class="delta <?= $pp <= 0 ? 'up' : 'down' ?>"><?= ($pp >= 0 ? '+' : '') . $pp ?> pp</span><span class="delta-note">vs. previous period</span><?php endif; ?></div></div>
  </div>
  <div class="card stat">
    <span class="stat-ic blue"><?= icon('users', 24) ?></span>
    <div><div class="stat-label">Active members</div><div class="stat-value"><?= number_format($activeMembers) ?></div>
      <div class="stat-delta"><?php $d = $prev ? pct_delta($prev['cnew'], $prev['new']) : null;
        if ($d): ?><span class="delta <?= $d[1] ?>"><?= $d[0] ?></span><span class="delta-note">new signups vs. previous period</span><?php endif; ?></div></div>
  </div>
</div>

<div class="grid-2 mt" style="grid-template-columns:minmax(0,1.55fr) minmax(0,1fr)">
  <div class="card">
    <div class="card-h"><h3>Borrowing rhythm</h3><div class="sub">Daily loans and returns — <?= e(strtolower($rangeLabel)) ?>.</div></div>
    <div class="card-b"><div class="chart-box"><canvas id="rhythmChart"></canvas></div></div>
  </div>
  <div class="card">
    <div class="card-h"><h3>Most-read subjects</h3><div class="sub">Checkouts by subject — <?= e(strtolower($rangeLabel)) ?>.</div></div>
    <div class="card-b"><div class="chart-box"><canvas id="subjectChart"></canvas></div></div>
  </div>
</div>

<div class="grid-2 mt" style="grid-template-columns:minmax(0,1.55fr) minmax(0,1fr)" id="overdue">
  <div class="card">
    <div class="card-h"><h3>Overdue follow-ups</h3><div class="sub">Members with items past the due date, with suggested contact action.</div></div>
    <div class="card-b" style="padding-top:6px">
      <?php if (!$overdueList): ?>
        <div class="empty"><span class="big">🎉</span><strong>No overdue items</strong>Every borrowed book is within its due date.</div>
      <?php else: ?>
      <div class="tbl-wrap">
      <table class="tbl">
        <thead><tr><th>Member</th><th>Title</th><th>Days late</th><th>Contact action</th></tr></thead>
        <tbody>
        <?php foreach ($overdueList as $l): $late = days_late($l['due_date']); ?>
          <tr class="overdue-row">
            <td><div class="row-member" style="font-size:13px"><?= avatar_html($l['member_name'], 30) ?>
              <div><div style="font-weight:700"><?= e($l['member_name']) ?></div><div class="row-sub"><?= e($l['member_code']) ?> · <?= e($l['email']) ?></div></div></div></td>
            <td><div class="row-title" style="font-size:13px"><?= e($l['title']) ?></div><div class="row-sub"><?= e($l['author']) ?></div></td>
            <td><span class="badge b-red"><?= $late ?></span></td>
            <td>
              <?php if ($l['reminded_at']): ?>
                <span class="badge b-green"><?= icon('check', 12) ?> Reminder sent <?= fmt_dt($l['reminded_at']) ?></span>
              <?php else: ?>
                <form class="loan-row-form" method="post" action="actions/reminder.php">
                  <?= csrf_field() ?>
                  <input type="hidden" name="loan_id" value="<?= (int)$l['id'] ?>">
                  <button class="btn ghost sm" type="submit"><?= icon('remind', 14) ?> Send reminder</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-h"><h3>Actionable insights</h3><div class="sub">Computed live from your data.</div></div>
    <div class="card-b" style="padding-top:12px">
      <?php if (!$insights): ?>
        <div class="empty" style="padding:18px"><span class="big" style="font-size:28px">💡</span><strong>No insights yet</strong>Add books, members and loans — insights appear here automatically.</div>
      <?php else: foreach ($insights as $i): [$ic, $head, $body] = $i;
          $soft = ['cal' => ['purple', 'cal'], 'laptop' => ['green', 'laptop'], 'alert' => ['red', 'alert'], 'books' => ['amber', 'books']][$ic] ?? ['purple', 'trend']; ?>
        <div class="insight">
          <span class="insight-ic <?= $soft[0] === 'purple' ? 'purple' : ($soft[0] === 'green' ? 'green' : ($soft[0] === 'red' ? 'red' : 'amber')) ?>" style="background:var(--<?= $soft[0] === 'purple' ? 'primary-soft' : ($soft[0] === 'green' ? 'green-bg' : ($soft[0] === 'red' ? 'red-bg' : 'amber-bg')) ?>);color:var(--<?= $soft[0] ?>)"><?= icon($soft[1], 21) ?></span>
          <div><h4><?= e($head) ?></h4><p><?= e($body) ?></p></div>
          <span class="chev"><?= icon('arrow', 16) ?></span>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

<script>
LibCharts.line('rhythmChart',
  <?= json_encode($labels) ?>,
  <?= json_encode($loansSeries) ?>,
  <?= json_encode($returnsSeries) ?>);
LibCharts.bars('subjectChart',
  <?= json_encode(array_column($subjects, 'category')) ?>,
  <?= json_encode(array_map(function ($s) { return (int)$s['c']; }, $subjects)) ?>);
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
