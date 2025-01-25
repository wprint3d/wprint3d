import { useQuery } from "@tanstack/react-query";
import { Text } from "react-native-paper";
import UserPaneLoadingIndicator from "./UserPaneLoadingIndicator";
import NavBarMenuSettingsModalCamerasItem from "./NavBarMenuSettingsModalCamerasItem";
import NavBarMenuSettingsModalPlaceholderItem from "./NavBarMenuSettingsModalPlaceholderItem";
import { useEffect, useState } from "react";
import API from "../includes/API";
import CameraSettingsModal from "./CameraSettingsModal";

const NavBarMenuSettingsModalCameras = ({ isSmallTablet, isSmallLaptop, enqueueSnackbar }) => {
  const allCameras = useQuery({
    queryKey: ['cameras'],
    queryFn:  () => API.get('/cameras')
  });

  const [ cameras, setCameras ] = useState([]);

  const [ showSettingsModal,  setShowSettingsModal ] = useState(false);
  const [ selectedCamera,     setSelectedCamera    ] = useState(null);

  const handleEditModal = (camera) => {
    setSelectedCamera(camera);
    setShowSettingsModal(true);
  };

  useEffect(() => {
    console.debug('NavBarMenuSettingsModalCameras: allCameras:', allCameras);

    if (!allCameras.isSuccess) {
      setCameras([]);

      return;
    }

    setCameras(allCameras?.data?.data);
  }, [ allCameras ]);

  if (allCameras.isFetching) {
    return <UserPaneLoadingIndicator message={`Loading cameras list...`} />;
  }

  return (
    <>
      {cameras.length === 0 && !allCameras.isFetching
        ? <NavBarMenuSettingsModalPlaceholderItem
              icon="camera-off"
              message="No cameras were detected"
              troubleshootingOptions={[
                'Reset the USB controller.',
                'Re-seat the camera\'s USB plug into the port.',
                'If it\'s a CSI camera, ensure that the flex cable works properly.',
                'Restart the host.',
              ]}
          />
        : cameras.map(camera => (
          <NavBarMenuSettingsModalCamerasItem
            key={camera._id}
            camera={camera}
            isLoading={allCameras.isLoading}
            handleSettingsModal={handleEditModal}
            enqueueSnackbar={enqueueSnackbar}
            isSmallLaptop={isSmallLaptop}
            isSmallTablet={isSmallTablet}
          />
        ))
      }
      {selectedCamera &&
        <CameraSettingsModal
            key={selectedCamera._id}
            isVisible={showSettingsModal}
            setIsVisible={setShowSettingsModal}
            isSmallTablet={isSmallTablet}
            camera={selectedCamera}
            enqueueSnackbar={enqueueSnackbar}
        />
      }
    </>
  );
}

export default NavBarMenuSettingsModalCameras;