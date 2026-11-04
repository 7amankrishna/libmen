<?php
/** Add / edit a book (incl. optional cover upload). */
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
csrf_verify();

$pdo = db();

$id      = (int)($_POST['id'] ?? 0);
$title   = trim($_POST['title'] ?? '');
$author  = trim($_POST['author'] ?? '');
$isbn    = trim($_POST['isbn'] ?? '');
$callNo  = trim($_POST['call_no'] ?? '');
$category= trim($_POST['category'] ?? '') ?: 'Other';
$copies  = (int)($_POST['copies'] ?? 1);

if ($title === '' || $author === '') fail('Title and author are required.', 'book_form.php');
if ($copies < 1 || $copies > 10000)  fail('Copies must be between 1 and 10000.', 'book_form.php');
$isbn   = $isbn === '' ? null : $isbn;
$callNo = $callNo === '' ? null : $callNo;

/* unique ISBN (excluding the row being edited) */
if ($isbn !== null) {
    $st = $pdo->prepare('SELECT id FROM books WHERE isbn = ? AND id <> ?');
    $st->execute([$isbn, $id]);
    if ($st->fetch()) fail('This ISBN already belongs to another book.', $id ? "book_form.php?id=$id" : 'book_form.php');
}

$existing = null;
if ($id > 0) {
    $st = $pdo->prepare('SELECT * FROM books WHERE id = ?');
    $st->execute([$id]);
    $existing = $st->fetch();
    if (!$existing) fail('Book not found.', 'books.php');
    /* cannot reduce copies below the number currently on loan */
    $st = $pdo->prepare("SELECT COUNT(*) FROM loans WHERE book_id=? AND status='issued'");
    $st->execute([$id]);
    $onLoan = (int)$st->fetchColumn();
    if ($copies < $onLoan) fail("Only $copies copies set, but $onLoan copies are currently on loan.", "book_form.php?id=$id");
}

/* ---- optional cover upload ---- */
$coverPath = $existing['cover'] ?? null;
if (isset($_FILES['cover']) && $_FILES['cover']['error'] === UPLOAD_ERR_OK) {
    $f = $_FILES['cover'];
    if ($f['size'] > 2 * 1024 * 1024) {
        fail('Cover image is larger than 2 MB.', $id ? "book_form.php?id=$id" : 'book_form.php');
    }
    $mime = '';
    if (class_exists('finfo')) {
        $fi = new finfo(FILEINFO_MIME_TYPE);
        $mime = $fi->file($f['tmp_name']);
    } else {
        $mime = $f['type'];
    }
    $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    if (!isset($extMap[$mime])) {
        fail('Cover must be a JPG, PNG, GIF or WebP image.', $id ? "book_form.php?id=$id" : 'book_form.php');
    }
    $dir = __DIR__ . '/../uploads/covers';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = 'cover_' . ($id > 0 ? $id : 'new') . '_' . time() . '.' . $extMap[$mime];
    $dest = $dir . '/' . $name;
    if (move_uploaded_file($f['tmp_name'], $dest)) {
        if ($coverPath && is_file(__DIR__ . '/../' . $coverPath) && $coverPath !== 'uploads/covers/' . $name) {
            @unlink(__DIR__ . '/../' . $coverPath);
        }
        $coverPath = 'uploads/covers/' . $name;
    } else {
        flash('Book saved, but the cover image could not be stored (check that uploads/covers is writable).', 'error');
    }
}

try {
    if ($existing) {
        $st = $pdo->prepare('UPDATE books SET title=?, author=?, isbn=?, call_no=?, category=?, copies=?, cover=? WHERE id=?');
        $st->execute([$title, $author, $isbn, $callNo, $category, $copies, $coverPath, $id]);
        log_activity('book.updated', "$title (id $id)");
        flash('Book updated successfully.');
        redirect('book_details.php?id=' . $id);
    } else {
        $st = $pdo->prepare('INSERT INTO books (title, author, isbn, call_no, category, copies, cover) VALUES (?,?,?,?,?,?,?)');
        $st->execute([$title, $author, $isbn, $callNo, $category, $copies, $coverPath]);
        $newId = (int)$pdo->lastInsertId();
        log_activity('book.added', "$title — $author (id $newId)");
        flash('Book added to the collection.');
        redirect('book_details.php?id=' . $newId);
    }
} catch (PDOException $e) {
    if ($e->getCode() === '23000') fail('A book with this ISBN already exists.', $id ? "book_form.php?id=$id" : 'book_form.php');
    throw $e;
}
