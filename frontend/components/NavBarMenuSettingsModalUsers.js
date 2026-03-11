import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Appbar, Button, DataTable, FAB, IconButton, Menu, Text, Tooltip, useTheme } from "react-native-paper";
import API from "../includes/API";
import { useEffect, useState } from "react";
import UserPaneLoadingIndicator from "./UserPaneLoadingIndicator";
import NavBarMenuSettingsModalPlaceholderItem from "./NavBarMenuSettingsModalPlaceholderItem";
import SimpleDialog from "./SimpleDialog";
import TextBold from "./TextBold";
import UserSettingsModal from "./UserSettingsModal";
import UserNewPasswordModal from "./UserNewPasswordModal";
import NavBarMenuSettingsModalUsersOptions from "./NavBarMenuSettingsModalUsersOptions";

const NavBarMenuSettingsModalUsers = ({ isSmallTablet, isSmallLaptop, enqueueSnackbar }) => {
    const queryClient = useQueryClient();

    const { colors } = useTheme();

    const roleTypes = useQuery({
        queryKey: ["roleTypes"],
        queryFn:  () => API.get('/enum/UserRole')
    });

    const userList = useQuery({
        queryKey: ["users"],
        queryFn:  () => API.get("/users"),
    });

    const [ users, setUsers ] = useState([]),
          [ roles, setRoles ] = useState([]),
          [ selectedUser, setSelectedUser ] = useState(null),
          [ isDeleteDialogOpen,         setIsDeleteDialogOpen        ] = useState(false),
          [ isEditDialogOpen,           setIsEditDialogOpen          ] = useState(false),
          [ isPasswordResetDialogOpen,  setIsPasswordResetDialogOpen ] = useState(false),
          [ isNewPasswordDialogOpen,    setIsNewPasswordDialogOpen   ] = useState(false),
          [ generatedPassword,          setGeneratedPassword         ] = useState(null);

    const resetPasswordMutation = useMutation({
        mutationFn: (userId) => API.post(`/users/${userId}/reset-password`),
        onSuccess: (response) => {
            console.debug('NavBarMenuSettingsModalUsers: resetPasswordMutation:', response);

            const user      = response?.data?.user,
                  password  = response?.data?.password;

            setGeneratedPassword(password);
            setSelectedUser(user);

            setIsPasswordResetDialogOpen(false);
            setIsNewPasswordDialogOpen(true);

            enqueueSnackbar({
                message: 'Password reset successfully.',
                variant: 'success',
                action:  { label: 'Dismiss', onPress: () => {} }
            });
        },
        onError: (error) => {
            enqueueSnackbar({
                message: error?.response?.data?.message || 'An error occurred while resetting the password.',
                variant: 'error',
                action:  { label: 'Dismiss', onPress: () => {} }
            });
        }
    });

    const deleteUserMutation = useMutation({
        mutationFn: (userId) => API.delete(`/users/${userId}`),
        onSuccess: () => {
            enqueueSnackbar({
                message: 'User deleted successfully.',
                variant: 'success',
                action:  { label: 'Dismiss', onPress: () => {} }
            });

            queryClient.invalidateQueries({ queryKey: ['users'] });

            setIsDeleteDialogOpen(false);
        },
        onError: (error) => {
            enqueueSnackbar({
                message: error?.response?.data?.message || 'An error occurred while deleting the user.',
                variant: 'error',
                action:  { label: 'Dismiss', onPress: () => {} }
            });
        }
    });

    useEffect(() => {
        if (!userList.isSuccess) { return; }

        console.debug('NavBarMenuSettingsModalUsers: userList:', userList.data);

        setUsers(userList?.data?.data);
    }, [ userList.data ]);

    useEffect(() => {
        if (!roleTypes.isSuccess) { return; }

        console.debug('NavBarMenuSettingsModalUsers: roleTypes:', roleTypes.data);

        setRoles(roleTypes?.data?.data);
    }, [ roleTypes.data ]);

    useEffect(() => {
        console.debug('NavBarMenuSettingsModalUsers: users:', users);
    }, [ users ]);

    useEffect(() => {
        console.debug('NavBarMenuSettingsModalUsers: roles:', roles);
    }, [ roles ]);

    useEffect(() => {
        if (generatedPassword === null) { return; }

        setIsNewPasswordDialogOpen(true);
    }, [ generatedPassword ]);

    const handleAddUser = () => {
        setSelectedUser(null);
        setIsEditDialogOpen(true);
    };

    const handleEditUser = (user) => {
        setSelectedUser(user);
        setIsEditDialogOpen(true);
    };

    const handleDeleteUser = (user) => {
        setSelectedUser(user);
        setIsDeleteDialogOpen(true);
    };

    const handleResetPassword = (user) => {
        setSelectedUser(user);
        setIsPasswordResetDialogOpen(true);
    };

    if (roleTypes.isLoading) {
        return <UserPaneLoadingIndicator message={"Loading roles..."} />;
    }

    if (roleTypes.isError) {
        return (
            <NavBarMenuSettingsModalPlaceholderItem
                icon="alert-circle-outline"
                message="An error occurred while loading roles."
            />
        );
    }

    if (userList.isFetching) {
        return <UserPaneLoadingIndicator message={"Loading users..."} />;
    }

    if (userList.isError) {
        if (userList.error.response.status === 422) {
            return (
                <NavBarMenuSettingsModalPlaceholderItem
                    icon="shield-off-outline"
                    message="You are not authorized to view users."
                    troubleshootingOptions={[
                        "Contact your system administrator for access.",
                        "Try refreshing the page.",
                    ]}
                />
            );
        } else {
            return (
                <NavBarMenuSettingsModalPlaceholderItem
                    icon="alert-circle-outline"
                    message="An error occurred while loading users."
                />
            );
        }
    }

    if (users.length === 0) {
        return (
            <NavBarMenuSettingsModalPlaceholderItem
                icon="account-group-outline"
                message="No users found."
            />
        );
    }

    return (
        <>
            <DataTable style={{ overflow: 'scroll' }}>
                <DataTable.Header>
                    <DataTable.Title>
                        Name
                    </DataTable.Title>
                    {!isSmallTablet &&
                        <DataTable.Title>
                            E-mail address
                        </DataTable.Title>
                    }
                    <DataTable.Title>
                        Role
                    </DataTable.Title>
                    <DataTable.Title style={{ justifyContent: 'end' }}>
                        Actions
                    </DataTable.Title>
                </DataTable.Header>
                {users.map((user) => (
                    <DataTable.Row key={user._id}>
                        <DataTable.Cell>
                            <Text>{user.name}</Text>
                        </DataTable.Cell>
                        {!isSmallTablet &&
                            <DataTable.Cell>
                                <Text>{user.email}</Text>
                            </DataTable.Cell>
                        }
                        <DataTable.Cell>
                            <Text>{roles[user.role]}</Text>
                        </DataTable.Cell>
                        <DataTable.Cell style={{ justifyContent: 'end' }}>
                            <NavBarMenuSettingsModalUsersOptions
                                user={user}
                                handleEditUser={handleEditUser}
                                handleDeleteUser={handleDeleteUser}
                                handleResetPassword={handleResetPassword}
                                isSmallLaptop={isSmallLaptop}
                                isSmallTablet={isSmallTablet}
                                enqueueSnackbar={enqueueSnackbar}
                            />
                        </DataTable.Cell>
                    </DataTable.Row>
                ))}
            </DataTable>

            <UserSettingsModal
                visible={isEditDialogOpen}
                onDismiss={() => setIsEditDialogOpen(false)}
                user={selectedUser}
                roles={roles}
                enqueueSnackbar={enqueueSnackbar}
                isSmallLaptop={isSmallLaptop}
                isSmallTablet={isSmallTablet}
                setGeneratedPassword={setGeneratedPassword}
                setSelectedUser={setSelectedUser}
            />

            <UserNewPasswordModal
                visible={isNewPasswordDialogOpen}
                onDismiss={() => setIsNewPasswordDialogOpen(false)}
                user={selectedUser}
                password={generatedPassword}
                enqueueSnackbar={enqueueSnackbar}
                isSmallLaptop={isSmallLaptop}
                isSmallTablet={isSmallTablet}
            />

            <FAB
                style={{
                    position: 'fixed',
                    margin: 16,
                    right: 48,
                    bottom: 32
                }}
                icon="plus"
                label="Add user"
                onPress={() => handleAddUser()}
            />

            <SimpleDialog
                title="Do you really want to reset the password for this user?"
                visible={isPasswordResetDialogOpen}
                onClose={() => setIsPasswordResetDialogOpen(false)}
                setVisible={setIsPasswordResetDialogOpen}
                content={
                    <Text variant="bodyMedium">
                        "<TextBold variant="bodyMedium">{selectedUser?.name}</TextBold>" will have their password reset to a randomly generated password.
                    </Text>
                }
                actions={
                    <>
                        <Button
                            onPress={() => setIsPasswordResetDialogOpen(false)}
                            disabled={resetPasswordMutation.isLoading}
                        >
                            Cancel
                        </Button>
                        <Button
                            theme={{ colors: { primary: colors.onPrimary } }}
                            style={{ backgroundColor: colors.primary }}
                            loading={resetPasswordMutation.isLoading}
                            disabled={resetPasswordMutation.isLoading}
                            onPress={() => resetPasswordMutation.mutate(selectedUser?._id)}
                        >
                            Reset password
                        </Button>
                    </>
                }
            />                

            <SimpleDialog
                title="Do you really want to delete this user?"
                visible={isDeleteDialogOpen}
                onClose={() => setIsDeleteDialogOpen(false)}
                setVisible={setIsDeleteDialogOpen}
                content={
                    <Text variant="bodyMedium">
                        "<TextBold variant="bodyMedium">{selectedUser?.name}</TextBold>" will be removed from the system and will immediately lose access to the application.
                    </Text>
                }
                actions={
                    <>
                        <Button
                            onPress={() => setIsDeleteDialogOpen(false)}
                            disabled={deleteUserMutation.isLoading}
                        >
                            Cancel
                        </Button>
                        <Button
                            theme={{ colors: { primary: colors.onPrimary } }}
                            style={{ backgroundColor: colors.primary }}
                            loading={deleteUserMutation.isLoading}
                            disabled={deleteUserMutation.isLoading}
                            onPress={() => deleteUserMutation.mutate(selectedUser?._id)}
                        >
                            Delete
                        </Button>
                    </>
                }
            />
        </>
    );
}

export default NavBarMenuSettingsModalUsers;