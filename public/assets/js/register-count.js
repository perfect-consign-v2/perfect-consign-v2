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
      total += key * Math.floor(count);
    });
    var label = modal.querySelector('[data-live-total]');
    if (label) {
      label.textContent = dollars(total);
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
