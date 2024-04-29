import { useWindowDimensions } from "react-native";

import UserPane                      from "./UserPane";
import UserPaneLoadingIndicator      from "./UserPaneLoadingIndicator";
import UserPrinterPicker             from "./UserPrinterPicker";
import UserPrinterStatusWrapper      from "./UserPrinterStatusWrapper";
import UserPrinterCameras            from "./UserPrinterCameras";
import UserPrinterTemperaturePresets from "./UserPrinterTemperaturePresets";
import UserPrinterFileControls       from "./UserPrinterFileControls";
import { Banner } from "react-native-paper";

export default function UserLeftPane({ selectedPrinter, maxHeight }) {
    console.debug('UserLeftPane: selectedPrinter:', selectedPrinter);

    const windowWidth = useWindowDimensions().width;

    console.debug('windowWidth:', windowWidth);

    let width = '30%';

    if (windowWidth        <= 425)  {   // mobile large
        width = '100%';
    } else if (windowWidth <= 768)  {   // small tablet
        width = '50%';
    } else if (windowWidth <= 1024) {   // small laptop
        width = '45%';
    } else if (windowWidth <= 1440) {   // medium laptop
        width = '40%';
    } else if (windowWidth <= 1600) {   // small desktop
        width = '35%';
    }

    let paneComponents = [];

    if (selectedPrinter.isSuccess && selectedPrinter.data.data.length > 0) {
        paneComponents.push(<UserPrinterStatusWrapper       key={paneComponents.length} />);
        paneComponents.push(<UserPrinterTemperaturePresets  key={paneComponents.length} />);
        paneComponents.push(<UserPrinterCameras             key={paneComponents.length} />);
        paneComponents.push(<UserPrinterFileControls        key={paneComponents.length} />);
    }

    return (
        <UserPane style={{
            width:      width,
            maxHeight:  maxHeight,
            height:     maxHeight,
            overflow:   'auto'
        }}>
            {
                selectedPrinter.isFetching
                    ? <UserPaneLoadingIndicator message={"Getting printer information"} />
                    : <>
                        <UserPrinterPicker selectedPrinter={selectedPrinter} />
                        {paneComponents}
                      </>
            }
        </UserPane>
    );
}