(function () {
    document.querySelectorAll('[data-width-pct]').forEach(function (el) {
        const raw = el.getAttribute('data-width-pct');
        if (raw === null || raw === '') {
            return;
        }
        el.style.setProperty('width', raw + '%');
    });
    document.querySelectorAll('[data-page-width]').forEach(function (el) {
        const width = el.getAttribute('data-page-width');
        const height = el.getAttribute('data-page-height');
        if (width) {
            document.documentElement.style.setProperty('--sticker-page-width', width);
        }
        if (height) {
            document.documentElement.style.setProperty('--sticker-page-height', height);
        }
    });
    document.querySelectorAll('[data-style-prop]').forEach(function (el) {
        const props = el.getAttribute('data-style-prop');
        if (!props) {
            return;
        }
        props.split(';').forEach(function (pair) {
            const parts = pair.split(':');
            if (parts.length < 2) {
                return;
            }
            const name = parts[0].trim();
            const value = parts.slice(1).join(':').trim();
            if (name !== '' && value !== '') {
                el.style.setProperty(name, value);
            }
        });
    });
})();
