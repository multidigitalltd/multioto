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

    public function test_safe_html_keeps_formatting_and_links(): void
    {
        $html = '<p>שלום <strong>מודגש</strong> ו<em>נטוי</em></p>'
            .'<ul><li>אחד</li><li>שתיים</li></ul>'
            .'<a href="https://multidigital.co.il">קישור</a>';

        $out = EmailBody::toSafeHtml($html);

        $this->assertStringContainsString('<strong>מודגש</strong>', $out);
        $this->assertStringContainsString('<em>נטוי</em>', $out);
        $this->assertStringContainsString('<li>אחד</li>', $out);
        $this->assertStringContainsString('href="https://multidigital.co.il"', $out);
        // Links are neutralised against tab-nabbing / referrer leakage.
        $this->assertStringContainsString('rel="noopener nofollow noreferrer"', $out);
    }

    public function test_safe_html_strips_scripts_styles_handlers_and_dangerous_urls(): void
    {
        $html = '<p onclick="steal()">טקסט</p>'
            .'<script>alert(1)</script>'
            .'<style>.x{color:red}</style>'
            .'<div style="color:red">צבע</div>'
            .'<a href="javascript:alert(2)">רע</a>'
            .'<img src="http://tracker/pixel.gif">';

        $out = EmailBody::toSafeHtml($html);

        $this->assertStringNotContainsString('script', $out);
        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringNotContainsString('style', $out);
        $this->assertStringNotContainsString('color:red', $out);
        $this->assertStringNotContainsString('javascript:', $out);
        // Inline images are dropped so remote tracking pixels never load.
        $this->assertStringNotContainsString('<img', $out);
        $this->assertStringContainsString('טקסט', $out);
    }

    public function test_safe_html_is_null_when_there_is_no_html_or_only_plain_content(): void
    {
        $this->assertNull(EmailBody::toSafeHtml(null));
        $this->assertNull(EmailBody::toSafeHtml('   '));
        // Sanitizes down to text only → nothing richer than the plain body.
        $this->assertNull(EmailBody::toSafeHtml('<script>alert(1)</script>'));
    }

    public function test_safe_html_drops_all_remote_loading_media(): void
    {
        $html = '<p>שלום</p>'
            .'<video src="https://tracker/x.mp4" poster="https://tracker/p.jpg"></video>'
            .'<audio src="https://tracker/a.mp3"></audio>'
            .'<picture><source srcset="https://tracker/s.jpg"></picture>'
            .'<track src="https://tracker/c.vtt">';

        $out = EmailBody::toSafeHtml($html);

        $this->assertStringContainsString('שלום', $out);
        foreach (['video', 'audio', 'source', 'track', 'picture', 'tracker'] as $needle) {
            $this->assertStringNotContainsString($needle, $out);
        }
    }

    public function test_safe_html_strips_the_document_wrapper_of_a_full_html_email(): void
    {
        // Some mail clients send a full document, not a fragment. A nested
        // <html>/<body> rendered inside the panel re-parents the DOM and
        // duplicates the Alpine/Livewire runtime — the wrapper must be dropped
        // while its content survives.
        $html = "<html>\n<body lang=\"EN-US\" link=\"#467886\">\n<div><p dir=\"RTL\">שלום</p></div>\n</body>\n</html>";

        $out = (string) EmailBody::toSafeHtml($html);

        $this->assertStringContainsString('שלום', $out);
        $this->assertStringNotContainsString('<html', $out);
        $this->assertStringNotContainsString('<body', $out);
        $this->assertStringNotContainsString('</body>', $out);
    }

    public function test_safe_html_falls_back_to_plain_body_when_too_large_to_render(): void
    {
        // Over the sanitizer input cap → null, so the caller shows the full
        // plain body instead of a silently truncated rich one.
        $huge = '<p>'.str_repeat('א', 600_000).'</p>';

        $this->assertNull(EmailBody::toSafeHtml($huge));
    }
}
