import { View } from "react-native"
import { Icon, Modal, Portal, Text, useTheme } from "react-native-paper"
import { SnackbarProvider } from "react-native-paper-snackbar-stack"

import TextBold from "./TextBold";

const UserNewPasswordModal = ({ visible, onDismiss, user, password, isSmallTablet }) => {
    const { colors } = useTheme();

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
                                    <TextBold>{user?.name}</TextBold>'s password
                                </Text>
                            </View>
                        </Text>
                    </View>
                    <Text style={{ paddingHorizontal: 8, marginBottom: 16, textAlign: 'center' }}>
                        Here's the new password for <TextBold>{user?.name}</TextBold>.
                        {'\n'}{'\n'}
                        <TextBold>Please make sure to take note of it and share it with the user as you won't be able to see it again.</TextBold>
                        {'\n'}{'\n'}
                        If the user forgets their password, you can always reset it again from their account settings.
                    </Text>
                    <View style={{ padding: 8, marginBottom: 16, backgroundColor: colors.elevation.level2, paddingVertical: 16 }}>
                        <Text variant="headlineMedium" style={{ textAlign: 'center' }}>
                            <TextBold>{password}</TextBold>
                        </Text>
                    </View>
                    <View style={{ padding: 8, marginBottom: 16 }}>
                        <Text style={{ textAlign: 'center' }}>
                            This password is randomly generated and is unique to this user. It is <TextBold>case-sensitive</TextBold> and must be entered exactly as shown.
                        </Text>
                    </View>
                </Modal>
            </SnackbarProvider>
        </Portal>
    );
}

export default UserNewPasswordModal;