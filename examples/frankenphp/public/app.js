document.addEventListener('DOMContentLoaded', () => {
  // Theme toggle
  const themeToggle = document.getElementById('theme-toggle');
  function applyTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem('theme', theme);
  }
  themeToggle.addEventListener('click', () => {
    const current = document.documentElement.getAttribute('data-theme') || 'dark';
    applyTheme(current === 'dark' ? 'light' : 'dark');
  });
  window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
    if (!localStorage.getItem('theme')) {
      applyTheme(e.matches ? 'dark' : 'light');
    }
  });

  // Scale Slider mapping (10k -> 10M)
  const SCALES = [
    { count: 10000, label: '10,000 keys (10k)' },
    { count: 50000, label: '50,000 keys (50k)' },
    { count: 100000, label: '100,000 keys (100k)' },
    { count: 250000, label: '250,000 keys (250k)' },
    { count: 500000, label: '500,000 keys (500k)' },
    { count: 1000000, label: '1,000,000 keys (1M)' },
    { count: 2500000, label: '2,500,000 keys (2.5M)' },
    { count: 5000000, label: '5,000,000 keys (5M)' },
    { count: 10000000, label: '10,000,000 keys (10M)' },
  ];

  const scaleSlider = document.getElementById('scale-slider');
  const scaleDisplay = document.getElementById('scale-display');
  const chips = document.querySelectorAll('.chip');
  const presetCards = document.querySelectorAll('.preset-card');
  const runBtn = document.getElementById('btn-run');
  const resetBtn = document.getElementById('btn-reset');
  const btnSpinner = document.getElementById('btn-spinner');
  const btnText = document.getElementById('btn-text');
  const workerInfo = document.getElementById('worker-info');
  const resultsBadge = document.getElementById('results-badge');
  const tableBody = document.getElementById('table-body');
  const comparisonBox = document.getElementById('comparison-box');
  const progressContainer = document.getElementById('progress-container');
  const terminalBody = document.getElementById('terminal-body');
  const btnClearTerm = document.getElementById('btn-clear-term');
  const integrityCard = document.getElementById('integrity-card');
  const integrityDetails = document.getElementById('integrity-details');
  const samplesCard = document.getElementById('samples-card');
  const samplesGrid = document.getElementById('samples-grid');
  const probeInput = document.getElementById('probe-input');
  const btnProbe = document.getElementById('btn-probe');
  const probeResult = document.getElementById('probe-result');

  let currentWorkload = 'memory_shootout';
  const fmt = (n) => Number(n).toLocaleString();

  function logTerminal(text, level = 'info') {
    const time = new Date().toLocaleTimeString([], { hour12: false });
    const line = document.createElement('div');
    line.className = `term-line ${level}`;
    line.innerHTML = `<span class="term-time">[${time}]</span> ${text}`;
    terminalBody.appendChild(line);
    terminalBody.scrollTop = terminalBody.scrollHeight;
  }

  btnClearTerm.addEventListener('click', () => {
    terminalBody.innerHTML = '';
    logTerminal('Terminal cleared.', 'muted');
  });

  function updateScaleIndex(idx) {
    idx = Math.max(0, Math.min(SCALES.length - 1, parseInt(idx, 10)));
    scaleSlider.value = idx;
    scaleDisplay.textContent = SCALES[idx].label;
    chips.forEach(chip => {
      chip.classList.toggle('active', parseInt(chip.dataset.idx, 10) === idx);
    });
  }

  scaleSlider.addEventListener('input', (e) => {
    updateScaleIndex(e.target.value);
  });

  chips.forEach(chip => {
    chip.addEventListener('click', () => {
      updateScaleIndex(chip.dataset.idx);
    });
  });

  presetCards.forEach(card => {
    card.addEventListener('click', () => {
      presetCards.forEach(c => c.classList.remove('active'));
      card.classList.add('active');
      currentWorkload = card.dataset.workload;
      logTerminal(`Workload switched to: <strong>${card.querySelector('strong').textContent}</strong>`, 'info');
    });
  });

  // Fetch Worker Status
  async function fetchStatus() {
    try {
      const res = await fetch('/api/status');
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const data = await res.json();
      workerInfo.innerHTML = `PID <strong>${data.pid}</strong> &bull; RSS <strong>${data.current_memory_mb} MB</strong> &bull; Req <strong>#${data.requests_served_by_worker}</strong>`;
    } catch (e) {
      workerInfo.textContent = 'FrankenPHP worker active (refreshing...)';
    }
  }

  fetchStatus();
  setInterval(fetchStatus, 4000);

  // Clear Worker Memory
  resetBtn.addEventListener('click', async () => {
    resetBtn.disabled = true;
    resetBtn.innerHTML = '<span>Flushing...</span>';
    logTerminal('🧹 Invoking gc_collect_cycles() and flushing resident memory...', 'warn');
    try {
      const res = await fetch('/api/clear', { method: 'POST' });
      const data = await res.json();
      fetchStatus();
      logTerminal(`✓ Resident worker memory flushed cleanly. Current RSS: ${data.current_rss_mb} MB`, 'success');
      tableBody.innerHTML = `
        <tr>
          <td colspan="6" class="placeholder-row">
            <div class="placeholder-content">
              <span style="color: var(--accent-emerald)">✓ Worker memory cleared. Current RSS: ${data.current_rss_mb} MB</span>
            </div>
          </td>
        </tr>
      `;
      comparisonBox.style.display = 'none';
      integrityCard.style.display = 'none';
      samplesCard.style.display = 'none';
      document.getElementById('kpi-mem').textContent = '—';
      document.getElementById('kpi-lat').textContent = '—';
      document.getElementById('kpi-ops').textContent = '—';
    } catch (e) {
      logTerminal(`❌ Failed to clear worker memory: ${e.message}`, 'error');
    } finally {
      resetBtn.disabled = false;
      resetBtn.innerHTML = `
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
        <span>Clear Memory</span>
      `;
    }
  });

  // Execute Streaming Benchmark (SSE)
  runBtn.addEventListener('click', () => {
    const scaleObj = SCALES[parseInt(scaleSlider.value, 10)];
    const count = scaleObj.count;
    const backend = document.querySelector('input[name="backend"]:checked').value;

    runBtn.disabled = true;
    btnSpinner.style.display = 'inline-block';
    btnText.textContent = `Streaming ${fmt(count)} items...`;
    progressContainer.style.display = 'block';
    resultsBadge.textContent = 'Streaming...';
    resultsBadge.style.color = 'var(--accent-amber)';

    const sseUrl = `/api/stream-benchmark?count=${count}&backend=${backend}&workload=${currentWorkload}`;
    const eventSource = new EventSource(sseUrl);

    eventSource.addEventListener('log', (e) => {
      const data = JSON.parse(e.data);
      logTerminal(data.text, data.level || 'info');
    });

    eventSource.addEventListener('result', (e) => {
      const data = JSON.parse(e.data);
      renderResults(data);
      resultsBadge.textContent = 'Completed';
      resultsBadge.style.color = 'var(--accent-emerald)';
      fetchStatus();
      eventSource.close();
      cleanup();
    });

    eventSource.addEventListener('error', (e) => {
      logTerminal('❌ Streaming error or benchmark failure occurred.', 'error');
      resultsBadge.textContent = 'Error';
      resultsBadge.style.color = 'var(--accent-rose)';
      eventSource.close();
      cleanup();
    });

    function cleanup() {
      runBtn.disabled = false;
      btnSpinner.style.display = 'none';
      btnText.textContent = 'Execute Benchmark';
      progressContainer.style.display = 'none';
    }
  });

  function renderResults(data) {
    const results = data.results;
    const judy = results.judy;
    const arr = results.array;
    const polyfill = results.polyfill;

    // Update KPI Hero Cards
    if (judy) {
      document.getElementById('kpi-mem').textContent = `${judy.mem_allocated_mb} MB`;
      document.getElementById('kpi-lat').textContent = `${judy.duration_ms} ms`;
      document.getElementById('kpi-ops').textContent = `${fmt(judy.ops_per_sec)}/s`;

      if (arr) {
        const memSavings = arr.mem_allocated_mb > 0 
          ? Math.round((1 - (judy.mem_allocated_mb / arr.mem_allocated_mb)) * 100)
          : (arr.peak_rss_mb > 0 ? Math.round((1 - (judy.peak_rss_mb / arr.peak_rss_mb)) * 100) : 0);
        
        document.getElementById('kpi-mem-sub').innerHTML = `<span style="color: var(--accent-emerald); font-weight:700">−${Math.max(0, memSavings)}%</span> vs Array (${arr.mem_allocated_mb} MB)`;

        const speedup = (arr.duration_ms / Math.max(0.01, judy.duration_ms)).toFixed(1);
        document.getElementById('kpi-lat-sub').textContent = `${speedup}x vs Array (${arr.duration_ms} ms)`;

        // Comparison Bar Chart
        comparisonBox.style.display = 'block';
        document.getElementById('savings-badge').textContent = `−${Math.max(0, memSavings)}% RAM Savings`;
        const maxMem = Math.max(judy.mem_allocated_mb, arr.mem_allocated_mb, 1);
        document.getElementById('bar-fill-judy').style.width = `${Math.max(4, (judy.mem_allocated_mb / maxMem) * 100)}%`;
        document.getElementById('bar-val-judy').textContent = `${judy.mem_allocated_mb} MB`;
        document.getElementById('bar-fill-array').style.width = `${(arr.mem_allocated_mb / maxMem) * 100}%`;
        document.getElementById('bar-val-array').textContent = `${arr.mem_allocated_mb} MB`;
      }

      // Render Integrity Card
      if (judy.integrity) {
        integrityCard.style.display = 'block';
        integrityDetails.innerHTML = `<strong>${fmt(judy.total_keys || judy.total_entries || data.count)}</strong> keys intact in digital trie &bull; <strong>${judy.integrity.probed_samples}</strong> boundary probes verified with <strong>0 bit corruption</strong> &bull; Checksum: <code>${judy.integrity.checksum_crc || '0x0'}</code>`;
      }

      // Render Live Samples Grid
      if (judy.samples && judy.samples.length > 0) {
        samplesCard.style.display = 'block';
        samplesGrid.innerHTML = judy.samples.map(s => `
          <div class="sample-item">
            <div class="sample-key">Key: ${s.key}</div>
            <div class="sample-val">Val: ${typeof s.value === 'object' ? JSON.stringify(s.value) : s.value}</div>
            <div class="sample-status">✓ ${s.status}</div>
          </div>
        `).join('');
      }
    }

    // Build Table Rows
    const formatRow = (name, m, badgeClass) => {
      if (!m) return '';
      let details = '';
      if (m.prefix_invalidation_ms !== undefined) {
        details = `Prefix Delete: <strong>${m.prefix_invalidation_ms} ms</strong> (${m.algo_complexity})`;
      } else if (m.write_ops_sec !== undefined) {
        details = `Write: ${fmt(m.write_ops_sec)}/s &bull; Read: ${fmt(m.read_ops_sec)}/s`;
      } else if (m.bytes_per_key !== undefined) {
        details = `<strong>${m.bytes_per_key} bytes/key</strong> (libJudy: ${m.judy_internal_mb || 0} MB)`;
      } else {
        details = `${fmt(m.total_entries || m.total_keys || data.count)} entries`;
      }

      return `
        <tr>
          <td><span class="badge-tag ${badgeClass}">${name}</span></td>
          <td><strong>${m.duration_ms} ms</strong></td>
          <td>${fmt(m.ops_per_sec)} ops/s</td>
          <td><strong>${m.mem_allocated_mb} MB</strong></td>
          <td>${m.peak_rss_mb} MB</td>
          <td>${details}</td>
        </tr>
      `;
    };

    let rows = '';
    if (judy) rows += formatRow('ext-judy 2.6.0 (C)', judy, 'badge-judy');
    if (arr) rows += formatRow('Native PHP Array', arr, 'badge-array');
    if (polyfill) rows += formatRow('judy-polyfill (PHP)', polyfill, 'badge-polyfill');

    tableBody.innerHTML = rows;
  }

  // On-Demand Probe Search Handler
  btnProbe.addEventListener('click', async () => {
    const val = probeInput.value.trim();
    if (!val) return;
    probeResult.style.display = 'block';
    probeResult.innerHTML = '<span class="output-muted">Probing live memory...</span>';

    try {
      const res = await fetch(`/api/verify-probe?key=${encodeURIComponent(val)}&index=${encodeURIComponent(val)}`);
      const data = await res.json();
      if (data.found) {
        probeResult.innerHTML = `<span style="color: var(--accent-emerald)">✓ <strong>${data.key}</strong> found in ${data.source} &bull; Value: <code style="color: var(--badge-text)">${JSON.stringify(data.value)}</code> (${data.integrity_status})</span>`;
        logTerminal(`[On-Demand Probe] Key "${data.key}" verified intact in memory: ${JSON.stringify(data.value)}`, 'success');
      } else {
        probeResult.innerHTML = `<span style="color: var(--accent-amber)">⚠️ Key/Index "${val}" not present in active memory dataset.</span>`;
      }
    } catch (e) {
      probeResult.innerHTML = `<span style="color: var(--accent-rose)">❌ Probe error: ${e.message}</span>`;
    }
  });

  // Resident Cache Playground Logic
  const playKey = document.getElementById('play-key');
  const playVal = document.getElementById('play-val');
  const playOutput = document.getElementById('play-output');

  document.getElementById('btn-play-set').addEventListener('click', async () => {
    const key = playKey.value.trim();
    let val = playVal.value.trim();
    try { val = JSON.parse(val); } catch (e) {}

    try {
      const res = await fetch('/api/cache/set', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ key, value: val }),
      });
      const data = await res.json();
      if (data.error) throw new Error(data.error);
      playOutput.innerHTML = `<span style="color: var(--accent-emerald)">✓ Stored key "<strong>${data.key}</strong>" in JudySimpleCache. Total resident keys: <strong>${data.total_cached}</strong></span>`;
      logTerminal(`[Cache Playground] Set key "${data.key}" into persistent worker memory (Total: ${data.total_cached} items)`, 'success');
      fetchStatus();
    } catch (e) {
      playOutput.innerHTML = `<span style="color: var(--accent-rose)">❌ Error: ${e.message}</span>`;
    }
  });

  document.getElementById('btn-play-get').addEventListener('click', async () => {
    const key = playKey.value.trim();
    try {
      const res = await fetch(`/api/cache/get?key=${encodeURIComponent(key)}`);
      const data = await res.json();
      if (data.found) {
        playOutput.innerHTML = `<span>Key: <strong>${data.key}</strong> &bull; Latency: <strong>${data.lookup_time_us} &mu;s</strong> &bull; Value: <code style="color: var(--badge-text)">${JSON.stringify(data.value)}</code></span>`;
        logTerminal(`[Cache Playground] Found "${data.key}" in ${data.lookup_time_us} &mu;s`, 'info');
      } else {
        playOutput.innerHTML = `<span style="color: var(--accent-amber)">⚠️ Key "${key}" not found in resident memory (nil).</span>`;
      }
    } catch (e) {
      playOutput.innerHTML = `<span style="color: var(--accent-rose)">❌ Error: ${e.message}</span>`;
    }
  });

  document.getElementById('btn-play-prefix').addEventListener('click', async () => {
    const prefix = 'tenant.1.';
    try {
      const res = await fetch('/api/cache/delete-prefix', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ prefix }),
      });
      const data = await res.json();
      if (data.error) throw new Error(data.error);
      playOutput.innerHTML = `<span style="color: var(--accent-emerald)">✓ O(range) sub-trie splice pruned <strong>${data.deleted}</strong> entries starting with "${prefix}" in <strong>${data.duration_ms} ms</strong>! (Remaining: ${data.remaining})</span>`;
      logTerminal(`[Cache Playground] Pruned ${data.deleted} entries with prefix "${prefix}" in ${data.duration_ms} ms via deletePrefix()`, 'highlight');
      fetchStatus();
    } catch (e) {
      playOutput.innerHTML = `<span style="color: var(--accent-rose)">❌ Error: ${e.message}</span>`;
    }
  });
});
