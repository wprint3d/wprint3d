import { useQuery } from '@tanstack/react-query';
import { StatusBar } from 'expo-status-bar';
import { AppState, StyleSheet, Text, View } from 'react-native';
import { ActivityIndicator, Icon } from 'react-native-paper';

import Login  from './components/Login';
import Main   from './components/Main';

import API from './includes/API';
import { useEffect, useRef, useState } from 'react';

export default function QueryableApp() {
  const [ lastAppState, setLastAppState ] = useState(null);

  const getAppName = useQuery({
    queryKey: ['getAppName'],
    queryFn:  () => API.get('/app/name')
  });

  console.debug('getAppName:', getAppName);

  const checkLogin = useQuery({
    queryKey: ['checkLogin'],
    queryFn:  () => API.get('/checkLogin'),
    refetchOnWindowFocus: 'always'
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

  if (lastAppState !== null && lastAppState !== 'active') {
    return (
      <View style={styles.preloader}>
        <View style={styles.container}>
          <Icon source="sleep" size={48} />

          <View style={styles.messageContainer}>
              <Text style={styles.message}>
                The application sleeps while you're away in order to save on system resources.
                {'\n'}
                {'\n'}
                Please wait for a few seconds while we get back on track...
              </Text>
          </View>
        </View>
      </View>
    );
  }

  if (!getAppName.isFetched || !checkLogin.isFetched) {
    return (
      <View style={styles.preloader}>
        <View style={styles.container}>
          <ActivityIndicator animating={true} />

          <View style={styles.messageContainer}>
              <Text style={styles.message}>
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
              <Text style={styles.message}>
                Something went wrong, please wait for a few minutes and try again.
              </Text>
          </View>
        </View>
      </View>
    );
  }

  const appName = getAppName.data.data;

  if (!checkLogin.isSuccess) {
    return <Login appName={appName} style={styles.container} />;
  }

  return <Main appName={appName} />;
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
  message: { textAlign: 'center' },
  messageContainer: {
    paddingHorizontal: 20,
    paddingVertical: 10,
    borderRadius: 10,
    marginBottom: 20,
    alignItems: 'center'
  }
});
