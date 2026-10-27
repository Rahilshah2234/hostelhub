// HostelHub Client-side Interactions & Validations
document.addEventListener('DOMContentLoaded', function () {
    // Bootstrap form validation
    const forms = document.querySelectorAll('.needs-validation');
    Array.prototype.slice.call(forms).forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        }, false);
    });

    // Validate registration phone number inputs
    const registrationPhoneInputs = document.querySelectorAll(
        'input[name="phone"], input[name="emergency_phone"]'
    );

    registrationPhoneInputs.forEach(function (input) {
        input.addEventListener('input', function () {
            // Allow only digits and limit the input to 10.
            input.value = input.value.replace(/\D/g, '').slice(0, 10);

            if (input.value.length > 0 && input.value.length < 10) {
                input.setCustomValidity('Enter exactly 10 digits.');
            } else {
                input.setCustomValidity('');
            }
        });

        input.addEventListener('paste', function (event) {
            event.preventDefault();

            let digits = event.clipboardData
                .getData('text')
                .replace(/\D/g, '');

            // Remove the Indian country code if included in pasted text.
            if (digits.length === 12 && digits.startsWith('91')) {
                digits = digits.slice(2);
            }

            if (digits.length === 10) {
                input.value = digits;
                input.setCustomValidity('');
                input.dispatchEvent(new Event('input', { bubbles: true }));
            } else {
                input.value = digits.length < 10 ? digits : '';
                input.setCustomValidity(
                    'Enter exactly 10 digits. You may include +91 when pasting.'
                );
                input.reportValidity();
            }
        });
    });
    // Show/hide password toggle on the login form
    const passwordInput = document.getElementById('password');
    const togglePassword = document.getElementById('togglePassword');

    if (passwordInput && togglePassword) {
        const eyeIcon = togglePassword.querySelector('i');

        togglePassword.addEventListener('click', function () {
            const shouldShow = passwordInput.type === 'password';

            // Toggle visibility without changing the password value.
            passwordInput.type = shouldShow ? 'text' : 'password';

            togglePassword.setAttribute(
                'aria-label',
                shouldShow ? 'Hide password' : 'Show password'
            );

            togglePassword.setAttribute('aria-pressed', String(shouldShow));

            // Update the eye icon.
            if (eyeIcon) {
                eyeIcon.classList.toggle('bi-eye', !shouldShow);
                eyeIcon.classList.toggle('bi-eye-slash', shouldShow);
            }
        });
    }

    // Auto-dismiss alerts after 5 seconds
    const alerts = document.querySelectorAll('.alert:not(.alert-permanent)');
    alerts.forEach(function(alert) {
        setTimeout(function() {
            try {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            } catch (e) {}
        }, 5000);
    });

    // Dynamic search table filter helper
    const searchInputs = document.querySelectorAll('[data-table-search]');
    searchInputs.forEach(function (input) {
        const targetTableSelector = input.getAttribute('data-table-search');
        const targetTable = document.querySelector(targetTableSelector);
        if (!targetTable) return;

        input.addEventListener('input', function () {
            const query = input.value.toLowerCase().trim();
            const rows = targetTable.querySelectorAll('tbody tr');
            rows.forEach(function (row) {
                const text = row.textContent.toLowerCase();
                if (text.includes(query)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    });

    // Print button trigger
    const printBtns = document.querySelectorAll('[data-action="print"]');
    printBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            window.print();
        });
    });

    // Confirmation on destructive actions
    const confirmButtons = document.querySelectorAll('[data-confirm]');
    confirmButtons.forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            const msg = btn.getAttribute('data-confirm') || 'Are you sure you want to proceed?';
            if (!confirm(msg)) {
                e.preventDefault();
            }
        });
    });
});
