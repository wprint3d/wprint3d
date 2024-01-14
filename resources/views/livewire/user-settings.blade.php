<div class="col-12 col-sm-6 col-xxl-12 border-bottom py-2">
    <div class="row">
        <div class="col-12 col-xxl-3 mb-2 mb-xxl-0">
            <label for="name" class="form-label"> Username </label>
            <div class="input-group">
                <input
                    wire:model.live="name"
                    type="text"
                    class="form-control"
                    placeholder="example"
                    aria-label="Username"
                    value="{{ $name }}"
                >

                <button
                    wire:click="revert('name')"
                    class="btn btn-primary"
                    type="button"
                    data-bs-toggle="tooltip" data-bs-title="Revert changes."
                    @if ($name == $user->name) disabled @endif
                >
                    @svg('bootstrap-reboot')
                </button>
            </div>
        </div>

        <div class="col-12 col-xxl-3 mb-2 mb-xxl-0">
            <label for="name" class="form-label"> E-mail address </label>
            <div class="input-group">
                <input
                    wire:model.live="email"
                    type="text"
                    class="form-control"
                    placeholder="user@example.com"
                    aria-label="E-mail address"
                    value="{{ $email }}"
                >

                <button
                    wire:click="revert('email')"
                    class="btn btn-primary"
                    type="button"
                    data-bs-toggle="tooltip" data-bs-title="Revert changes."
                    @if ($email == $user->email) disabled @endif
                >
                    @svg('bootstrap-reboot')
                </button>
            </div>
        </div>

        <div class="col-12 col-xxl-2 mb-2 mb-xxl-0">
            <label for="name" class="form-label"> Role </label>
            <div class="input-group">
                <select
                    wire:model.live="role"
                    class="form-select"
                    @if ($user->_id == $selfUserId) disabled @endif
                >
                    @foreach (UserRole::asArray() as $key => $value)
                        <option
                            value="{{ $value }}"
                            @if ($role == $value) selected @endif
                        >
                            {{ Str::title( $key ) }}
                        </option>
                    @endforeach
                </select>

                <button
                    wire:click="revert('role')"
                    class="btn btn-primary"
                    type="button"
                    data-bs-toggle="tooltip" data-bs-title="Revert changes."
                    @if ($role == $user->role) disabled @endif
                >
                    @svg('bootstrap-reboot')
                </button>
            </div>
        </div>

        @if ($isChangingPassword)
        <div class="col-12 col-xxl-2 mb-2 mb-xxl-0">
            <label for="name" class="form-label"> New password </label>
            <div class="input-group">
                <input
                    wire:model.live="newPassword"
                    type="password"
                    class="form-control"
                    placeholder="Password"
                    aria-label="New password"
                    value="{{ $newPassword }}"
                >

                <button wire:click="changePassword" class="btn btn-primary" type="button">
                    @svg('check')
                </button>
            </div>
        </div>
        @else
        <div class="col-12 col-xxl-2 mb-1 mb-xxl-0 align-self-end">
            <button wire:click="enablePasswordChange" class="btn btn-primary w-100">
                @if ($hasPassword)
                    @svg('key')
                    Change password
                @else
                    @svg('key', [ 'class' => 'animate__animated animate__flash animate__infinite animate__slow' ])
                    Set password
                @endif
            </button>
        </div>
        @endif

        @if ($isDeleting)
        <div class="col-12 col-xxl-1 mb-1 mb-xxl-0 align-self-end">
            <button wire:click="delete" class="btn btn-danger w-100">
                <div class="d-inline animate__animated animate__flash animate__infinite animate__slower">
                    @svg('trash-fill')
                </div>
                Again!
            </button>
        </div>
        @else
        <div class="col-12 col-xxl-1 mb-1 mb-xxl-0 align-self-end">
            <button
                wire:click="enableDelete"
                class="btn btn-primary w-100"
                @if ($role == UserRole::ADMINISTRATOR || $hasChanges || $user->_id == $selfUserId) disabled @endif
            >
                @svg('trash-fill') Delete
            </button>
        </div>
        @endif

        <div class="col-12 col-xxl-1 align-self-end">
            <button
                wire:click="save"
                wire:loading.attr="disabled"
                class="btn btn-primary w-100"
                @if (!$hasChanges) disabled @endif
            >
                <div wire:loading>
                    <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                    <span class="visually-hidden"> Loading... </span>
                </div>

                <span wire:loading.remove> @svg('save') </span>
                Save
            </button>
        </div>
    </div>

    @if (!$name || !$email || !$hasPassword)
        <div class="text-danger text-center pt-2 pb-1">
            This user is currently disabled, set a password, a user name and/or an e-mail address in order to enable this account.
        </div>
    @endif

    @if ($isChangingRole)
        <div class="text-danger text-center pt-2 pb-1">
            Changing the role of this user will invalidate all of their sessions and they'll be required to log back in.
        </div>
    @endif
</div>
