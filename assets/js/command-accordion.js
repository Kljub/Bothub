// PFAD: /assets/js/command-accordion.js

/* ── BhAutoSave — debounced form auto-save via fetch ── */
var BhAutoSave = (function () {
    var _timer     = null;
    var _statusEl  = null;
    var _hideTimer = null;

    function getStatusEl() {
        if (_statusEl) return _statusEl;
        _statusEl           = document.createElement('div');
        _statusEl.className = 'bh-autosave-status';
        document.body.appendChild(_statusEl);
        return _statusEl;
    }

    function setStatus(state) {
        var el = getStatusEl();
        clearTimeout(_hideTimer);
        el.className   = 'bh-autosave-status bh-autosave-status--' + state;
        el.textContent = state === 'saving' ? 'Speichern…'
                       : state === 'saved'  ? 'Gespeichert'
                       :                      'Fehler beim Speichern';
        el.style.opacity = '1';
        if (state !== 'saving') {
            _hideTimer = setTimeout(function () { el.style.opacity = '0'; }, 1800);
        }
    }

    function doSave() {
        var form = document.querySelector('form[data-autosave]');
        if (!form) return;
        setStatus('saving');
        fetch(window.location.href, {
            method: 'POST',
            body: new FormData(form),
        }).then(function (r) {
            setStatus(r.ok ? 'saved' : 'error');
        }).catch(function () {
            setStatus('error');
        });
    }

    function trigger() {
        clearTimeout(_timer);
        _timer = setTimeout(doSave, 450);
    }

    return { trigger: trigger };
}());

document.addEventListener('DOMContentLoaded', function () {
    var cards = document.querySelectorAll('.command-card');

    function closeAllCardsExcept(currentCard) {
        cards.forEach(function (card) {
            if (card !== currentCard) {
                card.classList.remove('open');
            }
        });
    }

    cards.forEach(function (card) {
        var header = card.querySelector('.command-header');
        var panel  = card.querySelector('.command-panel');

        if (!header || !panel) return;

        header.addEventListener('click', function (event) {
            if (event.target.closest('.toggle')) return;

            var isOpen = card.classList.contains('open');
            closeAllCardsExcept(card);

            if (isOpen) {
                card.classList.remove('open');
            } else {
                card.classList.add('open');
            }
        });

        panel.addEventListener('click', function (event) {
            event.stopPropagation();
        });
    });

    // Auto-save on toggle change
    document.querySelectorAll('.command-card .toggle input[type="checkbox"]').forEach(function (cb) {
        cb.addEventListener('change', function () {
            BhAutoSave.trigger();
        });
    });

    document.addEventListener('click', BhPerm.closePicker);
});

/* ── BhPerm — self-contained permission system for predefined/moderation ── */
var BhPerm = (function () {

    var DISCORD_PERMISSIONS = [
        { key: 'ADMINISTRATOR',           label: 'Administrator' },
        { key: 'MANAGE_GUILD',            label: 'Manage Server' },
        { key: 'MANAGE_CHANNELS',         label: 'Manage Channels' },
        { key: 'MANAGE_ROLES',            label: 'Manage Roles' },
        { key: 'MANAGE_MESSAGES',         label: 'Manage Messages' },
        { key: 'MANAGE_WEBHOOKS',         label: 'Manage Webhooks' },
        { key: 'MANAGE_EMOJIS_AND_STICKERS', label: 'Manage Emojis & Stickers' },
        { key: 'KICK_MEMBERS',            label: 'Kick Members' },
        { key: 'BAN_MEMBERS',             label: 'Ban Members' },
        { key: 'MODERATE_MEMBERS',        label: 'Timeout Members' },
        { key: 'MENTION_EVERYONE',        label: 'Mention @everyone' },
        { key: 'VIEW_AUDIT_LOG',          label: 'View Audit Log' },
        { key: 'MUTE_MEMBERS',            label: 'Mute Members' },
        { key: 'DEAFEN_MEMBERS',          label: 'Deafen Members' },
        { key: 'MOVE_MEMBERS',            label: 'Move Members' },
        { key: 'USE_APPLICATION_COMMANDS', label: 'Use Slash Commands' },
        { key: 'MANAGE_NICKNAMES',        label: 'Manage Nicknames' },
        { key: 'CHANGE_NICKNAME',         label: 'Change Nickname' },
        { key: 'CREATE_INSTANT_INVITE',   label: 'Create Invite' },
        { key: 'ADD_REACTIONS',           label: 'Add Reactions' },
    ];

    var _picker       = null;
    var _pickerAnchor = null;
    var _cache        = {};

    function esc(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function closePicker() {
        if (_picker && _picker.parentNode) {
            _picker.parentNode.removeChild(_picker);
        }
        _picker       = null;
        _pickerAnchor = null;
    }

    function openPicker(anchorEl, botId, type, currentItems, onAdd) {
        closePicker();

        var _pickerGuildId = ''; // tracks which guild channels were loaded from

        var popup = document.createElement('div');
        popup.className = 'bh-perm-picker';
        _picker       = popup;
        _pickerAnchor = anchorEl;

        // Position
        var rect       = anchorEl.getBoundingClientRect();
        var popupH     = 320;
        var spaceBelow = window.innerHeight - rect.bottom;
        var spaceAbove = rect.top;
        var left       = Math.min(Math.max(4, rect.left), window.innerWidth - 290);

        if (spaceBelow >= popupH || spaceBelow >= spaceAbove) {
            popup.style.top    = (rect.bottom + 4) + 'px';
            popup.style.bottom = 'auto';
        } else {
            popup.style.bottom = (window.innerHeight - rect.top + 4) + 'px';
            popup.style.top    = 'auto';
        }
        popup.style.left = left + 'px';

        // Search
        var searchWrap  = document.createElement('div');
        searchWrap.className = 'bh-perm-picker__search';
        var searchInput = document.createElement('input');
        searchInput.type        = 'text';
        searchInput.placeholder = 'Suchen…';
        searchWrap.appendChild(searchInput);
        popup.appendChild(searchWrap);

        // List
        var list = document.createElement('div');
        list.className = 'bh-perm-picker__list';
        popup.appendChild(list);

        // Manual ID entry for roles/channels
        if (type !== 'permissions') {
            var manualWrap = document.createElement('div');
            manualWrap.className = 'bh-perm-picker__manual';
            var manualRow  = document.createElement('div');
            manualRow.className = 'bh-perm-picker__manual-row';
            var manualInput = document.createElement('input');
            manualInput.type        = 'text';
            manualInput.placeholder = 'ID eingeben…';
            var manualBtn = document.createElement('button');
            manualBtn.type        = 'button';
            manualBtn.textContent = '+ Add';
            manualBtn.addEventListener('click', function () {
                var id = manualInput.value.trim();
                if (!id) return;
                onAdd({ id: id, name: id });
                manualInput.value = '';
                closePicker();
            });
            manualRow.appendChild(manualInput);
            manualRow.appendChild(manualBtn);
            manualWrap.appendChild(manualRow);
            popup.appendChild(manualWrap);
        }

        document.body.appendChild(popup);

        function renderList(items) {
            list.innerHTML = '';
            var query    = searchInput.value.toLowerCase();
            var filtered = query
                ? items.filter(function (i) { return (i.label || i.name || '').toLowerCase().indexOf(query) !== -1; })
                : items;

            if (filtered.length === 0) {
                var empty = document.createElement('div');
                empty.className   = 'bh-perm-picker__empty';
                empty.textContent = query ? 'Keine Treffer' : 'Keine Einträge';
                list.appendChild(empty);
                return;
            }

            var existingIds = currentItems.map(function (i) { return i.id || i.key; });

            filtered.forEach(function (item) {
                var itemId     = item.id || item.key;
                var isSelected = existingIds.indexOf(itemId) !== -1;

                var btn = document.createElement('button');
                btn.type      = 'button';
                btn.className = 'bh-perm-picker__item' + (isSelected ? ' is-selected' : '');
                btn.textContent = item.label || item.name || itemId;

                if (!isSelected) {
                    btn.addEventListener('click', function (e) {
                        e.stopPropagation();
                        onAdd(type === 'permissions'
                            ? { key: item.key, name: item.label }
                            : { id: item.id, name: item.name, guild_id: _pickerGuildId });
                        closePicker();
                    });
                }
                list.appendChild(btn);
            });
        }

        if (type === 'permissions') {
            renderList(DISCORD_PERMISSIONS);
            searchInput.addEventListener('input', function () { renderList(DISCORD_PERMISSIONS); });
            setTimeout(function () { searchInput.focus(); }, 30);
            return;
        }

        if (botId <= 0) {
            list.innerHTML = '<div class="bh-perm-picker__empty">Kein Bot gewählt.</div>';
            return;
        }

        var endpoint = type === 'roles'
            ? '/api/v1/bot_guild_roles.php?bot_id='    + botId
            : type === 'guilds'
            ? '/api/v1/bot_guild_list.php?bot_id='     + botId
            : '/api/v1/bot_guild_channels.php?bot_id=' + botId;

        function applyData(data) {
            if (!data.ok) {
                list.innerHTML = '<div class="bh-perm-picker__empty">Fehler: ' + esc(String(data.error || 'Unbekannt')) + '</div>';
                return;
            }

            // guilds type: flat list of {id, name}
            if (type === 'guilds') {
                var gItems = data.guilds || [];
                renderList(gItems);
                searchInput.addEventListener('input', function () { renderList(gItems); });
                return;
            }

            if (data.needs_guild && Array.isArray(data.guilds)) {
                list.innerHTML = '';
                var hint = document.createElement('div');
                hint.className   = 'bh-perm-picker__empty';
                hint.textContent = 'Server wählen:';
                list.appendChild(hint);

                data.guilds.forEach(function (g) {
                    var gbtn = document.createElement('button');
                    gbtn.type        = 'button';
                    gbtn.className   = 'bh-perm-picker__item';
                    gbtn.textContent = g.name || g.id;
                    gbtn.addEventListener('click', function (e) {
                        e.stopPropagation();
                        list.innerHTML = '<div class="bh-perm-picker__empty">Lädt…</div>';
                        var subUrl = endpoint + '&guild_id=' + encodeURIComponent(g.id);
                        if (_cache[subUrl]) {
                            var items2 = type === 'roles' ? (_cache[subUrl].roles || []) : (_cache[subUrl].channels || []);
                            renderList(items2);
                            searchInput.addEventListener('input', function () { renderList(items2); });
                            return;
                        }
                        fetch(subUrl).then(function (r) { return r.json(); }).then(function (d2) {
                            _cache[subUrl] = d2;
                            if (d2.guild_id) _pickerGuildId = d2.guild_id;
                            var items2 = type === 'roles' ? (d2.roles || []) : (d2.channels || []);
                            renderList(items2);
                            searchInput.addEventListener('input', function () { renderList(items2); });
                        }).catch(function () {
                            list.innerHTML = '<div class="bh-perm-picker__empty">Ladefehler</div>';
                        });
                    });
                    list.appendChild(gbtn);
                });
                return;
            }

            if (data.guild_id) _pickerGuildId = data.guild_id;
            var items = type === 'roles' ? (data.roles || []) : (data.channels || []);
            renderList(items);
            searchInput.addEventListener('input', function () { renderList(items); });
        }

        list.innerHTML = '<div class="bh-perm-picker__empty">Lädt…</div>';

        if (_cache[endpoint]) {
            applyData(_cache[endpoint]);
        } else {
            fetch(endpoint).then(function (r) { return r.json(); }).then(function (data) {
                if (data.ok && !data.needs_guild) _cache[endpoint] = data;
                applyData(data);
            }).catch(function (err) {
                list.innerHTML = '<div class="bh-perm-picker__empty">Netzwerkfehler: ' + esc(String(err)) + '</div>';
            });
        }

        setTimeout(function () { searchInput.focus(); }, 30);
    }

    function buildPermPanel(panelEl, cmdKey, botId, perms) {
        if (panelEl.dataset.bhBuilt === '1') return;
        panelEl.dataset.bhBuilt = '1';

        var sections = [
            { key: 'allowed_roles',        title: 'Allowed Roles',        desc: 'Users with these roles can use the command. @everyone means anyone can use it.', type: 'roles' },
            { key: 'banned_roles',         title: 'Banned Roles',         desc: "Users with these roles can't use the command.",                                   type: 'roles' },
            { key: 'required_permissions', title: 'Required Permissions', desc: 'Users need these server permissions to use this command.',                         type: 'permissions' },
            { key: 'banned_channels',      title: 'Banned Channels',      desc: 'The command will not work in these channels.',                                     type: 'channels' },
        ];

        var heading = document.createElement('div');
        heading.className   = 'bh-perm-heading';
        heading.textContent = 'Permission Options';
        panelEl.appendChild(heading);

        function savePerms() {
            var input = document.querySelector('.bh-settings-json[data-command-key="' + cmdKey + '"]');
            if (input) input.value = JSON.stringify(perms);
            BhAutoSave.trigger();
        }

        sections.forEach(function (section) {
            if (!Array.isArray(perms[section.key])) {
                perms[section.key] = section.key === 'allowed_roles'
                    ? [{ id: 'everyone', name: '@everyone' }]
                    : [];
            }

            var box = document.createElement('div');
            box.className = 'bh-perm-box';

            var boxTitle = document.createElement('div');
            boxTitle.className   = 'bh-perm-box-title';
            boxTitle.textContent = section.title;

            var boxDesc = document.createElement('div');
            boxDesc.className   = 'bh-perm-box-desc';
            boxDesc.textContent = section.desc;

            var tagRow = document.createElement('div');
            tagRow.className = 'bh-perm-tags';

            var addBtn = document.createElement('button');
            addBtn.type        = 'button';
            addBtn.className   = 'bh-perm-add';
            addBtn.title       = 'Hinzufügen';
            addBtn.textContent = '+';

            function rebuildTags() {
                tagRow.innerHTML = '';
                var items = perms[section.key] || [];
                items.forEach(function (item, idx) {
                    var tag   = document.createElement('span');
                    tag.className = 'bh-perm-tag';
                    tag.appendChild(document.createTextNode(item.name || item.label || item.id || item.key || '?'));

                    var rm   = document.createElement('button');
                    rm.type        = 'button';
                    rm.className   = 'bh-perm-tag-rm';
                    rm.textContent = '×';
                    rm.title       = 'Entfernen';
                    rm.addEventListener('click', function () {
                        perms[section.key].splice(idx, 1);
                        savePerms();
                        rebuildTags();
                    });
                    tag.appendChild(rm);
                    tagRow.appendChild(tag);
                });
                tagRow.appendChild(addBtn);
            }

            addBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                if (_pickerAnchor === addBtn) { closePicker(); return; }
                openPicker(addBtn, botId, section.type, perms[section.key], function (entry) {
                    var id = entry.id || entry.key;
                    var already = (perms[section.key] || []).some(function (i) { return (i.id || i.key) === id; });
                    if (already) return;
                    perms[section.key].push(entry);
                    savePerms();
                    rebuildTags();
                });
            });

            rebuildTags();
            box.appendChild(boxTitle);
            box.appendChild(boxDesc);
            box.appendChild(tagRow);
            panelEl.appendChild(box);
        });
    }

    // Init: build all panels on page load
    document.addEventListener('DOMContentLoaded', function () {
        var data   = window.BhCmdData || {};
        var botId  = data.botId  || 0;
        var cmds   = data.commands || {};

        document.querySelectorAll('.bh-perm-panel').forEach(function (panelEl) {
            var cmdKey  = panelEl.dataset.commandKey;
            if (!cmdKey) return;

            // Read initial perms from hidden input
            var input = document.querySelector('.bh-settings-json[data-command-key="' + cmdKey + '"]');
            var perms = {};
            if (input) {
                try { perms = JSON.parse(input.value) || {}; } catch (_) {}
            }
            if (!perms || typeof perms !== 'object') perms = {};

            buildPermPanel(panelEl, cmdKey, botId, perms);
        });

        // Close picker on outside click
        document.addEventListener('click', function (e) {
            if (_picker && !_picker.contains(e.target) && e.target !== _pickerAnchor) {
                closePicker();
            }
        });
    });

    return { closePicker: closePicker, openPicker: openPicker };
}());

/* ── bhSetupChannelPicker — reusable single-channel picker helper ── */
function bhSetupChannelPicker(boxId, valId, btnId, botId, guildValId, onClear) {
    var box      = document.getElementById(boxId);
    var val      = document.getElementById(valId);
    var btn      = document.getElementById(btnId);
    var guildVal = guildValId ? document.getElementById(guildValId) : null;
    if (!box || !val || !btn) return null;

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

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
            if (guildVal) guildVal.value = '';
            tag.remove();
            if (typeof onClear === 'function') onClear();
        });
        box.insertBefore(tag, btn);
    }

    if (val.value) renderTag(val.value, val.value);

    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        BhPerm.openPicker(this, botId, 'channels', [], function (item) {
            val.value = item.id;
            if (guildVal && item.guild_id) guildVal.value = item.guild_id;
            renderTag(item.id, item.name || item.id);
        });
    });

    return {
        renderTag: renderTag,
        getGuildId: function () { return guildVal ? guildVal.value : ''; },
        clear: function () {
            val.value = '';
            if (guildVal) guildVal.value = '';
            box.querySelectorAll('.it-ch-tag').forEach(function (t) { t.remove(); });
        },
    };
}
