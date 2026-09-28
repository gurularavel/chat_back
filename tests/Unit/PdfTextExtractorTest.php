<?php

namespace Tests\Unit;

use App\Services\Knowledge\PdfTextExtractor;
use PHPUnit\Framework\TestCase;

class PdfTextExtractorTest extends TestCase
{
    public function test_one_glyph_per_line_output_is_joined_back_into_words(): void
    {
        // What smalot/pdfparser returns for Photoshop/Canva PDFs: every glyph on its own line,
        // real word gaps as lines with a single space.
        $raw = "8 aprel 2026\nSERTİFİKAT\n".implode("\n", mb_str_split('Bu sertiﬁkat, Gülnar Abdullayeva Elşən qızının'))."\n";

        $text = (new PdfTextExtractor)->clean($raw);

        $this->assertSame("8 aprel 2026\nSERTİFİKAT\nBu sertifikat, Gülnar Abdullayeva Elşən qızının", $text);
    }

    public function test_normal_text_is_left_intact(): void
    {
        $raw = "Qiymət siyahısı\nStandart paket ayda 20 AZN-dir.\nÇatdırılma pulsuzdur.";

        $this->assertSame($raw, (new PdfTextExtractor)->clean($raw));
    }
}
