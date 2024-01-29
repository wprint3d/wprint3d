<div>
    <div wire:ignore.self id="profileModal" class="modal fade" tabindex="-1">
        <div class="modal-dialog modal-fullscreen-sm-down">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"> Profile </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form class="row g-3">
                        <div class="align-items-center d-flex flex-column w-100 py-2">
                            @svg('person-circle', [
                                'class'  => 'col-4 col-sm-3 bg-transparent fs-3',
                                'width'  => 'auto',
                                'height' => 'auto'
                            ])
                        </div>

                        <div class="col-12 text-center">
                            <h1 class="display-6"> About me </h1>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label"> Username </label>
                            <input type="email" class="form-control" value="{{ $user->name }}" readonly>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label"> E-mail address </label>
                            <input type="email" class="form-control" value="{{ $user->email }}" readonly>
                        </div>

                        <small class="text-muted mb-4">
                            Editing user data from this dialog is not yet enabled and will be implemented in the future. To make changes, select <span class="fw-bold">Settings</span> from the top bar drop-down menu.
                        </small>

                        <hr class="my-0">

                        <div class="col-12 text-center">
                            <h1 class="display-6"> Appearance </h1>
                        </div>

                        <div class="col-12">
                            <label class="form-label"> Theme </label>
                            <select
                                wire:model.live="theme"
                                class="form-select"
                                aria-label="Theme selector"
                                wire:offline.attr="disabled"
                            >
                                @foreach (ThemeOption::asSelectArray() as $key => $value)
                                    <option
                                        value="{{ $key }}"
                                        @if ($user->theme === $key || $key === '' && !isset($user->theme)) selected @endif
                                    >
                                        {{ $value }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
</div>
