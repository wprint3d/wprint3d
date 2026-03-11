import { useEffect, useState } from "react";
import { useWindowDimensions } from "react-native";
import { Appbar, IconButton, Menu, Tooltip } from "react-native-paper";

const NavBarMenuSettingsModalUsersOptions = ({
    user,
    handleEditUser, handleDeleteUser, handleResetPassword,
    isSmallLaptop, isSmallTablet,
    enqueueSnackbar
}) => {
    const window = useWindowDimensions();

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
                    <Menu.Item leadingIcon="pencil"     title="Edit"    onPress={() => handleEditUser(user)} />
                    <Menu.Item leadingIcon="delete"     title="Delete"  onPress={() => {
                        if (user?.deletable === false) {
                            enqueueSnackbar({
                                message: 'This user cannot be deleted.',
                                variant: 'error',
                                action: { label: 'Dismiss' }
                            });

                            return;
                        }

                        handleDeleteUser(user);
                    }} />
                    <Menu.Item leadingIcon="lock-reset" title="Reset password"  onPress={() => handleResetPassword(user)} />
                </Menu>
            )
            : (
                <>
                    <Tooltip title="Edit">
                        <IconButton
                            icon="pencil"
                            onPress={() => handleEditUser(user)}
                        />
                    </Tooltip>
                    <Tooltip title={user?.deletable === false ? "This user cannot be deleted" : "Delete"}>
                        <IconButton
                            icon="delete"
                            disabled={user?.deletable === false}
                            onPress={() => handleDeleteUser(user)}
                        />
                    </Tooltip>
                    <Tooltip title="Reset password">
                        <IconButton
                            icon="lock-reset"
                            onPress={() => handleResetPassword(user)}
                        />
                    </Tooltip>
                </>
            )
    );
}

export default NavBarMenuSettingsModalUsersOptions;