import { useEffect, useState } from "react";
import NavBar     from "./NavBar";
import UserLayout from "./UserLayout";
import { useWindowDimensions } from "react-native";

export default function Main({ appName, colorScheme, setColorScheme }) {
    const [ navbarHeight, setNavbarHeight ] = useState(0);

    const windowWidth = useWindowDimensions().width;

    useEffect(() => {
        console.debug('windowWidth:', windowWidth);
    }, [ windowWidth ]);

    const IS_SMALL_TABLET = windowWidth < 768,  // small tablet
          IS_SMALL_LAPTOP = windowWidth < 1024; // small laptop

    return (
        <>
            <NavBar
                appName={appName}
                heightReporter={setNavbarHeight}
                isSmallTablet={IS_SMALL_TABLET}
                isSmallLaptop={IS_SMALL_LAPTOP}
                colorScheme={colorScheme}
                setColorScheme={setColorScheme}
            />

            <UserLayout
                navbarHeight={navbarHeight}
                isSmallLaptop={IS_SMALL_LAPTOP}
                isSmallTablet={IS_SMALL_TABLET}
                colorScheme={colorScheme}
                setColorScheme={setColorScheme}
            />
        </>
    );
}