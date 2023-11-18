<div>
    <div class="row text-start">
        @foreach ($users as $user)
            @livewire('user-settings', [ 'user' => $user, 'selfUserId' => $selfUserId ], key( $user->_id ))
        @endforeach

        <div class="col-12 mt-2 d-flex justify-content-center">
            <button wire:click="add" class="btn btn-primary"> @svg('plus') Add more </button>
        </div>
    </div>
</div>