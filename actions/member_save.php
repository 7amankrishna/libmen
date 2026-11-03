<?php
/** Register / edit a member. */
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
csrf_verify();

$pdo = db();

$id        = (int)($_POST['id'] ?? 0);
$name      = trim($_POST['name'] ?? '');
$email     = trim($_POST['email'] ?? '');
$phone     = trim($_POST['phone'] ?? '');
$membership= $_POST['membership'] ?? 'General';
$since     = $_POST['member_since'] ?? '';
$till      = $_POST['valid_till'] ?? '';
$maxBooks  = (int)($_POST['max_books'] ?? 5);
$status    = $_POST['status'] ?? 'Active';

if ($name === '') fail('Name is required.', 'member_form.php');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Please enter a valid email address.', $id ? "member_form.php?id=$id" : 'member_form.php');
if (!in_array($membership, ['Student', 'Faculty', 'General'], true)) $membership = 'General';
if (!in_array($status, ['Active', 'Suspended'], true)) $status = 'Active';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $since) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $till)) {
    fail('Please provide valid member-since and valid-till dates.', $id ? "member_form.php?id=$id" : 'member_form.php');
}
if (strtotime($till) < strtotime($since)) fail('“Valid till” must be after “Member since”.', $id ? "member_form.php?id=$id" : 'member_form.php');
if ($maxBooks < 1 || $maxBooks > 20) fail('Max books must be between 1 and 20.', $id ? "member_form.php?id=$id" : 'member_form.php');
$phone = $phone === '' ? null : $phone;

/* unique email (excluding self) */
$st = $pdo->prepare('SELECT id FROM members WHERE email = ? AND id <> ?');
$st->execute([$email, $id]);
if ($st->fetch()) fail('This email is already registered to another member.', $id ? "member_form.php?id=$id" : 'member_form.php');

/* cannot lower max_books below current active loans */
if ($id > 0) {
    $borrowed = member_active_loans($pdo, $id);
    if ($maxBooks < $borrowed) fail("This member currently has $borrowed books out — max books cannot be lower than that.", "member_form.php?id=$id");
}

try {
    if ($id > 0) {
        $st = $pdo->prepare('UPDATE members SET name=?, email=?, phone=?, membership=?, member_since=?, valid_till=?, max_books=?, status=? WHERE id=?');
        $st->execute([$name, $email, $phone, $membership, $since, $till, $maxBooks, $status, $id]);
        log_activity('member.updated', "$name (id $id)");
        flash('Member updated.');
        redirect('member_details.php?id=' . $id);
    } else {
        $st = $pdo->prepare('INSERT INTO members (member_code, name, email, phone, membership, member_since, valid_till, status, max_books)
                             VALUES (?,?,?,?,?,?,?,?,?)');
        $tmp = 'TMP-' . bin2hex(random_bytes(4));
        $st->execute([$tmp, $name, $email, $phone, $membership, $since, $till, 'Active', $maxBooks]);
        $newId = (int)$pdo->lastInsertId();
        $code = gen_member_code($newId);
        $pdo->prepare('UPDATE members SET member_code = ? WHERE id = ?')->execute([$code, $newId]);
        log_activity('member.added', "$name ($code)");
        flash("Member registered with ID $code.");
        redirect('member_details.php?id=' . $newId);
    }
} catch (PDOException $e) {
    if ($e->getCode() === '23000') fail('A member with this email already exists.', $id ? "member_form.php?id=$id" : 'member_form.php');
    throw $e;
}
