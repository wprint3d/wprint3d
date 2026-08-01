import { useQuery } from '@tanstack/react-query';

import { Animated, AppState, Linking, StyleSheet, Text, View } from 'react-native';
import Reanimated, { FadeIn } from 'react-native-reanimated';
import { ActivityIndicator, Icon, useTheme } from 'react-native-paper';

import Login  from './components/Login';
import Main   from './components/Main';

import API from './includes/API';

import { useEffect, useState } from 'react';
import UserChangePasswordModal from './components/UserChangePasswordModal';
import PluginLoadingProvider from './components/PluginLoadingProvider';
import { useLocalization } from './includes/LocalizationProvider';
import { EchoProvider } from './hooks/useEcho';

export default function QueryableApp({ colorScheme, setColorScheme }) {
  const { colors } = useTheme();
  const { t } = useLocalization();

  const messageStyle = { color: colors.onBackground, textAlign: 'center' };

  const [ lastAppState, setLastAppState ] = useState('active');

  const getAppName = useQuery({
    queryKey: ['getAppName'],
    queryFn:  () => API.get('/app/name')
  });

  console.debug('getAppName:', getAppName);

  const checkLogin = useQuery({
    queryKey: ['checkLogin'],
    queryFn:  () => API.get('/checkLogin'),
    refetchOnWindowFocus: 'always',
    enabled: getAppName.isSuccess && lastAppState === 'active'
  });

  console.debug('checkLogin:', checkLogin);

  useEffect(() => {
    const subscription = AppState.addEventListener('change', nextAppState => {
      console.debug('nextAppState:', nextAppState);

      setLastAppState(nextAppState);
    });
  
    return () => {
      subscription.remove();
    };
  }, [ lastAppState ]);

  useEffect(() => {
    if (getAppName.isFetching || getAppName.isSuccess) { return; }

    setTimeout(() => getAppName.refetch(), 5000);
  }, [ getAppName.isFetching, getAppName.isSuccess ]);

  const sharedRetryingScale = new Animated.Value(1);

  // Write a useEffect() hook to make the heart bounce while the app name is being fetched
  useEffect(() => {
    if (!getAppName.isFetching) { return; }

    const interval = setInterval(() => {
      Animated.spring(sharedRetryingScale, {
        toValue: 1.1,
        friction: 1,
        duration: 500,
        useNativeDriver: true
      }).start(() => {
        sharedRetryingScale.setValue(1);
      });
    }, 750);

    return () => clearInterval(interval);
  }, [ getAppName.isFetching ]);

  // if (lastAppState !== null && lastAppState !== 'active') {
  //   return (
  //     <View style={styles.preloader}>
  //       <View style={styles.container}>
  //         <Icon source="sleep" size={48} />

  //         <View style={styles.messageContainer}>
  //             <Text style={messageStyle}>
  //               The application sleeps while you're away in order to save on system resources.
  //               {'\n'}
  //               {'\n'}
  //               Please wait for a few seconds while we get back on track...
  //             </Text>
  //         </View>
  //       </View>
  //     </View>
  //   );
  // }

  if (getAppName.isError && getAppName?.error?.status === 502) {
    return (
      <Reanimated.View 
        style={styles.preloader}
        entering={FadeIn.duration(500)}
      >
        <View style={styles.container}>
          <ActivityIndicator animating={true} />

          <Reanimated.View 
            style={styles.messageContainer}
            entering={FadeIn.delay(200).duration(500)}
          >
              <Text style={messageStyle}>
                {t("app.serverStarting")}
              </Text>
          </Reanimated.View>
        </View>
      </Reanimated.View>
    );
  }

  if (!getAppName.isSuccess && getAppName.isFetched) {
    return (
      <View style={styles.preloader}>
        <View style={styles.container}>
          <View style={styles.messageContainer}>
            <View>
              <Animated.View style={{ transform: [{ scale: sharedRetryingScale }], textAlignLast: 'center' }}>
                <Icon source="heart-broken" size={48} color={getAppName.isFetching ? 'orange': colors.onBackground} />
              </Animated.View>
              <Text style={[ messageStyle, { visibility: getAppName.isFetching ? 'visible' : 'hidden' } ]}> {t("app.retrying")} </Text>
            </View>
            <View style={styles.messageContainer}>
                <Text style={messageStyle}>
                  {t("app.serverUnavailable")}
                  {'\n'}
                  {'\n'}
                  {t("app.problemPersistsPrefix")}<Text style={{ textDecorationLine: 'underline' }} onPress={() => Linking.openURL('https://github.com/wprint3d/wprint3d')}>{t("app.createGithubIssue")}</Text>{t("app.problemPersistsSuffix")}
                </Text>
            </View>
          </View>
        </View>
      </View>
    );
  }

  if (!getAppName.isFetched || !checkLogin.isFetched) {
    return (
      <Reanimated.View 
        style={styles.preloader}
        entering={FadeIn.duration(500)}
      >
        <Reanimated.View style={styles.container}>
          <ActivityIndicator animating={true} />

          <Reanimated.View 
            style={styles.messageContainer}
            entering={FadeIn.delay(200).duration(500)}
          >
              <Text style={messageStyle}>
                {t("app.loadingAssets")}
              </Text>
          </Reanimated.View>
        </Reanimated.View>
      </Reanimated.View>
    );
  }

  const appName = getAppName.data.data;

  if (!checkLogin.isSuccess) {
    return (
      <>
        {checkLogin?.error?.status === 423 && (
          <UserChangePasswordModal
            visible={true} fromFirstLogin={true}
            extraHint={checkLogin?.error?.response?.data}
          />
        )}
        <Reanimated.View style={styles.container} entering={FadeIn.duration(500)}>
          <Login appName={appName} style={{flex: 1}} />
        </Reanimated.View>
      </>
    );
  }

  return (
    <Reanimated.View style={{flex: 1}} entering={FadeIn.duration(500)}>
      <EchoProvider>
        <PluginLoadingProvider>
          <Main appName={appName} colorScheme={colorScheme} setColorScheme={setColorScheme} />
        </PluginLoadingProvider>
      </EchoProvider>
    </Reanimated.View>
  );
}

const styles = StyleSheet.create({
  preloader: {
    width: '100%',
    height: '100%',
    display: 'flex',
    flexDirection: 'row',
    alignItems: 'center'
  },
  container: {
    flex: 1,
    display: 'flex',
    flexDirection: 'column',
    alignItems: 'center'
  },
  spinnerContainer: {
    alignItems: 'center',
    marginBottom: 20,
  },
  messageContainer: {
    paddingHorizontal: 20,
    paddingVertical: 10,
    borderRadius: 10,
    marginBottom: 20,
    alignItems: 'center'
  }
});
