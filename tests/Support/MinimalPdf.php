<?php

namespace Tests\Support;

/**
 * Builds a tiny valid text PDF (one line of Helvetica per page) for pipeline tests.
 */
class MinimalPdf
{
    /** @param list<string> $pages ASCII text, one string per page */
    public static function make(array $pages): string
    {
        $objects = [];
        $pageCount = count($pages);
        $fontId = 3 + $pageCount * 2;

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        foreach ($pages as $i => $text) {
            $pageId = 3 + $i * 2;
            $contentId = $pageId + 1;
            $kids[] = "{$pageId} 0 R";
            $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
            $stream = "BT /F1 12 Tf 50 750 Td ({$escaped}) Tj ET";
            $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents {$contentId} 0 R /Resources << /Font << /F1 {$fontId} 0 R >> >> >>";
            $objects[$contentId] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream";
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids)."] /Count {$pageCount} >>";
        $objects[$fontId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }

        $xref = strlen($pdf);
        $size = count($objects) + 1;
        $pdf .= "xref\n0 {$size}\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size {$size} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

        return $pdf;
    }
}
