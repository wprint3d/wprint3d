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
