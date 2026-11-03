<?php
/** Issue (lend) a book to a member. */
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
csrf_verify();

$pdo = db();

$memberId  = (int)($_POST['member_id'] ?? 0);
$bookId    = (int)($_POST['book_id'] ?? 0);
$issueDate = $_POST['issue_date'] ?? date('Y-m-d');
$days      = (int)($_POST['days'] ?? LOAN_DAYS_DEFAULT);

/* member checks */
$st = $pdo->prepare('SELECT * FROM members WHERE id = ?');
$st->execute([$memberId]);
$m = $st->fetch();
if (!$m) fail('Please select a member.', 'issue_return.php#issue');
if ($m['status'] !== 'Active') fail($m['name'] . ' is suspended and cannot borrow books.', 'issue_return.php#issue');
$borrowed = member_active_loans($pdo, $memberId);
if ($borrowed >= (int)$m['max_books']) {
    fail($m['name'] . " already has $borrowed of " . (int)$m['max_books'] . ' books out.', 'issue_return.php#issue');
}

/* book checks */
$st = $pdo->prepare('SELECT * FROM books WHERE id = ?');
$st->execute([$bookId]);
$b = $st->fetch();
if (!$b) fail('Please select a book.', 'issue_return.php#issue');
$available = book_available($pdo, $bookId);
if ($available < 1) fail('No available copy of “' . $b['title'] . '” right now.', 'issue_return.php#issue');

/* date checks */
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issueDate) || strtotime($issueDate) > strtotime(date('Y-m-d'))) {
    fail('Issue date must be a valid date, not in the future.', 'issue_return.php#issue');
}
if ($days < 1 || $days > 60) fail('Loan period must be between 1 and 60 days.', 'issue_return.php#issue');
$due = date('Y-m-d', strtotime("$issueDate +$days days"));

$pdo->prepare('INSERT INTO loans (book_id, member_id, issue_date, due_date, status) VALUES (?,?,?,?,\'issued\')')
    ->execute([$bookId, $memberId, $issueDate, $due]);
$loanId = (int)$pdo->lastInsertId();

log_activity('loan.issued', "“{$b['title']}” → {$m['name']} ({$m['member_code']}), due " . fmt_date($due));
flash('Issued “' . $b['title'] . '” to ' . $m['name'] . ' — due ' . fmt_date($due) . '.');
redirect('issue_return.php#return');
