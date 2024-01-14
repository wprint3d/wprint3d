@include('common.header')

@yield('main')

@if (Auth::user())
    <livewire:profile-modal />
    <livewire:settings-modal />
@endif

@include('common.footer')
