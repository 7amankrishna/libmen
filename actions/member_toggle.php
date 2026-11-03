<?php
/** Suspend / reactivate a member. */
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
csrf_verify();

$pdo = db();
$id = (int)($_POST['id'] ?? 0);

$st = $pdo->prepare('SELECT * FROM members WHERE id = ?');
$st->execute([$id]);
$m = $st->fetch();
if (!$m) fail('Member not found.', 'borrowers.php');

$new = $m['status'] === 'Active' ? 'Suspended' : 'Active';
$pdo->prepare('UPDATE members SET status = ? WHERE id = ?')->execute([$new, $id]);
log_activity('member.' . strtolower($new), $m['name'] . ' (' . $m['member_code'] . ')');
flash($new === 'Active' ? 'Member reactivated.' : 'Member suspended — they cannot borrow new books.');
redirect('member_details.php?id=' . $id);
