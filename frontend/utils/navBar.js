export function getNavBarLeftPadding(windowWidth) {
    return windowWidth < 768 ? 8 : 16;
}

export function groupNavbarWidgets(widgets = [], isSmallTablet = false) {
    if (!isSmallTablet) {
        return {
            inlineWidgets: widgets,
            mobileCardWidgets: [],
        };
    }

    return {
        inlineWidgets: widgets.filter(extension => extension.mobilePresentation !== 'card'),
        mobileCardWidgets: widgets.filter(extension => extension.mobilePresentation === 'card'),
    };
}
