import { useQuery } from "@tanstack/react-query";

import { useEffect, useState } from "react";

import { View } from "react-native";

import { ActivityIndicator, Badge, Divider, Icon, List, Text, useTheme } from "react-native-paper";

import UserPrinterFileListControls from "./UserPrinterFileListControls";

import API from "../includes/API";

export default function UserPrinterFileList({
    selectedFileName,
    setSelectedFileName,
    subDirectory,
    setSubDirectory,
    isCreatingFolder,
    setIsCreatingFolder,
    deleteDirectoryMutation,
    isParentBusy = false
}) {
    const { colors } = useTheme();

    const sortingModesIcons = {
        NAME_ASCENDING:     'sort-alphabetical-ascending',
        NAME_DESCENDING:    'sort-alphabetical-descending',
        DATE_ASCENDING:     'sort-clock-ascending',
        DATE_DESCENDING:    'sort-clock-descending'
    };

    const sortingModesTitles = {
        NAME_ASCENDING:     'Name ascending',
        NAME_DESCENDING:    'Name descending',
        DATE_ASCENDING:     'Date ascending',
        DATE_DESCENDING:    'Date descending'
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
                            ? 'Loading files list'
                            : 'Getting sorting modes'
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
                                    NEW
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
                                    {file.prints} print{file.prints > 1 ? 's' : ''}
                                </Badge>
                            );
                        }

                        return (
                            <Badge
                                theme={{
                                    colors: {
                                        error:   colors.elevation.level1,
                                        onError: colors.primary
                                    }
                                }}
                                style={{ paddingHorizontal: 8 }}
                            >
                                {file.prints} print{file.prints > 1 ? 's' : ''}
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
                        ? <Text> No files uploaded, try uploading something.</Text>
                        : <Text> No files uploaded, <Text onPress={() => deleteDirectoryMutation.mutate(subDirectory)} style={{ textDecoration: 'underline' }}>delete this folder</Text> or try uploading something.</Text>
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