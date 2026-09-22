/*
 * Generisches Drag-&-Drop-Sortieren für Listen (Kategorien, Lagerorte).
 *
 * Wird auf jedes Element mit [data-sortable-list] angewendet. Benötigte
 * data-Attribute auf dem Container:
 *   data-sortable-row            CSS-Selektor der einzelnen Zeilen
 *   data-sortable-id-attribute   Name des data-*-Attributs der Zeile,
 *                                 das die ID enthält (camelCase, wie im
 *                                 JS-.dataset), z. B. "categoryId" für
 *                                 data-category-id
 *   data-sortable-action         POST-action-Wert für den Reorder-Request
 */

document.addEventListener('DOMContentLoaded', () => {

    document
        .querySelectorAll('[data-sortable-list]')
        .forEach(initSortableList);

});


function initSortableList(list) {

    const rowSelector = list.dataset.sortableRow;
    const idAttribute = list.dataset.sortableIdAttribute;
    const action = list.dataset.sortableAction;

    if (!rowSelector || !idAttribute || !action) {
        return;
    }

    let dragged = null;


    list.addEventListener('dragstart', event => {

        const row = event.target.closest(rowSelector);

        if (!row) {
            return;
        }

        dragged = row;
        row.classList.add('dragging');

        event.dataTransfer.effectAllowed = 'move';

    });


    list.addEventListener('dragend', event => {

        const row = event.target.closest(rowSelector);

        if (row) {
            row.classList.remove('dragging');
        }

        dragged = null;

        saveOrder();

    });


    list.addEventListener('dragover', event => {

        event.preventDefault();

        if (!dragged) {
            return;
        }

        const target = event.target.closest(rowSelector);

        if (!target || target === dragged) {
            return;
        }

        const rect = target.getBoundingClientRect();

        const before =
            event.clientY <
            rect.top + rect.height / 2;

        if (before) {
            list.insertBefore(dragged, target);
        } else {
            list.insertBefore(
                dragged,
                target.nextSibling
            );
        }

    });


    function saveOrder() {

        const ids = [
            ...list.querySelectorAll(rowSelector)
        ].map(row => row.dataset[idAttribute]);


        const formData = new FormData();

        formData.append('action', action);

        ids.forEach(id => {
            formData.append('ids[]', id);
        });


        fetch('', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {

            if (!data.success) {

                alert(
                    data.error ||
                    'Die Reihenfolge konnte nicht gespeichert werden.'
                );

            }

        })
        .catch(() => {

            alert(
                'Die Reihenfolge konnte nicht gespeichert werden.'
            );

        });

    }

}
