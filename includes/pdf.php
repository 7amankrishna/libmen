<?php
/**
 * MiniPdf - a tiny, dependency-free PDF writer (no Composer/FPDF needed,
 * which matters on free hosting like InfinityFree).
 *
 * Uses the 14 standard PDF core fonts (Helvetica family), so no font files
 * are embedded. Coordinates passed to the public methods use a TOP-DOWN
 * origin (y grows downwards, like CSS) and are converted internally.
 */
class MiniPdf {
    public const W = 595.28;   // A4 width  in points
    public const H = 841.89;   // A4 height in points

    private array $pages = [];       // list of arrays of content-stream lines
    private float $x = 48, $y = 48;  // cursor (top-down coordinates)
    private string $font = 'F1';
    private float $size = 10;
    private array $rgbText = [0.12, 0.12, 0.15];
    private array $rgbFill = [0.49, 0.23, 0.93];

    /** Helvetica AFM widths for ASCII 32..126 (per 1000 units) */
    private const WIDTHS = [
        278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,
        556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,
        1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,
        667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,
        333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,
        556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,
    ];

    private const FONTS = ['F1' => 'Helvetica', 'F2' => 'Helvetica-Bold', 'F3' => 'Courier'];

    public function __construct() {
        $this->addPage();
    }

    public function addPage(): void {
        $this->pages[] = [];
        $this->x = 48;
        $this->y = 48;
    }

    private function cur(): array {
        return $this->pages[count($this->pages) - 1];
    }

    private function add(string $line): void {
        $this->pages[count($this->pages) - 1][] = $line;
    }

    private function ty(float $y): float { return self::H - $y; } // top-down -> PDF coords

    /* ----------------------------- styling ------------------------------ */
    public function setFont(string $f, float $size): void {
        $this->font = $f;
        $this->size = $size;
    }

    public function setTextColor(float $r, float $g, float $b): void {
        $this->rgbText = [$r, $g, $b];
    }

    public function setFillColor(float $r, float $g, float $b): void {
        $this->rgbFill = [$r, $g, $b];
    }

    /* ----------------------------- drawing ------------------------------ */
    private function esc(string $s): string {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }

    public function text(float $x, float $y, string $s): void {
        [$r, $g, $b] = $this->rgbText;
        $this->add(sprintf('%.3f %.3f %.3f rg BT /%s %.2f Tf %.2f %.2f Td (%s) Tj ET',
            $r, $g, $b, $this->font, $this->size, $x, $this->ty($y), $this->esc($s)));
    }

    public function rect(float $x, float $y, float $w, float $h): void {
        [$r, $g, $b] = $this->rgbFill;
        $this->add(sprintf('%.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re f', $r, $g, $b, $x, $this->ty($y + $h), $w, $h));
    }

    public function hline(float $x1, float $x2, float $y, float $lw = 0.7, ?array $rgb = null): void {
        [$r, $g, $b] = $rgb ?? [0.85, 0.83, 0.9];
        $this->add(sprintf('%.3f %.3f %.3f RG %.2f w %.2f %.2f m %.2f %.2f l S', $r, $g, $b, $lw, $x1, $this->ty($y), $x2, $this->ty($y)));
    }

    /* ----------------------------- text flow ---------------------------- */
    public function textWidth(string $s): float {
        $w = 0.0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = ord($s[$i]);
            $w += ($c >= 32 && $c <= 126) ? (self::WIDTHS[$c - 32] ?? 556) : 556;
        }
        return $w * $this->size / 1000;
    }

    public function wrap(string $s, float $maxW): array {
        $lines = [];
        foreach (preg_split('/\n/', $s) as $para) {
            $words = preg_split('/ +/', trim($para));
            $line = '';
            foreach ($words as $word) {
                if ($word === '') continue;
                $try = $line === '' ? $word : $line . ' ' . $word;
                if ($this->textWidth($try) <= $maxW || $line === '') {
                    $line = $try;
                } else {
                    $lines[] = $line;
                    $line = $word;
                }
            }
            $lines[] = $line;
        }
        return $lines;
    }

    /** draw wrapped text at cursor, return new y */
    public function para(string $s, float $maxW, ?float $leading = null): float {
        $leading = $leading ?? $this->size * 1.4;
        foreach ($this->wrap($s, $maxW) as $line) {
            $this->text($this->x, $this->y, $line);
            $this->y += $leading;
            $this->checkPageBreak($leading);
        }
        return $this->y;
    }

    public function cursor(float $x, float $y): void {
        $this->x = $x;
        $this->y = $y;
    }

    public function getX(): float { return $this->x; }
    public function getY(): float { return $this->y; }

    public function move(float $dx, float $dy): void {
        $this->x += $dx;
        $this->y += $dy;
    }

    public function newLine(?float $d = null): float {
        $this->y += $d ?? $this->size * 1.4;
        $this->checkPageBreak($d ?? $this->size * 1.4);
        return $this->y;
    }

    private function checkPageBreak(float $needed): void {
        if ($this->y + $needed > self::H - 56) {
            $this->addPage();
        }
    }

    /* ----------------------------- build -------------------------------- */
    public function build(string $title = 'Library Report'): string {
        $n = count($this->pages);

        // stamp a footer on every page
        foreach ($this->pages as $i => &$lines) {
            $lines[] = sprintf('0.55 0.55 0.6 rg BT /F1 8 Tf 48 %.2f Td (%s) Tj ET',
                $this->ty(self::H - 34), $this->esc('Page ' . ($i + 1) . ' of ' . $n));
            $lines[] = sprintf('BT /F1 8 Tf %.2f %.2f Td (%s) Tj ET',
                $this->ty(self::H - 34), self::W - 48 - 8 * 0.6 * strlen($title), $this->esc($title));
        }
        unset($lines);

        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';

        $fontIds = [];
        $next = 3;
        foreach (self::FONTS as $key => $base) {
            $fontIds[$key] = $next;
            $objs[$next++] = '<< /Type /Font /Subtype /Type1 /BaseFont /' . $base . ' /Encoding /WinAnsiEncoding >>';
        }

        $kids = '';
        foreach ($this->pages as $streams) {
            $content = implode("\n", $streams);
            $pageId = $next++;
            $contId = $next++;
            $objs[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::W . ' ' . self::H . '] '
                . '/Resources << /Font << '
                . '/F1 ' . $fontIds['F1'] . ' 0 R /F2 ' . $fontIds['F2'] . ' 0 R /F3 ' . $fontIds['F3'] . ' 0 R'
                . ' >> >> /Contents ' . $contId . ' 0 R >>';
            $objs[$contId] = '<< /Length ' . strlen($content) . ' >>' . "\nstream\n" . $content . "\nendstream";
            $kids .= $pageId . ' 0 R ';
        }
        $objs[2] = '<< /Type /Pages /Kids [' . trim($kids) . '] /Count ' . $n . ' >>';

        ksort($objs);
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($out);
        $max = max(array_keys($objs));
        $out .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $out .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
        return $out;
    }

    public function send(string $filename, string $title = 'Library Report'): void {
        if (ob_get_length()) ob_end_clean();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        echo $this->build($title);
    }
}
