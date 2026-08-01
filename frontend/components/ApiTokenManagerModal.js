import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useEffect, useMemo, useState } from "react";
import { ScrollView, View } from "react-native";
import { Button, Card, Chip, Divider, Icon, IconButton, Modal, Portal, Text, TextInput, Tooltip, useTheme } from "react-native-paper";
import DropDown from "react-native-paper-dropdown";
import API from "../includes/API";
import { useLocalization } from "../includes/LocalizationProvider";
import { getScrollableModalContentStyle, getScrollableModalFrameStyle } from "../utils/modalLayout";
import SimpleDialog from "./SimpleDialog";
import UserPaneLoadingIndicator from "./UserPaneLoadingIndicator";
import BackButton from "./modules/BackButton";

const readError = (error, fallback) => {
    const errors = error?.response?.data?.errors;

    if (errors) {
        return Object.values(errors).flat()[0] || fallback;
    }

    return error?.response?.data?.message || error?.response?.data?.error || fallback;
};

const ApiTokenManagerModal = ({
    visible,
    onDismiss,
    user,
    isSmallTablet,
    enqueueSnackbar,
}) => {
    const { colors } = useTheme();
    const { t, effectiveLanguage } = useLocalization();
    const queryClient = useQueryClient();
    const [ screen, setScreen ] = useState('list'),
          [ name, setName ] = useState('Cura'),
          [ printerUuid, setPrinterUuid ] = useState(''),
          [ expiresInDays, setExpiresInDays ] = useState('365'),
          [ password, setPassword ] = useState(''),
          [ secret, setSecret ] = useState(''),
          [ error, setError ] = useState(''),
          [ showPrinterPicker, setShowPrinterPicker ] = useState(false),
          [ showExpiryPicker, setShowExpiryPicker ] = useState(false),
          [ tokenToRevoke, setTokenToRevoke ] = useState(null),
          [ revokePassword, setRevokePassword ] = useState('');

    const tokensQuery = useQuery({
        queryKey: ['apiTokens', user?._id],
        queryFn: () => API.get(`/users/${user._id}/tokens`),
        enabled: visible && !!user?._id,
    });

    const printersQuery = useQuery({
        queryKey: ['printers'],
        queryFn: () => API.get('/printers'),
        enabled: visible,
    });

    const printers = printersQuery.data?.data || [];
    const tokens = tokensQuery.data?.data?.tokens || [];
    const printerOptions = useMemo(() => printers
        .filter((printer) => printer?.machine?.uuid)
        .map((printer) => ({
            label: printer?.machine?.machineType || printer.machine.uuid,
            value: printer.machine.uuid,
        })), [ printers ]);

    useEffect(() => {
        if (!printerUuid && printerOptions.length > 0) {
            setPrinterUuid(printerOptions[0].value);
        }
    }, [ printerOptions, printerUuid ]);

    useEffect(() => {
        if (!visible) {
            setScreen('list');
            setPassword('');
            setSecret('');
            setError('');
            setTokenToRevoke(null);
            setRevokePassword('');
        }
    }, [ visible ]);

    const createMutation = useMutation({
        mutationFn: async () => {
            await API.post('/user/confirm-password', { password });

            return API.post(`/users/${user._id}/tokens`, {
                name,
                printerUuid,
                expiresInDays: expiresInDays === 'never' ? null : Number(expiresInDays),
            });
        },
        onSuccess: (response) => {
            setSecret(response?.data?.plainTextToken || '');
            setPassword('');
            setScreen('secret');
            setError('');
            queryClient.invalidateQueries({ queryKey: ['apiTokens', user?._id] });
        },
        onError: (mutationError) => setError(readError(mutationError, t('apiTokens.createError'))),
    });

    const revokeMutation = useMutation({
        mutationFn: async () => {
            await API.post('/user/confirm-password', { password: revokePassword });

            return API.delete(`/users/${user._id}/tokens/${tokenToRevoke.id}`);
        },
        onSuccess: () => {
            setTokenToRevoke(null);
            setRevokePassword('');
            queryClient.invalidateQueries({ queryKey: ['apiTokens', user?._id] });
            enqueueSnackbar?.({
                message: t('apiTokens.revokeSuccess'),
                variant: 'success',
                action: { label: t('apiTokens.dismiss') },
            });
        },
        onError: (mutationError) => setError(readError(mutationError, t('apiTokens.revokeError'))),
    });

    const copySecret = async () => {
        try {
            if (!globalThis?.navigator?.clipboard?.writeText) {
                throw new Error('Clipboard unavailable');
            }

            await globalThis.navigator.clipboard.writeText(secret);
            enqueueSnackbar?.({
                message: t('apiTokens.copySuccess'),
                variant: 'success',
                action: { label: t('apiTokens.dismiss') },
            });
        } catch (_copyError) {
            enqueueSnackbar?.({
                message: t('apiTokens.copyError'),
                variant: 'error',
                action: { label: t('apiTokens.dismiss') },
            });
        }
    };

    const resetCreateForm = () => {
        setName('Cura');
        setExpiresInDays('365');
        setPassword('');
        setError('');
        setScreen('list');
    };

    const formatDate = (value) => value
        ? new Intl.DateTimeFormat(effectiveLanguage?.replace('_', '-') || 'en', { dateStyle: 'medium' }).format(new Date(value))
        : t('apiTokens.never');

    const renderList = () => {
        if (tokensQuery.isFetching) {
            return <UserPaneLoadingIndicator message={t('apiTokens.loading')} />;
        }

        if (tokensQuery.isError) {
            return (
                <View style={{ alignItems: 'center', paddingVertical: 32, gap: 12 }}>
                    <Icon source="alert-circle-outline" size={40} color={colors.error} />
                    <Text variant="bodyLarge" style={{ textAlign: 'center' }}>{t('apiTokens.loadError')}</Text>
                    <Button mode="outlined" icon="refresh" onPress={() => tokensQuery.refetch()}>{t('apiTokens.retry')}</Button>
                </View>
            );
        }

        return (
            <View style={{ gap: 12 }}>
                {tokens.length === 0 ? (
                    <View style={{ alignItems: 'center', paddingVertical: 32, gap: 8 }}>
                        <Icon source="key-variant" size={40} color={colors.onSurfaceVariant} />
                        <Text variant="titleMedium">{t('apiTokens.emptyTitle')}</Text>
                        <Text variant="bodyMedium" style={{ color: colors.onSurfaceVariant, textAlign: 'center' }}>
                            {t('apiTokens.emptyBody')}
                        </Text>
                    </View>
                ) : tokens.map((token) => (
                    <Card key={token.id} mode="outlined">
                        <Card.Content style={{ gap: 6 }}>
                            <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
                                <Text variant="titleMedium" style={{ flex: 1 }}>{token.name}</Text>
                                {token.expired && <Chip compact icon="clock-alert-outline">{t('apiTokens.expired')}</Chip>}
                                <Tooltip title={t('apiTokens.revoke')}>
                                    <IconButton
                                        icon="delete-outline"
                                        iconColor={colors.error}
                                        accessibilityLabel={t('apiTokens.revoke')}
                                        onPress={() => {
                                            setError('');
                                            setTokenToRevoke(token);
                                        }}
                                    />
                                </Tooltip>
                            </View>
                            <Text variant="bodyMedium">{t('apiTokens.printerValue', { value: token.printerUuid })}</Text>
                            <Text variant="bodySmall" style={{ color: colors.onSurfaceVariant }}>
                                {t('apiTokens.createdValue', { value: formatDate(token.createdAt) })}
                            </Text>
                            <Text variant="bodySmall" style={{ color: colors.onSurfaceVariant }}>
                                {t('apiTokens.expiresValue', { value: formatDate(token.expiresAt) })}
                            </Text>
                            <Text variant="bodySmall" style={{ color: colors.onSurfaceVariant }}>
                                {t('apiTokens.lastUsedValue', { value: token.lastUsedAt ? formatDate(token.lastUsedAt) : t('apiTokens.notUsed') })}
                            </Text>
                        </Card.Content>
                    </Card>
                ))}

                <Button mode="contained" icon="plus" onPress={() => {
                    setError('');
                    setScreen('create');
                }}>
                    {t('apiTokens.create')}
                </Button>
            </View>
        );
    };

    const renderCreate = () => (
        <View style={{ gap: 12 }}>
            <Text variant="bodyMedium" style={{ color: colors.onSurfaceVariant }}>{t('apiTokens.createIntro')}</Text>
            <TextInput mode="outlined" label={t('apiTokens.name')} value={name} onChangeText={setName} maxLength={80} />
            <DropDown
                label={t('apiTokens.printer')}
                mode="outlined"
                value={printerUuid}
                setValue={setPrinterUuid}
                list={printerOptions}
                visible={showPrinterPicker}
                showDropDown={() => setShowPrinterPicker(true)}
                onDismiss={() => setShowPrinterPicker(false)}
            />
            {printersQuery.isError && <Text style={{ color: colors.error }}>{t('apiTokens.printersError')}</Text>}
            <DropDown
                label={t('apiTokens.expiration')}
                mode="outlined"
                value={expiresInDays}
                setValue={setExpiresInDays}
                list={[
                    { label: t('apiTokens.days30'), value: '30' },
                    { label: t('apiTokens.days90'), value: '90' },
                    { label: t('apiTokens.days365'), value: '365' },
                    { label: t('apiTokens.never'), value: 'never' },
                ]}
                visible={showExpiryPicker}
                showDropDown={() => setShowExpiryPicker(true)}
                onDismiss={() => setShowExpiryPicker(false)}
            />
            <TextInput
                mode="outlined"
                label={t('apiTokens.currentPassword')}
                value={password}
                onChangeText={setPassword}
                secureTextEntry
                autoComplete="current-password"
            />
            <Text variant="bodySmall" style={{ color: colors.onSurfaceVariant }}>{t('apiTokens.passwordHelp')}</Text>
            {!!error && <Text accessibilityRole="alert" style={{ color: colors.error }}>{error}</Text>}
            <View style={{ flexDirection: 'row', justifyContent: 'flex-end', flexWrap: 'wrap', gap: 8 }}>
                <Button onPress={resetCreateForm} disabled={createMutation.isPending}>{t('apiTokens.cancel')}</Button>
                <Button
                    mode="contained"
                    icon="key-plus"
                    loading={createMutation.isPending}
                    disabled={createMutation.isPending || !name.trim() || !printerUuid || !password}
                    onPress={() => createMutation.mutate()}
                >
                    {t('apiTokens.create')}
                </Button>
            </View>
        </View>
    );

    const renderSecret = () => (
        <View style={{ gap: 16 }}>
            <View style={{ flexDirection: 'row', gap: 12, alignItems: 'flex-start' }}>
                <Icon source="alert-outline" size={28} color={colors.warning || colors.tertiary} />
                <Text variant="bodyLarge" style={{ flex: 1 }}>{t('apiTokens.secretWarning')}</Text>
            </View>
            <TextInput
                mode="outlined"
                label={t('apiTokens.secret')}
                value={secret}
                readOnly
                multiline
                selectTextOnFocus
                style={{ fontFamily: 'monospace' }}
            />
            <Button mode="contained" icon="content-copy" onPress={copySecret}>{t('apiTokens.copy')}</Button>
            <Button mode="text" onPress={resetCreateForm}>{t('apiTokens.done')}</Button>
        </View>
    );

    return (
        <Portal>
            <Modal
                visible={visible}
                onDismiss={onDismiss}
                contentContainerStyle={getScrollableModalFrameStyle({
                    backgroundColor: colors.elevation.level2,
                    isFullScreen: isSmallTablet,
                    width: '92%',
                    maxWidth: 680,
                    maxHeight: '90%',
                })}
            >
                <ScrollView contentContainerStyle={getScrollableModalContentStyle({ horizontalPadding: 20, verticalPadding: 20 })}>
                    {isSmallTablet && <BackButton onPress={onDismiss} />}
                    <View style={{ flexDirection: 'row', alignItems: 'center', marginBottom: 8 }}>
                        <View style={{ flex: 1 }}>
                            <Text variant="headlineSmall">{t('apiTokens.title')}</Text>
                            <Text variant="bodyMedium" style={{ color: colors.onSurfaceVariant }}>
                                {t('apiTokens.forUser', { name: user?.name || '' })}
                            </Text>
                        </View>
                        {!isSmallTablet && <IconButton icon="close" accessibilityLabel={t('apiTokens.close')} onPress={onDismiss} />}
                    </View>
                    <Divider style={{ marginBottom: 16 }} />
                    {screen === 'list' && renderList()}
                    {screen === 'create' && renderCreate()}
                    {screen === 'secret' && renderSecret()}
                </ScrollView>
            </Modal>

            <SimpleDialog
                visible={!!tokenToRevoke}
                onDismiss={() => {
                    if (!revokeMutation.isPending) {
                        setTokenToRevoke(null);
                        setRevokePassword('');
                    }
                }}
                title={t('apiTokens.revokeTitle')}
                content={
                    <View style={{ gap: 12 }}>
                        <Text>{t('apiTokens.revokeBody', { name: tokenToRevoke?.name || '' })}</Text>
                        <TextInput
                            mode="outlined"
                            label={t('apiTokens.currentPassword')}
                            value={revokePassword}
                            onChangeText={setRevokePassword}
                            secureTextEntry
                            autoComplete="current-password"
                        />
                        {!!error && <Text accessibilityRole="alert" style={{ color: colors.error }}>{error}</Text>}
                    </View>
                }
                actions={
                    <>
                        <Button disabled={revokeMutation.isPending} onPress={() => setTokenToRevoke(null)}>{t('apiTokens.cancel')}</Button>
                        <Button
                            mode="contained"
                            buttonColor={colors.error}
                            textColor={colors.onError}
                            loading={revokeMutation.isPending}
                            disabled={revokeMutation.isPending || !revokePassword}
                            onPress={() => revokeMutation.mutate()}
                        >
                            {t('apiTokens.revoke')}
                        </Button>
                    </>
                }
            />
        </Portal>
    );
};

export default ApiTokenManagerModal;
