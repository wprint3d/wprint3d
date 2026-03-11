import { Button, Divider, Icon, Modal, Portal, Text, TextInput, useTheme } from "react-native-paper";
import { SnackbarProvider, useSnackbar } from "react-native-paper-snackbar-stack";
import BackButton from "./modules/BackButton";
import { View } from "react-native";
import { useQuery } from "@tanstack/react-query";
import API from "../includes/API";
import UserPaneLoadingIndicator from "./UserPaneLoadingIndicator";
import { useEffect, useState } from "react";
import TextBold from "./TextBold";
import DropDown from "react-native-paper-dropdown";
import UserChangePasswordModal from "./UserChangePasswordModal";

const NavBarMenuProfileModal = ({ isVisible, setIsVisible, onDismiss, isSmallTablet, isSmallLaptop, colorScheme, setColorScheme }) => {
    const { colors } = useTheme();

    const { enqueueSnackbar } = useSnackbar();

    const [ showThemePicker,         setShowThemePicker         ] = useState(false),
          [ showChangePasswordModal, setShowChangePasswordModal ] = useState(false);

    const userQuery = useQuery({
        queryKey: ['user'],
        queryFn:  () => API.get('/user')
    });

    const handlePasswordChange = () => setShowChangePasswordModal(true);

    const doDismiss = () => {
        setIsVisible(false);

        onDismiss();
    };

    const user = userQuery?.data?.data;

    useEffect(() => {
        console.debug('NavBarMenuProfileModal: user:', user);
    }, [user]);

    useEffect(() => {
        console.debug('NavBarMenuProfileModal: colorScheme:', colorScheme);
    }, [colorScheme]);

    return (
        <Portal>
            <SnackbarProvider maxSnack={4}>
                <Modal
                    visible={isVisible}
                    onDismiss={doDismiss}
                    contentContainerStyle={{
                        backgroundColor: colors.elevation.level1,
                        height:     isSmallTablet ? '100%' : '75%',
                        width:      isSmallTablet ? '100%' : '60%',
                        maxWidth:   isSmallTablet ? '100%' : 500,
                        alignSelf: 'center',
                        padding: 16,
                        overflow: 'scroll'
                    }}
                >
                    {isSmallTablet && <BackButton onPress={doDismiss} />}

                    <View style={{ flex: 1, justifyContent: 'center', alignItems: 'center' }}>
                        <Text variant="headlineLarge" style={{ marginVertical: 16 }}>
                            Profile
                        </Text>

                        <Text style={{ marginBottom: 16 }}>
                            Here's your profile information, you may <TextBold>not</TextBold> edit this information.
                            {'\n\n'}
                            If you need to make changes, please contact the administrator.
                        </Text>

                        {
                            userQuery.isFetching
                                ? <UserPaneLoadingIndicator message={"Loading your profile..."} />
                                : (
                                    <View style={{ width: '100%', paddingHorizontal: 32, maxWidth: 350 }}>
                                        <TextInput
                                            mode="outlined"
                                            label="Username"
                                            value={user?.name}
                                            readOnly={true}
                                            style={{ width: '100%', marginVertical: 8 }}
                                        />
                                        <TextInput
                                            mode="outlined"
                                            label="Email"
                                            value={user?.email}
                                            readOnly={true}
                                            style={{ width: '100%', marginVertical: 8 }}
                                        />

                                        <View style={{ flexDirection: 'row', justifyContent: 'center', alignItems: 'center' }}>
                                            <Button mode="contained" onPress={handlePasswordChange} style={{ marginVertical: 12 }}>
                                                <Icon source="key" color={colors.onPrimary} size={16} />
                                                <Text style={{ marginLeft: 4, color: colors.onPrimary }}>
                                                    Change password
                                                </Text>
                                            </Button>
                                        </View>
                                    </View>
                                )
                        }

                        <Divider style={{ width: '80%', marginVertical: 12, marginBottom: 16 }} />

                        <Text variant="headlineLarge" style={{ marginBottom: 16 }}>
                            Appearance
                        </Text>

                        <DropDown
                            label="Theme"
                            mode="outlined"
                            value={colorScheme}
                            setValue={setColorScheme}
                            list={[
                                { label: 'Use system preference', value: null     },
                                { label: 'Light',                 value: 'light'  },
                                { label: 'Dark',                  value: 'dark'   }
                            ]}
                            style={{ width: '100%', marginVertical: 8 }}
                            showDropDown={() => setShowThemePicker(true)}
                            onDismiss={() => setShowThemePicker(false)}
                            visible={showThemePicker}
                        />
                    </View>

                    <UserChangePasswordModal
                        visible={showChangePasswordModal}
                        onDismiss={() => setShowChangePasswordModal(false)}
                        isSmallTablet={isSmallTablet}
                        enqueueSnackbar={enqueueSnackbar}
                    />
                </Modal>
            </SnackbarProvider>
        </Portal>
    );
}

export default NavBarMenuProfileModal;