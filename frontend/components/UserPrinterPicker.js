import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useEffect, useState } from "react";

import { StyleSheet, View } from "react-native";

import { Icon, Text, TextInput, useTheme } from "react-native-paper";

import DropDown from "react-native-paper-dropdown";

import API from "../includes/API";
import UserPrinterPickerNoPrintersBanner from "./UserPrinterPickerNoPrintersBanner";

export default function UserPrinterPicker({ selectedPrinter }) {
    const [ showDropDown,       setShowDropDown       ] = useState(false);
    const [ parsedPrintersList, setParsedPrintersList ] = useState([]);

    const queryClient = useQueryClient();

    const printersList = useQuery({
        queryKey: ['printersList'],
        queryFn:  () => API.get('/printers')
    });

    const selectPrinterMutation = useMutation({
        mutationFn: newPrinterId => API.post('/user/printer/selected', { id: newPrinterId }),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['selectedPrinter'] })
    });

    useEffect(() => {
        console.debug('parsedPrintersList:', parsedPrintersList);
    }, [ parsedPrintersList ]);

    useEffect(() => {
        if (printersList.isFetching) {
            setParsedPrintersList([{
                label: 'Loading...',
                value: null
            }]);

            return;
        }

        if (printersList.isError) {
            setParsedPrintersList([{
                label: 'Something went wrong',
                value: null
            }]);

            return;
        }

        if (printersList.data.data.length == 0) {
            setParsedPrintersList([{
                label: 'No printers available',
                value: null
            }]);

            return;
        }

        setParsedPrintersList(
            printersList.data.data.map(printer => {
                return {
                    label: `${printer?.machine?.machineType ?? 'Unknown printer'} (${printer?.machine?.uuid})`,
                    value: printer._id
                };
            })
        );
    }, [ printersList.data ]);

    useEffect(() => {
        console.debug('UserPrinterPicker: parsedPrintersList:', parsedPrintersList);
        console.debug('UserPrinterPicker: selectedPrinter:', selectedPrinter);

        if (!parsedPrintersList.length || selectedPrinter.data) { return; }

        console.debug('UserPrinterPicker: selecting first printer:', parsedPrintersList[0].value);

        selectPrinterMutation.mutate(parsedPrintersList[0].value);
    }, [ parsedPrintersList ]);

    return (
        <>
            <DropDown
                label="Printer"
                mode="outlined"
                visible={showDropDown}
                showDropDown={() => setShowDropDown(true)}
                onDismiss={()    => setShowDropDown(false)}
                value={
                    selectedPrinter.isSuccess
                        ? selectedPrinter.data.data
                        : null
                }
                list={parsedPrintersList}
                setValue={newPrinterId => {
                    if (newPrinterId == selectedPrinter.data.data) { return; }

                    selectPrinterMutation.mutate(newPrinterId);
                }}
                inputProps={{
                    right: (
                        <TextInput.Icon
                            icon={showDropDown ? 'menu-up' : 'menu-down'}
                            onPress={() => setShowDropDown(true)}
                        />
                    )
                }}
            />
            {
                (printersList.isSuccess && printersList.data.data.length == 0) &&
                    <View style={{ alignItems: 'center', flexGrow: 1, justifyContent: 'center', top: -20 }}>
                        <Icon source="connection" size={48} />
                        <Text style={{ paddingTop: 20, textAlign: 'center' }}>
                            To get started, plug a compatible printer and wait for a few seconds.
                            {'\n'}
                            {'\n'}
                            Once the printer is ready to go, it'll be selected automatically.
                        </Text>
                    </View>
            }
        </>
    );
}