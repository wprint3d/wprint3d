import { useState } from 'react';
import { View } from 'react-native';

import { Appbar } from 'react-native-paper';

import NavBarMenu from './NavBarMenu';
import PluginHostRenderer from './PluginHostRenderer';
import usePluginExtensions from '../hooks/usePluginExtensions';

export default function NavBar({
  heightReporter = () => {}, enqueueSnackbar = () => {},
  appName, isSmallTablet, isSmallLaptop, colorScheme, setColorScheme 
}) {
  const [ headerHeight, _setHeaderHeight ] = useState(0);
  const navbarWidgetExtensions = usePluginExtensions('navbar_widget');

  const setHeaderHeight = (height) => {
    _setHeaderHeight(height);

    if (heightReporter) {
      heightReporter(height);
    }
  };

  return (
    <>
      <Appbar.Header onLayout={event => setHeaderHeight(event.nativeEvent.layout.height)} style={{ minHeight: 46 }}>
        <Appbar.Content title={appName} titleStyle={{ fontSize: 18, fontWeight: 'bold' }} />
        {!!navbarWidgetExtensions?.data?.data?.length && (
          <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6, marginRight: 6, paddingVertical: 7 }}>
            {(navbarWidgetExtensions?.data?.data || []).map((extension) => (
              <PluginHostRenderer
                key={`${extension.pluginId}-${extension.id}`}
                extension={extension}
              />
            ))}
          </View>
        )}
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
