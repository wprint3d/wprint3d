import { Text, useTheme } from "react-native-paper"
import { Tabs, TabScreen, TabsProvider } from "react-native-paper-tabs"
import NavBarMenuSettingsModalDeveloperLogging from "./NavBarMenuSettingsModalDeveloperLogging"

const NavBarMenuSettingsModalDeveloper = ({ isSmallTablet, isSmallLaptop, enqueueSnackbar }) => {
    const { colors } = useTheme();

    return (
        <TabsProvider defaultIndex={0}>
            <Tabs
                style={{
                    backgroundColor: colors.elevation.level1,
                    marginBottom: 16,
                }}
                tabHeaderStyle={{
                    alignSelf: 'center',
                    maxWidth: '100%',
                    overflow: 'scroll'
                }}
                mode="scrollable"
                showLeadingSpace={false}
            >
                <TabScreen label="Logging" icon="file-document-outline">
                    <NavBarMenuSettingsModalDeveloperLogging
                        isSmallTablet={isSmallTablet}
                        isSmallLaptop={isSmallLaptop}
                        enqueueSnackbar={enqueueSnackbar}
                    />
                </TabScreen>
            </Tabs>
        </TabsProvider>
    );
}

export default NavBarMenuSettingsModalDeveloper