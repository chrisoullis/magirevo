<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\RequiresAdministrator;
use Filament\Widgets\Widget;

/**
 * A widget whose data source does not exist (yet). It never shows numbers, only why there are none.
 */
abstract class UnavailableMetricWidget extends Widget
{
    use RequiresAdministrator;

    protected string $view = 'filament.widgets.metric-unavailable';

    protected int|string|array $columnSpan = ['default' => 'full', 'xl' => 1];

    abstract protected function key(): string;

    abstract protected function icon(): string;

    public function heading(): string
    {
        return __("dashboard.{$this->key()}.heading");
    }

    public function description(): string
    {
        return __("dashboard.{$this->key()}.description");
    }

    public function reason(): string
    {
        return __("dashboard.{$this->key()}.unavailable");
    }
}
