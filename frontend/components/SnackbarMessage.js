import { useEffect, useState } from "react";

import { useWindowDimensions } from "react-native";

import { Portal, Snackbar } from "react-native-paper";

export default function SnackbarMessage({ message, initialVisibility, duration = Snackbar.DURATION_LONG }) {
    const dimensions = useWindowDimensions();

    const [ visible, setVisible ] = useState(initialVisibility);

    useEffect(() => setVisible(initialVisibility), [ initialVisibility ]);

    return (
        <Portal>
            <Snackbar
                visible={visible}
                action={{
                    label:   'Got it',
                    onPress: () => setVisible(false)
                }}
                style={{
                    maxWidth:   '100%',
                    minWidth:   100,
                    width:      (
                        dimensions.width > 1280
                            ? 'auto'
                            : 512
                    ),
                    alignSelf:  'center'
                }}
                duration={duration}
                onDismiss={() => setVisible(false)}
            >
                {message}
            </Snackbar>
        </Portal>
    );
}