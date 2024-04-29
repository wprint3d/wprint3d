import { useQuery } from "@tanstack/react-query";

import { useEffect, useState } from "react";

import { View } from "react-native";

import { Button, Icon, SegmentedButtons, Text, TouchableRipple, useTheme } from "react-native-paper";

import API from "../includes/API";


import UserPrinterFileControlsOptions from "./UserPrinterFileControlsOptions";
import UserPrinterFileList            from "./UserPrinterFileList";
import TextBold                       from "./TextBold";
import SmallButton                    from "./SmallButton";
import SimpleDialog                   from "./SimpleDialog";

export default function UserPrinterFileControls() {
    const { colors } = useTheme();

    const [ selectedFileName,   setSelectedFileName  ] = useState(null);
    const [ isRequestingStart,  setIsRequestingStart ] = useState(false);

    return (
        <View style={{ paddingTop: 10 }}>
            <View style={{ flexDirection: 'row' }}>
                <SmallButton
                    onPress={() => setIsRequestingStart(true)}
                    disabled={selectedFileName === null}
                    left={<Icon source="play" color={colors.onPrimary} size={16} />}
                    style={{
                        borderWidth:             0,
                        borderRightWidth:        1,
                        borderTopRightRadius:    0,
                        borderBottomRightRadius: 0,
                        borderColor:             colors.outline
                    }}
                />

                <SmallButton
                    disabled={selectedFileName === null}
                    left={<Icon source="stop" color={colors.onPrimary} size={16} />}
                    style={{
                        borderWidth:      0,
                        borderRadius:     0,
                        borderRightWidth: 1,
                        borderColor:      colors.outline
                    }}
                />

                <UserPrinterFileControlsOptions disabled={selectedFileName === null} />
            </View>

            <UserPrinterFileList
                selectedFileName={selectedFileName}
                setSelectedFileName={setSelectedFileName}
            />

            <SimpleDialog
                visible={isRequestingStart}
                setVisible={setIsRequestingStart}
                actions={
                    <>
                        <Button onPress={() => setIsRequestingStart(false)}>
                            No
                        </Button>
                        <Button onPress={() => setIsRequestingStart(false)}>
                            Yes
                        </Button>
                    </>
                }
                title="Do you really want to start printing this file?"
                content={
                    <Text variant="bodyMedium">
                        "<TextBold variant="bodyMedium">{selectedFileName}</TextBold>" will be sent to the print queue and the the job will begin as soon as possible.
                    </Text>
                }
            />
        </View>
    );
}