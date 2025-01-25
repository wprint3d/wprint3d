import { useColorScheme } from 'react-native';

import {
    useMaterial3Theme,
    isDynamicThemeSupported
} from '@pchmn/expo-material3-theme';

import {
    MD3LightTheme,
    MD3DarkTheme
} from 'react-native-paper';
import { useCache } from '../hooks/useCache';

export default (({ colorScheme }) => {
    const preferredScheme = useColorScheme();

    const targetColorScheme = colorScheme || preferredScheme;

    console.debug('themes: colorScheme:', targetColorScheme);

    if (!isDynamicThemeSupported) {
        const availableThemes = {
            light:  require('../assets/themes/light.json'),
            dark:   require('../assets/themes/dark.json')
        };

        console.debug('themes: static:', availableThemes);

        return (
            targetColorScheme === 'dark'
                ? availableThemes.dark
                : availableThemes.light
        );
    }

    const { theme } = useMaterial3Theme({ sourceColor: '#4F5863' });

    console.debug('themes: dynamic:', theme);

    return (
        targetColorScheme === 'dark'
            ? { ...MD3DarkTheme,  colors: theme.dark  }
            : { ...MD3LightTheme, colors: theme.light }
    );
})
