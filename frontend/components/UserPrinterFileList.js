import { useQuery } from "@tanstack/react-query";

import { useEffect, useRef, useState } from "react";

import { Animated, View } from "react-native";

import { ActivityIndicator, Badge, Divider, Icon, IconButton, List, Text, useTheme } from "react-native-paper";

import UserPrinterFileListControls from "./UserPrinterFileListControls";

import API from "../includes/API";
import { useLocalization } from "../includes/LocalizationProvider";

function FileTransferListItem({ transfer, colors, t, onRetry, onCancel, onDismiss }) {
    const progress = useRef(new Animated.Value(transfer.progress || 0)).current;

    useEffect(() => {
        Animated.timing(progress, {
            toValue: transfer.progress || 0,
            duration: 220,
            useNativeDriver: false,
        }).start();
    }, [progress, transfer.progress]);

    const stateLabel = {
        queued: t("files.transferQueued"),
        uploading: t("files.transferUploading"),
        processing: t("files.transferProcessing"),
        transferring: t("files.transferImporting"),
        'starting-print': t("files.transferStartingPrint"),
        ready: t("files.transferReady"),
        complete: t("files.transferReady"),
        printing: t("files.transferPrinting"),
        error: t("files.transferError"),
        cancelled: t("files.transferCancelled"),
    }[transfer.state] || t("files.transferring");
    const isActive = ['queued', 'uploading', 'processing', 'transferring', 'starting-print'].includes(transfer.state);
    const canRetry = ['error', 'cancelled'].includes(transfer.state) && typeof onRetry === 'function';
    const canDismiss = ['ready', 'complete', 'printing', 'error', 'cancelled'].includes(transfer.state) && typeof onDismiss === 'function';

    return (
        <View
            testID="file-transfer-item"
            accessibilityLabel={`${transfer.name}, ${stateLabel}, ${Math.round(transfer.progress || 0)}%`}
            accessibilityLiveRegion="polite"
            style={{
                minHeight: 56,
                position: 'relative',
                overflow: 'hidden',
                justifyContent: 'center',
                backgroundColor: transfer.state === 'error' ? colors.errorContainer : colors.surfaceVariant,
                opacity: 0.86,
            }}
        >
            <Animated.View
                testID="file-transfer-progress-fill"
                pointerEvents="none"
                style={{
                    position: 'absolute',
                    top: 0,
                    bottom: 0,
                    left: 0,
                    width: progress.interpolate({ inputRange: [0, 100], outputRange: ['0%', '100%'] }),
                    backgroundColor: transfer.state === 'error' ? colors.error : colors.primaryContainer,
                    opacity: 0.72,
                }}
            />
            <View style={{ minHeight: 56, flexDirection: 'row', alignItems: 'center', paddingHorizontal: 14, gap: 12 }}>
                {['ready', 'complete', 'printing'].includes(transfer.state)
                    ? <Icon source={transfer.state === 'printing' ? 'printer-3d' : 'check-circle'} color={colors.primary} size={24} />
                    : ['error', 'cancelled'].includes(transfer.state)
                        ? <Icon source={transfer.state === 'error' ? 'alert-circle' : 'cancel'} color={colors.error} size={24} />
                        : <ActivityIndicator animating size={22} color={colors.primary} />}
                <View style={{ minWidth: 0, flexGrow: 1, flexShrink: 1 }}>
                    <Text numberOfLines={1} variant="bodyMedium" style={{ color: colors.onSurface, fontWeight: '600' }}>{transfer.name}</Text>
                    <Text numberOfLines={1} variant="labelSmall" style={{ color: colors.onSurfaceVariant }}>{stateLabel}</Text>
                </View>
                <Text variant="labelMedium" style={{ color: colors.onSurface }}>{Math.round(transfer.progress || 0)}%</Text>
                {canRetry && (
                    <IconButton
                        icon="refresh"
                        size={20}
                        accessibilityLabel={t("files.retryTransfer")}
                        onPress={onRetry}
                        style={{ width: 44, height: 44, margin: 0 }}
                    />
                )}
                {isActive && typeof onCancel === 'function' && (
                    <IconButton
                        icon="close"
                        size={20}
                        accessibilityLabel={t("files.cancelTransfer")}
                        onPress={onCancel}
                        style={{ width: 44, height: 44, margin: 0 }}
                    />
                )}
                {canDismiss && (
                    <IconButton
                        icon="close"
                        size={20}
                        accessibilityLabel={t("files.dismissTransfer")}
                        onPress={onDismiss}
                        style={{ width: 44, height: 44, margin: 0 }}
                    />
                )}
            </View>
        </View>
    );
}

export default function UserPrinterFileList({
    selectedFileName,
    setSelectedFileName,
    subDirectory,
    setSubDirectory,
    isCreatingFolder,
    setIsCreatingFolder,
    deleteDirectoryMutation,
    isParentBusy = false,
    pendingUploads = [],
    onRetryUpload,
    onCancelUpload,
    onDismissUpload,
}) {
    const { colors } = useTheme();
    const { t } = useLocalization();

    const sortingModesIcons = {
        NAME_ASCENDING:     'sort-alphabetical-ascending',
        NAME_DESCENDING:    'sort-alphabetical-descending',
        DATE_ASCENDING:     'sort-clock-ascending',
        DATE_DESCENDING:    'sort-clock-descending'
    };

    const sortingModesTitles = {
        NAME_ASCENDING:     t("files.nameAscending"),
        NAME_DESCENDING:    t("files.nameDescending"),
        DATE_ASCENDING:     t("files.dateAscending"),
        DATE_DESCENDING:    t("files.dateDescending")
    };

    const [ sortingMode,  setSortingMode  ] = useState(null);
    const [ directories,  setDirectories  ] = useState([]);
    const [ files,        setFiles        ] = useState([]);

    const appendSubDirectory = directory => setSubDirectory(`${subDirectory}/${directory}`);

    const sortingModes = useQuery({
        queryKey:   ['sortingModes'],
        queryFn:    () => API.get('/files/sortingModes')
    });

    const fileList = useQuery({
        enabled:    sortingModes.isSuccess,
        queryKey:   [ 'fileList', subDirectory, sortingMode ],
        queryFn:    () => API.get('/files', {
            subPath: subDirectory,
            sortBy:  sortingModes?.data?.data[sortingMode]
        }),
        enabled:    sortingMode !== null
    });

    const hostTransfers = useQuery({
        queryKey: ['fileTransfers'],
        queryFn: async () => [],
        initialData: [],
        enabled: false,
    });

    useEffect(() => setSelectedFileName(null), [ subDirectory ]);

    useEffect(() => {
        console.debug('sortingModes:', sortingModes);

        if (
            !sortingModes.isSuccess
            ||
            !sortingModes?.data?.data
            ||
            sortingMode !== null
        ) { return; }

        setSortingMode(
            Object.keys(sortingModes.data.data)[0]
        );
    }, [ sortingModes.data ]);

    useEffect(() => {
        console.debug('fileList:',      fileList);
        console.debug('directories:',   directories);
        console.debug('files:',         files);

        if (!fileList.isSuccess) return;

        setDirectories(fileList.data.data.directories);
        setFiles(fileList.data.data.files);
    }, [ fileList.data ]);

    useEffect(() => {
        console.debug('selectedFileName:', selectedFileName);
    }, [ selectedFileName ]);

    let components = [];
    const transfers = [
        ...pendingUploads,
        ...(hostTransfers.data || []),
    ];

    transfers.forEach(transfer => {
        components.push(
            <FileTransferListItem
                key={`transfer-${transfer.id}`}
                transfer={transfer}
                colors={colors}
                t={t}
                onRetry={transfer.onRetry || (onRetryUpload ? () => onRetryUpload(transfer.id) : undefined)}
                onCancel={transfer.onCancel || (onCancelUpload ? () => onCancelUpload(transfer.id) : undefined)}
                onDismiss={transfer.onDismiss || (onDismissUpload ? () => onDismissUpload(transfer.id) : undefined)}
            />
        );
        components.push(<Divider key={`transfer-divider-${transfer.id}`} />);
    });
    const formatPrintCount = (count) => (
        count === 1
            ? t("files.printCountOne", { count })
            : t("files.printCountOther", { count })
    );

    const isBusy = fileList.isFetching || sortingModes.isFetching || isParentBusy;

    if (
        isBusy
        &&
        (directories.length == 0 || files.length == 0)
    ) {
        components.push(
            <List.Item
                key={components.length}
                title={
                    (
                        fileList.isFetching
                            ? t("files.loadingFilesList")
                            : t("files.gettingSortingModes")
                    ) + '...'
                }
                left={() => <ActivityIndicator animating={true} style={{ paddingLeft: 10 }} />}
            />
        );
    } else {
        directories.forEach(directory => {
            const baseName = directory.replace(subDirectory.substr(1) + '/', '');

            components.push(
                <List.Item
                    key={components.length}
                    onPress={() => appendSubDirectory(baseName)}
                    title={baseName}
                    titleStyle={{ fontWeight: 'bold' }}
                    style={{ paddingVertical: 4 }}
                    left={props => <List.Icon {...props} icon="folder" />}
                    disabled={isBusy}
                />
            );

            components.push(<Divider key={components.length} />);
        });

        files.forEach(file => {
            const name      = file?.name,
                  baseName  = name.replace(subDirectory.substr(1) + '/', '');

            components.push(
                <List.Item
                    key={components.length}
                    onPress={() => setSelectedFileName(baseName)}
                    title={baseName}
                    style={baseName == selectedFileName && { backgroundColor: colors.primary }}
                    titleStyle={baseName == selectedFileName && { color: colors.onPrimary }}
                    right={() => {
                        if (!file?.prints) {
                            return (
                                <Badge theme={{ colors: { onError: '#FFFFFF' } }} style={{ paddingHorizontal: 8 }}>
                                    {t("files.newBadge")}
                                </Badge>
                            );
                        }

                        if (baseName == selectedFileName) {
                            return (
                                <Badge
                                    theme={{
                                        colors: {
                                            error:   colors.onPrimary,
                                            onError: colors.primary
                                        }
                                    }}
                                    style={{ paddingHorizontal: 8 }}
                                >
                                    {formatPrintCount(file.prints)}
                                </Badge>
                            );
                        }

                        return (
                            <Badge
                                theme={{
                                    colors: {
                                        error:   colors.elevation.level2,
                                        onError: colors.primary
                                    }
                                }}
                                style={{ paddingHorizontal: 8 }}
                            >
                                {formatPrintCount(file.prints)}
                            </Badge>
                        );
                    }}
                    disabled={isBusy}
                />
            );

            components.push(<Divider key={components.length} />);
        });

        components.pop(); // removes the last divider
    }

    if (components.length == 0) {
        components.push(
                <List.Item
                    key={components.length}
                    title={
                        subDirectory.length == 0
                            ? <Text>{t("files.emptyRoot")}</Text>
                            : <Text>
                                {t("files.emptySubdirectoryPrefix")}
                                <Text onPress={() => deleteDirectoryMutation.mutate(subDirectory)} style={{ textDecoration: 'underline' }}>
                                    {t("files.deleteThisFolder")}
                                </Text>
                                {t("files.emptySubdirectorySuffix")}
                            </Text>
                }
                disabled={true}
            />
        );
    }

    return (
        <View style={{ paddingTop: 10, overflow: 'auto' }}>
            <UserPrinterFileListControls
                subDirectory={subDirectory}
                setSubDirectory={setSubDirectory}
                isLoading={fileList.isFetching}
                sortingMode={sortingMode}
                setSortingMode={setSortingMode}
                sortingModes={sortingModes}
                sortingModesIcons={sortingModesIcons}
                sortingModesTitles={sortingModesTitles}
                isCreatingFolder={isCreatingFolder}
                setIsCreatingFolder={setIsCreatingFolder}
            />

            <List.Section style={{
                borderRadius:    5,
                borderWidth:     1,
                borderColor:     colors.outlineVariant
            }}>
                {components}
            </List.Section>
        </View>
    );
}
