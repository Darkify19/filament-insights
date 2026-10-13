/**
 * Refined motion for the insights grid, driven by anime.js (vendor/anime.esm.min.js, v4.5.0).
 *
 * Optional polish: the page imports this only when the viewer allows motion, and the grid
 * works the same, unanimated, if it never loads.
 *
 * The look lives in CSS custom properties on each widget (see page.blade.php). --fi-rise and
 * --fi-scale drive the separate `translate` and `scale` properties, so they never fight
 * SortableJS, which slides widgets with `transform`. --fi-lift and --fi-ring drive the shadow
 * and outline. This file only animates those numbers, then removes them again: a leftover
 * `scale` would keep a stacking context that traps a widget's fixed-position dropdowns.
 */

const STAT_VALUE = '.fi-wi-stats-overview-stat-value'

// "₱1,250.50", "1,234", "-3.5 kg": the number counts up, the text around it stays.
const NUMBER = /^(\D*?)(-?\d{1,3}(?:,\d{3})+|-?\d+)(\.\d+)?(\D*)$/

export default ({ animate, spring, stagger, utils }) => {
    const settle = spring({ bounce: 0.35, duration: 420 })
    const gentle = spring({ bounce: 0.12, duration: 650 })
    // Removes what an animation left behind. Not utils.cleanInlineStyles: that restores the
    // inline values from before the animation, and the entrance starts from an inline opacity: 0.
    const release = (elements, ...properties) => () => [elements].flat().forEach((element) =>
        properties.forEach((property) => element.style.removeProperty(property)))
    const counted = new WeakSet()

    const countUp = (element) => {
        if (counted.has(element)) {
            return
        }

        counted.add(element)

        // A value rendered as HTML (an HtmlString from a host widget) keeps its markup untouched.
        if (element.childElementCount) {
            return
        }

        const text = element.textContent.trim()
        const match = text.match(NUMBER)

        if (! match) {
            return
        }

        const [, prefix, whole, fraction = '', suffix] = match
        const decimals = Math.max(fraction.length - 1, 0)
        const grouped = whole.includes(',')
        const value = { current: 0 }
        const render = () => element.textContent = prefix + (grouped
            ? value.current.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals })
            : value.current.toFixed(decimals)) + suffix

        // Zero now, before the browser paints the final number for a frame.
        render()

        animate(value, {
            current: parseFloat(whole.replaceAll(',', '') + fraction),
            duration: 900,
            ease: 'outExpo',
            onUpdate: render,
            // End on the server's exact text, not a re-formatted float.
            onComplete: () => element.textContent = text,
        })
    }

    /** A small spring and a fading outline where a widget lands. */
    const land = (widget) => animate(widget, {
        '--fi-scale': { from: 0.97, to: 1, ease: settle },
        '--fi-ring': { from: 1, to: 0, ease: 'outQuad', duration: 700 },
        onComplete: release(widget, '--fi-scale', '--fi-ring'),
    })

    /**
     * @param {HTMLElement} grid
     * @param {boolean} enter  false when the scripts arrived too late to play an entrance
     */
    const watch = (grid, enter) => {
        const widgets = [...grid.children]

        if (enter) {
            utils.set(widgets, { opacity: 0 })
        }

        grid.classList.remove('fi-insights-pending')

        if (enter) {
            animate(widgets, {
                opacity: [0, 1],
                '--fi-rise': ['16px', '0px'],
                '--fi-scale': [0.98, 1],
                delay: stagger(55),
                ease: gentle,
                onComplete: release(widgets, 'opacity', '--fi-rise', '--fi-scale'),
            })
        }

        // Stats load lazily, so count each one up as it arrives.
        grid.querySelectorAll(STAT_VALUE).forEach(countUp)
        new MutationObserver((mutations) => mutations.forEach(({ addedNodes }) => addedNodes.forEach((node) => {
            if (node.nodeType !== Node.ELEMENT_NODE) {
                return
            }

            if (node.matches(STAT_VALUE)) {
                countUp(node)
            }

            node.querySelectorAll(STAT_VALUE).forEach(countUp)
        }))).observe(grid, { childList: true, subtree: true })

        // SortableJS events. A plain click fires choose and unchoose; a drag adds start and end.
        let dragged = false

        grid.addEventListener('choose', ({ item }) => {
            dragged = false
            animate(item, {
                '--fi-lift': { from: 0, to: 1, ease: 'outQuad', duration: 220 },
                '--fi-scale': { from: 1, to: 1.02, ease: settle },
            })
        })

        // The browser has already captured the lifted widget as its drag image. What stays
        // in the grid is the slot it will drop into, which CSS draws.
        grid.addEventListener('start', ({ item }) => {
            dragged = true
            utils.remove(item)
            release(item, '--fi-lift', '--fi-scale')()
        })

        grid.addEventListener('unchoose', ({ item }) => dragged || animate(item, {
            '--fi-lift': { to: 0, ease: 'outQuad', duration: 200 },
            '--fi-scale': { to: 1, ease: settle },
            onComplete: release(item, '--fi-lift', '--fi-scale'),
        }))

        grid.addEventListener('end', ({ item, oldIndex, newIndex }) => oldIndex !== newIndex && land(item))
    }

    return { watch, land }
}
