import { useState } from 'react';

import { Menu, Icon, useTheme } from 'react-native-paper';

import SmallButton from './SmallButton';
import { useLocalization } from '../includes/LocalizationProvider';

export default function UserPrinterFileControlsOptions({ disabled, setIsRequestingDelete, setIsRequestingRename }) {
  const { colors } = useTheme();
  const { t } = useLocalization();

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
          > {t("files.options")} </SmallButton>
        }
        anchorPosition='bottom'
      >
        <Menu.Item leadingIcon="trash-can"  onPress={() => { closeMenu(); setIsRequestingDelete(true) }} title={t("files.delete")} />
        <Menu.Item leadingIcon="rename-box" onPress={() => { closeMenu(); setIsRequestingRename(true) }} title={t("files.rename")} />
      </Menu>
    </>
  );
};
