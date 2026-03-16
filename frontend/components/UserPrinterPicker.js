import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useEffect, useMemo, useState } from "react";

import { TouchableOpacity, View } from "react-native";

import { Icon, Menu, Text, TextInput } from "react-native-paper";

import API from "../includes/API";
import { useEcho } from "../hooks/useEcho";
import { useLocalization } from "../includes/LocalizationProvider";

const buildPrinterOption = (printer, t) => {
    const simulated = printer?.machine?.connectionType === "fakeSerial" || printer?.machine?.simulated === true;

    return {
        label: `${printer?.machine?.machineType ?? t("settings.unknownPrinter")} (${printer?.machine?.uuid})`,
        value: printer._id,
        icon: simulated ? "monitor" : "printer-3d-nozzle-outline",
        simulated,
    };
};

export default function UserPrinterPicker({ printerId }) {
    const { t } = useLocalization();
    const [ showMenu, setShowMenu ] = useState(false);
    const [ options,  setOptions  ] = useState([]);

    const echo = useEcho();
    const queryClient = useQueryClient();

    const selectPrinterMutation = useMutation({
        mutationFn: newPrinterId => API.post("/user/printer/selected", { id: newPrinterId }),
        onSuccess:  () => queryClient.invalidateQueries({ queryKey: ["selectedPrinter"] }),
    });

    const printersListQuery = useQuery({
        queryKey: ["printersList"],
        queryFn:  () => API.get("/printers"),
    });

    useEffect(() => {
        if (printersListQuery.isFetching) {
            setOptions([{
                label: t("printer.picker.loading"),
                value: null,
                icon: "progress-clock",
                disabled: true,
            }]);

            return;
        }

        if (printersListQuery.isError) {
            setOptions([{
                label: t("printer.picker.loadError"),
                value: null,
                icon: "alert-circle-outline",
                disabled: true,
            }]);

            return;
        }

        const printersList = printersListQuery?.data?.data ?? [];

        if (!printersList.length) {
            setOptions([{
                label: t("settings.noPrinters"),
                value: null,
                icon: "usb-port",
                disabled: true,
            }]);

            return;
        }

        setOptions(printersList.map((printer) => buildPrinterOption(printer, t)));
    }, [ printersListQuery.data, printersListQuery.isError, printersListQuery.isFetching, t ]);

    useEffect(() => {
        if (!options.length || printerId || options[0]?.value === null) {
            return;
        }

        selectPrinterMutation.mutate(options[0].value);
    }, [ options, printerId ]);

    useEffect(() => {
        if (!echo) {
            return;
        }

        const channel = echo.channel("printers-map-updated");

        if (!channel) {
            return;
        }

        channel.listen("PrintersMapUpdated", () => {
            queryClient.invalidateQueries({ queryKey: ["printersList"] });
        });

        return () => { channel.stopListening("PrintersMapUpdated"); };
    }, [ echo, queryClient ]);

    const selectedOption = useMemo(
        () => options.find(option => option.value === printerId) ?? options[0] ?? null,
        [ options, printerId ]
    );

    const selectedLabel = selectedOption?.label ?? t("printer.picker.selectPrinter");
    const selectedIcon = selectedOption?.icon ?? "printer-3d-nozzle-outline";

    return (
        <>
            <Menu
                visible={showMenu}
                onDismiss={() => setShowMenu(false)}
                anchor={(
                    <TouchableOpacity onPress={() => setShowMenu(true)} activeOpacity={0.9}>
                        <View pointerEvents="none">
                            <TextInput
                                label={t("printer.picker.label")}
                                mode="outlined"
                                editable={false}
                                value={selectedLabel}
                                left={<TextInput.Icon icon={selectedIcon} />}
                                right={<TextInput.Icon icon={showMenu ? "menu-up" : "menu-down"} />}
                            />
                        </View>
                    </TouchableOpacity>
                )}
            >
                {options.map(option => (
                    <Menu.Item
                        key={option.value ?? option.label}
                        leadingIcon={option.icon}
                        title={option.label}
                        disabled={option.disabled === true}
                        onPress={() => {
                            setShowMenu(false);

                            if (option.value === null || option.value === printerId) {
                                return;
                            }

                            selectPrinterMutation.mutate(option.value);
                        }}
                    />
                ))}
            </Menu>

            {(!printersListQuery.isError && !printerId) && (
                <View style={{ alignItems: "center", flexGrow: 1, justifyContent: "center", paddingVertical: 80 }}>
                    <Icon source="connection" size={48} />
                    <Text style={{ paddingTop: 20, textAlign: "center" }}>
                        {t("printer.picker.emptyState")}
                        {"\n"}
                        {"\n"}
                        {t("printer.picker.emptyStateFollowUp")}
                    </Text>
                </View>
            )}
        </>
    );
}
