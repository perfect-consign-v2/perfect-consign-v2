/* Same face as PerfectConsign\Shared\Web\NavClock: en-US, the zone on the clock. */
(function () {
  'use strict';

  const clock = document.querySelector('[data-mall-clock]');
  if (!clock) {
    return;
  }
  const zone = clock.getAttribute('data-time-zone');
  if (!zone) {
    return;
  }
  const dateEl = clock.querySelector('[data-clock-date]');
  const timeEl = clock.querySelector('[data-clock-time]');
  if (!dateEl || !timeEl) {
    return;
  }

  let formatter;
  try {
    formatter = new Intl.DateTimeFormat('en-US', {
      timeZone: zone,
      weekday: 'short',
      month: 'short',
      day: 'numeric',
      year: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
      second: '2-digit',
      hour12: true,
    });
  } catch (error) {
    return;
  }

  function part(parts, type) {
    const found = parts.find(function (item) {
      return item.type === type;
    });
    return found ? found.value : '';
  }

  function paint() {
    let parts;
    try {
      parts = formatter.formatToParts(new Date());
    } catch (error) {
      return;
    }
    const weekday = part(parts, 'weekday');
    const month = part(parts, 'month');
    const day = part(parts, 'day');
    const year = part(parts, 'year');
    const hour = part(parts, 'hour');
    const minute = part(parts, 'minute');
    const second = part(parts, 'second');
    const period = part(parts, 'dayPeriod').toUpperCase();
    if (
      weekday === '' || month === '' || day === '' || year === ''
      || hour === '' || minute === '' || second === '' || period === ''
    ) {
      return;
    }
    dateEl.textContent = weekday + ', ' + month + ' ' + day + ', ' + year;
    timeEl.textContent = hour + ':' + minute + ':' + second + ' ' + period;
    clock.setAttribute('datetime', new Date().toISOString());
  }

  paint();
  window.setInterval(paint, 1000);
}());
