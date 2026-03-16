import { useEffect, useState } from 'react';

import { useWindowDimensions, View } from 'react-native';
import { Button, Menu, Divider, PaperProvider, Appbar, Text, useTheme, Icon, IconButton, Tooltip, Portal, List, Badge } from 'react-native-paper';
import NavBarMenuSettingsModal from './NavBarMenuSettingsModal';
import NavBarMenuProfileModal from './NavBarMenuProfileModal';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import API from '../includes/API';
import { useSnackbar } from 'react-native-paper-snackbar-stack';
import SimpleDialog from './SimpleDialog';
import NotificationsCenter from './modules/NotificationsCenter';
import { useEcho } from '../hooks/useEcho';
import { useLocalization } from '../includes/LocalizationProvider';

export default function NavBarMenu({ isSmallTablet, isSmallLaptop, colorScheme, setColorScheme, headerHeight, enqueueSnackbar = () => {} }) {
  const { colors } = useTheme();
  const { t } = useLocalization();

  const window = useWindowDimensions();

  const queryClient = useQueryClient();

  const logoutMutation = useMutation({
    mutationFn: ()      => API.post('/user/logout'),
    onSuccess:  ()      => {
      queryClient.refetchQueries({ queryKey: ['checkLogin'] });
      queryClient.refetchQueries({ queryKey: ['csrf-token'] });
    },
    onError:    (error) => {
      enqueueSnackbar({
        message:  error?.response?.data?.message || 'An error occurred while logging out.',
        variant:  'error',
        action:   { label: 'Try again', onPress: () => logoutMutation.mutate() }
      });
    }
  });

  const [ isVisible, setIsVisible ] = useState(false);

  const [ showSettingsModal, setShowSettingsModal ] = useState(false),
        [ showProfileModal,  setShowProfileModal  ] = useState(false),
        [ showLogoutDialog,  setShowLogoutDialog  ] = useState(false);

  const openMenu  = () => setIsVisible(true);
  const closeMenu = () => setIsVisible(false);

  const handleLogout = () => logoutMutation.mutate();

  useEffect(() => {
    if (!showSettingsModal) { return; }

    closeMenu();
  }, [ showSettingsModal ]);

  useEffect(() => {
    if (isSmallTablet) { return; }

    closeMenu();
  }, [ window ]);

  return (
    <>
      <NotificationsCenter isSmallTablet={isSmallTablet} headerHeight={headerHeight} enqueueSnackbar={enqueueSnackbar} />

      {isSmallTablet ?
        (
          <Menu
            visible={isVisible}
            onDismiss={closeMenu}
            anchor={<Appbar.Action icon="dots-vertical" onPress={openMenu} />}
            anchorPosition='bottom'
          >
            <Menu.Item title={t("navbar.profile")}  leadingIcon="account" onPress={() => { closeMenu(); setShowProfileModal(true);  }} />
            <Menu.Item title={t("navbar.settings")} leadingIcon="cog"     onPress={() => { closeMenu(); setShowSettingsModal(true); }} />
            <Divider />
            <Menu.Item title={t("navbar.signOut")} leadingIcon="logout"  onPress={() => { closeMenu(); setShowLogoutDialog(true);  }} />
          </Menu>
        ) : (
          <View style={{ flexDirection: 'row', alignItems: 'center' }}>
            <Button icon="account" mode="text" style={{ marginHorizontal: 5 }} onPress={() => setShowProfileModal(true)}>
              {t("navbar.profile")}
            </Button>
            <Button icon="cog"     mode="text" style={{ marginHorizontal: 5 }} onPress={() => setShowSettingsModal(true)}>
              {t("navbar.settings")}
            </Button>
            <Button icon="logout"  mode="text" style={{ marginHorizontal: 5 }} onPress={() => setShowLogoutDialog(true)}>
              {t("navbar.signOut")}
            </Button>
          </View>
        )
      }

      {showProfileModal && (
        <NavBarMenuProfileModal
          isVisible={showProfileModal}
          setIsVisible={setShowProfileModal}
          onDismiss={() => setShowProfileModal(false)}
          isSmallTablet={isSmallTablet}
          isSmallLaptop={isSmallLaptop}
          colorScheme={colorScheme}
          setColorScheme={setColorScheme}
        />
      )}

      {showSettingsModal && (
        <NavBarMenuSettingsModal
          isVisible={showSettingsModal}
          setIsVisible={setShowSettingsModal}
          isSmallTablet={isSmallTablet}
          isSmallLaptop={isSmallLaptop}
        />
      )}

      <SimpleDialog
        visible={showLogoutDialog}
        setVisible={setShowLogoutDialog}
        title={t("navbar.confirmSignOutTitle")}
        left={<Icon source="logout" size={24} />}
        content={<Text>{t("navbar.confirmSignOutBody")}</Text>}
        style={{ maxWidth: 480 }}
        actions={
          <>
            <Button mode="text" onPress={() => setShowLogoutDialog(false)}>
              {t("navbar.cancel")}
            </Button>

            <Button
              mode="text"
              onPress={() => handleLogout()}
              style={{ backgroundColor: colors.onBackground }}
              theme={{ colors: { primary: colors.background } }}
            >
              {t("navbar.signOut")}
            </Button>
          </>
        }
      />
    </>
  );
};
