import { View } from "react-native";

import UserPrinterStatusConnection  from "./UserPrinterStatusConnection";
import UserPrinterStatusExtruders   from "./UserPrinterStatusExtruders";
import UserPrinterStatusBed         from "./UserPrinterStatusBed";

export default function UserPrinterStatus({ connectionStatus, isRunningMapper }) {
    return (
        <>
            <UserPrinterStatusConnection connectionStatus={connectionStatus} isRunningMapper={isRunningMapper} />

            <View style={{
                display:        'flex',
                flexDirection:  'row',
                justifyContent: 'space-evenly'
            }}>
                <UserPrinterStatusExtruders connectionStatus={connectionStatus} />
            </View>

            <UserPrinterStatusBed connectionStatus={connectionStatus} />
        </>
    );
}