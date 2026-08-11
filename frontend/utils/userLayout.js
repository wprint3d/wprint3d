export function getLeftPaneWidth(windowWidth) {
    if (windowWidth < 768) {           // small tablet
        return "100%";
    }

    if (windowWidth <= 1024) {         // small laptop
        return "45%";
    }

    if (windowWidth <= 1440) {         // medium laptop
        return "40%";
    }

    return "35%";
}

export function getUserLayoutRootStyle({ windowWidth, isSmallTablet }) {
    return {
        flex: 1,
        minHeight: 0,
        minWidth: 0,
        overflow: "hidden",
        flexDirection: isSmallTablet ? "column" : "row",
        alignItems: "stretch",
        flexWrap: windowWidth <= 425 ? "wrap" : "nowrap",
        flexShrink: isSmallTablet ? 0 : 1,
        padding: isSmallTablet ? 0 : 8,
    };
}

export function getDesktopWorkspaceStyle() {
    return {
        flex: 1,
        minHeight: 0,
        minWidth: 0,
        maxHeight: "100%",
        maxWidth: "100%",
        overflow: "hidden",
        gap: 8,
        flexDirection: "row",
    };
}
