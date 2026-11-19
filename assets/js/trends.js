(() => {
    'use strict';

    const data = window.kapetbahayTrends;
    if (!data || !window.Chart) {
        return;
    }

    const styles = getComputedStyle(document.documentElement);
    const ink = styles.getPropertyValue('--ink').trim() || '#283b32';
    const sage = styles.getPropertyValue('--sage').trim() || '#dce5d4';
    const terra = styles.getPropertyValue('--terra').trim() || styles.getPropertyValue('--terracotta').trim() || '#bf684c';
    const charts = new Map();
    const draw = (id, config) => {
        const canvas = document.getElementById(id);
        if (!canvas) {
            return;
        }
        const existing = charts.get(id);
        if (existing) {
            existing.destroy();
        }
        charts.set(id, new Chart(canvas, config));
    };
    const axes = { x: { ticks: { color: ink }, grid: { display: false } }, y: { beginAtZero: true, ticks: { color: ink }, grid: { color: sage } } };

    draw('trends-daily-chart', { type: 'line', data: { labels: data.daily.map((row) => row.date.slice(5)), datasets: [{ label: 'Units', data: data.daily.map((row) => row.units), borderColor: terra, backgroundColor: terra, tension: .25 }, { label: '7-day average', data: data.daily.map((row) => row.moving_average), borderColor: ink, borderDash: [5, 4], tension: .25 }] }, options: { responsive: true, maintainAspectRatio: false, scales: axes } });
    const moving = data.products.filter((row) => (row.label === 'rising' || row.label === 'falling') && row.percent_change !== null);
    draw('trends-items-chart', { type: 'bar', data: { labels: moving.map((row) => row.name), datasets: [{ label: '% change', data: moving.map((row) => row.percent_change), backgroundColor: moving.map((row) => row.label === 'rising' ? sage : terra), borderColor: ink, borderWidth: 1 }] }, options: { responsive: true, maintainAspectRatio: false, scales: axes } });
    draw('trends-ingredients-chart', { type: 'bar', data: { labels: data.ingredients.map((row) => `${row.name} (${row.unit})`), datasets: [{ label: 'This period', data: data.ingredients.map((row) => row.current), backgroundColor: terra }, { label: 'Previous period', data: data.ingredients.map((row) => row.previous), backgroundColor: sage }] }, options: { responsive: true, maintainAspectRatio: false, scales: axes } });
    draw('trends-weekday-chart', { type: 'bar', data: { labels: data.weekday_averages.map((row) => row.day), datasets: [{ label: 'Average units', data: data.weekday_averages.map((row) => row.average), backgroundColor: sage, borderColor: ink, borderWidth: 1 }] }, options: { responsive: true, maintainAspectRatio: false, scales: axes } });
})();
