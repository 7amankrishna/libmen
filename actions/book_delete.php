<?php
/** Delete a book — only possible when it has no loan history. */
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
csrf_verify();

$pdo = db();
$id = (int)($_POST['id'] ?? 0);

$st = $pdo->prepare('SELECT * FROM books WHERE id = ?');
$st->execute([$id]);
$book = $st->fetch();
if (!$book) fail('Book not found.', 'books.php');

$st = $pdo->prepare('SELECT COUNT(*) FROM loans WHERE book_id = ?');
$st->execute([$id]);
if ((int)$st->fetchColumn() > 0) {
    fail('Cannot delete “' . $book['title'] . '” — it has loan history. Keep it for the records.', 'book_details.php?id=' . $id);
}

$pdo->prepare('DELETE FROM books WHERE id = ?')->execute([$id]);
if (!empty($book['cover']) && is_file(__DIR__ . '/../' . $book['cover'])) @unlink(__DIR__ . '/../' . $book['cover']);
log_activity('book.deleted', $book['title'] . " (id $id)");
flash('Book deleted.');
redirect('books.php');
