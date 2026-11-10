<?php

use CarlJanzell\FilamentInsights\FilamentInsightsPlugin;
use Illuminate\Support\Facades\Blade;

$guide = __DIR__.'/../../resources/boost/guidelines/core.blade.php';

it('documents every config method in the agent guide', function () use ($guide): void {
    $text = file_get_contents($guide);

    $missing = collect((new ReflectionClass(FilamentInsightsPlugin::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->filter(fn (ReflectionMethod $method): bool => ! $method->isStatic() && (string) $method->getReturnType() === 'static')
        ->map(fn (ReflectionMethod $method): string => $method->getName())
        ->reject(fn (string $name): bool => str_contains($text, "{$name}("))
        ->values()
        ->all();

    expect($missing)->toBe([]);
});

it('renders the agent guide as Blade', function () use ($guide): void {
    expect(Blade::render(file_get_contents($guide)))->toContain('FilamentInsightsPlugin::make()');
});
