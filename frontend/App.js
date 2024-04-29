// TODO: Figure out how to implement theme changes properly.
// import './assets/styles.css';

import "@expo/metro-runtime";

import { registerRootComponent } from "expo";

import {
  QueryClient,
  QueryClientProvider
} from "@tanstack/react-query";

import Animated, { FadingTransition } from 'react-native-reanimated';

import { PaperProvider } from "react-native-paper";

import Background   from "./components/Background";

import Theme        from "./includes/Theme";

import QueryableApp from "./QueryableApp";
import { SafeAreaView, StyleSheet } from "react-native";
import { SnackbarProvider } from "react-native-paper-snackbar-stack";

export default function App() {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: 0 }
    }
  });

  return (
    <QueryClientProvider client={queryClient}>
      <PaperProvider theme={Theme()}>
        <Background>
            <Animated.View layout={FadingTransition} style={styles.animatedViewRoot}>
              <SnackbarProvider maxSnack={4}>
                <QueryableApp />
              </SnackbarProvider>
            </Animated.View>
        </Background>
      </PaperProvider>
    </QueryClientProvider>
  );
}

const styles = StyleSheet.create({
  animatedViewRoot: {
    width:  '100%',
    height: '100%',
    overflow: 'auto'
  }
})

registerRootComponent(App);