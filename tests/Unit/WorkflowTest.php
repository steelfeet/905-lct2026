<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class WorkflowTest extends TestCase
{
    public function testPhaseRequiresConfirmationEvenPhases(): void
    {
        foreach ([2, 4, 6, 8, 10, 12, 14] as $n) {
            $this->assertTrue(phase_requires_confirmation($n), "phase $n");
        }
    }

    public function testPhaseRequiresConfirmationOddPhases(): void
    {
        foreach ([1, 3, 5, 7, 9, 11, 13] as $n) {
            $this->assertFalse(phase_requires_confirmation($n), "phase $n");
        }
    }

    public function testPhaseRequiresConfirmationNonPositive(): void
    {
        $this->assertFalse(phase_requires_confirmation(0));
        $this->assertFalse(phase_requires_confirmation(-1));
    }

    public function testBuildProjectWorkflowAssignsStates(): void
    {
        $wf = build_project_workflow(
            [
                ['num' => 1, 'name' => 'S1', 'side' => 'internal'],
                ['num' => 2, 'name' => 'S2', 'side' => 'both'],
                ['num' => 3, 'name' => 'S3', 'side' => 'external'],
            ],
            ['internal' => 'I', 'external' => 'E', 'both' => 'B'],
            [
                'completed'        => ['label' => 'C', 'color' => '#0f0', 'fill' => 100],
                'in_progress'      => ['label' => 'P', 'color' => '#00f', 'fill' => 60],
                'waiting_external' => ['label' => 'W', 'color' => '#fa0', 'fill' => 40],
                'needs_revision'   => ['label' => 'R', 'color' => '#f00', 'fill' => 25],
                'pending'          => ['label' => 'O', 'color' => '#ccc', 'fill' => 0],
            ],
            ['in_progress', 'waiting_external', 'needs_revision'],
            2,   // current phase
            0    // project id → in_progress
        );

        $this->assertCount(3, $wf);
        $this->assertSame('completed', $wf[0]['state']);
        $this->assertSame('in_progress', $wf[1]['state']);
        $this->assertSame('pending', $wf[2]['state']);
        $this->assertTrue($wf[1]['isCurrent']);
        $this->assertFalse($wf[0]['isCurrent']);
        $this->assertSame('#00f', $wf[1]['color']);
        $this->assertSame(60, $wf[1]['fill']);
    }

    public function testBuildProjectWorkflowIsDeterministic(): void
    {
        $args = [
            [['num' => 1, 'name' => 'S1', 'side' => 'internal']],
            ['internal' => 'I'],
            ['completed' => ['label' => 'C', 'color' => '#0f0', 'fill' => 100],
             'in_progress' => ['label' => 'P', 'color' => '#00f', 'fill' => 60],
             'waiting_external' => ['label' => 'W', 'color' => '#fa0', 'fill' => 40],
             'needs_revision' => ['label' => 'R', 'color' => '#f00', 'fill' => 25],
             'pending' => ['label' => 'O', 'color' => '#ccc', 'fill' => 0]],
            ['in_progress', 'waiting_external', 'needs_revision'],
        ];
        $a = build_project_workflow(...$args, 5, 42);
        $b = build_project_workflow(...$args, 5, 42);
        $this->assertSame($a, $b);
    }

    public function testProjectCurrentPhaseEmptyHistory(): void
    {
        $this->assertSame(0, project_current_phase([]));
        $this->assertSame(0, project_current_phase(['history' => []]));
    }

    public function testProjectCurrentPhaseReturnsLast(): void
    {
        $proj = ['history' => [
            ['num' => 1, 'date' => '02.09', 'note' => ''],
            ['num' => 2, 'date' => '09.09', 'note' => ''],
            ['num' => 3, 'date' => '23.09', 'note' => ''],
        ]];
        $this->assertSame(3, project_current_phase($proj));
    }

    public function testProjectCurrentPhaseHandlesReturn(): void
    {
        // Возврат с 3 на 2 — текущая фаза должна быть 2
        $proj = ['history' => [
            ['num' => 1, 'date' => '02.09', 'note' => ''],
            ['num' => 2, 'date' => '09.09', 'note' => ''],
            ['num' => 3, 'date' => '23.09', 'note' => ''],
            ['num' => 2, 'date' => '30.09', 'note' => ''],
        ]];
        $this->assertSame(2, project_current_phase($proj));
    }
}