<div>
    <div class="d-flex flex-column min-vh-100 justify-content-center align-items-center">
        <form wire:submit.prevent="submit" class="col-11 col-sm-8 col-md-6 col-lg-4 col-xxl-3 bg-body d-flex flex-column p-4 rounded rounded-3">
            <div class="mb-3">
                <label for="exampleInputEmail1" class="form-label">Email address</label>
                <input wire:model="mailAddress" type="email" class="form-control" id="exampleInputEmail1" aria-describedby="emailHelp">
                <div id="emailHelp" class="form-text">We'll never share your email with anyone else.</div>
            </div>
            <div class="mb-3">
                <label for="exampleInputPassword1" class="form-label">Password</label>
                <input wire:model="password" type="password" class="form-control" id="exampleInputPassword1">
            </div>
            <div class="mb-3 form-check">
                <input wire:model="rememberMe" type="checkbox" class="form-check-input" id="exampleCheck1">
                <label class="form-check-label" for="exampleCheck1">
                    Remember me on this device
                </label>
            </div>

            <span class="text-danger mb-3 text-center text-danger">
                @error('mailAddress') {{ $message }} <br> @enderror
                @error('password')    {{ $message }} <br> @enderror
                @error('rememberMe')  {{ $message }} <br> @enderror
            </span>

            <button type="submit" class="btn btn-primary"> Submit </button>
        </form>
    </div>
</div>