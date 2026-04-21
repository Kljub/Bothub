//# PFAD: /assets/js/bot-metrics-chart.js
(function () {
  "use strict";

  function getBotIdFromUrl() {
    const u = new URL(window.location.href);
    const v = u.searchParams.get("bot_id");
    const n = v ? parseInt(v, 10) : 0;
    return Number.isFinite(n) ? n : 0;
  }

  async function loadMetrics(botId) {
    const url = `/api/v1/bot_metrics.php?bot_id=${encodeURIComponent(botId)}&range=24h`;
    const res = await fetch(url, { credentials: "same-origin" });
    const data = await res.json();
    if (!data || !data.ok) {
      throw new Error((data && data.error) ? data.error : "metrics load failed");
    }
    return data;
  }

  function destroyIfExists(canvas) {
    if (!canvas) return;
    const existing = Chart.getChart(canvas);
    if (existing) existing.destroy();
  }

  function render(canvas, payload) {
    const ctx = canvas.getContext("2d");
    const labels = payload.labels || [];
    const ds = payload.datasets || [];

    const uptime = ds.find(d => d.key === "uptime_ok");
    const calls = ds.find(d => d.key === "cmd_calls");
    const errs = ds.find(d => d.key === "errors");

    new Chart(ctx, {
      type: "line",
      data: {
        labels,
        datasets: [
          {
            label: uptime ? uptime.label : "Erreichbar",
            data: uptime ? uptime.data : [],
            tension: 0.35,
            pointRadius: 0,
            borderWidth: 2
          },
          {
            label: calls ? calls.label : "Commands",
            data: calls ? calls.data : [],
            tension: 0.35,
            pointRadius: 0,
            borderWidth: 2
          },
          {
            label: errs ? errs.label : "Errors",
            data: errs ? errs.data : [],
            tension: 0.35,
            pointRadius: 0,
            borderWidth: 2
          }
        ]
      },
      options: {
        maintainAspectRatio: false,
        interaction: { intersect: false, mode: "index" },
        plugins: {
          legend: { display: true }
        },
        scales: {
          x: {
            type: "time",
            time: { tooltipFormat: "YYYY-MM-DD HH:mm" },
            ticks: { maxRotation: 0 }
          },
          y: {
            beginAtZero: true
          }
        }
      }
    });
  }

  async function init() {
    const canvas = document.getElementById("dashboard-card-01");
    if (!canvas || typeof Chart === "undefined") return;

    const botId = getBotIdFromUrl();
    if (!botId) return;

    try {
      const payload = await loadMetrics(botId);
      destroyIfExists(canvas);
      render(canvas, payload);
    } catch (e) {
      console.warn("Bot metrics chart:", e && e.message ? e.message : e);
    }
  }

  document.addEventListener("DOMContentLoaded", init);
})();