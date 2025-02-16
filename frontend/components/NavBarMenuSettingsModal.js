import { useState } from "react";
import { Button, Icon, Modal, PaperProvider, Portal, Text, useTheme } from "react-native-paper";
import { Tabs, TabsProvider, TabScreen } from "react-native-paper-tabs";
import { View } from "react-native";
import NavBarMenuSettingsModalPrinters from "./NavBarMenuSettingsModalPrinters";
import { SnackbarProvider, useSnackbar } from "react-native-paper-snackbar-stack";
import NavBarMenuSettingsModalPresets from "./NavBarMenuSettingsModalPresets";
import NavBarMenuSettingsModalCameras from "./NavBarMenuSettingsModalCameras";
import NavBarMenuSettingsModalRecording from "./NavBarMenuSettingsModalRecording";
import NavBarMenuSettingsModalSystem from "./NavBarMenuSettingsModalSystem";
import NavBarMenuSettingsModalUsers from "./NavBarMenuSettingsModalUsers";
import NavBarMenuSettingsModalAbout from "./NavBarMenuSettingsModalAbout";
import BackButton from "./modules/BackButton";
import { useQuery } from "@tanstack/react-query";
import API from "../includes/API";
import NavBarMenuSettingsModalDeveloper from "./NavBarMenuSettingsModalDeveloper";

const NavBarMenuSettingsModal = ({ isVisible, setIsVisible, isSmallTablet, isSmallLaptop }) => {
    const developerModeQuery = useQuery({
        queryKey:   ['developerMode'],
        queryFn:    () => API.get('/config/developerMode'),
    });

    const theme = useTheme();

    const { enqueueSnackbar } = useSnackbar();

    const doDismiss = () => {
        setIsVisible(false);
    };

    const Wrapper = ({ children, style = {} }) => (
        <View style={[{ width: '100%', flexGrow: 1, maxWidth: 1400, alignSelf: 'center' }, style]}>
            {children}
        </View>
    );

    return (
        <Portal>
            <SnackbarProvider maxSnack={4}>
                <Modal
                    visible={isVisible}
                    onDismiss={doDismiss}
                    contentContainerStyle={{
                        backgroundColor: theme.colors.elevation.level1,
                        height: (isSmallLaptop || isSmallTablet) ? '100%' : '95%',
                        width:  (isSmallLaptop || isSmallTablet) ? '100%' : '95%',
                        alignSelf: 'center',
                        paddingVertical: 16,
                        paddingHorizontal: isSmallTablet ? 4 : 16,
                        overflow: 'scroll'
                    }}
                >
                    {(isSmallLaptop || isSmallTablet) && <BackButton onPress={doDismiss} />}
                    <TabsProvider defaultIndex={0}>
                        <Tabs
                            style={{
                                backgroundColor: theme.colors.elevation.level1,
                                marginBottom: 16,
                                marginLeft: (isSmallLaptop || isSmallTablet) ? 78 : 0,
                            }}
                            tabHeaderStyle={{
                                alignSelf: 'center',
                                maxWidth: '100%',
                                overflow: 'scroll'
                            }}
                            mode="scrollable"
                            showLeadingSpace={false}
                        >
                            <TabScreen label="Printers" icon="printer-3d">
                                <Wrapper>
                                    <NavBarMenuSettingsModalPrinters isSmallTablet={isSmallTablet} isSmallLaptop={isSmallLaptop} enqueueSnackbar={enqueueSnackbar} />
                                </Wrapper>
                            </TabScreen>
                            <TabScreen label="Presets" icon="printer-3d-nozzle">
                                <Wrapper>
                                    <NavBarMenuSettingsModalPresets isSmallTablet={isSmallTablet} isSmallLaptop={isSmallLaptop} enqueueSnackbar={enqueueSnackbar} />
                                </Wrapper>
                            </TabScreen>
                            <TabScreen label="Cameras" icon="camera">
                                <Wrapper>
                                    <NavBarMenuSettingsModalCameras isSmallTablet={isSmallTablet} isSmallLaptop={isSmallLaptop} enqueueSnackbar={enqueueSnackbar} />
                                </Wrapper>
                            </TabScreen>
                            <TabScreen label="Recording" icon="record">
                                <Wrapper>
                                    <NavBarMenuSettingsModalRecording isSmallTablet={isSmallTablet} isSmallLaptop={isSmallLaptop} enqueueSnackbar={enqueueSnackbar} />
                                </Wrapper>
                            </TabScreen>
                            <TabScreen label="System" icon="cog">
                                <Wrapper>
                                    <NavBarMenuSettingsModalSystem isSmallTablet={isSmallTablet} isSmallLaptop={isSmallLaptop} enqueueSnackbar={enqueueSnackbar} />
                                </Wrapper>
                            </TabScreen>
                            <TabScreen label="Users" icon="account">
                                <Wrapper>
                                    <NavBarMenuSettingsModalUsers isSmallTablet={isSmallTablet} isSmallLaptop={isSmallLaptop} enqueueSnackbar={enqueueSnackbar} />
                                </Wrapper>
                            </TabScreen>
                            {developerModeQuery?.data?.data === true && (
                                <TabScreen label="Developer" icon="code-tags">
                                    <Wrapper style={{ flexShrink: 1, overflow: 'scroll' }}>
                                        <NavBarMenuSettingsModalDeveloper isSmallTablet={isSmallTablet} isSmallLaptop={isSmallLaptop} enqueueSnackbar={enqueueSnackbar} />
                                    </Wrapper>
                                </TabScreen>
                            )}
                            <TabScreen label="About" icon="information">
                                <Wrapper>
                                    <NavBarMenuSettingsModalAbout isSmallTablet={isSmallTablet} isSmallLaptop={isSmallLaptop} enqueueSnackbar={enqueueSnackbar} />
                                </Wrapper>
                            </TabScreen>
                        </Tabs>
                    </TabsProvider>
                </Modal>
            </SnackbarProvider>
        </Portal>
    );
}

export default NavBarMenuSettingsModal;