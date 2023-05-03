<div>
    <ul class="nav nav-tabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="terminal-tab" data-bs-toggle="tab" data-bs-target="#terminal-tab-pane"
                    type="button" role="tab" aria-controls="terminal-tab-pane" aria-selected="true"> Terminal </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="preview-tab" data-bs-toggle="tab" data-bs-target="#preview-tab-pane"
                    type="button" role="tab" aria-controls="preview-tab-pane" aria-selected="false"> Preview </button>
        </li>

        @if ($enableControlTab)
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="control-tab" data-bs-toggle="tab" data-bs-target="#control-tab-pane"
                    type="button" role="tab" aria-controls="control-tab-pane" aria-selected="false"> Control </button>
        </li>
        @endif
    </ul>
    <div class="tab-content m-2">
        <div class="tab-pane fade show active" id="terminal-tab-pane" role="tabpanel" aria-labelledby="terminal-tab" tabindex="0">
            <livewire:terminal />
        </div>
        <div class="tab-pane fade" id="preview-tab-pane" role="tabpanel" aria-labelledby="preview-tab" tabindex="0">
            <livewire:gcode-preview />
        </div>
        <div class="tab-pane fade" id="control-tab-pane" role="tabpanel" aria-labelledby="control-tab" tabindex="0">
            <livewire:printer-control />
        </div>
    </div>
</div>

@push('scripts')
<script>

window.addEventListener('DOMContentLoaded', () => {

    window.addEventListener('resetDefaultTab', () => {
        document.querySelector('#terminal-tab-pane').click();
    });

});

</script>
@endpush