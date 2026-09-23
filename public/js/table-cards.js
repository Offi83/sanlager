/*
 * Tabellen mit der Klasse "table-cards" werden auf schmalen Bildschirmen
 * (Smartphone) per CSS als Karten dargestellt, siehe app.css. Dafür
 * bekommt jede Zelle hier die Überschrift ihrer Spalte als data-label
 * (wird dort als kleine Beschriftung angezeigt), und Zellen mit Buttons
 * werden als Aktionszelle markiert. Ohne JavaScript bleibt die Tabelle
 * lesbar, nur ohne Beschriftungen.
 */

document.addEventListener('DOMContentLoaded', function () {

    document.querySelectorAll('table.table-cards').forEach(function (table) {

        const labels = Array.from(
            table.querySelectorAll('thead th')
        ).map(function (th) {
            return th.textContent.trim();
        });

        table.querySelectorAll('tbody tr').forEach(function (row) {

            Array.from(row.children).forEach(function (cell, index) {

                if (cell.tagName !== 'TD') {
                    return;
                }

                if (cell.querySelector('form, button')) {
                    cell.classList.add('cell-actions');
                    return;
                }

                if (index > 0 && labels[index]) {
                    cell.dataset.label = labels[index];
                }

            });

        });

    });

});
