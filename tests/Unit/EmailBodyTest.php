<?php

namespace Tests\Unit;

use App\Support\EmailBody;
use PHPUnit\Framework\TestCase;

class EmailBodyTest extends TestCase
{
    public function test_it_prefers_the_plain_text_part_and_normalises_newlines(): void
    {
        $out = EmailBody::toText("שורה 1\r\nשורה 2\r\n\r\n\r\nשורה 3", '<p>ignored</p>');

        $this->assertSame("שורה 1\nשורה 2\n\nשורה 3", $out);
    }

    public function test_it_converts_html_only_email_to_text_with_line_breaks(): void
    {
        $html = '<div>שלום,</div><p>שורה ראשונה<br>שורה שנייה</p><p>תודה</p>';

        $out = EmailBody::toText(null, $html);

        // Block tags and <br> became real newlines instead of one run.
        $this->assertStringContainsString("שורה ראשונה\nשורה שנייה", $out);
        $this->assertStringContainsString('שלום,', $out);
        $this->assertStringContainsString('תודה', $out);
        $this->assertStringNotContainsString('<', $out);
    }

    public function test_it_strips_script_and_style_content(): void
    {
        $html = '<style>.x{color:red}</style><p>נראה</p><script>alert(1)</script>';

        $out = EmailBody::toText(null, $html);

        $this->assertStringContainsString('נראה', $out);
        $this->assertStringNotContainsString('alert', $out);
        $this->assertStringNotContainsString('color:red', $out);
    }

    public function test_it_decodes_html_entities(): void
    {
        $this->assertSame('a & b', EmailBody::toText(null, '<p>a &amp; b</p>'));
    }

    public function test_empty_in_empty_out(): void
    {
        $this->assertSame('', EmailBody::toText(null, null));
        $this->assertSame('', EmailBody::toText('   ', '   '));
    }
}
