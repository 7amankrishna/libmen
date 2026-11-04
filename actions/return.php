<?php
/** Record the return of a loan (computes the fine). */
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
csrf_verify();

$pdo = db();
$loanId = (int)($_POST['loan_id'] ?? 0);

$st = $pdo->prepare("
    SELECT l.*, b.title, m.name AS member_name, m.member_code
    FROM loans l JOIN books b ON b.id = l.book_id JOIN members m ON m.id = l.member_id
    WHERE l.id = ?
");
$st->execute([$loanId]);
$l = $st->fetch();
if (!$l) fail('Loan not found.', 'issue_return.php#return');

if ($l['status'] === 'returned') {
    flash('This loan was already returned on ' . fmt_date($l['return_date']) . '.', 'error');
    back('issue_return.php#return');
}

$today = date('Y-m-d');
$late  = days_late($l['due_date'], $today);
$fine  = round($late * FINE_PER_DAY, 2);

$pdo->prepare("UPDATE loans SET status='returned', return_date=?, fine=? WHERE id=?")
    ->execute([$today, $fine, $loanId]);

log_activity('loan.returned',
    '“' . $l['title'] . '” by ' . $l['member_name'] . ($late > 0 ? " — $late days late, fine " . money($fine) : ' — on time'));

flash($late > 0
    ? 'Returned “' . $l['title'] . '” — ' . $late . ' day(s) late, fine ' . money($fine) . '.'
    : 'Returned “' . $l['title'] . '” on time. No fine.');
back('issue_return.php#return');
