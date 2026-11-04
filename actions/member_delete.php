<?php
/** Delete a member — only possible when they have no loan history. */
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
csrf_verify();

$pdo = db();
$id = (int)($_POST['id'] ?? 0);

$st = $pdo->prepare('SELECT * FROM members WHERE id = ?');
$st->execute([$id]);
$m = $st->fetch();
if (!$m) fail('Member not found.', 'borrowers.php');

$st = $pdo->prepare('SELECT COUNT(*) FROM loans WHERE member_id = ?');
$st->execute([$id]);
if ((int)$st->fetchColumn() > 0) {
    fail('Cannot delete ' . $m['name'] . ' — they have loan history. Suspend the member instead.', 'member_details.php?id=' . $id);
}

$pdo->prepare('DELETE FROM members WHERE id = ?')->execute([$id]);
log_activity('member.deleted', $m['name'] . ' (' . $m['member_code'] . ')');
flash('Member deleted.');
redirect('borrowers.php');
