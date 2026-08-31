<div
    x-data
    x-on:reconciliation-assistant-finalized.window="
        const modal = $el.closest('[data-fi-modal-id]');
        if (modal) window.dispatchEvent(new CustomEvent('close-modal', { detail: { id: modal.id } }));
    "
    x-on:reconciliation-assistant-cancelled.window="
        const modal = $el.closest('[data-fi-modal-id]');
        if (modal) window.dispatchEvent(new CustomEvent('close-modal', { detail: { id: modal.id } }));
    "
>
    <p style="margin-bottom: .75rem; text-align: right;">
        <a href="{{ $fallbackUrl }}" class="fi-link">
            {{ __('filament-accounting::actions.open_fallback_page') }}
        </a>
    </p>
    <livewire:filament-accounting.reconciliation-assistant
        :line="$line"
        context="modal"
        :key="'reconciliation-assistant-'.$line"
        @reconciliation-assistant-finalized="$refresh"
    />
</div>
