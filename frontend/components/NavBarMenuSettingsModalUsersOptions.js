import { useEffect, useState } from "react";
import { useWindowDimensions } from "react-native";
import { Appbar, IconButton, Menu, Tooltip } from "react-native-paper";
import { useLocalization } from "../includes/LocalizationProvider";

const NavBarMenuSettingsModalUsersOptions = ({
    user,
    handleEditUser, handleDeleteUser, handleResetPassword, handleManageTokens,
    isSmallLaptop, isSmallTablet,
    enqueueSnackbar
}) => {
    const window = useWindowDimensions();
    const { t } = useLocalization();

    const [ isMobileMenuOpen, setIsMobileMenuOpen ] = useState(false);

    useEffect(() => {
        if (isSmallLaptop || isSmallTablet) { return; }
    
        setIsMobileMenuOpen(false);
    }, [ window ]);

    return (
        isSmallLaptop || isSmallTablet
            ? (
                <Menu visible={isMobileMenuOpen} onDismiss={() => setIsMobileMenuOpen(false)} anchor={
                    <Appbar.Action icon="dots-vertical" onPress={() => setIsMobileMenuOpen(true)} />
                }>
                    <Menu.Item leadingIcon="pencil"     title={t("users.edit")}    onPress={() => handleEditUser(user)} />
                    <Menu.Item leadingIcon="delete"     title={t("users.delete")}  onPress={() => {
                        if (user?.deletable === false) {
                            enqueueSnackbar({
                                message: t("users.cannotDelete"),
                                variant: 'error',
                                action: { label: t("users.dismiss") }
                            });

                            return;
                        }

                        handleDeleteUser(user);
                    }} />
                    <Menu.Item leadingIcon="lock-reset" title={t("users.resetPassword")}  onPress={() => handleResetPassword(user)} />
                    <Menu.Item leadingIcon="key-variant" title={t("apiTokens.title")} onPress={() => handleManageTokens(user)} />
                </Menu>
            )
            : (
                <>
                    <Tooltip title={t("users.edit")}>
                        <IconButton
                            icon="pencil"
                            onPress={() => handleEditUser(user)}
                        />
                    </Tooltip>
                    <Tooltip title={user?.deletable === false ? t("users.cannotDelete") : t("users.delete")}>
                        <IconButton
                            icon="delete"
                            disabled={user?.deletable === false}
                            onPress={() => handleDeleteUser(user)}
                        />
                    </Tooltip>
                    <Tooltip title={t("users.resetPassword")}>
                        <IconButton
                            icon="lock-reset"
                            onPress={() => handleResetPassword(user)}
                        />
                    </Tooltip>
                    <Tooltip title={t("apiTokens.title")}>
                        <IconButton
                            icon="key-variant"
                            onPress={() => handleManageTokens(user)}
                        />
                    </Tooltip>
                </>
            )
    );
}

export default NavBarMenuSettingsModalUsersOptions;
