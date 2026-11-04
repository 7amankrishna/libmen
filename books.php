<?php
/** Books — the collection: browse, search, filter, sort, manage titles. */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$pdo = db();

$q    = trim($_GET['q'] ?? '');
$cat  = trim($_GET['cat'] ?? '');
$sort = $_GET['sort'] ?? 'availability';
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = 6;

/* category chips come from the data itself (no hard-coded dummy list) */
$categories = $pdo->query("SELECT DISTINCT category FROM books WHERE category <> '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);

$where = [];
$args  = [];
if ($q !== '') {
    $where[] = '(b.title LIKE ? OR b.author LIKE ? OR b.isbn LIKE ? OR b.call_no LIKE ?)';
    $like = '%' . $q . '%';
    array_push($args, $like, $like, $like, $like);
}
if ($cat !== '' && $cat !== 'All') {
    $where[] = 'b.category = ?';
    $args[] = $cat;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sorts = [
    'availability' => 'available DESC, b.title ASC',
    'title'        => 'b.title ASC',
    'newest'       => 'b.id DESC',
    'category'     => 'b.category ASC, b.title ASC',
];
$order = $sorts[$sort] ?? $sorts['availability'];

$total = (int)$pdo->prepare("SELECT COUNT(*) FROM books b $whereSql");
$total->execute($args);
$total = (int)$total->fetchColumn();

$st = $pdo->prepare("
    SELECT b.*,
           b.copies - (SELECT COUNT(*) FROM loans l WHERE l.book_id = b.id AND l.status='issued') AS available
    FROM books b
    $whereSql
    ORDER BY $order
    LIMIT $per OFFSET " . (($page - 1) * $per)
);
$st->execute($args);
$books = $st->fetchAll();

/* header mini-stats + right rail figures */
$agg = $pdo->query("
    SELECT COUNT(*) AS titles, COALESCE(SUM(copies),0) AS copies,
           COALESCE(SUM(copies),0) - (SELECT COUNT(*) FROM loans WHERE status='issued') AS available
    FROM books
")->fetch();
$onLoan = (int)$pdo->query("SELECT COUNT(*) FROM loans WHERE status='issued'")->fetchColumn();

$dist = $pdo->query("
    SELECT category, SUM(copies) AS c FROM books GROUP BY category ORDER BY c DESC
")->fetchAll();

$lowStock = $pdo->query("
    SELECT b.title, b.call_no,
           b.copies - (SELECT COUNT(*) FROM loans l WHERE l.book_id=b.id AND l.status='issued') AS available
    FROM books b
    HAVING available <= " . LOW_STOCK_LIMIT . "
    ORDER BY available ASC, b.title LIMIT 6
")->fetchAll();

$availPct = ((int)$agg['copies'] > 0) ? (int)round(((int)$agg['available'] / (int)$agg['copies']) * 100) : 0;

/* keep filter params in pagination links */
$base = 'books.php?' . http_build_query(['q' => $q, 'cat' => $cat, 'sort' => $sort]);

$page_title = 'Books';
$active = 'books';
$load_charts = true;
include __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <div>
    <h1 class="page-title">The Collection</h1>
    <p class="page-sub">Every story, beautifully organized.</p>
  </div>
  <div class="spacer"></div>
  <div class="page-actions">
    <a class="btn" href="book_form.php"><?= icon('plus', 18) ?> Add New Book</a>
  </div>
</div>

<div class="mini-stats">
  <div class="card mini-stat"><span class="stat-ic purple"><?= icon('book', 21) ?></span>
    <div><div class="stat-label">Total titles</div><div class="stat-value"><?= number_format((int)$agg['titles']) ?></div></div></div>
  <div class="card mini-stat"><span class="stat-ic green"><?= icon('check', 21) ?></span>
    <div><div class="stat-label">Available</div><div class="stat-value"><?= number_format((int)$agg['available']) ?></div></div></div>
  <div class="card mini-stat"><span class="stat-ic amber"><?= icon('books', 21) ?></span>
    <div><div class="stat-label">On loan</div><div class="stat-value"><?= number_format($onLoan) ?></div></div></div>
</div>

<div class="toolbar">
  <form class="searchbar" method="get" action="books.php">
    <?= icon('search', 17) ?>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search title, ISBN, author…">
    <?php if ($cat !== '' && $cat !== 'All'): ?><input type="hidden" name="cat" value="<?= e($cat) ?>"><?php endif; ?>
    <?php if ($sort !== 'availability'): ?><input type="hidden" name="sort" value="<?= e($sort) ?>"><?php endif; ?>
  </form>
  <form method="get" action="books.php" data-autosubmit style="display:flex;align-items:center;gap:8px">
    <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
    <?php if ($cat !== '' && $cat !== 'All'): ?><input type="hidden" name="cat" value="<?= e($cat) ?>"><?php endif; ?>
    <span class="select-wrap">Sort by:
      <select class="input" name="sort">
        <?php foreach (['availability' => 'Availability', 'title' => 'Title A–Z', 'newest' => 'Newest', 'category' => 'Category'] as $k => $v): ?>
          <option value="<?= $k ?>" <?= $sort === $k ? 'selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </span>
  </form>
</div>

<div class="chips" style="margin-bottom:18px">
  <a class="chip <?= ($cat === '' || $cat === 'All') ? 'on' : '' ?>" href="books.php?<?= e(http_build_query(['q' => $q, 'sort' => $sort])) ?>">All</a>
  <?php foreach ($categories as $c): ?>
    <a class="chip <?= $cat === $c ? 'on' : '' ?>" href="books.php?<?= e(http_build_query(['q' => $q, 'cat' => $c, 'sort' => $sort])) ?>"><?= e($c) ?></a>
  <?php endforeach; ?>
</div>

<div class="layout-books">
  <div>
    <?php if (!$books): ?>
      <div class="card empty">
        <span class="big">📖</span>
        <strong>No books found</strong>
        <?= $total === 0 && $q === '' && ($cat === '' || $cat === 'All')
            ? 'The collection is empty — add your first book to get started.'
            : 'Try a different search or filter.' ?>
        <div style="margin-top:14px"><a class="btn" href="book_form.php"><?= icon('plus', 17) ?> Add New Book</a></div>
      </div>
    <?php else: ?>
      <div class="book-grid">
        <?php foreach ($books as $b):
            $av = (int)$b['available'];
            if ($av <= 0)      { $badge = ['On Loan', 'b-amber']; }
            elseif ($av <= LOW_STOCK_LIMIT) { $badge = ['Low · ' . $av . ' left', 'b-red']; }
            else               { $badge = ['Available', 'b-green']; }
        ?>
          <div class="book-card">
            <a href="book_details.php?id=<?= (int)$b['id'] ?>"><?= cover_html($b, 84, 122) ?></a>
            <div class="book-info">
              <div class="book-title"><?= e($b['title']) ?></div>
              <div class="book-author"><?= e($b['author']) ?></div>
              <div class="book-call"><?= icon('barcode', 14) ?> Call No: <?= e($b['call_no'] ?: '—') ?></div>
              <span class="badge <?= $badge[1] ?>" style="align-self:flex-start"><?= icon($av > 0 ? 'check' : 'clock', 12) ?> <?= $badge[0] ?></span>
              <div class="book-copies"><?= icon('books', 14) ?> Copies: <?= (int)$b['copies'] ?> (<?= $av ?> available)</div>
              <div class="book-actions">
                <a class="btn ghost sm" href="book_form.php?id=<?= (int)$b['id'] ?>"><?= icon('edit', 14) ?> Edit</a>
                <a class="btn sm" href="book_details.php?id=<?= (int)$b['id'] ?>"><?= icon('eye', 14) ?> Details</a>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="table-foot">
        <span class="showing">Showing <?= count($books) ? (($page - 1) * $per + 1) . '–' . min($total, $page * $per) : 0 ?> of <?= $total ?> titles</span>
        <?= page_links($base, $page, max(1, (int)ceil($total / $per))) ?>
      </div>
    <?php endif; ?>
  </div>

  <aside class="rail">
    <div class="card">
      <div class="card-h"><h3>Collection health</h3><div class="sub">A quick look at your library's collection.</div></div>
      <div class="card-b">
        <div class="donut-wrap"><canvas id="healthDonut"></canvas></div>
        <p class="rail-note"><strong>Overall availability</strong><br>
          <?= number_format((int)$agg['available']) ?> of <?= number_format((int)$agg['copies']) ?> copies are currently available.</p>
      </div>
    </div>

    <div class="card">
      <div class="card-h"><h3>Collection distribution</h3><div class="sub">Share of copies per subject.</div></div>
      <div class="card-b">
        <?php if (!$dist): ?>
          <div class="empty" style="padding:14px"><span class="big" style="font-size:26px">🏷️</span>No categories yet.</div>
        <?php else: foreach ($dist as $d):
            $share = ((int)$agg['copies'] > 0) ? round(((int)$d['c'] / (int)$agg['copies']) * 100) : 0; ?>
          <div class="dist-row">
            <span class="dist-name"><?= e($d['category']) ?></span>
            <span class="dist-track"><span class="dist-fill" style="width:<?= max(3, $share) ?>%"></span></span>
            <span class="dist-val"><?= $share ?>% (<?= (int)$d['c'] ?>)</span>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-h"><h3>⚠️ Low-stock watchlist</h3><div class="sub">Titles with <?= LOW_STOCK_LIMIT ?> or fewer available copies.</div></div>
      <div class="card-b" style="padding-top:10px">
        <?php if (!$lowStock): ?>
          <div class="empty" style="padding:14px"><span class="big" style="font-size:26px">✅</span>All titles are well stocked.</div>
        <?php else: foreach ($lowStock as $w): ?>
          <div class="watch-item">
            <div><div class="watch-title"><?= e($w['title']) ?></div><div class="watch-call"><?= e($w['call_no'] ?: 'no call no') ?></div></div>
            <span class="badge <?= (int)$w['available'] === 0 ? 'b-red' : 'b-amber' ?>"><?= (int)$w['available'] ?> available</span>
          </div>
        <?php endforeach; endif; ?>
        <div style="margin-top:14px;text-align:center"><a class="btn ghost sm" href="reports.php">View Full Report <?= icon('arrow', 14) ?></a></div>
      </div>
    </div>
  </aside>
</div>

<script>
LibCharts.donut('healthDonut',
  { labels: ['Available', 'On loan'], values: [<?= (int)$agg['available'] ?>, <?= $onLoan ?>], colors: ['#7c3aed', '#c4b5fd'] },
  <?= json_encode($availPct . '%') ?>, 'available');
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
