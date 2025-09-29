/* dam-market-page.js — JSON loader with mode switch + empty-state */
(function () {
    'use strict';

    // ---- DOM ----
    const $ = (s) => document.querySelector(s);
    const techSel   = $('#tech');
    const modeSel   = $('#mode');
    const dateInput = $('#date');
    const loadBtn   = $('#loadBtn');
    const exportBtn = $('#exportBtn');
    const statusEl  = $('#status');
    const canvas    = $('#psChart');
    const table     = $('#psTable');
    const theadRow  = $('#psHeadRow');
    const hiValEl   = $('#hiVal');
    const loValEl   = $('#loVal');
    const hiTimeEl  = $('#hiTime');
    const loTimeEl  = $('#loTime');
    const tbody     = table ? table.querySelector('tbody') : null;

    // empty-state host
    const emptyHostId = 'psEmptyHost';
    let emptyHost = document.getElementById(emptyHostId);
    if (!emptyHost) {
        emptyHost = document.createElement('div');
        emptyHost.id = emptyHostId;
        document.querySelector('.ps-chart')?.prepend(emptyHost);
    }

    // ---- utils ----
    const toNum = (x, d = 0) => Number.isFinite(Number(x)) ? Number(x) : d;
    function formatYMD(d) {
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${day}`;
    }
    function parseYMD(s) {
        const [y, m, d] = (s || '').split('-').map(Number);
        return new Date(y, (m || 1) - 1, d || 1);
    }

    let chart;
    let lastSnapshot = null;

    // ---- empty-state ----
    function renderEmptyState(dateStr) {
        if (chart) { chart.destroy(); chart = null; }
        if (tbody) tbody.innerHTML = '';
        if (theadRow) theadRow.innerHTML = `
      <th>#</th><th>Time</th><th>Volume (MWh)</th><th>Price (€/MWh)</th>
    `;
        if (statusEl) statusEl.textContent = '';
        if (hiValEl) hiValEl.textContent = '—';
        if (loValEl) loValEl.textContent = '—';
        if (hiTimeEl) hiTimeEl.textContent = '—';
        if (loTimeEl) loTimeEl.textContent = '—';
        if (exportBtn) exportBtn.disabled = true;

        const niceDate = new Date(dateStr).toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' });

        emptyHost.innerHTML = `
      <div class="empty-card">
        <div class="empty-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="48" height="48">
            <path d="M19 3H5a2 2 0 0 0-2 2v14l4-4h12a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2z"
                  fill="currentColor" opacity="0.12"></path>
            <path d="M19 3H5a2 2 0 0 0-2 2v14l4-4h12a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2z"
                  fill="none" stroke="currentColor" stroke-width="1.5"></path>
          </svg>
        </div>
        <h3>No data available</h3>
        <p>We could not find data for ${niceDate}.</p>
        <div class="empty-actions">
          <button type="button" class="btn" id="emptyPrev">Previous day</button>
          <button type="button" class="btn btn-primary" id="emptyToday">Today</button>
          <button type="button" class="btn" id="emptyNext">Next day</button>
        </div>
      </div>
    `;

        const dt = parseYMD(dateStr);
        const setDate = (d) => { if (dateInput) dateInput.value = formatYMD(d); load(); };
        const prev = new Date(dt); prev.setDate(prev.getDate() - 1);
        const next = new Date(dt); next.setDate(next.getDate() + 1);
        $('#emptyPrev')?.addEventListener('click', () => setDate(prev));
        $('#emptyToday')?.addEventListener('click', () => setDate(new Date()));
        $('#emptyNext')?.addEventListener('click', () => setDate(next));
    }
    function clearEmptyState() { emptyHost.innerHTML = ''; }

    // ---- data ----
    async function fetchSeries(date) {
        const base = (typeof PowerAPI !== 'undefined' && PowerAPI.endpoint) ? PowerAPI.endpoint : '';
        if (!base) throw new Error('PowerAPI.endpoint not found');
        const u = new URL(base, window.location.origin);
        if (date) u.searchParams.set('date', date);
        const res = await fetch(u.toString(), { method: 'GET', credentials: 'same-origin' });
        if (!res.ok) {
            let detail = null;
            try { detail = await res.json(); } catch (_) {}
            const err = new Error(`HTTP ${res.status}`); err.status = res.status; err.detail = detail; throw err;
        }
        const data = await res.json();
        data.labels = Array.isArray(data.labels) ? data.labels.map(String) : [];
        data.VOLUME = Array.isArray(data.VOLUME) ? data.VOLUME.map(Number) : [];
        data.PRICE  = Array.isArray(data.PRICE)  ? data.PRICE.map(Number)  : [];
        return data;
    }

    // ---- chart ----
    function renderChart(date, mode, series) {
        if (!canvas) return;
        if (chart) chart.destroy();

        const labels = series.labels || [];
        const price  = series.PRICE  || [];
        const volume = series.VOLUME || [];
        const datasets = [];

        if (mode === 'price' || mode === 'both') {
            datasets.push({
                type: 'line',
                label: 'Price (€/MWh)',
                data: price.map(x => +Number(x).toFixed(2)),
                yAxisID: 'yPrice',
                borderWidth: 2,
                pointRadius: 0,
                tension: 0.25,
                borderColor: 'rgb(37,99,235)',
                backgroundColor: 'rgba(37,99,235,0.15)',
                fill: true
            });
        }
        if (mode === 'volume' || mode === 'both') {
            datasets.push({
                type: 'bar',
                label: 'Volume (MWh)',
                data: volume.map(x => +Number(x).toFixed(2)),
                yAxisID: 'yVolume',
                borderWidth: 0,
                backgroundColor: 'rgba(16,185,129,0.60)',
                borderRadius: 3,
                barPercentage: 0.9,
                categoryPercentage: 0.9
            });
        }

        const title = `${date} (30 min slots)`;
        chart = new Chart(canvas, {
            data: { labels, datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    x: { title: { display: true, text: title } },
                    yPrice:  { type: 'linear', position: 'left',  title: { display: true, text: 'Price (€/MWh)'  }, grid: { drawOnChartArea: true  } },
                    yVolume: { type: 'linear', position: 'right', title: { display: true, text: 'Volume (MWh)' }, grid: { drawOnChartArea: false } }
                },
                plugins: { legend: { display: true }, tooltip: { mode: 'index', intersect: false } }
            }
        });

        if (statusEl) {
            const total = volume.reduce((s, v) => s + Number(v), 0);
            statusEl.textContent = `Total volume: ${total.toFixed(0)} MWh`;
        }
    }

    // ---- table ----
    function buildHead() {
        if (!theadRow) return;
        theadRow.innerHTML = `
      <th>#</th><th>Time</th><th>Volume (MWh)</th><th>Price (€/MWh)</th>
    `;
    }
    function renderTable(series) {
        if (!tbody) return;
        buildHead();
        const { labels = [], PRICE = [], VOLUME = [] } = series;
        let html = '';
        for (let i = 0; i < labels.length; i++) {
            const p = toNum(PRICE[i]), v = toNum(VOLUME[i]);
            html += `<tr><td>${i + 1}</td><td>${labels[i]}</td><td class="pos">${v.toFixed(2)}</td><td>${p.toFixed(2)}</td></tr>`;
        }
        tbody.innerHTML = html;
    }
    function updateHiLo(series) {
        if (!hiValEl || !loValEl || !hiTimeEl || !loTimeEl) return;
        const price = series.PRICE || [], labels = series.labels || [];
        if (!price.length) { hiValEl.textContent = loValEl.textContent = hiTimeEl.textContent = loTimeEl.textContent = '—'; return; }
        let min = price[0], max = price[0], minIdx = 0, maxIdx = 0;
        for (let i = 1; i < price.length; i++) { const p = price[i]; if (p < min) { min = p; minIdx = i; } if (p > max) { max = p; maxIdx = i; } }
        hiValEl.textContent  = (max ?? 0).toFixed(2);
        loValEl.textContent  = (min ?? 0).toFixed(2);
        hiTimeEl.textContent = labels[maxIdx] ?? '—';
        loTimeEl.textContent = labels[minIdx] ?? '—';
    }

    // ---- CSV ----
    function csvEscape(val) { const s = String(val ?? ''); return /[",\n\r]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s; }
    function buildCSVRows(snapshot) {
        const { date, labels, series } = snapshot;
        const rows = [[`Date: ${date}`], [], ['#', 'Time', 'Volume (MWh)', 'Price (€/MWh)']];
        const price = series.PRICE || [], volume = series.VOLUME || [];
        for (let i = 0; i < labels.length; i++) rows.push([i + 1, labels[i], toNum(volume[i]).toFixed(2), toNum(price[i]).toFixed(2)]);
        return rows;
    }
    function exportCSV() {
        if (!lastSnapshot) return;
        const rows = buildCSVRows(lastSnapshot);
        const csv = rows.map(r => r.map(csvEscape).join(',')).join('\r\n');
        const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `dam_market_data_${lastSnapshot.date}.csv`;
        document.body.appendChild(a); a.click(); URL.revokeObjectURL(a.href); a.remove();
    }

    // ---- load ----
    async function load() {
        const date = dateInput?.value || formatYMD(new Date());
        const mode = (modeSel?.value || 'both').toLowerCase();
        clearEmptyState();

        if (statusEl) statusEl.textContent = 'Loading…';
        if (exportBtn) exportBtn.disabled = true;
        if (hiValEl) hiValEl.textContent = '—';
        if (loValEl) loValEl.textContent = '—';
        if (hiTimeEl) hiTimeEl.textContent = '—';
        if (loTimeEl) loTimeEl.textContent = '—';

        try {
            const series = await fetchSeries(date);
            renderChart(date, mode, series);
            renderTable(series);
            updateHiLo(series);
            lastSnapshot = { date, labels: series.labels, series };
            if (statusEl && statusEl.textContent === 'Loading…') statusEl.textContent = '';
            if (exportBtn) exportBtn.disabled = false;
        } catch (err) {
            if (err && err.status === 404) {
                renderEmptyState(date);
                if (statusEl) statusEl.textContent = '';
                return;
            }
            if (statusEl) statusEl.textContent = `We could not find data for ${date}.`;
            renderEmptyState(date);
        }
    }

    // ---- wire & boot ----
    function wire() {
        loadBtn?.addEventListener('click', load);
        dateInput?.addEventListener('change', load);
        exportBtn?.addEventListener('click', exportCSV);
        modeSel?.addEventListener('change', load);
        techSel?.closest('.field')?.classList.add('hidden');
    }
    window.addEventListener('load', () => {
        if (dateInput && !dateInput.value) dateInput.value = formatYMD(new Date());
        if (modeSel && !modeSel.value) modeSel.value = 'both';
        wire();
        load();
    });
})();
