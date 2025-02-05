import React from 'react';

import { useWindowDimensions, View } from 'react-native';

import UserPrinterControlMovement    from './UserPrinterControlMovement';
import UserPrinterControlExtrusion   from './UserPrinterControlExtrusion';
import UserPrinterControlTemperature from './UserPrinterControlTemperature';

const UserPrinterControl = ({ connectionStatus, isSmallTablet, isSmallLaptop }) => {
    console.debug('UserPrinterControl: connectionStatus:', connectionStatus);

    return <View style={[
        { paddingHorizontal: 8, paddingVertical: 16, paddingBottom: 32, overflow: 'scroll', maxHeight: '100%' },
        isSmallTablet && { paddingHorizontal: 24 }
    ]}>
        <UserPrinterControlMovement     styles={styles} isSmallTablet={isSmallTablet} isSmallLaptop={isSmallLaptop} />
        <UserPrinterControlExtrusion    styles={styles} isSmallTablet={isSmallTablet} isSmallLaptop={isSmallLaptop} connectionStatus={connectionStatus} />
        <UserPrinterControlTemperature  connectionStatus={connectionStatus} />
    </View>;
};

const styles = {
    controlButton: {
        minWidth: 48,
        width: 60,
        height: 48,
        borderRadius: 25,
        justifyContent: 'center',
        alignItems: 'center',
    },
};

export default UserPrinterControl;