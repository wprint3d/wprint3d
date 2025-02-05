import { useMutation, useQuery } from "@tanstack/react-query";
import { useEffect, useState } from "react";
import { View } from "react-native";
import { Button, Card, Icon, Text, useTheme } from "react-native-paper";
import API from "../includes/API";
import UserPrinterRecordingItem from "./UserPrinterRecordingItem";
import SimpleDialog from "./SimpleDialog";
import UserPaneLoadingIndicator from "./UserPaneLoadingIndicator";
import TextBold from "./TextBold";
import VideoPlayer from "./modules/VideoPlayer";
import { useSnackbar } from "react-native-paper-snackbar-stack";

const UserPrinterRecordings = ({ isLoadingPrinter = true, printerId = null, isSmallTablet, isSmallLaptop }) => {
    const { enqueueSnackbar } = useSnackbar();

    const [ recordings, setRecordings ] = useState([]);

    const [ selectedRecording, setSelectedRecording ] = useState(null);

    const [ deleteDialogVisible, setDeleteDialogVisible ] = useState(false);
    const [ playerDialogVisible, setPlayerDialogVisible ] = useState(false);

    const TOP_CENTER_OFFSET = 64; // px

    const userSettingsQuery = useQuery({
        queryKey: ['userSettings'],
        queryFn:  () => API.get('/user/settings')
    });

    const userSettings = userSettingsQuery?.data?.data;

    const isRecordingEnabled = !!userSettings?.recording?.enabled;

    const recordingsQuery = useQuery({
        queryKey:   [ 'user', 'printer', printerId, 'recordings' ],
        queryFn:    () => API.get(`/user/printer/selected/recordings`),
        refetchOnWindowFocus: true
    });

    const deleteRecordingMutation = useMutation({
        mutationFn:  ({ recording }) => {
            console.debug('UserPrinterRecordings: deleteRecordingMutation:', recording);
            return API.delete(`/user/printer/selected/recording/${recording.id}`);
        },
        onSuccess:   () => {
            console.debug('UserPrinterRecordings: deleteRecordingMutation: success');

            enqueueSnackbar({
                message: 'Recording deleted successfully!',
                variant: 'success',
                action:  { label: 'Got it' }
            });

            recordingsQuery.refetch();
        },
        onError:     (error) => {
            console.error('UserPrinterRecordings: deleteRecordingMutation: error:', error);

            enqueueSnackbar({
                message: 'Failed to delete recording: ' + (error.response?.data?.message ?? error.message).toLowerCase(),
                variant: 'error',
                action:  { label: 'Got it' }
            });
        }
    });

    useEffect(() => {
        console.debug('UserPrinterRecordings: userSettings:', userSettings);
    }, [ userSettings ]);

    useEffect(() => {
        console.debug('UserPrinterRecordings: recordings:', recordings);
    }, [ recordings ]);

    useEffect(() => {
        if (!recordingsQuery.data) { return; }

        setRecordings(recordingsQuery?.data?.data ?? []);
    }, [ recordingsQuery.data ]);

    useEffect(() => {
        if (deleteDialogVisible || playerDialogVisible) { return; }

        setSelectedRecording(null);
    }, [ deleteDialogVisible, playerDialogVisible ]);

    if (recordingsQuery.isLoading) {
        return <UserPaneLoadingIndicator message={'Loading recordings'} />;
    }

    return (
        <View style={{ padding: 10, height: '100%', overflow: 'hidden' }}>
            {recordings.map((recording, index) => (
                <UserPrinterRecordingItem
                    key={index}
                    recording={recording}
                    isSmallTablet={isSmallTablet}
                    isSmallLaptop={isSmallLaptop}
                    setSelectedRecording={setSelectedRecording}
                    setDeleteDialogVisible={setDeleteDialogVisible}
                    setPlayerDialogVisible={setPlayerDialogVisible}
                />
            ))}

            {(recordings.length === 0 && !recordingsQuery.isLoading) && (
                <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'center', height: '100%', top: -TOP_CENTER_OFFSET }}>
                    <View style={{ width: '100%', padding: 10, margin: 10, alignItems: 'center' }}>
                        <Icon source={'video-off'} size={48} />
                        <Text variant="bodyMedium" style={{ textAlign: 'center', marginTop: 10 }}>
                            {
                                isRecordingEnabled
                                    ? 'Nothing here so far, select a file and get going!'
                                    : <>
                                        Recording is disabled. {'\n'}
                                        {'\n'}
                                        To start recording, enable the feature from <TextBold>Settings</TextBold> {'>'} <TextBold>Recording</TextBold>.
                                    </>
                            }
                        </Text>
                    </View>
                </View>
            )}

            {/* Video player dialog */}
            <SimpleDialog
                visible={playerDialogVisible}
                setVisible={setPlayerDialogVisible}
                title={`Playing: ${selectedRecording?.name ?? ''}`}
                content={<VideoPlayer source={selectedRecording?.url} />}
                style={{ maxWidth: '100%', width: '95%' }}
                actions={
                    <>
                        <Button
                            mode="text"
                            onPress={() => setPlayerDialogVisible(false)}
                        >
                            Close
                        </Button>
                    </>
                }
            />

            {/* Delete dialog */}
            <SimpleDialog
                visible={deleteDialogVisible}
                setVisible={setDeleteDialogVisible}
                title="Do you really want to delete this recording?"
                content={
                    <Text variant="bodyMedium">
                        "<TextBold variant="bodyMedium">{selectedRecording?.name ?? ''}</TextBold>" will be permanently deleted.
                    </Text>
                }
                actions={
                    <>
                        <Button
                            mode="text"
                            onPress={() => setDeleteDialogVisible(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            mode="contained"
                            onPress={() => {
                                setDeleteDialogVisible(false);

                                console.debug('UserPrinterRecordings: deleteRecording:', selectedRecording);

                                deleteRecordingMutation.mutate({ recording: selectedRecording });
                            }}
                        >
                            Delete
                        </Button>
                    </>
                }
            />
        </View>
    );
}

export default UserPrinterRecordings;