// PFAD: /assets/js/plex/_plex.js

document.addEventListener('DOMContentLoaded', function () {
    const root = document.querySelector('.plex-library-form');
    if (!root) {
        return;
    }

    const selectAllButton = root.querySelector('[data-plex-select-all]');
    const clearAllButton = root.querySelector('[data-plex-clear-all]');
    const checkboxes = root.querySelectorAll('.plex-library-item__checkbox');
    const summaryMeta = root.querySelector('.plex-multiselect__meta');

    const updateCount = function () {
        if (!summaryMeta) {
            return;
        }

        let selected = 0;
        checkboxes.forEach(function (checkbox) {
            if (checkbox.checked) {
                selected += 1;
            }
        });

        summaryMeta.textContent = selected + ' ausgewählt';
    };

    if (selectAllButton) {
        selectAllButton.addEventListener('click', function () {
            checkboxes.forEach(function (checkbox) {
                checkbox.checked = true;
            });
            updateCount();
        });
    }

    if (clearAllButton) {
        clearAllButton.addEventListener('click', function () {
            checkboxes.forEach(function (checkbox) {
                checkbox.checked = false;
            });
            updateCount();
        });
    }

    checkboxes.forEach(function (checkbox) {
        checkbox.addEventListener('change', updateCount);
    });

    updateCount();
});
