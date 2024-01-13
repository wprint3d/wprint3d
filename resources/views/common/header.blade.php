<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    @vite([ 'resources/js/app.js', 'resources/css/app.scss' ])

    @stack('head')

    @livewireStyles
</head>

<body>
    @if (Auth::user())
        <livewire:job-recovery-modal />
        <livewire:video-player-modal />

        <header class="p-3 mb-3 border-bottom bg-primary">
            <div class="mx-0 mx-lg-2">
                <div class="d-flex flex-wrap align-items-center justify-content-center justify-content-lg-start">
                    <a href="/" class="d-flex align-items-center text-light text-decoration-none">
                        {{ env('APP_NAME') }}
                    </a>

                    <div class="col"></div>

                    {{--
                    <ul class="nav col col-lg-auto me-lg-auto justify-content-center">
                        <li><a href="#" class="nav-link px-2 link-secondary">Overview</a></li>
                        <li><a href="#" class="nav-link px-2 link-dark">Inventory</a></li>
                        <li><a href="#" class="nav-link px-2 link-dark">Customers</a></li>
                        <li><a href="#" class="nav-link px-2 link-dark">Products</a></li>
                    </ul>
                    --}}

                    {{--
                    <form class="col-12 col-lg-auto mb-3 mb-lg-0 me-lg-3" role="search">
                        <input type="search" class="form-control" placeholder="Search..." aria-label="Search">
                    </form>
                    --}}

                    <div class="dropdown text-end">
                        <a href="#" class="d-block link-dark text-decoration-none dropdown-toggle"
                            data-bs-toggle="dropdown" aria-expanded="false">
                            @svg('person-circle', [ 'class' => 'bg-white border fs-3 rounded-circle' ])
                        </a>
                        <livewire:user-options-dropdown />
                    </div>
                </div>
            </div>
        </header>
    @endif
    <main>

<script>

window.handleMissingImage = element => {
    console.error('Image load error: ', element.src);

    element.outerHTML = `
        <div class="d-flex flex-row justify-content-center w-100 h-100 border">
            <p class="d-flex align-items-center fs-4 m-0">
                @svg('file-x')
            </p>
        </div>`;
};

window.addEventListener('forceRedirect', event => {
    window.location.href = event.detail.route;
});

</script>