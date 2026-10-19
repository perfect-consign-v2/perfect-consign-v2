(function () {
    'use strict';

    var map = document.getElementById('booth-map');
    var modal = document.getElementById('booth-detail');
    var codeEl = document.getElementById('bd-code');
    var statusEl = document.getElementById('bd-status');
    var vendorEl = document.getElementById('bd-vendor');
    var incomingRow = document.getElementById('bd-incoming-row');
    var incomingEl = document.getElementById('bd-incoming');
    var sizeEl = document.getElementById('bd-size');
    var editEl = document.getElementById('bd-edit');
    var closeEl = document.getElementById('booth-detail-close');
    if (!map || !modal || !codeEl || !statusEl || !vendorEl || !sizeEl || !editEl) {
        return;
    }

    function close() {
        modal.hidden = true;
    }

    function select(cell) {
        document.querySelectorAll('.booth-cell').forEach(function (other) {
            other.classList.toggle('is-selected', other === cell);
        });
        codeEl.textContent = cell.getAttribute('data-code') || '';
        var status = cell.getAttribute('data-status') || '';
        statusEl.innerHTML = '';
        var badge = document.createElement('span');
        badge.className = 'occupancy-pill ' + (status || '');
        badge.textContent = status.charAt(0).toUpperCase() + status.slice(1);
        statusEl.appendChild(badge);
        vendorEl.textContent = cell.getAttribute('data-vendor') || '';
        var incoming = cell.getAttribute('data-incoming') || '';
        if (incomingRow && incomingEl) {
            incomingEl.textContent = incoming;
            incomingRow.hidden = incoming === '';
        }
        sizeEl.textContent = cell.getAttribute('data-size') || '';
        editEl.setAttribute('href', cell.getAttribute('href') || '#');
        modal.hidden = false;
    }

    document.querySelectorAll('.booth-cell').forEach(function (cell) {
        cell.addEventListener('click', function (event) {
            event.preventDefault();
            select(cell);
        });
    });

    if (closeEl) {
        closeEl.addEventListener('click', close);
    }
    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            close();
        }
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
