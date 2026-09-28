(function () {
    'use strict';

    var root = document.getElementById('map-editor');
    var dataEl = document.getElementById('map-booths');
    var floorEl = document.getElementById('map-floor');
    var unplacedEl = document.getElementById('map-unplaced-row');
    var layoutField = document.getElementById('map-layout-field');
    var floorField = document.getElementById('map-floor-field');
    var statusEl = document.getElementById('map-status');
    var lockEl = document.getElementById('lock-layout');
    var gridEl = document.getElementById('show-grid');
    var takeOff = document.getElementById('take-off');
    if (!root || !dataEl || !floorEl || !unplacedEl || !layoutField || !floorField || !lockEl || !gridEl) {
        return;
    }

    var foot = Number(root.getAttribute('data-foot')) || 12;
    var floorWidth = Number(root.getAttribute('data-width'));
    var floorDepth = Number(root.getAttribute('data-depth'));
    var defaultWidth = Number(root.getAttribute('data-default-width')) || 10;
    var defaultDepth = Number(root.getAttribute('data-default-depth')) || 10;
    var floorId = root.getAttribute('data-floor') || '';
    var booths = JSON.parse(dataEl.textContent || '[]');
    var selected = null;
    var drag = null;

    floorEl.style.width = (floorWidth * foot) + 'px';
    floorEl.style.height = (floorDepth * foot) + 'px';

    function sizeOf(booth) {
        var width = booth.width === null || booth.width === undefined ? defaultWidth : booth.width;
        var depth = booth.depth === null || booth.depth === undefined ? defaultDepth : booth.depth;
        return { width: width, depth: depth, explicit: booth.width !== null && booth.width !== undefined };
    }

    function overlaps(booth, x, z, width, depth) {
        var i;
        for (i = 0; i < booths.length; i += 1) {
            var other = booths[i];
            if (other.id === booth.id || other.floorId !== floorId || other.x === null || other.z === null) {
                continue;
            }
            var box = sizeOf(other);
            var separated = x + width <= other.x || other.x + box.width <= x || z + depth <= other.z || other.z + box.depth <= z;
            if (!separated) {
                return true;
            }
        }
        return false;
    }

    function paint() {
        floorEl.classList.toggle('is-grid', gridEl.checked);
        floorEl.innerHTML = '';
        unplacedEl.innerHTML = '';
        var unplaced = 0;
        booths.forEach(function (booth) {
            var box = sizeOf(booth);
            if (booth.floorId === floorId && booth.x !== null && booth.z !== null) {
                var cell = document.createElement('button');
                cell.type = 'button';
                cell.className = 'booth-cell ' + booth.status + (selected === booth.id ? ' is-selected' : '');
                cell.style.left = (booth.x * foot) + 'px';
                cell.style.top = (booth.z * foot) + 'px';
                cell.style.width = (box.width * foot) + 'px';
                cell.style.height = (box.depth * foot) + 'px';
                cell.textContent = booth.code;
                cell.setAttribute('data-id', booth.id);
                cell.addEventListener('pointerdown', function (event) {
                    onFloorPointer(event, booth);
                });
                floorEl.appendChild(cell);
                return;
            }
            if (booth.floorId !== null && booth.floorId !== undefined && booth.x !== null) {
                return;
            }
            unplaced += 1;
            var chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'map-chip ' + booth.status;
            chip.style.width = (box.width * foot) + 'px';
            chip.style.height = (box.depth * foot) + 'px';
            chip.textContent = booth.code;
            chip.addEventListener('pointerdown', function (event) {
                selected = booth.id;
                drag = { id: booth.id, mode: 'place', originX: event.clientX, originY: event.clientY };
                chip.setPointerCapture(event.pointerId);
            });
            unplacedEl.appendChild(chip);
        });
        if (unplaced === 0) {
            var empty = document.createElement('p');
            empty.className = 'muted text-sm';
            empty.textContent = 'Every booth is on a floor.';
            unplacedEl.appendChild(empty);
        }
        if (takeOff) {
            var current = booths.find(function (booth) { return booth.id === selected; });
            takeOff.hidden = !current || current.floorId !== floorId || lockEl.checked;
        }
        syncLayout();
    }

    function onFloorPointer(event, booth) {
        selected = booth.id;
        if (lockEl.checked) {
            paint();
            return;
        }
        var box = sizeOf(booth);
        drag = { id: booth.id, mode: 'move', originX: event.clientX, originY: event.clientY, x: booth.x, z: booth.z, width: box.width, depth: box.depth };
        event.currentTarget.setPointerCapture(event.pointerId);
        event.currentTarget.classList.add('is-selected');
    }

    function moveNode(booth) {
        var node = floorEl.querySelector('[data-id="' + booth.id + '"]');
        var box = sizeOf(booth);
        if (!node) {
            return;
        }
        node.style.left = (booth.x * foot) + 'px';
        node.style.top = (booth.z * foot) + 'px';
        node.style.width = (box.width * foot) + 'px';
        node.style.height = (box.depth * foot) + 'px';
    }

    document.addEventListener('pointermove', function (event) {
        if (!drag) {
            return;
        }
        var booth = booths.find(function (row) { return row.id === drag.id; });
        if (!booth) {
            return;
        }
        var dx = Math.round((event.clientX - drag.originX) / foot);
        var dy = Math.round((event.clientY - drag.originY) / foot);
        if (drag.mode === 'place') {
            return;
        }
        if (drag.mode === 'move') {
            var x = drag.x + dx;
            var z = drag.z + dy;
            if (x < 0 || z < 0 || x + drag.width > floorWidth || z + drag.depth > floorDepth) {
                return;
            }
            if (overlaps(booth, x, z, drag.width, drag.depth)) {
                return;
            }
            booth.x = x;
            booth.z = z;
            booth.floorId = floorId;
            moveNode(booth);
        }
    });

    document.addEventListener('pointerup', function (event) {
        if (!drag) {
            return;
        }
        var booth = booths.find(function (row) { return row.id === drag.id; });
        var mode = drag.mode;
        drag = null;
        if (!booth || mode !== 'place') {
            paint();
            return;
        }
        var rect = floorEl.getBoundingClientRect();
        var x = Math.floor((event.clientX - rect.left) / foot);
        var z = Math.floor((event.clientY - rect.top) / foot);
        var box = sizeOf(booth);
        if (event.clientX < rect.left || event.clientY < rect.top || x < 0 || z < 0 || x + box.width > floorWidth || z + box.depth > floorDepth) {
            if (statusEl) {
                statusEl.textContent = 'Drop the booth on an open part of the floor.';
            }
            paint();
            return;
        }
        if (overlaps(booth, x, z, box.width, box.depth)) {
            if (statusEl) {
                statusEl.textContent = 'That spot overlaps another booth.';
            }
            paint();
            return;
        }
        booth.x = x;
        booth.z = z;
        booth.floorId = floorId;
        selected = booth.id;
        if (statusEl) {
            statusEl.textContent = booth.code + ' placed. Save the map when the floor looks right.';
        }
        paint();
    });

    function syncLayout() {
        var placed = [];
        booths.forEach(function (booth) {
            if (booth.x === null || booth.z === null || !booth.floorId) {
                return;
            }
            var row = { id: booth.id, floorId: booth.floorId, x: booth.x, z: booth.z };
            if (booth.width !== null && booth.width !== undefined && booth.depth !== null && booth.depth !== undefined) {
                row.width = booth.width;
                row.depth = booth.depth;
            }
            placed.push(row);
        });
        layoutField.value = JSON.stringify(placed);
        floorField.value = floorId;
    }

    lockEl.addEventListener('change', paint);
    gridEl.addEventListener('change', paint);
    if (takeOff) {
        takeOff.addEventListener('click', function () {
            var booth = booths.find(function (row) { return row.id === selected; });
            if (!booth) {
                return;
            }
            booth.x = null;
            booth.z = null;
            booth.floorId = null;
            if (statusEl) {
                statusEl.textContent = booth.code + ' taken off the map. Save to keep that.';
            }
            paint();
        });
    }

    document.querySelectorAll('[data-floor]').forEach(function (tab) {
        if (tab.tagName !== 'BUTTON') {
            return;
        }
        tab.addEventListener('click', function () {
            floorId = tab.getAttribute('data-floor') || floorId;
            floorField.value = floorId;
            document.querySelectorAll('.map-floor-tab').forEach(function (other) {
                var on = other === tab;
                other.classList.toggle('current', on);
                other.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            var rename = document.getElementById('rename-floor-form');
            var renameName = document.getElementById('rename-floor-name');
            var remove = document.getElementById('remove-floor-form');
            if (rename) {
                rename.action = rename.action.replace(/floors\/[^/]+$/, 'floors/' + floorId);
            }
            if (renameName) {
                renameName.value = tab.getAttribute('data-floor-name') || '';
            }
            if (remove) {
                remove.action = remove.action.replace(/floors\/[^/]+\/delete$/, 'floors/' + floorId + '/delete');
            }
            selected = null;
            paint();
        });
    });

    document.querySelectorAll('[data-open-modal]').forEach(function (button) {
        button.addEventListener('click', function () {
            var modal = document.getElementById(button.getAttribute('data-open-modal'));
            if (modal) {
                modal.hidden = false;
                var field = modal.querySelector('input[name="name"]');
                if (field) {
                    field.focus();
                }
            }
        });
    });
    document.querySelectorAll('[data-close-modal]').forEach(function (button) {
        button.addEventListener('click', function () {
            var modal = document.getElementById(button.getAttribute('data-close-modal'));
            if (modal) {
                modal.hidden = true;
            }
        });
    });

    paint();
}());
