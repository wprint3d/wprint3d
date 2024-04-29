import { useEffect, useRef } from 'react';

import { Appbar } from 'react-native-paper';

import NavBarMenu from './NavBarMenu';

export default function NavBar({ appName, heightReporter }) {
  return (
    <>
      <Appbar.Header onLayout={event => heightReporter(event.nativeEvent.layout.height)}>
        <Appbar.Content title={appName} titleStyle={{ fontSize: 18, fontWeight: 'bold' }} />
        <NavBarMenu />
      </Appbar.Header>
    </>
  );
};