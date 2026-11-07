function initializeOrganizationSharing() {
    document.querySelectorAll('[data-organization-share]').forEach((root) => {
        if (root.dataset.initialized) return;
        root.dataset.initialized = 'true';
        const status = root.querySelector('[data-share-status]');
        async function copy(text) {
            try {
                await navigator.clipboard.writeText(text);
                status.textContent = status.dataset.copied;
            } catch {
                status.textContent = status.dataset.failed;
                const fallback = root.querySelector('[data-copy-fallback]');
                fallback.hidden = false;
                const input = fallback.querySelector('input');
                input.value = text;
                input.focus();
                input.select();
            }
        }
        root.querySelectorAll('[data-copy-text]').forEach((button) => {
            button.addEventListener('click', () => copy(button.dataset.copyText));
        });
        root.querySelector('[data-share-url]')?.addEventListener('click', async (event) => {
            const button = event.currentTarget;
            if (navigator.share) {
                try {
                    await navigator.share({ title: button.dataset.shareTitle, url: button.dataset.shareUrl });
                    return;
                } catch (error) {
                    if (error.name === 'AbortError') return;
                }
            }
            await copy(button.dataset.shareUrl);
        });
    });
}
initializeOrganizationSharing();
document.addEventListener('DOMContentLoaded', initializeOrganizationSharing);
