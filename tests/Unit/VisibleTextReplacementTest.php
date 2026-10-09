<?php

namespace Tests\Unit;

use App\Services\SiteAgent\VisibleTextReplacement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class VisibleTextReplacementTest extends TestCase
{
    #[DataProvider('quotes')]
    public function test_only_one_literal_quote_in_a_text_node_is_editable(string $html, string $find, bool $expected): void
    {
        $this->assertSame($expected, VisibleTextReplacement::matchesOnce($html, $find));
    }

    public static function quotes(): array
    {
        return [
            'plain' => ['Welcome home', 'Welcome', true],
            'visible anchor' => ['<a href="/contact/">Contact</a>', 'Contact', true],
            'href' => ['<a href="/contact/">Contact</a>', '/contact/', false],
            'attribute with greater-than' => ['<a title="x > secret">Contact</a>', 'secret', false],
            'split nodes cannot authorize attribute' => ['<a href="foobar">foo<b>bar</b></a>', 'foobar', false],
            'comment' => ['Hello <!-- hidden --> world', 'hidden', false],
            'script' => ['<script>secret</script> Welcome', 'secret', false],
            'unclosed script' => ['<script>secretJS', 'secretJS', false],
            'unfinished attribute' => ['<a href="/contact/', '/contact/', false],
            'style' => ['<style>.secret{}</style> Welcome', 'secret', false],
            'duplicate' => ['Hello Hello', 'Hello', false],
            'markup quote' => ['<b>Hello</b>', '<b>Hello</b>', false],
        ];
    }
}
