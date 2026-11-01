<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\RequiresAdministrator;
use Filament\Widgets\Widget;

class RecentApplicationErrors extends Widget
{
    use RequiresAdministrator;

    protected static ?int $sort = 84;

    protected string $view = 'filament.widgets.recent-errors';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        return ['summary' => $this->system()->logSummary()];
    }
}
