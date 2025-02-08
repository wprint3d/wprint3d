

import { Linking, StyleSheet, View, useWindowDimensions } from "react-native";

import { useQuery } from "@tanstack/react-query";

import UserLeftPane  from "./UserLeftPane";
import UserRightPane from "./UserRightPane";

import API from "../includes/API";
import { useEffect, useState } from "react";
import { useEcho } from "../hooks/useEcho";
import UserPrinterMapProgressSnackbar from "./UserPrinterMapProgressSnackbar";
import SimpleDialog from "./SimpleDialog";
import { BottomNavigation, Text } from "react-native-paper";
import JobRecoveryModal from "./JobRecoveryModal";
import UserPaneLoadingIndicator from "./UserPaneLoadingIndicator";
import UserMobileLayout from "./UserMobileLayout";

export default function UserLayout({ navbarHeight, isSmallTablet, isSmallLaptop }) {
    const dimensions = useWindowDimensions();

    const [ isRunningMapper,    setIsRunningMapper  ] = useState(null);
    const [ printerId,          setPrinterId        ] = useState(null);
    const [ printStatus,        setPrintStatus      ] = useState(null);

    const selectedPrinter = useQuery({
        queryKey: ['selectedPrinter'],
        queryFn:  () => API.get('/user/printer/selected')
    });

    const printStatusQuery = useQuery({
        queryKey: ['printStatus'],
        queryFn:  () => API.get('/user/printer/selected/print'),
        enabled:  !!(selectedPrinter.isFetched && selectedPrinter.isSuccess && selectedPrinter.data?.data)
    });

    useEffect(() => {
        if (!selectedPrinter.isFetched || !selectedPrinter.isSuccess) {
            console.warn('UserLayout: private: listen: selectedPrinter is not ready');

            return;
        }

        const nextPrinterId = selectedPrinter.data?.data;

        if (printerId === nextPrinterId) { return; }

        console.debug('UserLayout: private: listen: printerId: ', nextPrinterId);

        setPrinterId(nextPrinterId);
    }, [ selectedPrinter ]);

    useEffect(() => {
        if (!isRunningMapper) { return; }

        const timeout = setTimeout(() => setIsRunningMapper(null), 5000);

        return () => clearTimeout(timeout);
    }, [isRunningMapper]);

    useEffect(() => {
        if (!printStatusQuery.isFetched || !printStatusQuery.isSuccess) { return; }

        setPrintStatus(printStatusQuery?.data?.data ?? null);
    }, [printStatusQuery]);

    const BASE_PADDING = isSmallTablet ? 8 : 0;

    const maxPaneHeight = (
        isSmallTablet
            ? '100%'
            : dimensions.height - navbarHeight - (BASE_PADDING * 2)
    );

    return (
        <View style={[
            styles.root,
            (
                dimensions.width >= 1600
                    ? { paddingHorizontal: (dimensions.width >= 1920 ? 128 : 32) }
                    : {}
            ),
            {
                flexWrap: (
                    dimensions.width <= 425 // mobile large
                        ? 'wrap'
                        : 'nowrap'
                ),
                flexShrink: (
                    isSmallTablet
                        ? 0
                        : 1
                ),
                padding: (
                    isSmallTablet
                        ? 0
                        : 8
                )
            }
        ]}>
            <UserPrinterMapProgressSnackbar isRunningMapper={isRunningMapper} />

            <JobRecoveryModal
                isLoadingPrinter={selectedPrinter.isLoading}
                printerId={printerId}
                isSmallLaptop={isSmallLaptop}
                isSmallTablet={isSmallTablet}
                printStatus={printStatus}
            />

            {isSmallTablet
                ? <UserMobileLayout
                    printerId={printerId}
                    isLoadingPrinter={selectedPrinter.isLoading}
                    isRunningMapper={isRunningMapper}
                    printStatus={printStatus}
                    isSmallLaptop={isSmallLaptop}
                    isSmallTablet={isSmallTablet}
                />
                : (
                    <View style={{ flexGrow: 1, gap: 8, flexDirection: 'row', maxHeight: '100%', maxWidth: '100%' }}>
                        <UserLeftPane
                            isLoadingPrinter={selectedPrinter.isLoading}
                            printerId={printerId}
                            isRunningMapper={isRunningMapper}
                            printStatus={printStatus}
                        />

                        <UserRightPane
                            isLoadingPrinter={selectedPrinter.isLoading}
                            printerId={printerId}
                            isSmallLaptop={isSmallLaptop}
                            isSmallTablet={isSmallTablet}
                        />
                    </View>
                )
            }
        </View>
    );
}

const styles = StyleSheet.create({
    root: {
        width: '100%',
        display: 'flex',
        flexDirection: 'row',
        flexGrow: 1,
        gap: 8
    }
});