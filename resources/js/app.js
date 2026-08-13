const loadingMarkup = (label) => `<span class="gpha-loading-spinner" aria-hidden="true"></span><span>${label}</span>`;

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.dataset.confirmTitle || form.dataset.confirmBypass === 'true') return;

    event.preventDefault();
    const submitter = event.submitter instanceof HTMLElement ? event.submitter : undefined;

    window.dispatchEvent(new CustomEvent('gpha-confirm', {
        detail: {
            title: form.dataset.confirmTitle,
            message: form.dataset.confirmMessage,
            confirmLabel: form.dataset.confirmLabel,
            tone: form.dataset.confirmTone || 'danger',
            onConfirm: () => {
                form.dataset.confirmBypass = 'true';
                form.requestSubmit(submitter);
                delete form.dataset.confirmBypass;
            },
        },
    }));
}, true);

document.addEventListener('click', (event) => {
    if (!(event.target instanceof Element)) return;
    const trigger = event.target.closest('[data-mark-all-responded]');
    if (!(trigger instanceof HTMLButtonElement)) return;

    const form = trigger.closest('form');
    if (!form) return;

    form.querySelectorAll('select[data-response]:not(:disabled)').forEach((field) => {
        field.value = '1';
        field.dispatchEvent(new Event('change', { bubbles: true }));
    });
});

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || event.defaultPrevented || form.dataset.noLoading !== undefined) return;

    const emptyEditor = [...form.querySelectorAll('[data-rich-required]')].find((editor) => !editor.innerText.trim());
    if (emptyEditor) {
        event.preventDefault();
        emptyEditor.classList.add('has-error');
        emptyEditor.focus();
        return;
    }

    window.setTimeout(() => {
        if (event.defaultPrevented) return;
        form.setAttribute('aria-busy', 'true');
        form.querySelectorAll('button:not([type]), button[type="submit"], input[type="submit"]').forEach((control) => {
            if (control.disabled) return;
            control.disabled = true;
            control.classList.add('is-loading');
            if (control instanceof HTMLButtonElement) {
                control.dataset.originalHtml = control.innerHTML;
                control.innerHTML = loadingMarkup(control.dataset.loadingText || 'Please wait…');
            } else {
                control.dataset.originalValue = control.value;
                control.value = control.dataset.loadingText || 'Please wait…';
            }
        });
    }, 0);
});

document.addEventListener('input', (event) => {
    if (event.target instanceof HTMLElement && event.target.matches('[data-rich-required]')) event.target.classList.remove('has-error');
});

window.addEventListener('pageshow', () => {
    document.querySelectorAll('form[aria-busy="true"]').forEach((form) => {
        form.removeAttribute('aria-busy');
        form.querySelectorAll('.is-loading').forEach((control) => {
            control.disabled = false;
            control.classList.remove('is-loading');
            if (control instanceof HTMLButtonElement && control.dataset.originalHtml) control.innerHTML = control.dataset.originalHtml;
            if (control instanceof HTMLInputElement && control.dataset.originalValue) control.value = control.dataset.originalValue;
        });
    });
});

document.addEventListener('click', async (event) => {
    if (!(event.target instanceof Element)) return;
    const button = event.target.closest('[data-download-analytics-snapshot]');
    if (!(button instanceof HTMLButtonElement) || button.disabled) return;

    const target = document.getElementById(button.dataset.target || '');
    const status = document.querySelector('[data-snapshot-status]');
    if (!target) return;

    const originalHtml = button.innerHTML;
    button.disabled = true;
    button.classList.add('is-loading');
    button.innerHTML = loadingMarkup('Preparing PNG…');
    if (status) status.textContent = 'Rendering management snapshot…';

    try {
        if (document.fonts?.ready) await document.fonts.ready;
        const { default: html2canvas } = await import('html2canvas');
        const canvas = await html2canvas(target, {
            backgroundColor: '#f1f5f9',
            logging: false,
            scale: 2,
            scrollX: 0,
            scrollY: -window.scrollY,
            useCORS: true,
            windowWidth: Math.max(document.documentElement.clientWidth, target.scrollWidth),
        });
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
        if (!(blob instanceof Blob)) throw new Error('The snapshot image could not be created.');

        const link = document.createElement('a');
        const objectUrl = URL.createObjectURL(blob);
        link.href = objectUrl;
        link.download = button.dataset.filename || 'gpha-ems-management-analytics.png';
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(objectUrl);
        if (status) status.textContent = 'Snapshot downloaded.';
    } catch (error) {
        console.error('Management snapshot failed', error);
        if (status) status.textContent = 'Snapshot could not be created. Please try again.';
    } finally {
        button.disabled = false;
        button.classList.remove('is-loading');
        button.innerHTML = originalHtml;
    }
});
