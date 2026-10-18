// Модальная форма "Запрос" — открытие/закрытие/отправка через FormSubmit.co
(function () {
    function openRequestForm(e) {
        if (e) e.preventDefault();
        var overlay = document.getElementById('rf-overlay');
        if (overlay) {
            overlay.classList.add('rf-open');
            document.body.style.overflow = 'hidden';
        }
    }

    function closeRequestForm() {
        var overlay = document.getElementById('rf-overlay');
        if (overlay) {
            overlay.classList.remove('rf-open');
            document.body.style.overflow = '';
            resetRequestForm();
        }
    }

    function resetRequestForm() {
        var form = document.getElementById('rf-form');
        var success = document.getElementById('rf-success');
        var error = document.getElementById('rf-error');
        if (form) {
            form.reset();
            form.style.display = 'block';
        }
        if (success) success.style.display = 'none';
        if (error) error.style.display = 'none';
    }

    function submitRequestForm(e) {
        e.preventDefault();
        var form = document.getElementById('rf-form');
        var error = document.getElementById('rf-error');
        var success = document.getElementById('rf-success');
        var btn = document.getElementById('rf-submit-btn');

        if (error) error.style.display = 'none';
        btn.disabled = true;
        btn.textContent = 'Отправка...';

        var formData = new FormData(form);

        fetch('https://formsubmit.co/ajax/help@abv-es.ru', {
            method: 'POST',
            headers: { 'Accept': 'application/json' },
            body: formData
        })
            .then(function (response) {
                if (!response.ok) throw new Error('Bad response');
                return response.json();
            })
            .then(function () {
                form.style.display = 'none';
                if (success) success.style.display = 'block';
            })
            .catch(function () {
                if (error) {
                    error.textContent = 'Не удалось отправить запрос. Попробуйте ещё раз или напишите нам на help@abv-es.ru';
                    error.style.display = 'block';
                }
            })
            .finally(function () {
                btn.disabled = false;
                btn.textContent = 'Отправить';
            });
    }

    window.openRequestForm = openRequestForm;
    window.closeRequestForm = closeRequestForm;

    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('rf-form');
        if (form) form.addEventListener('submit', submitRequestForm);

        var overlay = document.getElementById('rf-overlay');
        if (overlay) {
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) closeRequestForm();
            });
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeRequestForm();
        });
    });
})();
