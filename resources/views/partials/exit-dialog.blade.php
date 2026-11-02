{{-- "Leave PDF templates?" confirmation for [data-pt-exit] links (they leave the package for config back_url).
     Skipped when another handler already took the click (editor with unsaved changes → its "save & leave" dialog). --}}
<dialog class="pt-dialog pt-dialog-sm" id="pt-exit-dialog">
    <div class="pt-dialog-body">
        <h2>{{ __('pdf-templates::ui.exit_title') }}</h2>
        <p class="pt-muted" style="margin: 0;">{{ __('pdf-templates::ui.exit_text') }}</p>
    </div>
    <div class="pt-dialog-foot">
        <button type="button" class="pt-btn pt-btn-ghost" onclick="this.closest('dialog').close()">{{ __('pdf-templates::ui.cancel') }}</button>
        <a class="pt-btn pt-btn-primary" id="pt-exit-go" href="#">
            <x-pdf-templates::icon name="log-out" :size="14" /> {{ __('pdf-templates::ui.exit_confirm') }}
        </a>
    </div>
</dialog>
<script>
    document.addEventListener('click', (e) => {
        const link = e.target.closest && e.target.closest('[data-pt-exit]');
        if (!link || e.defaultPrevented) return;
        e.preventDefault();
        document.getElementById('pt-exit-go').href = link.href;
        document.getElementById('pt-exit-dialog').showModal();
    });
</script>
