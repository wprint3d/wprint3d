import { useEffect, useRef, useState } from "react";

import { ActivityIndicator, Appbar, Checkbox, Divider, FAB, Icon, Text, TextInput, Tooltip, useTheme } from "react-native-paper";

import { useEcho } from "../hooks/useEcho";
import { ScrollView, StyleSheet, View } from "react-native";
import { useMutation, useQuery } from "@tanstack/react-query";

import { useSafeAreaInsets } from 'react-native-safe-area-context';

import UserPaneLoadingIndicator from './UserPaneLoadingIndicator';

import API from "../includes/API";
import AppbarActionWithTooltip from "./AppbarActionWithTooltip";

import { useCache } from "../hooks/useCache";

import uuid from 'react-native-uuid';
import { useSnackbar } from "react-native-paper-snackbar-stack";

export default function UserPrinterTerminal({ selectedPrinter }) {
    const { enqueueSnackbar } = useSnackbar();
    const { bottom }          = useSafeAreaInsets();

    const BOTTOM_APPBAR_HEIGHT = 48;

    const terminalView = useRef();

    const echo  = useEcho();
    const cache = useCache();

    const { colors } = useTheme();

    const [ log,            setLog           ] = useState([]);
    const [ customCommand,  setCustomCommand ] = useState('');

    const [ autoScrollToBottom, _setAutoScrollToBottom ] = useState(null);
    const [ showSensorsUpdates, _setShowSensorsUpdates ] = useState(null);
    const [ showInputCommands,  _setShowInputCommands  ] = useState(null);

    console.log('autoScrollToBottom:', autoScrollToBottom);
    console.log('showSensorsUpdates:', showSensorsUpdates);
    console.log('showInputCommands:',  showInputCommands);

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

    console.debug('log:', log);

    const terminalLastLog = useQuery({
        queryKey: ['terminalLastLog'],
        queryFn:  () => API.get('/user/printer/selected/console')
    });

    console.debug('terminalLastLog:', terminalLastLog);

    const terminalMaxLinesConfig = useQuery({
        enabled:  terminalLastLog.isFetched,
        queryKey: ['terminalMaxLinesConfig'],
        queryFn:  () => API.get('/config/terminalMaxLines')
    });

    console.debug('terminalMaxLines:', terminalMaxLinesConfig);

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

    console.debug('queueCommandMutation:', queueCommandMutation);

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
            <Text
                key={key}
                style={{ display: 'flex', marginTop: 4 }}
                testID={key}
            >
                <View style={[ styles.terminalCommandKind, {backgroundColor: tagColor } ]} />

                {date ? `${date}: ` : ''}{line.trim()}
            </Text>
        );
    };

    useEffect(() => {
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
        if (echo === null) {
            console.debug('UserPrinterTerminal: the echo client isn\'t available yet.');

            return;
        }

        if (!terminalLastLog.isFetched) {
            console.debug('UserPrinterTerminal: terminalLastLog isn\'t fetched yet.', terminalLastLog);

            return;
        }

        if (!terminalMaxLinesConfig.isFetched) {
            console.debug('UserPrinterTerminal: terminalMaxLinesConfig isn\'t fetched yet.', terminalMaxLinesConfig);

            return;
        }

        if (!selectedPrinter.isSuccess) {
            console.error('UserPrinterTerminal: couldn\'t initialize:', selectedPrinter.error);

            return;
        }

        const channelName       = `console.${selectedPrinter.data.data}`,
              eventName         = 'PrinterTerminalUpdated',
              terminalMaxLines  = terminalMaxLinesConfig.data.data;

        console.debug('UserPrinterTerminal: private: listen: ', channelName);

        const channel = echo.private(channelName);

        channel.listen(eventName, event => {
            console.debug('Terminal:', event);

            let nextLog = [];

            event.command.split('\n').forEach(line => {
                if (
                    !line.trim().length
                    ||
                    isMessageBlocked(line)
                ) { return; }

                nextLog.push(
                    buildLogLine({
                        key:  uuid.v4(),
                        date: event.dateString,
                        line: line
                    })
                );
            });

            console.debug('setLog:', nextLog);

            setLog(prevLog => {
                let newLog = [...prevLog, ...nextLog];

                while (newLog.length > terminalMaxLines) { newLog.shift(); }

                return newLog;
            });

            if (!autoScrollToBottom) { return true; }

            if (typeof terminalView.current === 'undefined') {
                console.warn('terminalView is undefined.');

                return true;
            }

            terminalView.current.scrollToEnd({ animated: true });
        });

        return (() => {
            console.debug('channel:', channel);

            if (channel === null) { return; }

            channel.stopListening(eventName);
        });
    }, [ echo, terminalMaxLinesConfig ]);

    let loaderMessage = null;

    if (echo === null) {
        loaderMessage = 'Creating websocket client';
    } else if (!selectedPrinter.isFetched) {
        loaderMessage = 'Getting selected printer';
    } else if (!terminalLastLog.isFetched) {
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
                                message={loaderMessage + '...'}
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
                    backgroundColor: colors.elevation.level1
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