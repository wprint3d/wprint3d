<div class="text-start">
    <div class="text-center py-4 w-100">
        <span class="fw-bold">{{ env('APP_NAME') }} ({{ getAppRevision() }})</span> is open-source software licensed under the <span class="fw-bold">MIT license</span>. <br>
        <br>
        For more information about licensing, security and other administrative procedures please refer to the <span class="fw-bold">README.md</span> file provided as part of <a href="https://github.com/wprint3d/wprint3d">the repository</a>. <br>
        <br>
        If you want to know more about the licensing specifications of most of our third-party dependencies, please refer to the documentation provided below.
    </div>
    @if ($licenses)
    <pre>{{ $licenses }}</pre>
    @else
    <div class="d-flex justify-content-center">
        <div class="d-flex flex-column align-items-center">
            <div class="spinner-border" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="pt-3"> Downloading third-party licenses... </p>
        </div>
    </div>
    @endif
</div>
