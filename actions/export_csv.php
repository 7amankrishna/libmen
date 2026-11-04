<?php
/**
 * Export data as CSV download.
 *   ?type=loans|overdue|books|members   (default: loans)
 *   &range=7|30|90|365|all               (for loans/overdue, default: 30)
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$pdo = db();

$type  = $_GET['type'] ?? 'loans';
$range = $_GET['range'] ?? '30';
[$rangeLabel, $start] = range_bounds($range);
$today = date('Y-m-d');

$filename = 'library-' . $type . '-' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
echo "\xEF\xBB\xBF"; // BOM so Excel opens UTF-8 correctly

$out = fopen('php://output', 'w');

switch ($type) {
    case 'books':
        fputcsv($out, ['ID', 'Title', 'Author', 'ISBN', 'Call No', 'Category', 'Copies', 'Available', 'On loan']);
        $rows = $pdo->query("
            SELECT b.id, b.title, b.author, b.isbn, b.call_no, b.category, b.copies,
                   b.copies - (SELECT COUNT(*) FROM loans l WHERE l.book_id=b.id AND l.status='issued') AS available,
                   (SELECT COUNT(*) FROM loans l WHERE l.book_id=b.id AND l.status='issued') AS on_loan
            FROM books b ORDER BY b.title
        ");
        foreach ($rows as $r) {
            fputcsv($out, [$r['id'], $r['title'], $r['author'], $r['isbn'], $r['call_no'], $r['category'], $r['copies'], $r['available'], $r['on_loan']]);
        }
        break;

    case 'members':
        fputcsv($out, ['Member ID', 'Name', 'Email', 'Phone', 'Membership', 'Member since', 'Valid till', 'Status', 'Max books', 'Active loans']);
        $rows = $pdo->query("
            SELECT m.*, (SELECT COUNT(*) FROM loans l WHERE l.member_id=m.id AND l.status='issued') AS active_loans
            FROM members m ORDER BY m.name
        ");
        foreach ($rows as $r) {
            fputcsv($out, [$r['member_code'], $r['name'], $r['email'], $r['phone'], $r['membership'], $r['member_since'], $r['valid_till'], $r['status'], $r['max_books'], $r['active_loans']]);
        }
        break;

    case 'overdue':
        fputcsv($out, ['Loan ID', 'Book', 'Author', 'Member', 'Member ID', 'Email', 'Issue date', 'Due date', 'Days late', 'Reminder sent at']);
        $rows = $pdo->query("
            SELECT l.*, b.title, b.author, m.name AS member_name, m.member_code, m.email
            FROM loans l JOIN books b ON b.id=l.book_id JOIN members m ON m.id=l.member_id
            WHERE l.status='issued' AND l.due_date < CURDATE()
            ORDER BY l.due_date
        ");
        foreach ($rows as $r) {
            fputcsv($out, [$r['id'], $r['title'], $r['author'], $r['member_name'], $r['member_code'], $r['email'],
                $r['issue_date'], $r['due_date'], days_late($r['due_date']), $r['reminded_at'] ?: '']);
        }
        break;

    case 'loans':
    default:
        $where = $start ? 'WHERE l.issue_date >= ?' : '';
        $args  = $start ? [$start] : [];
        fputcsv($out, ['Loan ID', 'Book', 'Author', 'Call No', 'Member', 'Member ID', 'Issue date', 'Due date', 'Return date', 'Status', 'Days late', 'Fine (INR)', "Range: $rangeLabel"]);
        $st = $pdo->prepare("
            SELECT l.*, b.title, b.author, b.call_no, m.name AS member_name, m.member_code
            FROM loans l JOIN books b ON b.id=l.book_id JOIN members m ON m.id=l.member_id
            $where ORDER BY l.issue_date DESC, l.id DESC
        ");
        $st->execute($args);
        foreach ($st->fetchAll() as $r) {
            $status = $r['status'] === 'returned' ? 'Returned' : (loan_is_overdue($r) ? 'Overdue' : 'Issued');
            $late = $r['status'] === 'returned' ? days_late($r['due_date'], $r['return_date']) : ($status === 'Overdue' ? days_late($r['due_date']) : 0);
            fputcsv($out, [$r['id'], $r['title'], $r['author'], $r['call_no'], $r['member_name'], $r['member_code'],
                $r['issue_date'], $r['due_date'], $r['return_date'] ?: '', $status, $late, $r['fine'], '']);
        }
        break;
}

fclose($out);
