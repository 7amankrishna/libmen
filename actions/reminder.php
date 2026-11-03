<?php
/** Mark an overdue loan as reminded (from the reports page). */
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
csrf_verify();

$pdo = db();
$loanId = (int)($_POST['loan_id'] ?? 0);

$st = $pdo->prepare("
    SELECT l.*, b.title, m.name AS member_name, m.member_code, m.email
    FROM loans l JOIN books b ON b.id = l.book_id JOIN members m ON m.id = l.member_id
    WHERE l.id = ?
");
$st->execute([$loanId]);
$l = $st->fetch();
if (!$l) fail('Loan not found.', 'reports.php#overdue');
if ($l['status'] !== 'issued' || !loan_is_overdue($l)) fail('This loan is not overdue.', 'reports.php#overdue');

$pdo->prepare('UPDATE loans SET reminded_at = NOW() WHERE id = ?')->execute([$loanId]);
$late = days_late($l['due_date']);
log_activity('loan.reminder', 'Reminder to ' . $l['member_name'] . ' (' . $l['email'] . ") for “{$l['title']}” — $late days late");
flash('Reminder marked as sent to ' . $l['member_name'] . ' (' . $l['email'] . ').');
redirect('reports.php#overdue');
