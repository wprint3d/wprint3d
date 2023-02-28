<div>
    <ul class="dropdown-menu text-small" style="">
        <li><a class="dropdown-item" href="#">New project...</a></li>
        <li><a class="dropdown-item" href="#" id="showSettingsModalBtn">Settings</a></li>
        <li><a class="dropdown-item" href="#">Profile</a></li>
        <li>
            <hr class="dropdown-divider">
        </li>
        <li><a wire:click="logout" class="dropdown-item" href="#">Sign out</a></li>
    </ul>
</div>

<livewire:settings-modal />

@push('scripts')
<script>

window.addEventListener('DOMContentLoaded', () => {
    let settingsModal = new bootstrap.Modal(
        document.querySelector('#settingsModal')
    );

    document.querySelector('#showSettingsModalBtn').addEventListener('click', () => {
        settingsModal.show();
    });
});

</script>
@endpush