import { useState } from 'react';

import { Appbar } from 'react-native-paper';

import NavBarMenu from './NavBarMenu';

export default function NavBar({
  heightReporter = () => {}, enqueueSnackbar = () => {},
  appName, isSmallTablet, isSmallLaptop, colorScheme, setColorScheme 
}) {
  const [ headerHeight, _setHeaderHeight ] = useState(0);

  const setHeaderHeight = (height) => {
    _setHeaderHeight(height);

    if (heightReporter) {
      heightReporter(height);
    }
  };

  return (
    <>
      <Appbar.Header onLayout={event => setHeaderHeight(event.nativeEvent.layout.height)}>
        <Appbar.Content title={appName} titleStyle={{ fontSize: 18, fontWeight: 'bold' }} />
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