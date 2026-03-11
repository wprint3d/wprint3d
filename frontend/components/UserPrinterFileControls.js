import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { useEffect, useRef, useState } from "react";

import { Animated, View } from "react-native";

import { Button, Icon, SegmentedButtons, Text, TextInput, TouchableRipple, useTheme } from "react-native-paper";

import { useSnackbar } from "react-native-paper-snackbar-stack";

import { useEcho } from "../hooks/useEcho";

import API from "../includes/API";

import UserPrinterFileControlsOptions from "./UserPrinterFileControlsOptions";
import UserPrinterFileList            from "./UserPrinterFileList";
import TextBold                       from "./TextBold";
import SmallButton                    from "./SmallButton";
import SimpleDialog                   from "./SimpleDialog";
import UserPrinterFileControlsUploader from "./UserPrinterFileControlsUploader";
import UserPaneLoadingIndicator from "./UserPaneLoadingIndicator";

export default function UserPrinterFileControls({ printerId, connectionStatus, printStatus }) {
    const echo = useEcho();

    const { enqueueSnackbar } = useSnackbar();

    const { colors } = useTheme();

    const [ subDirectory,       setSubDirectory       ] = useState('');
    const [ selectedFileName,   setSelectedFileName   ] = useState(null);
    const [ isRequestingStart,  setIsRequestingStart  ] = useState(false);
    const [ isRequestingStop,   setIsRequestingStop   ] = useState(false);
    const [ isRequestingDelete, setIsRequestingDelete ] = useState(false);
    const [ isRequestingRename, setIsRequestingRename ] = useState(false);
    const [ isCreatingFolder,   setIsCreatingFolder   ] = useState(false);

    const [ newFileName,   setNewFileName   ] = useState(selectedFileName);
    const [ newFolderName, setNewFolderName ] = useState('');

    const [ isWaitingForNewStatus, setIsWaitingForNewStatus ] = useState(true);

    const isPrinting = !!connectionStatus?.isPrinting;
    const isPaused   = !!connectionStatus?.isPaused;

    const getParentTree     = () => subDirectory.split('/').slice(0, -1).join('/');
    const getCurrentFolder  = () => subDirectory.split('/').pop();

    const queryClient = useQueryClient();

    const startPrintMutation = useMutation({
        mutationFn: () => API.post('/user/printer/selected/print', {
            subDirectory: subDirectory,
            fileName:     selectedFileName
        }),
        onSuccess:  () => {
            setIsRequestingStart(false);

            queryClient.invalidateQueries({ queryKey: ['fileList'] });

            setIsWaitingForNewStatus(true);
        },
        onError: error => {
            console.error(error);

            enqueueSnackbar({
                message: 'Failed to start the print job: ' + (error.response?.data?.message ?? error.message).toLowerCase(),
                variant: 'error',
                action:  { label: 'Got it' }
            });

            setIsRequestingStart(false);
        }
    });

    const pausePrintMutation = useMutation({
        mutationFn: () => API.post('/user/printer/selected/print/pause'),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['connectionStatus'] });

            setIsWaitingForNewStatus(true);
        },
        onError: error => {
            enqueueSnackbar({
                message: 'Failed to pause the print job: ' + (error.response?.data?.message ?? error.message).toLowerCase(),
                variant: 'error',
                action:  { label: 'Got it' }
            });
        }
    });

    const resumePrintMutation = useMutation({
        mutationFn: () => API.post('/user/printer/selected/print/resume'),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['connectionStatus'] });

            setIsWaitingForNewStatus(true);
        },
        onError: error => {
            console.error(error);

            enqueueSnackbar({
                message: 'Failed to resume the print job: ' + (error.response?.data?.message ?? error.message).toLowerCase(),
                variant: 'error',
                action:  { label: 'Got it' }
            });
        }
    });

    const stopPrintMutation = useMutation({
        mutationFn: () => API.post('/user/printer/selected/print/cancel'),
        onSuccess: () => {
            setIsRequestingStop(false);

            queryClient.invalidateQueries({ queryKey: ['connectionStatus'] });

            setIsWaitingForNewStatus(true);
        },
        onError: error => {
            console.error(error);

            enqueueSnackbar({
                message: 'Failed to stop the print job: ' + (error.response?.data?.message ?? error.message).toLowerCase(),
                variant: 'error',
                action:  { label: 'Got it' }
            });

            setIsRequestingStop(false);
        }
    });

    const deleteFileMutation = useMutation({
        mutationFn: () => API.delete('/user/file', {
            subDirectory: subDirectory,
            fileName:     selectedFileName
        }),
        onSuccess: () => {
            setIsRequestingDelete(false);

            queryClient.invalidateQueries({ queryKey: ['fileList'] });

            setSelectedFileName(null);
        },
        onError: error => {
            console.error(error);

            enqueueSnackbar({
                message: 'Failed to delete the file: ' + (error.response?.data?.message ?? error.message).toLowerCase(),
                variant: 'error',
                action:  { label: 'Got it' },
                duration: 5000
            });

            setIsRequestingDelete(false);
        }
    });

    const renameFileMutation = useMutation({
        mutationFn: () => API.put('/user/file/rename', {
            subDirectory: subDirectory,
            oldName:      selectedFileName,
            newName:      newFileName
        }),
        onSuccess: () => {
            setIsRequestingRename(false);

            queryClient.invalidateQueries({ queryKey: ['fileList'] });

            setSelectedFileName(newFileName.replace(subDirectory.substring(1) + '/', ''));
        },
        onError: error => {
            console.error(error);

            enqueueSnackbar({
                message: 'Failed to rename the file: ' + (error.response?.data?.message ?? error.message).toLowerCase(),
                variant: 'error',
                action:  { label: 'Got it' },
                duration: 5000
            });
        }
    });

    const createDirectoryMutation = useMutation({
        mutationFn: () => API.post('/user/directory', {
            subDirectory: subDirectory,
            name:         newFolderName
        }),
        onSuccess: () => {
            setIsCreatingFolder(false);

            setSubDirectory(`${subDirectory}/${newFolderName}`);

            setNewFolderName('');

            queryClient.invalidateQueries({ queryKey: ['fileList'] });
        },
        onError: error => {
            console.error(error);

            enqueueSnackbar({
                message: 'Failed to create folder: ' + (error.response?.data?.message ?? error.message).toLowerCase(),
                variant: 'error',
                action:  { label: 'Got it' },
                duration: 5000
            });
        }
    });

    const deleteDirectoryMutation = useMutation({
        mutationFn: () => {
            return API.delete('/user/directory', {
                subDirectory: getParentTree(),
                name:         getCurrentFolder()
            });
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['fileList'] });

            setSubDirectory( getParentTree() );
        },
        onError: error => {
            console.error(error);

            enqueueSnackbar({
                message: 'Failed to delete the folder: ' + (error.response?.data?.message ?? error.message).toLowerCase(),
                variant: 'error',
                action:  { label: 'Got it' },
                duration: 5000
            });
        }
    });

    useEffect(() => {
        console.debug('UserPrinterFileControls: subDirectory:', subDirectory);
    }, [ subDirectory ]);

    const sharedWaitingFade = useRef( new Animated.Value(0) ).current;

    useEffect(() => {
        // console.debug('UserPrinterFileControls: isPaused:', isPaused, sharedWaitingFade._value);

        let animation;

        if (isPaused) {
            animation = Animated.loop(
                Animated.sequence([
                    Animated.timing(sharedWaitingFade, {
                        toValue: 1,
                        duration: 500,
                        useNativeDriver: true,
                    }),
                    Animated.timing(sharedWaitingFade, {
                        toValue: 0,
                        duration: 500,
                        useNativeDriver: true,
                    }),
                ])
            ).start();
        } else {
            sharedWaitingFade.setValue(0);
        }

        return () => {
            if (animation) {
                animation.stop();
            }
        };
    }, [ isPaused, sharedWaitingFade._value ]);

    useEffect(() => {
        if (!isWaitingForNewStatus || !connectionStatus) return;

        setIsWaitingForNewStatus(false);
    }, [ connectionStatus ]);

    useEffect(() => {
        if (!isRequestingRename) return;

        setNewFileName(selectedFileName);
    }, [ isRequestingRename ]);

    useEffect(() => {
        if (!echo) {
            console.warn('UserPrinterFileControls: echo is not available');

            return;
        }

        console.debug('UserPrinterFileControls: printerId:', printerId);

        if (!printerId) {
            console.warn('UserPrinterFileControls: printerId is not available');

            return;
        }

        const channel = echo?.private(`finished-job.${printerId}`);

        if (!channel) {
            console.warn('UserPrinterFileControls: the channel is not available');

            return;
        }

        channel.listen('PrintJobFinished', event => {
            console.debug('UserPrinterFileControls: PrintJobFinished:', event);

            queryClient.invalidateQueries({ queryKey: ['connectionStatus'] });
            queryClient.invalidateQueries({ queryKey: ['fileList'] });
            queryClient.invalidateQueries({ queryKey: ['printStatus'] });
        });

        return () => { channel.stopListening('PrintJobFinished'); }
    }, [ echo, printerId ]);

    return (
        <View style={{ paddingTop: 10 }}>
            <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
                <View style={{ flexDirection: 'row' }}>
                    {
                        isPrinting
                            ? (
                                isPaused
                                    ? <SmallButton
                                        onPress={() => resumePrintMutation.mutate()}
                                        disabled={resumePrintMutation.isPending || isWaitingForNewStatus || !printerId}
                                        left={
                                            <Animated.View style={{ opacity: sharedWaitingFade }}>
                                                <Icon source="play" color={colors.onPrimary} size={16} />
                                            </Animated.View>
                                        }
                                        style={{
                                            borderWidth:             0,
                                            borderRightWidth:        1,
                                            borderTopRightRadius:    0,
                                            borderBottomRightRadius: 0,
                                            borderColor:             colors.outline
                                        }}
                                    />
                                    : <SmallButton
                                        onPress={() => pausePrintMutation.mutate()}
                                        disabled={resumePrintMutation.isPending  || isWaitingForNewStatus || !printerId}
                                        left={<Icon source="pause" color={colors.onPrimary} size={16} />}
                                        style={{
                                            borderWidth:             0,
                                            borderRightWidth:        1,
                                            borderTopRightRadius:    0,
                                            borderBottomRightRadius: 0,
                                            borderColor:             colors.outline
                                        }}
                                    />
                            )
                            : <SmallButton
                                onPress={() => setIsRequestingStart(true)}
                                disabled={selectedFileName === null || isWaitingForNewStatus || !printStatus || (printStatus && printStatus.hasActiveJob) || !printerId}
                                left={<Icon source="play" color={colors.onPrimary} size={16} />}
                                style={{
                                    borderWidth:             0,
                                    borderRightWidth:        1,
                                    borderTopRightRadius:    0,
                                    borderBottomRightRadius: 0,
                                    borderColor:             colors.outline
                                }}
                            />
                    }

                    <SmallButton
                        onPress={() => setIsRequestingStop(true)}
                        disabled={!isPrinting || isWaitingForNewStatus || !printerId}
                        left={<Icon source="stop" color={colors.onPrimary} size={16} />}
                        style={{
                            borderWidth:      0,
                            borderRadius:     0,
                            borderRightWidth: 1,
                            borderColor:      colors.outline
                        }}
                    />

                    <UserPrinterFileControlsOptions
                        disabled={selectedFileName === null}
                        setIsRequestingDelete={setIsRequestingDelete}
                        setIsRequestingRename={setIsRequestingRename}
                    />
                </View>
                <View>
                    <UserPrinterFileControlsUploader
                        setSelectedFileName={setSelectedFileName}
                        subDirectory={subDirectory}
                    />
                </View>
            </View>

            <UserPrinterFileList
                selectedFileName={selectedFileName}
                setSelectedFileName={setSelectedFileName}
                subDirectory={subDirectory}
                setSubDirectory={setSubDirectory}
                isCreatingFolder={isCreatingFolder}
                setIsCreatingFolder={setIsCreatingFolder}
                deleteDirectoryMutation={deleteDirectoryMutation}
                isParentBusy={createDirectoryMutation.isPending || deleteDirectoryMutation.isPending}
            />

            <SimpleDialog
                visible={isRequestingStart}
                setVisible={setIsRequestingStart}
                actions={
                    <>
                        <Button onPress={() => setIsRequestingStart(false)}>
                            No
                        </Button>
                        <Button onPress={() => startPrintMutation.mutate()}>
                            Yes
                        </Button>
                    </>
                }
                title="Do you really want to start printing this file?"
                content={
                    <Text variant="bodyMedium">
                        "<TextBold variant="bodyMedium">{selectedFileName ?? ''}</TextBold>" will be sent to the print queue and the the job will begin as soon as possible.
                    </Text>
                }
            />

            <SimpleDialog
                visible={isRequestingStop}
                setVisible={setIsRequestingStop}
                actions={
                    <>
                        <Button onPress={() => setIsRequestingStop(false)}>
                            No
                        </Button>
                        <Button onPress={() => stopPrintMutation.mutate()}>
                            Yes
                        </Button>
                    </>
                }
                title="Do you really want to stop the current print job?"
                content={
                    <Text variant="bodyMedium">
                        The current print job will be stopped and the printer will be reset.
                    </Text>
                }
            />

            <SimpleDialog
                visible={isRequestingDelete}
                setVisible={setIsRequestingDelete}
                actions={
                    <>
                        <Button onPress={() => setIsRequestingDelete(false)}>
                            No
                        </Button>
                        <Button onPress={() => deleteFileMutation.mutate()}>
                            Yes
                        </Button>
                    </>
                }
                title="Do you really want to delete this file?"
                content={
                    <Text variant="bodyMedium">
                        "<TextBold variant="bodyMedium">{selectedFileName ?? ''}</TextBold>" will be permanently deleted.
                    </Text>
                }
            />

            <SimpleDialog
                visible={isRequestingRename}
                setVisible={setIsRequestingRename}
                actions={
                    <>
                        <Button onPress={() => setIsRequestingRename(false)}>
                            Cancel
                        </Button>
                        <Button onPress={() => renameFileMutation.mutate()}>
                            Rename
                        </Button>
                    </>
                }
                title="Provide a new name for this file"
                content={
                    <>
                        <Text variant="bodyMedium" style={{ marginBottom: 16 }}>
                            "<TextBold variant="bodyMedium">{selectedFileName ?? ''}</TextBold>" will be renamed.
                        </Text>
                        <TextInput label="New name" value={newFileName} onChangeText={newFileName => setNewFileName(newFileName)} />
                    </>
                }
            />

            <SimpleDialog
                visible={isCreatingFolder}
                setVisible={setIsCreatingFolder}
                actions={
                    <>
                        <Button onPress={() => setIsCreatingFolder(false)}>
                            Cancel
                        </Button>
                        <Button onPress={() => createDirectoryMutation.mutate()}>
                            Create folder
                        </Button>
                    </>
                }
                left={
                    <View style={{ marginRight: 6 }}>
                        <Icon source="folder-plus" size={24} style={{ marginRight: 4 }} />
                    </View>
                }
                title="Provide a name for this folder"
                content={
                    <>
                        <Text variant="bodyMedium" style={{ marginBottom: 16 }}>
                            Type the name of the new folder.
                        </Text>
                        <TextInput label="Name" value={newFolderName} onChangeText={newFolderName => setNewFolderName(newFolderName)} />
                    </>
                }
            />
        </View>
    );
}