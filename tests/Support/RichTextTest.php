<?php

namespace FilamentAccounting\Tests\Support;

use FilamentAccounting\Support\RichText;
use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class RichTextTest extends TestCase
{
    #[Test]
    public function invoice_rich_text_keeps_formatting_but_removes_unsafe_markup(): void
    {
        $html = '<p class="lead"><strong>Hosting</strong><script>alert(1)</script></p><ul><li onclick="x">Managed</li></ul>';

        $this->assertSame('<p><strong>Hosting</strong></p><ul><li>Managed</li></ul>', RichText::sanitize($html));
        $this->assertSame("Hosting\n\n- Managed", RichText::plainText($html));
        $this->assertNull(RichText::sanitize('<script>alert(1)</script>'));
    }
}
