import React from 'react';

import { useWindowDimensions, View } from 'react-native';

import UserPrinterControlMovement    from './UserPrinterControlMovement';
import UserPrinterControlExtrusion   from './UserPrinterControlExtrusion';
import UserPrinterControlTemperature from './UserPrinterControlTemperature';
import usePluginExtensions from '../hooks/usePluginExtensions';
import PluginHostRenderer from './PluginHostRenderer';

const UserPrinterControl = ({ connectionStatus, isSmallTablet, isSmallLaptop, printerId = null }) => {
    console.debug('UserPrinterControl: connectionStatus:', connectionStatus);

    const printerPanelExtensions = usePluginExtensions('printer_panel');
    const printerActionExtensions = usePluginExtensions('printer_action');
    const modalExtensions = usePluginExtensions('modal');

    return <View style={[
        { paddingHorizontal: 8, paddingVertical: 16, paddingBottom: 32, overflow: 'scroll', maxHeight: '100%' },
        isSmallTablet && { paddingHorizontal: 24 }
    ]}>
        <UserPrinterControlMovement     styles={styles} isSmallTablet={isSmallTablet} isSmallLaptop={isSmallLaptop} />
        <UserPrinterControlExtrusion    styles={styles} isSmallTablet={isSmallTablet} isSmallLaptop={isSmallLaptop} connectionStatus={connectionStatus} />
        <UserPrinterControlTemperature  connectionStatus={connectionStatus} />
        {(printerActionExtensions?.data?.data || []).map((extension) => (
            <PluginHostRenderer
                key={`${extension.pluginId}-${extension.id}`}
                extension={extension}
                modalExtensions={modalExtensions?.data?.data || []}
                printerId={printerId}
            />
        ))}
        {(printerPanelExtensions?.data?.data || []).map((extension) => (
            <PluginHostRenderer
                key={`${extension.pluginId}-${extension.id}`}
                extension={extension}
                modalExtensions={modalExtensions?.data?.data || []}
                printerId={printerId}
            />
        ))}
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
