import { useEffect, useRef } from 'react';

import { Appbar } from 'react-native-paper';

import NavBarMenu from './NavBarMenu';

export default function NavBar({ appName, heightReporter, isSmallTablet, isSmallLaptop, colorScheme, setColorScheme }) {
  return (
    <>
      <Appbar.Header onLayout={event => heightReporter(event.nativeEvent.layout.height)}>
        <Appbar.Content title={appName} titleStyle={{ fontSize: 18, fontWeight: 'bold' }} />
        <NavBarMenu isSmallTablet={isSmallTablet} isSmallLaptop={isSmallLaptop} colorScheme={colorScheme} setColorScheme={setColorScheme} />
      </Appbar.Header>
    </>
  );
};