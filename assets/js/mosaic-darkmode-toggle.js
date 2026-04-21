//# PFAD: /assets/js/mosaic-darkmode-toggle.js
(function () {
  function apply(mode) {
    var html = document.documentElement;
    if (mode === 'on') {
      localStorage.setItem('dark-mode', 'true');
      html.classList.add('dark');
      html.style.colorScheme = 'dark';
    } else {
      localStorage.setItem('dark-mode', 'false');
      html.classList.remove('dark');
      html.style.colorScheme = 'light';
    }

    try {
      document.dispatchEvent(new CustomEvent('darkMode', { detail: { mode: mode } }));
    } catch (e) {
      // ignore
    }
  }

  function currentMode() {
    return localStorage.getItem('dark-mode') === 'true' ? 'on' : 'off';
  }

  document.addEventListener('DOMContentLoaded', function () {
    var btn = document.getElementById('darkModeToggle');
    if (!btn) return;

    btn.addEventListener('click', function () {
      apply(currentMode() === 'on' ? 'off' : 'on');
    });
  });
})();