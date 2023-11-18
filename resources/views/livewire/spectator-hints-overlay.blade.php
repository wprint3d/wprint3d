<div>
    @if ($isSpectator)
    <button
        id="spectatorHint"
        class="position-fixed z-3 bg-white border border-1 border-dark-subtle bottom-0 mb-3 mx-3 rounded rounded-circle shadow-sm start-0 p-2"
        style="width: 3.75em;"
        data-bs-toggle="tooltip"
        data-bs-html="true"
        data-bs-offset="75,0"
        data-bs-title="
            This is a read-only profile. <br>
            <br>
            If you think this is an error, please contact your system administrator.
        "
    >
        @svg('question-circle', [ 'width' => '100%', 'height' => '100%', 'style' => '' ])
        <span class="visually-hidden"> Account type notice </span>
    </button>
    @endif
</div>
