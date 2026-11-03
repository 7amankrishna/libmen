    </main>
  </div>
</div>

<?php $flash = flash(); ?>
<?php if ($flash): ?>
  <div class="toast toast-<?= e($flash['type']) ?>" id="toast">
    <span class="toast-ic"><?= $flash['type'] === 'error' ? icon('alert', 18) : icon('check', 18) ?></span>
    <span><?= e($flash['msg']) ?></span>
  </div>
<?php endif; ?>

<?php if (!empty($load_charts)): ?>
<script src="assets/js/chart.umd.js"></script>
<?php endif; ?>
<script src="assets/js/app.js"></script>
</body>
</html>
