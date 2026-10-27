document.getElementById('scope').addEventListener('change', function () {
    const who = document.getElementById('funded_by');
    if (!who) {
        return;
    }
    who.value = this.value === 'store' ? 'store' : 'vendor';
});
