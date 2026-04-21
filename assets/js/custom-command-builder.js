// PFAD: /assets/js/custom-command-builder.js

(function () {
    'use strict';

    const root = document.getElementById('cc-builder-root');
    if (!root) {
        return;
    }

    const MODE = root.dataset.mode || 'slash';
    const TRIGGER_TYPE = MODE === 'timed' ? 'trigger.timed' : (MODE === 'event' ? 'trigger.event' : 'trigger.slash');

    const form = document.getElementById('cc-builder-form');
    const canvas = document.getElementById('cc-builder-canvas');
    const world = document.getElementById('cc-builder-world');
    const canvasInner = document.getElementById('cc-builder-canvas-inner');
    const edgesSvg = document.getElementById('cc-builder-edges');
    const initialJsonField = document.getElementById('cc-builder-initial-json');
    const builderJsonField = document.getElementById('builder-json-field');
    const propsEmpty = document.getElementById('cc-builder-properties-empty');
    const propsPanel = document.getElementById('cc-builder-properties-panel');
    const propsDrawer = document.getElementById('cc-properties-drawer');
    const mainLayout = root.querySelector('.cc-main');
    const dynamicFields = document.getElementById('cc-builder-dynamic-fields');
    const propNodeId = document.getElementById('cc-prop-node-id');
    const propNodeType = document.getElementById('cc-prop-node-type');
    const deleteNodeBtn = document.getElementById('cc-builder-delete-node-btn');
    const saveStatus = document.getElementById('builder-save-status');
    const centerBtn = document.getElementById('cc-builder-center-btn');
    const clearSelectionBtn = document.getElementById('cc-builder-clear-selection-btn');
    const zoomInBtn = document.getElementById('cc-builder-zoom-in-btn');
    const zoomOutBtn = document.getElementById('cc-builder-zoom-out-btn');
    const zoomResetBtn = document.getElementById('cc-builder-zoom-reset-btn');
    const exportBtn = document.getElementById('cc-export-builder-btn');
    const importFileInput = document.getElementById('cc-import-builder-file');
    const blockSearch = document.getElementById('cc-block-search');
    const importCommandBtn = document.getElementById('cc-import-command-btn');
    const hiddenCommandNameInput = document.getElementById('command_name');
    const hiddenSlashNameInput = document.getElementById('slash_name');
    const hiddenDescriptionInput = document.getElementById('description');

    const NATIVE_NODE_TYPES = [TRIGGER_TYPE, 'utility.error_handler'];

    const blockDefs = window.BuilderDefinitions || {};

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
        activeBlockTab: (MODE === 'event' || MODE === 'timed') ? 'actions' : 'options',
        viewport: {
            x: 0,
            y: 0,
            zoom: 1
        },
        isPanning: false,
        panStartX: 0,
        panStartY: 0,
        panOriginX: 0,
        panOriginY: 0,
        isSelectingArea: false,
        selectionStartWorldX: 0,
        selectionStartWorldY: 0,
        selectionCurrentWorldX: 0,
        selectionCurrentWorldY: 0,
        selectionBoxEl: null
    };

    normalizeState();
    ensureNativeNodes();
    ensureTriggerMetaNode();
    ensureSelectionValidity();
    applyViewport();
    renderAll();
    registerRail();
    registerTabs();
    registerSidebarBlocks();
    registerSearch();
    registerToolbar();
    registerPanAndZoom();
    registerKeyboard();
    registerTemplateActions();
    registerFormSubmit();
    if (MODE === 'event' || MODE === 'timed') { registerEventMeta(); }

    function readInitialBuilder() {
        try {
            return JSON.parse(initialJsonField.value || '{}');
        } catch (error) {
            return {
                version: 1,
                viewport: { x: 0, y: 0, zoom: 1 },
                nodes: [],
                edges: []
            };
        }
    }

    function normalizeState() {
        if (!state.builder || typeof state.builder !== 'object') {
            state.builder = { version: 1, viewport: { x: 0, y: 0, zoom: 1 }, nodes: [], edges: [] };
        }

        if (!Array.isArray(state.builder.nodes)) {
            state.builder.nodes = [];
        }

        if (!Array.isArray(state.builder.edges)) {
            state.builder.edges = [];
        }

        if (state.builder.viewport && typeof state.builder.viewport === 'object') {
            state.viewport.x = toNumber(state.builder.viewport.x, 0);
            state.viewport.y = toNumber(state.builder.viewport.y, 0);
            state.viewport.zoom = clampZoom(toNumber(state.builder.viewport.zoom, 1));
        }

        state.builder.nodes = state.builder.nodes
            .filter((node) => node && typeof node === 'object' && typeof node.id === 'string' && typeof node.type === 'string')
            .map((node) => {
                const def = getDef(node.type);
                return {
                    id: node.id,
                    type: node.type,
                    label: typeof node.label === 'string' && node.label !== '' ? node.label : def.label,
                    x: toInt(node.x, 0),
                    y: toInt(node.y, 0),
                    config: Object.assign({}, def.defaults, isObject(node.config) ? node.config : {})
                };
            });

        state.builder.edges = state.builder.edges
            .filter((edge) => edge && typeof edge === 'object')
            .map((edge) => ({
                id: typeof edge.id === 'string' && edge.id !== '' ? edge.id : buildEdgeId(edge.from_node_id, edge.from_port, edge.to_node_id, edge.to_port),
                from_node_id: String(edge.from_node_id || ''),
                from_port: String(edge.from_port || ''),
                to_node_id: String(edge.to_node_id || ''),
                to_port: String(edge.to_port || '')
            }))
            .filter((edge) => edge.from_node_id !== '' && edge.from_port !== '' && edge.to_node_id !== '' && edge.to_port !== '');
    }

    function ensureNativeNodes() {
        let triggerNode = state.builder.nodes.find((node) => node.type === TRIGGER_TYPE);
        let errorNode = state.builder.nodes.find((node) => node.type === 'utility.error_handler');

        if (!triggerNode) {
            triggerNode = {
                id: 'node_trigger_' + Date.now(),
                type: TRIGGER_TYPE,
                label: MODE === 'timed' ? 'Timed Event Trigger' : (MODE === 'event' ? 'Event Trigger' : 'Slash Command'),
                x: 560,
                y: 260,
                config: Object.assign({}, getDef(TRIGGER_TYPE).defaults)
            };
            state.builder.nodes.unshift(triggerNode);
        }

        if (!errorNode) {
            errorNode = {
                id: 'node_error_handler_' + Date.now(),
                type: 'utility.error_handler',
                label: 'Error Handler',
                x: 980,
                y: 260,
                config: Object.assign({}, getDef('utility.error_handler').defaults)
            };
            state.builder.nodes.push(errorNode);
        }

        if (!edgeExists(triggerNode.id, 'error', errorNode.id, 'in')) {
            state.builder.edges.push({
                id: buildEdgeId(triggerNode.id, 'error', errorNode.id, 'in'),
                from_node_id: triggerNode.id,
                from_port: 'error',
                to_node_id: errorNode.id,
                to_port: 'in'
            });
        }
    }

    function ensureSelectionValidity() {
        const validIds = new Set(state.builder.nodes.map((node) => node.id));
        state.selectedNodeIds = state.selectedNodeIds.filter((id) => validIds.has(id));

        if (state.selectedNodeId !== null && !validIds.has(state.selectedNodeId)) {
            state.selectedNodeId = null;
        }

        if (state.selectedNodeId !== null && !state.selectedNodeIds.includes(state.selectedNodeId)) {
            state.selectedNodeIds = [state.selectedNodeId];
        }
    }

    function registerRail() {
        document.querySelectorAll('.cc-rail-btn').forEach((btn) => {
            btn.addEventListener('click', () => {
                state.activeRailPanel = btn.getAttribute('data-rail-panel') || 'blocks';

                document.querySelectorAll('.cc-rail-btn').forEach((item) => {
                    item.classList.toggle('is-active', item === btn);
                });

                document.querySelectorAll('.cc-panel-section').forEach((section) => {
                    section.classList.toggle('is-active', section.getAttribute('data-rail-content') === state.activeRailPanel);
                });
            });
        });
    }

    function registerTabs() {
        document.querySelectorAll('.cc-tab').forEach((btn) => {
            btn.addEventListener('click', () => {
                state.activeBlockTab = btn.getAttribute('data-block-tab') || 'options';

                document.querySelectorAll('.cc-tab').forEach((item) => {
                    item.classList.toggle('is-active', item === btn);
                });

                document.querySelectorAll('.cc-tab-panel').forEach((panel) => {
                    panel.classList.toggle('is-active', panel.getAttribute('data-block-tab-content') === state.activeBlockTab);
                });

                applySearchFilter();
            });
        });
    }

    function registerSidebarBlocks() {
        document.querySelectorAll('.cc-block-item').forEach((item) => {
            item.addEventListener('dragstart', (event) => {
                event.dataTransfer.setData('text/plain', JSON.stringify({
                    type: item.dataset.blockType || '',
                    label: item.dataset.blockLabel || ''
                }));
            });

            item.addEventListener('click', () => {
                const center = getCanvasCenterWorldPoint();
                addNodeFromType(item.dataset.blockType || '', item.dataset.blockLabel || '', center.x - 125, center.y - 34);
            });
        });

        if (importCommandBtn) {
            importCommandBtn.addEventListener('click', () => {
                if (importFileInput) {
                    importFileInput.click();
                }
            });
        }
    }

    function registerSearch() {
        if (!blockSearch) {
            return;
        }

        blockSearch.addEventListener('input', applySearchFilter);
    }

    function applySearchFilter() {
        const query = String(blockSearch ? blockSearch.value : '').trim().toLowerCase();
        const activePanel = document.querySelector('.cc-tab-panel.is-active');

        document.querySelectorAll('.cc-tab-panel').forEach((panel) => {
            panel.querySelectorAll('.cc-block-item').forEach((item) => {
                if (panel !== activePanel) {
                    item.style.display = '';
                    return;
                }

                const haystack = item.textContent ? item.textContent.toLowerCase() : '';
                item.style.display = query === '' || haystack.includes(query) ? '' : 'none';
            });
        });
    }

    function registerToolbar() {
        if (centerBtn) {
            centerBtn.addEventListener('click', () => {
                centerOnNodes();
                markDirty();
            });
        }

        if (clearSelectionBtn) {
            clearSelectionBtn.addEventListener('click', () => {
                clearSelection();
                renderAll();
            });
        }

        if (zoomInBtn) {
            zoomInBtn.addEventListener('click', () => {
                setZoom(state.viewport.zoom + 0.1);
            });
        }

        if (zoomOutBtn) {
            zoomOutBtn.addEventListener('click', () => {
                setZoom(state.viewport.zoom - 0.1);
            });
        }

        if (zoomResetBtn) {
            zoomResetBtn.addEventListener('click', () => {
                setZoom(1);
            });
        }

        if (deleteNodeBtn) {
            deleteNodeBtn.addEventListener('click', () => {
                deleteSelectedNodes();
            });
        }
    }

    function registerPanAndZoom() {
        canvas.addEventListener('dragover', (event) => {
            event.preventDefault();
        });

        canvas.addEventListener('drop', (event) => {
            event.preventDefault();

            let payload = null;
            try {
                payload = JSON.parse(event.dataTransfer.getData('text/plain'));
            } catch (error) {
                payload = null;
            }

            if (!payload || typeof payload.type !== 'string' || payload.type === '') {
                return;
            }

            const point = getWorldPoint(event.clientX, event.clientY);
            addNodeFromType(payload.type, payload.label || '', point.x - 125, point.y - 34);
        });

        edgesSvg.addEventListener('mouseover', (event) => {
            if (isEdgeDeleteTarget(event.target)) {
                canvas.classList.add('is-over-edge-delete');
            }
        }, true);

        edgesSvg.addEventListener('mouseout', (event) => {
            if (isEdgeDeleteTarget(event.target)) {
                canvas.classList.remove('is-over-edge-delete');
            }
        }, true);

        edgesSvg.addEventListener('click', (event) => {
            const target = event.target;

            if (!(target instanceof Element)) {
                return;
            }

            const deleteButton = target.closest('.cc-edge-delete-btn, .cc-edge-delete-circle, .cc-edge-delete-text');
            if (!deleteButton) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            const edgeId = deleteButton.getAttribute('data-edge-id')
                || (deleteButton.closest('.cc-edge-group') instanceof Element
                    ? deleteButton.closest('.cc-edge-group').getAttribute('data-edge-id')
                    : '');

            if (!edgeId) {
                return;
            }

            deleteEdge(edgeId);
        }, true);

        canvas.addEventListener('mousedown', (event) => {
            if (event.button !== 0) {
                return;
            }

            const target = event.target;
            const clickedNode = target instanceof Element ? target.closest('.cc-node') : null;
            const clickedPort = target instanceof Element ? target.closest('.cc-port') : null;
            const clickedEdgeDelete = target instanceof Element
                ? target.closest('.cc-edge-delete-btn, .cc-edge-delete-circle, .cc-edge-delete-text')
                : null;
            const clickedDrawer = target instanceof Element ? target.closest('.cc-drawer') : null;

            if (clickedNode || clickedPort || clickedEdgeDelete || clickedDrawer) {
                return;
            }

            if (state.selectedNodeIds.length > 0) {
                clearSelection();
                renderAll();
            }

            if (event.shiftKey) {
                state.isSelectingArea = true;
                state.selectionStartWorldX = getWorldPoint(event.clientX, event.clientY).x;
                state.selectionStartWorldY = getWorldPoint(event.clientX, event.clientY).y;
                state.selectionCurrentWorldX = state.selectionStartWorldX;
                state.selectionCurrentWorldY = state.selectionStartWorldY;
                ensureSelectionBox();
                updateSelectionBox();
                return;
            }

            state.isPanning = true;
            state.panStartX = event.clientX;
            state.panStartY = event.clientY;
            state.panOriginX = state.viewport.x;
            state.panOriginY = state.viewport.y;
            canvas.classList.add('is-panning');
        });

        canvas.addEventListener('wheel', (event) => {
            event.preventDefault();
            const delta = event.deltaY < 0 ? 0.08 : -0.08;
            setZoom(state.viewport.zoom + delta, event.clientX, event.clientY);
        }, { passive: false });

        document.addEventListener('mousemove', (event) => {
            if (state.isSelectingArea) {
                const point = getWorldPoint(event.clientX, event.clientY);
                state.selectionCurrentWorldX = point.x;
                state.selectionCurrentWorldY = point.y;
                updateSelectionBox();
                return;
            }

            if (state.isPanning) {
                const dx = event.clientX - state.panStartX;
                const dy = event.clientY - state.panStartY;
                state.viewport.x = state.panOriginX + dx;
                state.viewport.y = state.panOriginY + dy;
                applyViewport();
                writeJsonField();
                return;
            }

            if (state.pendingConnection && state.pendingConnection.isDragging) {
                const point = getWorldPoint(event.clientX, event.clientY);
                state.pendingConnection.mouseX = point.x;
                state.pendingConnection.mouseY = point.y;
                renderEdges();
                return;
            }

            if (!state.dragNodeId) {
                return;
            }

            const point = getWorldPoint(event.clientX, event.clientY);
            const offsetX = point.x - state.dragStartWorldX;
            const offsetY = point.y - state.dragStartWorldY;

            state.dragSelectionSnapshot.forEach((snapshot) => {
                const node = findNodeById(snapshot.id);
                if (!node) {
                    return;
                }

                node.x = snapshot.x + offsetX;
                node.y = snapshot.y + offsetY;
            });

            if (!state.dragRafPending) {
                state.dragRafPending = true;
                requestAnimationFrame(() => {
                    state.dragRafPending = false;
                    // Update only the positions of dragged nodes instead of full re-render
                    state.dragSelectionSnapshot.forEach((snapshot) => {
                        const node = findNodeById(snapshot.id);
                        if (!node) return;
                        const el = canvasInner.querySelector('[data-node-id="' + node.id + '"]');
                        if (el instanceof HTMLElement) {
                            el.style.left = node.x + 'px';
                            el.style.top = node.y + 'px';
                        }
                    });
                    renderEdges();
                });
            }

            markDirty(false);
        });

        document.addEventListener('mouseup', (event) => {
            if (state.isSelectingArea) {
                finishAreaSelection();
            }

            if (state.isPanning) {
                state.isPanning = false;
                canvas.classList.remove('is-panning');
                markDirty();
            }

            if (state.pendingConnection && state.pendingConnection.isDragging) {
                let targetPort = null;
                if (typeof event.clientX === 'number' && typeof event.clientY === 'number') {
                    const hit = document.elementFromPoint(event.clientX, event.clientY);
                    targetPort = hit instanceof HTMLElement ? hit.closest('.cc-port--input') : null;
                }

                if (targetPort instanceof HTMLElement) {
                    const targetNodeId = targetPort.getAttribute('data-node-id') || '';
                    const targetPortName = targetPort.getAttribute('data-port-name') || '';
                    connectPendingTo(targetNodeId, targetPortName);
                } else {
                    state.pendingConnection = null;
                    renderAll();
                }
            }

            if (state.dragNodeId) {
                // Snap to integer grid on release
                state.dragSelectionSnapshot.forEach((snapshot) => {
                    const node = findNodeById(snapshot.id);
                    if (!node) return;
                    node.x = Math.round(node.x);
                    node.y = Math.round(node.y);
                });
                state.dragNodeId = null;
                state.dragRafPending = false;
                state.dragSelectionSnapshot = [];
                markDirty();
                renderNodes();
                renderEdges();
                writeJsonField();
            }
        });
    }

    function registerKeyboard() {
        document.addEventListener('keydown', (event) => {
            const target = event.target;
            const tagName = target instanceof HTMLElement ? target.tagName.toLowerCase() : '';

            if (tagName === 'input' || tagName === 'textarea' || tagName === 'select') {
                return;
            }

            const ctrl = event.ctrlKey || event.metaKey;

            if (ctrl && event.key.toLowerCase() === 'c') {
                if (state.selectedNodeIds.length > 0) {
                    event.preventDefault();
                    copySelectedNodes();
                }
                return;
            }

            if (ctrl && event.key.toLowerCase() === 'v') {
                if (state.clipboardNodes.length > 0) {
                    event.preventDefault();
                    pasteClipboardNodes();
                }
                return;
            }

            if ((event.key === 'Delete' || event.key === 'Del') && state.selectedNodeIds.length > 0) {
                event.preventDefault();
                deleteSelectedNodes();
                return;
            }

            if (event.key === 'Escape') {
                clearSelection();
                renderAll();
            }
        });
    }

    function registerTemplateActions() {
        if (exportBtn) {
            exportBtn.addEventListener('click', () => {
                writeJsonField();
                const blob = new Blob([builderJsonField.value], { type: 'application/json;charset=utf-8' });
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = url;
                link.download = 'builder-export.json';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                URL.revokeObjectURL(url);
            });
        }

        if (importFileInput) {
            importFileInput.addEventListener('change', () => {
                const file = importFileInput.files && importFileInput.files[0] ? importFileInput.files[0] : null;
                if (!file) {
                    return;
                }

                const reader = new FileReader();
                reader.onload = () => {
                    try {
                        state.builder = JSON.parse(String(reader.result || '{}'));
                        normalizeState();
                        ensureNativeNodes();
                        clearSelection();
                        applyViewport();
                        renderAll();
                        markDirty();
                    } catch (error) {
                        alert('Import fehlgeschlagen: Ungültige JSON-Datei.');
                    }

                    importFileInput.value = '';
                };
                reader.readAsText(file, 'UTF-8');
            });
        }
    }

    // ── Toast helper ──────────────────────────────────────────────────────────
    function showToast(message, type /* 'success' | 'error' | 'info' */ = 'info', durationMs = 3500) {
        let container = document.getElementById('cc-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'cc-toast-container';
            document.body.appendChild(container);
        }
        const toast = document.createElement('div');
        toast.className = 'cc-toast cc-toast--' + type;
        toast.textContent = message;
        container.appendChild(toast);
        // Animate in
        requestAnimationFrame(() => { requestAnimationFrame(() => { toast.classList.add('is-visible'); }); });
        // Remove after duration
        setTimeout(() => {
            toast.classList.remove('is-visible');
            toast.addEventListener('transitionend', () => toast.remove(), { once: true });
        }, durationMs);
    }

    function registerFormSubmit() {
        if (!form) {
            return;
        }

        form.addEventListener('submit', async (e) => {
            e.preventDefault(); // never reload

            ensureNativeNodes();
            writeJsonField();

            if (saveStatus) { saveStatus.textContent = 'Wird gespeichert…'; }

            const data = new FormData(form);
            try {
                const resp = await fetch(form.action || window.location.href, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                    body: data,
                });

                let json;
                try {
                    json = await resp.json();
                } catch (_) {
                    // Server returned non-JSON (PHP fatal, redirect page, etc.)
                    const hint = resp.status === 403 ? 'Zugriff verweigert (403) – bitte Seite neu laden.'
                               : resp.status === 404 ? 'Seite nicht gefunden (404).'
                               : resp.status === 500 ? 'Interner Serverfehler (500) – Details in der PHP-Log-Datei.'
                               : resp.status === 0   ? 'Keine Verbindung zum Server.'
                               : 'Server antwortete mit Status ' + resp.status + ' (kein JSON).';
                    json = { ok: false, message: hint };
                }

                if (json.ok) {
                    state.dirty = false;
                    if (saveStatus) { saveStatus.textContent = 'Gespeichert'; }
                    showToast(json.message || 'Gespeichert', 'success');

                    // If a new command was created, update the URL without reloading
                    if (json.command_id && json.command_id > 0) {
                        const url = new URL(window.location.href);
                        if (!url.searchParams.get('command_id')) {
                            url.searchParams.set('command_id', json.command_id);
                            history.replaceState({}, '', url.toString());
                            // Also update the form action so future saves go to the right URL
                            form.action = url.toString();
                        }
                    }
                } else {
                    if (saveStatus) { saveStatus.textContent = 'Fehler beim Speichern'; }
                    showToast(json.message || 'Fehler beim Speichern', 'error', 6000);
                }
            } catch (err) {
                if (saveStatus) { saveStatus.textContent = 'Netzwerkfehler'; }
                showToast('Netzwerkfehler: ' + err.message, 'error', 6000);
            }
        });

        // Ctrl+S / Cmd+S → save
        document.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                if (form && !form.querySelector('[type="submit"]')?.disabled) {
                    form.requestSubmit();
                }
            }
        });

        // Warn before navigating away with unsaved changes
        window.addEventListener('beforeunload', (e) => {
            if (state.dirty) {
                e.preventDefault();
            }
        });
    }

    function addNodeFromType(type, label, x, y) {
        if (isNativeNodeType(type)) {
            return;
        }

        const def = getDef(type);
        const nextNumber = state.builder.nodes.length + 1;

        const node = {
            id: 'node_' + nextNumber + '_' + Date.now(),
            type: type,
            label: label || def.label,
            x: Math.max(0, Math.round(x)),
            y: Math.max(0, Math.round(y)),
            config: Object.assign({}, def.defaults)
        };

        state.builder.nodes.push(node);
        state.selectedNodeId = node.id;
        state.selectedNodeIds = [node.id];
        markDirty();
        renderAll();
    }

    function spawnComponentNode(parentNode, fromPort, spawnType, spawnLabel) {
        if (!spawnType) { return; }

        const def = getDef(spawnType);
        const existingOnPort = state.builder.edges.filter((e) => e.from_node_id === parentNode.id && e.from_port === fromPort).length;
        const nextNumber = state.builder.nodes.length + 1;

        const baseOffsetX = fromPort === 'menu' ? 160 : -20;
        const newX = Math.max(0, parentNode.x + baseOffsetX + existingOnPort * 210);
        const newY = parentNode.y + 220;

        const autoLabel = fromPort === 'button'
            ? (spawnLabel || 'Button') + ' ' + (existingOnPort + 1)
            : spawnLabel || def.label;

        const newNode = {
            id: 'node_' + nextNumber + '_' + Date.now(),
            type: spawnType,
            label: autoLabel,
            x: newX,
            y: newY,
            config: Object.assign({}, def.defaults, fromPort === 'button' ? { label: autoLabel } : {})
        };

        state.builder.nodes.push(newNode);
        state.builder.edges.push({
            id: buildEdgeId(parentNode.id, fromPort, newNode.id, 'in'),
            from_node_id: parentNode.id,
            from_port: fromPort,
            to_node_id: newNode.id,
            to_port: 'in'
        });

        state.selectedNodeId = newNode.id;
        state.selectedNodeIds = [newNode.id];
        markDirty();
        renderAll();
    }

    function deleteSelectedNodes() {
        if (state.selectedNodeIds.length === 0) {
            return;
        }

        const removableIds = new Set(
            state.selectedNodeIds.filter((nodeId) => {
                const node = findNodeById(nodeId);
                return node && !isNativeNode(node);
            })
        );

        if (removableIds.size === 0) {
            renderProperties();
            return;
        }

        state.builder.nodes = state.builder.nodes.filter((node) => !removableIds.has(node.id));
        state.builder.edges = state.builder.edges.filter((edge) => !removableIds.has(edge.from_node_id) && !removableIds.has(edge.to_node_id));
        state.selectedNodeIds = state.selectedNodeIds.filter((nodeId) => !removableIds.has(nodeId));
        state.selectedNodeId = state.selectedNodeIds.length > 0 ? state.selectedNodeIds[0] : null;
        ensureNativeNodes();
        markDirty();
        renderAll();
    }

    function copySelectedNodes() {
        state.clipboardNodes = state.builder.nodes
            .filter((node) => state.selectedNodeIds.includes(node.id) && !isNativeNode(node))
            .map((node) => ({
                type: node.type,
                label: node.label,
                x: node.x,
                y: node.y,
                config: JSON.parse(JSON.stringify(node.config))
            }));
    }

    function pasteClipboardNodes() {
        if (state.clipboardNodes.length === 0) {
            return;
        }

        const pastedIds = [];
        const offsetX = 40;
        const offsetY = 40;

        state.clipboardNodes.forEach((snapshot, index) => {
            const def = getDef(snapshot.type);
            const newNode = {
                id: 'node_' + (state.builder.nodes.length + index + 1) + '_' + Date.now() + '_' + index,
                type: snapshot.type,
                label: snapshot.label || def.label,
                x: Math.max(0, Math.round(snapshot.x + offsetX)),
                y: Math.max(0, Math.round(snapshot.y + offsetY)),
                config: JSON.parse(JSON.stringify(snapshot.config || def.defaults))
            };
            state.builder.nodes.push(newNode);
            pastedIds.push(newNode.id);
        });

        state.selectedNodeIds = pastedIds;
        state.selectedNodeId = pastedIds.length > 0 ? pastedIds[0] : null;
        state.clipboardNodes = state.clipboardNodes.map((node) => ({
            type: node.type,
            label: node.label,
            x: node.x + offsetX,
            y: node.y + offsetY,
            config: JSON.parse(JSON.stringify(node.config))
        }));

        markDirty();
        renderAll();
    }

    function clearSelection() {
        state.selectedNodeId = null;
        state.selectedNodeIds = [];
        state.pendingConnection = null;
    }

    function selectSingleNode(nodeId) {
        state.selectedNodeId = nodeId;
        state.selectedNodeIds = nodeId ? [nodeId] : [];
        state.pendingConnection = null;
    }

    function isEdgeDeleteTarget(target) {
        return target instanceof Element
            && !!target.closest('.cc-edge-delete-btn, .cc-edge-delete-circle, .cc-edge-delete-text');
    }

    function ensureTriggerMetaNode() {
        const triggerNode = state.builder.nodes.find((node) => node.type === TRIGGER_TYPE);
        if (!triggerNode) {
            return;
        }

        if (!isObject(triggerNode.config)) {
            triggerNode.config = {};
        }

        if (MODE === 'event') {
            const hiddenEventType = hiddenSlashNameInput instanceof HTMLInputElement ? hiddenSlashNameInput.value.trim() : '';
            if (hiddenEventType !== '' && (typeof triggerNode.config.event_type !== 'string' || triggerNode.config.event_type === '')) {
                triggerNode.config.event_type = hiddenEventType;
            }
            syncTriggerMetaFields(triggerNode);
            return;
        }

        if (MODE === 'timed') {
            // event_name synced via registerEventMeta; event_type managed by CcTimedBuilder modal
            const hiddenEventName = hiddenCommandNameInput instanceof HTMLInputElement ? hiddenCommandNameInput.value.trim() : '';
            if (hiddenEventName !== '' && (typeof triggerNode.config.event_name !== 'string' || triggerNode.config.event_name === '')) {
                triggerNode.config.event_name = hiddenEventName;
            }
            syncTriggerMetaFields(triggerNode);
            return;
        }

        const hiddenDisplayName = hiddenCommandNameInput instanceof HTMLInputElement ? hiddenCommandNameInput.value.trim() : '';
        const hiddenSlashName = hiddenSlashNameInput instanceof HTMLInputElement ? hiddenSlashNameInput.value.trim() : '';
        const hiddenDescription = hiddenDescriptionInput instanceof HTMLInputElement ? hiddenDescriptionInput.value.trim() : '';

        if (typeof triggerNode.config.display_name !== 'string' || triggerNode.config.display_name === '') {
            triggerNode.config.display_name = hiddenDisplayName;
        }

        if (typeof triggerNode.config.name !== 'string' || triggerNode.config.name === '') {
            triggerNode.config.name = hiddenSlashName !== '' ? hiddenSlashName : 'command';
        }

        if (typeof triggerNode.config.description !== 'string' || triggerNode.config.description === '') {
            triggerNode.config.description = hiddenDescription;
        }

        syncTriggerMetaFields(triggerNode);
    }

    function syncTriggerMetaFields(node) {
        if (!node || node.type !== TRIGGER_TYPE) {
            return;
        }

        const config = isObject(node.config) ? node.config : {};

        if (MODE === 'event') {
            if (hiddenSlashNameInput instanceof HTMLInputElement) {
                hiddenSlashNameInput.value = typeof config.event_type === 'string' ? config.event_type : '';
            }
            const eventTypeDisplay = document.getElementById('cc-event-type-display');
            if (eventTypeDisplay) {
                const evLabels = window.CebEventLabels || {};
                const evType = config.event_type || '';
                eventTypeDisplay.textContent = evType ? (evLabels[evType] || evType) : '(not set — click trigger node)';
            }
            return;
        }

        if (MODE === 'timed') {
            // Sync event_name back to the hidden command_name field
            const eventName = typeof config.event_name === 'string' ? config.event_name : '';
            if (hiddenCommandNameInput instanceof HTMLInputElement) {
                hiddenCommandNameInput.value = eventName;
            }
            return;
        }

        const displayName = typeof config.display_name === 'string' ? config.display_name : '';
        const slashName = typeof config.name === 'string' && config.name !== '' ? config.name : 'command';
        const description = typeof config.description === 'string' ? config.description : '';

        if (hiddenCommandNameInput instanceof HTMLInputElement) {
            hiddenCommandNameInput.value = displayName;
        }

        if (hiddenSlashNameInput instanceof HTMLInputElement) {
            hiddenSlashNameInput.value = slashName;
        }

        if (hiddenDescriptionInput instanceof HTMLInputElement) {
            hiddenDescriptionInput.value = description;
        }
    }

    function renderAll() {
        ensureNativeNodes();
        ensureSelectionValidity();
        renderNodes();
        renderEdges();
        renderProperties();
        applyViewport();
        ensureTriggerMetaNode();
        writeJsonField();
    }

    function renderNodes() {
        canvasInner.querySelectorAll('.cc-node').forEach((node) => node.remove());

        state.builder.nodes.forEach((node) => {
            const def = getDef(node.type);
            const isPrimarySelected = node.id === state.selectedNodeId;
            const isMultiSelected = state.selectedNodeIds.includes(node.id);
            const nativeClass = isNativeNode(node) ? ' is-native' : '';

            const el = document.createElement('div');
            el.className = 'cc-node cc-node--' + def.category
                + nativeClass
                + (isPrimarySelected ? ' is-selected' : '')
                + (isMultiSelected && !isPrimarySelected ? ' is-multi-selected' : '');
            el.style.left = node.x + 'px';
            el.style.top = node.y + 'px';
            el.dataset.nodeId = node.id;

            const incomingCount = def.input
                ? state.builder.edges.filter((e) => e.to_node_id === node.id && (e.to_port === 'in' || e.to_port === 'options')).length
                : 0;
            const inputPortHtml = def.input
                ? '<button type="button" class="cc-port cc-port--input' + (incomingCount > 1 ? ' is-multi' : '') + '" data-node-id="' + escapeAttr(node.id) + '" data-port-type="input" data-port-name="in" title="Input">'
                  + (incomingCount > 1 ? '<span class="cc-port-multi-badge">' + incomingCount + '</span>' : '')
                  + '</button>'
                : '';

            let outputPortsHtml = '';
            let branchFooterHtml = '';
            const outputs = Array.isArray(def.outputs) ? def.outputs : [];

            if (def.category === 'condition' && (outputs.length > 0 || node.type === 'condition.comparison')) {
                if (node.type === 'condition.comparison') {
                    // ── Dynamic switch/case branches: Else + one port per condition ──
                    const OP_LABELS = {
                        '<': 'less than', '<=': 'less than or equal to',
                        '>': 'greater than', '>=': 'greater than or equal to',
                        '==': 'equal to', '!=': 'not equal to',
                        'contains': 'contains', 'not_contains': 'does not contain',
                        'starts_with': 'starts with', 'ends_with': 'ends with',
                        'not_starts_with': 'not starts with', 'not_ends_with': 'not ends with',
                        'collection_contains': 'coll. contains',
                        'collection_not_contains': 'coll. not contains',
                    };
                    const conds = Array.isArray(node.config.conditions) ? node.config.conditions : [];
                    const totalBranches = conds.length + 1; // +1 for Else
                    const nodeWidth = Math.max(260, totalBranches * 90 + 20);
                    el.style.width = nodeWidth + 'px';

                    branchFooterHtml = '<div class="cc-node-branches cc-node-branches--comparison">';

                    // Else port (first — leftmost)
                    const elseP = isPendingPort(node.id, 'else') ? ' is-pending' : '';
                    branchFooterHtml += ''
                        + '<div class="cc-node-branch cc-node-branch--false">'
                        + '  <div class="cc-node-branch-inner"><span class="cc-node-branch-icon">✗</span><span class="cc-node-branch-label">Else</span></div>'
                        + '  <button type="button" class="cc-port cc-port--output' + elseP + '" data-node-id="' + escapeAttr(node.id) + '" data-port-type="output" data-port-name="else" title="Else"></button>'
                        + '</div>';

                    // One port per condition
                    conds.forEach((cond, idx) => {
                        const portName = 'cond_' + idx;
                        const pending  = isPendingPort(node.id, portName) ? ' is-pending' : '';
                        const bv = truncate(String(cond.base_value       || '…'), 9);
                        const op = OP_LABELS[cond.operator || '=='] || (cond.operator || '==');
                        const cv = truncate(String(cond.comparison_value || '…'), 9);
                        const label = bv + ' ' + op + ' ' + cv;
                        branchFooterHtml += ''
                            + '<div class="cc-node-branch cc-node-branch--cond">'
                            + '  <div class="cc-node-branch-inner">'
                            + '    <span class="cc-node-branch-num">' + (idx + 1) + '</span>'
                            + '    <span class="cc-node-branch-label">' + escapeHtml(label) + '</span>'
                            + '  </div>'
                            + '  <button type="button" class="cc-port cc-port--output' + pending + '" data-node-id="' + escapeAttr(node.id) + '" data-port-type="output" data-port-name="' + escapeAttr(portName) + '" title="' + escapeAttr(label) + '"></button>'
                            + '</div>';
                    });

                    branchFooterHtml += '</div>';
                } else {
                // ── Standard condition branches (true / false / else) ─────────
                branchFooterHtml = '<div class="cc-node-branches">';
                outputs.forEach((portName) => {
                    const pending    = isPendingPort(node.id, portName) ? ' is-pending' : '';
                    const isTruthy   = portName === 'true'  || portName === 'yes';
                    const isFalsy    = portName === 'false' || portName === 'no' || portName === 'else';
                    const branchMod  = isTruthy ? '--true' : isFalsy ? '--false' : '--other';
                    const icon       = isTruthy ? '✓' : isFalsy ? '✗' : '→';
                    const label      = portName.charAt(0).toUpperCase() + portName.slice(1);
                    branchFooterHtml += ''
                        + '<div class="cc-node-branch cc-node-branch' + branchMod + '">'
                        + '  <div class="cc-node-branch-inner">'
                        + '    <span class="cc-node-branch-icon">' + icon + '</span>'
                        + '    <span class="cc-node-branch-label">' + escapeHtml(label) + '</span>'
                        + '  </div>'
                        + '  <button type="button" class="cc-port cc-port--output' + pending + '"'
                        + '    data-node-id="' + escapeAttr(node.id) + '" data-port-type="output"'
                        + '    data-port-name="' + escapeAttr(portName) + '" title="' + escapeAttr(label) + '">'
                        + '  </button>'
                        + '</div>';
                });
                branchFooterHtml += '</div>';
                }
            } else {
                // ── Split flow vs component output ports ───────────────────────
                const outputPortDefs = Array.isArray(def.output_ports) ? def.output_ports : [];
                const flowOutputs = [];
                const compOutputs = [];

                outputs.forEach((portName) => {
                    const portDef = outputPortDefs.find((p) => p.key === portName) || {};
                    if (portDef.kind === 'component') {
                        compOutputs.push({ portName, portDef });
                    } else {
                        flowOutputs.push(portName);
                    }
                });

                // Render flow output ports (centered at bottom of node)
                flowOutputs.forEach((portName, index) => {
                    const spacing = flowOutputs.length > 1 ? (100 / (flowOutputs.length + 1)) * (index + 1) : 50;
                    outputPortsHtml += ''
                        + '<button type="button" class="cc-port cc-port--output'
                        + (isPendingPort(node.id, portName) ? ' is-pending' : '')
                        + '" data-node-id="' + escapeAttr(node.id) + '" data-port-type="output" data-port-name="' + escapeAttr(portName) + '"'
                        + ' style="left: calc(' + spacing + '% - 6px);" title="' + escapeAttr(portName) + '">'
                        + '<span class="cc-port-label">' + escapeHtml(portName) + '</span>'
                        + '</button>';
                });

                // Render component ports as bottom spawn bar
                if (compOutputs.length > 0) {
                    branchFooterHtml = '<div class="cc-node-component-bar">';
                    compOutputs.forEach(({ portName, portDef }, idx) => {
                        const isRight = idx > 0;
                        const spawnType  = portDef.spawn_type || '';
                        const label      = portDef.label || portName;
                        const pendingCls = isPendingPort(node.id, portName) ? ' is-pending' : '';
                        const hasConn    = state.builder.edges.some((e) => e.from_node_id === node.id && e.from_port === portName);
                        const connCls    = hasConn ? ' has-connection' : '';
                        branchFooterHtml += ''
                            + '<button type="button"'
                            + ' class="cc-comp-port-btn' + (isRight ? ' cc-comp-port-btn--right' : '') + connCls + '"'
                            + ' data-node-id="' + escapeAttr(node.id) + '"'
                            + ' data-spawn-port="' + escapeAttr(portName) + '"'
                            + ' data-spawn-type="' + escapeAttr(spawnType) + '"'
                            + ' data-spawn-label="' + escapeAttr(label) + '">'
                            + '<span class="cc-port cc-port--output cc-port--comp' + pendingCls + '"'
                            + ' data-node-id="' + escapeAttr(node.id) + '" data-port-type="output"'
                            + ' data-port-name="' + escapeAttr(portName) + '" title="' + escapeAttr(label) + '"></span>'
                            + escapeHtml(label)
                            + '</button>';
                    });
                    branchFooterHtml += '</div>';
                }
            }

            const nativeMeta = isNativeNode(node)
                ? '<div class="cc-node-native-pill">Native</div>'
                : '';

            el.innerHTML = ''
                + inputPortHtml
                + '<div class="cc-node-head">'
                + '  <div class="cc-node-badge">' + escapeHtml(renderNodeBadge(def)) + '</div>'
                + '  <div class="cc-node-title-wrap">'
                + '      <div class="cc-node-title">' + escapeHtml(node.label) + '</div>'
                + '      <div class="cc-node-subtitle">' + escapeHtml(def.subtitle) + '</div>'
                + '  </div>'
                +       nativeMeta
                + '</div>'
                + '<div class="cc-node-body">' + renderNodePreview(node) + '</div>'
                + branchFooterHtml
                + outputPortsHtml;

            attachNodeEvents(el, node);
            canvasInner.appendChild(el);
        });
    }

    function attachNodeEvents(el, node) {
        el.addEventListener('mousedown', (event) => {
            const target = event.target;
            if (target instanceof Element && target.closest('.cc-port')) {
                return;
            }

            event.stopPropagation();

            const isMulti = event.ctrlKey || event.metaKey;

            if (isMulti) {
                if (state.selectedNodeIds.includes(node.id)) {
                    state.selectedNodeIds = state.selectedNodeIds.filter((id) => id !== node.id);
                    state.selectedNodeId = state.selectedNodeIds.length > 0 ? state.selectedNodeIds[0] : null;
                    renderAll();
                    return;
                }

                state.selectedNodeIds.push(node.id);
                state.selectedNodeId = node.id;
            } else {
                selectSingleNode(node.id);
            }

            state.dragNodeId = node.id;
            state.dragStartWorldX = getWorldPoint(event.clientX, event.clientY).x;
            state.dragStartWorldY = getWorldPoint(event.clientX, event.clientY).y;
            state.dragSelectionSnapshot = state.builder.nodes
                .filter((item) => state.selectedNodeIds.includes(item.id))
                .map((item) => ({
                    id: item.id,
                    x: item.x,
                    y: item.y
                }));

            renderAll();
        });

        el.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            selectSingleNode(node.id);
            renderAll();
        });

        el.querySelectorAll('.cc-port').forEach((portEl) => {
            portEl.addEventListener('mousedown', (event) => {
                event.stopPropagation();

                const portType = portEl.getAttribute('data-port-type') || '';
                const portName = portEl.getAttribute('data-port-name') || '';

                if (portType !== 'output' || event.button !== 0) {
                    return;
                }

                selectSingleNode(node.id);

                const point = getWorldPoint(event.clientX, event.clientY);
                state.pendingConnection = {
                    fromNodeId: node.id,
                    fromPort: portName,
                    mouseX: point.x,
                    mouseY: point.y,
                    isDragging: true
                };
                renderAll();
            });

            portEl.addEventListener('mouseup', (event) => {
                event.stopPropagation();

                const portType = portEl.getAttribute('data-port-type') || '';
                const portName = portEl.getAttribute('data-port-name') || '';

                if (portType === 'input' && state.pendingConnection && state.pendingConnection.isDragging) {
                    connectPendingTo(node.id, portName);
                }
            });
        });

        // ── Component port bar: click to spawn a new node ─────────────────
        el.querySelectorAll('.cc-comp-port-btn').forEach((compBtn) => {
            // Stop the node's mousedown handler from firing (it calls renderAll which
            // destroys the DOM before mouseup, so the click event never reaches compBtn)
            compBtn.addEventListener('mousedown', (event) => {
                // Let port-circle drags through; block everything else
                if (!(event.target instanceof Element && event.target.closest('.cc-port'))) {
                    event.stopPropagation();
                }
            });

            compBtn.addEventListener('click', (event) => {
                event.stopPropagation();

                // If the click originated from the port circle itself, it's a drag-start — don't spawn
                if (event.target instanceof Element && event.target.closest('.cc-port')) {
                    return;
                }

                const spawnType  = compBtn.getAttribute('data-spawn-type')  || '';
                const spawnPort  = compBtn.getAttribute('data-spawn-port')  || '';
                const spawnLabel = compBtn.getAttribute('data-spawn-label') || '';

                if (!spawnType || !spawnPort) { return; }

                spawnComponentNode(node, spawnPort, spawnType, spawnLabel);
            });
        });
    }

    function connectPendingTo(targetNodeId, targetPortName) {
        const pending = state.pendingConnection;
        if (!pending) {
            return;
        }

        if (pending.fromNodeId === targetNodeId) {
            state.pendingConnection = null;
            renderNodes();
            return;
        }

        const sourceNode = findNodeById(pending.fromNodeId);
        const targetNode = findNodeById(targetNodeId);

        if (!sourceNode || !targetNode) {
            state.pendingConnection = null;
            renderNodes();
            return;
        }

        const sourceDef = getDef(sourceNode.type);
        const targetDef = getDef(targetNode.type);

        if (!targetDef.input || targetPortName !== 'in') {
            state.pendingConnection = null;
            renderNodes();
            return;
        }

        const sourceIsOption = sourceDef.category === 'option';
        const targetIsSlashTrigger = MODE !== 'event' && targetNode.type === 'trigger.slash';

        if (MODE !== 'event' && sourceIsOption && !targetIsSlashTrigger) {
            state.pendingConnection = null;
            renderAll();
            return;
        }

        if (MODE !== 'event' && targetIsSlashTrigger && !sourceIsOption) {
            state.pendingConnection = null;
            renderAll();
            return;
        }

        state.builder.edges = state.builder.edges.filter((edge) => {
            // Each output port can connect to exactly one target (replace if reconnected)
            if (edge.from_node_id === pending.fromNodeId && edge.from_port === pending.fromPort) {
                return false;
            }
            return true;
        });

        // Option → trigger.slash: always store as 'options' port so the service finds it
        const resolvedToPort = (sourceIsOption && targetIsSlashTrigger) ? 'options' : targetPortName;

        // Prevent duplicate edge (same from→to already exists)
        const alreadyConnected = state.builder.edges.some((edge) =>
            edge.from_node_id === pending.fromNodeId
            && edge.from_port === pending.fromPort
            && edge.to_node_id === targetNodeId
            && edge.to_port === resolvedToPort
        );
        if (alreadyConnected) {
            state.pendingConnection = null;
            renderNodes();
            return;
        }

        state.builder.edges.push({
            id: buildEdgeId(pending.fromNodeId, pending.fromPort, targetNodeId, resolvedToPort),
            from_node_id: pending.fromNodeId,
            from_port: pending.fromPort,
            to_node_id: targetNodeId,
            to_port: resolvedToPort
        });

        state.pendingConnection = null;
        markDirty();
        renderAll();
    }

    function deleteEdge(edgeId) {
        const edge = state.builder.edges.find((item) => item.id === edgeId) || null;
        if (edge && isProtectedNativeEdge(edge)) {
            return;
        }

        state.builder.edges = state.builder.edges.filter((item) => item.id !== edgeId);
        state.pendingConnection = null;
        ensureNativeNodes();
        markDirty();
        renderAll();
    }

    function renderEdges() {
        while (edgesSvg.firstChild) {
            edgesSvg.removeChild(edgesSvg.firstChild);
        }

        state.builder.edges.forEach((edge) => {
            const fromNodeEl = canvasInner.querySelector('.cc-node[data-node-id="' + cssEscape(edge.from_node_id) + '"]');
            const toNodeEl = canvasInner.querySelector('.cc-node[data-node-id="' + cssEscape(edge.to_node_id) + '"]');

            if (!(fromNodeEl instanceof HTMLElement) || !(toNodeEl instanceof HTMLElement)) {
                return;
            }

            const fromPortEl = fromNodeEl.querySelector('.cc-port--output[data-port-name="' + cssEscape(edge.from_port) + '"]');
            const toNode = state.builder.nodes.find((n) => n.id === edge.to_node_id);
            const toPortName = (edge.to_port === 'options' && toNode?.type === 'trigger.slash' && MODE !== 'event') ? 'in' : edge.to_port;
            const toPortEl = toNodeEl.querySelector('.cc-port--input[data-port-name="' + cssEscape(toPortName) + '"]');

            if (!(fromPortEl instanceof HTMLElement) || !(toPortEl instanceof HTMLElement)) {
                return;
            }

            const start = getPortCenter(fromPortEl);
            const end = getPortCenter(toPortEl);
            const center = getBezierMidPoint(start.x, start.y, end.x, end.y);
            const pathValue = buildBezierPath(start.x, start.y, end.x, end.y);
            const protectedEdge = isProtectedNativeEdge(edge);

            const group = document.createElementNS('http://www.w3.org/2000/svg', 'g');
            group.setAttribute('class', 'cc-edge-group' + (protectedEdge ? ' is-protected' : ''));
            group.setAttribute('data-edge-id', edge.id);

            const hitPath = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            hitPath.setAttribute('class', 'cc-edge-hit');
            hitPath.setAttribute('d', pathValue);
            group.appendChild(hitPath);

            const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            path.setAttribute('class', 'cc-edge-path');
            path.setAttribute('d', pathValue);
            group.appendChild(path);

            if (!protectedEdge) {
                const deleteGroup = document.createElementNS('http://www.w3.org/2000/svg', 'g');
                deleteGroup.setAttribute('class', 'cc-edge-delete-btn');
                deleteGroup.setAttribute('data-edge-id', edge.id);
                deleteGroup.setAttribute('transform', 'translate(' + center.x + ' ' + center.y + ')');
                deleteGroup.setAttribute('role', 'button');
                deleteGroup.setAttribute('tabindex', '0');
                deleteGroup.setAttribute('aria-label', 'Verbindung löschen');

                const deleteCircle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
                deleteCircle.setAttribute('r', '10');
                deleteCircle.setAttribute('class', 'cc-edge-delete-circle');
                deleteCircle.setAttribute('data-edge-id', edge.id);
                deleteGroup.appendChild(deleteCircle);

                const deleteText = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                deleteText.setAttribute('class', 'cc-edge-delete-text');
                deleteText.setAttribute('data-edge-id', edge.id);
                deleteText.setAttribute('text-anchor', 'middle');
                deleteText.setAttribute('dominant-baseline', 'middle');
                deleteText.textContent = '×';
                deleteGroup.appendChild(deleteText);

                group.addEventListener('mouseenter', () => {
                    group.classList.add('is-hovered');
                });
                group.addEventListener('mouseleave', () => {
                    group.classList.remove('is-hovered');
                });
                deleteGroup.addEventListener('mouseenter', () => {
                    canvas.classList.add('is-over-edge-delete');
                });
                deleteGroup.addEventListener('mouseleave', () => {
                    canvas.classList.remove('is-over-edge-delete');
                });
                deleteGroup.addEventListener('mousedown', (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    canvas.classList.add('is-over-edge-delete');
                });
                deleteGroup.addEventListener('click', (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    deleteEdge(edge.id);
                });
                deleteCircle.addEventListener('click', (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    deleteEdge(edge.id);
                });
                deleteText.addEventListener('click', (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    deleteEdge(edge.id);
                });
                deleteGroup.addEventListener('keydown', (event) => {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        event.stopPropagation();
                        deleteEdge(edge.id);
                    }
                });

                group.appendChild(deleteGroup);
            }

            edgesSvg.appendChild(group);
        });

        if (state.pendingConnection && state.pendingConnection.isDragging) {
            const fromNodeEl = canvasInner.querySelector('.cc-node[data-node-id="' + cssEscape(state.pendingConnection.fromNodeId) + '"]');
            if (fromNodeEl instanceof HTMLElement) {
                const fromPortEl = fromNodeEl.querySelector('.cc-port--output[data-port-name="' + cssEscape(state.pendingConnection.fromPort) + '"]');
                if (fromPortEl instanceof HTMLElement) {
                    const start = getPortCenter(fromPortEl);
                    const end = {
                        x: state.pendingConnection.mouseX,
                        y: state.pendingConnection.mouseY
                    };
                    const pendingPath = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                    pendingPath.setAttribute('class', 'cc-edge-path cc-edge-path--pending');
                    pendingPath.setAttribute('d', buildBezierPath(start.x, start.y, end.x, end.y));
                    edgesSvg.appendChild(pendingPath);
                }
            }
        }
    }

    function renderProperties() {
    const node = state.selectedNodeId ? findNodeById(state.selectedNodeId) : null;

    if (!node || state.selectedNodeIds.length !== 1) {
        propsEmpty.style.display = 'block';
        propsPanel.classList.add('is-hidden');
        propsDrawer.classList.remove('is-open');
        if (mainLayout) {
            mainLayout.classList.remove('is-drawer-open');
        }
        dynamicFields.innerHTML = '';

        if (propNodeId) {
            propNodeId.value = '';
        }

        if (propNodeType) {
            propNodeType.value = '';
        }

        if (deleteNodeBtn) {
            deleteNodeBtn.disabled = false;
            deleteNodeBtn.textContent = 'Block löschen';
        }

        return;
    }

    const def = getDef(node.type);

    propsEmpty.style.display = 'none';
    propsPanel.classList.remove('is-hidden');
    propsDrawer.classList.add('is-open');
    if (mainLayout) {
        mainLayout.classList.add('is-drawer-open');
    }

    if (propNodeId) {
        propNodeId.value = node.id;
    }

    if (propNodeType) {
        propNodeType.value = node.type;
    }

    dynamicFields.innerHTML = '';

    // ── Option variable info card ──────────────────────────────────────────
    if (def.category === 'option') {
        const optName = String(node.config.option_name || 'varname');
        const optionTypeExamples = {
            'option.text':       { label: 'Text',       example: '"Hello World" oder "Mein Text"' },
            'option.number':     { label: 'Zahl',       example: '42 oder 3.14' },
            'option.user':       { label: 'User',       example: 'User-ID des gewählten Mitglieds' },
            'option.channel':    { label: 'Kanal',      example: 'Channel-ID des gewählten Kanals' },
            'option.role':       { label: 'Rolle',      example: 'Rollen-ID der gewählten Rolle' },
            'option.choice':     { label: 'Auswahl',    example: 'Wert der gewählten Option' },
            'option.attachment': { label: 'Datei',      example: 'URL der hochgeladenen Datei' },
        };
        const meta = optionTypeExamples[node.type] || { label: 'Option', example: '…' };

        const card = document.createElement('div');
        card.className = 'cc-opt-info-card';
        card.innerHTML = ''
            + '<div class="cc-opt-info-header">'
            + '  <span class="cc-opt-info-icon">ℹ</span>'
            + '  <span>Was gibt <button type="button" class="cc-opt-info-var-chip" id="cc-opt-info-chip" title="Klicken zum Kopieren">'
            +        '{option.<span class="cc-opt-chip-name">' + escapeHtml(optName) + '</span>}'
            + '  </button> zurück?</span>'
            + '</div>'
            + '<div class="cc-opt-info-body">'
            + '  <p>Gibt den tatsächlichen <strong>' + escapeHtml(meta.label) + '-Wert</strong> zurück, den der Nutzer eingegeben hat.</p>'
            + '  <div class="cc-opt-info-example">'
            + '    <span class="cc-opt-info-example-label">Beispiel:</span>'
            + '    <code>' + escapeHtml(meta.example) + '</code>'
            + '  </div>'
            + '  <p class="cc-opt-info-tip">💡 Nutze diese Variable in Nachrichten, Embeds und Bedingungen.</p>'
            + '</div>';

        const chip = card.querySelector('#cc-opt-info-chip');
        const copiedMsg    = document.createElement('span');
        copiedMsg.className = 'cc-opt-info-copied';
        copiedMsg.textContent = 'Kopiert!';
        card.querySelector('.cc-opt-info-header').appendChild(copiedMsg);

        chip.addEventListener('click', () => {
            navigator.clipboard.writeText('{option.' + (node.config.option_name || 'varname') + '}').catch(() => {});
            copiedMsg.classList.add('is-visible');
            setTimeout(() => copiedMsg.classList.remove('is-visible'), 1500);
        });

        // chipNameSpan is updated via live DOM query in the input event (see below)
        dynamicFields.appendChild(card);
    }

    // ── Select Menu info card ──────────────────────────────────────────────
    if (node.type === 'action.select_menu') {
        const varName = String(node.config.var_name || '').trim() || 'selected_option';
        const card = document.createElement('div');
        card.className = 'cc-opt-info-card';
        card.innerHTML = ''
            + '<div class="cc-opt-info-header">'
            + '  <span class="cc-opt-info-icon">ℹ</span>'
            + '  <span>Accessing Selected Options '
            + '    <button type="button" class="cc-opt-info-var-chip" id="cc-sm-info-chip" title="Klicken zum Kopieren">'
            + '      {<span class="cc-sm-chip-name">' + escapeHtml(varName) + '</span>}'
            + '    </button>'
            + '  </span>'
            + '</div>'
            + '<div class="cc-opt-info-body">'
            + '  <p>You can run attached Actions when a user selects one or more options from menu.</p>'
            + '  <p><code>{<span class="cc-sm-chip-name2">' + escapeHtml(varName) + '</span>}</code> — Returns the selected option.</p>'
            + '</div>';

        const chip = card.querySelector('#cc-sm-info-chip');
        chip.addEventListener('click', () => {
            const v = '{' + (node.config.var_name || 'selected_option') + '}';
            navigator.clipboard.writeText(v).catch(() => {});
        });

        dynamicFields.appendChild(card);
    }

    // ── Edit Component: custom properties panel ───────────────────────────────
    if (node.type === 'action.message.edit_component') {
        if (!Array.isArray(node.config.components)) node.config.components = [];

        // ── helpers ──────────────────────────────────────────────────────────
        const ec = node.config;

        function ecSave() { markDirty(); writeJsonField(); }

        function ecMakeRow(labelText, helpText) {
            const row = document.createElement('div');
            row.className = 'cc-prop-row';
            const lbl = document.createElement('label');
            lbl.textContent = labelText;
            if (helpText) {
                const h = document.createElement('span');
                h.className = 'cc-prop-help';
                h.textContent = helpText;
                lbl.appendChild(document.createElement('br'));
                lbl.appendChild(h);
            }
            row.appendChild(lbl);
            return row;
        }

        function ecInput(value, placeholder, maxlen, onchange) {
            const wrap = document.createElement('div');
            wrap.className = 'cc-prop-input-wrap';
            const inp = document.createElement('input');
            inp.type = 'text';
            inp.className = 'cc-prop-input';
            inp.value = value || '';
            if (placeholder) inp.placeholder = placeholder;
            if (maxlen)       inp.maxLength   = maxlen;
            inp.addEventListener('input', () => { onchange(inp.value); ecSave(); });
            wrap.appendChild(inp);
            return wrap;
        }

        function ecSelect(options, current, onchange) {
            const sel = document.createElement('select');
            sel.className = 'cc-prop-select';
            options.forEach(([val, label]) => {
                const opt = document.createElement('option');
                opt.value = val;
                opt.textContent = label;
                if (val === current) opt.selected = true;
                sel.appendChild(opt);
            });
            sel.addEventListener('change', () => { onchange(sel.value); ecSave(); });
            return sel;
        }

        function ecSwitch(checked, onchange) {
            const lbl = document.createElement('label');
            lbl.className = 'bh-toggle';
            const inp = document.createElement('input');
            inp.type = 'checkbox';
            inp.checked = !!checked;
            inp.addEventListener('change', () => { onchange(inp.checked); ecSave(); });
            const track = document.createElement('span');
            track.className = 'bh-toggle__track';
            const thumb = document.createElement('span');
            thumb.className = 'bh-toggle__thumb';
            lbl.appendChild(inp);
            lbl.appendChild(track);
            lbl.appendChild(thumb);
            return lbl;
        }

        // ── Target Message dropdown ───────────────────────────────────────────
        const targetRow = ecMakeRow('Target Message', 'Select a message action containing the components you want to edit.');
        const msgNodes = state.builder.nodes.filter(n => n.type === 'action.message.send_or_edit');
        const msgSel = document.createElement('select');
        msgSel.className = 'cc-prop-select';
        const emptyOpt = document.createElement('option');
        emptyOpt.value = '';
        emptyOpt.textContent = '— Select a message action —';
        msgSel.appendChild(emptyOpt);
        msgNodes.forEach(mn => {
            const opt = document.createElement('option');
            opt.value = mn.id;
            const label = mn.config.display_name || mn.config.var_name || mn.label || mn.id;
            opt.textContent = (label || mn.id) + ' — ' + mn.id.replace('node_', '').substring(0, 12);
            if (mn.id === ec.target_message_node_id) opt.selected = true;
            msgSel.appendChild(opt);
        });
        msgSel.addEventListener('change', () => {
            ec.target_message_node_id = msgSel.value;
            ec.components = [];
            ecSave();
            renderProperties(); // re-render
        });
        targetRow.appendChild(msgSel);

        const tipEl = document.createElement('div');
        tipEl.className = 'cc-prop-help';
        tipEl.style.marginTop = '6px';
        tipEl.textContent = 'Tip: You can add labels to your Advanced Messages to make them easier to identify here.';
        targetRow.appendChild(tipEl);
        dynamicFields.appendChild(targetRow);

        // ── Available Components ──────────────────────────────────────────────
        const targetNodeId = ec.target_message_node_id;
        const targetNode   = targetNodeId ? findNodeById(targetNodeId) : null;

        // Find button & select_menu nodes connected (via component port) to target
        const compNodes = [];
        if (targetNode) {
            state.builder.nodes.forEach(n => {
                if (n.type !== 'action.button' && n.type !== 'action.select_menu') return;
                const linked = state.builder.edges.some(e =>
                    (e.from_node_id === n.id && e.to_node_id === targetNodeId) ||
                    (e.to_node_id   === n.id && e.from_node_id === targetNodeId)
                );
                if (linked) compNodes.push(n);
            });
        }

        const availRow = ecMakeRow('Available Components', 'Click on components to select them for editing.');
        if (compNodes.length === 0) {
            const none = document.createElement('div');
            none.className = 'cc-prop-help';
            none.style.marginTop = '4px';
            none.textContent = targetNode
                ? 'No buttons or select menus connected to this message.'
                : 'Select a target message first.';
            availRow.appendChild(none);
        } else {
            const chips = document.createElement('div');
            chips.style.cssText = 'display:flex;flex-wrap:wrap;gap:6px;margin-top:6px';
            compNodes.forEach(cn => {
                const isBtn  = cn.type === 'action.button';
                const lbl    = cn.config.label || cn.config.placeholder || 'Unnamed ' + (isBtn ? 'Button' : 'Select Menu');
                const chip   = document.createElement('button');
                chip.type    = 'button';
                const alreadyAdded = ec.components.some(c => c.component_node_id === cn.id);
                chip.className = 'cc-ec-chip' + (alreadyAdded ? ' cc-ec-chip--active' : '');
                chip.textContent = (isBtn ? 'Button: ' : 'Basic Select Menu: ') + lbl;
                chip.addEventListener('click', () => {
                    if (!ec.components.some(c => c.component_node_id === cn.id)) {
                        ec.components.push({
                            component_node_id: cn.id,
                            component_type: isBtn ? 'button' : 'select_menu',
                            label:           cn.config.label       || '',
                            emoji:           cn.config.emoji       || '',
                            style:           cn.config.style       || 'blue',
                            custom_style:    '',
                            disabled:        'false',
                            remove:          'false',
                            component_order: false,
                            // select menu extras
                            options:         cn.config.options ? JSON.parse(JSON.stringify(cn.config.options)) : [],
                            max_values:      cn.config.max_values  || '1',
                            placeholder:     cn.config.placeholder || 'Choose an option...',
                        });
                        ecSave();
                        renderProperties();
                    }
                });
                chips.appendChild(chip);
            });
            availRow.appendChild(chips);
        }
        dynamicFields.appendChild(availRow);

        // ── Selected Components ───────────────────────────────────────────────
        const selHeader = ecMakeRow('Selected Components', 'Click to expand and edit settings for each component.');
        dynamicFields.appendChild(selHeader);

        if (ec.components.length === 0) {
            const none = document.createElement('div');
            none.className = 'cc-prop-help';
            none.style.marginTop = '4px';
            none.textContent = 'No components selected yet. Click components above to add them.';
            dynamicFields.appendChild(none);
        }

        ec.components.forEach((comp, idx) => {
            const isBtn = comp.component_type === 'button';
            const compNode = findNodeById(comp.component_node_id);
            const compLabel = comp.label || comp.placeholder || (isBtn ? 'Button ' + (idx+1) : 'Select Menu ' + (idx+1));

            const accordion = document.createElement('div');
            accordion.className = 'cc-ec-accordion';

            // Header row
            const head = document.createElement('div');
            head.className = 'cc-ec-accordion-head';

            const headLabel = document.createElement('span');
            headLabel.className = 'cc-ec-accordion-label';
            headLabel.textContent = (isBtn ? 'Button: ' : 'Basic Select Menu: ') + compLabel;
            head.appendChild(headLabel);

            const headRight = document.createElement('div');
            headRight.style.cssText = 'display:flex;align-items:center;gap:8px';

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'cc-ec-remove-btn';
            removeBtn.textContent = 'Remove';
            removeBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                ec.components.splice(idx, 1);
                ecSave();
                renderProperties();
            });
            headRight.appendChild(removeBtn);

            const chevron = document.createElement('span');
            chevron.className = 'cc-ec-chevron';
            chevron.textContent = '▾';
            headRight.appendChild(chevron);
            head.appendChild(headRight);

            const body = document.createElement('div');
            body.className = 'cc-ec-accordion-body';

            let isOpen = !!comp._open;
            body.style.display = isOpen ? '' : 'none';
            chevron.style.transform = isOpen ? 'rotate(180deg)' : '';

            head.addEventListener('click', () => {
                isOpen = !isOpen;
                comp._open = isOpen;
                body.style.display = isOpen ? '' : 'none';
                chevron.style.transform = isOpen ? 'rotate(180deg)' : '';
            });

            accordion.appendChild(head);

            // ── Button Settings ───────────────────────────────────────────────
            if (isBtn) {
                const sectionTitle = document.createElement('div');
                sectionTitle.className = 'cc-ec-section-title';
                sectionTitle.textContent = 'Button Settings';
                body.appendChild(sectionTitle);

                // Button Text
                const textRow = ecMakeRow('Button Text', 'The text of this button. All options and variables can be used.');
                textRow.appendChild(ecInput(comp.label, 'Button 1', 80, v => { comp.label = v; }));
                body.appendChild(textRow);

                // Emoji ID
                const emojiRow = ecMakeRow('Emoji ID', 'An optional emoji id to set for this button.');
                emojiRow.appendChild(ecInput(comp.emoji, 'Enter emoji ID', 64, v => { comp.emoji = v; }));
                body.appendChild(emojiRow);

                // Button Style
                const styleRow = ecMakeRow('Button Style', 'The style and color of this button.');
                styleRow.appendChild(ecSelect([
                    ['blue',    'Blue'],
                    ['gray',    'Gray'],
                    ['green',   'Green'],
                    ['red',     'Red'],
                    ['link',    'Link'],
                ], comp.style || 'blue', v => { comp.style = v; }));
                body.appendChild(styleRow);

                // Custom Button Style
                const custStyleRow = ecMakeRow('Custom Button Style', 'Optionally enter a custom style to override the selected style above. Leave blank to use the selected style above.');
                custStyleRow.appendChild(ecInput(comp.custom_style, 'Enter custom style (e.g. PRIMARY, SECONDARY)', 64, v => { comp.custom_style = v; }));
                body.appendChild(custStyleRow);

                // Disable Button
                const disRow = ecMakeRow('Disable Button', 'Set to \'true\' to disable this button, or \'false\' to enable it if it was previously disabled.');
                disRow.appendChild(ecInput(comp.disabled, 'false', 16, v => { comp.disabled = v; }));
                body.appendChild(disRow);

                // Remove Button
                const rmRow = ecMakeRow('Remove Button', 'Set to \'true\' to remove this button from the message. This cannot be undone.');
                rmRow.appendChild(ecInput(comp.remove, 'false', 16, v => { comp.remove = v; }));
                body.appendChild(rmRow);

                // Enable Component Ordering
                const orderRow = ecMakeRow('Enable Component Ordering', 'Order this button amongst other message components.');
                const orderWrap = document.createElement('div');
                orderWrap.style.cssText = 'display:flex;align-items:center;gap:8px;margin-top:4px';
                orderWrap.appendChild(ecSwitch(comp.component_order, v => { comp.component_order = v; }));
                orderRow.appendChild(orderWrap);
                body.appendChild(orderRow);

            // ── Select Menu Settings ──────────────────────────────────────────
            } else {
                const sectionTitle = document.createElement('div');
                sectionTitle.className = 'cc-ec-section-title';
                sectionTitle.textContent = 'Select Menu Settings';
                body.appendChild(sectionTitle);

                // Options list
                const optRow = ecMakeRow('Options', 'Add options for users to select from the menu. (Max 25 options)');
                if (!Array.isArray(comp.options)) comp.options = [];

                const optListWrap = document.createElement('div');
                optListWrap.style.cssText = 'display:flex;flex-direction:column;gap:4px;margin-top:4px';

                function renderOptList() {
                    optListWrap.innerHTML = '';
                    comp.options.forEach((opt, oi) => {
                        const row = document.createElement('div');
                        row.style.cssText = 'display:flex;gap:4px;align-items:center';
                        const sel = document.createElement('select');
                        sel.className = 'cc-prop-select';
                        sel.style.flex = '1';
                        const o = document.createElement('option');
                        o.value = opt.value || '';
                        o.textContent = opt.label || opt.value || 'Option ' + (oi+1);
                        sel.appendChild(o);
                        const delBtn = document.createElement('button');
                        delBtn.type = 'button';
                        delBtn.className = 'cc-ec-remove-btn';
                        delBtn.textContent = '✕';
                        delBtn.style.padding = '2px 6px';
                        delBtn.addEventListener('click', () => { comp.options.splice(oi, 1); ecSave(); renderOptList(); });
                        row.appendChild(sel);
                        row.appendChild(delBtn);
                        optListWrap.appendChild(row);
                    });
                }
                renderOptList();
                optRow.appendChild(optListWrap);

                const addOptBtn = document.createElement('button');
                addOptBtn.type = 'button';
                addOptBtn.className = 'cc-mb-primary-btn';
                addOptBtn.style.marginTop = '6px';
                addOptBtn.textContent = 'Add Option';
                addOptBtn.addEventListener('click', () => {
                    if (comp.options.length >= 25) return;
                    comp.options.push({ label: 'Option ' + (comp.options.length + 1), value: 'option_' + (comp.options.length + 1), description: '' });
                    ecSave();
                    renderOptList();
                });
                optRow.appendChild(addOptBtn);
                body.appendChild(optRow);

                // Enable Multiselect
                const msRow = ecMakeRow('Enable Multiselect', 'Allow users to select more than one option.');
                msRow.appendChild(ecSelect([
                    ['1',  'Single Select'],
                    ['25', 'Multi Select'],
                ], String(comp.max_values || '1'), v => { comp.max_values = v; }));
                body.appendChild(msRow);

                // Placeholder Text
                const phRow = ecMakeRow('Placeholder Text', 'The text shown when no option is selected. All options and variables can be used.');
                phRow.appendChild(ecInput(comp.placeholder, 'Choose an option...', 150, v => { comp.placeholder = v; }));
                body.appendChild(phRow);

                // Disable Menu
                const disRow = ecMakeRow('Disable Menu', 'Set to \'true\' to disable this menu.');
                disRow.appendChild(ecInput(comp.disabled, 'false', 16, v => { comp.disabled = v; }));
                body.appendChild(disRow);

                // Remove Menu
                const rmRow = ecMakeRow('Remove Menu', 'Set to \'true\' to remove this menu from the message. This cannot be undone.');
                const rmWrap = ecInput(comp.remove, 'true or false', 16, v => { comp.remove = v; });
                rmRow.appendChild(rmWrap);
                const rmHint = document.createElement('div');
                rmHint.className = 'cc-prop-help cc-prop-help--warn';
                rmHint.textContent = 'Enter \'true\' to remove this menu from the message. This cannot be undone.';
                rmRow.appendChild(rmHint);
                body.appendChild(rmRow);

                // Enable Component Ordering
                const orderRow = ecMakeRow('Enable Component Ordering', 'Order this select menu amongst other message components.');
                const orderWrap = document.createElement('div');
                orderWrap.style.cssText = 'display:flex;align-items:center;gap:8px;margin-top:4px';
                orderWrap.appendChild(ecSwitch(comp.component_order, v => { comp.component_order = v; }));
                orderRow.appendChild(orderWrap);
                body.appendChild(orderRow);
            }

            accordion.appendChild(body);
            dynamicFields.appendChild(accordion);
        });

        // Skip generic field rendering for this node type
        if (deleteNodeBtn) {
            deleteNodeBtn.disabled = false;
            deleteNodeBtn.textContent = 'Block löschen';
        }
        return;
    }

    def.fields.forEach((field) => {
        // message_builder_btn is rendered in the second pass below
        if (field.type === 'message_builder_btn') {
            return;
        }

        // ── permissions_select: dropdown + chip selector ─────────────────────
        if (field.type === 'permissions_select') {
            if (!Array.isArray(node.config[field.key])) {
                node.config[field.key] = [];
            }

            const DISCORD_PERMISSIONS = [
                { key: 'Administrator',              label: 'Administrator' },
                { key: 'ManageGuild',                label: 'Server verwalten' },
                { key: 'ManageRoles',                label: 'Rollen verwalten' },
                { key: 'ManageChannels',             label: 'Kanäle verwalten' },
                { key: 'KickMembers',                label: 'Mitglieder kicken' },
                { key: 'BanMembers',                 label: 'Mitglieder bannen' },
                { key: 'ModerateMembers',            label: 'Mitglieder timen out' },
                { key: 'ManageMessages',             label: 'Nachrichten verwalten' },
                { key: 'ManageNicknames',            label: 'Spitznamen verwalten' },
                { key: 'ManageWebhooks',             label: 'Webhooks verwalten' },
                { key: 'ManageEmojisAndStickers',    label: 'Emojis & Sticker verwalten' },
                { key: 'ViewAuditLog',               label: 'Audit-Log ansehen' },
                { key: 'MentionEveryone',            label: '@everyone erwähnen' },
                { key: 'MoveMembers',                label: 'Mitglieder verschieben' },
                { key: 'MuteMembers',                label: 'Mitglieder stummschalten' },
                { key: 'DeafenMembers',              label: 'Mitglieder taubschalten' },
            ];

            const wrapper = document.createElement('div');
            wrapper.className = 'cc-prop-row';

            const lbl = document.createElement('label');
            lbl.textContent = field.label;
            if (field.help) {
                const helpEl = document.createElement('span');
                helpEl.className = 'cc-prop-help';
                helpEl.textContent = field.help;
                lbl.appendChild(document.createElement('br'));
                lbl.appendChild(helpEl);
            }
            wrapper.appendChild(lbl);

            // Chips container
            const chipsWrap = document.createElement('div');
            chipsWrap.className = 'cc-perm-chips';

            function renderPermChips() {
                chipsWrap.innerHTML = '';
                const current = node.config[field.key];
                if (current.length === 0) {
                    const none = document.createElement('span');
                    none.className = 'cc-perm-chips-empty';
                    none.textContent = 'Keine Berechtigung ausgewählt';
                    chipsWrap.appendChild(none);
                    return;
                }
                current.forEach((permKey) => {
                    const meta = DISCORD_PERMISSIONS.find(p => p.key === permKey);
                    const chip = document.createElement('span');
                    chip.className = 'cc-perm-chip';
                    chip.textContent = meta ? meta.label : permKey;
                    const rm = document.createElement('button');
                    rm.type = 'button';
                    rm.className = 'cc-perm-chip-rm';
                    rm.textContent = '×';
                    rm.addEventListener('click', () => {
                        const idx = current.indexOf(permKey);
                        if (idx !== -1) current.splice(idx, 1);
                        markDirty();
                        writeJsonField();
                        renderPermChips();
                        updatePermDropdown();
                    });
                    chip.appendChild(rm);
                    chipsWrap.appendChild(chip);
                });
            }

            // Dropdown to add a permission
            const addSel = document.createElement('select');
            addSel.className = 'cc-prop-select';
            addSel.style.marginTop = '6px';

            function updatePermDropdown() {
                const current = node.config[field.key];
                addSel.innerHTML = '';
                const placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.textContent = '+ Berechtigung hinzufügen…';
                addSel.appendChild(placeholder);
                DISCORD_PERMISSIONS.forEach(({ key: permKey, label: permLabel }) => {
                    if (current.includes(permKey)) return; // already selected
                    const opt = document.createElement('option');
                    opt.value = permKey;
                    opt.textContent = permLabel;
                    addSel.appendChild(opt);
                });
            }

            addSel.addEventListener('change', () => {
                const val = addSel.value;
                if (!val) return;
                const current = node.config[field.key];
                if (!current.includes(val)) current.push(val);
                markDirty();
                writeJsonField();
                renderPermChips();
                updatePermDropdown();
                addSel.value = '';
            });

            renderPermChips();
            updatePermDropdown();
            wrapper.appendChild(chipsWrap);
            wrapper.appendChild(addSel);
            dynamicFields.appendChild(wrapper);
            return;
        }

        // ── options_list: dynamic select-menu options editor ───────────────
        if (field.type === 'options_list') {
            if (!Array.isArray(node.config[field.key]) || node.config[field.key].length === 0) {
                node.config[field.key] = [{ label: 'Option 1', value: 'option_1', description: '' }];
            }
            const maxItems = field.max_items || 25;

            const wrapper = document.createElement('div');
            wrapper.className = 'cc-prop-row';

            const lbl = document.createElement('label');
            lbl.textContent = field.label + ' (' + node.config[field.key].length + '/' + maxItems + ')';
            if (field.help) {
                const helpEl = document.createElement('span');
                helpEl.className = 'cc-prop-help';
                helpEl.textContent = field.help;
                lbl.appendChild(document.createElement('br'));
                lbl.appendChild(helpEl);
            }
            wrapper.appendChild(lbl);

            const listEl = document.createElement('div');
            listEl.className = 'cc-options-list';

            function renderOptionsList() {
                listEl.innerHTML = '';
                lbl.firstChild.textContent = field.label + ' (' + node.config[field.key].length + '/' + maxItems + ')';

                node.config[field.key].forEach((opt, idx) => {
                    const item = document.createElement('div');
                    item.className = 'cc-options-item';

                    const header = document.createElement('div');
                    header.className = 'cc-options-item-header';

                    const toggle = document.createElement('button');
                    toggle.type = 'button';
                    toggle.className = 'cc-options-item-toggle';
                    toggle.textContent = opt.label || ('Option ' + (idx + 1));

                    const chevron = document.createElement('span');
                    chevron.className = 'cc-options-item-chevron';
                    chevron.innerHTML = '<svg viewBox="0 0 16 16" width="12" height="12" fill="currentColor"><path d="M4.22 6.22a.75.75 0 0 1 1.06 0L8 8.94l2.72-2.72a.75.75 0 1 1 1.06 1.06l-3.25 3.25a.75.75 0 0 1-1.06 0L4.22 7.28a.75.75 0 0 1 0-1.06Z"/></svg>';

                    header.appendChild(toggle);
                    header.appendChild(chevron);

                    const body = document.createElement('div');
                    body.className = 'cc-options-item-body is-hidden';

                    toggle.addEventListener('click', () => {
                        body.classList.toggle('is-hidden');
                        chevron.classList.toggle('is-open');
                    });

                    [
                        { key: 'label',       placeholder: 'Label',       required: true  },
                        { key: 'value',       placeholder: 'Value',       required: true  },
                        { key: 'description', placeholder: 'Description', required: false },
                    ].forEach(({ key, placeholder, required }) => {
                        const row = document.createElement('div');
                        row.className = 'cc-options-sub-row';
                        const subLbl = document.createElement('label');
                        subLbl.textContent = placeholder + (required ? ' *' : '');
                        const inp = document.createElement('input');
                        inp.type = 'text';
                        inp.value = opt[key] || '';
                        inp.placeholder = placeholder;
                        inp.addEventListener('input', () => {
                            node.config[field.key][idx][key] = inp.value;
                            if (key === 'label') {
                                toggle.textContent = inp.value || ('Option ' + (idx + 1));
                            }
                            markDirty();
                            writeJsonField();
                        });
                        row.appendChild(subLbl);
                        row.appendChild(inp);
                        body.appendChild(row);
                    });

                    // Remove button
                    if (node.config[field.key].length > 1) {
                        const removeBtn = document.createElement('button');
                        removeBtn.type = 'button';
                        removeBtn.className = 'cc-options-remove-btn';
                        removeBtn.textContent = '✕ Remove';
                        removeBtn.addEventListener('click', () => {
                            node.config[field.key].splice(idx, 1);
                            markDirty();
                            writeJsonField();
                            renderOptionsList();
                        });
                        body.appendChild(removeBtn);
                    }

                    item.appendChild(header);
                    item.appendChild(body);
                    listEl.appendChild(item);
                });

                // Add Option button
                const addBtn = document.createElement('button');
                addBtn.type = 'button';
                addBtn.className = 'cc-options-add-btn';
                addBtn.textContent = '+ Add Option';
                addBtn.disabled = node.config[field.key].length >= maxItems;
                addBtn.addEventListener('click', () => {
                    const n = node.config[field.key].length + 1;
                    node.config[field.key].push({ label: 'Option ' + n, value: 'option_' + n, description: '' });
                    markDirty();
                    writeJsonField();
                    renderOptionsList();
                });
                listEl.appendChild(addBtn);
            }

            renderOptionsList();
            wrapper.appendChild(listEl);
            dynamicFields.appendChild(wrapper);
            return;
        }

        // ── Comparison conditions list ─────────────────────────────────────
        if (field.type === 'comparison_conditions') {
            if (!Array.isArray(node.config.conditions) || node.config.conditions.length === 0) {
                node.config.conditions = [{ base_value: '', operator: '==', comparison_value: '' }];
            }

            const OPERATORS = [
                { value: '<',                    label: 'Less than' },
                { value: '<=',                   label: 'Less than or equal to' },
                { value: '>',                    label: 'Greater than' },
                { value: '>=',                   label: 'Greater than or equal to' },
                { value: '==',                   label: 'Equal to' },
                { value: '!=',                   label: 'Not equal to' },
                { value: 'contains',             label: 'Contains' },
                { value: 'not_contains',         label: 'Does not contain' },
                { value: 'starts_with',          label: 'Starts with' },
                { value: 'ends_with',            label: 'Ends with' },
                { value: 'not_starts_with',      label: 'Does not start with' },
                { value: 'not_ends_with',        label: 'Does not end with' },
                { value: 'collection_contains',  label: 'Collection contains' },
                { value: 'collection_not_contains', label: 'Collection does not contain' },
            ];

            const editorWrap = document.createElement('div');
            editorWrap.className = 'cc-cond-editor';

            // ── Run mode ──────────────────────────────────────────────────
            const modeRow = document.createElement('div');
            modeRow.className = 'cc-prop-row cc-cond-mode-row';
            const modeLabel = document.createElement('label');
            modeLabel.textContent = 'Run multiple actions';
            const modeHint = document.createElement('div');
            modeHint.className = 'cc-prop-hint';
            modeHint.textContent = 'Each condition gets its own output port. Choose how the first match is determined.';
            const modeSel = document.createElement('select');
            [
                { value: 'first_match', label: 'First match wins (Switch / Case)' },
                { value: 'all_matches', label: 'Run all matching branches' },
            ].forEach(({ value, label }) => {
                const o = document.createElement('option');
                o.value = value;
                o.textContent = label;
                if ((node.config.run_mode || 'first_match') === value) {
                    o.selected = true;
                }
                modeSel.appendChild(o);
            });
            modeSel.addEventListener('change', () => {
                node.config.run_mode = modeSel.value;
                markDirty();
                writeJsonField();
            });
            modeRow.appendChild(modeLabel);
            modeRow.appendChild(modeHint);
            modeRow.appendChild(modeSel);
            editorWrap.appendChild(modeRow);

            // ── Condition list ─────────────────────────────────────────────
            const list = document.createElement('div');
            list.className = 'cc-cond-list';
            editorWrap.appendChild(list);

            const addBtn = document.createElement('button');
            addBtn.type = 'button';
            addBtn.className = 'cc-cond-add-btn';
            addBtn.textContent = '+ Add Condition';
            editorWrap.appendChild(addBtn);

            function saveAndRefresh() {
                markDirty();
                renderNodes();
                writeJsonField();
            }

            function buildCondRow(cond, idx) {
                const item = document.createElement('div');
                item.className = 'cc-cond-item';

                // Header: index + remove btn
                const head = document.createElement('div');
                head.className = 'cc-cond-item-head';
                const numEl = document.createElement('span');
                numEl.className = 'cc-cond-item-num';
                numEl.textContent = 'Condition ' + (idx + 1);
                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'cc-cond-item-remove';
                removeBtn.textContent = '✕';
                removeBtn.title = 'Remove condition';
                removeBtn.addEventListener('click', () => {
                    node.config.conditions.splice(idx, 1);
                    if (node.config.conditions.length === 0) {
                        node.config.conditions = [{ base_value: '', operator: '==', comparison_value: '' }];
                    }
                    rebuildList();
                    saveAndRefresh();
                });
                head.appendChild(numEl);
                head.appendChild(removeBtn);
                item.appendChild(head);

                // Base Value
                const baseRow = document.createElement('div');
                baseRow.className = 'cc-cond-field-row';
                const baseLbl = document.createElement('label');
                baseLbl.textContent = 'Base Value';
                const baseInput = document.createElement('input');
                baseInput.type = 'text';
                baseInput.value = cond.base_value || '';
                baseInput.placeholder = '{option.count} oder 42';
                baseInput.addEventListener('input', () => {
                    node.config.conditions[idx].base_value = baseInput.value;
                    saveAndRefresh();
                });
                baseRow.appendChild(baseLbl);
                baseRow.appendChild(baseInput);
                item.appendChild(baseRow);

                // Operator
                const opRow = document.createElement('div');
                opRow.className = 'cc-cond-field-row';
                const opLbl = document.createElement('label');
                opLbl.textContent = 'Comparison Type';
                const opSel = document.createElement('select');
                OPERATORS.forEach(({ value, label }) => {
                    const o = document.createElement('option');
                    o.value = value;
                    o.textContent = label;
                    if ((cond.operator || '==') === value) {
                        o.selected = true;
                    }
                    opSel.appendChild(o);
                });
                opSel.addEventListener('change', () => {
                    node.config.conditions[idx].operator = opSel.value;
                    saveAndRefresh();
                });
                opRow.appendChild(opLbl);
                opRow.appendChild(opSel);
                item.appendChild(opRow);

                // Comparison Value
                const valRow = document.createElement('div');
                valRow.className = 'cc-cond-field-row';
                const valLbl = document.createElement('label');
                valLbl.textContent = 'Comparison Value';
                const valInput = document.createElement('input');
                valInput.type = 'text';
                valInput.value = cond.comparison_value || '';
                valInput.placeholder = 'Vergleichswert …';
                valInput.addEventListener('input', () => {
                    node.config.conditions[idx].comparison_value = valInput.value;
                    saveAndRefresh();
                });
                valRow.appendChild(valLbl);
                valRow.appendChild(valInput);
                item.appendChild(valRow);

                return item;
            }

            function rebuildList() {
                list.innerHTML = '';
                (node.config.conditions || []).forEach((cond, idx) => {
                    list.appendChild(buildCondRow(cond, idx));
                });
                addBtn.disabled = (node.config.conditions || []).length >= 10;
                addBtn.textContent = addBtn.disabled
                    ? 'Max. 10 conditions'
                    : '+ Add Condition';
            }

            addBtn.addEventListener('click', () => {
                if ((node.config.conditions || []).length >= 10) {
                    return;
                }
                node.config.conditions.push({ base_value: '', operator: '==', comparison_value: '' });
                rebuildList();
                saveAndRefresh();
            });

            rebuildList();
            dynamicFields.appendChild(editorWrap);
            return;
        }

        const wrapper = document.createElement('div');
        wrapper.className = 'cc-prop-row';

        const label = document.createElement('label');
        label.textContent = field.label;
        if (field.help) {
            const helpEl = document.createElement('span');
            helpEl.className = 'cc-prop-help';
            helpEl.textContent = field.help;
            label.appendChild(document.createElement('br'));
            label.appendChild(helpEl);
        }
        wrapper.appendChild(label);

        // show_if: ['otherKey', ['val1', 'val2']] — hide when condition not met
        if (Array.isArray(field.show_if)) {
            const [watchKey, allowedVals] = field.show_if;
            const updateVisibility = () => {
                const v = String(node.config[watchKey] ?? '');
                wrapper.style.display = allowedVals.includes(v) ? '' : 'none';
            };
            updateVisibility();
            wrapper.dataset.ccShowIfWatch = watchKey;
            wrapper._ccShowIf = { watchKey, allowedVals, update: updateVisibility };
        }

        const currentRawValue = node.config[field.key];
        const currentValue = currentRawValue !== undefined ? String(currentRawValue) : '';

        if (field.type === 'switch') {
            const switchWrap = document.createElement('label');
            switchWrap.className = 'cc-switch';

            const input = document.createElement('input');
            input.type = 'checkbox';
            input.checked = currentRawValue === true || currentRawValue === 'true' || currentRawValue === 1 || currentRawValue === '1';

            const slider = document.createElement('span');
            slider.className = 'cc-slider';

            switchWrap.appendChild(input);
            switchWrap.appendChild(slider);
            wrapper.appendChild(switchWrap);

            input.addEventListener('change', () => {
                node.config[field.key] = input.checked;

                if (node.type === TRIGGER_TYPE) {
                    syncTriggerMetaFields(node);
                }

                markDirty();
                renderNodes();
                writeJsonField();
            });

            dynamicFields.appendChild(wrapper);
            return;
        }

        // ── event_select: grouped select of all Discord event types ──────────
        if (field.type === 'event_select') {
            const eventTypes = window.CebEventTypes || {};
            const eventLabels = window.CebEventLabels || {};
            const sel = document.createElement('select');
            const emptyOpt = document.createElement('option');
            emptyOpt.value = '';
            emptyOpt.textContent = '– Event wählen –';
            sel.appendChild(emptyOpt);
            Object.entries(eventTypes).forEach(([cat, events]) => {
                const grp = document.createElement('optgroup');
                grp.label = cat.charAt(0).toUpperCase() + cat.slice(1);
                (Array.isArray(events) ? events : []).forEach((evId) => {
                    const opt = document.createElement('option');
                    opt.value = evId;
                    opt.textContent = eventLabels[evId] || evId;
                    if (evId === currentValue) { opt.selected = true; }
                    grp.appendChild(opt);
                });
                sel.appendChild(grp);
            });
            sel.addEventListener('change', () => {
                node.config[field.key] = sel.value;
                syncTriggerMetaFields(node);
                dynamicFields.querySelectorAll('[data-cc-show-if-watch]').forEach((el) => {
                    if (el._ccShowIf && el._ccShowIf.watchKey === field.key) el._ccShowIf.update();
                });
                markDirty();
                renderNodes();
                writeJsonField();
            });
            wrapper.appendChild(sel);
            dynamicFields.appendChild(wrapper);
            return;
        }

        let input;

        if (field.type === 'textarea') {
            input = document.createElement('textarea');
            input.value = currentValue;
        } else if (field.type === 'select') {
            input = document.createElement('select');
            (field.options || []).forEach((optDef) => {
                const isObj = optDef !== null && typeof optDef === 'object';
                const optVal = isObj ? String(optDef.value ?? '') : String(optDef);
                const optLbl = isObj ? String(optDef.label ?? optVal) : String(optDef);
                const option = document.createElement('option');
                option.value = optVal;
                option.textContent = optLbl;
                if (optVal === currentValue) {
                    option.selected = true;
                }
                input.appendChild(option);
            });
        } else {
            input = document.createElement('input');
            input.type = field.type === 'number' ? 'number' : 'text';
            input.value = currentValue;
            if (field.type === 'number') {
                if (field.min !== undefined) input.min = String(field.min);
                if (field.max !== undefined) input.max = String(field.max);
            }
        }

        input.addEventListener('input', () => {
            node.config[field.key] = input.value;

            // Update any show_if-dependent sibling rows
            dynamicFields.querySelectorAll('[data-cc-show-if-watch]').forEach((el) => {
                if (el._ccShowIf && el._ccShowIf.watchKey === field.key) {
                    el._ccShowIf.update();
                }
            });

            if (node.type === TRIGGER_TYPE) {
                syncTriggerMetaFields(node);
            }

            // Live update usage chips for http request var_name
            if (node.type === 'action.http.request' && field.key === 'var_name') {
                const name = input.value.trim() || 'varname';
                dynamicFields.querySelectorAll('.cc-rb-chip[data-rb-var]').forEach((chip) => {
                    chip.textContent = '{' + name + '.' + chip.dataset.rbVar + '}';
                });
            }

            // Live update variable hint + info card chip for option-name fields
            if (def.category === 'option' && field.key === 'option_name') {
                const newName = input.value || 'varname';
                const chipSpan = dynamicFields.querySelector('.cc-opt-chip-name');
                if (chipSpan)  { chipSpan.textContent = newName; }
                const hintSpan = dynamicFields.querySelector('.cc-var-hint-name');
                if (hintSpan)  { hintSpan.textContent = newName; }
            }

            markDirty();
            renderNodes();
            writeJsonField();
        });

        if (field.type === 'select') {
            input.addEventListener('change', () => {
                node.config[field.key] = input.value;
                dynamicFields.querySelectorAll('[data-cc-show-if-watch]').forEach((el) => {
                    if (el._ccShowIf && el._ccShowIf.watchKey === field.key) el._ccShowIf.update();
                });
                if (node.type === TRIGGER_TYPE) syncTriggerMetaFields(node);
                markDirty(); renderNodes(); writeJsonField();
            });
        }

        wrapper.appendChild(input);

        // ── Variable usage hint for option nodes ───────────────────────────
        let varHintSpan = null;
        if (def.category === 'option' && field.key === 'option_name') {
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'cc-prop-var-chip';
            chip.title = 'Klicken zum Kopieren';

            const prefix = document.createTextNode('{option.');
            varHintSpan = document.createElement('span');
            varHintSpan.className = 'cc-var-hint-name';
            varHintSpan.textContent = String(node.config.option_name || 'varname');
            const suffix = document.createTextNode('}');
            chip.appendChild(prefix);
            chip.appendChild(varHintSpan);
            chip.appendChild(suffix);

            const copied = document.createElement('span');
            copied.className = 'cc-prop-var-copied';
            copied.textContent = 'Kopiert!';

            const hintWrap = document.createElement('div');
            hintWrap.className = 'cc-prop-var-hint';
            hintWrap.appendChild(chip);
            hintWrap.appendChild(copied);

            chip.addEventListener('click', () => {
                const val = '{option.' + (node.config.option_name || 'varname') + '}';
                navigator.clipboard.writeText(val).catch(() => {
                    const tmp = document.createElement('textarea');
                    tmp.value = val;
                    tmp.style.cssText = 'position:fixed;opacity:0';
                    document.body.appendChild(tmp);
                    tmp.select();
                    document.body.removeChild(tmp);
                });
                copied.classList.add('is-visible');
                setTimeout(() => copied.classList.remove('is-visible'), 1500);
            });

            wrapper.appendChild(hintWrap);
        }

        dynamicFields.appendChild(wrapper);
    });

    // Permission Options for slash command trigger
    if (node.type === 'trigger.slash') {
        renderSlashPermissions(node);
    }

    // Timed Event Trigger: show open-modal button in properties
    if (node.type === 'trigger.timed') {
        const wrap = document.createElement('div');
        wrap.className = 'cc-prop-row';
        const cfg = node.config || {};
        const hasConfig = !!(cfg.event_name || cfg.interval_seconds || cfg.interval_minutes || cfg.interval_hours || cfg.interval_days);
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'cc-mb-open-btn' + (hasConfig ? ' cc-mb-open-btn--filled' : '');
        btn.textContent = hasConfig ? '✎ Timed Event bearbeiten' : '+ Timed Event konfigurieren';
        btn.addEventListener('click', () => {
            if (window.CcTimedBuilder) window.CcTimedBuilder.open(node);
        });
        if (hasConfig) {
            const hint = document.createElement('div');
            hint.className = 'cc-mb-status-hint';
            const type = cfg.event_type === 'schedule' ? 'Schedule' : 'Interval';
            const name = cfg.event_name ? '"' + cfg.event_name + '"' : '';
            hint.textContent = [type, name].filter(Boolean).join(' · ');
            wrap.appendChild(btn);
            wrap.appendChild(hint);
        } else {
            wrap.appendChild(btn);
        }
        dynamicFields.appendChild(wrap);
    }

    // form_builder fields rendered after the main loop
    def.fields.forEach((field) => {
        if (field.type !== 'form_builder') return;

        const wrapper = document.createElement('div');
        wrapper.className = 'cc-prop-row';

        const lbl = document.createElement('label');
        lbl.textContent = field.label;
        if (field.help) {
            const helpEl = document.createElement('span');
            helpEl.className = 'cc-prop-help';
            helpEl.textContent = field.help;
            lbl.appendChild(document.createElement('br'));
            lbl.appendChild(helpEl);
        }
        wrapper.appendChild(lbl);

        const fieldCount = Array.isArray(node.config.fields) ? node.config.fields.length : 0;
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'cc-fb-open-btn' + (fieldCount > 0 ? ' cc-fb-open-btn--filled' : '');
        btn.textContent = fieldCount > 0 ? '✎ Open Form Builder' : 'Open Form Builder';
        btn.addEventListener('click', () => {
            if (window.CcFormBuilder) window.CcFormBuilder.open(node);
        });
        wrapper.appendChild(btn);
        dynamicFields.appendChild(wrapper);
    });

    // message_builder_btn fields rendered after the loop (need node reference)
    def.fields.forEach((field) => {
        if (field.type !== 'message_builder_btn') {
            return;
        }

        const wrapper = document.createElement('div');
        wrapper.className = 'cc-prop-row';

        const hasContent = !!(node.config.message_content || (Array.isArray(node.config.embeds) && node.config.embeds.length > 0));

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'cc-mb-open-btn' + (hasContent ? ' cc-mb-open-btn--filled' : '');
        btn.textContent = hasContent ? '✎ Nachricht bearbeiten' : '+ Nachricht erstellen';
        btn.addEventListener('click', () => {
            if (window.CcMsgBuilder) {
                window.CcMsgBuilder.open(node);
            }
        });

        // Status hint
        if (hasContent) {
            const hint = document.createElement('div');
            hint.className = 'cc-mb-status-hint';
            const embedCount = Array.isArray(node.config.embeds) ? node.config.embeds.length : 0;
            const parts = [];
            if (node.config.message_content) {
                parts.push(node.config.message_content.length + ' Zeichen Text');
            }
            if (embedCount > 0) {
                parts.push(embedCount + ' Embed' + (embedCount !== 1 ? 's' : ''));
            }
            hint.textContent = parts.join(' · ');
            wrapper.appendChild(btn);
            wrapper.appendChild(hint);
        } else {
            wrapper.appendChild(btn);
        }

        dynamicFields.appendChild(wrapper);
    });

    // request_builder_btn fields rendered after the loop
    def.fields.forEach((field) => {
        if (field.type !== 'request_builder_btn') return;

        const wrapper = document.createElement('div');
        wrapper.className = 'cc-prop-row';

        if (field.label) {
            const lbl = document.createElement('label');
            lbl.textContent = field.label;
            if (field.help) {
                const helpEl = document.createElement('span');
                helpEl.className = 'cc-prop-help';
                helpEl.textContent = field.help;
                lbl.appendChild(document.createElement('br'));
                lbl.appendChild(helpEl);
            }
            wrapper.appendChild(lbl);
        }

        const hasConfig = !!(node.config.url && String(node.config.url).trim() !== '');
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'cc-rb-open-btn' + (hasConfig ? ' cc-rb-open-btn--filled' : '');
        btn.textContent = hasConfig ? '✎ Edit Request' : 'Request Builder';
        btn.addEventListener('click', () => {
            if (window.CcRequestBuilder) window.CcRequestBuilder.open(node);
        });
        wrapper.appendChild(btn);

        if (hasConfig) {
            const hint = document.createElement('div');
            hint.className = 'cc-mb-status-hint';
            hint.textContent = (node.config.method || 'GET') + ' ' + node.config.url;
            wrapper.appendChild(hint);
        }

        // "Using Responses" hint
        const varName = String(node.config.var_name || '').trim() || 'varname';
        const usageDiv = document.createElement('div');
        usageDiv.className = 'cc-rb-usage';
        usageDiv.innerHTML =
            '<div class="cc-rb-usage-title">Using Responses</div>' +
            '<div class="cc-rb-usage-row"><span class="cc-rb-chip" data-rb-var="response">{' + varName + '.response}</span> <small>Response body</small></div>' +
            '<div class="cc-rb-usage-row"><span class="cc-rb-chip" data-rb-var="status">{' + varName + '.status}</span> <small>HTTP status code</small></div>' +
            '<div class="cc-rb-usage-row"><span class="cc-rb-chip" data-rb-var="statusText">{' + varName + '.statusText}</span> <small>Status text</small></div>';
        wrapper.appendChild(usageDiv);

        dynamicFields.appendChild(wrapper);
    });

    // emoji_picker fields rendered after the loop
    def.fields.forEach((field) => {
        if (field.type !== 'emoji_picker') return;

        if (!Array.isArray(node.config[field.key])) {
            node.config[field.key] = [];
        }

        const wrapper = document.createElement('div');
        wrapper.className = 'cc-prop-row';

        if (field.label) {
            const lbl = document.createElement('label');
            lbl.textContent = field.label;
            if (field.help) {
                const helpEl = document.createElement('span');
                helpEl.className = 'cc-prop-help';
                helpEl.textContent = field.help;
                lbl.appendChild(document.createElement('br'));
                lbl.appendChild(helpEl);
            }
            wrapper.appendChild(lbl);
        }

        // Chips container showing selected emojis
        const chipsEl = document.createElement('div');
        chipsEl.className = 'cc-ep-chips';

        function renderEpChips() {
            chipsEl.innerHTML = '';
            const emojis = node.config[field.key] || [];
            emojis.forEach((emoji, idx) => {
                const chip = document.createElement('span');
                chip.className = 'cc-ep-chip';

                // Detect custom emoji <:name:id> or <a:name:id>
                const customMatch = String(emoji).match(/^<a?:([^:]+):(\d+)>$/);
                if (customMatch) {
                    const emojiId = customMatch[2];
                    const img = document.createElement('img');
                    img.src = 'https://cdn.discordapp.com/emojis/' + emojiId + '.webp?size=20';
                    img.alt = customMatch[1];
                    img.className = 'cc-ep-chip-img';
                    chip.appendChild(img);
                } else {
                    chip.textContent = emoji;
                }

                const rmBtn = document.createElement('button');
                rmBtn.type = 'button';
                rmBtn.className = 'cc-ep-chip-rm';
                rmBtn.textContent = '×';
                rmBtn.addEventListener('click', () => {
                    node.config[field.key].splice(idx, 1);
                    markDirty();
                    writeJsonField();
                    renderEpChips();
                });
                chip.appendChild(rmBtn);
                chipsEl.appendChild(chip);
            });

            if (emojis.length === 0) {
                const placeholder = document.createElement('span');
                placeholder.className = 'cc-ep-placeholder';
                placeholder.textContent = 'No reactions selected';
                chipsEl.appendChild(placeholder);
            }
        }

        renderEpChips();
        wrapper.appendChild(chipsEl);

        const addBtn = document.createElement('button');
        addBtn.type = 'button';
        addBtn.className = 'cc-ep-open-btn';
        addBtn.textContent = '+ Add Reaction';
        addBtn.addEventListener('click', () => {
            if (window.CcEmojiPicker) {
                window.CcEmojiPicker.open(node, field.key, renderEpChips);
            }
        });
        wrapper.appendChild(addBtn);

        dynamicFields.appendChild(wrapper);
    });

    if (deleteNodeBtn) {
        if (isNativeNode(node)) {
            deleteNodeBtn.disabled = true;
            deleteNodeBtn.textContent = 'Nativer Block';
        } else {
            deleteNodeBtn.disabled = false;
            deleteNodeBtn.textContent = 'Block löschen';
        }
    }
}

    function renderNodePreview(node) {
        const config = node.config || {};

        switch (node.type) {
            case 'trigger.event': {
                const evType = config.event_type || '';
                const evLabels = window.CebEventLabels || {};
                return '<div class="cc-node-preview-line">' + escapeHtml(evLabels[evType] || evType || 'Event Trigger') + '</div>';
            }

            case 'trigger.timed': {
                const tType = config.event_type === 'schedule' ? 'Schedule' : 'Interval';
                const tName = config.event_name ? escapeHtml(config.event_name) : '';
                return '<div class="cc-node-preview-line">' + tType + '</div>'
                    + (tName ? '<div class="cc-node-preview-line">' + tName + '</div>' : '');
            }

            case 'trigger.slash':
                return ''
                    + '<div class="cc-node-preview-line">/' + escapeHtml(config.name || 'command') + '</div>'
                    + '<div class="cc-node-preview-line">' + escapeHtml((config.display_name || '').trim() !== '' ? config.display_name : (config.description || 'Startblock')) + '</div>';

            case 'option.text':
            case 'option.number':
            case 'option.user':
            case 'option.channel':
            case 'option.role':
            case 'option.choice':
            case 'option.attachment':
                return '';

            case 'action.message.send_or_edit':
                return ''
                    + '<div class="cc-node-preview-line">Mode: ' + escapeHtml(config.mode || 'send') + '</div>'
                    + '<div class="cc-node-preview-line">' + escapeHtml(truncate(config.message_content || 'Keine Nachricht gesetzt', 64)) + '</div>';

            case 'action.message.delete':
                return '<div class="cc-node-preview-line">Target: ' + escapeHtml(config.target || 'message') + '</div>';

            case 'action.flow.wait':
                return '<div class="cc-node-preview-line">' + escapeHtml(config.duration_ms || '1000') + ' ms</div>';

            case 'action.text.manipulate':
                return '<div class="cc-node-preview-line">' + escapeHtml(config.operation || 'uppercase') + '</div>';

            case 'action.utility.note':
                return '<div class="cc-node-preview-line">' + escapeHtml(truncate(config.note || 'Keine Notiz', 64)) + '</div>';

            case 'condition.if_else':
                return '<div class="cc-node-preview-line">'
                    + escapeHtml(truncate(config.left_value  || '…', 20)) + ' '
                    + escapeHtml(config.operator || '==')
                    + ' ' + escapeHtml(truncate(config.right_value || '…', 20))
                    + '</div>';

            case 'condition.comparison': {
                const conds = Array.isArray(config.conditions) ? config.conditions : [];
                const mode  = config.run_mode === 'any' ? 'ANY' : 'ALL';
                if (conds.length === 0) {
                    return '<div class="cc-node-preview-line">No conditions</div>';
                }
                const first = conds[0];
                let html = '<div class="cc-node-preview-line">'
                    + escapeHtml(truncate(first.base_value || '…', 14))
                    + ' ' + escapeHtml(first.operator || '==')
                    + ' ' + escapeHtml(truncate(first.comparison_value || '…', 14))
                    + '</div>';
                if (conds.length > 1) {
                    html += '<div class="cc-node-preview-line">+' + (conds.length - 1) + ' more · ' + mode + '</div>';
                }
                return html;
            }

            case 'utility.error_handler':
                return '<div class="cc-node-preview-line">' + escapeHtml(config.title || 'Error Handler') + '</div>';

            default:
                return '<div class="cc-node-preview-line">' + escapeHtml(node.type) + '</div>';
        }
    }

    function ensureSelectionBox() {
        if (state.selectionBoxEl instanceof HTMLElement) {
            return;
        }

        const box = document.createElement('div');
        box.className = 'cc-selection-box';
        box.style.display = 'none';
        canvas.appendChild(box);
        state.selectionBoxEl = box;
    }

    function updateSelectionBox() {
        if (!(state.selectionBoxEl instanceof HTMLElement)) {
            return;
        }

        const x1 = state.selectionStartWorldX;
        const y1 = state.selectionStartWorldY;
        const x2 = state.selectionCurrentWorldX;
        const y2 = state.selectionCurrentWorldY;

        const left = Math.min(x1, x2);
        const top = Math.min(y1, y2);
        const width = Math.abs(x2 - x1);
        const height = Math.abs(y2 - y1);

        state.selectionBoxEl.style.display = 'block';
        state.selectionBoxEl.style.left = (left * state.viewport.zoom + state.viewport.x) + 'px';
        state.selectionBoxEl.style.top = (top * state.viewport.zoom + state.viewport.y) + 'px';
        state.selectionBoxEl.style.width = (width * state.viewport.zoom) + 'px';
        state.selectionBoxEl.style.height = (height * state.viewport.zoom) + 'px';
    }

    function finishAreaSelection() {
        const x1 = Math.min(state.selectionStartWorldX, state.selectionCurrentWorldX);
        const y1 = Math.min(state.selectionStartWorldY, state.selectionCurrentWorldY);
        const x2 = Math.max(state.selectionStartWorldX, state.selectionCurrentWorldX);
        const y2 = Math.max(state.selectionStartWorldY, state.selectionCurrentWorldY);

        const selectedIds = state.builder.nodes
            .filter((node) => {
                const nodeLeft = node.x;
                const nodeTop = node.y;
                const nodeRight = node.x + 250;
                const nodeBottom = node.y + 90;

                return !(nodeRight < x1 || nodeLeft > x2 || nodeBottom < y1 || nodeTop > y2);
            })
            .map((node) => node.id);

        state.selectedNodeIds = selectedIds;
        state.selectedNodeId = selectedIds.length > 0 ? selectedIds[0] : null;
        state.isSelectingArea = false;

        if (state.selectionBoxEl instanceof HTMLElement) {
            state.selectionBoxEl.style.display = 'none';
        }

        renderAll();
    }

    function writeJsonField() {
        if (!builderJsonField) {
            return;
        }

        state.builder.viewport = {
            x: round2(state.viewport.x),
            y: round2(state.viewport.y),
            zoom: round2(state.viewport.zoom)
        };

        builderJsonField.value = JSON.stringify(state.builder, null, 2);
    }

    function markDirty(updateText = true) {
        state.dirty = true;
        if (updateText && saveStatus) {
            saveStatus.textContent = 'Unsaved changes';
        }
    }

    function applyViewport() {
        if (!world) {
            return;
        }

        world.style.zoom = state.viewport.zoom;
        world.style.transform = 'translate(' + Math.round(state.viewport.x / state.viewport.zoom) + 'px, ' + Math.round(state.viewport.y / state.viewport.zoom) + 'px)';

        // Dot grid follows the viewport
        const dotSize = 8 * state.viewport.zoom;
        canvas.style.backgroundSize = dotSize + 'px ' + dotSize + 'px';
        canvas.style.backgroundPosition = (state.viewport.x % dotSize) + 'px ' + (state.viewport.y % dotSize) + 'px';

        if (zoomResetBtn) {
            zoomResetBtn.textContent = Math.round(state.viewport.zoom * 100) + '%';
        }
    }

    function setZoom(newZoom, clientX, clientY) {
        const oldZoom = state.viewport.zoom;
        const nextZoom = clampZoom(newZoom);

        if (Math.abs(nextZoom - oldZoom) < 0.001) {
            return;
        }

        if (typeof clientX === 'number' && typeof clientY === 'number') {
            const rect = canvas.getBoundingClientRect();
            const screenX = clientX - rect.left;
            const screenY = clientY - rect.top;
            const worldX = (screenX - state.viewport.x) / oldZoom;
            const worldY = (screenY - state.viewport.y) / oldZoom;

            state.viewport.zoom = nextZoom;
            state.viewport.x = screenX - (worldX * nextZoom);
            state.viewport.y = screenY - (worldY * nextZoom);
        } else {
            state.viewport.zoom = nextZoom;
        }

        applyViewport();
        writeJsonField();
        markDirty();
    }

    function getWorldPoint(clientX, clientY) {
        const rect = canvas.getBoundingClientRect();
        const localX = clientX - rect.left;
        const localY = clientY - rect.top;

        return {
            x: (localX - state.viewport.x) / state.viewport.zoom,
            y: (localY - state.viewport.y) / state.viewport.zoom
        };
    }

    function getCanvasCenterWorldPoint() {
        const rect = canvas.getBoundingClientRect();
        return {
            x: ((rect.width / 2) - state.viewport.x) / state.viewport.zoom,
            y: ((rect.height / 2) - state.viewport.y) / state.viewport.zoom
        };
    }

    function getPortCenter(portEl) {
        const portRect = portEl.getBoundingClientRect();
        const canvasRect = canvas.getBoundingClientRect();

        return {
            x: (portRect.left - canvasRect.left - state.viewport.x + (portRect.width / 2)) / state.viewport.zoom,
            y: (portRect.top - canvasRect.top - state.viewport.y + (portRect.height / 2)) / state.viewport.zoom
        };
    }

    function centerOnNodes() {
        if (state.builder.nodes.length === 0) {
            state.viewport.x = 0;
            state.viewport.y = 0;
            applyViewport();
            return;
        }

        let minX = Infinity;
        let minY = Infinity;
        let maxX = -Infinity;
        let maxY = -Infinity;

        state.builder.nodes.forEach((node) => {
            minX = Math.min(minX, node.x);
            minY = Math.min(minY, node.y);
            maxX = Math.max(maxX, node.x + 250);
            maxY = Math.max(maxY, node.y + 90);
        });

        const boundsCenterX = (minX + maxX) / 2;
        const boundsCenterY = (minY + maxY) / 2;
        const rect = canvas.getBoundingClientRect();

        state.viewport.x = (rect.width / 2) - (boundsCenterX * state.viewport.zoom);
        state.viewport.y = (rect.height / 2) - (boundsCenterY * state.viewport.zoom);
        applyViewport();
    }

    function renderNodeBadge(def) {
        if (!isObject(def)) {
            return '?';
        }

        const badge = typeof def.badge === 'string' ? def.badge.trim() : '';
        if (badge !== '') {
            return badge;
        }

        const icon = typeof def.icon === 'string' ? def.icon.trim() : '';
        if (icon === '/' || icon.length === 1) {
            return icon;
        }

        const subtitle = typeof def.subtitle === 'string' ? def.subtitle.trim() : '';
        if (subtitle.startsWith('/')) {
            return '/';
        }

        const category = typeof def.category === 'string' ? def.category.trim() : '';
        if (category !== '') {
            return category.charAt(0).toUpperCase();
        }

        return '?';
    }

    function getDef(type) {
        if (blockDefs[type]) {
            return blockDefs[type];
        }
        const prefix = typeof type === 'string' && type.includes('.') ? type.split('.')[0] : '';
        return {
            category: prefix || 'action',
            badge: '?',
            label: type,
            subtitle: type,
            outputs: ['next'],
            input: true,
            defaults: {},
            fields: []
        };
    }

    function findNodeById(nodeId) {
        return state.builder.nodes.find((node) => node.id === nodeId) || null;
    }

    function edgeExists(fromNodeId, fromPort, toNodeId, toPort) {
        return state.builder.edges.some((edge) => {
            return edge.from_node_id === fromNodeId
                && edge.from_port === fromPort
                && edge.to_node_id === toNodeId
                && edge.to_port === toPort;
        });
    }

    function isPendingPort(nodeId, portName) {
        return !!state.pendingConnection
            && state.pendingConnection.fromNodeId === nodeId
            && state.pendingConnection.fromPort === portName;
    }

    function isNativeNodeType(type) {
        return NATIVE_NODE_TYPES.includes(String(type || ''));
    }

    function isNativeNode(node) {
        return !!node && isNativeNodeType(node.type);
    }

    function isProtectedNativeEdge(edge) {
        const fromNode = findNodeById(edge.from_node_id);
        const toNode = findNodeById(edge.to_node_id);

        if (!fromNode || !toNode) { return false; }

        // trigger → error_handler
        if (fromNode.type === TRIGGER_TYPE
            && edge.from_port === 'error'
            && toNode.type === 'utility.error_handler'
            && edge.to_port === 'in') {
            return true;
        }

        // send_or_edit_message → button / menu component nodes
        if (fromNode.type === 'action.message.send_or_edit'
            && (edge.from_port === 'button' || edge.from_port === 'menu')
            && edge.to_port === 'in') {
            return true;
        }

        return false;
    }

    function buildBezierPath(x1, y1, x2, y2) {
        const dx = Math.abs(x2 - x1);
        const curve = Math.max(60, dx * 0.45);

        return 'M ' + x1 + ' ' + y1
            + ' C ' + x1 + ' ' + (y1 + curve)
            + ', ' + x2 + ' ' + (y2 - curve)
            + ', ' + x2 + ' ' + y2;
    }

    function getBezierMidPoint(x1, y1, x2, y2) {
        const dx = Math.abs(x2 - x1);
        const curve = Math.max(60, dx * 0.45);
        const t = 0.5;
        const p0 = { x: x1, y: y1 };
        const p1 = { x: x1, y: y1 + curve };
        const p2 = { x: x2, y: y2 - curve };
        const p3 = { x: x2, y: y2 };

        const mt = 1 - t;

        return {
            x: (mt ** 3 * p0.x) + (3 * mt ** 2 * t * p1.x) + (3 * mt * t ** 2 * p2.x) + (t ** 3 * p3.x),
            y: (mt ** 3 * p0.y) + (3 * mt ** 2 * t * p1.y) + (3 * mt * t ** 2 * p2.y) + (t ** 3 * p3.y)
        };
    }

    function buildEdgeId(fromNodeId, fromPort, toNodeId, toPort) {
        return 'edge_' + fromNodeId + '_' + fromPort + '_' + toNodeId + '_' + toPort;
    }

    function clampZoom(value) {
        return Math.max(0.15, Math.min(2.2, value));
    }

    function toInt(value, fallback) {
        const parsed = Number.parseInt(String(value), 10);
        return Number.isFinite(parsed) ? parsed : fallback;
    }

    function toNumber(value, fallback) {
        const parsed = Number.parseFloat(String(value));
        return Number.isFinite(parsed) ? parsed : fallback;
    }

    function round2(value) {
        return Math.round(value * 100) / 100;
    }

    function isObject(value) {
        return value !== null && typeof value === 'object' && !Array.isArray(value);
    }

    function truncate(value, maxLength) {
        const stringValue = String(value || '');
        return stringValue.length > maxLength ? stringValue.slice(0, maxLength - 1) + '…' : stringValue;
    }

    function escapeHtml(value) {
        return String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function escapeAttr(value) {
        return escapeHtml(value);
    }

    function cssEscape(value) {
        if (window.CSS && typeof window.CSS.escape === 'function') {
            return window.CSS.escape(String(value));
        }
        return String(value).replace(/"/g, '\\"');
    }

    // ── Permission Options for trigger.slash ─────────────────────────────────

    const DISCORD_PERMISSIONS = [
        { key: 'ADMINISTRATOR',       label: 'Administrator' },
        { key: 'MANAGE_GUILD',        label: 'Manage Server' },
        { key: 'MANAGE_ROLES',        label: 'Manage Roles' },
        { key: 'MANAGE_CHANNELS',     label: 'Manage Channels' },
        { key: 'KICK_MEMBERS',        label: 'Kick Members' },
        { key: 'BAN_MEMBERS',         label: 'Ban Members' },
        { key: 'MANAGE_MESSAGES',     label: 'Manage Messages' },
        { key: 'SEND_MESSAGES',       label: 'Send Messages' },
        { key: 'VIEW_CHANNEL',        label: 'View Channels' },
        { key: 'MENTION_EVERYONE',    label: 'Mention Everyone' },
        { key: 'EMBED_LINKS',         label: 'Embed Links' },
        { key: 'ATTACH_FILES',        label: 'Attach Files' },
        { key: 'MUTE_MEMBERS',        label: 'Mute Members' },
        { key: 'DEAFEN_MEMBERS',      label: 'Deafen Members' },
        { key: 'MOVE_MEMBERS',        label: 'Move Members' },
        { key: 'USE_APPLICATION_COMMANDS', label: 'Use Slash Commands' },
        { key: 'MANAGE_NICKNAMES',    label: 'Manage Nicknames' },
        { key: 'CHANGE_NICKNAME',     label: 'Change Nickname' },
        { key: 'CREATE_INSTANT_INVITE', label: 'Create Invite' },
        { key: 'ADD_REACTIONS',       label: 'Add Reactions' },
    ];

    let _ccPermPicker       = null;
    let _ccPermPickerAnchor = null;
    const _ccPermCache      = {}; // endpoint → { roles/channels/guilds }

    function closeCcPermPicker() {
        if (_ccPermPicker && _ccPermPicker.parentNode) {
            _ccPermPicker.parentNode.removeChild(_ccPermPicker);
        }
        _ccPermPicker       = null;
        _ccPermPickerAnchor = null;
    }

    function openCcPermPicker(anchorEl, type, configKey, node, onAdded) {
        closeCcPermPicker();

        const botId = (window.CcBotMeta && typeof window.CcBotMeta.botId === 'number' && window.CcBotMeta.botId > 0)
            ? window.CcBotMeta.botId
            : 0;

        const popup = document.createElement('div');
        popup.className = 'cc-perm-picker';
        _ccPermPicker       = popup;
        _ccPermPickerAnchor = anchorEl;

        // Position: open downward by default, upward if not enough space below
        const rect       = anchorEl.getBoundingClientRect();
        const popupH     = 320; // max-height from CSS
        const spaceBelow = window.innerHeight - rect.bottom;
        const spaceAbove = rect.top;
        const left       = Math.min(Math.max(4, rect.left), window.innerWidth - 290);

        if (spaceBelow >= popupH || spaceBelow >= spaceAbove) {
            popup.style.top    = (rect.bottom + 4) + 'px';
            popup.style.bottom = 'auto';
        } else {
            popup.style.bottom = (window.innerHeight - rect.top + 4) + 'px';
            popup.style.top    = 'auto';
        }
        popup.style.left = left + 'px';

        if (botId <= 0 && type !== 'permissions') {
            document.body.appendChild(popup);
            popup.innerHTML = '<div class="cc-perm-picker__empty">Kein Bot zugewiesen.<br>Bitte Seite neu laden.</div>';
            return;
        }

        // Search box
        const searchWrap = document.createElement('div');
        searchWrap.className = 'cc-perm-picker__search';
        const searchInput = document.createElement('input');
        searchInput.type = 'text';
        searchInput.placeholder = 'Suchen…';
        searchWrap.appendChild(searchInput);
        popup.appendChild(searchWrap);

        // List
        const list = document.createElement('div');
        list.className = 'cc-perm-picker__list';
        popup.appendChild(list);

        // Manual ID entry (only for roles / channels)
        if (type !== 'permissions') {
            const manualWrap = document.createElement('div');
            manualWrap.className = 'cc-perm-picker__manual';
            const manualRow = document.createElement('div');
            manualRow.className = 'cc-perm-picker__manual-row';
            const manualInput = document.createElement('input');
            manualInput.type = 'text';
            manualInput.placeholder = 'ID manuell eingeben';
            const manualBtn = document.createElement('button');
            manualBtn.type = 'button';
            manualBtn.textContent = '+ Add';
            manualBtn.addEventListener('click', () => {
                const id = manualInput.value.trim();
                if (!id) return;
                addPermItem(configKey, node, { id, name: id }, onAdded);
                manualInput.value = '';
                closeCcPermPicker();
            });
            manualRow.appendChild(manualInput);
            manualRow.appendChild(manualBtn);
            manualWrap.appendChild(manualRow);
            popup.appendChild(manualWrap);
        }

        document.body.appendChild(popup);

        function renderList(items) {
            list.innerHTML = '';
            const query = searchInput.value.toLowerCase();
            const filtered = query
                ? items.filter(i => (i.label || i.name || '').toLowerCase().includes(query))
                : items;

            if (filtered.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'cc-perm-picker__empty';
                empty.textContent = query ? 'Keine Treffer' : 'Keine Einträge';
                list.appendChild(empty);
                return;
            }

            const existing = (node.config[configKey] || []).map(i => i.id || i.key);

            filtered.forEach(item => {
                const itemId = item.id || item.key;
                const isSelected = existing.includes(itemId);

                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'cc-perm-picker__item' + (isSelected ? ' is-selected' : '');

                if (type === 'roles' && item.color) {
                    const dot = document.createElement('span');
                    dot.className = 'cc-perm-picker__dot';
                    dot.style.background = '#' + item.color.toString(16).padStart(6, '0');
                    btn.appendChild(dot);
                }

                const lbl = document.createTextNode(item.label || item.name || itemId);
                btn.appendChild(lbl);

                if (isSelected) {
                    btn.title = 'Bereits hinzugefügt';
                } else {
                    btn.addEventListener('click', () => {
                        const entry = type === 'permissions'
                            ? { key: item.key, name: item.label }
                            : { id: item.id, name: item.name };
                        addPermItem(configKey, node, entry, onAdded);
                        closeCcPermPicker();
                    });
                }

                list.appendChild(btn);
            });
        }

        if (type === 'permissions') {
            renderList(DISCORD_PERMISSIONS);
            searchInput.addEventListener('input', () => renderList(DISCORD_PERMISSIONS));
        } else {
            // Loading state
            const loading = document.createElement('div');
            loading.className = 'cc-perm-picker__empty';
            loading.textContent = 'Lädt…';
            list.appendChild(loading);

            const endpoint = type === 'roles'
                ? '/api/v1/bot_guild_roles.php?bot_id=' + botId
                : '/api/v1/bot_guild_channels.php?bot_id=' + botId;

            function applyData(data) {
                    if (!data.ok) {
                        list.innerHTML = '<div class="cc-perm-picker__empty">Fehler: ' + escapeHtml(String(data.error || 'Unbekannt')) + '</div>';
                        return;
                    }

                    // Multiple guilds — show guild picker first
                    if (data.needs_guild && Array.isArray(data.guilds)) {
                        list.innerHTML = '';
                        const hint = document.createElement('div');
                        hint.className = 'cc-perm-picker__empty';
                        hint.textContent = 'Server wählen:';
                        list.appendChild(hint);

                        data.guilds.forEach(g => {
                            const gbtn = document.createElement('button');
                            gbtn.type = 'button';
                            gbtn.className = 'cc-perm-picker__item';
                            gbtn.textContent = g.name || g.id;
                            gbtn.addEventListener('click', () => {
                                list.innerHTML = '<div class="cc-perm-picker__empty">Lädt…</div>';
                                const subUrl = endpoint + '&guild_id=' + encodeURIComponent(g.id);
                                if (_ccPermCache[subUrl]) {
                                    const items = type === 'roles' ? (_ccPermCache[subUrl].roles || []) : (_ccPermCache[subUrl].channels || []);
                                    renderList(items);
                                    searchInput.addEventListener('input', () => renderList(items));
                                    return;
                                }
                                fetch(subUrl)
                                    .then(r2 => r2.json())
                                    .then(d2 => {
                                        _ccPermCache[subUrl] = d2;
                                        const items = type === 'roles' ? (d2.roles || []) : (d2.channels || []);
                                        renderList(items);
                                        searchInput.addEventListener('input', () => renderList(items));
                                    })
                                    .catch(() => {
                                        list.innerHTML = '<div class="cc-perm-picker__empty">Ladefehler</div>';
                                    });
                            });
                            list.appendChild(gbtn);
                        });
                        return;
                    }

                    const items = type === 'roles' ? (data.roles || []) : (data.channels || []);
                    renderList(items);
                    searchInput.addEventListener('input', () => renderList(items));
                }

            if (_ccPermCache[endpoint]) {
                applyData(_ccPermCache[endpoint]);
            } else {
                fetch(endpoint)
                    .then(r => r.text().then(text => ({ status: r.status, text })))
                    .then(({ status, text }) => {
                        let data;
                        try {
                            data = JSON.parse(text);
                        } catch (_) {
                            const preview = escapeHtml(text.substring(0, 200));
                            list.innerHTML = '<div class="cc-perm-picker__empty">PHP-Fehler (HTTP ' + status + '):<br><code style="font-size:10px;white-space:pre-wrap">' + preview + '</code></div>';
                            return;
                        }
                        if (data.ok && !data.needs_guild) {
                            _ccPermCache[endpoint] = data;
                        }
                        applyData(data);
                    })
                    .catch((err) => {
                        list.innerHTML = '<div class="cc-perm-picker__empty">Netzwerkfehler: ' + escapeHtml(String(err)) + '</div>';
                    });
            }
        }

        // Auto-focus search
        setTimeout(() => searchInput.focus(), 30);
    }

    function addPermItem(configKey, node, entry, onAdded) {
        if (!Array.isArray(node.config[configKey])) {
            node.config[configKey] = [];
        }
        const id = entry.id || entry.key;
        const alreadyIn = node.config[configKey].some(i => (i.id || i.key) === id);
        if (alreadyIn) return;
        node.config[configKey].push(entry);
        markDirty();
        writeJsonField();
        if (typeof onAdded === 'function') onAdded();
    }

    function renderSlashPermissions(node) {
        const sections = [
            {
                key:   'allowed_roles',
                title: 'Allowed Roles',
                desc:  'Users with these roles can use the command. Adding @everyone means anyone can use it.',
                type:  'roles',
            },
            {
                key:   'banned_roles',
                title: 'Banned Roles',
                desc:  "Users with these roles can't use the command.",
                type:  'roles',
            },
            {
                key:   'required_permissions',
                title: 'Required Permissions',
                desc:  'Users require these server permissions to use this command.',
                type:  'permissions',
            },
            {
                key:   'banned_permissions',
                title: 'Banned Permissions',
                desc:  'Users with these server permissions cannot use this command.',
                type:  'permissions',
            },
            {
                key:   'banned_channels',
                title: 'Banned Channels',
                desc:  'The command will not work in these channels.',
                type:  'channels',
            },
        ];

        const container = document.createElement('div');
        container.className = 'cc-perm-section cc-perm-accordion';

        // Accordion header (clickable, with chevron)
        const headingRow = document.createElement('div');
        headingRow.className = 'cc-perm-accordion__header';
        headingRow.style.marginTop = '18px';
        const headingLeft = document.createElement('div');
        const heading = document.createElement('div');
        heading.className = 'cc-perm-heading';
        heading.textContent = 'Permission Options';
        const headingDesc = document.createElement('div');
        headingDesc.className = 'cc-prop-hint';
        headingDesc.textContent = 'Set permissions for your command to restrict who can use it.';
        headingLeft.appendChild(heading);
        headingLeft.appendChild(headingDesc);
        const chevronEl = document.createElement('span');
        chevronEl.className = 'cc-perm-accordion__chevron';
        headingRow.appendChild(headingLeft);
        headingRow.appendChild(chevronEl);
        headingRow.addEventListener('click', () => container.classList.toggle('is-open'));
        container.appendChild(headingRow);

        // Accordion body (hidden until toggled)
        const accordionBody = document.createElement('div');
        accordionBody.className = 'cc-perm-accordion__body';

        sections.forEach(({ key, title, desc, type }) => {
            // Ensure array exists
            if (!Array.isArray(node.config[key])) {
                node.config[key] = [];
                if (key === 'allowed_roles') {
                    node.config[key] = [{ id: 'everyone', name: '@everyone' }];
                }
            }

            const box = document.createElement('div');
            box.className = 'cc-perm-box';

            const boxTitle = document.createElement('div');
            boxTitle.className = 'cc-perm-box-title';
            boxTitle.textContent = title;

            const boxDesc = document.createElement('div');
            boxDesc.className = 'cc-perm-box-desc';
            boxDesc.textContent = desc;

            const tagRow = document.createElement('div');
            tagRow.className = 'cc-perm-tags';

            const addBtn = document.createElement('button');
            addBtn.type = 'button';
            addBtn.className = 'cc-perm-add';
            addBtn.title = 'Hinzufügen';
            addBtn.textContent = '+';

            function rebuildTags() {
                tagRow.innerHTML = '';
                (node.config[key] || []).forEach((item, idx) => {
                    const tag = document.createElement('span');
                    tag.className = 'cc-perm-tag';
                    const label = item.name || item.label || item.id || item.key || '?';
                    tag.appendChild(document.createTextNode(label));

                    const rm = document.createElement('button');
                    rm.type = 'button';
                    rm.className = 'cc-perm-tag-rm';
                    rm.textContent = '×';
                    rm.title = 'Entfernen';
                    rm.addEventListener('click', () => {
                        node.config[key].splice(idx, 1);
                        markDirty();
                        writeJsonField();
                        rebuildTags();
                    });
                    tag.appendChild(rm);
                    tagRow.appendChild(tag);
                });
                tagRow.appendChild(addBtn);
            }

            addBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                if (_ccPermPickerAnchor === addBtn) {
                    closeCcPermPicker();
                    return;
                }
                openCcPermPicker(addBtn, type, key, node, rebuildTags);
            });

            rebuildTags();

            box.appendChild(boxTitle);
            box.appendChild(boxDesc);
            box.appendChild(tagRow);
            accordionBody.appendChild(box);
        });

        container.appendChild(accordionBody);
        dynamicFields.appendChild(container);
    }

    // Close picker when clicking outside
    document.addEventListener('click', () => closeCcPermPicker());

    // ── Event mode: meta panel inputs (name, description) ────────────────────
    function registerEventMeta() {
        const nameInput = document.getElementById('cc-event-meta-name');
        const descInput = document.getElementById('cc-event-meta-desc');

        if (nameInput instanceof HTMLInputElement && hiddenCommandNameInput instanceof HTMLInputElement) {
            nameInput.addEventListener('input', () => {
                hiddenCommandNameInput.value = nameInput.value;
                markDirty();
                writeJsonField();
            });
        }

        if (descInput instanceof HTMLInputElement && hiddenDescriptionInput instanceof HTMLInputElement) {
            descInput.addEventListener('input', () => {
                hiddenDescriptionInput.value = descInput.value;
                markDirty();
                writeJsonField();
            });
        }
    }

    // Expose hooks for the Message Builder modal
    window._ccBuilderMarkDirty  = () => markDirty();
    window._ccBuilderWriteJson  = () => writeJsonField();
    window._ccBuilderRenderNodes = () => { renderNodes(); renderProperties(); };
    window._ccRenderProperties  = (node) => { state.selectedNodeId = node.id; renderProperties(); };
    window.__ccBuilderState     = state; // exposed for variable picker
})();

// ═══════════════════════════════════════════════════════════════ Message Builder
(function () {
    'use strict';

    const overlay   = document.getElementById('cc-msg-builder-overlay');
    const saveBtn   = document.getElementById('cc-mb-save-btn');
    const closeBtn  = document.getElementById('cc-mb-close-btn');
    const contentTA = document.getElementById('cc-mb-content');
    const countEl   = document.getElementById('cc-mb-content-count');
    const embedsCountEl = document.getElementById('cc-mb-embeds-count');
    const embedsList    = document.getElementById('cc-mb-embeds-list');
    const addEmbedBtn   = document.getElementById('cc-mb-add-embed-btn');
    const clearEmbedsBtn = document.getElementById('cc-mb-clear-embeds-btn');
    const previewText   = document.getElementById('cc-mb-preview-text');
    const previewEmbeds = document.getElementById('cc-mb-preview-embeds');
    const embedTpl      = document.getElementById('cc-mb-embed-tpl');
    const fieldTpl      = document.getElementById('cc-mb-field-tpl');
    const previewTimeEl  = document.getElementById('cc-mb-preview-time');
    const botNameEl      = document.getElementById('cc-mb-bot-name');
    const botAvatarImg   = document.getElementById('cc-mb-bot-avatar-img');
    const botAvatarFb    = document.getElementById('cc-mb-bot-avatar-fallback');
    const responseTypeSel      = document.getElementById('cc-mb-response-type');
    const condSpecificChannel  = document.getElementById('cc-mb-cond-specific-channel');
    const condChannelOption    = document.getElementById('cc-mb-cond-channel-option');
    const condDmUserOption     = document.getElementById('cc-mb-cond-dm-user-option');
    const condDmSpecificUser   = document.getElementById('cc-mb-cond-dm-specific-user');
    const condEditAction       = document.getElementById('cc-mb-cond-edit-action');
    const inputChannelId       = document.getElementById('cc-mb-target-channel-id');
    const inputOptionName      = document.getElementById('cc-mb-target-option-name');
    const inputDmOptionName    = document.getElementById('cc-mb-target-dm-option-name');
    const inputUserId          = document.getElementById('cc-mb-target-user-id');
    const inputEditTargetVar   = document.getElementById('cc-mb-edit-target-var');

    if (!overlay || !embedTpl || !fieldTpl) {
        return;
    }

    let currentNode = null;

    // ── bot meta ──────────────────────────────────────────────────────────────

    (function initBotMeta() {
        const meta = window.CcBotMeta || {};
        const name = meta.name || 'Bot';
        const userId = String(meta.userId || '');

        if (botNameEl) {
            botNameEl.textContent = name;
        }
        if (botAvatarFb) {
            botAvatarFb.textContent = name.charAt(0).toUpperCase();
        }

        // Build Discord default avatar URL from snowflake
        if (userId && botAvatarImg) {
            let avatarIndex = 0;
            try {
                avatarIndex = Number(BigInt(userId) >> 22n) % 5;
            } catch (_) {
                avatarIndex = parseInt(userId, 10) % 5 || 0;
            }
            const avatarUrl = 'https://cdn.discordapp.com/embed/avatars/' + avatarIndex + '.png';
            botAvatarImg.src = avatarUrl;
            botAvatarImg.style.display = 'block';
            if (botAvatarFb) {
                botAvatarFb.style.display = 'none';
            }
            botAvatarImg.onerror = () => {
                botAvatarImg.style.display = 'none';
                if (botAvatarFb) {
                    botAvatarFb.style.display = 'flex';
                }
            };
        }
    })();

    // ── helpers ───────────────────────────────────────────────────────────────

    function esc(v) {
        return String(v || '')
            .replaceAll('&', '&amp;').replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;').replaceAll('"', '&quot;');
    }

    function now() {
        const d = new Date();
        const h = d.getHours(), m = d.getMinutes();
        const ampm = h >= 12 ? 'PM' : 'AM';
        const hh = ((h % 12) || 12);
        return 'Today at ' + hh + ':' + String(m).padStart(2, '0') + ' ' + ampm;
    }

    function defaultEmbed() {
        return {
            color: '#5865F2',
            author_name: '',
            author_icon_url: '',
            title: '',
            url: '',
            description: '',
            thumbnail_url: '',
            image_url: '',
            fields: [],
            footer_text: '',
            timestamp: false,
        };
    }

    // ── read state from DOM ───────────────────────────────────────────────────

    function readEmbeds() {
        const items = embedsList.querySelectorAll('.cc-mb-embed-item');
        return Array.from(items).map((item) => {
            const embed = defaultEmbed();
            const colorPicker = item.querySelector('.cc-mb-color-picker');
            if (colorPicker) {
                embed.color = colorPicker.value;
            }
            item.querySelectorAll('[data-field]').forEach((el) => {
                const key = el.dataset.field;
                if (el.type === 'checkbox') {
                    embed[key] = el.checked;
                } else {
                    embed[key] = el.value;
                }
            });
            // sub-fields
            const subItems = item.querySelectorAll('.cc-mb-subfield-item');
            embed.fields = Array.from(subItems).map((sub) => {
                const f = { name: '', value: '', inline: false };
                sub.querySelectorAll('[data-subfield]').forEach((el) => {
                    const k = el.dataset.subfield;
                    if (el.type === 'checkbox') {
                        f[k] = el.checked;
                    } else {
                        f[k] = el.value;
                    }
                });
                return f;
            });
            return embed;
        });
    }

    // ── render preview ────────────────────────────────────────────────────────

    function renderPreview() {
        if (previewTimeEl) {
            previewTimeEl.textContent = now();
        }
        const text = contentTA ? contentTA.value : '';
        if (previewText) {
            previewText.innerHTML = text !== ''
                ? esc(text).replace(/\n/g, '<br>')
                : '';
        }

        if (!previewEmbeds) {
            return;
        }

        const embeds = readEmbeds();
        previewEmbeds.innerHTML = '';

        embeds.forEach((embed) => {
            const colorHex = embed.color || '#5865F2';

            let html = '<div class="cc-mb-discord-embed" style="border-left-color:' + esc(colorHex) + '">';

            // author
            if (embed.author_name) {
                html += '<div class="cc-mb-discord-embed-author">';
                if (embed.author_icon_url) {
                    html += '<img src="' + esc(embed.author_icon_url) + '" class="cc-mb-discord-embed-author-icon" alt="">';
                }
                html += esc(embed.author_name) + '</div>';
            }

            // title
            if (embed.title) {
                const titleTag = embed.url
                    ? '<a href="' + esc(embed.url) + '" class="cc-mb-discord-embed-title-link">' + esc(embed.title) + '</a>'
                    : esc(embed.title);
                html += '<div class="cc-mb-discord-embed-title">' + titleTag + '</div>';
            }

            // description
            if (embed.description) {
                html += '<div class="cc-mb-discord-embed-desc">' + esc(embed.description).replace(/\n/g, '<br>') + '</div>';
            }

            // fields
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

            // image
            if (embed.image_url) {
                html += '<img src="' + esc(embed.image_url) + '" class="cc-mb-discord-embed-image" alt="">';
            }

            // thumbnail (absolute inside embed)
            if (embed.thumbnail_url) {
                html += '<img src="' + esc(embed.thumbnail_url) + '" class="cc-mb-discord-embed-thumbnail" alt="">';
            }

            // footer + timestamp
            const hasFooter = embed.footer_text || embed.timestamp;
            if (hasFooter) {
                html += '<div class="cc-mb-discord-embed-footer">';
                if (embed.footer_text) {
                    html += '<span>' + esc(embed.footer_text) + '</span>';
                }
                if (embed.timestamp) {
                    html += '<span>' + now() + '</span>';
                }
                html += '</div>';
            }

            html += '</div>';
            previewEmbeds.insertAdjacentHTML('beforeend', html);
        });
    }

    // ── build embed DOM item ──────────────────────────────────────────────────

    function buildEmbedItem(embed, idx) {
        const clone = embedTpl.content.cloneNode(true);
        const item  = clone.querySelector('.cc-mb-embed-item');

        item.dataset.embedIdx = idx;
        const numEl = item.querySelector('.cc-mb-embed-num');
        if (numEl) {
            numEl.textContent = idx + 1;
        }

        const colorPicker = item.querySelector('.cc-mb-color-picker');
        if (colorPicker) {
            colorPicker.value = embed.color || '#5865F2';
            colorPicker.addEventListener('input', updateCountsAndPreview);
        }

        // text fields
        item.querySelectorAll('[data-field]').forEach((el) => {
            const key = el.dataset.field;
            if (el.type === 'checkbox') {
                el.checked = !!embed[key];
            } else {
                el.value = embed[key] != null ? embed[key] : '';
            }
            el.addEventListener('input', updateCountsAndPreview);
            el.addEventListener('change', updateCountsAndPreview);
        });

        // delete embed
        const delBtn = item.querySelector('.cc-mb-embed-del-btn');
        if (delBtn) {
            delBtn.addEventListener('click', () => {
                item.remove();
                updateCountsAndPreview();
            });
        }

        // add field btn
        const addFieldBtn = item.querySelector('.cc-mb-add-field-btn');
        const subList = item.querySelector('.cc-mb-subfields-list');
        const fieldsCountEl = item.querySelector('.cc-mb-fields-count');

        function updateFieldsCount() {
            if (fieldsCountEl) {
                fieldsCountEl.textContent = subList ? subList.querySelectorAll('.cc-mb-subfield-item').length : 0;
            }
        }

        if (addFieldBtn && subList) {
            addFieldBtn.addEventListener('click', () => {
                const count = subList.querySelectorAll('.cc-mb-subfield-item').length;
                if (count >= 25) {
                    return;
                }
                const fieldClone = fieldTpl.content.cloneNode(true);
                const fieldItem = fieldClone.querySelector('.cc-mb-subfield-item');
                fieldItem.querySelectorAll('[data-subfield]').forEach((el) => {
                    el.addEventListener('input', updateCountsAndPreview);
                    el.addEventListener('change', updateCountsAndPreview);
                });
                const delFieldBtn = fieldItem.querySelector('.cc-mb-field-del-btn');
                if (delFieldBtn) {
                    delFieldBtn.addEventListener('click', () => {
                        fieldItem.remove();
                        updateFieldsCount();
                        updateCountsAndPreview();
                    });
                }
                subList.appendChild(fieldItem);
                updateFieldsCount();
                updateCountsAndPreview();
            });
        }

        // populate existing fields
        if (Array.isArray(embed.fields) && subList) {
            embed.fields.forEach((f) => {
                const fieldClone = fieldTpl.content.cloneNode(true);
                const fieldItem = fieldClone.querySelector('.cc-mb-subfield-item');
                fieldItem.querySelectorAll('[data-subfield]').forEach((el) => {
                    const k = el.dataset.subfield;
                    if (el.type === 'checkbox') {
                        el.checked = !!f[k];
                    } else {
                        el.value = f[k] != null ? f[k] : '';
                    }
                    el.addEventListener('input', updateCountsAndPreview);
                    el.addEventListener('change', updateCountsAndPreview);
                });
                const delFieldBtn = fieldItem.querySelector('.cc-mb-field-del-btn');
                if (delFieldBtn) {
                    delFieldBtn.addEventListener('click', () => {
                        fieldItem.remove();
                        updateFieldsCount();
                        updateCountsAndPreview();
                    });
                }
                subList.appendChild(fieldItem);
            });
            updateFieldsCount();
        }

        return item;
    }

    // ── counts + preview refresh ──────────────────────────────────────────────

    function updateCountsAndPreview() {
        if (countEl && contentTA) {
            countEl.textContent = contentTA.value.length;
        }
        const embedCount = embedsList ? embedsList.querySelectorAll('.cc-mb-embed-item').length : 0;
        if (embedsCountEl) {
            embedsCountEl.textContent = embedCount;
        }
        if (addEmbedBtn) {
            addEmbedBtn.disabled = embedCount >= 10;
        }
        renderPreview();
    }

    // ── open / close ──────────────────────────────────────────────────────────

    // ── response type conditional visibility ─────────────────────────────────

    const RESPONSE_TYPE_CONDS = {
        specific_channel: condSpecificChannel,
        channel_option:   condChannelOption,
        dm_user_option:   condDmUserOption,
        dm_specific_user: condDmSpecificUser,
        edit_action:      condEditAction,
    };

    function updateResponseTypeConds() {
        const val = responseTypeSel ? responseTypeSel.value : 'reply';
        Object.entries(RESPONSE_TYPE_CONDS).forEach(([key, el]) => {
            if (el) el.style.display = (val === key) ? 'block' : 'none';
        });
    }

    if (responseTypeSel) {
        responseTypeSel.addEventListener('change', updateResponseTypeConds);
    }

    function open(node) {
        if (!node) {
            return;
        }
        currentNode = node;

        if (!node.config) {
            node.config = {};
        }

        // populate response type
        if (responseTypeSel) {
            responseTypeSel.value = node.config.response_type || 'reply';
        }
        if (inputChannelId)      inputChannelId.value      = node.config.target_channel_id      || '';
        if (inputOptionName)     inputOptionName.value     = node.config.target_option_name     || '';
        if (inputDmOptionName)   inputDmOptionName.value   = node.config.target_dm_option_name  || '';
        if (inputUserId)         inputUserId.value         = node.config.target_user_id         || '';
        if (inputEditTargetVar)  inputEditTargetVar.value  = node.config.edit_target_var        || '';
        updateResponseTypeConds();

        // populate content
        if (contentTA) {
            contentTA.value = node.config.message_content || '';
        }

        // populate embeds
        if (embedsList) {
            embedsList.innerHTML = '';
            const embeds = Array.isArray(node.config.embeds) ? node.config.embeds : [];
            embeds.forEach((embed, idx) => {
                embedsList.appendChild(buildEmbedItem(embed, idx));
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
        if (!currentNode) {
            close();
            return;
        }
        currentNode.config.response_type           = responseTypeSel    ? responseTypeSel.value              : 'reply';
        currentNode.config.target_channel_id       = inputChannelId    ? inputChannelId.value.trim()         : '';
        currentNode.config.target_option_name      = inputOptionName   ? inputOptionName.value.trim()        : '';
        currentNode.config.target_dm_option_name   = inputDmOptionName ? inputDmOptionName.value.trim()      : '';
        currentNode.config.target_user_id          = inputUserId       ? inputUserId.value.trim()            : '';
        currentNode.config.edit_target_var         = inputEditTargetVar ? inputEditTargetVar.value.trim()    : '';
        currentNode.config.message_content         = contentTA ? contentTA.value : '';
        currentNode.config.embeds                  = readEmbeds();

        // notify the main builder
        if (window._ccBuilderMarkDirty) {
            window._ccBuilderMarkDirty();
        }
        if (window._ccBuilderWriteJson) {
            window._ccBuilderWriteJson();
        }
        if (window._ccBuilderRenderNodes) {
            window._ccBuilderRenderNodes();
        }

        close();
    }

    // ── event listeners ───────────────────────────────────────────────────────

    if (closeBtn) {
        closeBtn.addEventListener('click', close);
    }

    if (saveBtn) {
        saveBtn.addEventListener('click', save);
    }

    if (addEmbedBtn) {
        addEmbedBtn.addEventListener('click', () => {
            const count = embedsList.querySelectorAll('.cc-mb-embed-item').length;
            if (count >= 10) {
                return;
            }
            const idx = count;
            embedsList.appendChild(buildEmbedItem(defaultEmbed(), idx));
            updateCountsAndPreview();
        });
    }

    if (clearEmbedsBtn) {
        clearEmbedsBtn.addEventListener('click', () => {
            if (embedsList) {
                embedsList.innerHTML = '';
            }
            updateCountsAndPreview();
        });
    }

    if (contentTA) {
        contentTA.addEventListener('input', updateCountsAndPreview);
    }

    // Close on overlay backdrop click
    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) {
            close();
        }
    });

    // Close on Escape
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && overlay.classList.contains('is-open')) {
            close();
        }
    });

    // ── Variable picker ────────────────────────────────────────────────────
    const varBtn    = document.getElementById('cc-mb-variables-btn');
    let varPicker   = null;          // created lazily
    let lastFocused = null;          // last textarea/input focused inside the modal

    // Track focus so we know where to insert
    overlay.addEventListener('focusin', (e) => {
        if (e.target instanceof HTMLInputElement || e.target instanceof HTMLTextAreaElement) {
            lastFocused = e.target;
        }
    });

    function buildVarPicker() {
        const el = document.createElement('div');
        el.className = 'cc-mb-var-picker';

        function section(title, vars) {
            const hd = document.createElement('div');
            hd.className = 'cc-mb-var-section-title';
            hd.textContent = title;
            el.appendChild(hd);
            vars.forEach(({ label, value }) => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'cc-mb-var-item';
                btn.dataset.varValue = value;
                const nameSpan = document.createElement('span');
                nameSpan.className = 'cc-mb-var-label';
                nameSpan.textContent = label;
                const codeSpan = document.createElement('code');
                codeSpan.className = 'cc-mb-var-code';
                codeSpan.textContent = value;
                btn.appendChild(nameSpan);
                btn.appendChild(codeSpan);
                btn.addEventListener('mousedown', (ev) => {
                    ev.preventDefault(); // don't steal focus
                    insertVariable(value);
                    closePicker();
                });
                el.appendChild(btn);
            });
        }

        // Collect option + local variable nodes from the canvas
        const optionVars   = [];
        const localVarList = [];
        const formVarList  = [];
        const optionTypes  = { 'option.text': 'Text', 'option.number': 'Zahl', 'option.user': 'User',
                               'option.channel': 'Kanal', 'option.role': 'Rolle', 'option.choice': 'Auswahl',
                               'option.attachment': 'Datei' };
        const allNodes = window.__ccBuilderState && Array.isArray(window.__ccBuilderState.builder && window.__ccBuilderState.builder.nodes)
            ? window.__ccBuilderState.builder.nodes
            : [];
        allNodes.forEach((n) => {
            if (n.config && optionTypes[n.type]) {
                const nName = String(n.config.option_name || '');
                if (nName) {
                    optionVars.push({ label: optionTypes[n.type] + ': ' + nName, value: '{option.' + nName + '}' });
                }
            }
            if (n.type === 'variable.local.set' && n.config && n.config.var_key) {
                const k = String(n.config.var_key);
                if (k && !localVarList.find(v => v.value === '{local.' + k + '}')) {
                    localVarList.push({ label: k, value: '{local.' + k + '}' });
                }
            }
            if (n.type === 'action.message.send_form' && n.config) {
                const fName  = String(n.config.form_name || 'form').trim();
                const fields = Array.isArray(n.config.fields) ? n.config.fields : [];
                fields.forEach((f, i) => {
                    if (f.hidden === 'true') return;
                    const varStr = '{' + fName + '.Input.' + (i + 1) + '.InputLabel}';
                    formVarList.push({ label: fName + ' → ' + (f.label || 'Input ' + (i + 1)), value: varStr });
                });
            }
        });
        if (optionVars.length > 0) {
            section('Optionen (Command-Parameter)', optionVars);
        }
        if (formVarList.length > 0) {
            section('Form Antworten', formVarList);
        }
        if (localVarList.length > 0) {
            section('Lokale Variablen', localVarList);
        }

        section('Nutzer', [
            { label: 'Tag (name#1234)',        value: '{user}' },
            { label: 'Username',               value: '{user.name}' },
            { label: 'User-ID',                value: '{user.id}' },
            { label: 'Avatar URL',             value: '{user.icon}' },
            { label: 'Tag (user.tag)',          value: '{user.tag}' },
            { label: 'Discriminator',          value: '{user.discriminator}' },
            { label: 'Anzeigename (Nickname)', value: '{user.display_name}' },
            { label: 'Nickname',               value: '{user.nickname}' },
            { label: 'Erstellt am',            value: '{user.created_at}' },
            { label: 'Beigetreten am',         value: '{user.joined_at}' },
            { label: 'Online-Status',          value: '{user.status}' },
            { label: 'Ist Bot (true/false)',   value: '{user.is_bot}' },
        ]);
        section('Server & Kanal', [
            { label: 'Servername',       value: '{server}' },
            { label: 'Server-ID',        value: '{server.id}' },
            { label: 'Kanalname',        value: '{channel}' },
        ]);
        // Lokale Variablen already shown above if any nodes exist
        section('Globale Variablen', [
            { label: 'Globale Variable', value: '{global.variablenname}' },
        ]);

        return el;
    }

    function insertVariable(varStr) {
        // Fallback: if nothing was explicitly focused, insert into message content textarea
        const target = lastFocused || contentTA;
        if (!target) return;
        const start = target.selectionStart ?? target.value.length;
        const end   = target.selectionEnd   ?? target.value.length;
        target.value = target.value.slice(0, start) + varStr + target.value.slice(end);
        const newPos = start + varStr.length;
        target.setSelectionRange(newPos, newPos);
        target.dispatchEvent(new Event('input', { bubbles: true }));
        target.focus();
    }

    function closePicker() {
        if (varPicker) {
            varPicker.remove();
            varPicker = null;
        }
    }

    if (varBtn) {
        varBtn.addEventListener('mousedown', () => {
            // Snapshot focus before the button click can steal it
            const active = document.activeElement;
            if (active instanceof HTMLInputElement || active instanceof HTMLTextAreaElement) {
                lastFocused = active;
            }
        });
        varBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            if (varPicker) { closePicker(); return; }
            varPicker = buildVarPicker();
            varBtn.parentElement.style.position = 'relative';
            varBtn.parentElement.appendChild(varPicker);
        });
        document.addEventListener('click', (e) => {
            if (varPicker && !varPicker.contains(e.target) && e.target !== varBtn) {
                closePicker();
            }
        });
    }

    window.CcMsgBuilder = { open, close };
})();
// ── Form Builder Modal ────────────────────────────────────────────────────────
(() => {
    const STYLE_OPTS = [
        { value: 'short',     label: 'Short' },
        { value: 'paragraph', label: 'Paragraph' },
    ];
    const REQ_OPTS = [
        { value: 'true',  label: 'Yes' },
        { value: 'false', label: 'No' },
    ];

    // ── DOM ──
    const overlay = document.createElement('div');
    overlay.id = 'cc-fb-overlay';
    overlay.className = 'cc-fb-overlay';
    overlay.innerHTML = `
<div class="cc-fb-modal" id="cc-fb-modal">
  <div class="cc-fb-modal-header">
    <span class="cc-fb-modal-title">Form Builder</span>
    <button type="button" class="cc-fb-close-btn" id="cc-fb-close">✕</button>
  </div>
  <div class="cc-fb-modal-body">
    <div class="cc-fb-left">
      <div class="cc-fb-section-title">▶ Form Configuration</div>

      <div class="cc-fb-field-group">
        <label class="cc-fb-label">Form Title <span class="cc-fb-req">?</span></label>
        <p class="cc-fb-help">The title of the form that users will see.</p>
        <input type="text" id="cc-fb-title" class="cc-fb-input" placeholder="Form Title" maxlength="45">
      </div>

      <div class="cc-fb-field-group">
        <div class="cc-fb-fields-header">
          <label class="cc-fb-label">Input Fields <span class="cc-fb-req">?</span></label>
          <span class="cc-fb-fields-count" id="cc-fb-fields-count">1/5</span>
          <button type="button" class="cc-fb-add-field-btn" id="cc-fb-add-field">+ Add Field</button>
        </div>
        <p class="cc-fb-help">Add up to 5 input fields to your form.</p>
        <div id="cc-fb-fields-list"></div>
      </div>
    </div>

    <div class="cc-fb-right">
      <div class="cc-fb-section-title">▶ Preview</div>
      <div class="cc-fb-preview" id="cc-fb-preview">
        <div class="cc-fb-preview-header">
          <div class="cc-fb-preview-avatar"></div>
          <span class="cc-fb-preview-title" id="cc-fb-preview-title">Form Title</span>
        </div>
        <div class="cc-fb-preview-fields" id="cc-fb-preview-fields"></div>
        <div class="cc-fb-preview-footer">
          <button type="button" class="cc-fb-preview-cancel">Cancel</button>
          <button type="button" class="cc-fb-preview-submit">Submit</button>
        </div>
      </div>
    </div>
  </div>
  <div class="cc-fb-modal-footer">
    <button type="button" class="cc-fb-save-btn" id="cc-fb-save">Save Form</button>
  </div>
</div>`;
    document.body.appendChild(overlay);

    let currentNode = null;

    function open(node) {
        currentNode = node;
        if (!Array.isArray(node.config.fields) || node.config.fields.length === 0) {
            node.config.fields = [{ label: 'Input Label', placeholder: '', min_length: '', max_length: '', style: 'short', required: 'true', hidden: '', default: '' }];
        }
        document.getElementById('cc-fb-title').value = node.config.form_title || '';
        renderFields();
        renderPreview();
        overlay.classList.add('is-open');
    }

    function close() {
        overlay.classList.remove('is-open');
        currentNode = null;
    }

    document.getElementById('cc-fb-close').addEventListener('click', close);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });

    document.getElementById('cc-fb-title').addEventListener('input', (e) => {
        if (currentNode) currentNode.config.form_title = e.target.value;
        document.getElementById('cc-fb-preview-title').textContent = e.target.value || 'Form Title';
    });

    document.getElementById('cc-fb-add-field').addEventListener('click', () => {
        if (!currentNode) return;
        if (currentNode.config.fields.length >= 5) return;
        currentNode.config.fields.push({ label: 'Input Label', placeholder: '', min_length: '', max_length: '', style: 'short', required: 'true', hidden: '', default: '' });
        renderFields();
        renderPreview();
    });

    document.getElementById('cc-fb-save').addEventListener('click', () => {
        if (!currentNode) return;
        if (window._ccBuilderMarkDirty) window._ccBuilderMarkDirty();
        if (window._ccBuilderWriteJson) window._ccBuilderWriteJson();
        if (window._ccBuilderRenderNodes) window._ccBuilderRenderNodes();
        close();
    });

    function renderFields() {
        if (!currentNode) return;
        const fields = currentNode.config.fields;
        const list = document.getElementById('cc-fb-fields-list');
        const countEl = document.getElementById('cc-fb-fields-count');
        countEl.textContent = fields.length + '/5';
        document.getElementById('cc-fb-add-field').disabled = fields.length >= 5;
        list.innerHTML = '';

        fields.forEach((field, idx) => {
            const item = document.createElement('div');
            item.className = 'cc-fb-field-item';

            const header = document.createElement('div');
            header.className = 'cc-fb-field-item-header';
            const titleEl = document.createElement('span');
            titleEl.className = 'cc-fb-field-item-title';
            titleEl.textContent = `Field ${idx + 1} - ${field.label || 'Input Label'}`;
            const varHint = document.createElement('span');
            varHint.className = 'cc-fb-field-var-hint';
            const _fName = String((currentNode && currentNode.config && currentNode.config.form_name) || 'form').trim();
            varHint.textContent = '{' + _fName + '.Input.' + (idx + 1) + '.InputLabel}';
            const delBtn = document.createElement('button');
            delBtn.type = 'button';
            delBtn.className = 'cc-fb-delete-field';
            delBtn.textContent = 'Delete Field';
            delBtn.addEventListener('click', () => {
                if (fields.length <= 1) return;
                fields.splice(idx, 1);
                renderFields();
                renderPreview();
            });
            header.appendChild(titleEl);
            header.appendChild(varHint);
            header.appendChild(delBtn);
            item.appendChild(header);

            // Two-column grid for properties
            const grid = document.createElement('div');
            grid.className = 'cc-fb-field-grid';

            function addTextProp(key, label, help, full = false) {
                const row = document.createElement('div');
                row.className = 'cc-fb-sub-row' + (full ? ' cc-fb-sub-row--full' : '');
                const lbl = document.createElement('label');
                lbl.innerHTML = `${label} <span class="cc-fb-req">?</span>`;
                const helpEl = document.createElement('p');
                helpEl.className = 'cc-fb-sub-help';
                helpEl.textContent = help;
                const inp = document.createElement('input');
                inp.type = 'text';
                inp.className = 'cc-fb-input cc-fb-sub-input';
                inp.value = field[key] || '';
                inp.addEventListener('input', () => {
                    field[key] = inp.value;
                    if (key === 'label') {
                        titleEl.textContent = `Field ${idx + 1} - ${inp.value}`;
                    }
                    renderPreview();
                });
                row.appendChild(lbl);
                row.appendChild(helpEl);
                row.appendChild(inp);
                grid.appendChild(row);
            }

            function addSelectProp(key, label, help, options) {
                const row = document.createElement('div');
                row.className = 'cc-fb-sub-row';
                const lbl = document.createElement('label');
                lbl.innerHTML = `${label} <span class="cc-fb-req">?</span>`;
                const helpEl = document.createElement('p');
                helpEl.className = 'cc-fb-sub-help';
                helpEl.textContent = help;
                const sel = document.createElement('select');
                sel.className = 'cc-fb-select';
                options.forEach(({ value, label: optLabel }) => {
                    const opt = document.createElement('option');
                    opt.value = value;
                    opt.textContent = optLabel;
                    if (field[key] === value) opt.selected = true;
                    sel.appendChild(opt);
                });
                sel.addEventListener('change', () => { field[key] = sel.value; renderPreview(); });
                row.appendChild(lbl);
                row.appendChild(helpEl);
                row.appendChild(sel);
                grid.appendChild(row);
            }

            addTextProp('label',       'Input Label',   'The label or text of this field.', true);
            addTextProp('placeholder', 'Placeholder',   'Optional placeholder text for the field.');
            addTextProp('min_length',  'Minimum Length','The minimum length the input can be.');
            addTextProp('max_length',  'Maximum Length','The maximum length the input can be.');
            addSelectProp('style',    'Input Type',     'The text input type for this field.', STYLE_OPTS);
            addSelectProp('required', 'Required',       'Should this field be required?', REQ_OPTS);
            addTextProp('hidden',      'Hidden',        'Set to \'true\' to hide this field.');
            addTextProp('default',     'Default Value', 'The default value of this field.');

            item.appendChild(grid);
            list.appendChild(item);
        });
    }

    function renderPreview() {
        if (!currentNode) return;
        const fields = currentNode.config.fields;
        const container = document.getElementById('cc-fb-preview-fields');
        container.innerHTML = '';
        fields.forEach((field) => {
            if (field.hidden === 'true') return;
            const row = document.createElement('div');
            row.className = 'cc-fb-prev-field';
            const lbl = document.createElement('label');
            lbl.className = 'cc-fb-prev-label';
            lbl.textContent = (field.label || 'Input Label').toUpperCase();
            row.appendChild(lbl);
            if (field.style === 'paragraph') {
                const ta = document.createElement('textarea');
                ta.className = 'cc-fb-prev-input cc-fb-prev-textarea';
                ta.placeholder = field.placeholder || '';
                ta.value = field.default || '';
                ta.readOnly = true;
                row.appendChild(ta);
            } else {
                const inp = document.createElement('input');
                inp.type = 'text';
                inp.className = 'cc-fb-prev-input';
                inp.placeholder = field.placeholder || '';
                inp.value = field.default || '';
                inp.readOnly = true;
                row.appendChild(inp);
            }
            container.appendChild(row);
        });
    }

    window.CcFormBuilder = { open, close };

    // Expose hooks
    window._ccBuilderMarkDirty = window._ccBuilderMarkDirty || null;
    window._ccBuilderWriteJson = window._ccBuilderWriteJson || null;
})();
// ── Request Builder Modal ─────────────────────────────────────────────────────
(() => {
    const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
    const BODY_TYPES = [
        { value: 'json',       label: 'JSON' },
        { value: 'form',       label: 'Form (URL-encoded)' },
        { value: 'raw',        label: 'Raw' },
    ];
    const TABS = ['url-params', 'http-headers', 'request-body', 'options', 'test-request'];

    let _currentNode = null;
    let _overlay = null;

    function markDirty() {
        if (window._ccBuilderMarkDirty) window._ccBuilderMarkDirty();
    }
    function writeJson() {
        if (window._ccBuilderWriteJson) window._ccBuilderWriteJson();
    }

    // ── Build overlay once ────────────────────────────────────────────────────
    function ensureOverlay() {
        if (_overlay) return _overlay;

        _overlay = document.createElement('div');
        _overlay.id = 'cc-rb-overlay';
        _overlay.className = 'cc-rb-overlay';
        _overlay.innerHTML = `
<div class="cc-rb-dialog" id="cc-rb-dialog">
  <div class="cc-rb-header">
    <div>
      <div class="cc-rb-title">Request Builder</div>
      <div class="cc-rb-subtitle">Build the request to be sent when this action is executed.</div>
    </div>
    <button type="button" class="cc-rb-close" id="cc-rb-close-btn">✕</button>
  </div>

  <div class="cc-rb-method-url-row">
    <div class="cc-rb-method-wrap">
      <button type="button" class="cc-rb-method-btn" id="cc-rb-method-btn">GET</button>
      <div class="cc-rb-method-drop" id="cc-rb-method-drop" style="display:none">
        ${METHODS.map(m => `<button type="button" data-method="${m}">${m}</button>`).join('')}
      </div>
    </div>
    <input type="text" class="cc-rb-url-input" id="cc-rb-url-input" placeholder="ex: https://www.example.com">
  </div>

  <div class="cc-rb-tabs">
    <button type="button" class="cc-rb-tab is-active" data-tab="url-params">URL Params</button>
    <button type="button" class="cc-rb-tab" data-tab="http-headers">HTTP Headers</button>
    <button type="button" class="cc-rb-tab" data-tab="request-body" id="cc-rb-tab-body">Request Body</button>
    <button type="button" class="cc-rb-tab" data-tab="options">Options</button>
    <button type="button" class="cc-rb-tab" data-tab="test-request">Test Request</button>
  </div>

  <div class="cc-rb-tab-panels">

    <!-- URL Params -->
    <div class="cc-rb-panel is-active" data-panel="url-params">
      <div class="cc-rb-pair-list" id="cc-rb-params-list"></div>
      <div class="cc-rb-add-row">
        <button type="button" class="cc-rb-add-btn" id="cc-rb-add-param">Add Parameter</button>
      </div>
    </div>

    <!-- HTTP Headers -->
    <div class="cc-rb-panel" data-panel="http-headers">
      <div class="cc-rb-pair-list" id="cc-rb-headers-list"></div>
      <div class="cc-rb-add-row">
        <button type="button" class="cc-rb-add-btn" id="cc-rb-add-header">Add Header</button>
      </div>
    </div>

    <!-- Request Body -->
    <div class="cc-rb-panel" data-panel="request-body">
      <div class="cc-rb-body-type-row">
        <label class="cc-rb-label">Body Type</label>
        <div class="cc-rb-body-types" id="cc-rb-body-types">
          ${BODY_TYPES.map(bt => `<button type="button" class="cc-rb-body-type-btn" data-body-type="${bt.value}">${bt.label}</button>`).join('')}
        </div>
      </div>
      <textarea class="cc-rb-body-textarea" id="cc-rb-body-input" placeholder='{"key": "value"}'></textarea>
    </div>

    <!-- Options -->
    <div class="cc-rb-panel" data-panel="options">
      <div class="cc-rb-options-list">
        <label class="cc-rb-toggle-row"><input type="checkbox" id="cc-rb-opt-exclude-empty" checked><span>Automatically exclude fields that are empty</span></label>
        <label class="cc-rb-toggle-row"><input type="checkbox" id="cc-rb-opt-vars-url" checked><span>Replace variables in the URL</span></label>
        <label class="cc-rb-toggle-row"><input type="checkbox" id="cc-rb-opt-vars-params" checked><span>Replace variables in the URL Params</span></label>
        <label class="cc-rb-toggle-row"><input type="checkbox" id="cc-rb-opt-vars-headers" checked><span>Replace variables in the HTTP Headers</span></label>
        <label class="cc-rb-toggle-row"><input type="checkbox" id="cc-rb-opt-vars-body" checked><span>Replace variables in body</span></label>
        <label class="cc-rb-toggle-row">
          <input type="checkbox" id="cc-rb-opt-sanitize">
          <span>Sanitize response data <small class="cc-rb-opt-recommended">(recommended for security)</small></span>
        </label>
        <p class="cc-rb-opt-help">Sanitizes all text values in the API response to ensure that variables in the response (like {User.id}) will not be replaced when used in other actions. This prevents potential security issues from untrusted API responses.</p>
      </div>
    </div>

    <!-- Test Request -->
    <div class="cc-rb-panel" data-panel="test-request">
      <div class="cc-rb-test-area">
        <div id="cc-rb-test-result" class="cc-rb-test-result"></div>
        <div class="cc-rb-test-actions">
          <button type="button" class="cc-rb-test-btn" id="cc-rb-test-btn">Test Request</button>
        </div>
      </div>
    </div>

  </div>

  <div class="cc-rb-footer">
    <button type="button" class="cc-rb-btn-cancel" id="cc-rb-cancel-btn">Cancel</button>
    <button type="button" class="cc-rb-btn-save" id="cc-rb-save-btn">Save</button>
  </div>
</div>`;

        document.body.appendChild(_overlay);

        // Close on overlay click
        _overlay.addEventListener('click', (e) => {
            if (e.target === _overlay) close();
        });
        document.getElementById('cc-rb-close-btn').addEventListener('click', close);
        document.getElementById('cc-rb-cancel-btn').addEventListener('click', close);
        document.getElementById('cc-rb-save-btn').addEventListener('click', save);

        // Method dropdown
        const methodBtn = document.getElementById('cc-rb-method-btn');
        const methodDrop = document.getElementById('cc-rb-method-drop');
        methodBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            methodDrop.style.display = methodDrop.style.display === 'none' ? 'block' : 'none';
        });
        methodDrop.querySelectorAll('[data-method]').forEach((btn) => {
            btn.addEventListener('click', () => {
                methodBtn.textContent = btn.dataset.method;
                methodDrop.style.display = 'none';
                const bodyTab = document.getElementById('cc-rb-tab-body');
                bodyTab.classList.toggle('is-disabled', btn.dataset.method === 'GET' || btn.dataset.method === 'DELETE');
            });
        });
        document.addEventListener('click', () => { methodDrop.style.display = 'none'; });

        // Tabs
        _overlay.querySelectorAll('.cc-rb-tab').forEach((tab) => {
            tab.addEventListener('click', () => {
                if (tab.classList.contains('is-disabled')) return;
                _overlay.querySelectorAll('.cc-rb-tab').forEach(t => t.classList.remove('is-active'));
                _overlay.querySelectorAll('.cc-rb-panel').forEach(p => p.classList.remove('is-active'));
                tab.classList.add('is-active');
                _overlay.querySelector('[data-panel="' + tab.dataset.tab + '"]').classList.add('is-active');
            });
        });

        // Body type buttons
        _overlay.querySelectorAll('.cc-rb-body-type-btn').forEach((btn) => {
            btn.addEventListener('click', () => {
                _overlay.querySelectorAll('.cc-rb-body-type-btn').forEach(b => b.classList.remove('is-active'));
                btn.classList.add('is-active');
            });
        });

        // Add param / header
        document.getElementById('cc-rb-add-param').addEventListener('click', () => addPair('cc-rb-params-list'));
        document.getElementById('cc-rb-add-header').addEventListener('click', () => addPair('cc-rb-headers-list'));

        // Test Request
        document.getElementById('cc-rb-test-btn').addEventListener('click', runTest);

        return _overlay;
    }

    // ── Pair row helpers ──────────────────────────────────────────────────────
    function addPair(listId, key = '', value = '') {
        const list = document.getElementById(listId);
        const row = document.createElement('div');
        row.className = 'cc-rb-pair-row';
        row.innerHTML = `
          <button type="button" class="cc-rb-pair-del">✕</button>
          <input type="text" class="cc-rb-pair-key" placeholder="key" value="">
          <input type="text" class="cc-rb-pair-val" placeholder="value" value="">`;
        row.querySelector('.cc-rb-pair-key').value = key;
        row.querySelector('.cc-rb-pair-val').value = value;
        row.querySelector('.cc-rb-pair-del').addEventListener('click', () => row.remove());
        list.appendChild(row);
    }

    function readPairs(listId) {
        const pairs = [];
        document.getElementById(listId).querySelectorAll('.cc-rb-pair-row').forEach((row) => {
            const k = row.querySelector('.cc-rb-pair-key').value.trim();
            const v = row.querySelector('.cc-rb-pair-val').value;
            if (k !== '') pairs.push({ key: k, value: v });
        });
        return pairs;
    }

    // ── Populate modal from node config ───────────────────────────────────────
    function populate(node) {
        const cfg = node.config || {};
        ensureOverlay();

        // Method
        const method = String(cfg.method || 'GET').toUpperCase();
        document.getElementById('cc-rb-method-btn').textContent = method;
        const bodyTab = document.getElementById('cc-rb-tab-body');
        bodyTab.classList.toggle('is-disabled', method === 'GET' || method === 'DELETE');

        // URL
        document.getElementById('cc-rb-url-input').value = String(cfg.url || '');

        // Params
        const paramList = document.getElementById('cc-rb-params-list');
        paramList.innerHTML = '';
        (Array.isArray(cfg.params) ? cfg.params : []).forEach(p => addPair('cc-rb-params-list', p.key || '', p.value || ''));

        // Headers
        const headerList = document.getElementById('cc-rb-headers-list');
        headerList.innerHTML = '';
        (Array.isArray(cfg.headers) ? cfg.headers : []).forEach(h => addPair('cc-rb-headers-list', h.key || '', h.value || ''));

        // Body
        document.getElementById('cc-rb-body-input').value = String(cfg.body || '');
        const bodyType = String(cfg.body_type || 'json');
        _overlay.querySelectorAll('.cc-rb-body-type-btn').forEach(b => {
            b.classList.toggle('is-active', b.dataset.bodyType === bodyType);
        });

        // Options
        document.getElementById('cc-rb-opt-exclude-empty').checked = cfg.opt_exclude_empty !== false;
        document.getElementById('cc-rb-opt-vars-url').checked     = cfg.opt_vars_url     !== false;
        document.getElementById('cc-rb-opt-vars-params').checked  = cfg.opt_vars_params  !== false;
        document.getElementById('cc-rb-opt-vars-headers').checked = cfg.opt_vars_headers !== false;
        document.getElementById('cc-rb-opt-vars-body').checked    = cfg.opt_vars_body    !== false;
        document.getElementById('cc-rb-opt-sanitize').checked     = !!cfg.opt_sanitize;

        // Reset to first tab
        _overlay.querySelectorAll('.cc-rb-tab').forEach(t => t.classList.remove('is-active'));
        _overlay.querySelectorAll('.cc-rb-panel').forEach(p => p.classList.remove('is-active'));
        _overlay.querySelector('[data-tab="url-params"]').classList.add('is-active');
        _overlay.querySelector('[data-panel="url-params"]').classList.add('is-active');

        // Clear test result
        document.getElementById('cc-rb-test-result').innerHTML = '';
    }

    // ── Save modal data back to node ──────────────────────────────────────────
    function save() {
        if (!_currentNode) return;

        const activeBodyType = _overlay.querySelector('.cc-rb-body-type-btn.is-active');

        _currentNode.config.method      = document.getElementById('cc-rb-method-btn').textContent.trim();
        _currentNode.config.url         = document.getElementById('cc-rb-url-input').value.trim();
        _currentNode.config.params      = readPairs('cc-rb-params-list');
        _currentNode.config.headers     = readPairs('cc-rb-headers-list');
        _currentNode.config.body        = document.getElementById('cc-rb-body-input').value;
        _currentNode.config.body_type   = activeBodyType ? activeBodyType.dataset.bodyType : 'json';
        _currentNode.config.opt_exclude_empty = document.getElementById('cc-rb-opt-exclude-empty').checked;
        _currentNode.config.opt_vars_url      = document.getElementById('cc-rb-opt-vars-url').checked;
        _currentNode.config.opt_vars_params   = document.getElementById('cc-rb-opt-vars-params').checked;
        _currentNode.config.opt_vars_headers  = document.getElementById('cc-rb-opt-vars-headers').checked;
        _currentNode.config.opt_vars_body     = document.getElementById('cc-rb-opt-vars-body').checked;
        _currentNode.config.opt_sanitize      = document.getElementById('cc-rb-opt-sanitize').checked;

        markDirty();
        writeJson();
        close();

        // Re-render properties panel to update button/hint
        if (window._ccRenderProperties) window._ccRenderProperties(_currentNode);
    }

    // ── Test request ──────────────────────────────────────────────────────────
    async function runTest() {
        const resultEl = document.getElementById('cc-rb-test-result');
        const method = document.getElementById('cc-rb-method-btn').textContent.trim();
        let url = document.getElementById('cc-rb-url-input').value.trim();
        if (!url) { resultEl.innerHTML = '<span class="cc-rb-test-error">Please enter a URL first.</span>'; return; }

        // Append params to URL
        const params = readPairs('cc-rb-params-list');
        if (params.length > 0) {
            const qs = params.map(p => encodeURIComponent(p.key) + '=' + encodeURIComponent(p.value)).join('&');
            url += (url.includes('?') ? '&' : '?') + qs;
        }

        const headers = {};
        readPairs('cc-rb-headers-list').forEach(h => { headers[h.key] = h.value; });

        const bodyStr = document.getElementById('cc-rb-body-input').value.trim();
        const bodyType = (_overlay.querySelector('.cc-rb-body-type-btn.is-active') || {}).dataset?.bodyType || 'json';
        if (bodyType === 'json' && bodyStr && !headers['Content-Type']) headers['Content-Type'] = 'application/json';

        const fetchOpts = { method, headers };
        if (bodyStr && method !== 'GET' && method !== 'DELETE') fetchOpts.body = bodyStr;

        resultEl.innerHTML = '<span class="cc-rb-test-loading">Sending request…</span>';
        try {
            const res = await fetch(url, fetchOpts);
            const text = await res.text();
            let display = text;
            try { display = JSON.stringify(JSON.parse(text), null, 2); } catch (_) {}
            resultEl.innerHTML =
                '<div class="cc-rb-test-status ' + (res.ok ? 'ok' : 'err') + '">' + res.status + ' ' + res.statusText + '</div>' +
                '<pre class="cc-rb-test-body">' + escHtml(display.slice(0, 2000)) + '</pre>';
        } catch (e) {
            resultEl.innerHTML = '<span class="cc-rb-test-error">Error: ' + escHtml(String(e.message || e)) + '</span>';
        }
    }

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    // ── Public API ────────────────────────────────────────────────────────────
    function open(node) {
        _currentNode = node;
        ensureOverlay();
        populate(node);
        _overlay.classList.add('is-open');
    }

    function close() {
        if (_overlay) _overlay.classList.remove('is-open');
        _currentNode = null;
    }

    window.CcRequestBuilder = { open, close };
})();

// ═══════════════════════════════════════════════════════════════ Emoji Picker
(function () {
    'use strict';

    // ── Common Unicode emoji categories ──────────────────────────────────────
    const EMOJI_CATEGORIES = [
        {
            label: 'Smileys', icon: '😀',
            emojis: ['😀','😃','😄','😁','😆','😅','🤣','😂','🙂','🙃','😉','😊','😇','🥰','😍','🤩','😘','😗','😚','😙','🥲','😋','😛','😜','🤪','😝','🤑','🤗','🤭','🤫','🤔','🤐','🤨','😐','😑','😶','😏','😒','🙄','😬','🤥','😌','😔','😪','🤤','😴','😷','🤒','🤕','🤢','🤮','🤧','🥵','🥶','🥴','😵','🤯','🤠','🥳','🥸','😎','🤓','🧐','😕','😟','🙁','☹️','😮','😯','😲','😳','🥺','😦','😧','😨','😰','😥','😢','😭','😱','😖','😣','😞','😓','😩','😫','🥱','😤','😡','😠','🤬','😈','👿'],
        },
        {
            label: 'Gestures', icon: '👍',
            emojis: ['👋','🤚','🖐','✋','🖖','👌','🤌','🤏','✌️','🤞','🤟','🤘','🤙','👈','👉','👆','🖕','👇','☝️','👍','👎','✊','👊','🤛','🤜','👏','🙌','👐','🤲','🤝','🙏','✍️','💅','🤳','💪','🦾','🦿','🦵','🦶','👂','🦻','👃','👀','👁','👅','👄','💋'],
        },
        {
            label: 'Animals', icon: '🐶',
            emojis: ['🐶','🐱','🐭','🐹','🐰','🦊','🐻','🐼','🐨','🐯','🦁','🐮','🐷','🐸','🐵','🙈','🙉','🙊','🐔','🐧','🐦','🐤','🦆','🦅','🦉','🦇','🐺','🐗','🐴','🦄','🐝','🐛','🦋','🐌','🐞','🐜','🦟','🦗','🐢','🐍','🦎','🦖','🦕','🐙','🦑','🦐','🦞','🦀','🐡','🐠','🐟','🐬','🐳','🐋','🦈','🦭','🐊','🐅','🐆','🦓','🦍','🦧','🦣','🐘','🦛','🦏','🐪','🐫','🦒','🦘','🦬','🐃','🐂','🐄','🐎','🐖','🐏','🐑','🦙','🐐','🦌','🐕','🐩','🦮','🐕‍🦺','🐈','🐈‍⬛','🪶','🐓','🦃','🦤','🦚','🦜','🦢','🦩','🕊','🐇','🦝','🦨','🦡','🦫','🦦','🦥','🐁','🐀','🐿','🦔'],
        },
        {
            label: 'Food', icon: '🍕',
            emojis: ['🍕','🍔','🍟','🌭','🍿','🧂','🥓','🥚','🍳','🧇','🥞','🧈','🍞','🥐','🥖','🫓','🥨','🥯','🧀','🥗','🥙','🥪','🌮','🌯','🫔','🥫','🍝','🍜','🍲','🍛','🍣','🍱','🥟','🦪','🍤','🍙','🍚','🍘','🍥','🥮','🍢','🧆','🥚','🍡','🧁','🍰','🎂','🍮','🍭','🍬','🍫','🍿','🍩','🍪','🌰','🥜','🫘','🍯','🧃','🥤','🧋','☕','🍵','🫖','🍺','🍻','🥂','🍷','🥃','🍸','🍹','🧉','🍾','🫗'],
        },
        {
            label: 'Activities', icon: '⚽',
            emojis: ['⚽','🏀','🏈','⚾','🥎','🎾','🏐','🏉','🥏','🎱','🏓','🏸','🏒','🏑','🥍','🏏','🪃','🥅','⛳','🪁','🏹','🎣','🤿','🥊','🥋','🎽','🛹','🛼','🛷','⛸','🥌','🎿','⛷','🏂','🪂','🏋️','🤼','🤸','⛹️','🤺','🏇','🧘','🏄','🏊','🚴','🧗','🤾','🏌️','🏆','🥇','🥈','🥉','🏅','🎖','🎪','🎭','🎨','🎬','🎤','🎧','🎼','🎹','🥁','🪘','🎷','🎺','🎸','🪕','🎻','🪗','🎲','♟','🎯','🎳','🎮','🎰','🧩'],
        },
        {
            label: 'Objects', icon: '💡',
            emojis: ['💡','🔦','🕯','🪔','🧯','💰','💳','💎','⚖️','🪜','🧰','🪛','🔧','🔨','⚒','🛠','⛏','🪚','🔩','🪤','🪣','🧲','💊','💉','🩸','🩹','🩺','🌡','🪞','🛋','🪑','🚿','🛁','🪠','🪣','🧴','🧷','🧹','🧺','🧻','🪣','🧼','🫧','🪥','🧽','🪒','🧹','🔒','🔓','🔑','🗝','🔐','🔏','🔎','🔍','📦','📫','📬','📭','📮','🗳','✏️','📝','📄','📃','📋','📁','📂','📊','📈','📉','📌','📍','✂️','🖇','📎','🖊','🖋','📏','📐','🗂','🗒','🗃','🗑','📤','📥','📧'],
        },
        {
            label: 'Symbols', icon: '❤️',
            emojis: ['❤️','🧡','💛','💚','💙','💜','🖤','🤍','🤎','💔','❣️','💕','💞','💓','💗','💖','💘','💝','💟','☮️','✝️','☪️','🕉','☸️','✡️','🔯','🕎','☯️','☦️','🛐','⛎','♈','♉','♊','♋','♌','♍','♎','♏','♐','♑','♒','♓','🆔','⚛️','🈳','🈹','🈲','🈵','🈴','🉐','🈷️','🈶','🈚','🉑','🈸','🈺','🈻','🈁','✴️','🆚','💮','🉐','㊙️','㊗️','🈴','🈵','🈹','🈲','🅰️','🅱️','🆎','🆑','🅾️','🆘','❌','⭕','🛑','⛔','🚫','🚳','🚭','🚯','🚱','🚷','📵','🔞','☢️','☣️','⬆️','↗️','➡️','↘️','⬇️','↙️','⬅️','↖️','↕️','↔️','↩️','↪️','⤴️','⤵️','🔃','🔄','🔙','🔚','🔛','🔜','🔝','🔰','✅','❎','🔱','⚜️','🔰','♻️','✅','🔴','🟠','🟡','🟢','🔵','🟣','⚫','⚪','🟤','🔶','🔷','🔸','🔹','🔺','🔻','💠','🔘','🔲','🔳','🏁','🚩','🎌','🏴','🏳️','🏳️‍🌈','🏳️‍⚧️','🏴‍☠️'],
        },
    ];

    let _overlay = null;
    let _currentNode = null;
    let _currentKey = null;
    let _onUpdate = null;
    let _activeTab = 'unicode';
    let _activeCategory = 0;
    let _search = '';
    let _guildEmojis = null; // null = not loaded yet
    let _guildLoading = false;

    function ensureOverlay() {
        if (_overlay) return;

        _overlay = document.createElement('div');
        _overlay.className = 'cc-ep-overlay';
        _overlay.innerHTML =
            '<div class="cc-ep-modal">' +
                '<div class="cc-ep-header">' +
                    '<span class="cc-ep-title">Reactions auswählen</span>' +
                    '<button type="button" class="cc-ep-close">✕</button>' +
                '</div>' +
                '<div class="cc-ep-tabs">' +
                    '<button type="button" class="cc-ep-tab is-active" data-ep-tab="unicode">Unicode</button>' +
                    '<button type="button" class="cc-ep-tab" data-ep-tab="server">Server Emojis</button>' +
                '</div>' +
                '<div class="cc-ep-search-wrap">' +
                    '<input type="text" class="cc-ep-search" placeholder="Search emojis…">' +
                '</div>' +
                '<div class="cc-ep-body">' +
                    '<div class="cc-ep-tab-pane" data-ep-pane="unicode">' +
                        '<div class="cc-ep-cats"></div>' +
                        '<div class="cc-ep-grid"></div>' +
                    '</div>' +
                    '<div class="cc-ep-tab-pane is-hidden" data-ep-pane="server">' +
                        '<div class="cc-ep-server-body"></div>' +
                    '</div>' +
                '</div>' +
                '<div class="cc-ep-selected-wrap">' +
                    '<div class="cc-ep-selected-label">Selected:</div>' +
                    '<div class="cc-ep-selected-chips"></div>' +
                '</div>' +
                '<div class="cc-ep-footer">' +
                    '<button type="button" class="cc-ep-confirm btn bg-violet-500 hover:bg-violet-600 text-white">Übernehmen</button>' +
                '</div>' +
            '</div>';

        document.body.appendChild(_overlay);

        // Close on overlay backdrop click
        _overlay.addEventListener('click', (e) => {
            if (e.target === _overlay) close();
        });

        _overlay.querySelector('.cc-ep-close').addEventListener('click', close);
        _overlay.querySelector('.cc-ep-confirm').addEventListener('click', confirm);

        // Tab switching
        _overlay.querySelectorAll('.cc-ep-tab').forEach((tab) => {
            tab.addEventListener('click', () => {
                _activeTab = tab.dataset.epTab;
                _search = '';
                _overlay.querySelector('.cc-ep-search').value = '';
                _overlay.querySelectorAll('.cc-ep-tab').forEach(t => t.classList.toggle('is-active', t === tab));
                _overlay.querySelectorAll('.cc-ep-tab-pane').forEach(p => p.classList.toggle('is-hidden', p.dataset.epPane !== _activeTab));
                if (_activeTab === 'server') loadServerEmojis();
                renderGrid();
            });
        });

        // Search
        _overlay.querySelector('.cc-ep-search').addEventListener('input', (e) => {
            _search = e.target.value.toLowerCase().trim();
            renderGrid();
        });

        // Unicode category buttons
        renderCategories();
    }

    function renderCategories() {
        const catsEl = _overlay.querySelector('.cc-ep-cats');
        catsEl.innerHTML = '';
        EMOJI_CATEGORIES.forEach((cat, idx) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'cc-ep-cat-btn' + (idx === _activeCategory ? ' is-active' : '');
            btn.title = cat.label;
            btn.textContent = cat.icon;
            btn.addEventListener('click', () => {
                _activeCategory = idx;
                _search = '';
                _overlay.querySelector('.cc-ep-search').value = '';
                catsEl.querySelectorAll('.cc-ep-cat-btn').forEach((b, i) => b.classList.toggle('is-active', i === idx));
                renderGrid();
            });
            catsEl.appendChild(btn);
        });
    }

    function renderGrid() {
        if (_activeTab === 'unicode') {
            renderUnicodeGrid();
        }
        // server tab grid rendered by loadServerEmojis
    }

    function renderUnicodeGrid() {
        const gridEl = _overlay.querySelector('.cc-ep-grid');
        gridEl.innerHTML = '';

        let emojis;
        if (_search !== '') {
            // Search across all categories
            emojis = [];
            EMOJI_CATEGORIES.forEach(cat => {
                cat.emojis.forEach(e => {
                    if (cat.label.toLowerCase().includes(_search) || e.includes(_search)) {
                        emojis.push(e);
                    }
                });
            });
        } else {
            emojis = EMOJI_CATEGORIES[_activeCategory].emojis;
        }

        emojis.forEach(emoji => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'cc-ep-emoji-btn';
            btn.textContent = emoji;
            btn.title = emoji;
            btn.addEventListener('click', () => addEmoji(emoji));
            gridEl.appendChild(btn);
        });

        if (emojis.length === 0) {
            gridEl.innerHTML = '<div class="cc-ep-empty">No emojis found.</div>';
        }
    }

    async function loadServerEmojis() {
        const serverBody = _overlay.querySelector('.cc-ep-server-body');

        if (_guildEmojis !== null) {
            renderServerEmojis(serverBody);
            return;
        }

        if (_guildLoading) return;

        _guildLoading = true;
        serverBody.innerHTML = '<div class="cc-ep-loading">Loading server emojis…</div>';

        try {
            const res = await fetch('/admin/api_guild_emojis.php', { credentials: 'same-origin' });
            const data = await res.json();
            _guildEmojis = Array.isArray(data.guilds) ? data.guilds : [];
        } catch (e) {
            _guildEmojis = [];
        }

        _guildLoading = false;
        renderServerEmojis(serverBody);
    }

    function renderServerEmojis(containerEl) {
        containerEl.innerHTML = '';

        if (!_guildEmojis || _guildEmojis.length === 0) {
            containerEl.innerHTML = '<div class="cc-ep-empty">No server emojis found. Make sure your bots are connected to servers.</div>';
            return;
        }

        const filter = _search.toLowerCase();

        _guildEmojis.forEach(guild => {
            const emojis = guild.emojis.filter(e =>
                filter === '' || e.name.toLowerCase().includes(filter)
            );
            if (emojis.length === 0) return;

            const section = document.createElement('div');
            section.className = 'cc-ep-server-section';

            const title = document.createElement('div');
            title.className = 'cc-ep-server-title';
            title.textContent = guild.guild_name + ' (' + guild.bot_name + ')';
            section.appendChild(title);

            const grid = document.createElement('div');
            grid.className = 'cc-ep-server-grid';

            emojis.forEach(emoji => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'cc-ep-emoji-btn cc-ep-emoji-btn--custom';
                btn.title = ':' + emoji.name + ':';

                const img = document.createElement('img');
                img.src = emoji.url;
                img.alt = emoji.name;
                img.className = 'cc-ep-custom-img';
                btn.appendChild(img);

                btn.addEventListener('click', () => addEmoji(emoji.value));
                grid.appendChild(btn);
            });

            section.appendChild(grid);
            containerEl.appendChild(section);
        });

        if (containerEl.innerHTML === '') {
            containerEl.innerHTML = '<div class="cc-ep-empty">No emojis match your search.</div>';
        }
    }

    function addEmoji(emoji) {
        if (!_currentNode || !_currentKey) return;

        if (!Array.isArray(_currentNode.config[_currentKey])) {
            _currentNode.config[_currentKey] = [];
        }

        // Avoid duplicates
        if (_currentNode.config[_currentKey].includes(emoji)) return;

        _currentNode.config[_currentKey].push(emoji);

        if (window._ccBuilderMarkDirty) window._ccBuilderMarkDirty();
        if (window._ccBuilderWriteJson) window._ccBuilderWriteJson();

        renderSelectedChips();
    }

    function renderSelectedChips() {
        const chipsEl = _overlay.querySelector('.cc-ep-selected-chips');
        chipsEl.innerHTML = '';

        const emojis = (_currentNode && _currentNode.config[_currentKey]) || [];
        emojis.forEach((emoji, idx) => {
            const chip = document.createElement('span');
            chip.className = 'cc-ep-chip';

            const customMatch = String(emoji).match(/^<a?:([^:]+):(\d+)>$/);
            if (customMatch) {
                const img = document.createElement('img');
                img.src = 'https://cdn.discordapp.com/emojis/' + customMatch[2] + '.webp?size=20';
                img.alt = customMatch[1];
                img.className = 'cc-ep-chip-img';
                chip.appendChild(img);
            } else {
                chip.textContent = emoji;
            }

            const rmBtn = document.createElement('button');
            rmBtn.type = 'button';
            rmBtn.className = 'cc-ep-chip-rm';
            rmBtn.textContent = '×';
            rmBtn.addEventListener('click', () => {
                _currentNode.config[_currentKey].splice(idx, 1);
                if (window._ccBuilderMarkDirty) window._ccBuilderMarkDirty();
                if (window._ccBuilderWriteJson) window._ccBuilderWriteJson();
                renderSelectedChips();
                if (_onUpdate) _onUpdate();
            });
            chip.appendChild(rmBtn);
            chipsEl.appendChild(chip);
        });

        if (emojis.length === 0) {
            chipsEl.innerHTML = '<span style="opacity:0.5;font-size:12px">None</span>';
        }
    }

    function confirm() {
        if (_onUpdate) _onUpdate();
        close();
    }

    function open(node, key, onUpdate) {
        ensureOverlay();
        _currentNode = node;
        _currentKey = key;
        _onUpdate = onUpdate || null;
        _activeTab = 'unicode';
        _activeCategory = 0;
        _search = '';
        _overlay.querySelector('.cc-ep-search').value = '';

        // Reset tabs
        _overlay.querySelectorAll('.cc-ep-tab').forEach(t => t.classList.toggle('is-active', t.dataset.epTab === 'unicode'));
        _overlay.querySelectorAll('.cc-ep-tab-pane').forEach(p => p.classList.toggle('is-hidden', p.dataset.epPane !== 'unicode'));

        renderCategories();
        renderUnicodeGrid();
        renderSelectedChips();

        _overlay.classList.add('is-open');
    }

    function close() {
        if (_overlay) _overlay.classList.remove('is-open');
    }

    window.CcEmojiPicker = { open, close };
})();

// ═══════════════════════════════════════════════════════ Timed Event Builder
(function () {
    'use strict';

    const overlay   = document.getElementById('cc-te-overlay');
    if (!overlay) { return; }

    const nameInput     = document.getElementById('cc-te-name');
    const typeSelect    = document.getElementById('cc-te-type');
    const secInput      = document.getElementById('cc-te-seconds');
    const minInput      = document.getElementById('cc-te-minutes');
    const hourInput     = document.getElementById('cc-te-hours');
    const dayInput      = document.getElementById('cc-te-days');
    const intervalSec   = document.getElementById('cc-te-interval-section');
    const scheduleSec   = document.getElementById('cc-te-schedule-section');
    const schedTimeInput= document.getElementById('cc-te-schedule-time');
    const saveBtn       = document.getElementById('cc-te-save-btn');
    const closeBtn      = document.getElementById('cc-te-close-btn');

    let currentNode = null;

    function getWeekdays(sectionId) {
        const btns = document.querySelectorAll('#' + sectionId + ' .cc-te-day-btn');
        const result = [];
        btns.forEach((btn) => { if (btn.classList.contains('is-active')) result.push(btn.dataset.day); });
        return result;
    }

    function setWeekdays(sectionId, days) {
        document.querySelectorAll('#' + sectionId + ' .cc-te-day-btn').forEach((btn) => {
            btn.classList.toggle('is-active', !days || days.length === 0 || days.includes(btn.dataset.day));
        });
    }

    function showSection(type) {
        if (intervalSec) intervalSec.style.display = type === 'interval' ? '' : 'none';
        if (scheduleSec) scheduleSec.style.display = type === 'schedule' ? '' : 'none';
    }

    function loadNode(node) {
        const cfg = node.config || {};
        if (nameInput)      nameInput.value     = cfg.event_name       || '';
        if (typeSelect)     typeSelect.value    = cfg.event_type       || 'interval';
        if (secInput)       secInput.value      = cfg.interval_seconds || 0;
        if (minInput)       minInput.value      = cfg.interval_minutes || 0;
        if (hourInput)      hourInput.value     = cfg.interval_hours   || 0;
        if (dayInput)       dayInput.value      = cfg.interval_days    || 0;
        if (schedTimeInput) schedTimeInput.value= cfg.schedule_time    || '00:00';
        setWeekdays('cc-te-interval-weekdays', Array.isArray(cfg.week_days)     ? cfg.week_days     : null);
        setWeekdays('cc-te-schedule-weekdays', Array.isArray(cfg.schedule_days) ? cfg.schedule_days : null);
        showSection(cfg.event_type || 'interval');
    }

    function saveNode() {
        if (!currentNode) return;
        const type = typeSelect ? typeSelect.value : 'interval';
        currentNode.config = currentNode.config || {};
        currentNode.config.event_name       = nameInput      ? nameInput.value.trim()          : '';
        currentNode.config.event_type       = type;
        currentNode.config.interval_seconds = secInput       ? parseInt(secInput.value,  10) || 0 : 0;
        currentNode.config.interval_minutes = minInput       ? parseInt(minInput.value,  10) || 0 : 0;
        currentNode.config.interval_hours   = hourInput      ? parseInt(hourInput.value, 10) || 0 : 0;
        currentNode.config.interval_days    = dayInput       ? parseInt(dayInput.value,  10) || 0 : 0;
        currentNode.config.week_days        = getWeekdays('cc-te-interval-weekdays');
        currentNode.config.schedule_time    = schedTimeInput ? schedTimeInput.value           : '00:00';
        currentNode.config.schedule_days    = getWeekdays('cc-te-schedule-weekdays');
        if (window._ccBuilderMarkDirty)   window._ccBuilderMarkDirty();
        if (window._ccBuilderWriteJson)   window._ccBuilderWriteJson();
        if (window._ccBuilderRenderNodes) window._ccBuilderRenderNodes();
    }

    // ── Event listeners ────────────────────────────────────────────────────
    if (typeSelect) {
        typeSelect.addEventListener('change', () => showSection(typeSelect.value));
    }

    // Day toggles
    document.querySelectorAll('.cc-te-day-btn').forEach((btn) => {
        btn.addEventListener('click', () => btn.classList.toggle('is-active'));
    });

    if (saveBtn) {
        saveBtn.addEventListener('click', () => { saveNode(); close(); });
    }
    if (closeBtn) {
        closeBtn.addEventListener('click', () => close());
    }
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });

    // ── Public API ─────────────────────────────────────────────────────────
    function open(node) {
        currentNode = node;
        loadNode(node);
        overlay.classList.add('is-open');
        overlay.setAttribute('aria-hidden', 'false');
        if (nameInput) nameInput.focus();
    }

    function close() {
        overlay.classList.remove('is-open');
        overlay.setAttribute('aria-hidden', 'true');
        currentNode = null;
    }

    window.CcTimedBuilder = { open, close };
})();
