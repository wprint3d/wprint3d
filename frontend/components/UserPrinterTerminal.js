import { useEffect, useRef, useState } from "react";

import { ActivityIndicator, Appbar, Checkbox, Divider, FAB, Icon, Text, TextInput, Tooltip, useTheme } from "react-native-paper";

import { Linking, ScrollView, StyleSheet, View } from "react-native";
import { useMutation, useQuery } from "@tanstack/react-query";

import { useSafeAreaInsets } from 'react-native-safe-area-context';

import UserPaneLoadingIndicator from './UserPaneLoadingIndicator';

import API from "../includes/API";
import AppbarActionWithTooltip from "./AppbarActionWithTooltip";

import { useCache } from "../hooks/useCache";

import uuid from 'react-native-uuid';

import { useSnackbar } from "react-native-paper-snackbar-stack";
import { useTerminalMessages } from "../hooks/useTerminalMessages";
import {
    filterTerminalEntries,
    mergeTerminalEntries,
    parseTerminalEvent,
    parseTerminalHistory,
} from "../utils/terminalLog";
import { useLocalization } from "../includes/LocalizationProvider";

export default function UserPrinterTerminal({ isLoadingPrinter = true, printerId = null, isSmallTablet = false }) {
    const { enqueueSnackbar } = useSnackbar();
    const { t } = useLocalization();
    const { bottom }          = useSafeAreaInsets();

    const queuedTerminalMessages = useTerminalMessages({ printerId });
    const lastProcessedMessageId = useRef(0);

    const BOTTOM_APPBAR_HEIGHT_BASE = 48;
    const BOTTOM_APPBAR_HEIGHT = (
        BOTTOM_APPBAR_HEIGHT_BASE + (
            isSmallTablet
                ? 8
                : 0
        )
    );

    const terminalView = useRef();

    const cache = useCache();

    const { colors } = useTheme();

    const [ logEntries,     setLogEntries    ] = useState([]);
    const [ customCommand,  setCustomCommand ] = useState('');

    const [ autoScrollToBottom,     _setAutoScrollToBottom   ] = useState(null);
    const [ showSensorsUpdates,     _setShowSensorsUpdates   ] = useState(null);
    const [ showInputCommands,      _setShowInputCommands    ] = useState(null);
    const [ inputLines,             setInputLines            ] = useState(0);
    const [ isSerialDriverFailing,  setIsSerialDriverFailing ] = useState(false);
    const [ terminalMaxLines,       setTerminalMaxLines      ] = useState(0);

    // This is the maximum number of input lines matched before raising a serial error.
    const SERIAL_ERROR_INPUT_THRESHOLD = 10;

    useEffect(() => {
        console.debug('autoScrollToBottom:', autoScrollToBottom);
    }, [ autoScrollToBottom ]);

    useEffect(() => {
        console.debug('showSensorsUpdates:', showSensorsUpdates);
    }, [ showSensorsUpdates ]);

    useEffect(() => {
        console.debug('showInputCommands:',  showInputCommands);
    }, [ showInputCommands ]);

    useEffect(() => {
        lastProcessedMessageId.current = 0;
    }, [ printerId ]);

    const setAutoScrollToBottom = async newValue => {
        await cache.set('autoScrollToBottom', newValue);

        return _setAutoScrollToBottom(newValue);
    };

    const setShowSensorsUpdates = async newValue => {
        await cache.set('showSensorsUpdates', newValue);

        return _setShowSensorsUpdates(newValue);
    };

    const setShowInputCommands = async newValue => {
        await cache.set('showInputCommands', newValue);

        return _setShowInputCommands(newValue);
    };

    // console.debug('log:', log);

    const terminalLastLog = useQuery({
        queryKey: ['terminalLastLog'],
        queryFn:  () => API.get('/user/printer/selected/console')
    });

    const terminalMaxLinesConfig = useQuery({
        enabled:  terminalLastLog.isFetched,
        queryKey: ['terminalMaxLinesConfig'],
        queryFn:  () => API.get('/config/terminalMaxLines')
    });

    const queueCommandMutation = useMutation({
        mutationKey: ['queueCommandMutation'],
        mutationFn:  command => API.post(`/user/printer/selected/terminal/queue/command`, { command: command }),
        onSuccess:   () => setCustomCommand(''),
        onError:     (
            error => {
                enqueueSnackbar({
                    message: error.response.data.message,
                    variant: 'error',
                    action:  { label: t("notifications.gotIt") }
                });
            }
        )
    });

    const handleCommandQueueing = () => queueCommandMutation.mutate(customCommand);

    const isMessageBlocked = line => {
        if (
            !showSensorsUpdates
            &&
            (
                line.indexOf('> M105') > -1
                ||
                line.indexOf('ok T:')  > -1
            )
        ) { return true; }

        if (
            !showInputCommands
            &&
            line.indexOf('> ') > -1
        ) { return true; }

        return false;
    }

    useEffect(() => {
        if (
            autoScrollToBottom === null
            ||
            typeof terminalView.current === 'undefined'
        ) { return; }

        if (autoScrollToBottom === true) {
            terminalView.current.scrollToEnd({ animated: true });
        }
    }, [ autoScrollToBottom ]);

    useEffect(() => {
        if (showSensorsUpdates === null || showInputCommands === null) { return; }

        terminalLastLog.refetch();

        if (typeof terminalView.current === 'undefined') { return; }
    }, [ showSensorsUpdates, showInputCommands ]);

    useEffect(() => {
        [ 'autoScrollToBottom', 'showSensorsUpdates', 'showInputCommands' ].forEach(key => {
            (
                async () => {
                    const state = await cache.get(key, true);

                    switch (key) {
                        case 'autoScrollToBottom': _setAutoScrollToBottom(state); break;
                        case 'showSensorsUpdates': _setShowSensorsUpdates(state); break;
                        case 'showInputCommands':  _setShowInputCommands(state);  break;
                    }
                }
            )();
        });
    }, []);

    useEffect(() => {
        console.debug('queueCommandMutation:', queueCommandMutation);
    }, [ queueCommandMutation.isPending ]);

    useEffect(() => {
        if (inputLines < SERIAL_ERROR_INPUT_THRESHOLD) { return; }

        setIsSerialDriverFailing(true);
    }, [ inputLines ]);

    useEffect(() => {
        if (!isSerialDriverFailing) { return; }

        enqueueSnackbar({
            message: (
                <Text>
                    {t("printer.terminal.serialDriverError")}
                    {'\n\n'}
                    {t("printer.terminal.serialDriverCheckUsb")}
                    {'\n\n'}
                    <Text
                        onPress={() => Linking.openURL('https://github.com/wprint3d/wprint3d/issues/new?template=Blank+issue')}
                        style={{ textDecorationLine: 'underline' }}
                    >
                        {t("printer.terminal.serialDriverCreateIssue")}
                    </Text>
                </Text>
            ),
            variant: 'error'
        });
    }, [ isSerialDriverFailing ]);

    const buildLogLine = ({ key, date, line }) => {
        let tagColor = colors.secondary;

        if (line.indexOf('error') > -1) {
            tagColor = colors.error;
        } else if (line.indexOf('ok') > -1 || line.indexOf('ok T:') > -1) {
            tagColor = colors.success;
        } else if (line.indexOf('busy') > -1) {
            tagColor = colors.warning;
        }

        return (
            <Text key={key} style={{ display: 'flex', marginTop: 4 }}>
                <View style={[ styles.terminalCommandKind, {backgroundColor: tagColor } ]} />

                {date ? `${date}: ` : ''}{line.trim()}
            </Text>
        );
    };

    useEffect(() => {
        console.debug('terminalLastLog:', terminalLastLog);

        if (
            !terminalLastLog.isFetched
            ||
            !terminalLastLog.isSuccess
            ||
            !terminalLastLog.data.data
        ) { return; }

        let nextLogEntries = [];

        parseTerminalHistory(terminalLastLog.data.data).forEach(({ date, line }) => {
            if (!line.length) { return; }

            if (line.indexOf('> ') > -1) {
                setInputLines(prevInputLines => prevInputLines + 1);
            } else {
                setInputLines(0);
            }

            nextLogEntries.push({ date, line });
        });

        setLogEntries(
            filterTerminalEntries(nextLogEntries, isMessageBlocked).map(entry => ({
                ...entry,
                key: uuid.v4()
            }))
        );
    }, [ terminalLastLog.isFetching, terminalLastLog.isFetched ]);

    useEffect(() => {
        console.debug('terminalMaxLines:', terminalMaxLinesConfig);

        if (!terminalMaxLinesConfig.isFetched) { return; }

        setTerminalMaxLines(terminalMaxLinesConfig?.data?.data ?? 0);
    }, [ terminalMaxLinesConfig.data ]);

    useEffect(() => {
        if (!terminalLastLog.isFetched) {
            console.debug('UserPrinterTerminal: terminalLastLog isn\'t fetched yet.', terminalLastLog);

            return;
        }

        if (!terminalMaxLinesConfig.isFetched) {
            console.debug('UserPrinterTerminal: terminalMaxLinesConfig isn\'t fetched yet.', terminalMaxLinesConfig);

            return;
        }

        if (isLoadingPrinter) {
            console.error('UserPrinterTerminal: couldn\'t initialize: printerId is missing.');

            return;
        }

        console.debug('UserPrinterTerminal: queuedTerminalMessages:', queuedTerminalMessages);

        const unprocessedMessages = queuedTerminalMessages.filter(
            ({ id }) => id > lastProcessedMessageId.current
        );

        if (!unprocessedMessages.length) { return; }

        lastProcessedMessageId.current = unprocessedMessages[unprocessedMessages.length - 1].id;

        const nextLogEntries = filterTerminalEntries(
            unprocessedMessages.flatMap(({ event }) => parseTerminalEvent(event)),
            isMessageBlocked
        );

        if (!nextLogEntries.length) { return; }

        console.debug('setLogEntries:', nextLogEntries);

        setLogEntries(previousEntries => mergeTerminalEntries(
            previousEntries,
            nextLogEntries.map(entry => ({
                ...entry,
                key: uuid.v4()
            })),
            terminalMaxLines
        ));

        if (!autoScrollToBottom) { return; }

        if (typeof terminalView.current === 'undefined' || !terminalView.current) {
            console.warn('terminalView is undefined.');

            return;
        }

        terminalView.current.scrollToEnd({ animated: true });
    }, [
        autoScrollToBottom,
        isLoadingPrinter,
        queuedTerminalMessages,
        terminalLastLog.isFetched,
        terminalMaxLines,
        terminalMaxLinesConfig.isFetched
    ]);

    let loaderMessage = null;

    if (isLoadingPrinter) {
        loaderMessage = t("printer.terminal.loadingSelectedPrinter");
    } else if (terminalLastLog.isFetching) {
        loaderMessage = t("printer.terminal.downloadingConsoleLog");
    } else if (!terminalMaxLinesConfig.isFetched) {
        loaderMessage = t("printer.terminal.gettingTerminalConfig");
    }

    return (
        <View style={{
            display:         'flex',
            flexDirection:   'column',
            flexGrow:        1,
            backgroundColor: colors.elevation.level1
        }}>
            <View style={{
                position: 'relative',
                padding: 8,
                flexGrow: 1,
                flexShrink: 0,
                flexBasis: 'auto',
                backgroundColor: colors.elevation.level1
            }}>
                <View style={{
                    position: 'absolute',
                    padding: 4,
                    left: 0,
                    top: 0,
                    right: 0,
                    bottom: 0
                }}>
                    {
                        loaderMessage !== null
                            ? <UserPaneLoadingIndicator
                                message={loaderMessage}
                                style={{
                                    height:         '100%',
                                    alignSelf:      'center',
                                    justifyContent: 'center'
                                }}
                            />
                            : <ScrollView
                                ref={terminalView}
                                onContentSizeChange={() => {
                                    if (!autoScrollToBottom) { return; }

                                    terminalView.current.scrollToEnd({ animated: true });
                                }}
                            >
                                <Text style={{ width: '100%', whiteSpace: 'nowrap' }}>
                                    {logEntries.length > 0
                                        ? logEntries.map(({ key, date, line }) => buildLogLine({
                                            key: key,
                                            date: date,
                                            line: line
                                        }))
                                        : buildLogLine({
                                            key:  null,
                                            line: t("printer.terminal.nothingHere")
                                        })
                                    }
                                </Text>
                            </ScrollView>
                    }
                </View>
            </View>

            <TextInput
                style={{
                    backgroundColor:  colors.elevation.level1,
                    marginHorizontal: 8
                }}
                value={customCommand}
                onChangeText={customCommand => setCustomCommand(customCommand)}
                mode="outlined"
                label={t("printer.terminal.customCommandLabel")}
                placeholder={t("printer.terminal.customCommandPlaceholder")}
                right={
                    <TextInput.Icon
                        loading={queueCommandMutation.isPending}
                        icon="send"
                        onPress={() => handleCommandQueueing()}
                    />
                }
                disabled={queueCommandMutation.isPending}
                onKeyPress={
                    event => {
                        if (event.type === 'keydown' && event.key === 'Enter') {
                            handleCommandQueueing();
                        }
                    }
                }
            />

            <Appbar
                style={[ styles.bottom, {
                    height: BOTTOM_APPBAR_HEIGHT,
                    backgroundColor: colors.elevation.level1,
                    marginVertical: 4
                }]}
                safeAreaInsets={{ bottom }}
            >
                <View style={{ flexDirection: 'row' }}>
                    <AppbarActionWithTooltip
                        title={t("printer.terminal.autoScrollToBottom")}
                        icon="format-vertical-align-bottom"
                        onPress={() => setAutoScrollToBottom(!autoScrollToBottom)}
                        disabled={!autoScrollToBottom}
                        loading={autoScrollToBottom === null}
                    />

                    <AppbarActionWithTooltip
                        title={t("printer.terminal.showSensorsUpdates")}
                        icon="update"
                        onPress={() => setShowSensorsUpdates(!showSensorsUpdates)}
                        disabled={!showSensorsUpdates}
                        loading={showSensorsUpdates === null}
                    />

                    <AppbarActionWithTooltip
                        title={t("printer.terminal.showInputCommands")}
                        icon="console-line"
                        onPress={() => setShowInputCommands(!showInputCommands)}
                        disabled={!showInputCommands}
                        loading={showInputCommands === null}
                    />
                </View>
            </Appbar>
        </View>
    );
}

const styles = StyleSheet.create({
    terminalCommandKind: {
        paddingLeft: 4,
        marginRight: 4
    }
});
