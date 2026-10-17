<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class JournalTest extends TestCase
{
    public function testAggregateEmpty(): void
    {
        $s = aggregate_journal([]);
        $this->assertSame(0, $s['cells']);
        $this->assertNull($s['avg']);
        $this->assertNull($s['attendance']);
    }

    public function testAggregateMixed(): void
    {
        // 2 студента × 4 занятия
        $s = aggregate_journal([
            1 => ['5', '4', '·', 'н'],
            2 => ['3', '2', '·', '·'],
        ]);
        $this->assertSame(8,   $s['cells']);
        $this->assertSame(1,   $s['absent']);    // н
        $this->assertSame(7,   $s['present']);   // 5,4,·,3,2,·,·
        $this->assertSame(4,   $s['gradeCount']);
        $this->assertSame(14,  $s['gradeSum']);
        $this->assertSame(3.5, $s['avg']);
        $this->assertSame(87.5, $s['attendance']); // 7/8
        $this->assertSame(1, $s['dist'][5]);
        $this->assertSame(1, $s['dist'][4]);
        $this->assertSame(1, $s['dist'][3]);
        $this->assertSame(1, $s['dist'][2]);
    }

    public function testAggregateAllAbsent(): void
    {
        $s = aggregate_journal([1 => ['н', 'н']]);
        $this->assertSame(2, $s['absent']);
        $this->assertSame(0, $s['present']);
        $this->assertNull($s['avg']);
        $this->assertSame(0.0, $s['attendance']);
    }

    public function testAggregateAllFive(): void
    {
        $s = aggregate_journal([1 => ['5', '5'], 2 => ['5', '5']]);
        $this->assertSame(20, $s['gradeSum']);
        $this->assertSame(4,  $s['gradeCount']);
        $this->assertSame(5.0, $s['avg']);
        $this->assertSame(100.0, $s['attendance']);
    }
}