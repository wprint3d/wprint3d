<div>
    <div class="d-flex flex-column min-vh-100 justify-content-center align-items-center">
        <form wire:submit.prevent="submit" class="col-11 col-sm-8 col-md-6 col-lg-4 col-xxl-3 bg-body d-flex flex-column p-4 rounded rounded-3">
            <div class="mb-3">
                <label class="form-label">Email address</label>
                <input wire:model="mailAddress" type="email" class="form-control" aria-describedby="emailHelp">
                <div id="emailHelp" class="form-text">
                    The default e-mail address is <b>{{ CreateSampleUser::SAMPLE_USER_MAIL_ADDRESS }}</b>.
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Password</label>
                <input wire:model="password" type="password" class="form-control" aria-describedby="passwordHelp">
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
                @error('mailAddress') {{ $message }} <br> @enderror
                @error('password')    {{ $message }} <br> @enderror

                @if (enabled( 'login.remember_me' ))
                    @error('rememberMe')  {{ $message }} <br> @enderror
                @endif
            </span>

            <button type="submit" class="btn btn-primary"> Submit </button>
        </form>
    </div>
</div>