import { useQuery } from "@tanstack/react-query";
import { Button, Card, Icon, List, Text, useTheme } from "react-native-paper";
import NavbarMenuSettingsModalPrintersItem from "./NavBarMenuSettingsModalPrintersItem";
import { View } from "react-native";
import NavBarMenuSettingsModalPlaceholderItem from "./NavBarMenuSettingsModalPlaceholderItem";
import UserPaneLoadingIndicator from "./UserPaneLoadingIndicator";
import PrinterSettingsModal from "./PrinterSettingsModal";
import { useEffect, useState } from "react";
import API from "../includes/API";

const NavBarMenuSettingsModalPrinters = ({ isSmallTablet, isSmallLaptop, enqueueSnackbar }) => {
    const printersList = useQuery({
        queryKey: ['printersList'],
        queryFn:  () => API.get('/printers')
    });

    const [ showSettingsModal, setShowSettingsModal ] = useState(false);
    const [ printer,           setPrinter           ] = useState(null);
    const [ printers,          setPrinters          ] = useState([]);

    useEffect(() => {
        if (printersList.isFetched && printersList.isSuccess) {
            setPrinters(printersList?.data?.data);
        }

        console.debug('NavBarMenuSettingsModalPrinters: printers:', printers);
    }, [ printersList ]);

    const handleSettingsModal = (printer) => {
        console.debug('NavBarMenuSettingsModalPrinters: handleSettingsModal:', printer);

        setPrinter(printer);
        setShowSettingsModal(true);
    }

    if (!printers.length && !printersList.isFetching) {
        console.debug('NavBarMenuSettingsModalPrinters: no printers available');

        return (
            <NavBarMenuSettingsModalPlaceholderItem
                icon="usb-port"
                message="No printers available"
                troubleshootingOptions={[
                    'Reset the USB controller',
                    'Re-seat the printer\'s USB plug into the port',
                    'Restart the host'
                ]}
            />
        );
    }

    return (
        <>
        {
            printersList.isFetching && !printers.length
                ? <UserPaneLoadingIndicator key={-1} message={`Loading printers list...`} />
                : (
                    <View style={{ flexDirection: 'row', flexWrap: 'wrap' }}>
                        {
                            printers?.map(printer => {
                                console.debug('NavBarMenuSettingsModalPrinters: printer:', printer);

                                return (
                                    <NavbarMenuSettingsModalPrintersItem
                                        key={printer._id}
                                        printer={printer}
                                        isSmallTablet={isSmallTablet}
                                        isSmallLaptop={isSmallLaptop}
                                        enqueueSnackbar={enqueueSnackbar}
                                        handleSettingsModal={handleSettingsModal}
                                    />
                                );
                            })
                        }
                    </View>
                )
        }
        {printer && (
            <PrinterSettingsModal
                key={`printer-settings-modal-${printer._id ?? 'new'}`}
                isVisible={showSettingsModal}
                setIsVisible={setShowSettingsModal}
                isSmallTablet={isSmallTablet}
                printer={printer}
            />
        )}
        </>
    );
}

export default NavBarMenuSettingsModalPrinters;