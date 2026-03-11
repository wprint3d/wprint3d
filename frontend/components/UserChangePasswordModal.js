import React, { useState } from "react";
import { View } from "react-native";
import { Icon, Modal, Portal, Text, useTheme, Button, TextInput, Checkbox } from "react-native-paper";
import { SnackbarProvider, useSnackbar } from "react-native-paper-snackbar-stack";
import TextBold from "./TextBold";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import API from "../includes/API";
import BackButton from "./modules/BackButton";

const UserChangePasswordModal = ({ visible, onDismiss, isSmallTablet, extraHint, fromFirstLogin = false }) => {
    const queryClient = useQueryClient();

    const { enqueueSnackbar } = useSnackbar();

    const { colors } = useTheme();

    const [currentPassword, setCurrentPassword] = useState('');
    const [newPassword,     setNewPassword    ] = useState('');
    const [repeatPassword,  setRepeatPassword ] = useState('');

    const [showPassword, setShowPassword] = useState(false);

    const [logoutOtherDevices, setLogoutOtherDevices] = useState(false);

    const isValid = currentPassword.length > 0 && newPassword.length > 0 && repeatPassword.length > 0;

    const saveChangeMutation = useMutation({
        mutationKey: ['savePasswordChange'],
        mutationFn: () => API.put('/user/password', {
            currentPassword,
            newPassword,
            repeatPassword,
            logoutOtherDevices
        }),
        onSuccess: () => {
            queryClient.refetchQueries({ queryKey: ['checkLogin'] });

            enqueueSnackbar({
                message: 'Your password has been changed successfully!',
                variant: 'success',
                action: { label: 'Dismiss', onPress: () => {} }
            });

            doDismiss();
        },
        onError: (error) => {
            console.error('UserChangePasswordModal.saveChangeMutation', error);

            enqueueSnackbar({
                message: error?.response?.data?.message || 'An error occurred while saving the change',
                variant: 'error',
                action: { label: 'Dismiss', onPress: () => {} }
            })
        }
    });

    const handleSave = () => saveChangeMutation.mutate();

    const doDismiss = () => {
        if (saveChangeMutation.isLoading) return;

        setCurrentPassword('');
        setNewPassword('');
        setRepeatPassword('');
        setLogoutOtherDevices(false);
        setShowPassword(false);

        if (onDismiss) onDismiss();
    }

    return (
        <Portal>
            <SnackbarProvider maxSnack={4}>
                <Modal visible={visible} onDismiss={onDismiss}
                    contentContainerStyle={{
                        backgroundColor: colors.elevation.level1,
                        alignSelf: 'center',
                        padding: 24,
                        height:   isSmallTablet ? '100%' : 'auto',
                        width:    isSmallTablet ? '100%' : '85%',
                        maxWidth: isSmallTablet ? '100%' : 480,
                        overflow: 'scroll'
                    }}
                >
                    {isSmallTablet && (<BackButton onPress={doDismiss} />)}
                    <View style={{ padding: 8 }}>
                        <Text variant="headlineSmall">
                            <Icon source="key" size={24} />
                            <View style={{ marginLeft: 8, flexDirection: 'row' }}>
                                <Text>
                                    <TextBold>Change your password</TextBold>
                                </Text>
                            </View>
                        </Text>
                        <Text style={{ marginTop: 24, marginBottom: 8, textAlign: 'center' }}>
                            {extraHint
                                ? extraHint
                                : 'Enter your current password and the new password in order to update it.'
                            }
                        </Text>
                    </View>
                    <View style={{ padding: 8 }}>
                        <TextInput
                            mode="outlined"
                            label="Current password"
                            value={currentPassword}
                            onChangeText={setCurrentPassword}
                            secureTextEntry={!showPassword}
                            style={{ marginVertical: 8 }}
                            right={<TextInput.Icon icon={showPassword ? 'eye' : 'eye-off'} onPress={() => setShowPassword(!showPassword)} />}
                            activeOutlineColor={currentPassword.length > 0 ? colors.primary : colors.error}
                        />
                        <TextInput
                            mode="outlined"
                            label="New password"
                            value={newPassword}
                            onChangeText={setNewPassword}
                            secureTextEntry={!showPassword}
                            style={{ marginVertical: 8 }}
                            right={<TextInput.Icon icon={showPassword ? 'eye' : 'eye-off'} onPress={() => setShowPassword(!showPassword)} />}
                            activeOutlineColor={newPassword.length > 0 ? colors.primary : colors.error}
                        />
                        <TextInput
                            mode="outlined"
                            label="Repeat new password"
                            value={repeatPassword}
                            onChangeText={setRepeatPassword}
                            secureTextEntry={!showPassword}
                            style={{ marginVertical: 8 }}
                            right={<TextInput.Icon icon={showPassword ? 'eye' : 'eye-off'} onPress={() => setShowPassword(!showPassword)} />}
                            activeOutlineColor={repeatPassword.length > 0 ? colors.primary : colors.error}
                        />

                        {!fromFirstLogin && (
                            <View style={{ flexDirection: 'row', justifyContent: 'center', paddingVertical: 8 }}>
                                <Checkbox.Item
                                    label="Log out other devices"
                                    status={logoutOtherDevices ? 'checked' : 'unchecked'}
                                    onPress={() => setLogoutOtherDevices(!logoutOtherDevices)}
                                />
                            </View>
                        )}
                    </View>
                    <View style={{ marginVertical: 16, flexDirection: 'row', justifyContent: 'center' }}>
                        <Button
                            mode="contained"
                            onPress={handleSave}
                            loading={saveChangeMutation.isLoading}
                            disabled={saveChangeMutation.isLoading || !isValid}
                        >
                            <Icon source="content-save" size={16} color={colors.onPrimary} />
                            <Text style={{ marginLeft: 4, color: colors.onPrimary }}>
                                Save change
                            </Text>
                        </Button>
                    </View>
                </Modal>
            </SnackbarProvider>
        </Portal>
    );
}

export default UserChangePasswordModal;