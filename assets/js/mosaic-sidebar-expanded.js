//# PFAD: /assets/js/mosaic-sidebar-expanded.js
(function () {
  try {
    var body = document.body;
    if (!body) return;

    if (localStorage.getItem('sidebar-expanded') === 'true') {
      body.classList.add('sidebar-expanded');
    } else {
      body.classList.remove('sidebar-expanded');
    }
  } catch (e) {
    // ignore
  }
})();