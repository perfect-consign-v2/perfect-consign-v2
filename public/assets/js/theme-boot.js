(function () {
    try {
        const theme = localStorage.getItem('pc-theme') || localStorage.getItem('nem-theme');
        const mode = localStorage.getItem('pc-mode') || localStorage.getItem('nem-mode');
        const root = document.documentElement;
        if (theme) {
            root.setAttribute('data-theme', theme);
            localStorage.setItem('pc-theme', theme);
        }
        if (mode) {
            root.setAttribute('data-mode', mode);
            localStorage.setItem('pc-mode', mode);
        }
    } catch (e) {
        // localStorage may be blocked
    }
})();
