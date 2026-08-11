import { Modal, Portal, useTheme } from "react-native-paper";
import { SnackbarProvider } from "react-native-paper-snackbar-stack";
import { Tabs, TabScreen, TabsProvider } from "react-native-paper-tabs"
import { useEffect, useState } from "react";

import PrinterSettingsModalDetails from "./PrinterSettingsModalDetails";
import PrinterSettingsModalLinking from "./PrinterSettingsModalLinking";
import PrinterSettingsModalSlicing from "./PrinterSettingsModalSlicing";
import { useQuery } from "@tanstack/react-query";
import API from "../includes/API";
import BackButton from "./modules/BackButton";
import { LocalizationContext, useLocalization } from "../includes/LocalizationProvider";

const PrinterSettingsModal = ({ isVisible, setIsVisible, printer, isSmallTablet, defaultTabKey = "details" }) => {
    const theme = useTheme();
    const localization = useLocalization();
    const { effectiveLanguage, t } = localization;

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
                    <TabsProvider key={`printer-settings-tabs:${effectiveLanguage}:${defaultTabKey}`} defaultIndex={defaultTabKey === "slicing" ? 2 : (defaultTabKey === "linking" ? 1 : 0)}>
                        <Tabs
                            style={{
                                backgroundColor: theme.colors.elevation.level1,
                                marginBottom: 16
                            }}
                            mode="scrollable"
                            tabHeaderStyle={{ alignSelf: 'center' }}
                            showLeadingSpace={false}
                        >
                            <TabScreen key={`details:${effectiveLanguage}`} label={t("settings.detailsTab")} icon="cog">
                                <LocalizationContext.Provider value={localization}>
                                    <PrinterSettingsModalDetails details={details} isLoading={loading} error={error} />
                                </LocalizationContext.Provider>
                            </TabScreen>
                            <TabScreen key={`linking:${effectiveLanguage}`} label={t("settings.linkingTab")} icon="link">
                                <LocalizationContext.Provider value={localization}>
                                    <PrinterSettingsModalLinking details={details} isLoading={loading} error={error} printer={printer} />
                                </LocalizationContext.Provider>
                            </TabScreen>
                            <TabScreen key={`slicing:${effectiveLanguage}`} label={t("settings.slicing.tab")} icon="layers-triple-outline">
                                <LocalizationContext.Provider value={localization}>
                                    <PrinterSettingsModalSlicing printer={printer} />
                                </LocalizationContext.Provider>
                            </TabScreen>
                        </Tabs>
                    </TabsProvider>
                </Modal>
            </SnackbarProvider>
        </Portal>
    );
}

export default PrinterSettingsModal;
