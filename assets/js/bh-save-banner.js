(function () {
    'use strict';

    var banner  = null;
    var btnEl   = null;
    var textEl  = null;
    var _cb     = null;
    var _snap   = null;

    /* ── Snapshot helpers ─────────────────────────────────────────────────── */

    function _captureScope() {
        var content = document.getElementById('bh-page-content');
        if (!content) return '{}';
        var state = {};
        content.querySelectorAll('input, select, textarea').forEach(function (f) {
            var k = f.id || f.name;
            if (!k) return;
            state[k] = (f.type === 'checkbox' || f.type === 'radio') ? f.checked : f.value;
        });
        return JSON.stringify(state);
    }

    function _isDirty() {
        return _snap !== null && _captureScope() !== _snap;
    }

    /* ── Public API ───────────────────────────────────────────────────────── */

    window.BhSaveBanner = {

        /** Zeige Banner mit optionalem Custom-Callback und optionalem Label */
        show: function (saveCallback, label) {
            if (!banner) return;
            _cb = saveCallback || null;
            textEl.textContent = label || 'Please save your changes!';
            btnEl.disabled = false;
            btnEl.textContent = 'Save Changes';
            banner.classList.add('is-visible');
        },

        /** Verstecke Banner */
        hide: function () {
            if (!banner) return;
            banner.classList.remove('is-visible');
            _cb = null;
        },

        /** Nach erfolgreichem AJAX-Save: Snapshot aktualisieren + Banner ausblenden */
        markSaved: function () {
            _snap = _captureScope();
            this.hide();
        },

        /** Setze Button in Lade-Zustand */
        setSaving: function () {
            if (!btnEl) return;
            btnEl.disabled = true;
            btnEl.textContent = 'Saving…';
        },

        /** Zeige Fehlerzustand — Banner bleibt sichtbar, Button wird re-aktiviert */
        setError: function (msg) {
            if (!btnEl || !textEl) return;
            btnEl.disabled = false;
            btnEl.textContent = 'Save Changes';
            textEl.textContent = msg || 'Error while saving — please try again!';
        },

        /** Erzwinge Banner-Anzeige mit bestehendem Callback (z.B. nach Tag-Änderung) */
        markDirty: function (saveCallback) {
            if (!_isDirty() && !saveCallback) return;
            if (saveCallback) _cb = saveCallback;
            this.show(_cb);
        }
    };

    /* ── Init nach DOM-Ready ─────────────────────────────────────────────── */

    document.addEventListener('DOMContentLoaded', function () {
        banner  = document.getElementById('bh-save-banner');
        btnEl   = document.getElementById('bh-save-banner-btn');
        textEl  = document.getElementById('bh-save-banner-text');
        if (!banner || !btnEl || !textEl) return;

        /* Snapshot beim Laden nehmen */
        _snap = _captureScope();

        var content = document.getElementById('bh-page-content');
        if (content) {
            /* change-Events (Checkboxen, Selects, fertig abgetippte Felder) */
            content.addEventListener('change', function (e) {
                if (e.target.id === 'bh-save-banner-btn') return;
                if (_isDirty()) {
                    window.BhSaveBanner.show(_cb);
                } else {
                    window.BhSaveBanner.hide();
                }
            });

            /* input-Events (Tippen in Textfelder) */
            content.addEventListener('input', function (e) {
                if (e.target.id === 'bh-save-banner-btn') return;
                if (_isDirty()) {
                    window.BhSaveBanner.show(_cb);
                }
            });
        }

        /* Banner-Button-Klick */
        btnEl.addEventListener('click', function () {
            if (_cb) {
                window.BhSaveBanner.setSaving();
                _cb();
                return;
            }
            /* Fallback: ersten [data-save-btn] auf der Seite klicken */
            var saveBtn = document.querySelector('[data-save-btn]');
            if (saveBtn) {
                window.BhSaveBanner.setSaving();
                saveBtn.click();
            }
        });
    });
}());
