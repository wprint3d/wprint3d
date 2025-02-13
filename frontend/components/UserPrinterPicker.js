import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useEffect, useState } from "react";

import { StyleSheet, View } from "react-native";

import { Icon, Text, TextInput, useTheme } from "react-native-paper";

import DropDown from "react-native-paper-dropdown";

import API from "../includes/API";

export default function UserPrinterPicker({ printerId, printersList }) {
    const [ showDropDown, setShowDropDown ] = useState(false);
    const [ options,      setOptions      ] = useState([]);

    const queryClient = useQueryClient();

    const selectPrinterMutation = useMutation({
        mutationFn: newPrinterId => API.post('/user/printer/selected', { id: newPrinterId }),
        onSuccess:  ()           => queryClient.invalidateQueries({ queryKey: ['selectedPrinter'] })
    });

    const printersListQuery = useQuery({
        queryKey: ['printersList'],
        queryFn:  () => API.get('/printers')
    });

    useEffect(() => {
        if (printersListQuery.isFetching) {
            setOptions([{
                label: 'Loading...',
                value: null
            }]);

            return;
        }

        if (printersListQuery.isError) {
            setOptions([{
                label: 'Something went wrong',
                value: null
            }]);

            return;
        }

        const printersList = printersListQuery?.data?.data ?? [];

        console.debug('UserPrinterPicker: printersList', printersList);

        if (!printersList || !printersList.length) {
            setOptions([{
                label: 'No printers available',
                value: null
            }]);

            return;
        }

        setOptions(
            printersList.map(printer => {
                return {
                    label: `${printer?.machine?.machineType ?? 'Unknown printer'} (${printer?.machine?.uuid})`,
                    value: printer._id
                };
            })
        );
    }, [ printersListQuery.data ]);

    useEffect(() => {
        console.debug('UserPrinterPicker: options', options);

        if (!options.length || (printerId && options.length) || options[0].value === null) {
            return;
        }

        console.debug('UserPrinterPicker: selecting first printer:', options[0].value);

        selectPrinterMutation.mutate(options[0].value);
    }, [ options ]);

    return (
        <>
            <DropDown
                label="Printer"
                mode="outlined"
                visible={showDropDown}
                showDropDown={() => setShowDropDown(true)}
                onDismiss={()    => setShowDropDown(false)}
                value={printerId ?? null}
                list={options}
                setValue={newPrinterId => {
                    if (newPrinterId == printerId) { return; }

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
                (!printersListQuery.isError && !printerId) &&
                    <View style={{ alignItems: 'center', flexGrow: 1, justifyContent: 'center', paddingVertical: 80 }}>
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