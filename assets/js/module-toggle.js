// Handles the standardised module enable/disable toggle card.
// Runs at DOMContentLoaded so the body div referenced by data-bh-body
// is guaranteed to exist when applyState() looks it up.
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.bh-mod-chk').forEach(function (chk) {
        var bodyId = chk.dataset.bhBody || '';
        var pillId = chk.dataset.bhPill || '';
        var key    = chk.dataset.bhKey  || '';

        function applyState(on) {
            var body = bodyId ? document.getElementById(bodyId) : null;
            var pill = pillId ? document.getElementById(pillId) : null;
            if (pill) {
                pill.className   = 'bh-mod-pill ' + (on ? 'bh-mod-pill--on' : 'bh-mod-pill--off');
                pill.textContent = on ? '● Aktiv' : '● Deaktiviert';
            }
            if (body) {
                body.classList.toggle('bh-mod-body--disabled', !on);
            }
        }

        // Sync initial visual state (body div now exists)
        applyState(chk.checked);

        chk.addEventListener('change', function () {
            var on = chk.checked;
            var fd = new URLSearchParams();
            fd.set('_bh_mod_action',  'toggle');
            fd.set('_bh_mod_key',     key);
            fd.set('_bh_mod_enabled', on ? '1' : '0');
            fetch(window.location.href, {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    fd.toString(),
            }).then(function (r) { return r.json(); }).then(function (res) {
                if (!res.ok) { chk.checked = !on; applyState(!on); }
                else         { applyState(on); }
            }).catch(function () { chk.checked = !on; applyState(!on); });
        });
    });
});
