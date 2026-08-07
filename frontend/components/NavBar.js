import { useState } from 'react';
import { View, useWindowDimensions } from 'react-native';

import { Appbar } from 'react-native-paper';

import NavBarMenu from './NavBarMenu';
import PluginHostRenderer from './PluginHostRenderer';
import usePluginExtensions from '../hooks/usePluginExtensions';
import { getNavBarLeftPadding, groupNavbarWidgets } from '../utils/navBar';

export default function NavBar({
  heightReporter = () => {}, enqueueSnackbar = () => {},
  appName, isSmallTablet, isSmallLaptop, colorScheme, setColorScheme 
}) {
  const [ headerHeight, _setHeaderHeight ] = useState(0);
  const navbarWidgetExtensions = usePluginExtensions('navbar_widget');
  const windowWidth = useWindowDimensions().width;
  const widgets = navbarWidgetExtensions?.data?.data || [];
  const { inlineWidgets, mobileCardWidgets } = groupNavbarWidgets(widgets, isSmallTablet);

  const setHeaderHeight = (height) => {
    _setHeaderHeight(height);
  };

  return (
    <View onLayout={event => heightReporter?.(event.nativeEvent.layout.height)}>
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
          {!!inlineWidgets.length && (
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
              {inlineWidgets.map((extension) => (
                <PluginHostRenderer
                  key={`${extension.pluginId}-${extension.id}`}
                  extension={extension}
                  navbarLayout={isSmallTablet ? extension.mobilePresentation : "inline"}
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

      {isSmallTablet && !!mobileCardWidgets.length && (
        <View style={{ gap: 8 }}>
          {mobileCardWidgets.map((extension) => (
            <PluginHostRenderer
              key={`${extension.pluginId}-${extension.id}`}
              extension={extension}
              navbarLayout={extension.mobilePresentation}
            />
          ))}
        </View>
      )}
    </View>
  );
};
