import { useEffect, useState } from "react";
import NavBar     from "./NavBar";
import UserLayout from "./UserLayout";
import { useWindowDimensions } from "react-native";
import { useSnackbar } from "react-native-paper-snackbar-stack";
import Reanimated, { FadeIn } from "react-native-reanimated";

export default function Main({ appName, colorScheme, setColorScheme }) {
    const [ navbarHeight, setNavbarHeight ] = useState(0);

    const { enqueueSnackbar } = useSnackbar();

    const windowWidth = useWindowDimensions().width;

    useEffect(() => {
        console.debug('windowWidth:', windowWidth);
    }, [ windowWidth ]);

    const IS_SMALL_TABLET = windowWidth < 768,  // small tablet
          IS_SMALL_LAPTOP = windowWidth < 1024; // small laptop

    return (
        <Reanimated.View 
            style={{ flex: 1 }}
            entering={FadeIn.duration(500)}
        >
            <NavBar
                appName={appName}
                heightReporter={setNavbarHeight}
                isSmallTablet={IS_SMALL_TABLET}
                isSmallLaptop={IS_SMALL_LAPTOP}
                colorScheme={colorScheme}
                setColorScheme={setColorScheme}
                enqueueSnackbar={enqueueSnackbar}
            />

            <UserLayout
                navbarHeight={navbarHeight}
                isSmallLaptop={IS_SMALL_LAPTOP}
                isSmallTablet={IS_SMALL_TABLET}
                colorScheme={colorScheme}
                setColorScheme={setColorScheme}
            />
        </Reanimated.View>
    );
}