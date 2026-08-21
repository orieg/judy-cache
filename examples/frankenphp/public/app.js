document.addEventListener('DOMContentLoaded', () => {
  const slider = document.getElementById('item-slider');
  const sliderDisplay = document.getElementById('slider-display');
  const presetButtons = document.querySelectorAll('.btn-preset');
  const runBtn = document.getElementById('btn-run');
  const resetBtn = document.getElementById('btn-reset');
  const spinner = document.getElementById('run-spinner');
  const workerInfo = document.getElementById('worker-info');
  const resultsBadge = document.getElementById('results-badge');
  const resultsBody = document.getElementById('results-body');
  const comparisonSection = document.getElementById('comparison-section');

  let currentWorkload = 'memory_shootout';

  // Format numbers with commas
  const fmt = (n) => Number(n).toLocaleString();

  // Slider update
  slider.addEventListener('input', (e) => {
    sliderDisplay.textContent = fmt(e.target.value);
  });

  // Preset buttons toggle
  presetButtons.forEach(btn => {
    btn.addEventListener('click', () => {
      presetButtons.forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      currentWorkload = btn.dataset.workload;
    });
  });

  // Fetch worker status
  async function fetchStatus() {
    try {
      const res = await fetch('/api/status');
      if (!res.ok) throw new Error('Worker response error');
      const data = await res.json();
      workerInfo.innerHTML = `PID <strong>${data.pid}</strong> &bull; RSS <strong>${data.current_memory_mb} MB</strong> &bull; Req #${data.requests_served_by_worker}`;
    } catch (err) {
      workerInfo.textContent = 'Worker offline / connecting...';
    }
  }

  fetchStatus();
  setInterval(fetchStatus, 3000);

  // Clear memory
  resetBtn.addEventListener('click', async () => {
    resetBtn.disabled = true;
    resetBtn.textContent = 'Clearing...';
    try {
      await fetch('/api/clear', { method: 'POST' });
      await fetchStatus();
      resultsBody.innerHTML = `<tr><td colspan="6" class="empty-state">Worker resident memory successfully cleared and garbage collected.</td></tr>`;
      comparisonSection.style.display = 'none';
    } catch (e) {
      alert('Failed to clear worker memory');
    } finally {
      resetBtn.disabled = false;
      resetBtn.textContent = 'Clear Worker Memory';
    }
  });

  // Run benchmark
  runBtn.addEventListener('click', async () => {
    const count = parseInt(slider.value, 10);
    const backend = document.querySelector('input[name="backend"]:checked').value;

    runBtn.disabled = true;
    spinner.style.display = 'inline-block';
    resultsBadge.textContent = 'Running...';

    try {
      const res = await fetch('/api/benchmark', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          count,
          backend,
          workload: currentWorkload,
        }),
      });

      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const data = await res.json();
      renderResults(data);
      fetchStatus();
    } catch (err) {
      resultsBody.innerHTML = `<tr><td colspan="6" class="empty-state" style="color: var(--danger)">Benchmark failed: ${err.message}</td></tr>`;
    } finally {
      runBtn.disabled = false;
      spinner.style.display = 'none';
      resultsBadge.textContent = 'Completed';
    }
  });

  function renderResults(data) {
    const results = data.results;
    let rows = '';

    const judy = results.judy;
    const arr = results.array;
    const polyfill = results.polyfill;

    // Update Hero Metrics
    if (judy && arr) {
      const memSavings = arr.mem_allocated_mb > 0 
        ? Math.round((1 - (judy.mem_allocated_mb / arr.mem_allocated_mb)) * 100)
        : (arr.peak_rss_mb > 0 ? Math.round((1 - (judy.peak_rss_mb / arr.peak_rss_mb)) * 100) : 0);
      
      document.getElementById('metric-mem').textContent = `${judy.mem_allocated_mb} MB`;
      document.getElementById('metric-mem-sub').innerHTML = `<span style="color: var(--accent); font-weight:700">−${Math.max(0, memSavings)}%</span> vs PHP Array (${arr.mem_allocated_mb} MB)`;

      document.getElementById('metric-lat').textContent = `${judy.duration_ms} ms`;
      const speedup = (arr.duration_ms / Math.max(0.01, judy.duration_ms)).toFixed(1);
      document.getElementById('metric-lat-sub').textContent = `${speedup}x vs PHP Array (${arr.duration_ms} ms)`;

      document.getElementById('metric-ops').textContent = `${fmt(judy.ops_per_sec)}/s`;
      document.getElementById('metric-ops-sub').textContent = `Processed ${fmt(data.count)} items`;

      // Update Comparison Bars
      comparisonSection.style.display = 'block';
      const maxMem = Math.max(judy.mem_allocated_mb, arr.mem_allocated_mb, 1);
      document.getElementById('bar-judy').style.width = `${(judy.mem_allocated_mb / maxMem) * 100}%`;
      document.getElementById('val-judy').textContent = `${judy.mem_allocated_mb} MB`;
      document.getElementById('bar-array').style.width = `${(arr.mem_allocated_mb / maxMem) * 100}%`;
      document.getElementById('val-array').textContent = `${arr.mem_allocated_mb} MB`;
    }

    // Build Table Rows
    const formatRow = (name, m, badgeClass) => {
      if (!m) return '';
      let details = '';
      if (m.prefix_invalidation_ms !== undefined) {
        details = `Prefix Del: <strong>${m.prefix_invalidation_ms} ms</strong> (${m.algo_complexity})`;
      } else if (m.write_ops_sec !== undefined) {
        details = `W: ${fmt(m.write_ops_sec)}/s | R: ${fmt(m.read_ops_sec)}/s`;
      } else if (m.bytes_per_key !== undefined) {
        details = `${m.bytes_per_key} bytes/key (internal: ${m.judy_internal_mb || 0} MB)`;
      } else {
        details = `${fmt(m.total_entries || m.total_keys || data.count)} entries`;
      }

      return `
        <tr>
          <td><span class="badge ${badgeClass}">${name}</span></td>
          <td><strong>${m.duration_ms} ms</strong></td>
          <td>${fmt(m.ops_per_sec)} ops/s</td>
          <td><strong>${m.mem_allocated_mb} MB</strong></td>
          <td>${m.peak_rss_mb} MB</td>
          <td>${details}</td>
        </tr>
      `;
    };

    if (judy) rows += formatRow('ext-judy 2.6.0 (C)', judy, 'badge-judy');
    if (polyfill) rows += formatRow('judy-polyfill (PHP)', polyfill, 'badge-polyfill');
    if (arr) rows += formatRow('Native PHP Array', arr, 'badge-array');

    resultsBody.innerHTML = rows;
  }
});
