<?php

namespace Tests\Feature;

use Tests\TestCase;

class EmployeeSelectionIndicatorUiTest extends TestCase
{
    public function test_employee_selection_uses_an_animated_check_and_profile_shift_only_while_selected(): void
    {
        $row = file_get_contents(resource_path('views/components/tables/rows/employees/employee-row.blade.php'));
        $preview = file_get_contents(resource_path('views/components/user/person-anchor-preview.blade.php'));
        $publicInfo = file_get_contents(resource_path('views/components/user/public-info.blade.php'));
        $table = file_get_contents(resource_path('views/components/tables/table.blade.php'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString(':selection-indicator="true"', $row);
        $this->assertStringContainsString("'selectionIndicator' => false", $preview);
        $this->assertStringContainsString(':selection-indicator="$selectionIndicator"', $preview);
        $this->assertStringContainsString('@if ($selectionIndicator)', $publicInfo);
        $this->assertSame(2, substr_count($publicInfo, 'data-checked="{{ $selected ? \'true\' : \'false\' }}"'));
        $this->assertStringContainsString('rt-person-selection-indicator__icon', $publicInfo);
        $this->assertStringContainsString('aria-hidden="true"', $publicInfo);
        $this->assertStringNotContainsString('h-4 w-0.5', $publicInfo);
        $this->assertStringNotContainsString('<input', $publicInfo);
        $this->assertLessThan(
            strpos($publicInfo, '<span class="relative shrink-0">'),
            strpos($publicInfo, 'class="rt-person-selection-slot"'),
        );
        $this->assertStringContainsString('aria-selected="{{ $isSelected ? \'true\' : \'false\' }}"', $table);

        $this->assertStringContainsString(".rt-person-selection-slot[data-checked='true']", $styles);
        $this->assertStringContainsString(".rt-person-selection-indicator[data-checked='true']", $styles);
        $this->assertMatchesRegularExpression(
            '/\\.rt-person-selection-slot\\s*\\{(?=[^}]*width:\\s*0)(?=[^}]*flex:\\s*0 0 0)(?=[^}]*width 420ms)(?=[^}]*flex-basis 420ms)[^}]*\\}/s',
            $styles,
        );
        $this->assertStringContainsString('flex-basis: 1.125rem', $styles);
        $this->assertStringContainsString('transform: translate(-50%, -50%) scale(1) rotate(0deg)', $styles);
        $this->assertStringContainsString('stroke-dashoffset: 18', $styles);
        $this->assertStringContainsString('stroke-dashoffset: 0', $styles);
        $this->assertMatchesRegularExpression(
            '/\\.rt-person-selection-indicator\\s*\\{(?=[^}]*opacity:\\s*0)(?=[^}]*visibility:\\s*hidden)[^}]*\\}/s',
            $styles,
        );
        $this->assertMatchesRegularExpression(
            '/\\.rt-person-selection-indicator\\[data-checked=\'true\'\\]\\s*\\{(?=[^}]*opacity:\\s*1)(?=[^}]*visibility:\\s*visible)[^}]*\\}/s',
            $styles,
        );
        $this->assertMatchesRegularExpression(
            '/\\.rt-table-frame\\.rt-employee-table \\.rt-table-row\\.rt-table-row-selected\\s*\\{\\s*box-shadow:\\s*inset 0 0 0 1px/s',
            $styles,
        );
        $this->assertStringContainsString('.rt-table-row.rt-table-row-selected:focus-visible', $styles);
        $this->assertStringContainsString('inset 4px 0 0 #e4002b', $styles);
        $this->assertMatchesRegularExpression(
            '/@media \\(prefers-reduced-motion: reduce\\)[\\s\\S]*?\\.rt-person-selection-slot,[\\s\\S]*?transition:\\s*none !important/s',
            $styles,
        );
    }
}
