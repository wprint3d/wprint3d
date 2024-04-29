import { useColorScheme } from 'react-native';

import {
    useMaterial3Theme,
    isDynamicThemeSupported
} from '@pchmn/expo-material3-theme';

import {
    MD3LightTheme,
    MD3DarkTheme
} from 'react-native-paper';

export default (() => {
    const colorScheme = useColorScheme();

    if (!isDynamicThemeSupported) {
        const availableThemes = {
            light:  require('../assets/themes/light.json'),
            dark:   require('../assets/themes/dark.json')
        };

        console.debug('themes: static:', availableThemes);

        return (
            colorScheme === 'dark'
                ? availableThemes.dark
                : availableThemes.light
        );
    }

    const { theme } = useMaterial3Theme({ sourceColor: '#4F5863' });

    console.debug('themes: dynamic:', theme);

    return (
        colorScheme === 'dark'
            ? { ...MD3DarkTheme,  colors: theme.dark  }
            : { ...MD3LightTheme, colors: theme.light }
    );
})
