export const getScrollableModalFrameStyle = ({
    backgroundColor,
    isFullScreen = false,
    width = '95%',
    maxWidth,
    maxHeight = '95%',
}) => {
    const style = {
        backgroundColor,
        maxHeight: isFullScreen ? '100%' : maxHeight,
        width: isFullScreen ? '100%' : width,
        alignSelf: 'center',
        padding: 0,
        overflow: 'hidden',
    };

    if (isFullScreen) {
        style.height = '100%';
    }

    if (isFullScreen || maxWidth !== undefined) {
        style.maxWidth = isFullScreen ? '100%' : maxWidth;
    }

    return style;
};

export const getScrollableModalContentStyle = ({
    horizontalPadding = 16,
    verticalPadding = 16,
} = {}) => ({
    paddingHorizontal: horizontalPadding,
    paddingVertical: verticalPadding,
});
