(function () {
  var modal = document.getElementById('register-count');
  if (!modal) {
    return;
  }
  var targetId = '';

  function dollars(minor) {
    var sign = minor < 0 ? '-' : '';
    var value = Math.abs(minor);
    var whole = Math.floor(value / 100);
    var cents = String(value % 100).padStart(2, '0');
    return sign + whole + '.' + cents;
  }

  function liveTotal() {
    var total = 0;
    modal.querySelectorAll('[data-count-key]').forEach(function (input) {
      var key = Number(input.getAttribute('data-count-key'));
      var count = Number(input.value);
      if (!Number.isFinite(count) || count < 0) {
        count = 0;
      }
      var line = key * Math.floor(count);
      total += line;
      var shown = input.parentElement ? input.parentElement.querySelector('[data-count-line]') : null;
      if (shown) {
        shown.textContent = '$' + dollars(line);
      }
    });
    var label = modal.querySelector('[data-live-total]');
    if (label) {
      label.textContent = '$' + dollars(total);
    }
    return total;
  }

  document.querySelectorAll('[data-count-form]').forEach(function (button) {
    button.addEventListener('click', function () {
      targetId = button.getAttribute('data-count-form') || '';
      var form = document.getElementById(targetId);
      modal.querySelectorAll('[data-count-key]').forEach(function (input) {
        var key = input.getAttribute('data-count-key');
        var hidden = form ? form.querySelector('[name="count_' + key + '"]') : null;
        input.value = hidden && hidden.value !== '' ? hidden.value : '0';
      });
      liveTotal();
    });
  });

  modal.querySelectorAll('[data-count-key]').forEach(function (input) {
    input.addEventListener('input', function () {
      applyCount(false);
    });
  });

  function applyCount(closeModal) {
    var form = document.getElementById(targetId);
    if (!form) {
      liveTotal();
      return;
    }
    var total = 0;
    var counted = false;
    modal.querySelectorAll('[data-count-key]').forEach(function (input) {
      var key = input.getAttribute('data-count-key');
      var count = input.value.trim();
      if (!/^\d+$/.test(count)) {
        count = '0';
      }
      if (count !== '0') {
        counted = true;
      }
      var hidden = form.querySelector('[name="count_' + key + '"]');
      if (hidden) {
        hidden.value = count;
      }
      total += Number(key) * Number(count);
    });
    var shown = form.querySelector('[data-count-total]');
    if (shown) {
      shown.textContent = dollars(total);
    }
    var floatField = form.querySelector('[name="opening_float"], [name="counted_cash"]');
    if (floatField && counted) {
      floatField.value = dollars(total);
      floatField.dispatchEvent(new Event('input', { bubbles: true }));
    }
    liveTotal();
    if (closeModal) {
      modal.hidden = true;
    }
  }

  var apply = modal.querySelector('[data-apply-count]');
  if (apply) {
    apply.addEventListener('click', function () {
      applyCount(true);
    });
  }
})();

(function () {
  function cents(value) {
    var text = String(value).trim().replace(/[$,]/g, '');
    if (!/^-?\d+(\.\d{0,2})?$/.test(text)) {
      return null;
    }
    var negative = text.startsWith('-');
    var parts = text.replace('-', '').split('.');
    var whole = Number(parts[0]);
    var fraction = (parts[1] || '').padEnd(2, '0').slice(0, 2);
    var minor = whole * 100 + Number(fraction || '0');
    return negative ? -minor : minor;
  }

  function phrase(diff) {
    var amount = dollars(Math.abs(diff));
    if (diff === 0) {
      return 'Even';
    }
    return '$' + amount.replace('-', '') + (diff > 0 ? '\u00A0over' : '\u00A0short');
  }

  function dollars(minor) {
    var sign = minor < 0 ? '-' : '';
    var value = Math.abs(minor);
    return sign + Math.floor(value / 100) + '.' + String(value % 100).padStart(2, '0');
  }

  document.querySelectorAll('[data-close-sheet]').forEach(function (form) {
    var expected = {
      cash: Number(form.getAttribute('data-expected-cash')),
      card: Number(form.getAttribute('data-expected-card')),
      checks: Number(form.getAttribute('data-expected-checks'))
    };

    function refresh() {
      ['cash', 'card', 'checks'].forEach(function (kind) {
        var input = form.querySelector('[data-counted="' + kind + '"]');
        var cell = form.querySelector('[data-difference="' + kind + '"]');
        if (!input || !cell) {
          return;
        }
        var counted = cents(input.value);
        cell.classList.remove('diff-over', 'diff-short', 'diff-even');
        if (counted === null || input.value.trim() === '') {
          cell.textContent = '—';
          return;
        }
        var diff = counted - expected[kind];
        cell.textContent = phrase(diff);
        cell.classList.add(diff === 0 ? 'diff-even' : (diff > 0 ? 'diff-over' : 'diff-short'));
      });
    }

    form.querySelectorAll('[data-counted]').forEach(function (input) {
      input.addEventListener('input', refresh);
    });
    refresh();
  });

  var lightbox = document.getElementById('register-variance');
  var confirmButton = document.getElementById('register-variance-confirm');
  var lead = document.getElementById('register-variance-lead');
  var combined = document.getElementById('register-variance-combined');
  var panel = lightbox ? lightbox.querySelector('.register-variance__panel') : null;
  var pending = null;
  var skip = false;
  var lastFocus = null;
  if (!lightbox || !confirmButton || !panel) {
    return;
  }

  function money(minor) {
    return '$' + dollars(minor);
  }

  function readCount(input) {
    if (!input || input.value.trim() === '') {
      return 0;
    }
    var minor = cents(input.value);
    if (minor === null || minor < 0) {
      return null;
    }
    return minor;
  }

  function fillCell(id, text, diff) {
    var cell = document.getElementById(id);
    if (!cell) {
      return;
    }
    cell.textContent = text;
    cell.classList.remove('diff-over', 'diff-short', 'diff-even');
    if (diff === 0) {
      cell.classList.add('diff-even');
    } else if (diff > 0) {
      cell.classList.add('diff-over');
    } else if (diff < 0) {
      cell.classList.add('diff-short');
    }
  }

  function hideVariance(restore) {
    lightbox.hidden = true;
    document.removeEventListener('keydown', onVarianceKey, true);
    if (restore !== false && pending) {
      var host = pending.closest('.modal');
      if (host) {
        host.hidden = false;
      }
    }
    if (lastFocus && typeof lastFocus.focus === 'function') {
      lastFocus.focus();
    }
    lastFocus = null;
    pending = null;
  }

  function onVarianceKey(event) {
    if (event.key !== 'Escape' || lightbox.hidden) {
      return;
    }
    event.preventDefault();
    event.stopImmediatePropagation();
    hideVariance();
  }

  function showVariance(form, rows) {
    var off = [];
    var total = 0;
    rows.forEach(function (row) {
      fillCell('register-var-exp-' + row.kind, money(row.expected), null);
      fillCell('register-var-ct-' + row.kind, money(row.counted), null);
      fillCell('register-var-var-' + row.kind, phrase(row.diff), row.diff);
      total += row.diff;
      if (row.diff !== 0) {
        off.push(row.label + (row.kind === 'checks' ? ' are ' : ' is ') + phrase(row.diff));
      }
    });
    var cashRow = null;
    rows.forEach(function (row) {
      if (row.kind === 'cash') {
        cashRow = row;
      }
    });
    if (lead) {
      lead.textContent = cashRow && cashRow.diff !== 0
        ? 'Counted cash does not match the drawer.'
        : off.join('. ') + '.';
    }
    if (combined) {
      combined.textContent = phrase(cashRow && cashRow.diff !== 0 ? cashRow.diff : total);
    }
    pending = form;
    lastFocus = document.activeElement;
    var host = form.closest('.modal');
    if (host) {
      host.hidden = true;
    }
    lightbox.hidden = false;
    document.addEventListener('keydown', onVarianceKey, true);
    panel.focus();
  }

  document.querySelectorAll('[data-close-sheet]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      if (skip) {
        skip = false;
        return;
      }
      if (form.querySelector('[name="confirm_variance"]')) {
        return;
      }
      var submitter = event.submitter;
      if (submitter && submitter.getAttribute('formaction')) {
        return;
      }
      if (form.querySelector('.alert-danger')) {
        return;
      }
      var expected = {
        cash: Number(form.getAttribute('data-expected-cash')),
        card: Number(form.getAttribute('data-expected-card')),
        checks: Number(form.getAttribute('data-expected-checks'))
      };
      var rows = [
        { kind: 'cash', label: 'Cash', expected: expected.cash },
        { kind: 'card', label: 'Card', expected: expected.card },
        { kind: 'checks', label: 'Checks', expected: expected.checks }
      ];
      var mismatch = false;
      var i;
      for (i = 0; i < rows.length; i += 1) {
        var input = form.querySelector('[data-counted="' + rows[i].kind + '"]');
        var counted = readCount(input);
        if (counted === null || !Number.isFinite(rows[i].expected)) {
          return;
        }
        rows[i].counted = counted;
        rows[i].diff = counted - rows[i].expected;
        if (rows[i].diff !== 0) {
          mismatch = true;
        }
      }
      if (!mismatch) {
        return;
      }
      event.preventDefault();
      showVariance(form, rows);
    });
  });

  lightbox.addEventListener('click', function (event) {
    if (event.target.closest('[data-variance-dismiss]')) {
      hideVariance();
    }
  });

  confirmButton.addEventListener('click', function () {
    var form = pending;
    if (!form) {
      return;
    }
    if (typeof form.reportValidity === 'function' && !form.reportValidity()) {
      hideVariance();
      return;
    }
    var field = form.querySelector('[name="confirm_variance"]');
    if (!field) {
      field = document.createElement('input');
      field.type = 'hidden';
      field.name = 'confirm_variance';
      form.appendChild(field);
    }
    field.value = 'yes';
    skip = true;
    hideVariance(false);
    if (typeof form.requestSubmit === 'function') {
      form.requestSubmit();
    } else {
      form.submit();
    }
  });
})();
