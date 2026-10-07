<?php

namespace CarlJanzell\FilamentInsights;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Support\Concerns\EvaluatesClosures;
use Filament\Widgets\Widget;

class FilamentInsightsPlugin implements Plugin
{
    use EvaluatesClosures;

    /**
     * Hand-written widgets an admin may place on a dashboard as-is.
     *
     * @var array<int, class-string<Widget>>
     */
    protected array $widgets = [];

    protected bool|Closure $canManage = true;

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static $plugin */
        $plugin = filament(app(static::class)->getId());

        return $plugin;
    }

    public function getId(): string
    {
        return 'insights';
    }

    public function register(Panel $panel): void {}

    public function boot(Panel $panel): void {}

    /**
     * @param  array<int, class-string<Widget>>  $widgets
     */
    public function widgets(array $widgets): static
    {
        $this->widgets = $widgets;

        return $this;
    }

    /**
     * @return array<int, class-string<Widget>>
     */
    public function getWidgets(): array
    {
        return $this->widgets;
    }

    /**
     * Who may edit dashboards. Everyone who can open the page can view it.
     */
    public function canManage(bool|Closure $condition): static
    {
        $this->canManage = $condition;

        return $this;
    }

    public function isManageable(): bool
    {
        return (bool) $this->evaluate($this->canManage);
    }
}
