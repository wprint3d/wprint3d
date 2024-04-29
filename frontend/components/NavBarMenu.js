import { useState } from 'react';

import { View } from 'react-native';
import { Button, Menu, Divider, PaperProvider, Appbar } from 'react-native-paper';

export default function NavBarMenu() {
  const [ isVisible, setIsVisible ] = useState(false);

  const openMenu  = () => setIsVisible(true);
  const closeMenu = () => setIsVisible(false);

  return (
    <>
      <Menu
        visible={isVisible}
        onDismiss={closeMenu}
        anchor={<Appbar.Action icon="dots-vertical" onPress={openMenu} />}
        anchorPosition='bottom'
      >
        <Menu.Item onPress={() => {}} title="Profile" />
        <Menu.Item onPress={() => {}} title="Settings" />
        <Divider />
        <Menu.Item onPress={() => {}} title="Sign out" />
      </Menu>
    </>
  );
};