import { useState } from 'react';
import { View, useWindowDimensions } from 'react-native';

import { Appbar } from 'react-native-paper';

import NavBarMenu from './NavBarMenu';
import PluginHostRenderer from './PluginHostRenderer';
import usePluginExtensions from '../hooks/usePluginExtensions';
import { getNavBarLeftPadding } from '../utils/navBar';

export default function NavBar({
  heightReporter = () => {}, enqueueSnackbar = () => {},
  appName, isSmallTablet, isSmallLaptop, colorScheme, setColorScheme 
}) {
  const [ headerHeight, _setHeaderHeight ] = useState(0);
  const navbarWidgetExtensions = usePluginExtensions('navbar_widget');
  const windowWidth = useWindowDimensions().width;

  const setHeaderHeight = (height) => {
    _setHeaderHeight(height);

    if (heightReporter) {
      heightReporter(height);
    }
  };

  return (
    <>
      <Appbar.Header
        onLayout={event => setHeaderHeight(event.nativeEvent.layout.height)}
        style={{ minHeight: 46, paddingLeft: getNavBarLeftPadding(windowWidth) }}
      >
        <View style={{ flex: 1, minWidth: 0, flexDirection: 'row', alignItems: 'center' }}>
          <View style={{ marginRight: 14, flexShrink: 0 }}>
            <Appbar.Content
              title={appName}
              style={{ flex: 0 }}
              titleStyle={{ fontSize: 18, fontWeight: 'bold' }}
            />
          </View>
          {!!navbarWidgetExtensions?.data?.data?.length && (
            <View
              style={{
                flex: 1,
                minWidth: 0,
                flexDirection: 'row',
                alignItems: 'center',
                justifyContent: 'flex-end',
                gap: 8,
                marginRight: 6,
                paddingVertical: 7,
              }}
            >
              {(navbarWidgetExtensions?.data?.data || []).map((extension) => (
                <PluginHostRenderer
                  key={`${extension.pluginId}-${extension.id}`}
                  extension={extension}
                />
              ))}
            </View>
          )}
        </View>
        <NavBarMenu
          isSmallTablet={isSmallTablet}
          isSmallLaptop={isSmallLaptop}
          colorScheme={colorScheme}
          setColorScheme={setColorScheme}
          headerHeight={headerHeight}
          enqueueSnackbar={enqueueSnackbar}
        />
      </Appbar.Header>
    </>
  );
};
