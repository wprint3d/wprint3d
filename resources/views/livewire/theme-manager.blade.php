<div>
    @if ($theme === ThemeOption::DARK)
        @vite([ "resources/css/app.dark.scss" ])
    @elseif ($theme === ThemeOption::USE_SYSTEM_PREFERENCE)
        @vite([ "resources/css/app.dark.auto.scss" ])
    @endif

    <div id="currentTheme"> {{ $theme }} </div>
</div>
