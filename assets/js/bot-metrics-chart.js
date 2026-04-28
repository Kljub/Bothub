//# PFAD: /assets/js/bot-metrics-chart.js
(function () {
  'use strict';

  var POLL_INTERVAL = 30 * 1000; // 30 seconds
  var RANGE = '6h';
  var chart = null;
  var pollTimer = null;
  var totalCmds = 0;

  function getBotId() {
    var u = new URL(window.location.href);
    var v = u.searchParams.get('bot_id');
    var n = v ? parseInt(v, 10) : 0;
    return Number.isFinite(n) ? n : 0;
  }

  async function fetchMetrics(botId) {
    var url = '/api/v1/bot_metrics.php?bot_id=' + encodeURIComponent(botId) + '&range=' + RANGE;
    var res = await fetch(url, { credentials: 'same-origin' });
    var data = await res.json();
    if (!data || !data.ok) throw new Error(data && data.error ? data.error : 'metrics load failed');
    return data;
  }

  function isDark() {
    return document.documentElement.classList.contains('dark') ||
           document.body.classList.contains('dark') ||
           window.matchMedia('(prefers-color-scheme: dark)').matches;
  }

  function colors() {
    var dark = isDark();
    return {
      grid:    dark ? 'rgba(255,255,255,.07)' : 'rgba(0,0,0,.06)',
      tick:    dark ? '#6b7280' : '#9ca3af',
      uptime:  '#22d3ee',   // cyan  — online ping
      calls:   '#818cf8',   // indigo — commands
      errors:  '#f87171',   // red   — errors
    };
  }

  function destroyExisting(canvas) {
    var existing = typeof Chart !== 'undefined' && Chart.getChart ? Chart.getChart(canvas) : null;
    if (existing) existing.destroy();
  }

  function buildChart(canvas, payload) {
    destroyExisting(canvas);
    var ctx = canvas.getContext('2d');
    var c = colors();
    var labels   = payload.labels  || [];
    var ds       = payload.datasets || [];
    var uptimeDs = ds.find(function (d) { return d.key === 'uptime_ok'; });
    var callsDs  = ds.find(function (d) { return d.key === 'cmd_calls'; });
    var errDs    = ds.find(function (d) { return d.key === 'errors'; });

    totalCmds = payload.total_cmds != null ? payload.total_cmds
              : (callsDs && callsDs.data ? callsDs.data.reduce(function (a, b) { return a + b; }, 0) : 0);
    updateBadges(totalCmds);

    chart = new Chart(ctx, {
      type: 'line',
      data: {
        labels: labels,
        datasets: [
          {
            label: 'Commands',
            key: 'cmd_calls',
            data: callsDs ? callsDs.data : [],
            borderColor: c.calls,
            backgroundColor: c.calls.replace(')', ', .12)').replace('rgb', 'rgba'),
            fill: true,
            tension: 0.4,
            pointRadius: 0,
            borderWidth: 2,
            yAxisID: 'yCmds',
          },
          {
            label: 'Errors',
            key: 'errors',
            data: errDs ? errDs.data : [],
            borderColor: c.errors,
            backgroundColor: 'rgba(248,113,113,.08)',
            fill: true,
            tension: 0.4,
            pointRadius: 0,
            borderWidth: 2,
            yAxisID: 'yCmds',
          },
          {
            label: 'Online',
            key: 'uptime_ok',
            data: uptimeDs ? uptimeDs.data : [],
            borderColor: c.uptime,
            backgroundColor: 'transparent',
            fill: false,
            tension: 0.4,
            pointRadius: 0,
            borderWidth: 1.5,
            borderDash: [4, 4],
            yAxisID: 'yUptime',
          },
        ],
      },
      options: {
        animation: false,
        maintainAspectRatio: false,
        interaction: { intersect: false, mode: 'index' },
        plugins: {
          legend: {
            display: true,
            position: 'bottom',
            labels: {
              color: c.tick,
              boxWidth: 12,
              padding: 16,
              font: { size: 11 },
            },
          },
          tooltip: {
            callbacks: {
              title: function (items) {
                var raw = items[0] && items[0].label ? items[0].label : '';
                try {
                  var d = new Date(raw);
                  return d.toLocaleString('de-DE', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit' });
                } catch (_) { return raw; }
              },
            },
          },
        },
        scales: {
          x: {
            type: 'time',
            time: { unit: 'minute', stepSize: 30, displayFormats: { minute: 'HH:mm' } },
            grid: { color: c.grid },
            ticks: { color: c.tick, maxRotation: 0, autoSkip: true, maxTicksLimit: 8, font: { size: 11 } },
          },
          yCmds: {
            position: 'left',
            beginAtZero: true,
            grid: { color: c.grid },
            ticks: { color: c.tick, precision: 0, font: { size: 11 } },
          },
          yUptime: {
            position: 'right',
            beginAtZero: true,
            grid: { display: false },
            ticks: { color: c.uptime, precision: 0, font: { size: 10 } },
            title: { display: true, text: 'Online-Pings', color: c.uptime, font: { size: 10 } },
          },
        },
      },
    });
  }

  function updateBadges(count) {
    var badge = document.getElementById('bh-cmd-total');
    if (badge) badge.textContent = count.toLocaleString('de-DE');
  }

  function updateChart(payload) {
    if (!chart) return;
    var ds = payload.datasets || [];
    chart.data.labels = payload.labels || [];

    chart.data.datasets.forEach(function (d) {
      var src = ds.find(function (s) { return s.key === d.key; });
      if (src) d.data = src.data;
    });

    totalCmds = payload.total_cmds != null ? payload.total_cmds : totalCmds;
    updateBadges(totalCmds);

    chart.update('none'); // no animation on refresh
  }

  function setLiveIndicator(ok) {
    var dot = document.getElementById('bh-chart-live-dot');
    var lbl = document.getElementById('bh-chart-live-lbl');
    if (dot) dot.className = ok
      ? 'inline-block w-2 h-2 rounded-full bg-emerald-500 animate-pulse'
      : 'inline-block w-2 h-2 rounded-full bg-gray-500';
    if (lbl) lbl.textContent = ok ? 'Live' : 'Offline';
  }

  var botId = 0;

  async function poll() {
    if (!botId) return;
    try {
      var payload = await fetchMetrics(botId);
      if (chart) {
        updateChart(payload);
      } else {
        var canvas = document.getElementById('bh-bot-metrics-chart');
        if (canvas) buildChart(canvas, payload);
      }
      setLiveIndicator(true);
    } catch (e) {
      console.warn('[BotHub] metrics poll:', e && e.message ? e.message : e);
      setLiveIndicator(false);
    }
  }

  async function init() {
    var canvas = document.getElementById('bh-bot-metrics-chart');
    if (!canvas || typeof Chart === 'undefined') return;

    botId = getBotId();
    if (!botId) return;

    await poll();
    pollTimer = setInterval(poll, POLL_INTERVAL);
  }

  document.addEventListener('DOMContentLoaded', init);
}());
