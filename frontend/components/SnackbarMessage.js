import { useEffect, useState } from "react";

import { useWindowDimensions } from "react-native";

import { Portal, Snackbar, useTheme } from "react-native-paper";

export default function SnackbarMessage({ message, initialVisibility, duration = Snackbar.DURATION_LONG, action = null }) {
    const { colors } = useTheme();

    const [ visible, setVisible ] = useState(initialVisibility);

    useEffect(() => setVisible(initialVisibility), [ initialVisibility ]);

    return (
        <Portal>
            <Snackbar
                visible={visible}
                action={action}
                style={{
                    maxWidth:   '100%',
                    minWidth:   100,
                    alignSelf:  'center',
                    backgroundColor: colors.surface,
                }}
                duration={duration}
                onDismiss={() => setVisible(false)}
            >
                {message}
            </Snackbar>
        </Portal>
    );
}