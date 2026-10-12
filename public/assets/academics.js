'use strict';

document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!window.confirm(form.dataset.confirm)) {
            event.preventDefault();
        }
    });
});

document.querySelectorAll('[data-mark-all-present]').forEach((button) => {
    button.addEventListener('click', () => {
        const form = button.closest('form');
        if (!form) {
            return;
        }

        form.querySelectorAll('select[name^="attendance["]').forEach((select) => {
            select.value = 'present';
        });
    });
});
