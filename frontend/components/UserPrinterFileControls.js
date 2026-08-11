import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { useEffect, useRef, useState } from "react";

import { Animated, Platform, View } from "react-native";

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
import { useLocalization } from "../includes/LocalizationProvider";

export default function UserPrinterFileControls({ printerId, connectionStatus, printStatus }) {
    const echo = useEcho();
    const { t } = useLocalization();

    const { enqueueSnackbar } = useSnackbar();

    const { colors } = useTheme();

    const [ subDirectory,       setSubDirectory       ] = useState('');
    const [ selectedFileName,   setSelectedFileName   ] = useState(null);
    const [ isRequestingStart,  setIsRequestingStart  ] = useState(false);
    const [ isRequestingStop,   setIsRequestingStop   ] = useState(false);
    const [ isRequestingDelete, setIsRequestingDelete ] = useState(false);
    const [ isRequestingRename, setIsRequestingRename ] = useState(false);
    const [ isCreatingFolder,   setIsCreatingFolder   ] = useState(false);
    const [ isFileDropActive,   setIsFileDropActive   ] = useState(false);
    const [ uploadTransfers,    setUploadTransfers    ] = useState([]);
    const fileDragDepth = useRef(0);
    const fileDropZone = useRef(null);
    const uploadGcodeFilesRef = useRef(null);
    const uploadFiles = useRef(new Map());
    const uploadControllers = useRef(new Map());

    const [ newFileName,   setNewFileName   ] = useState(selectedFileName);
    const [ newFolderName, setNewFolderName ] = useState('');

    const [ isWaitingForNewStatus, setIsWaitingForNewStatus ] = useState(true);

    const isPrinting = !!connectionStatus?.isPrinting;
    const isPaused   = !!connectionStatus?.isPaused;

    const getParentTree     = () => subDirectory.split('/').slice(0, -1).join('/');
    const getCurrentFolder  = () => subDirectory.split('/').pop();

    const queryClient = useQueryClient();
    const getErrorReason = error => (error.response?.data?.message ?? error.message).toLowerCase();

    const updateUploadTransfer = (transferId, changes) => {
        setUploadTransfers(current => current.map(item => (
            item.id === transferId ? { ...item, ...changes } : item
        )));
    };

    const dismissUploadTransfer = transferId => {
        uploadControllers.current.get(transferId)?.abort();
        uploadControllers.current.delete(transferId);
        uploadFiles.current.delete(transferId);
        setUploadTransfers(current => current.filter(item => item.id !== transferId));
    };

    const uploadGcodeFile = async transferId => {
        const upload = uploadFiles.current.get(transferId);
        if (!upload) { return; }

        const controller = new AbortController();
        uploadControllers.current.get(transferId)?.abort();
        uploadControllers.current.set(transferId, controller);
        updateUploadTransfer(transferId, { progress: 0, state: 'uploading', error: null });

        try {
            const result = await API.post('/user/file/upload', {
                subDirectory: upload.directory,
                files: [upload.file],
            }, {
                signal: controller.signal,
                onUploadProgress: event => {
                    const total = event.total || 0;
                    const uploadedPercent = total > 0 ? Math.round((event.loaded / total) * 100) : 8;
                    updateUploadTransfer(transferId, {
                        progress: Math.min(95, Math.max(1, uploadedPercent)),
                        state: uploadedPercent >= 100 ? 'processing' : 'uploading',
                    });
                }
            });
            if (controller.signal.aborted) { return; }

            const storedName = result?.data?.[0] || upload.file.name;
            updateUploadTransfer(transferId, { name: storedName, progress: 100, state: 'ready' });
            queryClient.invalidateQueries({ queryKey: ['fileList'] });
            if (upload.directory === subDirectory) {
                setSelectedFileName(storedName);
            }
            globalThis.setTimeout(() => {
                setUploadTransfers(current => current.filter(item => item.id !== transferId || item.state !== 'ready'));
                uploadFiles.current.delete(transferId);
            }, 1600);
        } catch (error) {
            if (controller.signal.aborted || error?.code === 'ERR_CANCELED') {
                updateUploadTransfer(transferId, { state: 'cancelled' });
                return;
            }

            const reason = getErrorReason(error);
            updateUploadTransfer(transferId, { state: 'error', error: reason });
            enqueueSnackbar({
                message: t("files.uploadError", { reason }),
                variant: 'error',
                action: { label: t("notifications.gotIt") }
            });
        } finally {
            if (uploadControllers.current.get(transferId) === controller) {
                uploadControllers.current.delete(transferId);
            }
        }
    };

    const cancelUploadTransfer = transferId => {
        uploadControllers.current.get(transferId)?.abort();
        updateUploadTransfer(transferId, { state: 'cancelled' });
    };

    const retryUploadTransfer = transferId => {
        void uploadGcodeFile(transferId);
    };

    const uploadGcodeFiles = filesInput => {
        const files = Array.from(filesInput || []);
        if (!files.length) { return; }
        const supportedFiles = files.filter(file => /(?:\.gcode\.gz|\.gcode|\.gco)$/i.test(file?.name || ''));
        if (supportedFiles.length !== files.length) {
            enqueueSnackbar({
                message: t('files.uploadOnlyGcode'),
                variant: 'error',
                action: { label: t("notifications.gotIt") }
            });
        }
        if (!supportedFiles.length) { return; }
        const timestamp = Date.now();
        const transfers = supportedFiles.map((file, index) => {
            const id = `upload-${timestamp}-${index}`;
            uploadFiles.current.set(id, { file, directory: subDirectory });
            return {
                id,
                name: file.name,
                directory: subDirectory,
                progress: 0,
                state: 'queued',
            };
        });
        setUploadTransfers(current => [...current, ...transfers]);
        transfers.forEach(transfer => void uploadGcodeFile(transfer.id));
    };

    uploadGcodeFilesRef.current = uploadGcodeFiles;

    useEffect(() => {
        const dropZone = fileDropZone.current;
        if (Platform.OS !== 'web' || !dropZone?.addEventListener) { return; }

        const isFileDrag = event => Array.from(event.dataTransfer?.types || []).includes('Files');
        const handleDragEnter = event => {
            if (!isFileDrag(event)) { return; }
            event.preventDefault();
            fileDragDepth.current += 1;
            setIsFileDropActive(true);
        };
        const handleDragOver = event => {
            if (!isFileDrag(event)) { return; }
            event.preventDefault();
            if (event.dataTransfer) { event.dataTransfer.dropEffect = 'copy'; }
            setIsFileDropActive(true);
        };
        const handleDragLeave = event => {
            event.preventDefault();
            fileDragDepth.current = Math.max(0, fileDragDepth.current - 1);
            if (fileDragDepth.current === 0) { setIsFileDropActive(false); }
        };
        const handleDrop = event => {
            if (!isFileDrag(event)) { return; }
            event.preventDefault();
            fileDragDepth.current = 0;
            setIsFileDropActive(false);
            uploadGcodeFilesRef.current?.(event.dataTransfer?.files);
        };

        dropZone.addEventListener('dragenter', handleDragEnter);
        dropZone.addEventListener('dragover', handleDragOver);
        dropZone.addEventListener('dragleave', handleDragLeave);
        dropZone.addEventListener('drop', handleDrop);

        return () => {
            dropZone.removeEventListener('dragenter', handleDragEnter);
            dropZone.removeEventListener('dragover', handleDragOver);
            dropZone.removeEventListener('dragleave', handleDragLeave);
            dropZone.removeEventListener('drop', handleDrop);
        };
    }, []);

    useEffect(() => () => {
        uploadControllers.current.forEach(controller => controller.abort());
        uploadControllers.current.clear();
    }, []);

    const startPrintMutation = useMutation({
        mutationFn: () => API.post('/user/printer/selected/print', {
            subDirectory: subDirectory,
            fileName:     selectedFileName
        }),
        onSuccess:  () => {
            setIsRequestingStart(false);

            queryClient.invalidateQueries({ queryKey: ['fileList'] });
            queryClient.invalidateQueries({ queryKey: ['connectionStatus'] });
            queryClient.invalidateQueries({ queryKey: ['printStatus'] });

            setIsWaitingForNewStatus(true);
        },
        onError: error => {
            console.error(error);

            enqueueSnackbar({
                message: t("files.startPrintError", { reason: getErrorReason(error) }),
                variant: 'error',
                action:  { label: t("notifications.gotIt") }
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
                message: t("files.pausePrintError", { reason: getErrorReason(error) }),
                variant: 'error',
                action:  { label: t("notifications.gotIt") }
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
                message: t("files.resumePrintError", { reason: getErrorReason(error) }),
                variant: 'error',
                action:  { label: t("notifications.gotIt") }
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
                message: t("files.stopPrintError", { reason: getErrorReason(error) }),
                variant: 'error',
                action:  { label: t("notifications.gotIt") }
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
                message: t("files.deleteFileError", { reason: getErrorReason(error) }),
                variant: 'error',
                action:  { label: t("notifications.gotIt") },
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
                message: t("files.renameFileError", { reason: getErrorReason(error) }),
                variant: 'error',
                action:  { label: t("notifications.gotIt") },
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
                message: t("files.createFolderError", { reason: getErrorReason(error) }),
                variant: 'error',
                action:  { label: t("notifications.gotIt") },
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
                message: t("files.deleteFolderError", { reason: getErrorReason(error) }),
                variant: 'error',
                action:  { label: t("notifications.gotIt") },
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

        const handlePrintJobFinished = event => {
            console.debug('UserPrinterFileControls: PrintJobFinished:', event);

            queryClient.invalidateQueries({ queryKey: ['connectionStatus'] });
            queryClient.invalidateQueries({ queryKey: ['fileList'] });
            queryClient.invalidateQueries({ queryKey: ['printStatus'] });
        };

        channel.listen('PrintJobFinished', handlePrintJobFinished);

        return () => { channel.stopListening('PrintJobFinished', handlePrintJobFinished); }
    }, [ echo, printerId, queryClient ]);

    return (
        <View
            ref={fileDropZone}
            testID="gcode-file-drop-zone"
            style={{ paddingTop: 10, position: 'relative' }}
        >
            {isFileDropActive && (
                <View
                    testID="gcode-file-drop-overlay"
                    pointerEvents="none"
                    style={{
                        position: 'absolute',
                        top: 0,
                        right: 0,
                        bottom: 0,
                        left: 0,
                        zIndex: 20,
                        alignItems: 'center',
                        justifyContent: 'center',
                        borderWidth: 2,
                        borderStyle: 'dashed',
                        borderColor: colors.primary,
                        borderRadius: 8,
                        backgroundColor: colors.elevation.level2,
                        opacity: 0.96,
                    }}
                >
                    <Icon source="file-upload-outline" color={colors.primary} size={36} />
                    <Text variant="titleMedium" style={{ color: colors.onSurface, marginTop: 8 }}>{t('files.dropGcodeTitle')}</Text>
                    <Text variant="bodySmall" style={{ color: colors.onSurfaceVariant }}>{t('files.dropGcodeTypes')}</Text>
                </View>
            )}
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
                        disabled={uploadTransfers.some(item => ['queued', 'uploading', 'processing'].includes(item.state))}
                        onFilesSelected={uploadGcodeFiles}
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
                pendingUploads={uploadTransfers}
                onRetryUpload={retryUploadTransfer}
                onCancelUpload={cancelUploadTransfer}
                onDismissUpload={dismissUploadTransfer}
            />

            <SimpleDialog
                visible={isRequestingStart}
                setVisible={setIsRequestingStart}
                actions={
                    <>
                        <Button onPress={() => setIsRequestingStart(false)}>
                            {t("notifications.no")}
                        </Button>
                        <Button onPress={() => startPrintMutation.mutate()}>
                            {t("notifications.yes")}
                        </Button>
                    </>
                }
                title={t("files.startConfirmTitle")}
                content={
                    <Text variant="bodyMedium">
                        {t("files.startConfirmBody", { name: selectedFileName ?? "" })}
                    </Text>
                }
            />

            <SimpleDialog
                visible={isRequestingStop}
                setVisible={setIsRequestingStop}
                actions={
                    <>
                        <Button onPress={() => setIsRequestingStop(false)}>
                            {t("notifications.no")}
                        </Button>
                        <Button onPress={() => stopPrintMutation.mutate()}>
                            {t("notifications.yes")}
                        </Button>
                    </>
                }
                title={t("files.stopConfirmTitle")}
                content={
                    <Text variant="bodyMedium">
                        {t("files.stopConfirmBody")}
                    </Text>
                }
            />

            <SimpleDialog
                visible={isRequestingDelete}
                setVisible={setIsRequestingDelete}
                actions={
                    <>
                        <Button onPress={() => setIsRequestingDelete(false)}>
                            {t("notifications.no")}
                        </Button>
                        <Button onPress={() => deleteFileMutation.mutate()}>
                            {t("notifications.yes")}
                        </Button>
                    </>
                }
                title={t("files.deleteConfirmTitle")}
                content={
                    <Text variant="bodyMedium">
                        {t("files.deleteConfirmBody", { name: selectedFileName ?? "" })}
                    </Text>
                }
            />

            <SimpleDialog
                visible={isRequestingRename}
                setVisible={setIsRequestingRename}
                actions={
                    <>
                        <Button onPress={() => setIsRequestingRename(false)}>
                            {t("notifications.cancel")}
                        </Button>
                        <Button onPress={() => renameFileMutation.mutate()}>
                            {t("notifications.rename")}
                        </Button>
                    </>
                }
                title={t("files.renameTitle")}
                content={
                    <>
                        <Text variant="bodyMedium" style={{ marginBottom: 16 }}>
                            {t("files.renameBody", { name: selectedFileName ?? "" })}
                        </Text>
                        <TextInput label={t("files.newName")} value={newFileName} onChangeText={newFileName => setNewFileName(newFileName)} />
                    </>
                }
            />

            <SimpleDialog
                visible={isCreatingFolder}
                setVisible={setIsCreatingFolder}
                actions={
                    <>
                        <Button onPress={() => setIsCreatingFolder(false)}>
                            {t("notifications.cancel")}
                        </Button>
                        <Button onPress={() => createDirectoryMutation.mutate()}>
                            {t("files.createFolder")}
                        </Button>
                    </>
                }
                left={
                    <View style={{ marginRight: 6 }}>
                        <Icon source="folder-plus" size={24} style={{ marginRight: 4 }} />
                    </View>
                }
                title={t("files.folderTitle")}
                content={
                    <>
                        <Text variant="bodyMedium" style={{ marginBottom: 16 }}>
                            {t("files.folderBody")}
                        </Text>
                        <TextInput label={t("files.folderName")} value={newFolderName} onChangeText={newFolderName => setNewFolderName(newFolderName)} />
                    </>
                }
            />
        </View>
    );
}
