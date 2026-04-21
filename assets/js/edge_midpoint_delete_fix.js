/* # PFAD: /assets/js/edge_midpoint_delete_fix.js */
(function () {
    'use strict';

    function rootEl() {
        return document.getElementById('cc-builder-canvas')
            || document.querySelector('.cc-canvas')
            || document.body;
    }

    function worldEl() {
        return document.getElementById('cc-builder-world')
            || document.querySelector('.cc-world');
    }

    function svgEl() {
        return document.getElementById('cc-builder-edges')
            || document.querySelector('.cc-edges');
    }

    function pathEls() {
        var svg = svgEl();
        if (!svg) {
            return [];
        }
        return Array.prototype.slice.call(svg.querySelectorAll('path'));
    }

    function removeButtons() {
        var root = rootEl();
        root.querySelectorAll('.cc-edge-delete').forEach(function (el) {
            el.remove();
        });
    }

    function edgeIdFromPath(path, index) {
        return path.getAttribute('data-edge-id')
            || path.getAttribute('data-id')
            || path.id
            || ('edge_' + index);
    }

    function midpointOfPath(path) {
        try {
            if (typeof path.getTotalLength !== 'function' || typeof path.getPointAtLength !== 'function') {
                return null;
            }
            var len = path.getTotalLength();
            if (!isFinite(len) || len <= 0) {
                return null;
            }
            var p = path.getPointAtLength(len / 2);
            return { x: p.x, y: p.y };
        } catch (err) {
            return null;
        }
    }

    function guessStateObjects() {
        var candidates = [];
        var keys = Object.keys(window);
        for (var i = 0; i < keys.length; i += 1) {
            var key = keys[i];
            try {
                var value = window[key];
                if (value && typeof value === 'object' && Array.isArray(value.edges)) {
                    candidates.push(value);
                }
            } catch (e) {}
        }
        return candidates;
    }

    function deleteFromKnownStates(edgeId) {
        var deleted = false;
        var states = guessStateObjects();
        states.forEach(function (stateObj) {
            var before = stateObj.edges.length;
            stateObj.edges = stateObj.edges.filter(function (edge, index) {
                var currentId = edge.id || edge.edge_id || edgeIdFromEdge(edge, index);
                return currentId !== edgeId;
            });
            if (stateObj.edges.length !== before) {
                deleted = true;
            }
        });
        return deleted;
    }

    function edgeIdFromEdge(edge, index) {
        if (!edge || typeof edge !== 'object') {
            return 'edge_' + index;
        }
        return edge.id
            || edge.edge_id
            || [edge.from_node || edge.source || 'src', edge.from_port || edge.sourceHandle || 'out', edge.to_node || edge.target || 'dst', edge.to_port || edge.targetHandle || 'in'].join('__')
            || ('edge_' + index);
    }

    function rerenderBuilder() {
        if (typeof window.renderAll === 'function') {
            window.renderAll();
            return true;
        }
        if (typeof window.renderEdges === 'function') {
            window.renderEdges();
            return true;
        }
        return false;
    }

    function removePathNow(edgeId) {
        pathEls().forEach(function (path, index) {
            if (edgeIdFromPath(path, index) === edgeId) {
                path.remove();
            }
        });
    }

    function deleteEdge(edgeId) {
        var changed = deleteFromKnownStates(edgeId);
        if (changed) {
            if (!rerenderBuilder()) {
                requestRender();
            }
            return;
        }

        removePathNow(edgeId);
        requestRender();
    }

    function createButton(edgeId, x, y) {
        var root = rootEl();
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'cc-edge-delete';
        btn.setAttribute('data-edge-id', edgeId);
        btn.textContent = '×';
        btn.style.left = x + 'px';
        btn.style.top = y + 'px';

        btn.addEventListener('mousedown', function (e) {
            e.stopPropagation();
            e.preventDefault();
        }, true);

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            e.preventDefault();
            deleteEdge(edgeId);
        }, true);

        root.appendChild(btn);
    }

    function renderButtons() {
        var root = rootEl();
        var svg = svgEl();
        var world = worldEl();

        if (!root || !svg || !world) {
            return;
        }

        removeButtons();

        var rootRect = root.getBoundingClientRect();
        var svgRect = svg.getBoundingClientRect();

        pathEls().forEach(function (path, index) {
            var midpoint = midpointOfPath(path);
            if (!midpoint) {
                return;
            }

            var edgeId = edgeIdFromPath(path, index);
            var x = (svgRect.left - rootRect.left) + midpoint.x;
            var y = (svgRect.top - rootRect.top) + midpoint.y;
            createButton(edgeId, x, y);
        });
    }

    var rafId = 0;
    function requestRender() {
        if (rafId) {
            cancelAnimationFrame(rafId);
        }
        rafId = requestAnimationFrame(function () {
            rafId = 0;
            renderButtons();
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        requestRender();

        var observer = new MutationObserver(function () {
            requestRender();
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['d', 'transform', 'style', 'class', 'data-edge-id']
        });

        window.addEventListener('resize', requestRender, true);
        document.addEventListener('mouseup', requestRender, true);
        document.addEventListener('mousemove', function () {
            if (pathEls().length > 0) {
                requestRender();
            }
        }, true);
    });

    window.ccEdgeDeleteFixRender = requestRender;
})();