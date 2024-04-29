import { useQuery } from "@tanstack/react-query";

import { useEffect, useState } from "react";

import { View } from "react-native";

import { ActivityIndicator, Divider, List, useTheme } from "react-native-paper";

import UserPrinterFileListControls from "./UserPrinterFileListControls";

import API from "../includes/API";

export default function UserPrinterFileList({ selectedFileName, setSelectedFileName }) {
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

    const [ subDirectory, setSubDirectory ] = useState('');
    const [ sortingMode,  setSortingMode  ] = useState(null);
    const [ directories,  setDirectories  ] = useState([]);
    const [ files,        setFiles        ] = useState([]);

    console.debug('subDirectory:', subDirectory);

    const appendSubDirectory = directory => setSubDirectory(`${subDirectory}/${directory}`);

    const sortingModes = useQuery({
        queryKey:   ['sortingModes'],
        queryFn:    () => API.get('/files/sortingModes')
    });

    console.debug('sortingModes:', sortingModes);

    const fileList = useQuery({
        enabled:    sortingModes.isSuccess,
        queryKey:   [ 'fileList', subDirectory, sortingMode ],
        queryFn:    () => API.get('/files', {
            subPath: subDirectory,
            sortBy:  sortingModes.data.data[sortingMode]
        })
    });

    console.debug('fileList:', fileList);

    useEffect(() => setSelectedFileName(null), [ subDirectory ]);

    useEffect(() => {
        if (
            !sortingModes.isSuccess
            ||
            sortingMode !== null
        ) { return; }

        setSortingMode(
            Object.keys(sortingModes.data.data)[0]
        );
    }, [ sortingModes ]);

    useEffect(() => {
        if (!fileList.isSuccess) return;

        setDirectories(fileList.data.data.directories);
        setFiles(fileList.data.data.files);
    }, [ fileList ]);

    console.debug('directories:', directories);
    console.debug('files:', files);

    let components = [];

    const isBusy = fileList.isFetching || sortingModes.isFetching;

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
            components.push(
                <List.Item
                    key={components.length}
                    onPress={() => appendSubDirectory(directory)}
                    title={directory}
                    titleStyle={{ fontWeight: 'bold' }}
                    style={{ paddingVertical: 4 }}
                    left={props => <List.Icon {...props} icon="folder" />}
                    disabled={isBusy}
                />
            );

            components.push(<Divider key={components.length} />);
        });

        files.forEach(file => {
            components.push(
                <List.Item
                    key={components.length}
                    onPress={() => setSelectedFileName(file)}
                    title={file}
                    style={file == selectedFileName && { backgroundColor: colors.primary }}
                    titleStyle={file == selectedFileName && { color: colors.onPrimary }}
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
                title="No files uploaded, try uploading something!"
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