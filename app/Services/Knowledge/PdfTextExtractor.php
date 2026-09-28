<?php

namespace App\Services\Knowledge;

use RuntimeException;
use Smalot\PdfParser\Parser;

class PdfTextExtractor
{
    /**
     * @return list<array{page: int, text: string}> pages with non-empty text
     */
    public function extract(string $absolutePath): array
    {
        $document = (new Parser)->parseFile($absolutePath);

        $pages = [];
        foreach ($document->getPages() as $index => $page) {
            $text = $this->clean($page->getText());
            if ($text !== '') {
                $pages[] = ['page' => $index + 1, 'text' => $text];
            }
        }

        if ($pages === []) {
            throw new RuntimeException('No extractable text found. Scanned (image-only) PDFs are not supported yet.');
        }

        return $pages;
    }

    public function pageCount(string $absolutePath): int
    {
        return count((new Parser)->parseFile($absolutePath)->getPages());
    }

    public function clean(string $text): string
    {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = str_replace(["\r\n", "\r", "\t", "\u{00A0}"], ["\n", "\n", ' ', ' '], $text);
        $text = $this->joinGlyphLines($text);
        // Ligatures and compatibility forms: "ﬁ" → "fi". After joining, since it changes glyph counts.
        $text = \Normalizer::normalize($text, \Normalizer::FORM_KC) ?: $text;
        // Join words hyphenated across line breaks
        $text = preg_replace('/(\p{L})-\n(\p{L})/u', '$1$2', $text);
        $text = preg_replace('/[ ]{2,}/', ' ', $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    /**
     * Some generators (Photoshop, Canva, certain Word exports) place every glyph
     * separately, so the parser returns one character per line ("G\nü\nl\nn\na\nr").
     * Real word gaps survive as lines containing a single space. When most lines are
     * single glyphs, runs of such lines are glued back together.
     */
    private function joinGlyphLines(string $text): string
    {
        $lines = explode("\n", $text);
        $nonEmpty = array_filter($lines, fn (string $line) => $line !== '');
        if (count($nonEmpty) < 20) {
            return $text;
        }

        $single = count(array_filter($nonEmpty, fn (string $line) => mb_strlen($line) === 1));
        if ($single / count($nonEmpty) < 0.6) {
            return $text;
        }

        $out = '';
        $inRun = false;
        foreach ($lines as $line) {
            if (mb_strlen($line) === 1) {
                $out .= $line;
                $inRun = true;

                continue;
            }
            if ($inRun) {
                $out .= "\n";
                $inRun = false;
            }
            $out .= $line."\n";
        }

        return $out;
    }
}
