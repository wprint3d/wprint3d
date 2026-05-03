import React from 'react';

import SnackbarMessage from './SnackbarMessage';
import { ActivityIndicator, Text } from 'react-native-paper';
import { View } from 'react-native';
import { useLocalization } from '../includes/LocalizationProvider';

export default function UserPrinterMapProgressSnackbar({ isRunningMapper }) {
    const { t } = useLocalization();

    return (
        <SnackbarMessage 
            message={
                <View style={{ flexDirection: 'row', alignItems: 'center' }}>
                    <Text>
                        {t("printer.mapper.detectingConnectionParameters")}
                    </Text>
                    <ActivityIndicator animating={true} style={{ marginLeft: 12 }} />
                </View>
            }
            initialVisibility={isRunningMapper}
            duration={Infinity} 
        />
    );
}
