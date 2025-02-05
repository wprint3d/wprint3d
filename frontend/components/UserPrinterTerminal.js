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

export default function UserPrinterTerminal({ isLoadingPrinter = true, printerId = null, lastMessage, isSmallTablet = false }) {
    const { enqueueSnackbar } = useSnackbar();
    const { bottom }          = useSafeAreaInsets();

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

    const [ log,            setLog           ] = useState([]);
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
                    action:  { label: 'Got it' }
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
                    The serial driver stopped responding. Please try to restart the host and try again.
                    {'\n\n'}
                    Check for EMI sources and make sure that all USB cables are properly connected and secured to the host.
                    {'\n\n'}
                    If the issue persists, please <Text
                        onPress={() => Linking.openURL('https://github.com/wprint3d/wprint3d/issues/new?template=Blank+issue')}
                        style={{ textDecorationLine: 'underline' }}
                    >
                        create an issue
                    </Text>.
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

        let nextLog = [];

        terminalLastLog.data.data.split('\n').forEach(splitLine => {
            if (!splitLine.length) { return; }

            const [ date, line ] = splitLine.split(': ');

            if (line.indexOf('> ') > -1) {
                setInputLines(prevInputLines => prevInputLines + 1);
            } else {
                setInputLines(0);
            }

            if (isMessageBlocked(line)) { return; }

            nextLog.push(
                buildLogLine({
                    key:  uuid.v4(),
                    date: date,
                    line: line
                })
            );
        });

        setLog(
            nextLog.filter(element => element !== null)
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

        console.debug('UserPrinterTerminal: lastMessage:', lastMessage);

        let nextLog = [];

        const command = lastMessage?.command;

        if (!command || !command.length) { return; }

        console.debug('UserPrinterTerminal: command:', command);

        command.split('\n').forEach(line => {
            if (
                !line.trim().length
                ||
                isMessageBlocked(line)
            ) { return; }

            nextLog.push(
                buildLogLine({
                    key:  uuid.v4(),
                    date: lastMessage.dateString,
                    line: line
                })
            );
        });

        console.debug('setLog:', nextLog);

        setLog(prevLog => {
            if (terminalMaxLines === 0) { return []; }

            let newLog = [...prevLog, ...nextLog];

            while (newLog.length > terminalMaxLines) { newLog.shift(); }

            return newLog;
        });

        if (!autoScrollToBottom) { return; }

        if (typeof terminalView.current === 'undefined' || !terminalView.current) {
            console.warn('terminalView is undefined.');

            return;
        }

        terminalView.current.scrollToEnd({ animated: true });
    }, [ lastMessage ]);

    let loaderMessage = null;

    if (isLoadingPrinter) {
        loaderMessage = 'Getting selected printer';
    } else if (terminalLastLog.isFetching) {
        loaderMessage = 'Downloading last console log';
    } else if (!terminalMaxLinesConfig.isFetched) {
        loaderMessage = 'Getting terminal configuration';
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
                                    {log.length > 0
                                        ? log
                                        : buildLogLine({
                                            key:  null,
                                            line: 'Nothing here!'
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
                label="Enter a custom command"
                placeholder="Custom command"
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
                        title="Auto-scroll to bottom"
                        icon="format-vertical-align-bottom"
                        onPress={() => setAutoScrollToBottom(!autoScrollToBottom)}
                        disabled={!autoScrollToBottom}
                        loading={autoScrollToBottom === null}
                    />

                    <AppbarActionWithTooltip
                        title="Show sensors updates"
                        icon="update"
                        onPress={() => setShowSensorsUpdates(!showSensorsUpdates)}
                        disabled={!showSensorsUpdates}
                        loading={showSensorsUpdates === null}
                    />

                    <AppbarActionWithTooltip
                        title="Show input commands"
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