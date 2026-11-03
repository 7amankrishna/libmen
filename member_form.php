<?php
/** Register / edit a member. POSTs to actions/member_save.php */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$pdo = db();
$id = (int)($_GET['id'] ?? 0);
$m = null;
if ($id > 0) {
    $st = $pdo->prepare('SELECT * FROM members WHERE id = ?');
    $st->execute([$id]);
    $m = $st->fetch();
    if (!$m) { fail('Member not found.', 'borrowers.php'); }
}

$page_title = $m ? 'Edit Member' : 'Register Member';
$active = 'borrowers';
include __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <div>
    <h1 class="page-title"><?= $m ? 'Edit Member' : 'Register Member' ?></h1>
    <p class="page-sub"><?= $m ? 'Update membership details for ' . e($m['name']) . '.' : 'Add a new borrower to the library.' ?></p>
  </div>
  <div class="spacer"></div>
  <a class="btn ghost" href="<?= $m ? 'member_details.php?id=' . (int)$m['id'] : 'borrowers.php' ?>"><?= icon('x', 16) ?> Cancel</a>
</div>

<div class="card">
  <div class="card-b">
    <form method="post" action="actions/member_save.php">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= $m ? (int)$m['id'] : '' ?>">
      <div class="form-grid">
        <div class="field">
          <label>Full name *</label>
          <input class="input" name="name" required maxlength="255" value="<?= e($m['name'] ?? '') ?>" placeholder="e.g. Aditi Sharma">
        </div>
        <div class="field">
          <label>Email *</label>
          <input class="input" type="email" name="email" required maxlength="190" value="<?= e($m['email'] ?? '') ?>" placeholder="member@example.org">
        </div>
        <div class="field">
          <label>Phone</label>
          <input class="input" name="phone" maxlength="30" value="<?= e($m['phone'] ?? '') ?>" placeholder="e.g. +91 98765 43210">
        </div>
        <div class="field">
          <label>Membership *</label>
          <select class="input" name="membership">
            <?php foreach (['Student', 'Faculty', 'General'] as $t): ?>
              <option value="<?= $t ?>" <?= ($m['membership'] ?? 'General') === $t ? 'selected' : '' ?>><?= $t ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>Member since *</label>
          <input class="input" type="date" name="member_since" required value="<?= e($m['member_since'] ?? date('Y-m-d')) ?>">
        </div>
        <div class="field">
          <label>Valid till *</label>
          <input class="input" type="date" name="valid_till" required value="<?= e($m['valid_till'] ?? date('Y-m-d', strtotime('+1 year'))) ?>">
        </div>
        <div class="field">
          <label>Max books allowed *</label>
          <input class="input" type="number" name="max_books" required min="1" max="20" value="<?= e($m['max_books'] ?? 5) ?>">
        </div>
        <?php if ($m): ?>
        <div class="field">
          <label>Status</label>
          <select class="input" name="status">
            <?php foreach (['Active', 'Suspended'] as $s): ?>
              <option value="<?= $s ?>" <?= $m['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <?php if (!$m): ?>
          <div class="field full">
            <span class="hint">A member ID like <strong>LIB-0001</strong> is generated automatically when you save.</span>
          </div>
        <?php endif; ?>
      </div>
      <div class="form-foot">
        <a class="btn ghost" href="<?= $m ? 'member_details.php?id=' . (int)$m['id'] : 'borrowers.php' ?>">Cancel</a>
        <button class="btn" type="submit"><?= icon('check', 17) ?> <?= $m ? 'Save Changes' : 'Register Member' ?></button>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
