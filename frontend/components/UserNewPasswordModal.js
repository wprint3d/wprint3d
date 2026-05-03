import { View } from "react-native"
import { Icon, Modal, Portal, Text, useTheme } from "react-native-paper"
import { SnackbarProvider } from "react-native-paper-snackbar-stack"

import TextBold from "./TextBold";
import { useLocalization } from "../includes/LocalizationProvider";

const UserNewPasswordModal = ({ visible, onDismiss, user, password, isSmallTablet }) => {
    const { colors } = useTheme();
    const { t } = useLocalization();

    return (
        <Portal>
            <SnackbarProvider maxSnack={4}>
                <Modal visible={visible} onDismiss={onDismiss}
                    contentContainerStyle={{
                        backgroundColor: colors.elevation.level1,
                        alignSelf: 'center',
                        padding: 24,
                        width:    isSmallTablet ? '100%' : '85%',
                        maxWidth: isSmallTablet ? '100%' : 1024,
                        overflow: 'scroll'
                    }}
                >
                    <View style={{ padding: 8, marginBottom: 16 }}>
                        <Text variant="headlineSmall">
                            <Icon source="key" size={24} />
                            <View style={{ marginLeft: 8, flexDirection: 'row' }}>
                                <Text>
                                    {t("users.newPasswordTitlePrefix")}<TextBold>{user?.name}</TextBold>{t("users.newPasswordTitleSuffix")}
                                </Text>
                            </View>
                        </Text>
                    </View>
                    <Text style={{ paddingHorizontal: 8, marginBottom: 16, textAlign: 'center' }}>
                        {t("users.newPasswordIntroPrefix")}<TextBold>{user?.name}</TextBold>{t("users.newPasswordIntroSuffix")}
                        {'\n'}{'\n'}
                        <TextBold>{t("users.newPasswordCopyWarning")}</TextBold>
                        {'\n'}{'\n'}
                        {t("users.newPasswordForgotReset")}
                    </Text>
                    <View style={{ padding: 8, marginBottom: 16, backgroundColor: colors.elevation.level2, paddingVertical: 16 }}>
                        <Text variant="headlineMedium" style={{ textAlign: 'center' }}>
                            <TextBold>{password}</TextBold>
                        </Text>
                    </View>
                    <View style={{ padding: 8, marginBottom: 16 }}>
                        <Text style={{ textAlign: 'center' }}>
                            {t("users.newPasswordCaseSensitivePrefix")}<TextBold>{t("users.newPasswordCaseSensitive")}</TextBold>{t("users.newPasswordCaseSensitiveSuffix")}
                        </Text>
                    </View>
                </Modal>
            </SnackbarProvider>
        </Portal>
    );
}

export default UserNewPasswordModal;
