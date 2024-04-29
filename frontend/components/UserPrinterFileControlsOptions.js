import { useState } from 'react';

import { View } from 'react-native';
import { Button, Menu, Divider, PaperProvider, Appbar, Icon, useTheme } from 'react-native-paper';
import SmallButton from './SmallButton';

export default function UserPrinterFileControlsOptions({ disabled }) {
  const { colors } = useTheme();

  const [ isVisible, setIsVisible ] = useState(false);

  const openMenu  = () => setIsVisible(true);
  const closeMenu = () => setIsVisible(false);

  return (
    <>
      <Menu
        visible={isVisible}
        onDismiss={closeMenu}
        anchor={
          <SmallButton
            disabled={disabled}
            onPress={openMenu}
            right={
              <Icon
                source={isVisible ? 'menu-up' : 'menu-down'}
                color={colors.onPrimary}
                size={16}
              />
            }
            style={{
              borderWidth:            0,
              borderTopLeftRadius:    0,
              borderBottomLeftRadius: 0
            }}
          > Options </SmallButton>
        }
        anchorPosition='bottom'
      >
        <Menu.Item leadingIcon="trash-can"  onPress={() => {}} title="Delete" />
        <Menu.Item leadingIcon="rename-box" onPress={() => {}} title="Rename" />
      </Menu>
    </>
  );
};