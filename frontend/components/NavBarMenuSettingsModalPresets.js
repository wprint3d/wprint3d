import { useQuery } from "@tanstack/react-query";
import { useEffect, useState } from "react";
import { View } from "react-native";
import NavBarMenuSettingsModalPresetsItem from "./NavBarMenuSettingsModalPresetsItem";
import API from "../includes/API";
import UserPaneLoadingIndicator from "./UserPaneLoadingIndicator";
import NavBarMenuSettingsModalPlaceholderItem from "./NavBarMenuSettingsModalPlaceholderItem";
import NavBarMenuSettingsModalPresetsEditDialog from "./NavBarMenuSettingsModalPresetsEditDialog";
import { Button, FAB, Portal } from "react-native-paper";
import { useLocalization } from "../includes/LocalizationProvider";

const NavBarMenuSettingsModalPresets = ({ isSmallTablet, isSmallLaptop, enqueueSnackbar }) => {
    const { t } = useLocalization();
    const materialsList = useQuery({
        queryKey: ['materials'],
        queryFn:  () => API.get('/user/materials')
    });

    const [ materials, setMaterials ] = useState([]);

    const [ showEditDialog,   setShowEditDialog   ] = useState(false);
    const [ selectedMaterial, setSelectedMaterial ] = useState(null);

    const handleEditModal = (material) => {
        setSelectedMaterial(material);
        setShowEditDialog(true);
    };

    useEffect(() => {
        if (!materialsList.isSuccess) {
            setMaterials([]);

            return;
        }

        setMaterials(materialsList?.data?.data);

        console.debug('NavBarMenuSettingsModalPresets: materials:', materials);
    }, [ materialsList ]);

    useEffect(() => {
        if (showEditDialog) { return; }

        setSelectedMaterial(null);
    }, [ showEditDialog ]);

    if (materialsList.isFetching) {
        return <UserPaneLoadingIndicator message={t("presets.loadingMaterials")} />;
    }

    return (
        <>
            {materials.length === 0 && !materialsList.isFetching
                ? <NavBarMenuSettingsModalPlaceholderItem
                    icon="printer-3d-nozzle"
                    message={t("presets.noMaterials")}
                    actions={
                        <Button
                            mode="contained"
                            icon="plus"
                            onPress={() => setShowEditDialog(true)}
                        >
                            {t("presets.add")}
                        </Button>
                    }
                />
                : (
                    <View style={{ flexGrow: 1 }}>
                        <View style={{ flexDirection: 'row', flexWrap: 'wrap' }}>
                            {
                                materials.map(material => (
                                    <NavBarMenuSettingsModalPresetsItem
                                        key={material._id}
                                        material={material}
                                        isSmallLaptop={isSmallLaptop}
                                        isSmallTablet={isSmallTablet}
                                        enqueueSnackbar={enqueueSnackbar}
                                        handleEditModal={handleEditModal}
                                    />
                                ))
                            }
                        </View>
                    </View>
                )
            }

            <Portal>
                <FAB
                    icon="plus"
                    visible={
                        materials.length !== 0 && !materialsList.isFetching
                        &&
                        !showEditDialog
                    }
                    label={t("presets.addPreset")}
                    variant="primary"
                    style={{
                        position: 'absolute',
                        bottom: 0,
                        right: 0,
                        marginVertical:     isSmallTablet ? 32 : 64,
                        marginHorizontal:   isSmallTablet ? 32 : 80
                    }}
                    onPress={() => setShowEditDialog(true)}
                />
            </Portal>

            <NavBarMenuSettingsModalPresetsEditDialog setVisible={setShowEditDialog} visible={showEditDialog} material={selectedMaterial} />
        </>
    );
}

export default NavBarMenuSettingsModalPresets;
