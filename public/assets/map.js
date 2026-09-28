(function () {
    'use strict';

    var map = document.getElementById('booth-map');
    if (!map) {
        return;
    }

    var empty = document.getElementById('booth-detail-empty');
    var body = document.getElementById('booth-detail-body');
    var codeEl = document.getElementById('bd-code');
    var statusEl = document.getElementById('bd-status');
    var vendorEl = document.getElementById('bd-vendor');
    var sizeEl = document.getElementById('bd-size');
    var editEl = document.getElementById('bd-edit');
    if (!empty || !body || !codeEl || !statusEl || !vendorEl || !sizeEl || !editEl) {
        return;
    }

    var badgeClass = {
        occupied: 'badge-success',
        available: 'badge-info',
        ending: 'badge-warning',
        incoming: 'badge-accent'
    };

    function select(cell) {
        document.querySelectorAll('.booth-cell').forEach(function (other) {
            other.classList.toggle('is-selected', other === cell);
        });
        empty.hidden = true;
        body.hidden = false;
        codeEl.textContent = cell.getAttribute('data-code') || '';
        var status = cell.getAttribute('data-status') || '';
        statusEl.innerHTML = '';
        var badge = document.createElement('span');
        badge.className = 'badge badge-dot ' + (badgeClass[status] || '');
        badge.textContent = status.charAt(0).toUpperCase() + status.slice(1);
        statusEl.appendChild(badge);
        vendorEl.textContent = cell.getAttribute('data-vendor') || '';
        sizeEl.textContent = cell.getAttribute('data-size') || '';
        editEl.setAttribute('href', cell.getAttribute('href') || '#');
    }

    document.querySelectorAll('.booth-cell').forEach(function (cell) {
        cell.addEventListener('click', function (event) {
            if (!cell.classList.contains('is-selected')) {
                event.preventDefault();
                select(cell);
            }
        });
        cell.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && !cell.classList.contains('is-selected')) {
                event.preventDefault();
                select(cell);
            }
        });
    });

    var hash = /^#booth-([0-9a-fA-F-]{36})$/.exec(window.location.hash);
    if (!hash) {
        return;
    }
    var opened = document.querySelector('.booth-cell[data-booth="' + hash[1] + '"]');
    if (!opened) {
        return;
    }
    select(opened);
    opened.scrollIntoView({ block: 'nearest', inline: 'nearest' });
}());
