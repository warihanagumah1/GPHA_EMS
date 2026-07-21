<?php

namespace Tests\Unit;

use App\Support\RichText;
use PHPUnit\Framework\TestCase;

class RichTextTest extends TestCase
{
    public function test_it_keeps_supported_formatting_and_removes_unsafe_markup(): void
    {
        $clean=RichText::clean('<p onclick="alert(1)"><strong>Routine</strong></p><ul><li>Check</li></ul><script>alert(1)</script><img src=x onerror=alert(1)>');

        $this->assertSame('<p><strong>Routine</strong></p><ul><li>Check</li></ul>',$clean);
        $this->assertSame('Routine Check',RichText::plain($clean));
    }
}
