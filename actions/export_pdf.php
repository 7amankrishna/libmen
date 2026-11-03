<?php
/**
 * Download a PDF report for the selected range.
 * Uses the bundled MiniPdf writer — no Composer/FPDF required.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_once __DIR__ . '/../includes/pdf.php';

$pdo = db();

$range = $_GET['range'] ?? '30';
[$rangeLabel, $start] = range_bounds($range);
$today = date('Y-m-d');

$issueWhere = $start ? 'issue_date >= ?' : '1=1';
$args = $start ? [$start] : [];

$circSt = $pdo->prepare("SELECT COUNT(*) FROM loans WHERE $issueWhere"); $circSt->execute($args);
$circulation = (int)$circSt->fetchColumn();

$retSt = $pdo->prepare("SELECT COUNT(*) FROM loans WHERE status='returned'" . ($start ? ' AND return_date >= ?' : ''));
$retSt->execute($args); $returns = (int)$retSt->fetchColumn();

$overSt = $pdo->prepare("
    SELECT COUNT(*) FROM loans WHERE $issueWhere
      AND ( (status='issued' AND due_date < CURDATE()) OR (status='returned' AND return_date > due_date) )");
$overSt->execute($args); $overdueInRange = (int)$overSt->fetchColumn();
$overdueRate = $circulation > 0 ? round($overdueInRange / $circulation * 100, 1) : 0;

$activeMembers = (int)$pdo->query("SELECT COUNT(*) FROM members WHERE status='Active'")->fetchColumn();
$onLoan = (int)$pdo->query("SELECT COUNT(*) FROM loans WHERE status='issued'")->fetchColumn();
$overdueNow = (int)$pdo->query("SELECT COUNT(*) FROM loans WHERE status='issued' AND due_date < CURDATE()")->fetchColumn();
$totalTitles = (int)$pdo->query("SELECT COUNT(*) FROM books")->fetchColumn();

$subjects = $pdo->prepare("
    SELECT b.category, COUNT(*) AS c FROM loans l JOIN books b ON b.id=l.book_id
    WHERE $issueWhere GROUP BY b.category ORDER BY c DESC LIMIT 8");
$subjects->execute($args); $subjects = $subjects->fetchAll();

$topTitles = $pdo->prepare("
    SELECT b.title, b.author, COUNT(*) AS c FROM loans l JOIN books b ON b.id=l.book_id
    WHERE $issueWhere GROUP BY b.id ORDER BY c DESC LIMIT 10");
$topTitles->execute($args); $topTitles = $topTitles->fetchAll();

$overdueList = $pdo->query("
    SELECT l.due_date, l.reminded_at, b.title, m.name AS member_name, m.member_code
    FROM loans l JOIN books b ON b.id=l.book_id JOIN members m ON m.id=l.member_id
    WHERE l.status='issued' AND l.due_date < CURDATE() ORDER BY l.due_date LIMIT 15
")->fetchAll();

/* ------------------------------- build the PDF ------------------------------ */
$purple = [0.486, 0.227, 0.929];
$light  = [0.965, 0.957, 0.984];
$ink    = [0.13, 0.10, 0.20];
$gray   = [0.44, 0.42, 0.52];
$red    = [0.86, 0.15, 0.15];
$green  = [0.09, 0.64, 0.29];
$line   = [0.90, 0.88, 0.94];

$pdf = new MiniPdf();

/* header band */
$pdf->setFillColor(...$purple);
$pdf->rect(0, 0, MiniPdf::W, 92);
$pdf->setFont('F2', 21); $pdf->setTextColor(1, 1, 1);
$pdf->text(48, 34, 'Library Report');
$pdf->setFont('F1', 10.5); $pdf->setTextColor(0.90, 0.86, 1.0);
$pdf->text(48, 58, $rangeLabel . '  ·  generated on ' . date('j M Y, H:i'));
$pdf->text(48, 74, 'Library Management System');

$pdf->cursor(48, 116);

/* ---- key figures ---- */
$pdf->setFont('F2', 14); $pdf->setTextColor(...$ink);
$pdf->para('Key figures', MiniPdf::W - 96);
$pdf->move(0, 8);

$kpis = [
    ['Circulation', number_format($circulation), 'loans in range'],
    ['Returns', number_format($returns), 'books returned'],
    ['Overdue rate', $overdueRate . '%', $overdueInRange . ' of ' . $circulation . ' loans'],
    ['Active members', number_format($activeMembers), $onLoan . ' books on loan'],
];
$bw = (MiniPdf::W - 96 - 3 * 12) / 4;
$y0 = $pdf->getY();
foreach ($kpis as $i => $k) {
    $x = 48 + $i * ($bw + 12);
    $pdf->setFillColor(...$light);
    $pdf->rect($x, $y0, $bw, 62);
    $pdf->setFont('F1', 8.5); $pdf->setTextColor(...$gray);
    $pdf->text($x + 10, $y0 + 17, strtoupper($k[0]));
    $pdf->setFont('F2', 16); $pdf->setTextColor(...$ink);
    $pdf->text($x + 10, $y0 + 40, $k[1]);
    $pdf->setFont('F1', 8); $pdf->setTextColor(...$gray);
    $pdf->text($x + 10, $y0 + 54, $k[2]);
}
$pdf->cursor(48, $y0 + 62 + 26);

/* section helper */
$section = function (string $title) use ($pdf, $ink, $gray, $line) {
    $pdf->setFont('F2', 13); $pdf->setTextColor(...$ink);
    $pdf->para($title, MiniPdf::W - 96);
    $pdf->move(0, 4);
    $pdf->hline(48, MiniPdf::W - 48, $pdf->getY(), 0.8, $line);
    $pdf->move(0, 12);
};

/* ---- most-read subjects ---- */
$section('Most-read subjects (' . strtolower($rangeLabel) . ')');
if (!$subjects) {
    $pdf->setFont('F1', 10); $pdf->setTextColor(...$gray);
    $pdf->para('No loans recorded in this period yet.', MiniPdf::W - 96);
} else {
    $max = max(array_map(function ($s) { return (int)$s['c']; }, $subjects));
    foreach ($subjects as $s) {
        $pdf->setFont('F2', 10.5); $pdf->setTextColor(...$ink);
        $pdf->text(48, $pdf->getY(), (string)$s['category']);
        $barW = max(4, ((int)$s['c'] / max(1, $max)) * 260);
        $pdf->setFillColor(...$purple);
        $pdf->rect(180, $pdf->getY() - 3, $barW, 9);
        $pdf->setFont('F1', 10); $pdf->setTextColor(...$gray);
        $pdf->text(450, $pdf->getY(), (int)$s['c'] . ' checkouts');
        $pdf->move(0, 17);
    }
}
$pdf->move(0, 10);

/* ---- top titles ---- */
$section('Top titles by checkouts');
if (!$topTitles) {
    $pdf->setFont('F1', 10); $pdf->setTextColor(...$gray);
    $pdf->para('No loans recorded in this period yet.', MiniPdf::W - 96);
} else {
    $i = 1;
    foreach ($topTitles as $t) {
        $pdf->setFont('F1', 9); $pdf->setTextColor(...$gray);
        $pdf->text(48, $pdf->getY(), $i . '.');
        $pdf->setFont('F2', 10.5); $pdf->setTextColor(...$ink);
        $name = $t['title'] . ' — ' . $t['author'];
        while ($pdf->textWidth($name) > 400 && mb_strlen($name) > 4) $name = mb_substr($name, 0, -2) . '…';
        $pdf->text(66, $pdf->getY(), $name);
        $pdf->setFont('F1', 10); $pdf->setTextColor(...$gray);
        $pdf->text(MiniPdf::W - 48 - $pdf->textWidth((string)$t['c'] . ' loans'), $pdf->getY(), $t['c'] . ' loans');
        $pdf->move(0, 15);
        $i++;
    }
}
$pdf->move(0, 10);

/* ---- overdue follow-ups ---- */
$section('Overdue follow-ups (right now)');
if (!$overdueList) {
    $pdf->setFont('F1', 10); $pdf->setTextColor(...$green);
    $pdf->para('No overdue items — every borrowed book is within its due date.', MiniPdf::W - 96);
} else {
    /* header */
    $pdf->setFillColor(...$light);
    $pdf->rect(48, $pdf->getY() - 4, MiniPdf::W - 96, 18);
    $pdf->setFont('F2', 9); $pdf->setTextColor(...$gray);
    $pdf->text(56, $pdf->getY() + 5, 'MEMBER');
    $pdf->text(230, $pdf->getY() + 5, 'TITLE');
    $pdf->text(430, $pdf->getY() + 5, 'DUE');
    $pdf->text(490, $pdf->getY() + 5, 'DAYS LATE');
    $pdf->move(0, 22);
    foreach ($overdueList as $o) {
        $pdf->hline(48, MiniPdf::W - 48, $pdf->getY(), 0.5, $line);
        $pdf->move(0, 13);
        $pdf->setFont('F2', 9.5); $pdf->setTextColor(...$ink);
        $m = $o['member_name'] . ' (' . $o['member_code'] . ')';
        while ($pdf->textWidth($m) > 165 && mb_strlen($m) > 4) $m = mb_substr($m, 0, -2) . '…';
        $pdf->text(56, $pdf->getY(), $m);
        $pdf->setFont('F1', 9.5); $pdf->setTextColor(...$ink);
        $t = $o['title'];
        while ($pdf->textWidth($t) > 190 && mb_strlen($t) > 4) $t = mb_substr($t, 0, -2) . '…';
        $pdf->text(230, $pdf->getY(), $t);
        $pdf->setTextColor(...$gray);
        $pdf->text(430, $pdf->getY(), fmt_date($o['due_date']));
        $late = days_late($o['due_date']);
        $pdf->setTextColor(...$red); $pdf->setFont('F2', 9.5);
        $pdf->text(490, $pdf->getY(), $late . ' d');
        $pdf->move(0, 15);
    }
}

$pdf->move(0, 8);
$pdf->setFont('F1', 8.5); $pdf->setTextColor(...$gray);
$pdf->para('Collection: ' . $totalTitles . ' titles · ' . $onLoan . ' copies on loan · ' . $overdueNow . ' overdue right now. '
    . 'Fine policy: ₹' . number_format(FINE_PER_DAY, 2) . ' per day after the due date.', MiniPdf::W - 96);

$pdf->send('library-report-' . strtolower(str_replace(' ', '-', $rangeLabel)) . '-' . date('Y-m-d') . '.pdf', 'Library Report — ' . $rangeLabel);
