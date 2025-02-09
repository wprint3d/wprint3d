import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import React, { useEffect, useState }   from 'react';
import { View, Text, StyleSheet }       from 'react-native';

import { TextInput, Button, ActivityIndicator, HelperText, useTheme, Icon } from 'react-native-paper';

import Backend from '../includes/Backend';

import API from '../includes/API';

const Login = ({ appName, style }) => {
    const { colors } = useTheme();

    return (
        <View style={style}>
            <View style={styles.container}>
                <View style={styles.content}>
                    <Text style={[
                        styles.title,
                        { color: colors.onBackground }
                    ]}>
                        {appName}
                    </Text>

                    <View style={styles.messageContainer}>
                        <Text style={[
                            styles.message,
                            { color: colors.onBackground }
                        ]}>
                            Welcome back!{'\n'}
                            {'\n'}
                            You can now log in to your account.
                        </Text>
                    </View>

                    <Form colors={colors} />
                </View>
            </View>
        </View>
    );
};

const Form = ({ colors }) => {
    const [ email,       setEmail        ] = useState('');
    const [ password,    setPassword     ] = useState('');
    const [ loginError,  setLoginError   ] = useState('');

    const queryClient = useQueryClient();

    const csrfTokenQuery = useQuery({
        queryKey:   [ 'csrf-token' ],
        queryFn:    () => Backend.get('/sanctum/csrf-cookie'),
        refetchOnWindowFocus: true
    });

    const loginHintsQuery = useQuery({
        queryKey:   [ 'login-hints' ],
        queryFn:    () => API.get('/config/showFirstLoginHints')
    });

    const loginMutation = useMutation({
        mutationKey: [ 'login' ],
        mutationFn:  ({ email, password }) => (
            Backend.post('/login', {
                email:    email,
                password: password
            })
        ),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['checkLogin'] })
    });

    const handleLoginRequest = () => {
        if (email.length == 0 || password.length == 0) {
            setLoginError('Both a username or an e-mail address and a password must be provided.');

            return;
        }

        setLoginError('');

        loginMutation.mutate({
            email:    email,
            password: password
        });
    };

    const handleKeyPress = event => {
        if (event.type === 'keydown' && event.key === 'Enter') {
            handleLoginRequest();
        }
    };

    useEffect(() => {
        if (loginMutation.isPending) { return; }

        if (loginMutation.isError) {
            setLoginError(
                loginMutation.error.response
                    ? loginMutation.error.response.data.message
                    : loginMutation.error
            );
        }
    }, [ loginMutation ]);

    useEffect(() => {
        console.debug('loginHintsQuery:', loginHintsQuery);
    }, [ loginHintsQuery.data ]);

    const SHOW_LOGIN_HINTS = !!(loginHintsQuery?.data?.data);

    if (csrfTokenQuery.isFetching) {
        return (
            <View>
                <ActivityIndicator animating={true} />

                <View style={styles.messageContainer}>
                    <Text style={styles.message}>Preparing login form...</Text>
                </View>
            </View>
        );
    }

    return (
        <View style={[
            styles.formContainer,
            {
                backgroundColor: colors.elevation.level1,
                borderColor:     colors.elevation.level4
            }
        ]}>
            <TextInput
                value={email}
                onChangeText={email => setEmail(email)}
                label="Username or e-mail address"
                mode="outlined"
                placeholder="Enter username or email"
                disabled={loginMutation.isPending}
                onKeyPress={handleKeyPress}
            />

            <HelperText type="info" style={{ marginVertical: 3 }}>
                {SHOW_LOGIN_HINTS
                    ? <Text>The default username is <Text style={styles.textBold}>admin</Text>.</Text>
                    : <Text>Type your username or e-mail address.</Text>
                }
            </HelperText>

            <TextInput
                value={password}
                onChangeText={password => setPassword(password)}
                label="Password"
                mode="outlined"
                placeholder="Enter password"
                secureTextEntry
                disabled={loginMutation.isPending}
                onKeyPress={handleKeyPress}
            />

            <HelperText type="info" style={{ marginVertical: 3 }}>
                {SHOW_LOGIN_HINTS
                    ? <Text>The default passsword is <Text style={styles.textBold}>admin</Text>.</Text>
                    : <Text>Forgot your password? <Text style={styles.textBold}>Contact your administrator.</Text></Text>
                }
            </HelperText>

            {(loginError && loginError.length > 0) &&
                <HelperText type="error" style={styles.centeredText}>
                    {loginError}
                </HelperText>
            }

            <View style={{ flexDirection: 'row', justifyContent: 'center' }}>
                <Button
                    onPress={handleLoginRequest}
                    mode="contained"
                    style={styles.formSubmitButton}
                    disabled={loginMutation.isPending}
                    loading={loginMutation.isPending}
                >
                    <View style={{ flexDirection: 'row', alignItems: 'center' }}>
                        {!loginMutation.isPending && <Icon source='login' color={colors.onPrimary} size={16} />}
                        <Text style={{ marginLeft: 4 }}>
                            {
                                loginMutation.isPending
                                    ? 'Logging in...'
                                    : 'Sign in'
                            }
                        </Text>
                    </View>
                </Button>
            </View>
        </View>
    );
};

const styles = StyleSheet.create({
    container: {
        flex: 1,
        maxWidth: '100%',
        justifyContent: 'center',
        alignItems: 'center'
    },
    content: {
        paddingHorizontal: 20,
        paddingVertical: 10,
        width: '100%'
    },
    title: {
        fontSize: 36,
        fontWeight: 'bold',
        marginBottom: 10,
        textAlign: 'center',
        fontSize: 48
    },
    centeredText: { textAlign: 'center' },
    spinnerContainer: {
        alignItems: 'center',
        marginBottom: 20,
    },
    messageContainer: {
        paddingHorizontal: 20,
        paddingVertical: 10,
        borderRadius: 10,
        marginBottom: 20,
    },
    message: {
        fontSize: 16,
        fontWeight: 'light',
        textAlign: 'center'
    },
    formContainer: {
        borderRadius: 12,
        borderWidth: 1,
        padding: 24,
        width: 500,
        maxWidth: '100%',
        alignSelf: 'center'
    },
    formSubmitButton: {
        marginTop: 10,
        minWidth: 120
    },
    formSubmitButtonLoaderContainer: {
        display: 'flex',
        flexDirection: 'row'
    },
    formSubmitButtonLoader: { paddingRight: 4 },
    textBold: { fontWeight: 'bold' }
});

export default Login;
