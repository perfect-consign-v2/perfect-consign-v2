(function () {
    const table = document.getElementById('list-pay-vendors');
    if (table === null) {
        return;
    }
    let length = 10;
    window.addEventListener('beforeprint', function () {
        if (typeof DataTable === 'undefined' || table.getAttribute('data-dt-ready') !== '1') {
            return;
        }
        const api = new DataTable(table);
        length = api.page.len();
        api.page.len(-1).draw(false);
    });
    window.addEventListener('afterprint', function () {
        if (typeof DataTable === 'undefined' || table.getAttribute('data-dt-ready') !== '1') {
            return;
        }
        new DataTable(table).page.len(length).draw(false);
    });
}());
