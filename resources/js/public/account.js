import Swal from 'sweetalert2';

export function init() {
    const disconnectForm = document.querySelector('[data-google-disconnect]');
    if (disconnectForm) {
        const trigger = disconnectForm.querySelector('[data-google-disconnect-trigger]');
        const needsPassword = disconnectForm.hasAttribute('data-google-disconnect-needs-password');

        if (trigger) {
            trigger.addEventListener('click', function (event) {
                event.preventDefault();
                const d = disconnectForm.dataset;

                if (needsPassword) {
                    Swal.fire({
                        icon: 'info',
                        title: d.needTitle || 'Set a password first',
                        text: d.needText || 'Please set an account password before disconnecting Google, so you can still sign in.',
                        confirmButtonText: d.needOk || 'OK',
                    });
                    return;
                }

                Swal.fire({
                    icon: 'warning',
                    title: d.confirmTitle || 'Disconnect Google?',
                    text: d.confirmText || 'You will no longer be able to sign in with Google.',
                    showCancelButton: true,
                    confirmButtonText: d.confirmOk || 'Disconnect',
                    cancelButtonText: d.confirmCancel || 'Cancel',
                    confirmButtonColor: '#cc1f1f',
                }).then(function (result) {
                    if (result.isConfirmed) {
                        disconnectForm.submit();
                    }
                });
            });
        }
    }

    const deleteForm = document.querySelector('[data-account-delete-form]');
    if (deleteForm) {
        const checkbox = deleteForm.querySelector('[data-account-delete-confirm]');
        const submitBtn = deleteForm.querySelector('[data-account-delete-submit]');

        if (checkbox && submitBtn) {
            submitBtn.disabled = true;
            checkbox.addEventListener('change', function () {
                submitBtn.disabled = !checkbox.checked;
            });

            submitBtn.addEventListener('click', function (event) {
                event.preventDefault();
                if (!checkbox.checked) {
                    return;
                }

                const d = deleteForm.dataset;
                Swal.fire({
                    icon: 'warning',
                    title: d.confirmTitle || 'Delete your account?',
                    text: d.confirmText || 'This permanently deletes your account and cannot be undone.',
                    showCancelButton: true,
                    confirmButtonText: d.confirmOk || 'Yes, delete my account',
                    cancelButtonText: d.confirmCancel || 'Cancel',
                    confirmButtonColor: '#b91c1c',
                }).then(function (result) {
                    if (result.isConfirmed) {
                        deleteForm.submit();
                    }
                });
            });
        }
    }

    document.querySelectorAll('[data-petition-withdraw]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            const d = form.dataset;
            Swal.fire({
                icon: 'warning',
                title: d.confirmTitle || 'Withdraw this petition?',
                text: d.confirmText || 'It will be taken offline and no longer appear in listings. You cannot undo this yourself: to restore it, contact support.',
                showCancelButton: true,
                confirmButtonText: d.confirmOk || 'Yes, withdraw',
                cancelButtonText: d.confirmCancel || 'Cancel',
                confirmButtonColor: '#b91c1c',
            }).then(function (result) {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        });
    });
}
