<?php
/** Add / edit a book. POSTs to actions/book_save.php */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$pdo = db();
$id = (int)($_GET['id'] ?? 0);
$book = null;
if ($id > 0) {
    $st = $pdo->prepare('SELECT * FROM books WHERE id = ?');
    $st->execute([$id]);
    $book = $st->fetch();
    if (!$book) { fail('Book not found.', 'books.php'); }
}

$categories = $pdo->query("SELECT DISTINCT category FROM books WHERE category <> '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);

$page_title = $book ? 'Edit Book' : 'Add New Book';
$active = 'books';
include __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <div>
    <h1 class="page-title"><?= $book ? 'Edit Book' : 'Add New Book' ?></h1>
    <p class="page-sub"><?= $book ? 'Update the details of this title.' : 'Add a new title to the collection.' ?></p>
  </div>
  <div class="spacer"></div>
  <a class="btn ghost" href="<?= $book ? 'book_details.php?id=' . (int)$book['id'] : 'books.php' ?>"><?= icon('x', 16) ?> Cancel</a>
</div>

<div class="card">
  <div class="card-b">
    <form method="post" action="actions/book_save.php" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= $book ? (int)$book['id'] : '' ?>">
      <div class="form-grid">
        <div class="field">
          <label>Title *</label>
          <input class="input" name="title" required maxlength="255" value="<?= e($book['title'] ?? '') ?>" placeholder="e.g. Clean Code">
        </div>
        <div class="field">
          <label>Author *</label>
          <input class="input" name="author" required maxlength="255" value="<?= e($book['author'] ?? '') ?>" placeholder="e.g. Robert C. Martin">
        </div>
        <div class="field">
          <label>ISBN</label>
          <input class="input" name="isbn" maxlength="20" value="<?= e($book['isbn'] ?? '') ?>" placeholder="e.g. 9780132350884">
          <span class="hint">Optional, but must be unique if provided.</span>
        </div>
        <div class="field">
          <label>Call No.</label>
          <input class="input" name="call_no" maxlength="60" value="<?= e($book['call_no'] ?? '') ?>" placeholder="e.g. TEC 005.133">
        </div>
        <div class="field">
          <label>Category / Subject *</label>
          <input class="input" name="category" required maxlength="60" list="catlist" value="<?= e($book['category'] ?? '') ?>" placeholder="e.g. Technology">
          <datalist id="catlist">
            <?php foreach ($categories as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?>
          </datalist>
          <span class="hint">Type a new subject or pick an existing one.</span>
        </div>
        <div class="field">
          <label>Copies *</label>
          <input class="input" type="number" name="copies" required min="1" max="10000" value="<?= e($book['copies'] ?? 1) ?>">
          <span class="hint">Total physical copies the library owns.</span>
        </div>
        <div class="field full">
          <label>Cover image (optional)</label>
          <div class="filebox">
            <?php if ($book && !empty($book['cover']) && is_file(__DIR__ . '/' . $book['cover'])): ?>
              <img class="cover" style="width:56px;height:80px" src="<?= e($book['cover']) ?>" alt="current cover">
            <?php else: ?>
              <?= cover_html($book ?: ['title' => $_POST['title'] ?? 'Book'], 56, 80) ?>
            <?php endif; ?>
            <div style="flex:1">
              <input type="file" name="cover" accept="image/jpeg,image/png,image/gif,image/webp">
              <div class="hint" style="margin-top:5px">JPG / PNG / GIF / WebP, max 2 MB. Leave empty to keep the current cover (or use the auto-generated one).</div>
            </div>
          </div>
        </div>
      </div>
      <div class="form-foot">
        <a class="btn ghost" href="<?= $book ? 'book_details.php?id=' . (int)$book['id'] : 'books.php' ?>">Cancel</a>
        <button class="btn" type="submit"><?= icon('check', 17) ?> <?= $book ? 'Save Changes' : 'Add Book' ?></button>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
