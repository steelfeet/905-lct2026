<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class UtilsTest extends TestCase
{
    public function testEscapeHtmlTags(): void
    {
        $this->assertSame(
            '&lt;script&gt;alert(1)&lt;/script&gt;',
            e('<script>alert(1)</script>')
        );
    }

    public function testEscapeQuotesAndAmpersand(): void
    {
        // ENT_QUOTES — оба типа кавычек
        $this->assertSame('&quot;x&quot; &amp; &#039;y&#039;', e('"x" & \'y\''));
    }

    public function testEscapeIsIdempotent(): void
    {
        $once = e('<a href="x">');
        $twice = e($once);
        $this->assertSame($once, $twice);
    }

    public function testEscapeUtf8(): void
    {
        $this->assertSame('Привет', e('Привет'));
        $this->assertSame('&lt;тег&gt;', e('<тег>'));
    }

    public function testUniInitialCyrillic(): void
    {
        $this->assertSame('К', uni_initial('КФУ'));
        $this->assertSame('М', uni_initial('МФТИ'));
        $this->assertSame('С', uni_initial('СПбГУ'));
    }

    public function testUniInitialLowercaseCyrillic(): void
    {
        // а..я → А..Я (граница 0x30 → 0x10 в UTF-8)
        $this->assertSame('А', uni_initial('абв'));
        $this->assertSame('Я', uni_initial('ябв'));
    }

    public function testUniInitialLatin(): void
    {
        $this->assertSame('M', uni_initial('MIT'));
        $this->assertSame('A', uni_initial('abc'));
    }

    public function testUniInitialEdgeCases(): void
    {
        $this->assertSame('?', uni_initial(''));
        $this->assertSame('?', uni_initial('   '));
    }

    public function testProjectPointTopLeftCorner(): void
    {
        // lat=max, lng=min → (0, 0)
        $p = project_point(82.0, 19.0, 1000, 500, 19.0, 180.0, 41.0, 82.0);
        $this->assertSame([0.0, 0.0], $p);
    }

    public function testProjectPointBottomRightCorner(): void
    {
        // lat=min, lng=max → (width, height)
        $p = project_point(41.0, 180.0, 1000, 500, 19.0, 180.0, 41.0, 82.0);
        $this->assertSame([1000.0, 500.0], $p);
    }

    public function testProjectPointMoscow(): void
    {
        // 55.75, 37.62 — где-то в средней полосе, x>0, y в середине
        [$x, $y] = project_point(55.75, 37.62, 1000, 500, 19.0, 180.0, 41.0, 82.0);
        $this->assertGreaterThan(0, $x);
        $this->assertLessThan(1000, $x);
        $this->assertGreaterThan(0, $y);
        $this->assertLessThan(500, $y);
    }
}