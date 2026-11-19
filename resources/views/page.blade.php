<x-filament-panels::page>
    @php
        $widgets = $this->getDashboardMounts();
        $canManage = \CarlJanzell\FilamentInsights\FilamentInsightsPlugin::get()->isManageable();
        $arrangeable = $canManage && ! $this->previewing;
        $spans = \CarlJanzell\FilamentInsights\Pages\InsightsPage::SPANS;
        $spanOrder = array_keys($spans);
    @endphp

    @if (blank($widgets))
        <x-filament::section>
            <div style="padding: 2.5rem 1rem; text-align: center">
                <x-filament::icon
                    icon="heroicon-o-presentation-chart-line"
                    style="width: 2.75rem; height: 2.75rem; margin: 0 auto 0.85rem; opacity: 0.55"
                />
                <p style="font-weight: 600; font-size: 1.05rem">No widgets yet</p>
                <p style="margin-top: 0.35rem; font-size: 0.875rem; opacity: 0.72; max-width: 28rem; margin-inline: auto">
                    {{ $canManage
                        ? 'Build your dashboard with KPI counters, time trends, category breakdowns, or custom widgets.'
                        : 'Nothing has been added to this dashboard yet.' }}
                </p>
                @if ($canManage)
                    <div style="margin-top: 1.25rem">
                        <x-filament::button
                            icon="heroicon-o-plus"
                            wire:click="mountAction('addWidget')"
                        >
                            Add your first widget
                        </x-filament::button>
                    </div>
                @endif
            </div>
        </x-filament::section>
    @else
        @php
            // Attribute bags, not @if inside the tags: Livewire 3 injects a morph marker at every
            // @if, and one inside a tag's attribute list closes that tag early.
            // Keep x-sortable mounted for managers so toggling Preview never remounts charts.
            $gridArrangement = new \Illuminate\View\ComponentAttributeBag($canManage ? [
                'role' => 'list',
                'x-sortable' => '',
                'x-on:end.stop' => '$event.oldIndex !== $event.newIndex && save()',
                'x-on:keydown' => 'nudge($event)',
            ] : []);
        @endphp

        {{--
            Plain CSS grid with container queries: responds to the actual dashboard pane width
            (even when Filament's sidebar is open), and never relies on host Tailwind compilation.
            Managers rearrange it with Filament's own x-sortable (SortableJS, shipped on v3–v5):
            mouse drags at once, touch after a press-and-hold so the page still scrolls, and
            Alt + arrow keys move the focused widget one place.

            Motion (resources/js/motion.js, on anime.js) is imported only here and only when the
            viewer allows it. `fi-insights-pending` hides the widgets until it takes over; a CSS
            fallback below shows them anyway if it never does. wire:ignore.self keeps Livewire
            from putting that class back on later renders.
        --}}
        <div class="fi-insights-wrap">
            <div
                class="fi-insights-grid fi-insights-pending"
                wire:ignore.self
                data-anime-src="{{ \Filament\Support\Facades\FilamentAsset::getScriptSrc('anime', \CarlJanzell\FilamentInsights\InsightsServiceProvider::PACKAGE) }}"
                data-motion-src="{{ \Filament\Support\Facades\FilamentAsset::getScriptSrc('motion', \CarlJanzell\FilamentInsights\InsightsServiceProvider::PACKAGE) }}"
                x-data="{
                    init() {
                        const started = performance.now()

                        window.filamentInsightsMotion ??= matchMedia('(prefers-reduced-motion: reduce)').matches
                            ? Promise.resolve(null)
                            : Promise.all([import(this.$el.dataset.animeSrc), import(this.$el.dataset.motionSrc)])
                                .then(([anime, motion]) => motion.default(anime), () => null)

                        // Too late for an entrance once the CSS fallback may already have shown the widgets.
                        window.filamentInsightsMotion.then((motion) => motion
                            ? motion.watch(this.$el, performance.now() - started < 1200)
                            : this.$el.classList.remove('fi-insights-pending'))

                        if (! this.$el.hasAttribute('x-sortable')) return

                        this.$nextTick(() => Object.entries({
                            delay: 350,
                            delayOnTouchOnly: true,
                            touchStartThreshold: 8,
                            filter: 'input, textarea, select, [contenteditable], .fi-insights-toolbar-actions, .fi-insights-toolbar-actions *',
                            preventOnFilter: false,
                            animation: matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 250,
                        }).forEach(([option, value]) => this.$el.sortable.option(option, value)))
                    },
                    nudge(event) {
                        const step = { ArrowLeft: -1, ArrowUp: -1, ArrowRight: 1, ArrowDown: 1 }[event.key]
                        const item = event.target

                        if (! event.altKey || ! step || item.parentElement !== this.$el || ! item.hasAttribute('x-sortable-handle')) return
                        event.preventDefault()

                        const to = step < 0 ? item.previousElementSibling : item.nextElementSibling
                        if (! to) return

                        this.$el.sortable.captureAnimationState()
                        to[step < 0 ? 'before' : 'after'](item)
                        this.$el.sortable.animateAll()
                        item.focus()
                        window.filamentInsightsMotion.then((motion) => motion?.land(item))
                        this.save()
                    },
                    save() {
                        this.$wire.reorderWidgets(this.$el.sortable.toArray())
                    },
                }"
                {{ $gridArrangement }}
            >
                @foreach ($widgets as $widget)
                    @php
                        $currentSpanIndex = array_search($widget['span'], $spanOrder, true);
                        $nextSpan = $spanOrder[$currentSpanIndex === false ? 0 : ($currentSpanIndex + 1) % count($spanOrder)];
                        $spanLabel = $spans[$widget['span']] ?? '½';
                        $nextSpanLabel = $spans[$nextSpan] ?? '½';

                        $cardAttrs = new \Illuminate\View\ComponentAttributeBag(array_filter([
                            'data-span' => (string) $widget['span'],
                            'data-accent' => $widget['color'] ?? null,
                            ...($arrangeable ? [
                                'role' => 'listitem',
                                'tabindex' => '0',
                                'aria-description' => 'Drag to rearrange, or hold Alt (Option) and press the arrow keys.',
                                'x-sortable-item' => $widget['key'],
                                'x-sortable-handle' => '',
                            ] : []),
                        ], fn (mixed $v): bool => $v !== null));
                    @endphp
                    <div
                        class="fi-insights-card"
                        wire:key="{{ $widget['key'] }}"
                        style="--fi-insights-span: {{ $widget['span'] }}"
                        {{ $cardAttrs }}
                    >
                        @if ($arrangeable)
                            <div class="fi-insights-toolbar" aria-label="Widget actions">
                                <span
                                    class="fi-insights-grip"
                                    title="Drag to move (or focus card and press Alt + Arrow keys)"
                                    aria-hidden="true"
                                >
                                    <x-filament::icon icon="heroicon-o-bars-2" style="width: 0.9rem; height: 0.9rem; flex-shrink: 0" />
                                </span>

                                <div class="fi-insights-toolbar-actions">
                                    <button
                                        type="button"
                                        class="fi-insights-tool-btn fi-insights-span-btn"
                                        title="Width: {{ $spanLabel }} — click to change to {{ $nextSpanLabel }}"
                                        wire:click="resizeWidget({{ \Illuminate\Support\Js::from($widget['key']) }}, {{ $nextSpan }})"
                                    >
                                        {{ $spanLabel }}
                                    </button>

                                    <button
                                        type="button"
                                        class="fi-insights-tool-btn"
                                        title="Edit widget"
                                        aria-label="Edit {{ $widget['label'] ?: 'widget' }}"
                                        wire:click="mountAction('editWidget', {{ \Illuminate\Support\Js::from(['key' => $widget['key']]) }})"
                                    >
                                        <x-filament::icon icon="heroicon-o-pencil-square" style="width: 0.9rem; height: 0.9rem" />
                                    </button>

                                    <button
                                        type="button"
                                        class="fi-insights-tool-btn"
                                        title="Duplicate widget"
                                        aria-label="Duplicate {{ $widget['label'] ?: 'widget' }}"
                                        wire:click="duplicateWidget({{ \Illuminate\Support\Js::from($widget['key']) }})"
                                    >
                                        <x-filament::icon icon="heroicon-o-document-duplicate" style="width: 0.9rem; height: 0.9rem" />
                                    </button>

                                    <button
                                        type="button"
                                        class="fi-insights-tool-btn fi-insights-tool-danger"
                                        title="Remove widget"
                                        aria-label="Remove {{ $widget['label'] ?: 'widget' }}"
                                        wire:click="mountAction('deleteWidget', {{ \Illuminate\Support\Js::from(['key' => $widget['key']]) }})"
                                    >
                                        <x-filament::icon icon="heroicon-o-trash" style="width: 0.9rem; height: 0.9rem" />
                                    </button>
                                </div>
                            </div>
                        @endif

                        <div class="fi-insights-card-body">
                            @livewire($widget['class'], $widget['props'], key($widget['key']))
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <style>
        .fi-insights-wrap {
            container-type: inline-size;
            container-name: fi-insights;
            width: 100%;
            min-width: 0;
        }
        .fi-insights-grid {
            display: grid;
            gap: 1.25rem;
            grid-template-columns: minmax(0, 1fr);
            align-items: start;
        }
        .fi-insights-card {
            position: relative;
            min-width: 0;
            max-width: 100%;
        }
        .fi-insights-card-body {
            min-width: 0;
            max-width: 100%;
            width: 100%;
            border-radius: 0.75rem;
        }
        /* Keep Chart.js canvases and Livewire roots constrained inside their grid track. */
        .fi-insights-card-body > *,
        .fi-insights-card-body .fi-wi-chart,
        .fi-insights-card-body .fi-section {
            min-width: 0;
            max-width: 100%;
        }
        .fi-insights-card-body .fi-wi-chart-canvas-ctn,
        .fi-insights-card-body .fi-section-content {
            min-width: 0;
            max-width: 100%;
            overflow: hidden;
        }
        .fi-insights-card-body canvas {
            max-width: 100% !important;
        }
        .fi-insights-grid > [x-sortable-handle] { cursor: grab; }
        .fi-insights-grid > [x-sortable-handle]:active { cursor: grabbing; }
        .fi-insights-grid > [x-sortable-handle]:focus-visible { outline: 2px solid; outline-offset: 2px; }

        /* Motion: motion.js animates these variables and removes them when done. Unset, `translate`
           and `scale` fall back to none, so a resting widget has no transform or stacking context. */
        .fi-insights-grid > * {
            border-radius: 0.75rem;
            translate: 0 var(--fi-rise);
            scale: var(--fi-scale);
            box-shadow:
                0 calc(var(--fi-lift, 0) * 18px) calc(var(--fi-lift, 0) * 40px) calc(var(--fi-lift, 0) * -12px) rgb(0 0 0 / calc(var(--fi-lift, 0) * 0.3)),
                0 0 0 2px color-mix(in srgb, currentColor calc(var(--fi-ring, 0) * 40%), transparent);
        }

        /* Subtle semantic accent strip hugging the card body when an accent color is chosen. */
        .fi-insights-card[data-accent] .fi-insights-card-body { border-top: 3px solid var(--fi-insights-accent); }
        .fi-insights-card[data-accent="primary"] { --fi-insights-accent: #3b82f6; }
        .fi-insights-card[data-accent="info"] { --fi-insights-accent: #06b6d4; }
        .fi-insights-card[data-accent="success"] { --fi-insights-accent: #10b981; }
        .fi-insights-card[data-accent="warning"] { --fi-insights-accent: #f59e0b; }
        .fi-insights-card[data-accent="danger"] { --fi-insights-accent: #ef4444; }
        .fi-insights-card[data-accent="gray"] { --fi-insights-accent: #64748b; }

        /* Floating manager toolbar on each card */
        .fi-insights-toolbar {
            position: absolute;
            top: 0.5rem;
            right: 0.6rem;
            z-index: 10;
            display: inline-flex;
            align-items: center;
            gap: 0.15rem;
            padding: 0.2rem 0.3rem;
            border-radius: 0.5rem;
            border: 1px solid color-mix(in srgb, currentColor 16%, transparent);
            background: color-mix(in srgb, Canvas 88%, transparent);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            box-shadow: 0 2px 10px rgb(0 0 0 / 0.12);
            opacity: 0.45;
            transition: opacity 150ms ease, box-shadow 150ms ease;
        }
        .dark .fi-insights-toolbar {
            background: color-mix(in srgb, #0f172a 86%, transparent);
            border-color: rgb(255 255 255 / 0.14);
        }
        .fi-insights-card:hover .fi-insights-toolbar,
        .fi-insights-card:focus-within .fi-insights-toolbar,
        .fi-insights-toolbar:hover {
            opacity: 1;
            box-shadow: 0 4px 14px rgb(0 0 0 / 0.18);
        }
        @media (hover: none) {
            .fi-insights-toolbar { opacity: 0.92; }
        }
        .fi-insights-grip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.2rem;
            opacity: 0.65;
            cursor: grab;
        }
        .fi-insights-toolbar-actions {
            display: inline-flex;
            align-items: center;
            gap: 0.15rem;
            flex-shrink: 0;
        }
        .fi-insights-tool-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 1.65rem;
            height: 1.65rem;
            padding: 0 0.28rem;
            border: 0;
            border-radius: 0.375rem;
            background: transparent;
            color: inherit;
            cursor: pointer;
            opacity: 0.82;
            transition: background-color 120ms ease, color 120ms ease, opacity 120ms ease;
        }
        .fi-insights-tool-btn:hover,
        .fi-insights-tool-btn:focus-visible {
            opacity: 1;
            background: color-mix(in srgb, currentColor 12%, transparent);
            outline: none;
        }
        .fi-insights-span-btn {
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.01em;
            padding: 0 0.4rem;
        }
        .fi-insights-tool-danger:hover,
        .fi-insights-tool-danger:focus-visible {
            color: #ef4444;
            background: rgb(239 68 68 / 0.15);
        }

        /* While dragging, the widget's own spot is a soft outlined slot rather than a faded copy. */
        .fi-insights-grid > .fi-sortable-ghost { opacity: 1; outline: 2px dashed color-mix(in srgb, currentColor 30%, transparent); outline-offset: 2px; }
        .fi-insights-grid > .fi-sortable-ghost > * { opacity: 0.2; }
        /* Touch drags a copy of the widget; SortableJS fades it to 0.8 inline, which muddies the lift. */
        .fi-insights-grid > .sortable-fallback { opacity: 1 !important; }

        /* Hidden until motion.js plays the entrance; shown regardless after 1.5s or with reduced motion. */
        .fi-insights-pending > * { opacity: 0; animation: fi-insights-reveal 1ms 1.5s forwards; }
        @keyframes fi-insights-reveal { to { opacity: 1; } }
        @media (prefers-reduced-motion: reduce) {
            .fi-insights-pending > * { opacity: 1; animation: none; }
        }

        /* Container-aware responsive grid: adapts to the actual dashboard pane width (accounting for sidebar). */
        @container fi-insights (min-width: 560px) {
            .fi-insights-grid { grid-template-columns: repeat(6, minmax(0, 1fr)); }
            .fi-insights-card[data-span="3"],
            .fi-insights-card[data-span="4"] { grid-column: span 3 / span 3; }
            .fi-insights-card[data-span="6"],
            .fi-insights-card[data-span="8"],
            .fi-insights-card[data-span="12"] { grid-column: span 6 / span 6; }
        }

        @container fi-insights (min-width: 900px) {
            .fi-insights-grid { grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 1.5rem; }
            .fi-insights-card { grid-column: span var(--fi-insights-span) / span var(--fi-insights-span); }
            /* Keep ¼ cards comfortable (3-across) until the pane is wide enough for 4-across. */
            .fi-insights-card[data-span="3"] { grid-column: span 4 / span 4; }
        }

        @container fi-insights (min-width: 1160px) {
            .fi-insights-card[data-span="3"] { grid-column: span 3 / span 3; }
        }

        /* Graceful fallback for older browsers without CSS Container Queries. */
        @supports not (container-type: inline-size) {
            @media (min-width: 1024px) {
                .fi-insights-grid { grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 1.5rem; }
                .fi-insights-grid > * { grid-column: span var(--fi-insights-span) / span var(--fi-insights-span); }
            }
        }
    </style>
</x-filament-panels::page>
