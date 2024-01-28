@extends('common.page')

@section('main')
<div class="container-lg pb-3 px-4 px-lg-0">
    <div class="row gap-2">
        <div class="left-pane overflow-x-auto col-12 col-sm-6 col-lg-5 bg-light border rounded-3 p-3">
            <livewire:select-printer />
            <livewire:connection-status />
            <livewire:temperature-presets />
            <livewire:webcam-feeds />
            <livewire:uploaded-files />
        </div>
        <div class="right-pane overflow-x-auto col bg-light border rounded-3 p-0 p-md-2">
            <livewire:index-tabs />
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>

window.addEventListener('DOMContentLoaded', () => {
    let container = document.querySelector('body');
    let listener  = window.SwipeListener(container);

    let header = document.querySelector('header');

    let swipeCount        = 0;
    let swipeResetTimeout = null;

    let isFoldableAndIsFolded = false,
        foldedModeHintToast   = null;

    const ON_SCREEN_TOP              = 0;
    const OUTSIDE_SCREEN_TOP         = '-100vh';
    const FLEX_MODE_HINT_TIMEOUT     = 60000;
    const FLEX_MODE_CHANGE_TIMEOUT   = 1000;
    const FOLDABLE_MATCH_MEDIA_QUERY = '(vertical-viewport-segments: 2) and (device-posture: folded)';

    container.addEventListener('swipe', function (e) {
        let directions  = e.detail.directions,
            x           = e.detail.x;
            y           = e.detail.y;

        if (swipeResetTimeout) {
            clearTimeout( swipeResetTimeout );

            swipeResetTimeout = null;
        }

        swipeResetTimeout = setTimeout(() => {
            swipeCount = 0;
        }, 750);

        if (directions.top) {
            console.log('Swiped top.');

            swipeCount++;
        }

        if (swipeCount > 1) {
            header.style.top = 0;
        }

        // console.log('Started vertically at', y[0], 'and ended at', y[1]);
        // console.log(swipeCount);
    });

    document.querySelector('main').addEventListener('click', () => {
        header.style.top = OUTSIDE_SCREEN_TOP;
    });

    const handleFoldableDisplayChange = () => {
        if (window.matchMedia( FOLDABLE_MATCH_MEDIA_QUERY ).matches) {
            header.style.top = OUTSIDE_SCREEN_TOP;

            isFoldableAndIsFolded = true;

            if (window.localStorage && window.localStorage.getItem('firstTimeFolded') === null) {
                localStorage.setItem('firstTimeFolded', false.toString());

                foldedModeHintToast = toastify.info(
                    `While in flex mode, the navbar is hidden.<br>Reveal it by swiping twice from the bottom to the top.`,
                    FLEX_MODE_HINT_TIMEOUT,
                    `Flex mode enabled`
                );
            }
        } else {
            isFoldableAndIsFolded = false;

            if (foldedModeHintToast) {
                foldedModeHintToast.hideToast();
            }
        }
    }

    const handleCollapsiblesOnResize = () => {
        document.querySelectorAll('.collapse').forEach(element => {
            let instance = bootstrap.Collapse.getInstance(element);

            if (instance) {
                instance.hide();
            }
        });
    }

    const handleScreenChange = () => {
        handleFoldableDisplayChange();
        handleCollapsiblesOnResize();
    }

    window.addEventListener('resize',            handleScreenChange);
    window.addEventListener('orientationchange', handleScreenChange);

    window
        .matchMedia( FOLDABLE_MATCH_MEDIA_QUERY )
        .addEventListener('change', handleScreenChange);

    if (window.localStorage) {
        console.debug('LocalStorage support detected!');

        if (window.localStorage.getItem('firstRun') === null) {
            localStorage.setItem('firstRun', false.toString());

            toastify.info(
                `${hasTouchScreen ? 'Tap' : 'Click'} on notification toasts or wait a few seconds to hide them.`
            );

            let device = UAParser.getDevice();

            if (device.vendor) {
                axios.get(`device/variant/${device.model}`)
                     .then(response => {
                        console.log(response.data);

                        if (response.data.publicName.indexOf('Fold') > -1) {
                            let browser = UAParser.getBrowser();

                            if (browser.name.startsWith('Samsung')) {
                                toastify.info(
                                    'Flex mode is available, try folding your device <b>halfway through</b> to get a special layout!',
                                    FLEX_MODE_HINT_TIMEOUT,
                                    `Try the flex mode on your ${response.data.brand} ${response.data.publicName}`
                                );
                            } else {
                                toastify.info(
                                    `Flex mode is available, load this page using <b>Samsung Internet</b> instead of <b>${browser.name}</b> and try it out!`,
                                    FLEX_MODE_HINT_TIMEOUT,
                                    `Check out the flex mode on your ${response.data.brand} ${response.data.publicName}`
                                );
                            }
                        }
                     })
                     .catch(error => {
                        console.error( error );
                     });
            }
        }
    } else {
        console.warn('LocalStorage is not supported by this browser.');
    }
});

</script>
@endpush