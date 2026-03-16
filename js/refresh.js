(function () {
  const tbody = document.getElementById('cameraTbody');
  const lastRefresh = document.getElementById('lastRefresh');
  const resultCount = document.getElementById('resultCount');
  const toast = document.getElementById('toast');
  let toastTimer;

  function showToast(msg) {
    toast.textContent = msg;
    toast.style.display = 'block';
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { toast.style.display = 'none'; }, 3000);
  }

  function statusBadge(s) {
    const map = { ONLINE: 'online', OFFLINE: 'offline', WARNING: 'warning', UNKNOWN: 'unknown' };
    const cls = map[s] || 'unknown';
    return `<span class="badge ${cls}"><span class="dot"></span>${s}</span>`;
  }

  function fmt(val) { return val ? val : '—'; }

  async function load() {
    const q = document.getElementById('q').value;
    const status = document.getElementById('filterStatus').value;
    const video = document.getElementById('filterVideo').value;
    const recording = document.getElementById('filterRecording').value;

    const params = new URLSearchParams({ q, status, video, recording });
    try {
      const res = await fetch('fetch_status.php?' + params);
      const data = await res.json();
      if (!data.ok) { showToast('Error loading cameras'); return; }

      tbody.innerHTML = data.cameras.length === 0
        ? '<tr><td colspan="10" class="muted">No cameras match filters.</td></tr>'
        : data.cameras.map(c => `<tr>
            <td>${statusBadge(c.status)}</td>
            <td>${c.camera_name}</td>
            <td class="mono">${c.ip_address}</td>
            <td>${fmt(c.nvr_name)}</td>
            <td>${fmt(c.area)}</td>
            <td class="mono">${c.video_signal_status}</td>
            <td class="mono">${c.recording_status}</td>
            <td class="mono">${c.communication_status}</td>
            <td class="mono">${fmt(c.last_seen)}</td>
            <td class="mono">${fmt(c.last_event_type)}</td>
            <td class="mono">${fmt(c.last_event_at)}</td>
          </tr>`).join('');

      const n = data.cameras.length;
      resultCount.innerHTML = `<span class="dot"></span>${n} camera${n !== 1 ? 's' : ''}`;
      lastRefresh.textContent = new Date().toLocaleTimeString();
    } catch (e) {
      showToast('Network error — retrying in 3 min');
    }
  }

  document.getElementById('applyFilters').addEventListener('click', load);
  load();
  setInterval(load, 180_000);
})();