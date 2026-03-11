import { Button, Modal, Portal, useTheme } from "react-native-paper";
import { SnackbarProvider } from "react-native-paper-snackbar-stack";
import { Tabs, TabScreen, TabsProvider } from "react-native-paper-tabs"
import { useEffect, useState } from "react";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import API from "../includes/API";
import CameraSettingsModalSettings from "./CameraSettingsModalSettings.js";
import CameraSettingsModalConfiguration from "./CameraSettingsModalSettings.js";
import BackButton from "./modules/BackButton";

const CameraSettingsModal = ({ isVisible, setIsVisible, camera, isSmallTablet, isSmallLaptop, enqueueSnackbar }) => {
    const theme = useTheme();

    return (
        <Portal>
            <SnackbarProvider maxSnack={4}>
                <Modal
                    visible={isVisible}
                    onDismiss={() => setIsVisible(false)}
                    contentContainerStyle={{
                        backgroundColor: theme.colors.elevation.level1,
                        alignSelf: 'center',
                        padding: 16,
                        height: isSmallTablet ? '100%' : '85%',
                        width:  isSmallTablet ? '100%' : '85%',
                        maxWidth: isSmallTablet ? '100%' : 600,
                        overflow: 'scroll'
                    }}
                >
                    {isSmallTablet && <BackButton onPress={() => setIsVisible(false)} />}
                    <TabsProvider defaultIndex={0}>
                        <Tabs
                            style={{
                                backgroundColor: theme.colors.elevation.level1,
                                marginBottom: 16
                            }}
                            mode="scrollable"
                            tabHeaderStyle={{ alignSelf: 'center' }}
                            showLeadingSpace={false}
                        >
                            <TabScreen label="Settings" icon="cog">
                                <CameraSettingsModalConfiguration
                                    camera={camera}
                                    isSmallTablet={isSmallTablet}
                                    isSmallLaptop={isSmallLaptop}
                                    enqueueSnackbar={enqueueSnackbar}
                                />
                            </TabScreen>
                        </Tabs>
                    </TabsProvider>
                </Modal>
            </SnackbarProvider>
        </Portal>
    );
}

export default CameraSettingsModal;