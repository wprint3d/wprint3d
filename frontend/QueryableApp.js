import { useQuery } from '@tanstack/react-query';

import { Animated, AppState, Linking, StyleSheet, Text, View } from 'react-native';
import { ActivityIndicator, Icon, useTheme } from 'react-native-paper';

import Login  from './components/Login';
import Main   from './components/Main';

import API from './includes/API';

import { useEffect, useState } from 'react';
import UserChangePasswordModal from './components/UserChangePasswordModal';

export default function QueryableApp({ colorScheme, setColorScheme }) {
  const { colors } = useTheme();

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

  if (!getAppName.isFetched || !checkLogin.isFetched) {
    return (
      <View style={styles.preloader}>
        <View style={styles.container}>
          <ActivityIndicator animating={true} />

          <View style={styles.messageContainer}>
              <Text style={messageStyle}>
                Please wait for a while, we're still loading some assets...
              </Text>
          </View>
        </View>
      </View>
    );
  }

  if (!getAppName.isSuccess) {
    return (
      <View style={styles.preloader}>
        <View style={styles.container}>
          <View style={styles.messageContainer}>
            <View>
              <Animated.View style={{ transform: [{ scale: sharedRetryingScale }], textAlignLast: 'center' }}>
                <Icon source="heart-broken" size={48} color={getAppName.isFetching ? 'orange': colors.onBackground} />
              </Animated.View>
              <Text style={[ messageStyle, { visibility: getAppName.isFetching ? 'visible' : 'hidden' } ]}> Retrying... </Text>
            </View>
            <View style={styles.messageContainer}>
                <Text style={messageStyle}>
                  Something went wrong, please wait for a few seconds as the server becomes available.
                  {'\n'}
                  {'\n'}
                  If the problem persists, please <Text style={{ textDecorationLine: 'underline' }} onPress={() => Linking.openURL('https://github.com/wprint3d/wprint3d')}>create an issue on our GitHub repository</Text>.
                </Text>
            </View>
          </View>
        </View>
      </View>
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
        <Login appName={appName} style={styles.container} />
      </>
    );
  }

  return <Main appName={appName} colorScheme={colorScheme} setColorScheme={setColorScheme} />;
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
