import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import React, { useEffect, useState }   from 'react';
import { View, Text, StyleSheet, useWindowDimensions } from 'react-native';
import Reanimated, { FadeIn } from 'react-native-reanimated';

import { TextInput, Button, ActivityIndicator, HelperText, useTheme, Icon } from 'react-native-paper';

import Backend from '../includes/Backend';

import API from '../includes/API';
import { useLocalization } from '../includes/LocalizationProvider';

const Login = ({ appName, style }) => {
    const { colors } = useTheme();
    const { t } = useLocalization();
    const { width } = useWindowDimensions();
    const isSmallScreen = width < 480;

    return (
        <View style={[style, { width }]}>
            <Reanimated.View style={styles.container} entering={FadeIn.duration(500)}>
                <Reanimated.View style={[styles.content, isSmallScreen && styles.contentSmall]} entering={FadeIn.duration(500)}>
                    <Text style={[
                        styles.title,
                        isSmallScreen && styles.titleSmall,
                        { color: colors.onBackground }
                    ]}>
                        {appName}
                    </Text>

                    <View style={styles.messageContainer}>
                        <Text style={[
                            styles.message,
                            { color: colors.onBackground }
                        ]}>
                            {t("login.welcomeBack")}{'\n'}
                            {'\n'}
                            {t("login.prompt")}
                        </Text>
                    </View>

                    <Form colors={colors} isSmallScreen={isSmallScreen} />
                </Reanimated.View>
            </Reanimated.View>
        </View>
    );
};

const Form = ({ colors, isSmallScreen }) => {
    const { t } = useLocalization();
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
            setLoginError(t("login.validationError"));

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
            <Reanimated.View entering={FadeIn.duration(500)}>
                <ActivityIndicator animating={true} />

                <Reanimated.View style={styles.messageContainer} entering={FadeIn.duration(500)}>
                    <Text style={styles.message}>{t("login.preparingForm")}</Text>
                </Reanimated.View>
            </Reanimated.View>
        );
    }

    return (
        <Reanimated.View
            style={[styles.formContainer,
                isSmallScreen && styles.formContainerSmall,
                {
                    backgroundColor: colors.elevation.level1,
                    borderColor:     colors.elevation.level4
                }
            ]}
            entering={FadeIn.duration(500)}
        >
            <Reanimated.View entering={FadeIn.duration(500)}>
                <TextInput
                    value={email}
                    onChangeText={email => setEmail(email)}
                    label={t("login.identifierLabel")}
                    mode="outlined"
                    placeholder={t("login.identifierPlaceholder")}
                    disabled={loginMutation.isPending}
                    onKeyPress={handleKeyPress}
                />
            </Reanimated.View>

            <Reanimated.View entering={FadeIn.duration(500)}>
                <HelperText type="info" style={{ marginVertical: 3 }}>
                    {SHOW_LOGIN_HINTS
                        ? <Text>{t("login.defaultUsernameHint", { username: "admin" })}</Text>
                        : <Text>{t("login.identifierHint")}</Text>
                    }
                </HelperText>
            </Reanimated.View>

            <Reanimated.View entering={FadeIn.duration(500)}>
                <TextInput
                    value={password}
                    onChangeText={password => setPassword(password)}
                    label={t("login.passwordLabel")}
                    mode="outlined"
                    placeholder={t("login.passwordPlaceholder")}
                    secureTextEntry
                    disabled={loginMutation.isPending}
                    onKeyPress={handleKeyPress}
                />
            </Reanimated.View>

            <Reanimated.View entering={FadeIn.duration(500)}>
                <HelperText type="info" style={{ marginVertical: 3 }}>
                    {SHOW_LOGIN_HINTS
                        ? <Text>{t("login.defaultPasswordHint", { password: "admin" })}</Text>
                        : <Text>{t("login.forgotPasswordHint")}</Text>
                    }
                </HelperText>
            </Reanimated.View>

            {loginError.length > 0 &&
                <Reanimated.View entering={FadeIn.duration(500)}>
                    <HelperText type="error" style={styles.centeredText}>
                        {loginError}
                    </HelperText>
                </Reanimated.View>
            }

            <Reanimated.View style={{ flexDirection: 'row', justifyContent: 'center' }} entering={FadeIn.duration(500)}>
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
                                    ? t("login.submitting")
                                    : t("login.submit")
                            }
                        </Text>
                    </View>
                </Button>
            </Reanimated.View>
        </Reanimated.View>
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
        width: '100%',
    },
    contentSmall: {
        paddingHorizontal: 12,
    },
    title: {
        fontSize: 48,
        fontWeight: 'bold',
        marginBottom: 10,
        textAlign: 'center',
    },
    titleSmall: {
        fontSize: 32,
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
    formContainerSmall: {
        padding: 16,
        borderRadius: 8,
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
});

export default Login;
