@push('head')
    <style> .rollable { transition: max-height .5s ease-in-out; } </style>
@endpush

<div>
    <div class="d-flex flex-column min-vh-100 justify-content-center align-items-center">
        <div wire:ignore class="animate__animated animate__slow animate__fadeIn px-4 py-1 my-1 text-center">
            <h1 class="d-none d-md-block display-4 fw-bold text-body-emphasis">
                {{ env('APP_NAME') }}
            </h1>

            <h1 class="d-block d-md-none display-1 fw-bold text-body-emphasis">
                {{ env('APP_NAME') }}
            </h1>

            <div class="rollable col-auto mx-auto" style="max-height: 100vh;">
                <div id="spinner" class="spinner-border" role="status">
                    <span class="visually-hidden"> Loading assets... </span>
                </div>

                <div class="col-auto mx-auto overflow-hidden mt-1">
                    <p class="lead mb-4">
                        Please wait for a while, we're still loading some assets...
                    </p>
                </div>
            </div>

            <div class="rollable col-auto mx-auto overflow-hidden" style="max-height: 0;">
                <p id="realLead" class="lead mb-4">
                    Welcome back! You can now log in to your account.
                </p>
            </div>
        </div>

        <form wire:ignore.self wire:submit="submit" class="rollable col-11 col-sm-8 col-md-6 col-lg-4 col-xxl-3 bg-body rounded rounded-3 overflow-hidden" style="max-height: 0;">
            <div class="d-flex flex-column p-4 border rounded rounded-3">
                <div class="mb-3">
                    <label class="form-label">Username or email address</label>
                    <input wire:model="identifier" type="text" class="form-control" aria-describedby="emailHelp" @if ($loggingIn) readonly @endif>
                    <div id="emailHelp" class="form-text">
                        The default username is <b>{{ CreateSampleUser::SAMPLE_USER_NAME }}</b>.
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <input wire:model="password" type="password" class="form-control" aria-describedby="passwordHelp" @if ($loggingIn) readonly @endif>
                    <div id="passwordHelp" class="form-text">
                        The default passsword is <b>{{ CreateSampleUser::SAMPLE_USER_PASSWORD }}</b>.
                    </div>
                </div>

                @if (enabled( 'login.remember_me' ))
                    <div class="mb-2 form-check">
                        <input wire:model="rememberMe" type="checkbox" class="form-check-input">

                        <label class="form-check-label">
                            Remember me on this device
                        </label>
                    </div>
                @endif

                <span class="text-danger mb-3 text-center text-danger">
                    @error('identifier') {{ $message }} @if ($logoutReason && $logoutReason != LogoutReason::USER_REQUEST) <br> @endif <br> @enderror
                    @error('password')   {{ $message }} @if ($logoutReason && $logoutReason != LogoutReason::USER_REQUEST) <br> @endif <br> @enderror

                    @switch ($logoutReason)
                        @case (LogoutReason::ACCOUNT_CHANGED)
                            Your account settings have changed and you've been logged out, please try to log in again. <br>
                            <br>
                            If you can't log in, contact your system administrator for help.

                            @break
                    @endswitch

                    @if (enabled( 'login.remember_me' ))
                        @error('rememberMe')  {{ $message }} <br> @enderror
                    @endif
                </span>

                <button type="submit" class="btn btn-primary" @if ($loggingIn) disabled @endif>
                    <div wire:loading>
                        <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                        <span class="visually-hidden"> Loading... </span>

                        Logging in...
                    </div>

                    @if ($loggingIn)
                        <span class="animate__animated animate__flash animate__infinite animate__slower">
                            Redirecting... 
                        </span>
                    @else
                        <span wire:loading.class="d-none">
                            Submit
                        </span>                    
                    @endif
                </button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>

const getTransitionDurationMillis = element => (
    parseFloat(
        getComputedStyle(element).transitionDuration
    ) * 1000
);

document.addEventListener('DOMContentLoaded', () => {
    let spinner = document.querySelector('#spinner'),
        lead    = document.querySelector('#realLead'),
        form    = document.querySelector('form');

    if (spinner) {
        spinner = spinner.parentElement;

        spinner.style.maxHeight = '0px';
        spinner.classList.add('animate__animated');
        spinner.classList.add('animate__fadeOut');
    }

    setTimeout(() => {
        if (lead) {
            lead = lead.parentElement;

            lead.style.maxHeight = '100vh';
        }

        if (form) {
            form.style.maxHeight = '100vh';
        }

        spinner.remove();
    }, getTransitionDurationMillis(spinner));
});

</script>
@endpush