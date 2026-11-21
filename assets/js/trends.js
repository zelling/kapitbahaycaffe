(() => {
    'use strict';

    const root = document.getElementById('app-trends');
    if (!root) {
        return;
    }

    const apiUrl = root.dataset.apiUrl || '';
    const csrfToken = root.dataset.csrf || '';
    const geminiEnabled = root.dataset.gemini === '1';
    const mode = root.dataset.mode || 'week';
    const anchor = root.dataset.anchor || '';
    const status = document.getElementById('app-trends-status');
    const progress = document.getElementById('app-trends-progress');
    const noData = document.getElementById('app-trends-nodata');
    const chartInstances = new Map();

    const text = (value) => typeof value === 'string' ? value : '';
    const number = (value) => Number.isFinite(Number(value)) ? Number(value) : 0;

    const setText = (element, value) => {
        if (element) {
            element.textContent = value;
        }
    };

    const showUnavailable = () => {
        setText(status, 'Trends are unavailable right now.');
    };

    const chart = (canvasId, type, data, options = {}) => {
        const canvas = document.getElementById(canvasId);
        if (!canvas || !window.Chart) {
            return;
        }
        const existing = chartInstances.get(canvasId);
        if (existing) {
            existing.destroy();
        }
        chartInstances.set(canvasId, new window.Chart(canvas, {
            type,
            data,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                ...options,
            },
        }));
    };

    const formatChange = (value) => {
        if (value === null || value === undefined || !Number.isFinite(Number(value))) {
            return '-';
        }
        return `${Number(value).toFixed(1)}%`;
    };

    const formatTrend = (value) => {
        const labels = {
            rising: 'Rising',
            falling: 'Falling',
            steady: 'Steady',
            not_enough_data: 'Not enough data',
        };
        return labels[text(value)] || 'Not enough data';
    };

    const renderTable = (items) => {
        const table = document.getElementById('app-trends-table');
        const body = table ? table.querySelector('tbody') : null;
        if (!body) {
            return;
        }
        body.replaceChildren();
        items.forEach((item) => {
            const row = document.createElement('tr');
            const cells = [
                text(item.name),
                String(number(item.units)),
                String(number(item.previous_units)),
                formatChange(item.percent_change),
                formatTrend(item.status),
            ];
            cells.forEach((value) => {
                const cell = document.createElement('td');
                cell.textContent = value;
                row.appendChild(cell);
            });
            body.appendChild(row);
        });
    };

    const renderExpected = (range) => {
        const section = document.getElementById('app-trends-expected');
        const paragraph = section ? section.querySelector('p') : null;
        if (!section || !paragraph) {
            return;
        }
        const label = text(range.label);
        if (range.available) {
            const message = `Expected range: ${number(range.low)} to ${number(range.high)} units (average ${number(range.average)}).`;
            paragraph.textContent = label === '' ? message : `${message} ${label}`;
        } else {
            paragraph.textContent = label === '' ? 'Not enough past data.' : `Not enough past data. ${label}`;
        }
        section.hidden = false;
    };

    const renderCharts = (trends) => {
        const daily = Array.isArray(trends.daily) ? trends.daily : [];
        const items = Array.isArray(trends.items) ? trends.items : [];
        const ingredients = Array.isArray(trends.ingredients) ? trends.ingredients : [];
        const weekdayAverages = Array.isArray(trends.weekday_averages) ? trends.weekday_averages : [];

        chart('app-chart-daily', 'line', {
            labels: daily.map((day) => text(day.date)),
            datasets: [
                {
                    label: 'Units',
                    data: daily.map((day) => number(day.units)),
                    borderColor: '#bf684c',
                    backgroundColor: '#bf684c',
                    tension: 0.25,
                },
                {
                    label: '7-day moving average',
                    data: daily.map((day) => number(day.moving_average)),
                    borderColor: '#283b32',
                    borderDash: [5, 4],
                    tension: 0.25,
                },
            ],
        });

        chart('app-chart-items', 'bar', {
            labels: items.map((item) => text(item.name)),
            datasets: [
                {
                    label: 'Units',
                    data: items.map((item) => number(item.units)),
                    backgroundColor: '#bf684c',
                },
                {
                    label: 'Previous units',
                    data: items.map((item) => number(item.previous_units)),
                    backgroundColor: '#dce5d4',
                },
            ],
        });

        chart('app-chart-ingredients', 'bar', {
            labels: ingredients.map((ingredient) => `${text(ingredient.name)} (${text(ingredient.unit)})`),
            datasets: [
                {
                    label: 'Usage',
                    data: ingredients.map((ingredient) => number(ingredient.total_usage)),
                    backgroundColor: '#bf684c',
                },
                {
                    label: 'Previous usage',
                    data: ingredients.map((ingredient) => number(ingredient.previous_total_usage)),
                    backgroundColor: '#dce5d4',
                },
            ],
        });

        chart('app-chart-weekday', 'bar', {
            labels: weekdayAverages.map((day) => text(day.day)),
            datasets: [{
                label: 'Average units',
                data: weekdayAverages.map((day) => number(day.average_units)),
                backgroundColor: '#dce5d4',
                borderColor: '#283b32',
                borderWidth: 1,
            }],
        });
    };

    const renderTrends = (trends) => {
        const period = trends && typeof trends.period === 'object' ? trends.period : {};
        const items = Array.isArray(trends.items) ? trends.items : [];
        const expectedRange = trends && typeof trends.expected_range === 'object' ? trends.expected_range : {};
        setText(status, text(period.label));
        setText(progress, 'This period is not finished, so totals are lower than a full period.');
        progress.hidden = !period.in_progress;
        setText(noData, 'Not enough data to compare with the previous period yet.');
        noData.hidden = Boolean(trends.enough_data);
        renderTable(items);
        renderExpected(expectedRange);
        renderCharts(trends);
    };

    const renderInsights = (result) => {
        const output = document.getElementById('app-insights-output');
        if (!output) {
            return;
        }
        output.replaceChildren();
        if (!result.ok || !result.summary || !Array.isArray(result.summary.observations)) {
            output.textContent = text(result.message) || 'Insights are unavailable right now.';
            return;
        }
        result.summary.observations.forEach((observation) => {
            const heading = document.createElement('h4');
            const detail = document.createElement('p');
            heading.textContent = text(observation.title);
            detail.textContent = text(observation.detail);
            output.appendChild(heading);
            output.appendChild(detail);
        });
        const caution = document.createElement('p');
        caution.textContent = text(result.summary.caution);
        output.appendChild(caution);
        const metadata = document.createElement('p');
        metadata.textContent = `Model: ${text(result.model)} | Generated: ${text(result.created_at)}`;
        output.appendChild(metadata);
    };

    const attachInsights = () => {
        const button = document.getElementById('app-insights-button');
        if (!button || !geminiEnabled) {
            return;
        }
        button.addEventListener('click', async () => {
            const output = document.getElementById('app-insights-output');
            button.disabled = true;
            setText(output, 'Generating...');
            try {
                const form = new URLSearchParams({
                    action: 'insights',
                    csrf_token: csrfToken,
                    mode,
                    anchor,
                });
                const response = await fetch(apiUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                    body: form.toString(),
                });
                if (!response.ok) {
                    throw new Error('Insight request failed.');
                }
                renderInsights(await response.json());
            } catch (error) {
                setText(output, 'Insights are unavailable right now.');
            } finally {
                button.disabled = false;
            }
        });
    };

    const loadTrends = async () => {
        try {
            const url = new URL(apiUrl, window.location.origin);
            url.searchParams.set('mode', mode);
            url.searchParams.set('anchor', anchor);
            const response = await fetch(url.toString(), { credentials: 'same-origin' });
            if (!response.ok) {
                throw new Error('Trend request failed.');
            }
            const payload = await response.json();
            if (!payload || !payload.trends) {
                throw new Error('Invalid trend response.');
            }
            renderTrends(payload.trends);
        } catch (error) {
            showUnavailable();
        }
    };

    attachInsights();
    loadTrends();
})();
