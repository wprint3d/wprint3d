import AsyncStorage from '@react-native-async-storage/async-storage';

export function useCache() {
    return {
        set: async (key, value) => await AsyncStorage.setItem(key, JSON.stringify(value)),

        get: async (key, defaultValue = null) => {
            const item = await AsyncStorage.getItem(key);

            if (item !== null) {
                const parsedItem = JSON.parse(item);

                if (typeof parsedItem == 'undefined') {
                    return defaultValue;
                }

                return parsedItem;
            }

            return defaultValue;
        }
    }
}