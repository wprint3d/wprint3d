<div>
    <div class="d-flex flex-column min-vh-100 justify-content-center align-items-center">
        <form wire:submit.prevent="submit" class="col-11 col-sm-8 col-md-6 col-lg-4 col-xxl-3 bg-body d-flex flex-column p-4 rounded rounded-3">
            <div class="mb-3">
                <label class="form-label">Username or email address</label>
                <input wire:model.lazy="identifier" type="text" class="form-control" aria-describedby="emailHelp">
                <div id="emailHelp" class="form-text">
                    The default username is <b>{{ CreateSampleUser::SAMPLE_USER_NAME }}</b>.
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Password</label>
                <input wire:model.lazy="password" type="password" class="form-control" aria-describedby="passwordHelp">
                <div id="passwordHelp" class="form-text">
                    The default passsword is <b>{{ CreateSampleUser::SAMPLE_USER_PASSWORD }}</b>.
                </div>
            </div>

            @if (enabled( 'login.remember_me' ))
                <div class="mb-2 form-check">
                    <input wire:model.lazy="rememberMe" type="checkbox" class="form-check-input">

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

            <button type="submit" class="btn btn-primary"> Submit </button>
        </form>
    </div>
</div>