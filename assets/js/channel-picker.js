// Initialises every .it-picker-row[data-bh-val] at DOMContentLoaded.
// Pages just need data attributes on the row div; no inline JS required.
//
// Required attributes on .it-picker-row:
//   data-bh-val   — id of the hidden channel-value input
//   data-bh-bot   — bot id (integer)
// Optional attributes:
//   data-bh-guild — id of the hidden guild-value input
//
// Custom events dispatched on the row div:
//   bh-picker-picked  — channel selected  (detail: {id, name, guild_id})
//   bh-picker-cleared — channel removed

document.addEventListener('DOMContentLoaded', function () {

    function escHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    document.querySelectorAll('.it-picker-row[data-bh-val]').forEach(function (box) {
        var botId   = parseInt(box.dataset.bhBot   || '0', 10);
        var valId   = box.dataset.bhVal   || '';
        var guildId = box.dataset.bhGuild || '';
        var val     = valId   ? document.getElementById(valId)   : null;
        var guildEl = guildId ? document.getElementById(guildId) : null;
        var btn     = box.querySelector('.it-picker-add');

        if (!val || !btn) return;

        function renderTag(id, name) {
            box.querySelectorAll('.it-ch-tag').forEach(function (t) { t.remove(); });
            if (!id) return;
            var tag = document.createElement('span');
            tag.className = 'it-ch-tag';
            tag.innerHTML = escHtml('#' + (name || id))
                + '<button type="button" class="it-ch-tag-rm" title="Entfernen">×</button>';
            tag.querySelector('.it-ch-tag-rm').addEventListener('click', function (e) {
                e.stopPropagation();
                e.preventDefault();
                val.value = '';
                if (guildEl) guildEl.value = '';
                tag.remove();
                box.dispatchEvent(new CustomEvent('bh-picker-cleared'));
            });
            box.insertBefore(tag, btn);
        }

        if (val.value) renderTag(val.value, val.value);

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            BhPerm.openPicker(this, botId, 'channels', [], function (item) {
                val.value = item.id || '';
                if (guildEl && item.guild_id) guildEl.value = item.guild_id;
                renderTag(item.id, item.name || item.id);
                box.dispatchEvent(new CustomEvent('bh-picker-picked', { detail: item }));
            });
        });
    });
});
