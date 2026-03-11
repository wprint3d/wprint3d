import { useEffect } from "react";
import { View } from "react-native";
import { List, Text } from "react-native-paper";

import UserPaneLoadingIndicator from "./UserPaneLoadingIndicator";

const PrinterSettingsModalDetails = ({ details, isLoading, error }) => {
    useEffect(() => {
        console.debug('PrinterSettingsModalDetails: details:', details);
    }, [ details ]);

    if (isLoading) {
        return (
            <View>
                <UserPaneLoadingIndicator message={`Loading printer details...`} />
            </View>
        );
    }

    if (error) {
        return (
            <View style={{ flex: 1, justifyContent: 'center', alignItems: 'center' }}>
                <Text style={{ textAlign: 'center' }}>
                    Couldn't load printer details, please try again later:
                    {'\n\n'}
                    {error}
                </Text>
            </View>
        );
    }

    const { node, baudRate, machine } = details;

    const CAPABILITY_NAMES = {
        serialXonXoff: 'Serial XON/XOFF',
        binaryFileTransfer: 'Binary file transfer',
        eeprom: 'EEPROM',
        volumetric: 'Volumetric',
        autoreportPos: 'Auto report position',
        autoreportTemp: 'Auto report temperature',
        progress: 'Progress',
        printJob: 'Print job',
        autolevel: 'Auto level',
        runout: 'Runout sensor',
        zProbe: 'Z-probe',
        levelingData: 'Leveling data',
        buildPercent: 'Build percent',
        softwarePower: 'Software power',
        toggleLights: 'Toggle lights',
        caseLightBrightness: 'Case light brightness',
        emergencyParser: 'Emergency parser',
        hostActionCommands: 'Host action commands',
        promptSupport: 'Prompt support',
        sdcard: 'SD card',
        repeat: 'Repeat',
        sdWrite: 'SD writing',
        autoreportSdStatus: 'Auto report SD status',
        longFilename: 'Long filenames',
        lfnWrite: 'Long filename writing',
        customFirmwareUpload: 'Custom firmware upload',
        extendedM20: 'Extended M20 (longer filenames)',
        thermalProtection: 'Thermal protection',
        motionModes: 'Motion modes',
        arcs: 'Arcs',
        babystepping: 'Babystepping',
        chamberTemperature: 'Chamber temperature',
        coolerTemperature: 'Cooler temperature',
        meatpack: 'Meatpack',
        configExport: 'Config export'
    };

    const META_NAMES = {
        firmwareName:    'Firmware',
        sourceCodeUrl:   'Source code URL',
        protocolVersion: 'Protocol version',
        machineType:     'Machine type',
        extruderCount:   'Number of Extruders',
        axisCount:       'Number of axes',
        uuid:            'Unique ID'
    };

    return (
        <View>
            <List.Section title="Connection">
                <List.Item title="Port" description={node} />
                <List.Item title="Baud rate" description={`${baudRate} baud`} />
            </List.Section>

            <List.Section title="Machine">
                {Object.keys(machine).map(key => {
                    if (key === 'capabilities') return;

                    return (
                        <List.Item
                            key={key}
                            title={META_NAMES[key] ?? key}
                            description={machine[key]}
                        />
                    );
                })}
            </List.Section>

            <List.Section title="Features">
                {Object.keys(machine?.capabilities).map(capability => {
                    return (
                        <List.Item
                            key={capability}
                            title={CAPABILITY_NAMES[capability] ?? capability}
                            description={machine.capabilities[capability] ? 'Yes' : 'No'}
                        />
                    );
                })}
            </List.Section>
        </View>
    );
}

export default PrinterSettingsModalDetails;