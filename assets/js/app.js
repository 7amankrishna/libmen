/* Library Management System — front-end helpers */
(function () {
  'use strict';

  /* ---- flash toast auto-dismiss ---- */
  var toast = document.getElementById('toast');
  if (toast) setTimeout(function () { toast.style.opacity = '0'; toast.style.transition = 'opacity .4s'; setTimeout(function () { toast.remove(); }, 420); }, 4500);

  /* ---- mobile sidebar ---- */
  var toggle = document.getElementById('navToggle');
  var sidebar = document.getElementById('sidebar');
  if (toggle && sidebar) {
    toggle.addEventListener('click', function () { sidebar.classList.toggle('open'); });
    document.addEventListener('click', function (ev) {
      if (sidebar.classList.contains('open') && !sidebar.contains(ev.target) && ev.target !== toggle && !toggle.contains(ev.target)) {
        sidebar.classList.remove('open');
      }
    });
  }

  /* ---- close user dropdown on outside click ---- */
  document.addEventListener('click', function (ev) {
    document.querySelectorAll('details.usermenu[open]').forEach(function (d) {
      if (!d.contains(ev.target)) d.removeAttribute('open');
    });
  });

  /* ---- confirm before destructive actions ---- */
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (ev) {
      if (!window.confirm(f.getAttribute('data-confirm'))) ev.preventDefault();
    });
  });

  /* ---- live due-date preview on the issue form ---- */
  var issueDate = document.getElementById('issue_date');
  var loanDays = document.getElementById('loan_days');
  var duePrev = document.getElementById('due_preview');
  function updateDue() {
    if (!issueDate || !loanDays || !duePrev) return;
    var d = new Date(issueDate.value + 'T00:00:00');
    if (isNaN(d)) { duePrev.textContent = '—'; return; }
    d.setDate(d.getDate() + (parseInt(loanDays.value, 10) || 0));
    duePrev.textContent = d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
  }
  if (issueDate) { issueDate.addEventListener('change', updateDue); loanDays.addEventListener('input', updateDue); updateDue(); }

  /* ---- auto-submit filter forms on change ---- */
  document.querySelectorAll('form[data-autosubmit] select').forEach(function (s) {
    s.addEventListener('change', function () { s.closest('form').submit(); });
  });

  /* ============================ charts ================================== */
  if (typeof Chart === 'undefined') return;

  Chart.defaults.font.family = "'Inter', system-ui, sans-serif";
  Chart.defaults.font.size = 11.5;
  Chart.defaults.color = '#6f6a85';

  var centerTextPlugin = {
    id: 'centerText',
    afterDraw: function (chart) {
      var opt = chart.config.options.plugins && chart.config.options.plugins.centerText;
      if (!opt) return;
      var area = chart.chartArea;
      var x = (area.left + area.right) / 2, y = (area.top + area.bottom) / 2;
      var ctx = chart.ctx;
      ctx.save();
      ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      ctx.fillStyle = '#211a33'; ctx.font = "800 26px Inter, sans-serif";
      ctx.fillText(opt.top, x, y - 8);
      ctx.fillStyle = '#6f6a85'; ctx.font = "600 11.5px Inter, sans-serif";
      ctx.fillText(opt.bottom, x, y + 14);
      ctx.restore();
    }
  };

  var barValuePlugin = {
    id: 'barValues',
    afterDatasetsDraw: function (chart) {
      var meta = chart.getDatasetMeta(0);
      var ctx = chart.ctx;
      ctx.save();
      ctx.fillStyle = '#211a33'; ctx.font = "700 12px Inter, sans-serif";
      ctx.textAlign = 'center';
      meta.data.forEach(function (bar, i) {
        var v = chart.data.datasets[0].data[i];
        if (v === null || v === undefined) return;
        ctx.fillText(String(v), bar.x, bar.y - 7);
      });
      ctx.restore();
    }
  };
  Chart.register(centerTextPlugin, barValuePlugin);

  var tooltipStyle = {
    backgroundColor: '#211a33', titleColor: '#fff', bodyColor: '#e6e1f5',
    padding: 10, cornerRadius: 10, boxPadding: 4, usePointStyle: true
  };

  window.LibCharts = {
    donut: function (id, data, centerTop, centerBottom) {
      var el = document.getElementById(id);
      if (!el) return;
      var total = data.values.reduce(function (a, b) { return a + b; }, 0);
      if (!total) { el.parentNode.innerHTML = '<div class="chart-empty">No data yet</div>'; return; }
      new Chart(el, {
        type: 'doughnut',
        data: {
          labels: data.labels,
          datasets: [{ data: data.values, backgroundColor: data.colors, borderWidth: 0, hoverOffset: 6 }]
        },
        options: {
          cutout: '72%', maintainAspectRatio: false,
          plugins: {
            centerText: { top: centerTop, bottom: centerBottom },
            legend: { display: false },
            tooltip: Object.assign({}, tooltipStyle, {
              callbacks: {
                label: function (c) {
                  var pct = total ? Math.round(c.parsed / total * 100) : 0;
                  return ' ' + c.label + ': ' + c.parsed + ' (' + pct + '%)';
                }
              }
            })
          }
        }
      });
    },

    line: function (id, labels, loans, returns) {
      var el = document.getElementById(id);
      if (!el) return;
      var has = loans.some(function (v) { return v > 0; }) || returns.some(function (v) { return v > 0; });
      if (!has) { el.parentNode.innerHTML = '<div class="chart-empty">No loans in this period yet</div>'; return; }
      new Chart(el, {
        type: 'line',
        data: {
          labels: labels,
          datasets: [
            { label: 'Loans (Checkouts)', data: loans, borderColor: '#7c3aed', backgroundColor: 'rgba(124,58,237,.14)', fill: true, tension: .38, pointRadius: 2.5, pointHoverRadius: 5, borderWidth: 2.2 },
            { label: 'Returns', data: returns, borderColor: '#34d399', backgroundColor: 'rgba(52,211,153,.12)', fill: true, tension: .38, pointRadius: 2.5, pointHoverRadius: 5, borderWidth: 2.2 }
          ]
        },
        options: {
          maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
          plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } }, tooltip: tooltipStyle },
          scales: {
            x: { grid: { display: false }, ticks: { maxTicksLimit: 10 } },
            y: { beginAtZero: true, grid: { color: '#f0ecf9' }, ticks: { precision: 0 } }
          }
        }
      });
    },

    bars: function (id, labels, values, color) {
      var el = document.getElementById(id);
      if (!el) return;
      var has = values.some(function (v) { return v > 0; });
      if (!has) { el.parentNode.innerHTML = '<div class="chart-empty">No checkouts in this period yet</div>'; return; }
      var palette = ['#7c3aed', '#4ade80', '#a78bfa', '#86efac', '#fca5a5'];
      new Chart(el, {
        type: 'bar',
        data: {
          labels: labels,
          datasets: [{ label: 'Checkouts', data: values, backgroundColor: palette, borderRadius: 8, maxBarThickness: 64 }]
        },
        options: {
          maintainAspectRatio: false,
          plugins: { legend: { display: false }, tooltip: tooltipStyle },
          scales: {
            x: { grid: { display: false } },
            y: { beginAtZero: true, grid: { color: '#f0ecf9' }, ticks: { precision: 0 } }
          }
        }
      });
    }
  };
})();
