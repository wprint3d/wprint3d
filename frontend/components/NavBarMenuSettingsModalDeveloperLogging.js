import { useMutation, useQuery } from "@tanstack/react-query"
import { View } from "react-native"
import { Button, Checkbox, Chip, DataTable, FAB, IconButton, Portal, Text, Tooltip, useTheme } from "react-native-paper"

import API from "../includes/API"
import UserPaneLoadingIndicator from "./UserPaneLoadingIndicator"
import { useEffect, useState } from "react"
import SimpleDialog from "./SimpleDialog"
import FormattedLogView from "./FormattedLogView"
import * as FileSystem from 'expo-file-system';
import { Platform } from 'react-native';
import dayjs from "dayjs"
import NavBarMenuSettingsModalPlaceholderItem from "./NavBarMenuSettingsModalPlaceholderItem"

const NavBarMenuSettingsModalDeveloperLogging = ({ isSmallTablet, isSmallLaptop, enqueueSnackbar }) => {
    const theme = useTheme();

    const [ logs, setLogs ] = useState({});

    const [ previewLog, setPreviewLog ] = useState(null);
    const [ isFabOpen,  setIsFabOpen  ] = useState(false);
    const [ isFetching, setIsFetching ] = useState(false);
    const [ isDeleting, setIsDeleting ] = useState(false);
    const [ sorting,    setSorting    ] = useState('ascending');

    const [ previewWrap, setPreviewWrap ] = useState(true);

    const selectedLogs = Object.keys(logs).filter(log => logs[log]);

    const logIndexQuery = useQuery({
        queryKey: ['logIndex'],
        queryFn:  () => API.get('/developer/logs')
    });

    const deleteLogMutation = useMutation({
        mutationFn:  () => {
            if (selectedLogs.length === 0) {
                return API.delete('/developer/logs');
            }

            return API.delete('/developer/logs', { files: selectedLogs });
        },
        onMutate:   () => {
            console.debug('NavBarMenuSettingsModalDeveloperLogging: deleteLogMutation: onMutate');
        },
        onSuccess:  (data) => {
            console.debug('NavBarMenuSettingsModalDeveloperLogging: deleteLogMutation: onSuccess', data);

            enqueueSnackbar({
                message: (selectedLogs.length === 0 ? 'All logs have been cleared.' : `${selectedLogs.length} log${selectedLogs.length === 1 ? ' has' : 's have'} been deleted.`),
                variant: 'success',
                action:  { label: 'Dismiss' }
            });

            logIndexQuery.refetch();
        },
        onError:    (error) => {
            console.error('NavBarMenuSettingsModalDeveloperLogging: deleteLogMutation: onError', error);

            enqueueSnackbar({
                message: `Couldn't clear logs: ${(error.response?.data?.message || error.message).toLowerCase()}`,
                variant: 'error',
                action:  { label: 'Dismiss' }
            });
        }
    });

    const handleLogClick = (log) => {
        console.debug('NavBarMenuSettingsModalDeveloperLogging: handleLogClick', log);

        let nextLogs = { ...logs };
            nextLogs[log] = !nextLogs[log];

        setLogs(nextLogs);
    };

    const handleLogPreview = (log) => {
        console.debug('NavBarMenuSettingsModalDeveloperLogging: handleLogPreview', log);

        setPreviewLog(log);
    };

    const downloadFile = async (data, fileName) => {
        if (Platform.OS === 'web') {
            const blob = new Blob([data]);
            const url = window.URL.createObjectURL(blob);
            const link = document.createElement('a');

            link.href = url;
            link.setAttribute('download', fileName);

            document.body.appendChild(link);

            link.click();
            link.remove();
        } else {
            const fileUri = FileSystem.documentDirectory + fileName;

            await FileSystem.writeAsStringAsync(fileUri, data, {
                encoding: FileSystem.EncodingType.Base64
            });

            console.debug('File downloaded to', fileUri);
        }
    };

    const fetchFileOrFail = async (url, data = {}) => {
        setIsFetching(true);

        try {
            const response = await API.get(url, data, { responseType: 'arraybuffer' });

            setIsFetching(false);

            return response.data;
        } catch (error) {
            console.error('Error downloading log:', error);

            enqueueSnackbar({
                message: `Couldn't download file: ${(error.response?.data?.message || error.message).toLowerCase()}`,
                variant: 'error',
                action:  { label: 'Dismiss' }
            });
        }

        setIsFetching(false);

        return null;
    };

    const handleLogDownload = async (log) => {
        console.debug('NavBarMenuSettingsModalDeveloperLogging: handleLogDownload', log);

        const file = await fetchFileOrFail(`/developer/logs/${log}`);

        if (file === null) { return; }

        downloadFile(file, log);
    };

    const handleBatchDownload = async () => {
        console.debug('NavBarMenuSettingsModalDeveloperLogging: handleBatchDownload', logs);

        const file = await fetchFileOrFail('/developer/logs/zip', { files: selectedLogs });

        if (file === null) { return; }

        downloadFile(file, `logs_${dayjs().format('YYYY-MM-DD_HH-mm-ss')}.zip`);
    };

    const handleLogDelete = async () => {
        console.debug('NavBarMenuSettingsModalDeveloperLogging: handleLogDelete');

        deleteLogMutation.mutate();

        setIsFabOpen(false);
        setIsDeleting(false);
    };

    const handleSelectedLogDelete = (log) => {
        console.debug('Delete');

        const nextLogs = { ...logs };

        Object.keys(nextLogs).forEach(key => {
            nextLogs[key] = false;
        });

        nextLogs[log] = true;

        setLogs(nextLogs);
        setIsDeleting(true);
    };

    useEffect(() => {
        console.debug('NavBarMenuSettingsModalDeveloperLogging: logIndexQuery', logIndexQuery);

        if (!logIndexQuery.isSuccess) { return; }

        let nextLogs = {};

        logIndexQuery.data?.data.forEach(log => {
            nextLogs[log] = false;
        });

        setLogs(
            Object.keys(nextLogs).sort((a, b) => {
                if (sorting === 'ascending') {
                    return a.localeCompare(b);
                }

                return b.localeCompare(a);
            }).reduce((acc, key) => {
                acc[key] = nextLogs[key];

                return acc;
            }, {})
        );
    }, [logIndexQuery.data, sorting]);

    if (logIndexQuery.isFetching) {
        return <UserPaneLoadingIndicator message={'Loading logs index...'} />;
    }

    if (logIndexQuery.isError) {
        return (
            <NavBarMenuSettingsModalPlaceholderItem
                icon="alert-circle-outline"
                message={
                    'An error occurred while loading the logs index: ' +
                    '\n\n' +
                    (logIndexQuery?.error?.response?.data?.message || logIndexQuery?.error?.message)
                }
            />
        );
    }

    return (
        <View style={{ maxHeight: '100%' }}>
            <Text style={{ textAlign: 'center', paddingVertical: 24 }}>
                From this page you can view, download and clear your server logs.
            </Text>

            <DataTable style={{ flexGrow: 1, overflow: 'scroll' }}>
                <DataTable.Header>
                    <DataTable.Title
                        textStyle={{ maxHeight: 48 }}
                        style={{ flex: 'none', minWidth: 45, paddingVertical: 4, marginLeft: 4 }}
                        onPress={() => {
                            console.debug('Select all');

                            const hasSelection = selectedLogs.length === Object.keys(logs).length;

                            setLogs(
                                Object.keys(logs).reduce((acc, key) => {
                                    acc[key] = !hasSelection;

                                    return acc;
                                }, {})
                            );
                        }}
                    >
                        <Checkbox status={
                            selectedLogs.length === Object.keys(logs).length
                                ? 'checked'
                                : 'unchecked'
                        } />
                    </DataTable.Title>
                    <DataTable.Title
                        style={{ flexGrow: 1, paddingHorizontal: 4 }}
                        sortDirection={sorting}
                        onPress={() => {
                            console.debug('Sort by file name');

                            setSorting(sorting === 'ascending' ? 'descending' : 'ascending');
                        }}
                    >
                        Path
                    </DataTable.Title>
                    <DataTable.Title style={{ flex: 'none', minWidth: 160 }}>
                        Actions
                    </DataTable.Title>
                </DataTable.Header>

                {Object.keys(logs).map((log, index) => (
                    <DataTable.Row key={index}>
                        <DataTable.Cell
                            style={{ flex: 'none', minWidth: 45, justifyContent: 'center' }}
                            onPress={() => handleLogClick(log)}
                        >
                            <Checkbox status={logs[log] ? 'checked' : 'unchecked'} />
                        </DataTable.Cell>
                        <DataTable.Cell style={{ flexGrow: 1, paddingHorizontal: 4 }}>
                            {log}
                        </DataTable.Cell>
                        <DataTable.Cell style={{ flex: 'none', minWidth: 160 }}>
                            <Tooltip title="Preview">
                                <IconButton icon="eye"      onPress={() => handleLogPreview(log)}   />
                            </Tooltip>
                            <Tooltip title="Download">
                                <IconButton icon="download" onPress={() => handleLogDownload(log)}  />
                            </Tooltip>
                            <Tooltip title="Delete">
                                <IconButton icon="delete"   onPress={() => handleSelectedLogDelete(log)} />
                            </Tooltip>
                        </DataTable.Cell>
                    </DataTable.Row>
                ))}
            </DataTable>

            <Portal>
                <FAB.Group
                    open={isFabOpen}
                    visible={!isFetching}
                    icon={isFabOpen ? 'close' : 'plus'}
                    label='Options'
                    actions={[
                        {
                            icon: 'download',
                            style: {
                                backgroundColor:
                                    theme.dark
                                        ? theme.colors.onPrimary
                                        : theme.colors.primary
                            },
                            label: (() => {
                                if (selectedLogs.length === 0) {
                                    return 'Download all';
                                }

                                return `Download ${selectedLogs.length} logs`;
                            })(),
                            onPress: () => {
                                console.debug('Download');

                                setIsFabOpen(false);

                                if (selectedLogs.length === 1) {
                                    handleLogDownload(selectedLogs[0]);

                                    return;
                                }

                                handleBatchDownload();
                            }
                        },
                        {
                            icon: 'delete',
                            style: {
                                backgroundColor:
                                    theme.dark
                                        ? theme.colors.onPrimary
                                        : theme.colors.primary
                            },
                            label: (() => {
                                if (selectedLogs.length === 0) {
                                    return 'Clear all';
                                }

                                return `Delete ${selectedLogs.length} logs`;
                            })(),
                            onPress: () => {
                                console.debug('Clear');

                                setIsDeleting(true);
                            }
                        },
                    ]}
                    onStateChange={({ open }) => {
                        console.debug('FAB.Group: onStateChange', open);

                        setIsFabOpen(open);
                    }}
                    backdropColor={
                        theme.dark
                            ? "rgba(0, 0, 0, 0.5)"
                            : "rgba(190, 190, 190, 0.5)"
                    }
                    onPress={() => setIsFabOpen(!isFabOpen)}
                    style={{ padding: isSmallTablet ? 16 : 64 }}
                />
            </Portal>

            <SimpleDialog
                visible={previewLog !== null}
                title={`Previewing log "${previewLog}"`}
                content={<FormattedLogView fileName={previewLog} wrap={previewWrap} />}
                style={{ maxWidth: 1000, width: '95%', maxHeight: '95%' }}
                onDismiss={() => setPreviewLog(null)}
                actions={
                    <View style={{
                        width: '100%',
                        flexDirection: 'row',
                        justifyContent: 'space-between',
                        alignItems: 'center'
                    }}>
                        <Checkbox.Item
                            testID="wrap-text-checkbox"
                            label="Wrap"
                            status={previewWrap ? 'checked' : 'unchecked'}
                            position="leading"
                            labelVariant="bodySmall"
                            style={{ maxHeight: 24, padding: 0 }}
                            onPress={() => setPreviewWrap(!previewWrap)}
                        />

                        <View style={{ flexDirection: 'row', gap: 8 }}>
                            <Button mode="contained" onPress={() => {
                                console.debug('Download');

                                handleLogDownload(previewLog);
                            }} icon="download">
                                Download
                            </Button>
                            <Button mode="text" onPress={() => setPreviewLog(null)}>
                                Close
                            </Button>
                        </View>
                    </View>
                }
            />

            <SimpleDialog
                visible={isDeleting}
                title={selectedLogs.length === 0 ? 'Clear all logs' : `Delete ${selectedLogs.length} file${selectedLogs.length === 1 ? '' : 's'}`}
                content={
                    <Text>
                        {selectedLogs.length === 0
                            ? 'Are you sure you want to clear all logs?'
                            : `Are you sure you want to delete ${selectedLogs.length} file${selectedLogs.length === 1 ? '' : 's'}?`
                        }
                    </Text>
                }
                onDismiss={() => setIsDeleting(false)}
                actions={
                    <View style={{ flexDirection: 'row', gap: 8 }}>
                        <Button
                            mode="contained"
                            onPress={() => {
                                console.debug('Clear');

                                handleLogDelete();
                            }}
                            icon="delete"
                            style={{ backgroundColor: theme.colors.error }}
                            textColor={theme.colors.white}
                        >
                            Delete
                        </Button>
                        <Button mode="text" onPress={() => setIsDeleting(false)}>
                            Cancel
                        </Button>
                    </View>
                }
            />
        </View>
    )
}

export default NavBarMenuSettingsModalDeveloperLogging