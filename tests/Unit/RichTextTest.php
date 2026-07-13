<?php

namespace Tests\Unit;

use App\Support\RichText;
use PHPUnit\Framework\TestCase;

class RichTextTest extends TestCase
{
    public function test_it_converts_bold_and_italic_to_whatsapp_markup(): void
    {
        $out = RichText::toWhatsapp('<p>שלום <strong>מודגש</strong> ו<em>נטוי</em></p>');

        $this->assertSame('שלום *מודגש* ו_נטוי_', $out);
    }

    public function test_it_converts_bulleted_and_numbered_lists(): void
    {
        $out = RichText::toWhatsapp('<ul><li>אחד</li><li>שתיים</li></ul><ol><li>ראשון</li><li>שני</li></ol>');

        $this->assertStringContainsString('• אחד', $out);
        $this->assertStringContainsString('• שתיים', $out);
        $this->assertStringContainsString('1. ראשון', $out);
        $this->assertStringContainsString('2. שני', $out);
    }

    public function test_it_renders_links_as_text_and_url(): void
    {
        $out = RichText::toWhatsapp('<p><a href="https://multidigital.co.il">האתר</a></p>');

        $this->assertSame('האתר (https://multidigital.co.il)', $out);
    }

    public function test_it_never_leaks_raw_html_tags(): void
    {
        $out = RichText::toWhatsapp('<p>טקסט <strong>מודגש</strong> <a href="https://x.co">קישור</a></p><ul><li>פריט</li></ul>');

        $this->assertStringNotContainsString('<', $out);
        $this->assertStringNotContainsString('>', $out);
    }

    public function test_empty_in_empty_out(): void
    {
        $this->assertSame('', RichText::toWhatsapp(null));
        $this->assertSame('', RichText::toWhatsapp('   '));
    }
}
