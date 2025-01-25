// TODO: Figure out how to implement theme changes properly.
// import './assets/styles.css';

import "@expo/metro-runtime";

import { registerRootComponent } from "expo";

import {
  QueryClient,
  QueryClientProvider
} from "@tanstack/react-query";

import { PaperProvider } from "react-native-paper";

import Background   from "./components/Background";

import Theme        from "./includes/Theme";

import QueryableApp from "./QueryableApp";
import { SafeAreaView, StyleSheet, useColorScheme, View } from "react-native";

import { SnackbarProvider } from "react-native-paper-snackbar-stack";
import { useCache } from "./hooks/useCache";
import { useEffect, useState } from "react";

export default function App() {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: {
        retry: 0,
        refetchOnWindowFocus: false
      }
    }
  });

  const cache = useCache();

  const [ colorScheme, _setColorScheme ] = useState(null);

  const setColorScheme = (value) => {
    _setColorScheme(value);

    cache.set('colorScheme', value);
  }

  useEffect(() => {
    cache.get('colorScheme').then((value) => {
      if (!value) { return; }

      setColorScheme(value);
    });
  }, []);

  return (
    <QueryClientProvider client={queryClient}>
      <PaperProvider theme={Theme({ colorScheme })}>
        <Background>
          <View style={styles.root}>
            <SnackbarProvider maxSnack={4}>
              <QueryableApp colorScheme={colorScheme} setColorScheme={setColorScheme} />
            </SnackbarProvider>
          </View>
        </Background>
      </PaperProvider>
    </QueryClientProvider>
  );
}

const styles = StyleSheet.create({
  root: {
    width:  '100%',
    height: '100%',
    overflow: 'auto'
  }
})

registerRootComponent(App);