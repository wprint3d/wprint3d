import { Button, Icon, List, Modal, Portal, Text, TextInput, useTheme } from "react-native-paper"
import { SnackbarProvider } from "react-native-paper-snackbar-stack";
import TextBold from "./TextBold";
import { View } from "react-native";
import DropDown from "react-native-paper-dropdown";
import { useEffect, useState } from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import API from "../includes/API";
import { useLocalization } from "../includes/LocalizationProvider";

const UserSettingsModal = ({ user = null, roles, onDismiss, visible, isSmallTablet, isSmallLaptop, enqueueSnackbar, setSelectedUser, setGeneratedPassword }) => {
    const queryClient = useQueryClient();
    const { t } = useLocalization();

    const { colors } = useTheme();

    const [ showRoleDropdown, setShowRoleDropdown ] = useState(false),
          [ username,         setUsername         ] = useState(''),
          [ email,            setEmail            ] = useState(''),
          [ role,             setRole             ] = useState(roles?.length ? 0 : null),
          [ isUsernameValid,  setIsUsernameValid  ] = useState(false),
          [ isEmailValid,     setIsEmailValid     ] = useState(false);

    const addNewUserMutation = useMutation({
        mutationFn: async ({ username, email, role }) => API.post('/users', { username, email, role }),
        onSuccess: response => {
            enqueueSnackbar({
                message: t("users.createUserSuccess"),
                variant: 'success',
                action:  { label: t("notifications.dismiss"), onPress: () => {} }
            });

            const user      = response?.data?.user,
                  password  = response?.data?.password;

            setSelectedUser(user);
            setGeneratedPassword(password);

            onDismiss();
            onSuccess();

            queryClient.invalidateQueries({ queryKey: ['users'] });
        },
        onError: (error) => {
            console.error('UserSettingsModal.addNewUserMutation.onError', error);

            enqueueSnackbar({
                message: error?.response?.data?.message || t("users.createUserError"),
                variant: 'error',
                action:  { label: t("notifications.dismiss"), onPress: () => {} }
            });
        }
    });

    const saveChangesMutation = useMutation({
        mutationFn: async ({ username, email, role }) => API.put(`/users/${user?._id}`, { username, email, role }),
        onSuccess: () => {
            enqueueSnackbar({
                message: (
                    user === null
                        ? t("users.createUserSuccess")
                        : t("users.saveUserSuccess")
                ),
                variant: 'success',
                action:  { label: t("notifications.dismiss"), onPress: () => {} }
            });

            onDismiss();
            onSuccess();

            queryClient.invalidateQueries({ queryKey: ['users'] });
        },
        onError: (error) => {
            console.error('UserSettingsModal.saveChangesMutation.onError', error);

            enqueueSnackbar({
                message: error?.response?.data?.message || t("users.saveUserError"),
                variant: 'error',
                action:  { label: t("notifications.dismiss"), onPress: () => {} }
            });
        }
    });

    const onSuccess = () => {
        setUsername('');
        setEmail('');
        setRole(roles?.length ? 0 : null);
    };

    const saveChanges = () => {
        if (!user?._id) return addNewUserMutation.mutate({ username, email, role });

        saveChangesMutation.mutate({ username, email, role });
    };

    const checkValidity = () => {
        setIsUsernameValid(!!username.length);
        setIsEmailValid(!!email.length && email.includes('@') && email.includes('.'));
    };

    useEffect(() => {
        console.debug('UserSettingsModal', user);

        if (!user) {
            setUsername('');
            setEmail('');
            setRole(roles?.length ? 0 : null);

            return;
        }

        setUsername(user?.name);
        setEmail(user?.email);
        setRole(user?.role);
    }, [ user ]);

    useEffect(() => checkValidity(), [ username, email ]);

    const canSave = isUsernameValid && isEmailValid;

    return (
        <Portal>
            <SnackbarProvider maxSnack={4}>
                <Modal visible={visible} onDismiss={onDismiss}
                    contentContainerStyle={{
                        backgroundColor: colors.elevation.level1,
                        alignSelf: 'center',
                        padding: 24,
                        width:    isSmallTablet ? '100%' : '85%',
                        maxWidth: isSmallTablet ? '100%' : 480,
                        overflow: 'scroll'
                    }}
                >
                    <View style={{ padding: 8, marginBottom: 16 }}>
                        {
                            user?._id
                                ? <Text variant="headlineSmall"><Icon source="pencil" size={24} /> {t("users.editUserTitlePrefix")} <TextBold>{user?.name}</TextBold>{t("users.editUserTitleSuffix")}</Text>
                                : <Text variant="headlineSmall"><Icon source="account-plus" size={24} /> {t("users.createUserTitle")}</Text>
                        }
                    </View>
                    <View style={{ marginBottom: 16 }}>
                        <TextInput
                            mode="outlined"
                            label={t("users.usernameLabel")}
                            value={username}
                            onChangeText={text => setUsername(text)}
                            activeOutlineColor={isUsernameValid ? colors.primary : colors.error}
                        />
                    </View>
                    <View style={{ marginBottom: 16 }}>
                        <TextInput
                            mode="outlined"
                            label={t("users.emailAddressLabel")}
                            value={email}
                            onChangeText={text => setEmail(text)}
                            activeOutlineColor={isEmailValid ? colors.primary : colors.error}
                        />
                    </View>
                    <View style={{ marginBottom: 16 }}>
                        <DropDown
                            label={t("users.roleLabel")}
                            mode="outlined"
                            value={role}
                            setValue={value => setRole(value)}
                            list={roles.map((role, index) => ({ label: role, value: index }))}
                            visible={showRoleDropdown}
                            onDismiss={() => setShowRoleDropdown(false)}
                            showDropDown={() => {
                                if (user?.deletable !== false) {
                                    setShowRoleDropdown(true);

                                    return;
                                }

                                enqueueSnackbar({
                                    message: t("users.cannotChangeRole"),
                                    variant: 'error',
                                    action:  { label: t("notifications.dismiss"), onPress: () => {} }
                                });
                            }}
                        />
                    </View>

                    <View style={{ width: '100%', flexDirection: 'row', justifyContent: 'center', paddingTop: 8 }}>
                        {
                            user?._id
                                ? (
                                    <Button mode="contained" onPress={saveChanges} loading={saveChangesMutation.isLoading} disabled={saveChangesMutation.isLoading || !canSave}>
                                        <Icon source="content-save" size={16} color={canSave ? colors.onPrimary : colors.disabled} />
                                        <Text style={{
                                            marginLeft: 8,
                                            color: canSave ? colors.onPrimary : colors.disabled
                                        }}>
                                            {t("settings.saveChanges")}
                                        </Text>
                                    </Button>
                                )
                                : (
                                    <Button mode="contained" onPress={saveChanges} loading={addNewUserMutation.isLoading} disabled={addNewUserMutation.isLoading || !canSave}>
                                        <Icon source="account-plus" size={16} color={canSave ? colors.onPrimary : colors.disabled} />
                                        <Text style={{
                                            marginLeft: 8,
                                            color: canSave ? colors.onPrimary : colors.disabled
                                        }}>
                                            {t("users.createUserCta")}
                                        </Text>
                                    </Button>
                                )
                        }
                    </View>
                </Modal>
            </SnackbarProvider>
        </Portal>
    );
};

export default UserSettingsModal;
