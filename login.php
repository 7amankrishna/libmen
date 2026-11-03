<?php
/** Staff sign-in page. */
require_once __DIR__ . '/includes/bootstrap.php';

// If the app is not installed yet, go to the installer.
try {
    $pdo = db_try();
    $t = $pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn();
    if (!$t) redirect('install.php');
    if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) redirect('install.php');
} catch (Throwable $e) {
    redirect('install.php');
}

if (!empty($_SESSION['user'])) redirect('index.php');

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email = trim($_POST['email'] ?? '');
    $pass  = (string)($_POST['password'] ?? '');
    $st = db()->prepare('SELECT * FROM users WHERE email = ?');
    $st->execute([$email]);
    $u = $st->fetch();
    if ($u && password_verify($pass, $u['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => (int)$u['id'], 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role']];
        log_activity('auth.login', $u['email']);
        redirect('index.php');
    }
    $error = 'Invalid email or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in · Library Management System</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Playfair+Display:ital,wght@0,700;0,800;1,500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css?v=1">
</head>
<body>
<div class="auth-wrap">
  <div class="auth-card">
    <div class="auth-brand">
      <span class="brand-logo"><?= icon('books', 26) ?></span>
      <div>
        <div class="auth-title">Library</div>
        <div style="color:var(--muted);font-size:12px;letter-spacing:1.5px;font-weight:700">MANAGEMENT SYSTEM</div>
      </div>
    </div>
    <div class="auth-title" style="font-size:22px">Welcome back</div>
    <p class="auth-sub">Sign in with your staff account to manage the library.</p>
    <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <div class="field">
        <label>Email</label>
        <input class="input" type="email" name="email" required autofocus placeholder="you@yourlibrary.org" value="<?= e($_POST['email'] ?? '') ?>">
      </div>
      <div class="field">
        <label>Password</label>
        <input class="input" type="password" name="password" required placeholder="••••••••">
      </div>
      <div style="margin-top:22px"><button class="btn block" type="submit">Sign in <?= icon('arrow', 17) ?></button></div>
    </form>
    <p style="margin:16px 0 0;color:var(--muted);font-size:12px;text-align:center">First time? Run <a href="install.php">install.php</a> once, then delete it.</p>
  </div>
</div>
</body>
</html>
