<?php
/**
 * Shared helper functions for the Library Management System.
 */

/* ------------------------------------------------------------------------- */
/* Tunables                                                                   */
/* ------------------------------------------------------------------------- */
const LOAN_DAYS_DEFAULT = 14;   // default loan period in days
const FINE_PER_DAY      = 10.00; // fine (INR) per day after the due date
const LOW_STOCK_LIMIT   = 2;    // "low stock" = this many or fewer available copies

/* ------------------------------------------------------------------------- */
/* Basics                                                                     */
/* ------------------------------------------------------------------------- */
function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

function back(string $fallback = 'index.php'): void {
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    if ($ref && strpos($ref, $_SERVER['HTTP_HOST'] ?? '') !== false) {
        redirect($ref);
    }
    redirect($fallback);
}

/* ------------------------------------------------------------------------- */
/* Flash messages                                                             */
/* ------------------------------------------------------------------------- */
function flash(?string $msg = null, string $type = 'success'): ?array {
    if ($msg === null) {
        $f = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return $f;
    }
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
    return null;
}

function fail(string $msg, string $fallback = 'index.php'): void {
    flash($msg, 'error');
    back($fallback);
}

/* ------------------------------------------------------------------------- */
/* CSRF                                                                       */
/* ------------------------------------------------------------------------- */
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void {
    if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) {
        http_response_code(419);
        exit('Security token expired or invalid. Please go back and try again.');
    }
}

/* ------------------------------------------------------------------------- */
/* Auth                                                                       */
/* ------------------------------------------------------------------------- */
function require_login(): void {
    if (empty($_SESSION['user'])) {
        redirect('login.php');
    }
}

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

/* ------------------------------------------------------------------------- */
/* Audit log                                                                  */
/* ------------------------------------------------------------------------- */
function log_activity(string $action, string $details = ''): void {
    try {
        $u = current_user();
        $st = db()->prepare('INSERT INTO activity_log (action, details, user_id) VALUES (?,?,?)');
        $st->execute([$action, $details, $u['id'] ?? null]);
    } catch (Throwable $t) {
        // never let logging break the main action
    }
}

/* ------------------------------------------------------------------------- */
/* Formatting                                                                 */
/* ------------------------------------------------------------------------- */
function fmt_date(?string $d): string {
    if (!$d) return '—';
    $t = strtotime($d);
    return $t ? date('j M Y', $t) : e($d);
}

function fmt_dt(?string $d): string {
    if (!$d) return '—';
    $t = strtotime($d);
    return $t ? date('j M Y, H:i', $t) : e($d);
}

function money($n): string {
    return '₹' . number_format((float)$n, 2);
}

function initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name));
    $s = '';
    foreach ($parts as $p) {
        if ($p !== '') $s .= mb_strtoupper(mb_substr($p, 0, 1));
        if (mb_strlen($s) >= 2) break;
    }
    return $s ?: '?';
}

/** deterministic pastel gradient pair for a string (avatars / cover placeholders) */
function gradient_for(string $seed): array {
    $palette = [
        ['#7c3aed', '#a78bfa'], ['#059669', '#6ee7b7'], ['#d97706', '#fcd34d'],
        ['#dc2626', '#fca5a5'], ['#2563eb', '#93c5fd'], ['#db2777', '#f9a8d4'],
        ['#0d9488', '#5eead4'], ['#ea580c', '#fdba74'], ['#4f46e5', '#a5b4fc'],
        ['#65a30d', '#bef264'],
    ];
    return $palette[abs(crc32($seed)) % count($palette)];
}

function avatar_html(string $name, int $size = 40): string {
    [$a, $b] = gradient_for($name);
    return '<span class="avatar" style="width:' . $size . 'px;height:' . $size . 'px;' .
        'background:linear-gradient(135deg,' . $a . ',' . $b . ');font-size:' . round($size * 0.38) . 'px">' .
        e(initials($name)) . '</span>';
}

/** book cover: uploaded image or a generated gradient placeholder */
function cover_html(array $book, int $w = 92, int $h = 132): string {
    if (!empty($book['cover']) && is_file(__DIR__ . '/../' . $book['cover'])) {
        return '<img class="cover" style="width:' . $w . 'px;height:' . $h . 'px" src="' . e($book['cover']) .
            '" alt="' . e($book['title']) . ' cover" loading="lazy">';
    }
    [$a, $b] = gradient_for($book['title'] ?? 'book');
    return '<span class="cover cover-ph" style="width:' . $w . 'px;height:' . $h . 'px;' .
        'background:linear-gradient(160deg,' . $a . ',' . $b . ')">' .
        '<span>' . e(mb_strtoupper(mb_substr(trim($book['title'] ?? '?'), 0, 1))) . '</span></span>';
}

/* ------------------------------------------------------------------------- */
/* Domain helpers                                                             */
/* ------------------------------------------------------------------------- */
function book_available(PDO $pdo, int $bookId): int {
    $st = $pdo->prepare(
        'SELECT b.copies - (SELECT COUNT(*) FROM loans l WHERE l.book_id = b.id AND l.status = \'issued\') AS avail
         FROM books b WHERE b.id = ?'
    );
    $st->execute([$bookId]);
    $v = $st->fetchColumn();
    return $v === false ? 0 : max(0, (int)$v);
}

function member_active_loans(PDO $pdo, int $memberId): int {
    $st = $pdo->prepare("SELECT COUNT(*) FROM loans WHERE member_id = ? AND status = 'issued'");
    $st->execute([$memberId]);
    return (int)$st->fetchColumn();
}

function loan_is_overdue(array $loan): bool {
    return ($loan['status'] ?? '') === 'issued'
        && !empty($loan['due_date'])
        && strtotime($loan['due_date']) < strtotime(date('Y-m-d'));
}

function days_late(string $due, ?string $ret = null): int {
    $end = $ret ?: date('Y-m-d');
    $d = (int)floor((strtotime($end) - strtotime($due)) / 86400);
    return max(0, $d);
}

/** [label, css-class] badge for a loan row */
function loan_badge(array $loan): array {
    if (($loan['status'] ?? '') === 'returned') return ['Returned', 'b-green'];
    if (loan_is_overdue($loan)) return ['Overdue', 'b-red'];
    return ['Issued', 'b-purple'];
}

function gen_member_code(int $id): string {
    return 'LIB-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
}

/* ------------------------------------------------------------------------- */
/* Reports date ranges                                                        */
/* ------------------------------------------------------------------------- */
/** returns [label, start 'Y-m-d'|null, days|null]  (range includes today) */
function range_bounds(string $range): array {
    switch ($range) {
        case '7':   return ['Last 7 days',   date('Y-m-d', strtotime('-6 days')), 7];
        case '90':  return ['Last 90 days',  date('Y-m-d', strtotime('-89 days')), 90];
        case '365': return ['Last 365 days', date('Y-m-d', strtotime('-364 days')), 365];
        case 'all': return ['All time', null, null];
        default:    return ['Last 30 days',  date('Y-m-d', strtotime('-29 days')), 30];
    }
}

/* ------------------------------------------------------------------------- */
/* Pagination                                                                 */
/* ------------------------------------------------------------------------- */
function page_links(string $base, int $page, int $pages): string {
    if ($pages <= 1) return '';
    $sep = strpos($base, '?') === false ? '?' : '&amp;';
    $html = '<nav class="pagination">';
    $url = function ($p) use ($base, $sep) { return $base . $sep . 'page=' . $p; };
    if ($page > 1) $html .= '<a class="pg" href="' . $url($page - 1) . '">&larr; Prev</a>';
    $start = max(1, $page - 2); $end = min($pages, $page + 2);
    if ($start > 1) $html .= '<a class="pg" href="' . $url(1) . '">1</a>' . ($start > 2 ? '<span class="pg-dots">…</span>' : '');
    for ($i = $start; $i <= $end; $i++) {
        $html .= $i == $page ? '<span class="pg on">' . $i . '</span>' : '<a class="pg" href="' . $url($i) . '">' . $i . '</a>';
    }
    if ($end < $pages) $html .= ($end < $pages - 1 ? '<span class="pg-dots">…</span>' : '') . '<a class="pg" href="' . $url($pages) . '">' . $pages . '</a>';
    if ($page < $pages) $html .= '<a class="pg" href="' . $url($page + 1) . '">Next &rarr;</a>';
    return $html . '</nav>';
}

/* ------------------------------------------------------------------------- */
/* Inline SVG icons (stroke = currentColor)                                   */
/* ------------------------------------------------------------------------- */
function icon(string $name, int $size = 20): string {
    $paths = [
        'home'    => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M9 21v-6h6v6"/>',
        'book'    => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20V4a2 2 0 0 0-2-2H6.5A2.5 2.5 0 0 0 4 4.5v15z"/><path d="M4 19.5A2.5 2.5 0 0 0 6.5 22H20v-5"/>',
        'books'   => '<path d="M4 19.5V4.5A2.5 2.5 0 0 1 6.5 2H20v15H6.5A2.5 2.5 0 0 0 4 19.5zm0 0A2.5 2.5 0 0 0 6.5 22H20"/><path d="M9 7h7"/>',
        'users'   => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3.2 3.4-5 6.5-5s5.7 1.8 6.5 5"/><circle cx="17.5" cy="9" r="2.5"/><path d="M16 15.2c2.6.3 4.6 1.9 5.3 4.8"/>',
        'swap'    => '<path d="M4 7h13l-3-3"/><path d="M20 17H7l3 3"/>',
        'chart'   => '<path d="M4 20V4"/><path d="M4 20h16"/><rect x="8" y="12" width="3" height="5" rx="1"/><rect x="13" y="8" width="3" height="9" rx="1"/><rect x="18" y="5" width="3" height="12" rx="1"/>',
        'search'  => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.8-3.8"/>',
        'bell'    => '<path d="M18 9a6 6 0 1 0-12 0c0 6-2.5 7-2.5 7h17S18 15 18 9z"/><path d="M10 20a2.2 2.2 0 0 0 4 0"/>',
        'plus'    => '<path d="M12 5v14M5 12h14"/>',
        'edit'    => '<path d="M4 20h4L19.5 8.5a2.1 2.1 0 0 0-3-3L5 17v3z"/><path d="m13.5 6.5 3 3"/>',
        'trash'   => '<path d="M4 7h16"/><path d="M9 7V5h6v2"/><path d="M6 7l1 13h10l1-13"/><path d="M10 11v6M14 11v6"/>',
        'eye'     => '<path d="M2 12s3.5-6.5 10-6.5S22 12 22 12s-3.5 6.5-10 6.5S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'download'=> '<path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M4 21h16"/>',
        'csv'     => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h4"/>',
        'pdf'     => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 17v-4h2a2 2 0 0 1 0 4H9"/>',
        'mail'    => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'phone'   => '<path d="M5 4h4l2 5-2.5 1.5a12 12 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/>',
        'id'      => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="11" r="2"/><path d="M6.5 16c.6-1.4 1.5-2 2.5-2s1.9.6 2.5 2"/><path d="M14 10h5M14 14h5"/>',
        'cal'     => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
        'alert'   => '<path d="M12 3 2.5 20h19z"/><path d="M12 10v4M12 17.5v.5"/>',
        'arrow'   => '<path d="M5 12h14"/><path d="m13 6 6 6-6 6"/>',
        'barcode' => '<path d="M4 6v12M7 6v12M10 6v12M13 6v12M16 6v12M19 6v12" stroke-width="1.4"/>',
        'check'   => '<path d="m4 12.5 5 5L20 6.5"/>',
        'logout'  => '<path d="M9 21H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h3"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        'clock'   => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
        'filter'  => '<path d="M4 5h16l-6 8v6l-4 2v-8z"/>',
        'grid'    => '<rect x="4" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="4" width="7" height="7" rx="1.5"/><rect x="4" y="13" width="7" height="7" rx="1.5"/><rect x="13" y="13" width="7" height="7" rx="1.5"/>',
        'menu'    => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'x'       => '<path d="M6 6l12 12M18 6 6 18"/>',
        'laptop'  => '<rect x="4" y="5" width="16" height="11" rx="1.5"/><path d="M2 19h20"/>',
        'remind'  => '<path d="M4 5h16v11H8l-4 4z"/><path d="M8 9h8M8 12h5"/>',
        'trend'   => '<path d="m3 17 6-6 4 4 8-8"/><path d="M15 7h6v6"/>',
    ];
    $p = $paths[$name] ?? $paths['book'];
    return '<svg class="ic" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" ' .
        'stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' .
        $p . '</svg>';
}
