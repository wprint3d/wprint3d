export function getLeftPaneWidth(windowWidth) {
    if (windowWidth <= 768) {          // small tablet
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
        flexDirection: isSmallTablet ? "column" : "row",
        alignItems: "stretch",
        flexWrap: windowWidth <= 425 ? "wrap" : "nowrap",
        flexShrink: isSmallTablet ? 0 : 1,
        padding: isSmallTablet ? 0 : 8,
    };
}
