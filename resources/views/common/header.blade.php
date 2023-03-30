<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    @vite([ 'resources/js/app.js', 'resources/css/app.scss' ])
    @livewireStyles
</head>

<body>
    @if (Auth::user())
        <livewire:job-recovery-modal />

        <header class="p-3 mb-3 border-bottom bg-primary">
            <div class="mx-0 mx-lg-2">
                <div class="d-flex flex-wrap align-items-center justify-content-center justify-content-lg-start">
                    <a href="/" class="d-flex align-items-center text-dark text-decoration-none">
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
                            <img src="https://github.com/mdo.png" alt="mdo" width="32" height="32"
                                class="rounded-circle">
                        </a>
                        <livewire:user-options-dropdown />
                    </div>
                </div>
            </div>
        </header>
    @endif
    <main>
