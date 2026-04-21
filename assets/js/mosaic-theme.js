//# PFAD: /assets/js/mosaic-theme.js
(function () {
  try {
    var html = document.documentElement;
    var dark = localStorage.getItem('dark-mode');
    // Mosaic convention: 'true' means dark, anything else -> light
    if (dark === 'true') {
      html.classList.add('dark');
      html.style.colorScheme = 'dark';
    } else {
      html.classList.remove('dark');
      html.style.colorScheme = 'light';
    }
  } catch (e) {
    // ignore
  }
})();