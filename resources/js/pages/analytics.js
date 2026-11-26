/**
 * The Analytics page's bar charts. Every [data-chart] holds one chart's data (see
 * <x-analytics.chart>); the table under it has the same numbers, so nothing depends on this file.
 * Values are pesos, or plain counts when the data says `format: 'count'`.
 *
 * Chart.js is loaded only when there is a chart to draw. Colours are read from the page: the
 * single series wears the theme's accent, the stacked ones the --chart-N variables (app.css), so a
 * switch to dark redraws them. Leaving the page (soft navigation) destroys the charts.
 */
const peso = (value) => '₱' + Number(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const shortPeso = (value) => '₱' + Number(value).toLocaleString('en-US', { notation: Math.abs(value) >= 10000 ? 'compact' : 'standard' });

const read = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();

// `--accent` is "r g b" for Tailwind; the chart library wants commas.
const colour = (key) => (key === 'accent' ? 'rgb(' + read('--accent').split(/\s+/).join(', ') + ')' : read('--chart-' + key));

const config = (spec) => {
    const surface = read('--chart-surface');
    const text = read('--chart-text');
    const grid = read('--chart-grid');
    const stacked = Boolean(spec.stacked);
    // Amounts are pesos unless the chart says it counts things.
    const counts = spec.format === 'count';
    const full = counts ? (value) => Number(value).toLocaleString('en-US') : peso;

    return {
        type: 'bar',
        data: {
            labels: spec.labels,
            datasets: spec.datasets.map((set) => ({
                label: set.label,
                data: set.data,
                backgroundColor: colour(set.color),
                maxBarThickness: 24,
                // Square at the baseline, rounded at the data end.
                borderRadius: stacked ? 0 : { topLeft: 4, topRight: 4 },
                // The gap between stacked segments is the card's own colour.
                borderColor: surface,
                borderWidth: stacked ? { top: 2 } : 0,
            })),
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ? false : { duration: 250 },
            interaction: { mode: 'index', intersect: false },
            plugins: {
                // One series needs no legend: the card's title names it.
                legend: { display: spec.datasets.length > 1, position: 'bottom', labels: { color: text, boxWidth: 10, boxHeight: 10 } },
                tooltip: { callbacks: { label: (item) => item.dataset.label + ': ' + full(item.parsed.y) } },
            },
            scales: {
                x: { stacked, grid: { display: false }, border: { color: grid }, ticks: { color: text, maxRotation: 0, autoSkipPadding: 12 } },
                y: { stacked, beginAtZero: true, grid: { color: grid }, border: { display: false }, ticks: { color: text, callback: counts ? full : shortPeso, maxTicksLimit: 6, precision: counts ? 0 : undefined } },
            },
        },
    };
};

const start = ({ signal } = {}) => {
    const holders = [...document.querySelectorAll('[data-chart]')];

    if (holders.length === 0) {
        return;
    }

    import('chart.js/auto').then(({ default: Chart }) => {
        // The page was left while the library downloaded.
        if (signal?.aborted) {
            return;
        }

        Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;

        let charts = [];
        let dark = null;

        const destroy = () => {
            charts.forEach((chart) => chart.destroy());
            charts = [];
        };

        const draw = () => {
            const now = document.documentElement.classList.contains('dark');

            // Other classes change on <html> too (the sidebar): only the theme needs a redraw.
            if (now === dark) {
                return;
            }

            dark = now;
            destroy();

            for (const holder of holders) {
                try {
                    charts.push(new Chart(holder.querySelector('canvas'), config(JSON.parse(holder.dataset.chart))));
                } catch (error) {
                    console.error('A chart could not be drawn; its table still has the numbers.', error);
                }
            }
        };

        draw();

        const observer = new MutationObserver(draw);

        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

        signal?.addEventListener('abort', () => {
            observer.disconnect();
            destroy();
        });
    }).catch((error) => console.error('The chart library could not be loaded.', error));
};

export default start;
