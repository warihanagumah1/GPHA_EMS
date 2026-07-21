<div
    x-data="{
        open: false,
        busy: false,
        title: '',
        message: '',
        confirmLabel: 'Confirm',
        tone: 'danger',
        action: null,
        returnFocus: null,
        openDialog(detail) {
            this.title = detail.title || 'Please confirm';
            this.message = detail.message || 'Are you sure you want to continue?';
            this.confirmLabel = detail.confirmLabel || 'Confirm';
            this.tone = detail.tone || 'danger';
            this.action = detail.onConfirm;
            this.returnFocus = document.activeElement;
            this.busy = false;
            this.open = true;
            this.$nextTick(() => this.$refs.cancelButton.focus());
        },
        close() {
            if (this.busy) return;
            this.open = false;
            this.$nextTick(() => this.returnFocus?.focus());
        },
        async proceed() {
            if (this.busy || typeof this.action !== 'function') return;
            this.busy = true;
            try {
                await this.action();
            } catch (error) {
                this.busy = false;
                throw error;
            }
        }
    }"
    x-on:gpha-confirm.window="openDialog($event.detail)"
    x-on:keydown.escape.window="open && close()"
    x-show="open"
    x-cloak
    class="fixed inset-0 z-[200] flex items-center justify-center overflow-y-auto bg-slate-950/60 p-4"
    role="dialog"
    aria-modal="true"
    x-bind:aria-label="title"
>
    <section
        x-show="open"
        x-transition:enter="ease-out duration-200"
        x-transition:enter-start="opacity-0 translate-y-3 scale-95"
        x-transition:enter-end="opacity-100 translate-y-0 scale-100"
        x-transition:leave="ease-in duration-150"
        x-transition:leave-start="opacity-100 translate-y-0 scale-100"
        x-transition:leave-end="opacity-0 translate-y-3 scale-95"
        x-on:click.outside="close()"
        class="w-full max-w-lg overflow-hidden rounded-2xl border border-slate-200 bg-white text-left shadow-2xl"
    >
        <div class="h-1.5" x-bind:class="tone === 'danger' ? 'bg-red-600' : (tone === 'success' ? 'bg-emerald-600' : 'bg-gpha-primary')"></div>
        <div class="p-6">
            <div class="flex items-start gap-4">
                <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full" x-bind:class="tone === 'danger' ? 'bg-red-100 text-red-700' : (tone === 'success' ? 'bg-emerald-100 text-emerald-700' : 'bg-blue-100 text-gpha-primary')">
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 8v5m0 3h.01M10.3 3.84 2.18 18a2 2 0 0 0 1.74 3h16.16a2 2 0 0 0 1.74-3L13.7 3.84a2 2 0 0 0-3.4 0Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>
                <div class="min-w-0 flex-1">
                    <h2 class="text-2xl font-black text-slate-950" x-text="title"></h2>
                    <p class="mt-2 font-semibold text-slate-600" x-text="message"></p>
                </div>
                <button type="button" x-on:click="close()" x-bind:disabled="busy" class="min-h-0 rounded-lg p-1 text-2xl font-black leading-none text-slate-400 shadow-none hover:bg-slate-100 hover:text-slate-700" aria-label="Close confirmation dialog">×</button>
            </div>
            <div class="mt-7 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <button x-ref="cancelButton" type="button" x-on:click="close()" x-bind:disabled="busy" class="gpha-button-secondary">Cancel</button>
                <button type="button" x-on:click="proceed()" x-bind:disabled="busy" x-bind:class="tone === 'danger' ? 'gpha-button-danger' : (tone === 'success' ? 'gpha-button-success' : 'gpha-button-primary')">
                    <span x-show="busy" class="gpha-loading-spinner" aria-hidden="true"></span>
                    <span x-text="busy ? 'Please wait…' : confirmLabel"></span>
                </button>
            </div>
        </div>
    </section>
</div>
