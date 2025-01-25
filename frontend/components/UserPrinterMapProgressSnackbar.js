import React from 'react';

import SnackbarMessage from './SnackbarMessage';
import { ActivityIndicator, Text } from 'react-native-paper';
import { View } from 'react-native';

export default function UserPrinterMapProgressSnackbar({ isRunningMapper }) {
    return (
        <SnackbarMessage 
            message={
                <View style={{ flexDirection: 'row', alignItems: 'center' }}>
                    <Text>
                        Detecting connection parameters…
                    </Text>
                    <ActivityIndicator animating={true} style={{ marginLeft: 12 }} />
                </View>
            }
            initialVisibility={isRunningMapper}
            duration={Infinity} 
        />
    );
}