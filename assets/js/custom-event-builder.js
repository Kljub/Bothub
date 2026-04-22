// PFAD: /assets/js/custom-event-builder.js

(function () {
    'use strict';

    const root = document.getElementById('ceb-root');
    if (!root) return;

    const form            = document.getElementById('ceb-form');
    const canvas          = document.getElementById('ceb-canvas');
    const world           = document.getElementById('ceb-world');
    const canvasInner     = document.getElementById('ceb-canvas-inner');
    const edgesSvg        = document.getElementById('ceb-edges');
    const initialJsonField = document.getElementById('ceb-initial-json');
    const builderJsonField = document.getElementById('ceb-builder-json');
    const propsEmpty      = document.getElementById('ceb-props-empty');
    const propsPanel      = document.getElementById('ceb-props-panel');
    const propsDrawer     = document.getElementById('ceb-props-drawer');
    const dynamicFields   = document.getElementById('ceb-dynamic-fields');
    const propNodeId      = document.getElementById('ceb-prop-node-id');
    const propNodeType    = document.getElementById('ceb-prop-node-type');
    const deleteNodeBtn   = document.getElementById('ceb-delete-node-btn');
    const mainLayout      = root.querySelector('.cc-main');
    const saveStatus      = document.getElementById('ceb-save-status');
    const centerBtn       = document.getElementById('ceb-center-btn');
    const clearSelBtn     = document.getElementById('ceb-clear-sel-btn');
    const zoomInBtn       = document.getElementById('ceb-zoom-in-btn');
    const zoomOutBtn      = document.getElementById('ceb-zoom-out-btn');
    const zoomResetBtn    = document.getElementById('ceb-zoom-reset-btn');
    const blockSearch     = document.getElementById('ceb-block-search');
    const blockList       = document.getElementById('ceb-block-list');
    const variablesList   = document.getElementById('ceb-variables-list');
    const eventTypeDisplay = document.getElementById('ceb-event-type-display');
    const metaNameInput   = document.getElementById('ceb-meta-name');
    const metaDescInput   = document.getElementById('ceb-meta-desc');
    const hiddenName      = document.getElementById('ceb-event-name');
    const hiddenType      = document.getElementById('ceb-event-type');
    const hiddenDesc      = document.getElementById('ceb-description');
    const saveBtn         = document.getElementById('ceb-save-btn');
    const errorLog        = document.getElementById('ceb-error-log');

    const META = window.CebMeta || {};
    const EVENT_TYPES  = window.CebEventTypes  || {};
    const EVENT_LABELS = window.CebEventLabels || {};

    // ── Context variables by event category ─────────────────────────────────
    const EVENT_CONTEXT_VARS = {
        'message': [
            '{message.id}', '{message.content}', '{message.author.id}', '{message.author.name}',
            '{message.channel.id}', '{message.guild.id}', '{guild.id}', '{guild.name}',
        ],
        'member': [
            '{member.id}', '{member.name}', '{member.display_name}', '{member.tag}',
            '{guild.id}', '{guild.name}',
        ],
        'reaction': [
            '{reaction.emoji}', '{reaction.emoji.id}', '{reaction.user.id}', '{reaction.user.name}',
            '{reaction.message.id}', '{reaction.channel.id}', '{guild.id}', '{guild.name}',
        ],
        'role': [
            '{role.id}', '{role.name}', '{role.color}', '{guild.id}', '{guild.name}',
        ],
        'channel': [
            '{channel.id}', '{channel.name}', '{channel.type}', '{guild.id}', '{guild.name}',
        ],
        'guild': [
            '{guild.id}', '{guild.name}', '{guild.owner_id}', '{guild.member_count}',
        ],
        'invite': [
            '{invite.code}', '{invite.channel.id}', '{invite.inviter.id}', '{guild.id}',
        ],
        'boost': [
            '{member.id}', '{member.name}', '{guild.id}', '{guild.name}',
            '{guild.boost_level}', '{guild.boost_count}',
        ],
        'bot': [
            '{bot.id}', '{bot.name}', '{guild.id}', '{guild.name}',
        ],
        'thread': [
            '{thread.id}', '{thread.name}', '{thread.parent.id}', '{guild.id}',
        ],
        'scheduled_event': [
            '{event.id}', '{event.name}', '{event.description}', '{guild.id}',
        ],
        'music': [
            '{track.title}', '{track.url}', '{track.duration}', '{track.requester.id}',
            '{guild.id}', '{guild.name}', '{queue.size}',
        ],
        'audit': [
            '{audit.action_type}', '{audit.executor.id}', '{audit.target.id}',
            '{audit.reason}', '{guild.id}',
        ],
        'automod': [
            '{automod.rule.id}', '{automod.rule.name}', '{automod.action.type}',
            '{automod.user.id}', '{automod.channel.id}', '{guild.id}',
        ],
    };

    // ── Block definitions ─────────────────────────────────────────────────────
    const _IN  = [{ name: 'in',   label: 'In',   color: '#4f8cff' }];
    const _OUT = [{ name: 'next', label: 'Next', color: '#4f8cff' }];
    const _BOOL_OUT = [
        { name: 'true',  label: 'True',  color: '#30c968' },
        { name: 'false', label: 'False', color: '#ef5350' },
    ];

    const BLOCK_DEFS = {
        // ── Trigger (native) ──────────────────────────────────────────────────
        'trigger.event': {
            label: 'Event Trigger', category: 'trigger', color: '#2a7cff',
            subtitle: 'Discord Event', badge: 'E',
            ports_in: [], ports_out: [{ name: 'next', label: 'next', color: '#4f8cff' }],
            defaults: { event_type: '', filter_bot_events: '1', ephemeral: '0', cooldown_type: 'none', cooldown_seconds: 10, required_permissions: [] },
        },

        // ── Message ───────────────────────────────────────────────────────────
        'action.send_message': {
            label: 'Send Message', category: 'action', color: '#2a7cff',
            ports_in: _IN, ports_out: _OUT,
            defaults: { content: '', tts: false, ephemeral: false },
        },
        'action.message.send_or_edit': {
            label: 'Send or Edit a Message', category: 'action', color: '#2a7cff',
            ports_in: _IN,
            ports_out: [
                { name: 'next',   label: 'Next',   color: '#4f8cff' },
                { name: 'button', label: 'Button', color: '#2a7cff' },
                { name: 'menu',   label: 'Menu',   color: '#2a7cff' },
            ],
            defaults: { response_type: 'reply', target_message_id: '', target_channel_id: '', target_option_name: '', target_dm_option_name: '', target_user_id: '', edit_target_var: '', var_name: '', message_content: '', embeds: [], ephemeral: false },
        },
        'action.message.edit_component': {
            label: 'Edit a Button or Select Menu', category: 'action', color: '#2a7cff',
            ports_in: _IN, ports_out: _OUT, defaults: {},
        },
        'action.message.send_form': {
            label: 'Send a Form', category: 'action', color: '#2a7cff',
            ports_in: _IN, ports_out: [{ name: 'next', label: 'Submitted', color: '#4f8cff' }],
            defaults: { form_name: 'my-form', form_title: 'Form Title', block_label: 'Send a Form', fields: [] },
        },
        'action.delete_message': {
            label: 'Delete Message', category: 'action', color: '#2a7cff',
            ports_in: _IN, ports_out: _OUT,
            defaults: { delete_mode: 'by_var', var_name: '', message_id: '', channel_id: '' },
        },
        'action.message.publish': {
            label: 'Publish a Message', category: 'action', color: '#2a7cff',
            ports_in: _IN, ports_out: _OUT, defaults: {},
        },
        'action.message.react': {
            label: 'React to a Message', category: 'action', color: '#2a7cff',
            ports_in: _IN, ports_out: _OUT,
            defaults: { react_mode: 'by_var', var_name: '', message_id: '', channel_id: '', emojis: [] },
        },
        'action.message.pin': {
            label: 'Pin a Message', category: 'action', color: '#2a7cff',
            ports_in: _IN, ports_out: _OUT,
            defaults: { pin_mode: 'by_var', var_name: '', message_id: '', channel_id: '' },
        },

        // ── Components ────────────────────────────────────────────────────────
        'action.button': {
            label: 'Button', category: 'action', color: '#2a7cff',
            ports_in: [{ name: 'in', label: 'Input', color: '#2a7cff' }],
            ports_out: [{ name: 'next', label: 'Clicked', color: '#4f8cff' }],
            defaults: { label: 'Button 1', style: 'primary', emoji: '', custom_id: '' },
        },
        'action.select_menu': {
            label: 'Select Menu', category: 'action', color: '#2a7cff',
            ports_in: [{ name: 'in', label: 'Input', color: '#2a7cff' }],
            ports_out: [{ name: 'next', label: 'Selected', color: '#4f8cff' }],
            defaults: { placeholder: 'Select an option...', menu_type: '', options: [], min_values: 1, max_values: 1, var_name: '', disabled: 'false', show_replies: 'show', custom_id: '' },
        },

        // ── HTTP ──────────────────────────────────────────────────────────────
        'action.http.request': {
            label: 'Send API Request', category: 'action', color: '#2bb5a0',
            ports_in: _IN, ports_out: _OUT,
            defaults: { var_name: '', method: 'GET', url: '', params: [], headers: [], body: '', body_type: 'json', opt_exclude_empty: true, opt_vars_url: true, opt_vars_body: true },
        },

        // ── Flow Control ──────────────────────────────────────────────────────
        'action.flow.loop.run': {
            label: 'Run Loop', category: 'action', color: '#d3a53b',
            ports_in: _IN,
            ports_out: [{ name: 'body', label: 'Loop Body', color: '#d3a53b' }, { name: 'next', label: 'Nach Loop', color: '#4f8cff' }],
            defaults: { var_name: 'loop', mode: 'count', count: '3', list_var: '' },
        },
        'action.flow.loop.stop': {
            label: 'Stop Loop', category: 'action', color: '#d3a53b',
            ports_in: _IN, ports_out: [], defaults: {},
        },
        'action.flow.wait': {
            label: 'Wait', category: 'action', color: '#d3a53b',
            ports_in: _IN, ports_out: _OUT, defaults: { duration: 5 },
        },
        'action.utility.error_log': {
            label: 'Send an Error Log Message', category: 'action', color: '#4f5f80',
            ports_in: _IN, ports_out: _OUT, defaults: { message: '' },
        },
        'action.bot.set_status': {
            label: 'Change the Bot Status', category: 'action', color: '#d3a53b',
            ports_in: _IN, ports_out: _OUT,
            defaults: { status: 'online', activity_type: 'Playing', activity_text: '' },
        },

        // ── Voice Channel ─────────────────────────────────────────────────────
        'action.vc.join': {
            label: 'Join a Voice Channel', category: 'action', color: '#30c968',
            ports_in: _IN, ports_out: _OUT, defaults: { channel_id: '' },
        },
        'action.vc.leave': {
            label: 'Leave VC', category: 'action', color: '#30c968',
            ports_in: _IN, ports_out: _OUT, defaults: {},
        },
        'action.vc.move_member': {
            label: 'Move a VC Member', category: 'action', color: '#30c968',
            ports_in: _IN, ports_out: _OUT, defaults: { user_id: '', channel_id: '' },
        },
        'action.vc.kick_member': {
            label: 'Kick a VC Member', category: 'action', color: '#30c968',
            ports_in: _IN, ports_out: _OUT, defaults: { user_id: '' },
        },
        'action.vc.mute_member': {
            label: 'Mute / Unmute a VC Member', category: 'action', color: '#30c968',
            ports_in: _IN, ports_out: _OUT, defaults: { user_id: '', mute: true },
        },
        'action.vc.deafen_member': {
            label: 'Deafen / Undeafen a VC Member', category: 'action', color: '#30c968',
            ports_in: _IN, ports_out: _OUT, defaults: { user_id: '', deafen: true },
        },

        // ── Roles ─────────────────────────────────────────────────────────────
        'action.role.add_to_member': {
            label: 'Add Roles to a Member', category: 'action', color: '#8b63c8',
            ports_in: _IN, ports_out: _OUT, defaults: { user_id: '', role_ids: '' },
        },
        'action.role.remove_from_member': {
            label: 'Remove Roles from a Member', category: 'action', color: '#8b63c8',
            ports_in: _IN, ports_out: _OUT, defaults: { user_id: '', role_ids: '' },
        },
        'action.role.add_to_everyone': {
            label: 'Add Roles to Everyone', category: 'action', color: '#8b63c8',
            ports_in: _IN, ports_out: _OUT, defaults: { role_ids: '' },
        },
        'action.role.remove_from_everyone': {
            label: 'Remove Roles from Everyone', category: 'action', color: '#8b63c8',
            ports_in: _IN, ports_out: _OUT, defaults: { role_ids: '' },
        },
        'action.role.create': {
            label: 'Create a Role', category: 'action', color: '#8b63c8',
            ports_in: _IN, ports_out: _OUT, defaults: { name: '', color: '', hoist: false, result_var: '' },
        },
        'action.role.delete': {
            label: 'Delete a Role', category: 'action', color: '#8b63c8',
            ports_in: _IN, ports_out: _OUT, defaults: { role_id: '' },
        },
        'action.role.edit': {
            label: 'Edit a Role', category: 'action', color: '#8b63c8',
            ports_in: _IN, ports_out: _OUT, defaults: { role_id: '', name: '', color: '' },
        },

        // ── Channels & Threads ────────────────────────────────────────────────
        'action.channel.create': {
            label: 'Create a Channel', category: 'action', color: '#2a7cff',
            ports_in: _IN, ports_out: _OUT, defaults: { name: '', type: 'text', result_var: '' },
        },
        'action.channel.edit': {
            label: 'Edit a Channel', category: 'action', color: '#2a7cff',
            ports_in: _IN, ports_out: _OUT, defaults: { channel_id: '', name: '', topic: '' },
        },
        'action.channel.delete': {
            label: 'Delete a Channel', category: 'action', color: '#2a7cff',
            ports_in: _IN, ports_out: _OUT, defaults: { channel_id: '' },
        },
        'action.thread.create': {
            label: 'Create a Thread', category: 'action', color: '#2a7cff',
            ports_in: _IN, ports_out: _OUT, defaults: { name: '', auto_archive: '1440', result_var: '' },
        },
        'action.thread.edit': {
            label: 'Edit a Thread', category: 'action', color: '#2a7cff',
            ports_in: _IN, ports_out: _OUT, defaults: { thread_id: '', name: '' },
        },
        'action.thread.delete': {
            label: 'Delete a Thread', category: 'action', color: '#2a7cff',
            ports_in: _IN, ports_out: _OUT, defaults: { thread_id: '' },
        },

        // ── Moderation ────────────────────────────────────────────────────────
        'action.mod.kick': {
            label: 'Kick Member', category: 'action', color: '#ef5350',
            ports_in: _IN, ports_out: _OUT, defaults: { user_id: '', reason: '' },
        },
        'action.mod.ban': {
            label: 'Ban Member', category: 'action', color: '#ef5350',
            ports_in: _IN, ports_out: _OUT, defaults: { user_id: '', reason: '', delete_days: '0' },
        },
        'action.mod.timeout': {
            label: 'Timeout a Member', category: 'action', color: '#ef5350',
            ports_in: _IN, ports_out: _OUT, defaults: { user_id: '', duration: '300', reason: '' },
        },
        'action.mod.nickname': {
            label: "Change Member's Nickname", category: 'action', color: '#ef5350',
            ports_in: _IN, ports_out: _OUT, defaults: { user_id: '', nickname: '' },
        },
        'action.mod.purge': {
            label: 'Purge Messages', category: 'action', color: '#ef5350',
            ports_in: _IN, ports_out: _OUT, defaults: { amount: '10', channel_id: '' },
        },

        // ── Server ────────────────────────────────────────────────────────────
        'action.server.create_invite': {
            label: 'Create Server Invite', category: 'action', color: '#30c968',
            ports_in: _IN, ports_out: _OUT, defaults: { max_age: '86400', max_uses: '0', result_var: '' },
        },
        'action.server.leave': {
            label: 'Leave Server', category: 'action', color: '#ef5350',
            ports_in: _IN, ports_out: _OUT, defaults: {},
        },

        // ── Music ─────────────────────────────────────────────────────────────
        'action.music.create_player': { label: 'Create Music Player',  category: 'action', color: '#d33c99', ports_in: _IN, ports_out: _OUT, defaults: {} },
        'action.music.create_plex':   { label: 'Create Plex Player',   category: 'action', color: '#d33c99', ports_in: _IN, ports_out: _OUT, defaults: {} },
        'action.music.add_queue':     { label: 'Add to Queue',         category: 'action', color: '#d33c99', ports_in: _IN, ports_out: _OUT, defaults: { query: '' } },
        'action.music.play_queue':    { label: 'Play Queue',           category: 'action', color: '#d33c99', ports_in: _IN, ports_out: _OUT, defaults: {} },
        'action.music.remove_queue':  { label: 'Remove Queue',         category: 'action', color: '#d33c99', ports_in: _IN, ports_out: _OUT, defaults: {} },
        'action.music.shuffle_queue': { label: 'Shuffle Queue',        category: 'action', color: '#d33c99', ports_in: _IN, ports_out: _OUT, defaults: {} },
        'action.music.pause':         { label: 'Pause Music',          category: 'action', color: '#d33c99', ports_in: _IN, ports_out: _OUT, defaults: {} },
        'action.music.resume':        { label: 'Resume Music',         category: 'action', color: '#d33c99', ports_in: _IN, ports_out: _OUT, defaults: {} },
        'action.music.stop':          { label: 'Stop Music',           category: 'action', color: '#d33c99', ports_in: _IN, ports_out: _OUT, defaults: {} },
        'action.music.disconnect':    { label: 'Disconnect from VC',   category: 'action', color: '#d33c99', ports_in: _IN, ports_out: _OUT, defaults: {} },
        'action.music.skip':          { label: 'Skip Track',           category: 'action', color: '#d33c99', ports_in: _IN, ports_out: _OUT, defaults: {} },
        'action.music.previous':      { label: 'Play Previous Track',  category: 'action', color: '#d33c99', ports_in: _IN, ports_out: _OUT, defaults: {} },
        'action.music.seek':          { label: 'Set Track Position',   category: 'action', color: '#d33c99', ports_in: _IN, ports_out: _OUT, defaults: { position: '' } },
        'action.music.volume':        { label: 'Set Volume',           category: 'action', color: '#d33c99', ports_in: _IN, ports_out: _OUT, defaults: { volume: '100' } },
        'action.music.autoleave':     { label: 'Set Autoleave',        category: 'action', color: '#d33c99', ports_in: _IN, ports_out: _OUT, defaults: { enabled: true } },
        'action.music.replay':        { label: 'Replay Track',         category: 'action', color: '#d33c99', ports_in: _IN, ports_out: _OUT, defaults: {} },
        'action.music.filter':        { label: 'Apply Audio Filter',   category: 'action', color: '#d33c99', ports_in: _IN, ports_out: _OUT, defaults: { filter: '' } },
        'action.music.clear_filters': { label: 'Clear Filters',        category: 'action', color: '#d33c99', ports_in: _IN, ports_out: _OUT, defaults: {} },
        'action.music.search':        { label: 'Search Tracks',        category: 'action', color: '#d33c99', ports_in: _IN, ports_out: _OUT, defaults: { query: '', result_var: '' } },
        'action.music.loop_mode':     { label: 'Set Loop Mode',        category: 'action', color: '#d33c99', ports_in: _IN, ports_out: _OUT, defaults: { mode: 'none' } },

        // ── Variables ─────────────────────────────────────────────────────────
        'variable.local.set': {
            label: 'Set Local Variable', category: 'variable', color: '#2bb5a0',
            ports_in: _IN, ports_out: _OUT, defaults: { var_key: '', var_value: '' },
        },
        'variable.global.set': {
            label: 'Set Global Variable', category: 'variable', color: '#2bb5a0',
            ports_in: _IN, ports_out: _OUT, defaults: { var_key: '', var_value: '' },
        },
        'variable.global.delete': {
            label: 'Delete Global Variable', category: 'variable', color: '#2bb5a0',
            ports_in: _IN, ports_out: _OUT, defaults: { var_key: '' },
        },

        // ── Conditions ────────────────────────────────────────────────────────
        'condition.comparison': {
            label: 'Comparison Condition', category: 'condition', color: '#d3a53b',
            ports_in: _IN,
            ports_out: [{ name: 'else', label: 'Else', color: '#ef5350' }],
            defaults: { run_mode: 'first_match', conditions: [{ base_value: '', operator: '==', comparison_value: '' }] },
        },
        'condition.if': {
            label: 'If Condition', category: 'condition', color: '#d3a53b',
            ports_in: _IN, ports_out: _BOOL_OUT,
            defaults: { left_value: '', operator: 'equals', right_value: '' },
        },
        'condition.chance': {
            label: 'Chance Condition', category: 'condition', color: '#d3a53b',
            ports_in: _IN, ports_out: _BOOL_OUT, defaults: { percent: '50' },
        },
        'condition.permission': {
            label: 'Permission Condition', category: 'condition', color: '#d3a53b',
            ports_in: _IN, ports_out: _BOOL_OUT, defaults: { permission: 'Administrator' },
        },
        'condition.role': {
            label: 'Role Condition', category: 'condition', color: '#d3a53b',
            ports_in: _IN, ports_out: _BOOL_OUT, defaults: { role_id: '' },
        },
        'condition.channel': {
            label: 'Channel Condition', category: 'condition', color: '#d3a53b',
            ports_in: _IN, ports_out: _BOOL_OUT, defaults: { channel_id: '' },
        },
        'condition.user': {
            label: 'User Condition', category: 'condition', color: '#d3a53b',
            ports_in: _IN, ports_out: _BOOL_OUT, defaults: { user_id: '' },
        },
        'condition.status': {
            label: 'Status Condition', category: 'condition', color: '#d3a53b',
            ports_in: _IN, ports_out: _BOOL_OUT, defaults: { status: 'online' },
        },

        // ── Utility (native) ──────────────────────────────────────────────────
        'utility.error_handler': {
            label: 'Error Handler', category: 'utility', color: '#ef5350',
            subtitle: 'utility.error_handler', badge: '?',
            ports_in: [], ports_out: [{ name: 'next', label: 'next', color: '#4f8cff' }],
            defaults: { display_name: 'Error Handler', enabled: true },
        },
    };

    const NATIVE_NODE_TYPES = ['trigger.event', 'utility.error_handler'];
    const CATEGORIES_ORDER = ['trigger', 'action', 'variable', 'condition', 'utility'];
    const CATEGORY_LABELS = {
        trigger: 'Trigger', action: 'Actions', variable: 'Variables',
        condition: 'Conditions', utility: 'Utility',
    };

    // ── State ─────────────────────────────────────────────────────────────────
    const state = {
        builder: readInitialBuilder(),
        selectedNodeId: null,
        selectedNodeIds: [],
        clipboardNodes: [],
        pendingConnection: null,
        dragNodeId: null,
        dragStartWorldX: 0,
        dragStartWorldY: 0,
        dragSelectionSnapshot: [],
        dragRafPending: false,
        dirty: false,
        activeRailPanel: 'blocks',
        activeBlockTab: 'actions',
        viewport: { x: 0, y: 0, zoom: 1 },
        isPanning: false,
        panStartX: 0, panStartY: 0,
        panOriginX: 0, panOriginY: 0,
        isSelectingArea: false,
        selectionStartWorldX: 0, selectionStartWorldY: 0,
        selectionCurrentWorldX: 0, selectionCurrentWorldY: 0,
        selectionBoxEl: null,
    };

    normalizeState();
    ensureNativeNodes();
    ensureSelectionValidity();
    applyViewport();
    renderAll();
    registerStaticBlockItems();
    registerRail();
    registerTabs();
    registerToolbar();
    registerPanAndZoom();
    registerKeyboard();
    registerMetaInputs();
    registerFormSubmit();

    // Sync hiddenType from the trigger node's saved config on load
    (function syncInitialEventType() {
        const triggerNode = state.builder.nodes.find((n) => n.type === 'trigger.event');
        if (!triggerNode) return;
        const et = triggerNode.config?.event_type || '';
        if (et && hiddenType) hiddenType.value = et;
        if (et && eventTypeDisplay) eventTypeDisplay.textContent = EVENT_LABELS[et] || et;
    })();

    updateVariablesList(META.eventType || '');

    // ── Init helpers ──────────────────────────────────────────────────────────
    function readInitialBuilder() {
        try {
            const val = initialJsonField ? initialJsonField.value : '{}';
            return JSON.parse(val || '{}');
        } catch (_) {
            return { version: 1, viewport: { x: 0, y: 0, zoom: 1 }, nodes: [], edges: [] };
        }
    }

    function getDef(type) {
        return BLOCK_DEFS[type] || {
            label: type, category: 'utility', color: '#4f5f80',
            ports_out: [{ name: 'next', label: 'Next', color: '#4f8cff' }],
            ports_in: [{ name: 'in', label: 'In', color: '#4f8cff' }],
            defaults: {},
        };
    }

    function normalizeState() {
        if (!state.builder || typeof state.builder !== 'object') {
            state.builder = { version: 1, viewport: { x: 0, y: 0, zoom: 1 }, nodes: [], edges: [] };
        }
        if (!Array.isArray(state.builder.nodes)) state.builder.nodes = [];
        if (!Array.isArray(state.builder.edges)) state.builder.edges = [];

        if (state.builder.viewport && typeof state.builder.viewport === 'object') {
            state.viewport.x    = toNumber(state.builder.viewport.x, 0);
            state.viewport.y    = toNumber(state.builder.viewport.y, 0);
            state.viewport.zoom = clampZoom(toNumber(state.builder.viewport.zoom, 1));
        }

        state.builder.nodes = state.builder.nodes
            .filter((n) => n && typeof n === 'object' && typeof n.id === 'string' && typeof n.type === 'string')
            .map((n) => {
                const def = getDef(n.type);
                return {
                    id: n.id, type: n.type,
                    label: typeof n.label === 'string' && n.label !== '' ? n.label : def.label,
                    x: toInt(n.x, 0), y: toInt(n.y, 0),
                    config: Object.assign({}, def.defaults, isObject(n.config) ? n.config : {}),
                };
            });

        state.builder.edges = state.builder.edges
            .filter((e) => e && typeof e === 'object')
            .map((e) => ({
                id: typeof e.id === 'string' && e.id !== '' ? e.id : buildEdgeId(e.from_node_id, e.from_port, e.to_node_id, e.to_port),
                from_node_id: String(e.from_node_id || ''),
                from_port:    String(e.from_port    || ''),
                to_node_id:   String(e.to_node_id   || ''),
                to_port:      String(e.to_port      || ''),
            }))
            .filter((e) => e.from_node_id && e.from_port && e.to_node_id && e.to_port);
    }

    function ensureNativeNodes() {
        let triggerNode = state.builder.nodes.find((n) => n.type === 'trigger.event');
        if (!triggerNode) {
            triggerNode = {
                id: 'node_trigger_' + Date.now(),
                type: 'trigger.event', label: 'Event Trigger',
                x: 200, y: 260,
                config: Object.assign({}, getDef('trigger.event').defaults, { event_type: META.eventType || '' }),
            };
            state.builder.nodes.unshift(triggerNode);
        }

        let errorNode = state.builder.nodes.find((n) => n.type === 'utility.error_handler');
        if (!errorNode) {
            errorNode = {
                id: 'node_error_handler_' + Date.now(),
                type: 'utility.error_handler', label: 'Error Handler',
                x: triggerNode.x + 400, y: triggerNode.y,
                config: Object.assign({}, getDef('utility.error_handler').defaults),
            };
            state.builder.nodes.push(errorNode);
        }

    }

    function ensureSelectionValidity() {
        const valid = new Set(state.builder.nodes.map((n) => n.id));
        state.selectedNodeIds = state.selectedNodeIds.filter((id) => valid.has(id));
        if (state.selectedNodeId !== null && !valid.has(state.selectedNodeId)) state.selectedNodeId = null;
        if (state.selectedNodeId !== null && !state.selectedNodeIds.includes(state.selectedNodeId)) {
            state.selectedNodeIds = [state.selectedNodeId];
        }
    }

    // ── Render ────────────────────────────────────────────────────────────────
    function renderAll() {
        renderNodes();
        renderEdges();
        if (state.selectedNodeId) {
            showProperties(state.selectedNodeId);
        } else {
            hideProperties();
        }
    }

    function renderNodes() {
        if (!canvasInner) return;

        const existingIds = new Set();
        state.builder.nodes.forEach((node) => {
            existingIds.add(node.id);
            let el = canvasInner.querySelector('[data-node-id="' + node.id + '"]');
            if (!el) {
                el = createNodeElement(node);
                canvasInner.appendChild(el);
            }
            el.style.left = node.x + 'px';
            el.style.top  = node.y + 'px';
            el.classList.toggle('is-selected', state.selectedNodeIds.includes(node.id));
            // Refresh preview body (shows event type, cooldown, etc.)
            const previewEl = el.querySelector('.ceb-node-preview');
            if (previewEl) previewEl.innerHTML = renderNodePreview(node);
        });

        // Remove stale nodes
        canvasInner.querySelectorAll('[data-node-id]').forEach((el) => {
            if (!existingIds.has(el.getAttribute('data-node-id') || '')) el.remove();
        });
    }

    function renderNodePreview(node) {
        const cfg = node.config || {};
        switch (node.type) {
            case 'trigger.event': {
                const et = cfg.event_type || '';
                const etLabel = et ? (EVENT_LABELS[et] || et) : '(no event type)';
                const cd = cfg.cooldown_type && cfg.cooldown_type !== 'none'
                    ? cfg.cooldown_type + ': ' + (cfg.cooldown_seconds || 10) + 's'
                    : 'No cooldown';
                return '<div class="cc-node-preview-line">' + escHtml(etLabel) + '</div>'
                     + '<div class="cc-node-preview-line">' + escHtml(cd) + '</div>';
            }
            case 'utility.error_handler':
                return '<div class="cc-node-preview-line">' + escHtml(cfg.title || 'Error Handler') + '</div>';
            default: {
                const def = getDef(node.type);
                return '<div class="cc-node-preview-line">' + escHtml(def.label || node.type) + '</div>';
            }
        }
    }

    function createNodeElement(node) {
        const def      = getDef(node.type);
        const isNative = NATIVE_NODE_TYPES.includes(node.type);
        const category = def.category || 'action';

        const el = document.createElement('div');
        el.className = 'cc-node cc-node--' + category + (isNative ? ' cc-node--native' : '');
        el.setAttribute('data-node-id', node.id);
        el.style.position = 'absolute';
        el.style.left     = node.x + 'px';
        el.style.top      = node.y + 'px';
        el.style.minWidth = '200px';
        el.style.cursor   = 'grab';

        // Badge: explicit badge > icon (≤2 chars) > first letter of category
        const badgeChar = def.badge
            ? def.badge
            : (def.icon && def.icon.length <= 2 ? def.icon : category.charAt(0).toUpperCase());

        // Subtitle: always static from def (event type shown in preview lines)
        const subtitle = def.subtitle || node.type;

        const nativePill = isNative ? '<div class="cc-node-native-pill">NATIVE</div>' : '';

        // ── Input port ────────────────────────────────────────────────────────
        const inputPortHtml = def.ports_in.length > 0
            ? '<button type="button" class="cc-port cc-port--input"'
                + ' data-node-id="' + escHtml(node.id) + '"'
                + ' data-port-type="input" data-port-direction="input"'
                + ' data-port-name="in" title="in"></button>'
            : '';

        // ── Output ports ──────────────────────────────────────────────────────
        let outputPortsHtml = '';
        def.ports_out.forEach((portDef, index) => {
            const spacing = def.ports_out.length > 1
                ? (100 / (def.ports_out.length + 1)) * (index + 1)
                : 50;
            outputPortsHtml += '<button type="button" class="cc-port cc-port--output'
                + (portDef.name === 'error' ? ' cc-port--error' : '')
                + '"'
                + ' data-node-id="' + escHtml(node.id) + '"'
                + ' data-port-type="output" data-port-direction="output"'
                + ' data-port-name="' + escHtml(portDef.name) + '"'
                + ' style="left:calc(' + spacing + '% - 6px);"'
                + ' title="' + escHtml(portDef.label || portDef.name) + '">'
                + '</button>';
        });

        el.innerHTML = inputPortHtml
            + '<div class="cc-node-head">'
            + '  <div class="cc-node-badge">' + escHtml(badgeChar) + '</div>'
            + '  <div class="cc-node-title-wrap">'
            + '    <div class="cc-node-title">' + escHtml(node.label || def.label) + '</div>'
            + '    <div class="cc-node-subtitle">' + escHtml(subtitle) + '</div>'
            + '  </div>'
            + nativePill
            + '</div>'
            + '<div class="cc-node-body ceb-node-preview">' + renderNodePreview(node) + '</div>'
            + outputPortsHtml;

        // ── Port event listeners ──────────────────────────────────────────────
        el.querySelectorAll('.cc-port--output').forEach((portEl) => {
            const portName  = portEl.getAttribute('data-port-name') || '';
            const portColor = portEl.classList.contains('cc-port--error') ? '#ef5350' : '#4f8cff';
            portEl.addEventListener('mousedown', (evt) => {
                evt.stopPropagation();
                startPendingConnection(node.id, portName, portColor, evt);
            });
        });

        const inputPortEl = el.querySelector('.cc-port--input');
        if (inputPortEl) {
            inputPortEl.addEventListener('mousedown', (evt) => {
                if (evt.button !== 0) return;
                evt.stopPropagation();
                const portName = inputPortEl.getAttribute('data-port-name') || 'in';
                const pos = getPortCenter(inputPortEl);
                state.pendingConnection = {
                    isDragging: true,
                    startedFromInput: true,
                    toNodeId: node.id,
                    toPort: portName,
                    color: '#4f8cff',
                    startX: pos.x, startY: pos.y,
                    mouseX: pos.x, mouseY: pos.y,
                };
                evt.preventDefault();
            });
        }

        // ── Node drag / select ────────────────────────────────────────────────
        el.addEventListener('mousedown', (evt) => {
            if (evt.button !== 0) return;
            if ((evt.target instanceof Element) && evt.target.closest('.cc-port')) return;
            evt.stopPropagation();
            startDrag(node.id, evt);
        });

        el.addEventListener('click', (evt) => {
            if ((evt.target instanceof Element) && evt.target.closest('.cc-port')) return;
            if (state.dragNodeId) return;
            selectNode(node.id, evt.shiftKey || evt.ctrlKey || evt.metaKey);
        });

        return el;
    }

    function escHtml(str) {
        return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function makePortEl(nodeId, portDef, direction) {
        const wrap = document.createElement('div');
        wrap.className = 'cc-port-wrap cc-port-wrap--' + direction;

        const portEl = document.createElement('div');
        portEl.className = 'cc-port cc-port--' + direction;
        portEl.style.background = portDef.color || '#4f8cff';
        portEl.setAttribute('data-node-id', nodeId);
        portEl.setAttribute('data-port-name', portDef.name);
        portEl.setAttribute('data-port-direction', direction);
        portEl.title = portDef.label || portDef.name;

        if (direction === 'output') {
            wrap.appendChild(portEl);
            portEl.addEventListener('mousedown', (evt) => {
                evt.stopPropagation();
                startPendingConnection(nodeId, portDef.name, portDef.color || '#4f8cff', evt);
            });
        } else {
            wrap.appendChild(portEl);
        }

        return wrap;
    }

    // ── Edges ─────────────────────────────────────────────────────────────────
    function renderEdges() {
        if (!edgesSvg || !canvasInner) return;
        edgesSvg.innerHTML = '';

        state.builder.edges.forEach((edge) => {
            const fromEl = canvasInner.querySelector('[data-node-id="' + edge.from_node_id + '"] [data-port-name="' + edge.from_port + '"][data-port-direction="output"]');
            const toEl   = canvasInner.querySelector('[data-node-id="' + edge.to_node_id   + '"] [data-port-name="' + edge.to_port   + '"][data-port-direction="input"]');
            if (!fromEl || !toEl) return;

            const fromPos = getPortCenter(fromEl);
            const toPos   = getPortCenter(toEl);
            drawEdge(edge.id, fromPos, toPos, '#4f8cff');
        });

        // Pending connection ghost line
        if (state.pendingConnection?.isDragging) {
            const { startX, startY, mouseX, mouseY, color } = state.pendingConnection;
            if (state.pendingConnection.startedFromInput) {
                drawEdge('__pending__', { x: mouseX, y: mouseY }, { x: startX, y: startY }, color || '#4f8cff', true);
            } else {
                drawEdge('__pending__', { x: startX, y: startY }, { x: mouseX, y: mouseY }, color || '#4f8cff', true);
            }
        }
    }

    function getPortCenter(portEl) {
        const worldRect  = world.getBoundingClientRect();
        const portRect   = portEl.getBoundingClientRect();
        const cx = (portRect.left + portRect.width / 2 - worldRect.left) / state.viewport.zoom;
        const cy = (portRect.top  + portRect.height / 2 - worldRect.top) / state.viewport.zoom;
        return { x: cx, y: cy };
    }

    function drawEdge(id, from, to, color, isDashed) {
        const dx = to.x - from.x;
        const cp = Math.max(60, Math.abs(dx) * 0.45);
        const d  = `M ${from.x} ${from.y} C ${from.x + cp} ${from.y}, ${to.x - cp} ${to.y}, ${to.x} ${to.y}`;

        if (id !== '__pending__') {
            const g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
            g.className.baseVal = 'cc-edge-group';
            g.setAttribute('data-edge-id', id);

            const hitPath = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            hitPath.setAttribute('d', d);
            hitPath.setAttribute('fill', 'none');
            hitPath.setAttribute('stroke', 'transparent');
            hitPath.setAttribute('stroke-width', '12');
            g.appendChild(hitPath);

            const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            path.setAttribute('d', d);
            path.setAttribute('fill', 'none');
            path.setAttribute('stroke', color);
            path.setAttribute('stroke-width', '2');
            path.setAttribute('opacity', '0.7');
            if (isDashed) path.setAttribute('stroke-dasharray', '6 4');
            g.appendChild(path);

            // Delete button
            const mx = (from.x + to.x) / 2;
            const my = (from.y + to.y) / 2;
            const delG = document.createElementNS('http://www.w3.org/2000/svg', 'g');
            delG.className.baseVal = 'cc-edge-delete-btn';
            delG.setAttribute('data-edge-id', id);
            delG.style.cssText = 'cursor:pointer;opacity:0;transition:opacity .15s';

            const circ = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
            circ.className.baseVal = 'cc-edge-delete-circle';
            circ.setAttribute('data-edge-id', id);
            circ.setAttribute('cx', String(mx));
            circ.setAttribute('cy', String(my));
            circ.setAttribute('r', '8');
            circ.setAttribute('fill', '#1e232c');
            circ.setAttribute('stroke', '#ef5350');
            circ.setAttribute('stroke-width', '1.5');
            delG.appendChild(circ);

            const txt = document.createElementNS('http://www.w3.org/2000/svg', 'text');
            txt.className.baseVal = 'cc-edge-delete-text';
            txt.setAttribute('data-edge-id', id);
            txt.setAttribute('x', String(mx));
            txt.setAttribute('y', String(my + 4));
            txt.setAttribute('text-anchor', 'middle');
            txt.setAttribute('fill', '#ef5350');
            txt.setAttribute('font-size', '11');
            txt.textContent = '×';
            delG.appendChild(txt);

            g.appendChild(delG);

            g.addEventListener('mouseenter', () => { delG.style.opacity = '1'; });
            g.addEventListener('mouseleave', () => { delG.style.opacity = '0'; });

            edgesSvg.appendChild(g);
        } else {
            const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            path.setAttribute('d', d);
            path.setAttribute('fill', 'none');
            path.setAttribute('stroke', color);
            path.setAttribute('stroke-width', '2');
            path.setAttribute('stroke-dasharray', '6 4');
            path.setAttribute('opacity', '0.5');
            edgesSvg.appendChild(path);
        }
    }

    function isEdgeDeleteTarget(el) {
        return el instanceof Element && !!el.closest('.cc-edge-delete-btn, .cc-edge-delete-circle, .cc-edge-delete-text');
    }

    function deleteEdge(edgeId) {
        state.builder.edges = state.builder.edges.filter((e) => e.id !== edgeId);
        markDirty();
        renderEdges();
        writeJsonField();
    }

    // ── Connection drag ───────────────────────────────────────────────────────
    function startPendingConnection(nodeId, portName, color, evt) {
        const portEl = canvasInner.querySelector('[data-node-id="' + nodeId + '"] [data-port-name="' + portName + '"][data-port-direction="output"]');
        if (!portEl) return;
        const pos = getPortCenter(portEl);
        state.pendingConnection = {
            isDragging: true,
            fromNodeId: nodeId, fromPort: portName, color,
            startX: pos.x, startY: pos.y,
            mouseX: pos.x, mouseY: pos.y,
        };
        evt.preventDefault();
    }

    function connectPendingTo(targetNodeId, targetPortName) {
        const pc = state.pendingConnection;
        if (!pc) return;

        const dup = state.builder.edges.find(
            (e) => e.from_node_id === pc.fromNodeId && e.from_port === pc.fromPort && e.to_node_id === targetNodeId && e.to_port === targetPortName
        );
        if (!dup && pc.fromNodeId !== targetNodeId) {
            state.builder.edges.push({
                id: buildEdgeId(pc.fromNodeId, pc.fromPort, targetNodeId, targetPortName),
                from_node_id: pc.fromNodeId,
                from_port:    pc.fromPort,
                to_node_id:   targetNodeId,
                to_port:      targetPortName,
            });
            markDirty();
            writeJsonField();
        }
        state.pendingConnection = null;
        renderEdges();
    }

    // ── Selection & drag ──────────────────────────────────────────────────────
    function selectNode(nodeId, addToSel) {
        if (addToSel) {
            if (state.selectedNodeIds.includes(nodeId)) {
                state.selectedNodeIds = state.selectedNodeIds.filter((id) => id !== nodeId);
                if (state.selectedNodeId === nodeId) state.selectedNodeId = state.selectedNodeIds[0] || null;
            } else {
                state.selectedNodeIds.push(nodeId);
                state.selectedNodeId = nodeId;
            }
        } else {
            state.selectedNodeId = nodeId;
            state.selectedNodeIds = [nodeId];
        }
        renderAll();
    }

    function clearSelection() {
        state.selectedNodeId = null;
        state.selectedNodeIds = [];
    }

    function startDrag(nodeId, evt) {
        if (!state.selectedNodeIds.includes(nodeId)) {
            selectNode(nodeId, false);
        }
        const wp = getWorldPoint(evt.clientX, evt.clientY);
        state.dragNodeId = nodeId;
        state.dragStartWorldX = wp.x;
        state.dragStartWorldY = wp.y;
        state.dragSelectionSnapshot = state.selectedNodeIds.map((id) => {
            const n = findNodeById(id);
            return n ? { id: n.id, x: n.x, y: n.y } : null;
        }).filter(Boolean);
        evt.preventDefault();
    }

    function deleteSelectedNodes() {
        const toDelete = state.selectedNodeIds.filter((id) => {
            const n = findNodeById(id);
            return n && !NATIVE_NODE_TYPES.includes(n.type);
        });
        if (toDelete.length === 0) return;
        state.builder.nodes  = state.builder.nodes.filter((n) => !toDelete.includes(n.id));
        state.builder.edges  = state.builder.edges.filter((e) => !toDelete.includes(e.from_node_id) && !toDelete.includes(e.to_node_id));
        clearSelection();
        markDirty();
        renderAll();
        writeJsonField();
    }

    function copySelectedNodes() {
        state.clipboardNodes = state.selectedNodeIds
            .map((id) => findNodeById(id))
            .filter(Boolean)
            .map((n) => JSON.parse(JSON.stringify(n)));
    }

    function pasteClipboardNodes() {
        if (state.clipboardNodes.length === 0) return;
        const offset = 40;
        const newIds = new Map();
        const newNodes = state.clipboardNodes.map((n) => {
            const newId = n.type + '_' + Date.now() + '_' + Math.random().toString(36).slice(2, 6);
            newIds.set(n.id, newId);
            return { ...JSON.parse(JSON.stringify(n)), id: newId, x: n.x + offset, y: n.y + offset };
        });
        state.builder.nodes.push(...newNodes);
        clearSelection();
        newNodes.forEach((n) => state.selectedNodeIds.push(n.id));
        if (newNodes.length > 0) state.selectedNodeId = newNodes[0].id;
        markDirty();
        renderAll();
        writeJsonField();
    }

    // ── Area selection ────────────────────────────────────────────────────────
    function ensureSelectionBox() {
        if (!state.selectionBoxEl) {
            state.selectionBoxEl = document.getElementById('ceb-selection-box');
        }
    }

    function updateSelectionBox() {
        ensureSelectionBox();
        if (!state.selectionBoxEl) return;
        const x1 = Math.min(state.selectionStartWorldX, state.selectionCurrentWorldX);
        const y1 = Math.min(state.selectionStartWorldY, state.selectionCurrentWorldY);
        const w  = Math.abs(state.selectionCurrentWorldX - state.selectionStartWorldX);
        const h  = Math.abs(state.selectionCurrentWorldY - state.selectionStartWorldY);
        const z  = state.viewport.zoom;
        state.selectionBoxEl.style.cssText = `
            display:block;position:absolute;pointer-events:none;
            left:${x1 * z + state.viewport.x}px;
            top:${y1  * z + state.viewport.y}px;
            width:${w * z}px;height:${h * z}px;
            border:1px dashed #4f8cff;background:rgba(79,140,255,.06);z-index:100;
        `;
    }

    function finishAreaSelection() {
        state.isSelectingArea = false;
        if (state.selectionBoxEl) state.selectionBoxEl.style.display = 'none';
        const x1 = Math.min(state.selectionStartWorldX, state.selectionCurrentWorldX);
        const y1 = Math.min(state.selectionStartWorldY, state.selectionCurrentWorldY);
        const x2 = Math.max(state.selectionStartWorldX, state.selectionCurrentWorldX);
        const y2 = Math.max(state.selectionStartWorldY, state.selectionCurrentWorldY);
        state.selectedNodeIds = [];
        state.selectedNodeId  = null;
        state.builder.nodes.forEach((n) => {
            if (n.x >= x1 && n.y >= y1 && n.x <= x2 && n.y <= y2) {
                state.selectedNodeIds.push(n.id);
                if (!state.selectedNodeId) state.selectedNodeId = n.id;
            }
        });
        renderAll();
    }

    // ── Properties panel ──────────────────────────────────────────────────────
    function showProperties(nodeId) {
        const node = findNodeById(nodeId);
        if (!node || !propsPanel || !propsEmpty || !dynamicFields) return;
        propsEmpty.classList.add('is-hidden');
        propsPanel.classList.remove('is-hidden');
        if (propsDrawer) propsDrawer.classList.add('is-open');
        if (mainLayout) mainLayout.classList.add('is-drawer-open');
        if (propNodeId)   propNodeId.value       = nodeId;
        if (propNodeType) propNodeType.textContent = node.type;

        // Native nodes cannot be deleted
        if (deleteNodeBtn) {
            deleteNodeBtn.style.display = NATIVE_NODE_TYPES.includes(node.type) ? 'none' : '';
        }

        dynamicFields.innerHTML = '';
        buildPropertyFields(node);
    }

    function hideProperties() {
        if (propsEmpty) propsEmpty.classList.remove('is-hidden');
        if (propsPanel) propsPanel.classList.add('is-hidden');
        if (propsDrawer) propsDrawer.classList.remove('is-open');
        if (mainLayout) mainLayout.classList.remove('is-drawer-open');
    }

    function buildPropertyFields(node) {
        const cfg = node.config || {};

        switch (node.type) {

            // ── Trigger ───────────────────────────────────────────────────────
            case 'trigger.event':
                addTextField(node, 'display_name', 'Anzeigename', cfg.display_name || 'Event Trigger');
                addField(node, 'Event Typ', buildEventTypeSelect(node), 'Welches Discord-Event soll diesen Flow starten.');
                addTextField(node, 'description', 'Beschreibung', cfg.description || '', true);
                addSelectField(node, 'ephemeral', 'Antworten verbergen', cfg.ephemeral ?? '0', [
                    ['0', 'Antworten für alle anzeigen'],
                    ['1', 'Nur für den Auslöser sichtbar (Ephemeral)'],
                ], 'Verbirgt Bot-Antworten vor allen außer dem Auslöser. Gilt nur für Events mit Interaction-Kontext.');
                addSelectFieldWithRerender(node, 'cooldown_type', 'Event Cooldown', cfg.cooldown_type || 'none', [
                    ['none', 'Kein Cooldown'], ['user', 'Nutzer-Cooldown'], ['server', 'Server-Cooldown'],
                ], 'Verhindert mehrfaches Auslösen des Events in kurzer Zeit.');
                if (cfg.cooldown_type === 'user' || cfg.cooldown_type === 'server') {
                    addTextField(node, 'cooldown_seconds', 'Cooldown (Sekunden)', String(cfg.cooldown_seconds ?? 10), false, 'Dauer des Cooldowns in Sekunden.');
                }
                addSelectField(node, 'filter_bot_events', 'Bot-Events ignorieren', cfg.filter_bot_events ?? '1', [
                    ['1', 'Ja – Bot-Events ignorieren'], ['0', 'Nein – auch Bot-Events ausführen'],
                ], 'Events die von Bots ausgelöst wurden überspringen.');
                addPermissionsField(node, 'required_permissions', 'Benötigte Berechtigungen', 'Event wird nur ausgeführt wenn der Auslöser mindestens eine dieser Berechtigungen hat. Leer = immer ausführen.');
                break;

            // ── Message ───────────────────────────────────────────────────────
            case 'action.send_message':
                addTextField(node, 'content', 'Nachricht', cfg.content || '', true);
                addCheckboxField(node, 'tts', 'TTS', !!cfg.tts);
                addCheckboxField(node, 'ephemeral', 'Ephemeral', !!cfg.ephemeral);
                break;

            case 'action.message.send_or_edit':
                addMsgBuilderBtn(node);
                addTextField(node, 'var_name', 'Save Message as Variable', cfg.var_name || '');
                break;

            case 'action.delete_message':
                addSelectFieldWithRerender(node, 'delete_mode', 'Delete Mode', cfg.delete_mode || 'by_var', [
                    ['by_var', 'By Variable (stored message)'], ['by_id', 'By Message ID'],
                ]);
                if (cfg.delete_mode === 'by_var' || !cfg.delete_mode) {
                    addTextField(node, 'var_name', 'Message Variable', cfg.var_name || '');
                } else {
                    addTextField(node, 'message_id', 'Message ID', cfg.message_id || '');
                    addTextField(node, 'channel_id', 'Channel ID (optional)', cfg.channel_id || '');
                }
                break;

            case 'action.message.react':
                addSelectFieldWithRerender(node, 'react_mode', 'Message Mode', cfg.react_mode || 'by_var', [
                    ['by_var', 'By Variable (stored message)'], ['by_id', 'By Message ID'],
                ]);
                if (cfg.react_mode === 'by_var' || !cfg.react_mode) {
                    addTextField(node, 'var_name', 'Message Variable', cfg.var_name || '');
                } else {
                    addTextField(node, 'message_id', 'Message ID', cfg.message_id || '');
                    addTextField(node, 'channel_id', 'Channel ID (optional)', cfg.channel_id || '');
                }
                break;

            case 'action.message.pin':
                addSelectFieldWithRerender(node, 'pin_mode', 'Message Mode', cfg.pin_mode || 'by_var', [
                    ['by_var', 'By Variable (stored message)'], ['by_id', 'By Message ID'],
                ]);
                if (cfg.pin_mode === 'by_var' || !cfg.pin_mode) {
                    addTextField(node, 'var_name', 'Message Variable', cfg.var_name || '');
                } else {
                    addTextField(node, 'message_id', 'Message ID', cfg.message_id || '');
                    addTextField(node, 'channel_id', 'Channel ID (optional)', cfg.channel_id || '');
                }
                break;

            // ── Components ────────────────────────────────────────────────────
            case 'action.button':
                addTextField(node, 'label', 'Button Label', cfg.label || 'Button 1');
                addSelectField(node, 'style', 'Button Style', cfg.style || 'primary', [
                    ['primary', 'Blue'], ['secondary', 'Gray'], ['success', 'Green'], ['danger', 'Red'], ['link', 'Link'],
                ]);
                addTextField(node, 'emoji', 'Emoji (optional)', cfg.emoji || '');
                addTextField(node, 'custom_id', 'Custom ID (optional)', cfg.custom_id || '');
                break;

            case 'action.select_menu':
                addTextField(node, 'placeholder', 'Placeholder', cfg.placeholder || 'Select an option...');
                addSelectField(node, 'max_values', 'Multiselect', cfg.max_values || '1', [
                    ['1', 'Single Select'], ['25', 'Multi Select'],
                ]);
                addTextField(node, 'var_name', 'Variable Name (optional)', cfg.var_name || '');
                addSelectField(node, 'show_replies', 'Show Replies', cfg.show_replies || 'show', [
                    ['show', 'Show Replies'], ['hide', 'Hide Replies'],
                ]);
                addTextField(node, 'custom_id', 'Custom ID (optional)', cfg.custom_id || '');
                break;

            // ── HTTP ──────────────────────────────────────────────────────────
            case 'action.http.request':
                addTextField(node, 'var_name', 'Name (Variable)', cfg.var_name || '');
                addSelectField(node, 'method', 'Method', cfg.method || 'GET', [
                    ['GET','GET'],['POST','POST'],['PUT','PUT'],['PATCH','PATCH'],['DELETE','DELETE'],
                ]);
                addTextField(node, 'url', 'URL', cfg.url || '');
                addTextField(node, 'body', 'Request Body (JSON)', cfg.body || '', true);
                break;

            // ── Flow Control ──────────────────────────────────────────────────
            case 'action.flow.loop.run':
                addTextField(node, 'var_name', 'Variablenname (Prefix)', cfg.var_name || 'loop');
                addSelectField(node, 'mode', 'Modus', cfg.mode || 'count', [
                    ['count', 'Count'], ['foreach', 'For Each'],
                ]);
                addTextField(node, 'count', 'Anzahl (max. 50)', String(cfg.count ?? 3));
                addTextField(node, 'list_var', 'Listen-Variable (foreach)', cfg.list_var || '');
                break;

            case 'action.flow.wait':
                addTextField(node, 'duration', 'Wartezeit (Sekunden)', String(cfg.duration ?? 5));
                break;

            case 'action.utility.error_log':
                addTextField(node, 'message', 'Log Message', cfg.message || '', true);
                break;

            case 'action.bot.set_status':
                addSelectField(node, 'status', 'Status', cfg.status || 'online', [
                    ['online','Online'],['idle','Idle'],['dnd','Do Not Disturb'],['invisible','Invisible'],
                ]);
                addSelectField(node, 'activity_type', 'Activity Type', cfg.activity_type || 'Playing', [
                    ['Playing','Playing'],['Streaming','Streaming'],['Listening','Listening'],['Watching','Watching'],['Competing','Competing'],
                ]);
                addTextField(node, 'activity_text', 'Activity Text', cfg.activity_text || '');
                break;

            // ── Voice Channel ─────────────────────────────────────────────────
            case 'action.vc.join':
                addTextField(node, 'channel_id', 'Channel ID (leer = Channel des Nutzers)', cfg.channel_id || '');
                break;

            case 'action.vc.move_member':
                addTextField(node, 'user_id', 'User ID', cfg.user_id || '');
                addTextField(node, 'channel_id', 'Ziel-Channel ID', cfg.channel_id || '');
                break;

            case 'action.vc.kick_member':
                addTextField(node, 'user_id', 'User ID', cfg.user_id || '');
                break;

            case 'action.vc.mute_member':
                addTextField(node, 'user_id', 'User ID', cfg.user_id || '');
                addCheckboxField(node, 'mute', 'Stummschalten', cfg.mute !== false);
                break;

            case 'action.vc.deafen_member':
                addTextField(node, 'user_id', 'User ID', cfg.user_id || '');
                addCheckboxField(node, 'deafen', 'Taubschalten', cfg.deafen !== false);
                break;

            // ── Roles ─────────────────────────────────────────────────────────
            case 'action.role.add_to_member':
            case 'action.role.remove_from_member':
                addTextField(node, 'user_id', 'User ID oder {option.user}', cfg.user_id || '');
                addTextField(node, 'role_ids', 'Rollen-IDs (kommagetrennt)', cfg.role_ids || '');
                break;

            case 'action.role.add_to_everyone':
            case 'action.role.remove_from_everyone':
                addTextField(node, 'role_ids', 'Rollen-IDs (kommagetrennt)', cfg.role_ids || '');
                break;

            case 'action.role.create':
                addTextField(node, 'name', 'Rollenname', cfg.name || '');
                addTextField(node, 'color', 'Farbe (Hex)', cfg.color || '');
                addCheckboxField(node, 'hoist', 'Separat anzeigen', !!cfg.hoist);
                addTextField(node, 'result_var', 'Rollen-ID als Variable speichern', cfg.result_var || '');
                break;

            case 'action.role.delete':
                addTextField(node, 'role_id', 'Rollen-ID', cfg.role_id || '');
                break;

            case 'action.role.edit':
                addTextField(node, 'role_id', 'Rollen-ID', cfg.role_id || '');
                addTextField(node, 'name', 'Neuer Name', cfg.name || '');
                addTextField(node, 'color', 'Neue Farbe (Hex)', cfg.color || '');
                break;

            // ── Channels & Threads ────────────────────────────────────────────
            case 'action.channel.create':
                addTextField(node, 'name', 'Name', cfg.name || '');
                addSelectField(node, 'type', 'Typ', cfg.type || 'text', [
                    ['text','Text'],['voice','Voice'],['category','Category'],['announcement','Announcement'],
                ]);
                addTextField(node, 'result_var', 'Channel-ID als Variable speichern', cfg.result_var || '');
                break;

            case 'action.channel.edit':
                addTextField(node, 'channel_id', 'Channel ID', cfg.channel_id || '');
                addTextField(node, 'name', 'Neuer Name', cfg.name || '');
                addTextField(node, 'topic', 'Neues Thema', cfg.topic || '');
                break;

            case 'action.channel.delete':
                addTextField(node, 'channel_id', 'Channel ID', cfg.channel_id || '');
                break;

            case 'action.thread.create':
                addTextField(node, 'name', 'Thread-Name', cfg.name || '');
                addSelectField(node, 'auto_archive', 'Auto-Archive', cfg.auto_archive || '1440', [
                    ['60','1 Stunde'],['1440','1 Tag'],['4320','3 Tage'],['10080','1 Woche'],
                ]);
                addTextField(node, 'result_var', 'Thread-ID als Variable speichern', cfg.result_var || '');
                break;

            case 'action.thread.edit':
                addTextField(node, 'thread_id', 'Thread ID', cfg.thread_id || '');
                addTextField(node, 'name', 'Neuer Name', cfg.name || '');
                break;

            case 'action.thread.delete':
                addTextField(node, 'thread_id', 'Thread ID', cfg.thread_id || '');
                break;

            // ── Moderation ────────────────────────────────────────────────────
            case 'action.mod.kick':
                addTextField(node, 'user_id', 'User ID oder {option.user}', cfg.user_id || '');
                addTextField(node, 'reason', 'Grund', cfg.reason || '', true);
                break;

            case 'action.mod.ban':
                addTextField(node, 'user_id', 'User ID oder {option.user}', cfg.user_id || '');
                addTextField(node, 'reason', 'Grund', cfg.reason || '', true);
                addTextField(node, 'delete_days', 'Nachrichten löschen (Tage)', cfg.delete_days || '0');
                break;

            case 'action.mod.timeout':
                addTextField(node, 'user_id', 'User ID oder {option.user}', cfg.user_id || '');
                addTextField(node, 'duration', 'Dauer (Sekunden)', cfg.duration || '300');
                addTextField(node, 'reason', 'Grund', cfg.reason || '', true);
                break;

            case 'action.mod.nickname':
                addTextField(node, 'user_id', 'User ID oder {option.user}', cfg.user_id || '');
                addTextField(node, 'nickname', 'Neuer Nickname (leer = zurücksetzen)', cfg.nickname || '');
                break;

            case 'action.mod.purge':
                addTextField(node, 'amount', 'Anzahl (max. 100)', cfg.amount || '10');
                addTextField(node, 'channel_id', 'Channel ID (leer = aktueller Kanal)', cfg.channel_id || '');
                break;

            // ── Server ────────────────────────────────────────────────────────
            case 'action.server.create_invite':
                addTextField(node, 'max_age', 'Gültigkeitsdauer (Sek., 0=unbegrenzt)', cfg.max_age || '86400');
                addTextField(node, 'max_uses', 'Max. Nutzungen (0=unbegrenzt)', cfg.max_uses || '0');
                addTextField(node, 'result_var', 'Link als Variable speichern', cfg.result_var || '');
                break;

            // ── Variables ─────────────────────────────────────────────────────
            case 'variable.local.set':
            case 'variable.global.set':
                addTextField(node, 'var_key', 'Variable Name', cfg.var_key || '');
                addTextField(node, 'var_value', 'Value', cfg.var_value || '', true);
                break;

            case 'variable.global.delete':
                addTextField(node, 'var_key', 'Variable Name', cfg.var_key || '');
                break;

            // ── Conditions ────────────────────────────────────────────────────
            case 'condition.comparison':
                addTextField(node, 'base_value', 'Linker Wert', (cfg.conditions?.[0]?.base_value) || '');
                addSelectField(node, 'operator', 'Operator', (cfg.conditions?.[0]?.operator) || '==', [
                    ['==','Gleich (==)'],['!=','Ungleich (!=)'],['<','Kleiner als'],['<=','Kleiner oder gleich'],['>','Größer als'],['>=','Größer oder gleich'],
                    ['contains','Enthält'],['not_contains','Enthält nicht'],['starts_with','Beginnt mit'],['ends_with','Endet mit'],
                ]);
                addTextField(node, 'comparison_value', 'Rechter Wert', (cfg.conditions?.[0]?.comparison_value) || '');
                break;

            case 'condition.if':
                addTextField(node, 'left_value', 'Linker Wert', cfg.left_value || '');
                addSelectField(node, 'operator', 'Operator', cfg.operator || 'equals', [
                    ['equals','Ist gleich'],['not_equals','Ist ungleich'],['contains','Enthält'],
                    ['greater_than','Größer als'],['less_than','Kleiner als'],
                ]);
                addTextField(node, 'right_value', 'Rechter Wert', cfg.right_value || '');
                break;

            case 'condition.chance':
                addTextField(node, 'percent', 'Wahrscheinlichkeit (%)', cfg.percent || '50');
                break;

            case 'condition.permission':
                addSelectField(node, 'permission', 'Berechtigung', cfg.permission || 'Administrator', [
                    ['Administrator','Administrator'],['ManageGuild','Server verwalten'],['ManageMessages','Nachrichten verwalten'],
                    ['KickMembers','Mitglieder kicken'],['BanMembers','Mitglieder bannen'],['MuteMembers','Mitglieder stummschalten'],
                    ['ManageRoles','Rollen verwalten'],['ManageChannels','Kanäle verwalten'],
                ]);
                break;

            case 'condition.role':
                addTextField(node, 'role_id', 'Rollen-ID', cfg.role_id || '');
                break;

            case 'condition.channel':
                addTextField(node, 'channel_id', 'Channel-ID', cfg.channel_id || '');
                break;

            case 'condition.user':
                addTextField(node, 'user_id', 'User-ID', cfg.user_id || '');
                break;

            case 'condition.status':
                addSelectField(node, 'status', 'Status', cfg.status || 'online', [
                    ['online','Online'],['idle','Idle'],['dnd','Do Not Disturb'],['offline','Offline'],
                ]);
                break;

            // ── Utility (native) ──────────────────────────────────────────────
            case 'utility.error_handler':
                addTextField(node, 'display_name', 'Anzeigename', cfg.display_name || 'Error Handler');
                addCheckboxField(node, 'enabled', 'Aktiv', cfg.enabled !== false);
                break;

            default:
                addLabelField('No configurable properties.');
                break;
        }
    }

    function buildEventTypeSelect(node) {
        const wrap = document.createElement('div');
        wrap.style.cssText = 'display:flex;flex-direction:column;gap:2px;max-height:280px;overflow-y:auto;';

        const search = document.createElement('input');
        search.type = 'search';
        search.className = 'cc-input';
        search.placeholder = 'Search event types…';
        search.style.marginBottom = '6px';
        wrap.appendChild(search);

        const currentType = (node.config?.event_type || '').toLowerCase();

        const listWrap = document.createElement('div');
        listWrap.style.cssText = 'display:flex;flex-direction:column;gap:1px;';

        Object.entries(EVENT_TYPES).forEach(([category, types]) => {
            const grp = document.createElement('div');

            const grpLabel = document.createElement('div');
            grpLabel.style.cssText = 'font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#4f5f80;padding:6px 4px 2px;';
            grpLabel.textContent = category;
            grp.appendChild(grpLabel);

            types.forEach((et) => {
                const opt = document.createElement('div');
                opt.style.cssText = 'padding:5px 8px;border-radius:4px;font-size:12px;cursor:pointer;transition:background .1s;';
                opt.textContent = EVENT_LABELS[et] || et;
                opt.setAttribute('data-et', et);
                if (et === currentType) {
                    opt.style.background = 'rgba(79,140,255,.2)';
                    opt.style.color = '#7eb3ff';
                }
                opt.addEventListener('click', () => {
                    setEventType(node, et);
                    // Update highlight
                    listWrap.querySelectorAll('[data-et]').forEach((el) => {
                        el.style.background = '';
                        el.style.color = '';
                    });
                    opt.style.background = 'rgba(79,140,255,.2)';
                    opt.style.color = '#7eb3ff';
                });
                opt.addEventListener('mouseenter', () => {
                    if (opt.getAttribute('data-et') !== node.config?.event_type) opt.style.background = 'rgba(79,140,255,.08)';
                });
                opt.addEventListener('mouseleave', () => {
                    if (opt.getAttribute('data-et') !== node.config?.event_type) opt.style.background = '';
                });
                grp.appendChild(opt);
            });

            listWrap.appendChild(grp);
        });

        search.addEventListener('input', () => {
            const q = search.value.trim().toLowerCase();
            listWrap.querySelectorAll('[data-et]').forEach((el) => {
                const et = el.getAttribute('data-et') || '';
                const label = (EVENT_LABELS[et] || et).toLowerCase();
                el.style.display = (!q || et.includes(q) || label.includes(q)) ? '' : 'none';
            });
        });

        wrap.appendChild(listWrap);
        return wrap;
    }

    function setEventType(node, et) {
        node.config = node.config || {};
        node.config.event_type = et;
        if (hiddenType) hiddenType.value = et;
        if (eventTypeDisplay) eventTypeDisplay.textContent = EVENT_LABELS[et] || et;
        updateVariablesList(et);
        renderNodes();
        markDirty();
        writeJsonField();
    }

    function updateVariablesList(eventType) {
        if (!variablesList) return;
        const cat = eventType ? eventType.split('.')[0] : '';
        const vars = (cat && EVENT_CONTEXT_VARS[cat]) ? EVENT_CONTEXT_VARS[cat] : [];
        if (vars.length === 0) {
            variablesList.innerHTML = '<div style="padding:4px;color:var(--cc-text-soft)">No event type selected.</div>';
            return;
        }
        variablesList.innerHTML = '<div style="padding:4px 0 8px;font-size:11px;color:var(--cc-text-soft)">Available for <strong style="color:#7eb3ff">' +
            (EVENT_LABELS[eventType] || eventType) + '</strong>:</div>' +
            vars.map((v) => `<div style="padding:3px 6px;font-size:12px;font-family:monospace;background:rgba(79,140,255,.08);border-radius:4px;margin-bottom:3px;cursor:pointer;color:#a5c0f8" onclick="navigator.clipboard.writeText('${v}').catch(()=>{})" title="Click to copy">${v}</div>`).join('');
    }

    // ── Property field helpers ────────────────────────────────────────────────

    /** Make a cc-prop-row wrapper with a label + optional help text. */
    function makePropRow(labelText, helpText) {
        const wrap = document.createElement('div');
        wrap.className = 'cc-prop-row';
        const lbl = document.createElement('label');
        lbl.textContent = labelText;
        if (helpText) {
            const helpEl = document.createElement('span');
            helpEl.className = 'cc-prop-help';
            helpEl.textContent = helpText;
            lbl.appendChild(document.createElement('br'));
            lbl.appendChild(helpEl);
        }
        wrap.appendChild(lbl);
        return wrap;
    }

    function addField(node, label, contentEl, helpText) {
        const wrap = makePropRow(label, helpText);
        wrap.appendChild(contentEl);
        dynamicFields.appendChild(wrap);
    }

    function addTextField(node, key, label, value, isTextarea, helpText) {
        const wrap = makePropRow(label, helpText);
        const input = isTextarea ? document.createElement('textarea') : document.createElement('input');
        if (!isTextarea) input.type = 'text';
        input.value = value;
        input.addEventListener('input', () => {
            node.config = node.config || {};
            node.config[key] = input.value;
            markDirty();
            writeJsonField();
        });
        wrap.appendChild(input);
        dynamicFields.appendChild(wrap);
    }

    function addSelectField(node, key, label, value, options, helpText) {
        const wrap = makePropRow(label, helpText);
        const sel = document.createElement('select');
        options.forEach(([v, l]) => {
            const opt = document.createElement('option');
            opt.value = v; opt.textContent = l;
            if (v === value) opt.selected = true;
            sel.appendChild(opt);
        });
        sel.addEventListener('change', () => {
            node.config = node.config || {};
            node.config[key] = sel.value;
            markDirty();
            writeJsonField();
        });
        wrap.appendChild(sel);
        dynamicFields.appendChild(wrap);
    }

    /** Like addSelectField but re-renders the whole property panel on change (for conditional fields). */
    function addSelectFieldWithRerender(node, key, label, value, options, helpText) {
        const wrap = makePropRow(label, helpText);
        const sel = document.createElement('select');
        options.forEach(([v, l]) => {
            const opt = document.createElement('option');
            opt.value = v; opt.textContent = l;
            if (v === value) opt.selected = true;
            sel.appendChild(opt);
        });
        sel.addEventListener('change', () => {
            node.config = node.config || {};
            node.config[key] = sel.value;
            markDirty();
            writeJsonField();
            showProperties(node.id);
        });
        wrap.appendChild(sel);
        dynamicFields.appendChild(wrap);
    }

    /** Toggle switch (matches CC Builder cc-switch style). */
    function addSwitchField(node, key, label, checked, helpText) {
        const wrap = makePropRow(label, helpText);
        const switchWrap = document.createElement('label');
        switchWrap.className = 'cc-switch';
        const input = document.createElement('input');
        input.type = 'checkbox';
        input.checked = checked;
        const slider = document.createElement('span');
        slider.className = 'cc-slider';
        switchWrap.appendChild(input);
        switchWrap.appendChild(slider);
        input.addEventListener('change', () => {
            node.config = node.config || {};
            node.config[key] = input.checked;
            markDirty();
            writeJsonField();
        });
        wrap.appendChild(switchWrap);
        dynamicFields.appendChild(wrap);
    }

    /** Backwards-compat alias for addSwitchField. */
    function addCheckboxField(node, key, label, checked, helpText) {
        addSwitchField(node, key, label, checked, helpText);
    }

    /** Permission dropdown + chips — replaces old cc-perm-grid. */
    function addPermissionsField(node, key, label, helpText) {
        const PERMS = [
            { key: 'Administrator',           label: 'Administrator' },
            { key: 'ManageGuild',             label: 'Server verwalten' },
            { key: 'ManageRoles',             label: 'Rollen verwalten' },
            { key: 'ManageChannels',          label: 'Kanäle verwalten' },
            { key: 'KickMembers',             label: 'Mitglieder kicken' },
            { key: 'BanMembers',              label: 'Mitglieder bannen' },
            { key: 'ModerateMembers',         label: 'Mitglieder timen out' },
            { key: 'ManageMessages',          label: 'Nachrichten verwalten' },
            { key: 'ManageNicknames',         label: 'Spitznamen verwalten' },
            { key: 'ManageWebhooks',          label: 'Webhooks verwalten' },
            { key: 'ManageEmojisAndStickers', label: 'Emojis & Sticker verwalten' },
            { key: 'ViewAuditLog',            label: 'Audit-Log ansehen' },
            { key: 'MentionEveryone',         label: '@everyone erwähnen' },
            { key: 'MoveMembers',             label: 'Mitglieder verschieben' },
            { key: 'MuteMembers',             label: 'Mitglieder stummschalten' },
            { key: 'DeafenMembers',           label: 'Mitglieder taubschalten' },
        ];

        node.config = node.config || {};
        if (!Array.isArray(node.config[key])) node.config[key] = [];

        const wrap = makePropRow(label, helpText);

        const chipsWrap = document.createElement('div');
        chipsWrap.className = 'cc-perm-chips';

        const addSel = document.createElement('select');
        addSel.className = 'cc-prop-select';
        addSel.style.marginTop = '6px';

        function renderChips() {
            chipsWrap.innerHTML = '';
            const current = node.config[key];
            if (current.length === 0) {
                const empty = document.createElement('span');
                empty.className = 'cc-perm-chips-empty';
                empty.textContent = 'Keine Berechtigung ausgewählt';
                chipsWrap.appendChild(empty);
                return;
            }
            current.forEach((permKey) => {
                const meta = PERMS.find(p => p.key === permKey);
                const chip = document.createElement('span');
                chip.className = 'cc-perm-chip';
                chip.textContent = meta ? meta.label : permKey;
                const rm = document.createElement('button');
                rm.type = 'button';
                rm.className = 'cc-perm-chip-rm';
                rm.textContent = '×';
                rm.addEventListener('click', () => {
                    node.config[key] = node.config[key].filter(p => p !== permKey);
                    markDirty(); writeJsonField();
                    renderChips(); updateDropdown();
                });
                chip.appendChild(rm);
                chipsWrap.appendChild(chip);
            });
        }

        function updateDropdown() {
            const current = node.config[key];
            addSel.innerHTML = '';
            const ph = document.createElement('option');
            ph.value = '';
            ph.textContent = '+ Berechtigung hinzufügen…';
            addSel.appendChild(ph);
            PERMS.forEach(({ key: permKey, label: permLabel }) => {
                if (current.includes(permKey)) return;
                const opt = document.createElement('option');
                opt.value = permKey;
                opt.textContent = permLabel;
                addSel.appendChild(opt);
            });
        }

        addSel.addEventListener('change', () => {
            const val = addSel.value;
            if (!val) return;
            if (!node.config[key].includes(val)) node.config[key].push(val);
            markDirty(); writeJsonField();
            renderChips(); updateDropdown();
            addSel.value = '';
        });

        renderChips();
        updateDropdown();
        wrap.appendChild(chipsWrap);
        wrap.appendChild(addSel);
        dynamicFields.appendChild(wrap);
    }

    function addLabelField(text) {
        const el = document.createElement('div');
        el.className = 'cc-prop-row';
        el.style.color = 'var(--cc-text-soft)';
        el.textContent = text;
        dynamicFields.appendChild(el);
    }

    /** Message Builder open button — same style as CC Builder. */
    function addMsgBuilderBtn(node) {
        const wrap = document.createElement('div');
        wrap.className = 'cc-prop-row';
        const hasContent = !!(node.config?.message_content || (Array.isArray(node.config?.embeds) && node.config.embeds.length > 0));
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'cc-mb-open-btn' + (hasContent ? ' cc-mb-open-btn--filled' : '');
        btn.textContent = hasContent ? '✎ Nachricht bearbeiten' : '+ Nachricht erstellen';
        btn.addEventListener('click', () => {
            if (window.CebMsgBuilder) window.CebMsgBuilder.open(node);
        });
        wrap.appendChild(btn);
        if (hasContent) {
            const hint = document.createElement('div');
            hint.className = 'cc-mb-status-hint';
            const parts = [];
            if (node.config.message_content) parts.push(node.config.message_content.length + ' Zeichen Text');
            const ec = Array.isArray(node.config.embeds) ? node.config.embeds.length : 0;
            if (ec > 0) parts.push(ec + ' Embed' + (ec !== 1 ? 's' : ''));
            hint.textContent = parts.join(' · ');
            wrap.appendChild(hint);
        }
        dynamicFields.appendChild(wrap);
    }

    // ── Block list (static PHP items) ─────────────────────────────────────────
    function registerStaticBlockItems() {
        document.querySelectorAll('[data-block-type][draggable]').forEach((item) => {
            const type = item.getAttribute('data-block-type');
            if (!type) return;
            const def = getDef(type);

            item.addEventListener('dragstart', (evt) => {
                evt.dataTransfer.setData('text/plain', JSON.stringify({ type, label: def.label }));
            });

            item.addEventListener('click', () => {
                const cp = getCanvasCenterWorldPoint();
                addNodeFromType(type, def.label, cp.x - 100, cp.y - 40);
            });
        });

        // Search — filters across all static items in every tab panel
        if (blockSearch) {
            blockSearch.addEventListener('input', () => {
                const q = blockSearch.value.trim().toLowerCase();
                document.querySelectorAll('[data-block-type][draggable]').forEach((item) => {
                    const t = (item.textContent || '').toLowerCase();
                    item.style.display = (!q || t.includes(q)) ? '' : 'none';
                });
            });
        }
    }

    function addNodeFromType(type, label, wx, wy) {
        const def = getDef(type);
        const node = {
            id: type + '_' + Date.now() + '_' + Math.random().toString(36).slice(2, 6),
            type, label: label || def.label,
            x: Math.round(wx), y: Math.round(wy),
            config: Object.assign({}, def.defaults),
        };
        state.builder.nodes.push(node);
        selectNode(node.id, false);
        markDirty();
        renderAll();
        writeJsonField();
    }

    // ── Rail / Tabs ───────────────────────────────────────────────────────────
    function registerRail() {
        document.querySelectorAll('[data-rail-panel]').forEach((btn) => {
            btn.addEventListener('click', () => {
                state.activeRailPanel = btn.getAttribute('data-rail-panel') || 'blocks';
                document.querySelectorAll('[data-rail-panel]').forEach((b) => b.classList.toggle('is-active', b === btn));
                document.querySelectorAll('.cc-panel-section').forEach((s) => s.classList.toggle('is-active', s.getAttribute('data-rail-content') === state.activeRailPanel));
            });
        });
    }

    function registerTabs() {
        document.querySelectorAll('[data-block-tab]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const tab = btn.getAttribute('data-block-tab') || 'actions';
                state.activeBlockTab = tab;
                document.querySelectorAll('[data-block-tab]').forEach((b) => b.classList.toggle('is-active', b === btn));
                document.querySelectorAll('[data-block-tab-content]').forEach((p) => {
                    p.classList.toggle('is-active', p.getAttribute('data-block-tab-content') === tab);
                });
            });
        });
    }

    // ── Toolbar ───────────────────────────────────────────────────────────────
    function registerToolbar() {
        if (centerBtn)    centerBtn.addEventListener('click', () => { centerOnNodes(); });
        if (clearSelBtn)  clearSelBtn.addEventListener('click', () => { clearSelection(); renderAll(); });
        if (zoomInBtn)    zoomInBtn.addEventListener('click', () => setZoom(state.viewport.zoom + 0.1));
        if (zoomOutBtn)   zoomOutBtn.addEventListener('click', () => setZoom(state.viewport.zoom - 0.1));
        if (zoomResetBtn) zoomResetBtn.addEventListener('click', () => setZoom(1));
        if (deleteNodeBtn) deleteNodeBtn.addEventListener('click', deleteSelectedNodes);
    }

    // ── Pan & Zoom ────────────────────────────────────────────────────────────
    function registerPanAndZoom() {
        if (!canvas) return;

        canvas.addEventListener('dragover', (evt) => evt.preventDefault());
        canvas.addEventListener('drop', (evt) => {
            evt.preventDefault();
            let payload = null;
            try { payload = JSON.parse(evt.dataTransfer.getData('text/plain')); } catch (_) {}
            if (!payload || typeof payload.type !== 'string' || payload.type === '') return;
            const pt = getWorldPoint(evt.clientX, evt.clientY);
            addNodeFromType(payload.type, payload.label || '', pt.x - 100, pt.y - 40);
        });

        edgesSvg.addEventListener('click', (evt) => {
            const target = evt.target;
            if (!(target instanceof Element)) return;
            const delBtn = target.closest('.cc-edge-delete-btn, .cc-edge-delete-circle, .cc-edge-delete-text');
            if (!delBtn) return;
            evt.preventDefault(); evt.stopPropagation();
            const edgeId = delBtn.getAttribute('data-edge-id')
                || (delBtn.closest('.cc-edge-group') instanceof Element ? delBtn.closest('.cc-edge-group').getAttribute('data-edge-id') : '');
            if (edgeId) deleteEdge(edgeId);
        }, true);

        canvas.addEventListener('mousedown', (evt) => {
            if (evt.button !== 0) return;
            const target = evt.target;
            const clickedNode  = target instanceof Element ? target.closest('.cc-node') : null;
            const clickedPort  = target instanceof Element ? target.closest('.cc-port') : null;
            const clickedEdgeDel = target instanceof Element ? target.closest('.cc-edge-delete-btn,.cc-edge-delete-circle,.cc-edge-delete-text') : null;
            if (clickedNode || clickedPort || clickedEdgeDel) return;

            if (state.selectedNodeIds.length > 0) { clearSelection(); renderAll(); }

            if (evt.shiftKey) {
                state.isSelectingArea = true;
                const wp = getWorldPoint(evt.clientX, evt.clientY);
                state.selectionStartWorldX = wp.x; state.selectionStartWorldY = wp.y;
                state.selectionCurrentWorldX = wp.x; state.selectionCurrentWorldY = wp.y;
                ensureSelectionBox(); updateSelectionBox();
                return;
            }

            state.isPanning = true;
            state.panStartX = evt.clientX; state.panStartY = evt.clientY;
            state.panOriginX = state.viewport.x; state.panOriginY = state.viewport.y;
            canvas.classList.add('is-panning');
        });

        canvas.addEventListener('wheel', (evt) => {
            evt.preventDefault();
            setZoom(state.viewport.zoom + (evt.deltaY < 0 ? 0.08 : -0.08), evt.clientX, evt.clientY);
        }, { passive: false });

        document.addEventListener('mousemove', (evt) => {
            if (state.isSelectingArea) {
                const wp = getWorldPoint(evt.clientX, evt.clientY);
                state.selectionCurrentWorldX = wp.x; state.selectionCurrentWorldY = wp.y;
                updateSelectionBox(); return;
            }

            if (state.isPanning) {
                state.viewport.x = state.panOriginX + (evt.clientX - state.panStartX);
                state.viewport.y = state.panOriginY + (evt.clientY - state.panStartY);
                applyViewport(); writeJsonField(); return;
            }

            if (state.pendingConnection?.isDragging) {
                const wp = getWorldPoint(evt.clientX, evt.clientY);
                state.pendingConnection.mouseX = wp.x;
                state.pendingConnection.mouseY = wp.y;
                renderEdges(); return;
            }

            if (!state.dragNodeId) return;
            const wp = getWorldPoint(evt.clientX, evt.clientY);
            const ox = wp.x - state.dragStartWorldX;
            const oy = wp.y - state.dragStartWorldY;
            state.dragSelectionSnapshot.forEach((snap) => {
                const n = findNodeById(snap.id);
                if (n) { n.x = snap.x + ox; n.y = snap.y + oy; }
            });

            if (!state.dragRafPending) {
                state.dragRafPending = true;
                requestAnimationFrame(() => {
                    state.dragRafPending = false;
                    state.dragSelectionSnapshot.forEach((snap) => {
                        const n = findNodeById(snap.id);
                        if (!n) return;
                        const el = canvasInner.querySelector('[data-node-id="' + n.id + '"]');
                        if (el instanceof HTMLElement) { el.style.left = n.x + 'px'; el.style.top = n.y + 'px'; }
                    });
                    renderEdges();
                });
            }
            markDirty(false);
        });

        document.addEventListener('mouseup', (evt) => {
            if (state.isSelectingArea) { finishAreaSelection(); return; }

            if (state.isPanning) {
                state.isPanning = false;
                canvas.classList.remove('is-panning');
                markDirty(); return;
            }

            if (state.pendingConnection?.isDragging) {
                const hit = document.elementFromPoint(evt.clientX, evt.clientY);
                if (state.pendingConnection.startedFromInput) {
                    const targetPort = hit instanceof HTMLElement ? hit.closest('.cc-port--output') : null;
                    if (targetPort instanceof HTMLElement) {
                        const fromNodeId = targetPort.getAttribute('data-node-id') || '';
                        const fromPort   = targetPort.getAttribute('data-port-name') || '';
                        const toNodeId   = state.pendingConnection.toNodeId;
                        const toPort     = state.pendingConnection.toPort;
                        state.pendingConnection = { isDragging: true, fromNodeId, fromPort, startedFromInput: false };
                        connectPendingTo(toNodeId, toPort);
                    } else {
                        state.pendingConnection = null;
                        renderEdges();
                    }
                } else {
                    const targetPort = hit instanceof HTMLElement ? hit.closest('.cc-port--input') : null;
                    if (targetPort instanceof HTMLElement) {
                        connectPendingTo(
                            targetPort.getAttribute('data-node-id') || '',
                            targetPort.getAttribute('data-port-name') || '',
                        );
                    } else {
                        state.pendingConnection = null;
                        renderEdges();
                    }
                }
                return;
            }

            if (state.dragNodeId) {
                state.dragSelectionSnapshot.forEach((snap) => {
                    const n = findNodeById(snap.id);
                    if (n) { n.x = Math.round(n.x); n.y = Math.round(n.y); }
                });
                state.dragNodeId = null;
                state.dragRafPending = false;
                state.dragSelectionSnapshot = [];
                markDirty(); renderNodes(); renderEdges(); writeJsonField();
            }
        });
    }

    // ── Keyboard ──────────────────────────────────────────────────────────────
    function registerKeyboard() {
        document.addEventListener('keydown', (evt) => {
            const tag = (evt.target instanceof HTMLElement ? evt.target.tagName : '').toLowerCase();
            if (tag === 'input' || tag === 'textarea' || tag === 'select') return;
            const ctrl = evt.ctrlKey || evt.metaKey;
            if (ctrl && evt.key.toLowerCase() === 'c') { copySelectedNodes(); return; }
            if (ctrl && evt.key.toLowerCase() === 'v') { pasteClipboardNodes(); return; }
            if ((evt.key === 'Delete' || evt.key === 'Del') && state.selectedNodeIds.length > 0) {
                evt.preventDefault(); deleteSelectedNodes();
            }
            if (evt.key === 'Escape') { clearSelection(); renderAll(); }
        });
    }

    // ── Meta inputs ───────────────────────────────────────────────────────────
    function registerMetaInputs() {
        if (metaNameInput) {
            metaNameInput.addEventListener('input', () => {
                if (hiddenName) hiddenName.value = metaNameInput.value;
                markDirty();
            });
        }
        if (metaDescInput) {
            metaDescInput.addEventListener('input', () => {
                if (hiddenDesc) hiddenDesc.value = metaDescInput.value;
                markDirty();
            });
        }
    }

    // ── Form submit ───────────────────────────────────────────────────────────
    function registerFormSubmit() {
        if (!form) return;
        form.addEventListener('submit', async (evt) => {
            evt.preventDefault();
            writeJsonField();

            // Sync meta from nodes
            const triggerNode = state.builder.nodes.find((n) => n.type === 'trigger.event');
            if (triggerNode && hiddenType) {
                hiddenType.value = triggerNode.config?.event_type || '';
            }
            if (metaNameInput && hiddenName) hiddenName.value = metaNameInput.value;
            if (metaDescInput && hiddenDesc) hiddenDesc.value = metaDescInput.value;

            // Validate
            if (!hiddenName?.value.trim()) {
                showSaveStatus('⚠ Event name required', 'error'); return;
            }
            if (!hiddenType?.value.trim()) {
                showSaveStatus('⚠ Event type required — set it in the trigger node', 'error'); return;
            }

            const fd = new FormData(form);
            showSaveStatus('Saving…', '');

            try {
                const resp = await fetch(form.action, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                    body: fd,
                });
                const data = await resp.json();
                if (data.ok) {
                    state.dirty = false;
                    showSaveStatus('Saved ✓', 'ok');
                    if (data.event_id && data.event_id !== META.eventId && META.eventId === 0) {
                        const newUrl = new URL(window.location.href);
                        newUrl.searchParams.set('event_id', String(data.event_id));
                        history.replaceState({}, '', newUrl.toString());
                        form.action = '/dashboard/custom-events/builder?event_id=' + data.event_id;
                        META.eventId = data.event_id;
                    }
                } else {
                    showSaveStatus('Error: ' + (data.message || 'Unknown error'), 'error');
                    logError(data.message || 'Save failed');
                }
            } catch (err) {
                showSaveStatus('Network error', 'error');
                logError(String(err));
            }
        });
    }

    function showSaveStatus(text, type) {
        if (!saveStatus) return;
        saveStatus.textContent = text;
        saveStatus.style.color = type === 'ok' ? '#30c968' : type === 'error' ? '#ef5350' : '#aab3c8';
    }

    function logError(msg) {
        if (!errorLog) return;
        const entry = document.createElement('div');
        entry.style.cssText = 'padding:4px 0;border-bottom:1px solid #2c3340;font-size:11px;color:#ef5350;';
        entry.textContent = '[' + new Date().toLocaleTimeString() + '] ' + msg;
        if (errorLog.firstChild) {
            errorLog.insertBefore(entry, errorLog.firstChild);
        } else {
            errorLog.appendChild(entry);
        }
        errorLog.innerHTML = errorLog.innerHTML.replace('No errors.', '');
    }

    // ── Viewport helpers ──────────────────────────────────────────────────────
    function applyViewport() {
        if (world) {
            world.style.transformOrigin = '0 0';
            world.style.transform = `translate(${state.viewport.x}px, ${state.viewport.y}px) scale(${state.viewport.zoom})`;
        }
        if (zoomResetBtn) zoomResetBtn.textContent = Math.round(state.viewport.zoom * 100) + '%';
    }

    function setZoom(zoom, pivotClientX, pivotClientY) {
        const oldZoom = state.viewport.zoom;
        const newZoom = clampZoom(zoom);
        if (newZoom === oldZoom) return;

        if (pivotClientX !== undefined && canvas) {
            const rect = canvas.getBoundingClientRect();
            const px = pivotClientX - rect.left;
            const py = pivotClientY - rect.top;
            state.viewport.x = px - (px - state.viewport.x) * (newZoom / oldZoom);
            state.viewport.y = py - (py - state.viewport.y) * (newZoom / oldZoom);
        }

        state.viewport.zoom = newZoom;
        applyViewport();
        writeJsonField();
    }

    function centerOnNodes() {
        if (!canvas || state.builder.nodes.length === 0) return;
        const padding = 60;
        const xs = state.builder.nodes.map((n) => n.x);
        const ys = state.builder.nodes.map((n) => n.y);
        const minX = Math.min(...xs) - padding;
        const minY = Math.min(...ys) - padding;
        const maxX = Math.max(...xs) + 220 + padding;
        const maxY = Math.max(...ys) + 100 + padding;
        const rect  = canvas.getBoundingClientRect();
        const scaleX = rect.width  / (maxX - minX);
        const scaleY = rect.height / (maxY - minY);
        const zoom   = clampZoom(Math.min(scaleX, scaleY, 1.2));
        state.viewport.zoom = zoom;
        state.viewport.x = (rect.width  - (minX + maxX) * zoom) / 2;
        state.viewport.y = (rect.height - (minY + maxY) * zoom) / 2;
        applyViewport();
        writeJsonField();
    }

    function getCanvasCenterWorldPoint() {
        if (!canvas) return { x: 400, y: 300 };
        const rect = canvas.getBoundingClientRect();
        return getWorldPoint(rect.left + rect.width / 2, rect.top + rect.height / 2);
    }

    function getWorldPoint(clientX, clientY) {
        if (!canvas) return { x: clientX, y: clientY };
        const rect = canvas.getBoundingClientRect();
        const lx = clientX - rect.left;
        const ly = clientY - rect.top;
        return {
            x: (lx - state.viewport.x) / state.viewport.zoom,
            y: (ly - state.viewport.y) / state.viewport.zoom,
        };
    }

    // ── Write JSON field ──────────────────────────────────────────────────────
    function writeJsonField() {
        if (!builderJsonField) return;
        state.builder.viewport = { x: state.viewport.x, y: state.viewport.y, zoom: state.viewport.zoom };
        try {
            builderJsonField.value = JSON.stringify(state.builder);
        } catch (_) {
            builderJsonField.value = '';
        }
    }

    function markDirty(updateField) {
        state.dirty = true;
        if (updateField !== false) writeJsonField();
        if (saveStatus && !saveStatus.textContent.startsWith('Saving')) {
            showSaveStatus('Unsaved changes', '');
        }
    }

    // ── Utils ─────────────────────────────────────────────────────────────────
    function findNodeById(id) {
        return state.builder.nodes.find((n) => n.id === id) || null;
    }

    function buildEdgeId(fNode, fPort, tNode, tPort) {
        return `edge_${fNode}_${fPort}_${tNode}_${tPort}`;
    }


    function clampZoom(z) {
        return Math.max(0.2, Math.min(2.5, z));
    }

    function toNumber(v, fallback) {
        const n = parseFloat(v);
        return isNaN(n) ? fallback : n;
    }

    function toInt(v, fallback) {
        const n = parseInt(v, 10);
        return isNaN(n) ? fallback : n;
    }

    function isObject(v) {
        return v !== null && typeof v === 'object' && !Array.isArray(v);
    }

    function hexToRgba(hex, alpha) {
        const r = parseInt(hex.slice(1, 3), 16);
        const g = parseInt(hex.slice(3, 5), 16);
        const b = parseInt(hex.slice(5, 7), 16);
        return `rgba(${r},${g},${b},${alpha})`;
    }

    // Expose hooks for Message Builder modal
    window._cebBuilderMarkDirty   = () => markDirty();
    window._cebBuilderWriteJson   = () => writeJsonField();
    window._cebBuilderRenderNodes = () => renderNodes();
    window._cebShowProperties     = (node) => { state.selectedNodeId = node.id; showProperties(node.id); };
    window.__cebBuilderState      = state;
})();

// ══════════════════════════════════════════════════════════ Message Builder
(function () {
    'use strict';

    const overlay        = document.getElementById('ceb-msg-builder-overlay');
    const saveBtn        = document.getElementById('ceb-mb-save-btn');
    const closeBtn       = document.getElementById('ceb-mb-close-btn');
    const contentTA      = document.getElementById('ceb-mb-content');
    const countEl        = document.getElementById('ceb-mb-content-count');
    const embedsCountEl  = document.getElementById('ceb-mb-embeds-count');
    const embedsList     = document.getElementById('ceb-mb-embeds-list');
    const addEmbedBtn    = document.getElementById('ceb-mb-add-embed-btn');
    const clearEmbedsBtn = document.getElementById('ceb-mb-clear-embeds-btn');
    const previewText    = document.getElementById('ceb-mb-preview-text');
    const previewEmbeds  = document.getElementById('ceb-mb-preview-embeds');
    const embedTpl       = document.getElementById('ceb-mb-embed-tpl');
    const fieldTpl       = document.getElementById('ceb-mb-field-tpl');
    const previewTimeEl  = document.getElementById('ceb-mb-preview-time');
    const botNameEl      = document.getElementById('ceb-mb-bot-name');
    const botAvatarImg   = document.getElementById('ceb-mb-bot-avatar-img');
    const botAvatarFb    = document.getElementById('ceb-mb-bot-avatar-fallback');
    const responseTypeSel    = document.getElementById('ceb-mb-response-type');
    const condSpecificChannel = document.getElementById('ceb-mb-cond-specific-channel');
    const condDmSpecificUser  = document.getElementById('ceb-mb-cond-dm-specific-user');
    const condEditAction      = document.getElementById('ceb-mb-cond-edit-action');
    const inputChannelId      = document.getElementById('ceb-mb-target-channel-id');
    const inputUserId         = document.getElementById('ceb-mb-target-user-id');
    const inputEditTargetVar  = document.getElementById('ceb-mb-edit-target-var');

    if (!overlay || !embedTpl || !fieldTpl) return;

    let currentNode = null;

    // ── bot meta ──────────────────────────────────────────────────────────────
    (function initBotMeta() {
        const meta = window.CebMeta || {};
        const name = meta.botName || 'Bot';
        const userId = String(meta.botId || '');
        if (botNameEl) botNameEl.textContent = name;
        if (botAvatarFb) botAvatarFb.textContent = name.charAt(0).toUpperCase();
        if (userId && botAvatarImg) {
            let idx = 0;
            try { idx = Number(BigInt(userId) >> 22n) % 5; } catch (_) { idx = parseInt(userId, 10) % 5 || 0; }
            botAvatarImg.src = 'https://cdn.discordapp.com/embed/avatars/' + idx + '.png';
            botAvatarImg.style.display = 'block';
            if (botAvatarFb) botAvatarFb.style.display = 'none';
            botAvatarImg.onerror = () => {
                botAvatarImg.style.display = 'none';
                if (botAvatarFb) botAvatarFb.style.display = 'flex';
            };
        }
    })();

    // ── helpers ───────────────────────────────────────────────────────────────
    function esc(v) {
        return String(v || '').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;');
    }

    function now() {
        const d = new Date();
        const h = d.getHours(), m = d.getMinutes();
        return 'Today at ' + ((h % 12) || 12) + ':' + String(m).padStart(2,'0') + (h >= 12 ? ' PM' : ' AM');
    }

    function defaultEmbed() {
        return { color: '#5865F2', author_name: '', author_icon_url: '', title: '', url: '', description: '',
                 thumbnail_url: '', image_url: '', fields: [], footer_text: '', timestamp: false };
    }

    function readEmbeds() {
        if (!embedsList) return [];
        return Array.from(embedsList.querySelectorAll('.cc-mb-embed-item')).map((item) => {
            const embed = defaultEmbed();
            const cp = item.querySelector('.cc-mb-color-picker');
            if (cp) embed.color = cp.value;
            item.querySelectorAll('[data-field]').forEach((el) => {
                embed[el.dataset.field] = el.type === 'checkbox' ? el.checked : el.value;
            });
            embed.fields = Array.from(item.querySelectorAll('.cc-mb-subfield-item')).map((sub) => {
                const f = { name: '', value: '', inline: false };
                sub.querySelectorAll('[data-subfield]').forEach((el) => {
                    f[el.dataset.subfield] = el.type === 'checkbox' ? el.checked : el.value;
                });
                return f;
            });
            return embed;
        });
    }

    // ── preview ───────────────────────────────────────────────────────────────
    function renderPreview() {
        if (previewTimeEl) previewTimeEl.textContent = now();
        const text = contentTA ? contentTA.value : '';
        if (previewText) previewText.innerHTML = text ? esc(text).replace(/\n/g,'<br>') : '';
        if (!previewEmbeds) return;
        previewEmbeds.innerHTML = '';
        readEmbeds().forEach((embed) => {
            const colorHex = embed.color || '#5865F2';
            let html = '<div class="cc-mb-discord-embed" style="border-left-color:' + esc(colorHex) + '">';
            if (embed.author_name) {
                html += '<div class="cc-mb-discord-embed-author">';
                if (embed.author_icon_url) html += '<img src="' + esc(embed.author_icon_url) + '" class="cc-mb-discord-embed-author-icon" alt="">';
                html += esc(embed.author_name) + '</div>';
            }
            if (embed.title) {
                const titleTag = embed.url
                    ? '<a href="' + esc(embed.url) + '" class="cc-mb-discord-embed-title-link">' + esc(embed.title) + '</a>'
                    : esc(embed.title);
                html += '<div class="cc-mb-discord-embed-title">' + titleTag + '</div>';
            }
            if (embed.description) html += '<div class="cc-mb-discord-embed-desc">' + esc(embed.description).replace(/\n/g,'<br>') + '</div>';
            if (embed.fields && embed.fields.length > 0) {
                html += '<div class="cc-mb-discord-embed-fields">';
                embed.fields.forEach((f) => {
                    html += '<div class="cc-mb-discord-embed-field' + (f.inline ? ' cc-mb-discord-embed-field--inline' : '') + '">'
                        + '<div class="cc-mb-discord-embed-field-name">' + esc(f.name) + '</div>'
                        + '<div class="cc-mb-discord-embed-field-value">' + esc(f.value) + '</div>'
                        + '</div>';
                });
                html += '</div>';
            }
            if (embed.image_url)     html += '<img src="' + esc(embed.image_url)     + '" class="cc-mb-discord-embed-image" alt="">';
            if (embed.thumbnail_url) html += '<img src="' + esc(embed.thumbnail_url) + '" class="cc-mb-discord-embed-thumbnail" alt="">';
            if (embed.footer_text || embed.timestamp) {
                html += '<div class="cc-mb-discord-embed-footer">';
                if (embed.footer_text) html += '<span>' + esc(embed.footer_text) + '</span>';
                if (embed.timestamp)   html += '<span>' + now() + '</span>';
                html += '</div>';
            }
            html += '</div>';
            previewEmbeds.insertAdjacentHTML('beforeend', html);
        });
    }

    // ── embed DOM items ───────────────────────────────────────────────────────
    function buildEmbedItem(embed, idx) {
        const clone = embedTpl.content.cloneNode(true);
        const item  = clone.querySelector('.cc-mb-embed-item');
        item.dataset.embedIdx = idx;
        const numEl = item.querySelector('.cc-mb-embed-num');
        if (numEl) numEl.textContent = idx + 1;

        const cp = item.querySelector('.cc-mb-color-picker');
        if (cp) { cp.value = embed.color || '#5865F2'; cp.addEventListener('input', updateCountsAndPreview); }

        item.querySelectorAll('[data-field]').forEach((el) => {
            const key = el.dataset.field;
            if (el.type === 'checkbox') { el.checked = !!embed[key]; } else { el.value = embed[key] != null ? embed[key] : ''; }
            el.addEventListener('input', updateCountsAndPreview);
            el.addEventListener('change', updateCountsAndPreview);
        });

        const delBtn = item.querySelector('.cc-mb-embed-del-btn');
        if (delBtn) delBtn.addEventListener('click', () => { item.remove(); updateCountsAndPreview(); });

        const addFieldBtn  = item.querySelector('.cc-mb-add-field-btn');
        const subList      = item.querySelector('.cc-mb-subfields-list');
        const fieldsCountEl = item.querySelector('.cc-mb-fields-count');

        function updateFieldsCount() {
            if (fieldsCountEl) fieldsCountEl.textContent = subList ? subList.querySelectorAll('.cc-mb-subfield-item').length : 0;
        }

        function addFieldItem(f) {
            const fc = fieldTpl.content.cloneNode(true);
            const fi = fc.querySelector('.cc-mb-subfield-item');
            if (f) {
                fi.querySelectorAll('[data-subfield]').forEach((el) => {
                    const k = el.dataset.subfield;
                    if (el.type === 'checkbox') { el.checked = !!f[k]; } else { el.value = f[k] != null ? f[k] : ''; }
                });
            }
            fi.querySelectorAll('[data-subfield]').forEach((el) => {
                el.addEventListener('input', updateCountsAndPreview);
                el.addEventListener('change', updateCountsAndPreview);
            });
            const delF = fi.querySelector('.cc-mb-field-del-btn');
            if (delF) delF.addEventListener('click', () => { fi.remove(); updateFieldsCount(); updateCountsAndPreview(); });
            if (subList) subList.appendChild(fi);
            updateFieldsCount();
        }

        if (addFieldBtn && subList) {
            addFieldBtn.addEventListener('click', () => {
                if (subList.querySelectorAll('.cc-mb-subfield-item').length >= 25) return;
                addFieldItem(null);
                updateCountsAndPreview();
            });
        }
        if (Array.isArray(embed.fields)) embed.fields.forEach((f) => addFieldItem(f));

        return item;
    }

    // ── counts ────────────────────────────────────────────────────────────────
    function updateCountsAndPreview() {
        if (countEl && contentTA) countEl.textContent = contentTA.value.length;
        const ec = embedsList ? embedsList.querySelectorAll('.cc-mb-embed-item').length : 0;
        if (embedsCountEl) embedsCountEl.textContent = ec;
        if (addEmbedBtn) addEmbedBtn.disabled = ec >= 10;
        renderPreview();
    }

    // ── response type conds ───────────────────────────────────────────────────
    function updateResponseTypeConds() {
        const val = responseTypeSel ? responseTypeSel.value : 'reply';
        if (condSpecificChannel) condSpecificChannel.style.display = val === 'specific_channel' ? 'block' : 'none';
        if (condDmSpecificUser)  condDmSpecificUser.style.display  = val === 'dm_specific_user'  ? 'block' : 'none';
        if (condEditAction)      condEditAction.style.display      = val === 'edit_action'        ? 'block' : 'none';
    }
    if (responseTypeSel) responseTypeSel.addEventListener('change', updateResponseTypeConds);

    // ── open / close / save ───────────────────────────────────────────────────
    function open(node) {
        if (!node) return;
        currentNode = node;
        if (!node.config) node.config = {};

        if (responseTypeSel) responseTypeSel.value = node.config.response_type || 'reply';
        if (inputChannelId)     inputChannelId.value     = node.config.target_channel_id  || '';
        if (inputUserId)        inputUserId.value         = node.config.target_user_id    || '';
        if (inputEditTargetVar) inputEditTargetVar.value  = node.config.edit_target_var   || '';
        updateResponseTypeConds();

        if (contentTA) contentTA.value = node.config.message_content || '';
        if (embedsList) {
            embedsList.innerHTML = '';
            (Array.isArray(node.config.embeds) ? node.config.embeds : []).forEach((e, i) => {
                embedsList.appendChild(buildEmbedItem(e, i));
            });
        }
        updateCountsAndPreview();
        overlay.setAttribute('aria-hidden', 'false');
        overlay.classList.add('is-open');
        document.body.style.overflow = 'hidden';
    }

    function close() {
        overlay.setAttribute('aria-hidden', 'true');
        overlay.classList.remove('is-open');
        document.body.style.overflow = '';
        currentNode = null;
    }

    function save() {
        if (!currentNode) { close(); return; }
        currentNode.config.response_type     = responseTypeSel    ? responseTypeSel.value           : 'reply';
        currentNode.config.target_channel_id = inputChannelId     ? inputChannelId.value.trim()     : '';
        currentNode.config.target_user_id    = inputUserId        ? inputUserId.value.trim()        : '';
        currentNode.config.edit_target_var   = inputEditTargetVar ? inputEditTargetVar.value.trim() : '';
        currentNode.config.message_content   = contentTA ? contentTA.value : '';
        currentNode.config.embeds            = readEmbeds();

        if (window._cebBuilderMarkDirty)   window._cebBuilderMarkDirty();
        if (window._cebBuilderWriteJson)   window._cebBuilderWriteJson();
        if (window._cebBuilderRenderNodes) window._cebBuilderRenderNodes();
        if (window._cebShowProperties)     window._cebShowProperties(currentNode);
        close();
    }

    // ── event wiring ──────────────────────────────────────────────────────────
    if (closeBtn)       closeBtn.addEventListener('click', close);
    if (saveBtn)        saveBtn.addEventListener('click', save);
    if (contentTA)      contentTA.addEventListener('input', updateCountsAndPreview);
    if (addEmbedBtn) {
        addEmbedBtn.addEventListener('click', () => {
            const ec = embedsList ? embedsList.querySelectorAll('.cc-mb-embed-item').length : 0;
            if (ec >= 10) return;
            embedsList.appendChild(buildEmbedItem(defaultEmbed(), ec));
            updateCountsAndPreview();
        });
    }
    if (clearEmbedsBtn) {
        clearEmbedsBtn.addEventListener('click', () => { if (embedsList) embedsList.innerHTML = ''; updateCountsAndPreview(); });
    }
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && overlay.classList.contains('is-open')) close(); });

    window.CebMsgBuilder = { open, close };
})();
