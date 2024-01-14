<div>
    <ul class="dropdown-menu text-small">
        @if (enabled( 'settings.profile' ))
            <li><a class="dropdown-item" href="#" id="showProfileModalBtn">Profile</a></li>
        @endif

        <li><a class="dropdown-item" href="#" id="showSettingsModalBtn">Settings</a></li>

        <li>
            <hr class="dropdown-divider">
        </li>

        <li><a wire:click="logout" class="dropdown-item" href="#">Sign out</a></li>
    </ul>
</div>

@push('scripts')
<script>

window.addEventListener('DOMContentLoaded', () => {
    let settingsModal = new bootstrap.Modal(
        document.querySelector('#settingsModal')
    );

    let profileModal = new bootstrap.Modal(
        document.querySelector('#profileModal')
    );

    document.querySelector('#showSettingsModalBtn').addEventListener('click', () => {
        settingsModal.show();
    });

    document.querySelector('#showProfileModalBtn').addEventListener('click', () => {
        profileModal.show();
    });
});

</script>
@endpush