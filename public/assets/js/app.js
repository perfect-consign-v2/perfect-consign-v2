/* ==========================================================================
   Ninja EMP — Tenant UI shell behavior
   Sidebar collapse, mobile drawer, theme/mode switching, dropdown menus,
   global search shortcut. No dependencies.
   ========================================================================== */
(function () {
  'use strict';

  var app = document.getElementById('app');
  var root = document.documentElement;

  /* ---- Sidebar collapse (persisted) ------------------------------------ */
  var COLLAPSE_KEY = 'nem-sidebar-collapsed';
  try {
    if (localStorage.getItem(COLLAPSE_KEY) === '1') {
      app.setAttribute('data-collapsed', 'true');
    }
  } catch (e) {}

  document.querySelectorAll('[data-collapse-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var collapsed = app.getAttribute('data-collapsed') === 'true';
      app.setAttribute('data-collapsed', collapsed ? 'false' : 'true');
      try { localStorage.setItem(COLLAPSE_KEY, collapsed ? '0' : '1'); } catch (e) {}
    });
  });

  /* ---- Mobile drawer ---------------------------------------------------- */
  document.querySelectorAll('[data-mobile-toggle]').forEach(function (el) {
    el.addEventListener('click', function () {
      var open = app.getAttribute('data-mobile-open') === 'true';
      app.setAttribute('data-mobile-open', open ? 'false' : 'true');
    });
  });

  /* ---- Theme + mode ----------------------------------------------------- */
  function setTheme(theme) {
    root.setAttribute('data-theme', theme);
    try { localStorage.setItem('pc-theme', theme); } catch (e) {}
    document.querySelectorAll('[data-set-theme]').forEach(function (b) {
      b.setAttribute('aria-checked', b.getAttribute('data-set-theme') === theme ? 'true' : 'false');
    });
  }
  function setMode(mode) {
    root.setAttribute('data-mode', mode);
    try { localStorage.setItem('pc-mode', mode); } catch (e) {}
    document.querySelectorAll('[data-set-mode]').forEach(function (b) {
      b.setAttribute('aria-checked', b.getAttribute('data-set-mode') === mode ? 'true' : 'false');
    });
  }
  setTheme(root.getAttribute('data-theme') || 'dark-blue');
  setMode(root.getAttribute('data-mode') || 'light');
  document.querySelectorAll('[data-set-theme]').forEach(function (b) {
    b.addEventListener('click', function () { setTheme(b.getAttribute('data-set-theme')); });
  });
  document.querySelectorAll('[data-set-mode]').forEach(function (b) {
    b.addEventListener('click', function () { setMode(b.getAttribute('data-set-mode')); });
  });

  /* ---- Dropdown menus --------------------------------------------------- */
  function closeAllMenus(except) {
    document.querySelectorAll('[data-menu]').forEach(function (menu) {
      if (menu === except) return;
      var panel = menu.querySelector('[data-menu-panel]');
      var trigger = menu.querySelector('[data-menu-trigger]');
      if (panel) panel.hidden = true;
      if (trigger) trigger.setAttribute('aria-expanded', 'false');
    });
  }
  document.querySelectorAll('[data-menu]').forEach(function (menu) {
    var trigger = menu.querySelector('[data-menu-trigger]');
    var panel = menu.querySelector('[data-menu-panel]');
    if (!trigger || !panel) return;
    trigger.addEventListener('click', function (e) {
      e.stopPropagation();
      var willOpen = panel.hidden;
      closeAllMenus(menu);
      panel.hidden = !willOpen;
      trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    });
  });
  document.addEventListener('click', function () { closeAllMenus(null); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      closeAllMenus(null);
      document.querySelectorAll('.modal').forEach(function (modal) {
        if (!modal.hasAttribute('data-lock')) {
          modal.hidden = true;
        }
      });
    }
  });

  document.querySelectorAll('[data-open-modal]').forEach(function (button) {
    button.addEventListener('click', function () {
      var modal = document.getElementById(button.getAttribute('data-open-modal'));
      if (!modal) {
        return;
      }
      var form = modal.querySelector('form');
      var action = button.getAttribute('data-brand-action');
      var name = button.getAttribute('data-brand-name');
      var field = modal.querySelector('input[name="name"]');
      if (form && action) {
        form.action = action;
      }
      if (field && name !== null) {
        field.value = name;
      }
      var chargeBooth = button.getAttribute('data-charge-booth');
      if (form && chargeBooth !== null) {
        var boothField = form.querySelector('input[name="booth_id"]');
        var dateField = form.querySelector('input[name="posting_date"]');
        var codeEl = modal.querySelector('[data-charge-code]');
        var vendorEl = modal.querySelector('[data-charge-vendor]');
        var amountEls = modal.querySelectorAll('[data-charge-amount]');
        if (boothField) {
          boothField.value = chargeBooth;
        }
        if (dateField) {
          dateField.value = button.getAttribute('data-charge-date') || '';
        }
        if (codeEl) {
          codeEl.textContent = button.getAttribute('data-charge-code') || '';
        }
        if (vendorEl) {
          var vendorName = button.getAttribute('data-charge-vendor') || '';
          vendorEl.textContent = vendorName;
          vendorEl.hidden = vendorName === '';
        }
        var amountText = button.getAttribute('data-charge-money') || '';
        amountEls.forEach(function (el) {
          el.textContent = amountText;
        });
      }
      modal.hidden = false;
      if (field) {
        field.focus();
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
  document.querySelectorAll('.modal').forEach(function (modal) {
    modal.addEventListener('click', function (event) {
      if (event.target === modal && !modal.hasAttribute('data-lock')) {
        modal.hidden = true;
      }
    });
  });

})();
