import { Modal, Portal, useTheme } from "react-native-paper";
import { SnackbarProvider } from "react-native-paper-snackbar-stack";
import { Tabs, TabScreen, TabsProvider } from "react-native-paper-tabs"
import { useEffect, useState } from "react";

import PrinterSettingsModalDetails from "./PrinterSettingsModalDetails";
import PrinterSettingsModalLinking from "./PrinterSettingsModalLinking";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import API from "../includes/API";
import BackButton from "./modules/BackButton";

const PrinterSettingsModal = ({ isVisible, setIsVisible, printer, isSmallTablet }) => {
    const theme = useTheme();

    const [ details,    setDetails   ] = useState(null);
    const [ loading,    setLoading   ] = useState(true);
    const [ error,      setError     ] = useState(null);

    const detailsQuery = useQuery({
        queryKey: ['printerDetails', printer._id],
        queryFn:  () => API.get(`/printer/${printer._id}`)
    });

    useEffect(() => {
        console.debug('detailsQuery:', detailsQuery);

        if (detailsQuery.isError) {
            setError(detailsQuery?.error?.response?.data?.message ?? 'Unknown error');

            return;
        }

        if (!detailsQuery.isFetching) { setLoading(false); }

        if (detailsQuery.isSuccess) {
            setDetails(detailsQuery?.data?.data);
        }
    }, [ detailsQuery, printer ]);

    return (
        <Portal>
            <SnackbarProvider maxSnack={4}>
                <Modal
                    visible={isVisible}
                    onDismiss={() => setIsVisible(false)}
                    contentContainerStyle={{
                        backgroundColor: theme.colors.elevation.level1,
                        alignSelf: 'center',
                        padding: 16,
                        height: isSmallTablet ? '100%' : '85%',
                        width:  isSmallTablet ? '100%' : '85%',
                        maxWidth: isSmallTablet ? '100%' : 512,
                        overflow: 'scroll'
                    }}
                >
                    {isSmallTablet && <BackButton onPress={() => setIsVisible(false)} />}
                    <TabsProvider defaultIndex={0}>
                        <Tabs
                            style={{
                                backgroundColor: theme.colors.elevation.level1,
                                marginBottom: 16
                            }}
                            mode="scrollable"
                            tabHeaderStyle={{ alignSelf: 'center' }}
                            showLeadingSpace={false}
                        >
                            <TabScreen label="Details" icon="cog">
                                <PrinterSettingsModalDetails details={details} isLoading={loading} error={error} />
                            </TabScreen>
                            <TabScreen label="Linking" icon="link">
                                <PrinterSettingsModalLinking details={details} isLoading={loading} error={error} printer={printer} />
                            </TabScreen>
                        </Tabs>
                    </TabsProvider>
                </Modal>
            </SnackbarProvider>
        </Portal>
    );
}

export default PrinterSettingsModal;