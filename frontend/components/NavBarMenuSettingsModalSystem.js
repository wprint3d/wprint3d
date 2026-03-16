import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useEffect, useState } from "react";
import { Button, FAB, Icon, List, Portal, Text, useTheme } from "react-native-paper"
import API from "../includes/API";
import UserPaneLoadingIndicator from "./UserPaneLoadingIndicator";
import NavBarMenuSettingsModalSystemItem from "./NavBarMenuSettingsModalSystemItem";
import { InteractionManager, View } from "react-native";
import SimpleDialog from "./SimpleDialog";
import { useEcho } from "../hooks/useEcho";
import NavBarMenuSystemUpdater from "./NavBarMenuSystemUpdater";
import { useLocalization } from "../includes/LocalizationProvider";
import {
    localizeSystemEnumOptions,
    localizeSystemSettingDefinition,
} from "../utils/systemSettingsLocalization";

const NavBarMenuSettingsModalSystem = ({ isSmallTablet, isSmallLaptop, enqueueSnackbar, checkForUpdatesMutation }) => {
    const theme = useTheme();
    const { t } = useLocalization();

    const [ settings,     setSettings     ] = useState({}),
          [ hasChanges,   setHasChanges   ] = useState(false),
          [ enumNames,    setEnumNames    ] = useState([]),
          [ enumOptions,  setEnumOptions  ] = useState({}),
          [ isFabOpen,    setIsFabOpen    ] = useState(false);

    const queryClient = useQueryClient();

    const dataTypesList = useQuery({
        queryKey: ['dataTypes'],
        queryFn:  () => API.get('/data/types')
    });

    const DATA_TYPES = dataTypesList?.data?.data;

    const configList = useQuery({
        queryKey: ['configList'],
        queryFn:  () => API.get('/config')
    });

    const enumList = useQuery({
        queryKey: ['enumList'],
        queryFn:  () => API.post('/enum/batch', { classes: enumNames }),
        enabled:  configList.isSuccess && dataTypesList.isSuccess && enumNames.length > 0
    });

    const saveChangeMutation = useMutation({
        mutationFn: (key) => API.put(`/config/${key}`, { value: settings[key].value }),
        onMutate:   (key) => {
            console.debug('NavBarMenuSettingsModalSystem: saveChangeMutation: onMutate:', key);
        },
        onSuccess: (response, key) => {
            console.debug('NavBarMenuSettingsModalSystem: saveChangeMutation: onSuccess:', response, key);

            setSettings({
                ...settings,
                [key]: {
                    ...settings[key],
                    initialValue: settings[key].value,
                    value:        settings[key].value
                }
            });

            queryClient.invalidateQueries({ queryKey: ['developerMode'] });
        },
        onError: (error, key) => {
            console.error('NavBarMenuSettingsModalSystem: saveChangeMutation: onError:', error, key);

            enqueueSnackbar({
                message: t("settings.saveSettingError", {
                    name: settings[key].hint,
                    reason: (error?.response?.data?.message ?? error.message).toLowerCase(),
                }),
                variant: 'error',
                action:  { label: t("notifications.gotIt") }
            });
        },
    });

    const saveChanges = () => {
        console.debug('NavBarMenuSettingsModalSystem: Save changes:', settings);

        Object.keys(settings).forEach((key) => {
            if (settings[key].initialValue === settings[key].value) { return; }

            saveChangeMutation.mutate(key);
        });
    };

    useEffect(() => {
        console.debug('NavBarMenuSettingsModalSystem: dataTypesList:', dataTypesList);
    }, [ dataTypesList.data ]);

    useEffect(() => {
        console.debug('NavBarMenuSettingsModalSystem: configList (useEffect => configList):', configList);

        if (!configList.isSuccess) { return; }

        const nextSettings = configList?.data?.data;

        if (!nextSettings) { return; }

        Object.keys(nextSettings).forEach((key) => {
            if (!(nextSettings[key].visible ?? true)) {
                delete nextSettings[key];

                return;
            }

            nextSettings[key] = localizeSystemSettingDefinition(nextSettings[key], t);
            nextSettings[key].initialValue = nextSettings[key].value;
            nextSettings[key].value        = settings[key]?.value ?? nextSettings[key].value;
        });

        setSettings(nextSettings);

        setEnumNames(
            Object.keys(nextSettings).filter((key) => nextSettings[key].type === DATA_TYPES?.ENUM).map(
                (key) => nextSettings[key].enum
            )
        );
    }, [ configList.data, DATA_TYPES, t ]);

    useEffect(() => {
        console.debug('NavBarMenuSettingsModalSystem: enumList:', enumList);

        if (!enumList.isSuccess) { return; }

        const nextEnumOptions = {};
    
        Object.keys(enumList?.data?.data).forEach((key) => {
            nextEnumOptions[key] = localizeSystemEnumOptions(key, enumList?.data?.data[key], t);
        });

        setEnumOptions(nextEnumOptions);
    }, [ enumList.data, t ]);

    useEffect(() => {
        console.debug('NavBarMenuSettingsModalSystem: enumOptions (useEffect => enumOptions):', enumOptions);
    }, [ enumOptions ]);

    useEffect(() => {
        console.debug('NavBarMenuSettingsModalSystem: enumNames (useEffect => enumNames):', enumNames);
    }, [ enumNames ]);

    useEffect(() => {
        console.debug('NavBarMenuSettingsModalSystem: settings (useEffect => settings):', settings);

        setHasChanges(
            Object.keys(settings).some((key) => settings[key].initialValue !== settings[key].value)
        );
    }, [ settings ]);

    useEffect(() => {
        if (hasChanges || !saveChangeMutation.isSuccess) { return; }

        enqueueSnackbar({
            message: t("settings.saveChangesSuccess"),
            variant: 'success',
            action:  { label: t("notifications.gotIt") }
        });
    }, [ hasChanges, saveChangeMutation.isSuccess, t ]);

    if (dataTypesList.isFetching) {
        return <UserPaneLoadingIndicator message={t("settings.loadingDataTypes")}      />;
    }

    if (configList.isFetching) {
        return <UserPaneLoadingIndicator message={t("settings.loadingSystemSettings")} />;
    }

    if (enumList.isFetching) {
        return <UserPaneLoadingIndicator message={t("settings.loadingEnumOptions")}    />;
    }

    let sections = {};

    Object.keys(settings).forEach((key, index) => {
        const setting = settings[key];

        console.debug('NavBarMenuSettingsModalSystem: setting:', setting);

        if (!sections[setting.section]) {
            sections[setting.section] = {};
        }

        sections[setting.section][key] = setting;
    });

    return (
        <>
            {
                Object.keys(sections).map((section, index) => (
                    <List.Section key={index} title={section}>
                        <View style={{ flexDirection: 'row', flexWrap: 'wrap' }}>
                            {Object.keys(sections[section]).map((key, index) => (
                                <NavBarMenuSettingsModalSystemItem
                                    key={index}
                                    setting={sections[section][key]}
                                    dataTypes={DATA_TYPES}
                                    isSmallTablet={isSmallTablet}
                                    isSmallLaptop={isSmallLaptop}
                                    enqueueSnackbar={enqueueSnackbar}
                                    disabled={saveChangeMutation.isPending}
                                    options={enumOptions[ sections[section][key].enum ] ?? []}
                                    onChange={(key, value) => {
                                        InteractionManager.runAfterInteractions(() => {
                                            console.debug('NavBarMenuSettingsModalSystem: onChange: key:', key, 'value:', value);

                                            setSettings(settings => {
                                                settings[key].value = value;

                                                return { ...settings };
                                            });
                                        });
                                    }}
                                />
                            ))}
                        </View>
                    </List.Section>
                ))
            }

            <Portal>
                <FAB.Group
                    open={isFabOpen}
                    visible={true}
                    icon={isFabOpen ? 'close' : 'plus'}
                    label={t("settings.options")}
                    actions={[
                        {
                            icon: 'update',
                            style: {
                                backgroundColor:
                                    theme.dark
                                        ? theme.colors.onPrimary
                                        : theme.colors.primary
                            },
                            label: t("settings.checkForUpdates"),
                            onPress: () => {
                                console.debug('Check for updates');

                                setIsFabOpen(false);

                                checkForUpdatesMutation.mutate();
                            }
                        },
                        {
                            icon: 'content-save',
                            style: {
                                backgroundColor:
                                    theme.dark
                                        ? theme.colors.onPrimary
                                        : theme.colors.primary
                            },
                            label: t("settings.saveChanges"),
                            onPress: () => {
                                console.debug('Save changes');

                                if (!hasChanges || saveChangeMutation.isPending) {
                                    if (!hasChanges) {
                                        enqueueSnackbar({
                                            message: t("settings.nothingToDo"),
                                            variant: 'info',
                                            action:  { label: t("notifications.gotIt") }
                                        });
                                    }

                                    return;
                                }

                                setIsFabOpen(false);

                                saveChanges();
                            },
                        }
                    ]}
                    onStateChange={({ open }) => {
                        console.debug('FAB.Group: onStateChange', open);

                        setIsFabOpen(open);
                    }}
                    backdropColor={
                        theme.dark
                            ? "rgba(0, 0, 0, 0.5)"
                            : "rgba(190, 190, 190, 0.5)"
                    }
                    onPress={() => setIsFabOpen(!isFabOpen)}
                    style={{ padding: isSmallTablet ? 16 : 64 }}
                />
            </Portal>
        </>
    );
}

export default NavBarMenuSettingsModalSystem;
