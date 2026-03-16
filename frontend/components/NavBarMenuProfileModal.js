import { Button, Divider, Icon, Modal, Portal, Text, TextInput, useTheme } from "react-native-paper";
import { SnackbarProvider, useSnackbar } from "react-native-paper-snackbar-stack";
import BackButton from "./modules/BackButton";
import { View } from "react-native";
import { useQuery } from "@tanstack/react-query";
import API from "../includes/API";
import UserPaneLoadingIndicator from "./UserPaneLoadingIndicator";
import { useEffect, useState } from "react";
import DropDown from "react-native-paper-dropdown";
import UserChangePasswordModal from "./UserChangePasswordModal";
import { LocalizationContext, useLocalization } from "../includes/LocalizationProvider";
import LanguagePicker from "./LanguagePicker";

const sectionContentStyle = {
    width: '100%',
    maxWidth: 350,
};

const NavBarMenuProfileModal = ({ isVisible, setIsVisible, onDismiss, isSmallTablet, isSmallLaptop, colorScheme, setColorScheme }) => {
    const { colors } = useTheme();
    const localization = useLocalization();
    const { t } = localization;

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
            <LocalizationContext.Provider value={localization}>
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
                                {t("profile.title")}
                            </Text>

                            <Text style={{ marginBottom: 16 }}>
                                {t("profile.intro")}
                            </Text>

                            {
                                userQuery.isFetching
                                    ? <UserPaneLoadingIndicator message={t("profile.loading")} />
                                    : (
                                        <View style={sectionContentStyle}>
                                            <TextInput
                                                mode="outlined"
                                                label={t("profile.username")}
                                                value={user?.name}
                                                readOnly={true}
                                                style={{ width: '100%', marginVertical: 8 }}
                                            />
                                            <TextInput
                                                mode="outlined"
                                                label={t("profile.email")}
                                                value={user?.email}
                                                readOnly={true}
                                                style={{ width: '100%', marginVertical: 8 }}
                                            />

                                            <View style={{ flexDirection: 'row', justifyContent: 'center', alignItems: 'center' }}>
                                                <Button mode="contained" onPress={handlePasswordChange} style={{ marginVertical: 12 }}>
                                                    <Icon source="key" color={colors.onPrimary} size={16} />
                                                    <Text style={{ marginLeft: 4, color: colors.onPrimary }}>
                                                        {t("profile.changePassword")}
                                                    </Text>
                                                </Button>
                                            </View>
                                        </View>
                                    )
                            }

                            <Divider style={{ width: '80%', marginVertical: 12, marginBottom: 16 }} />

                            <Text variant="headlineLarge" style={{ marginBottom: 16 }}>
                                {t("language.label")}
                            </Text>

                            <View style={[sectionContentStyle, { marginBottom: 16 }]}>
                                <LanguagePicker style={{ width: '100%', alignItems: 'stretch' }} />
                            </View>

                            <Divider style={{ width: '80%', marginVertical: 12, marginBottom: 16 }} />

                            <Text variant="headlineLarge" style={{ marginBottom: 16 }}>
                                {t("profile.appearance")}
                            </Text>

                            <View style={sectionContentStyle}>
                                <DropDown
                                    label={t("profile.theme")}
                                    mode="outlined"
                                    value={colorScheme}
                                    setValue={setColorScheme}
                                    list={[
                                        { label: t("profile.themeSystem"), value: null     },
                                        { label: t("profile.themeLight"),  value: 'light'  },
                                        { label: t("profile.themeDark"),   value: 'dark'   }
                                    ]}
                                    style={{ width: '100%', marginVertical: 8 }}
                                    showDropDown={() => setShowThemePicker(true)}
                                    onDismiss={() => setShowThemePicker(false)}
                                    visible={showThemePicker}
                                />
                            </View>
                        </View>

                        <UserChangePasswordModal
                            visible={showChangePasswordModal}
                            onDismiss={() => setShowChangePasswordModal(false)}
                            isSmallTablet={isSmallTablet}
                            enqueueSnackbar={enqueueSnackbar}
                        />
                    </Modal>
                </SnackbarProvider>
            </LocalizationContext.Provider>
        </Portal>
    );
}

export default NavBarMenuProfileModal;
