(function () {
    const root = document.documentElement;
    try {
        const theme = localStorage.getItem('pc-theme') || localStorage.getItem('nem-theme') || 'dark-blue';
        const mode = localStorage.getItem('pc-mode') || localStorage.getItem('nem-mode') || 'light';
        root.setAttribute('data-theme', theme);
        root.setAttribute('data-mode', mode);
        localStorage.setItem('pc-theme', theme);
        localStorage.setItem('pc-mode', mode);
    } catch (e) {
        // localStorage may be blocked
    }
    // Reveal if theme.css never arrives; enable shell transitions after first paint.
    window.setTimeout(function () {
        root.setAttribute('data-css-fallback', '1');
    }, 2500);
    window.requestAnimationFrame(function () {
        window.requestAnimationFrame(function () {
            root.classList.add('pc-ui');
        });
    });
})();
