import { useQuery } from "@tanstack/react-query";
import { useEffect, useState } from "react";
import { View } from "react-native";
import NavBarMenuSettingsModalPresetsItem from "./NavBarMenuSettingsModalPresetsItem";
import API from "../includes/API";
import UserPaneLoadingIndicator from "./UserPaneLoadingIndicator";
import NavBarMenuSettingsModalPlaceholderItem from "./NavBarMenuSettingsModalPlaceholderItem";
import NavBarMenuSettingsModalPresetsEditDialog from "./NavBarMenuSettingsModalPresetsEditDialog";
import { Button, FAB } from "react-native-paper";

const NavBarMenuSettingsModalPresets = ({ isSmallTablet, isSmallLaptop, enqueueSnackbar }) => {
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
        return <UserPaneLoadingIndicator message={`Loading materials list...`} />;
    }

    return (
        <>
            {materials.length === 0 && !materialsList.isFetching
                ? <NavBarMenuSettingsModalPlaceholderItem
                    icon="printer-3d-nozzle"
                    message="No materials available"
                    actions={
                        <Button
                            mode="contained"
                            icon="plus"
                            onPress={() => setShowEditDialog(true)}
                        >
                            Add
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

                        <FAB
                            icon="plus"
                            label="Add preset"
                            variant="primary"
                            style={{
                                position: 'fixed',
                                margin: 16,
                                right: 48,
                                bottom: 32
                            }}
                            onPress={() => setShowEditDialog(true)}
                        />
                    </View>
                )
            }
            <NavBarMenuSettingsModalPresetsEditDialog setVisible={setShowEditDialog} visible={showEditDialog} material={selectedMaterial} />
        </>
    );
}

export default NavBarMenuSettingsModalPresets;