import { useState } from "react";
import { View } from "react-native";
import { Button, Card, Icon, IconButton, Text, useTheme } from "react-native-paper";
import dayjs from 'dayjs';
import duration from 'dayjs/plugin/duration';

dayjs.extend(duration);

const UserPrinterRecordingItem = ({
    recording,
    isSmallTablet,
    isSmallLaptop,
    setSelectedRecording,
    setDeleteDialogVisible,
    setPlayerDialogVisible
}) => {
    console.debug('UserPrinterRecordingItem: recording:', recording);

    const { colors } = useTheme();

    const [ thumbLoadError, setThumbLoadError ] = useState(null);

    const formattedDuration = dayjs.duration(recording.durationSecs, 'seconds').format('HH:mm:ss');

    const handleDeleteRequest = (recording) => {
        console.debug('UserPrinterRecordingItem: handleDeleteRequest:', recording);

        setSelectedRecording(recording);
        setDeleteDialogVisible(true);
    };

    const handlePlaybackRequest = (recording) => {
        console.debug('UserPrinterRecordingItem: handlePlaybackRequest:', recording);

        setSelectedRecording(recording);
        setPlayerDialogVisible(true);
    };

    return (
        <Card style={{
            width: (
                isSmallTablet
                    ? '100%'
                    : (
                        isSmallLaptop
                            ? '50%'
                            : '33%'
                    )
            )
        }}>
            <View>
                {
                    thumbLoadError === null
                        ? (
                            <Card.Cover
                                source={{ uri: recording.thumb }}
                                onError={(error) => {
                                    console.error('UserPrinterRecordingItem: error:', error);

                                    setThumbLoadError(error);
                                }}
                            />
                        )
                        : (
                            <View style={{ height: 195, borderRadius: 12, backgroundColor: colors.primary, alignItems: 'center' }}>
                                <View style={{ flexDirection: 'column', justifyContent: 'center', alignItems: 'center', height: '100%' }}>
                                    <Icon source={'image-broken'} color={colors.onPrimary} size={48} />
                                    <Text style={{ color: colors.onPrimary, fontSize: 16, textAlign: 'center', paddingVertical: 8 }}>
                                        Couldn't load thumbnail
                                    </Text>
                                </View>
                            </View>
                        )
                }

                <Text
                    style={{
                        color: colors.primary, textAlign: 'center', paddingVertical: 4, marginTop: 16,
                        position: 'absolute', bottom: 10, right: 15, backgroundColor: colors.surface, color: colors.onSurface, paddingHorizontal: 8, borderRadius: 4
                    }}
                    variant="titleSmall"
                >
                    {formattedDuration}
                </Text>
            </View>
            <Card.Title
                title={recording.name}
                subtitle={
                    `Created ${recording.modified}` + '\n' +
                    (recording.sizeBytes / (1024 * 1024)).toFixed(2) + ' MB'
                }
                subtitleNumberOfLines={2}
                subtitleVariant="bodySmall"
            />
            <Card.Actions>
                <Button mode="contained" icon={'delete'} onPress={() => handleDeleteRequest(recording)} buttonColor={colors.error} textColor={colors.onBackground}>
                    Delete
                </Button>
                <Button mode="contained" icon={'play'} onPress={() => handlePlaybackRequest(recording)}>
                    Play
                </Button>
            </Card.Actions>
        </Card>
    );
};

export default UserPrinterRecordingItem;