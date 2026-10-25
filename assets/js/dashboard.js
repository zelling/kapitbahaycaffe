(() => {
    'use strict';

    const productCanvas = document.getElementById('product-chart');
    const ingredientCanvas = document.getElementById('ingredient-chart');
    const charts = new Map();

    if (!productCanvas || !ingredientCanvas || !window.Chart) {
        return;
    }

    const styles = getComputedStyle(document.documentElement);
    const ink = styles.getPropertyValue('--ink').trim() || '#283b32';
    const sage = styles.getPropertyValue('--sage').trim() || '#dce5d4';
    const terra = styles.getPropertyValue('--terra').trim()
        || styles.getPropertyValue('--terracotta').trim()
        || '#bf684c';
    const query = new URLSearchParams(window.location.search);

    if (!query.has('range')) {
        query.set('range', '30days');
    }

    const showMessage = (id, message) => {
        const element = document.getElementById(id);
        if (!element) {
            return;
        }

        element.textContent = message;
        element.hidden = false;
    };

    const makeBarChart = (canvas, labels, values, color, datasetLabel, tooltipLabel) => {
        const existingChart = charts.get(canvas.id);
        if (existingChart) {
            existingChart.destroy();
            charts.delete(canvas.id);
        }

        if (labels.length === 0 || values.every((value) => value === 0)) {
            return false;
        }

        const chart = new Chart(canvas, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: datasetLabel,
                    data: values,
                    backgroundColor: color,
                    borderColor: ink,
                    borderWidth: 1,
                    borderRadius: 3,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: tooltipLabel,
                        },
                    },
                },
                scales: {
                    x: {
                        ticks: { color: ink },
                        grid: { display: false },
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { color: ink, precision: 0 },
                        grid: { color: sage },
                    },
                },
            },
        });
        charts.set(canvas.id, chart);

        return true;
    };

    const loadCharts = async () => {
        try {
            const response = await fetch(`api.php?${query.toString()}`, {
                headers: { Accept: 'application/json' },
            });
            const data = await response.json();

            if (!response.ok || data.error) {
                throw new Error(data.error || 'The analytics request failed.');
            }

            const productItems = data.products.items;
            const productDrawn = makeBarChart(
                productCanvas,
                productItems.map((item) => item.name),
                productItems.map((item) => item.units),
                terra,
                'Units sold',
                (context) => `${context.label}: ${context.parsed.y} sold`,
            );
            if (!productDrawn) {
                showMessage('product-chart-message', 'No sales were recorded for this range.');
            }

            const ingredientItems = data.ingredients.usage;
            const ingredientDrawn = makeBarChart(
                ingredientCanvas,
                ingredientItems.map((item) => `${item.name} (${item.unit})`),
                ingredientItems.map((item) => item.total_usage),
                sage,
                'Ingredient usage',
                (context) => {
                    const item = ingredientItems[context.dataIndex];
                    return `${item.name}: ${context.parsed.y} ${item.unit}`;
                },
            );
            if (!ingredientDrawn) {
                showMessage('ingredient-chart-message', 'No ingredient usage was recorded for this range.');
            }
        } catch (error) {
            showMessage('product-chart-message', 'Charts could not be loaded right now.');
            showMessage('ingredient-chart-message', 'Charts could not be loaded right now.');
        }
    };

    loadCharts();
})();
