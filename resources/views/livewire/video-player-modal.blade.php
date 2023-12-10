<div wire:ignore>
    <div id="videoPlayerModal" class="modal fade" tabindex="-1" aria-modal="true" role="dialog">
        <div class="modal-dialog modal-xl modal-fullscreen-xl-down">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"> Video player </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <video id="videoPlayer" class="w-100 mh-100" autoplay controls></video>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>

window.addEventListener('DOMContentLoaded', () => {

    let videoPlayer      = document.querySelector('#videoPlayer'),
        videoPlayerModal = new bootstrap.Modal(
            document.querySelector('#videoPlayerModal')
        );

    window.addEventListener('openVideoURL', event => {
        console.debug('openVideoURL:', event.detail);

        videoPlayer.src = event.detail.src;

        videoPlayerModal.show();
    });

});

</script>
@endpush
