import UserPrinterStatusExtruder from "./UserPrinterStatusExtruder";

export default function UserPrinterStatusExtruders({ connectionStatus }) {
    if (
        !connectionStatus.isFetched || !connectionStatus.isSuccess
        ||
        typeof connectionStatus.data.data.statistics === 'undefined'
    ) { return <></>; }

    const { extruders } = connectionStatus.data.data.statistics;

    if (typeof extruders === 'undefined') { return <></>; }

    return extruders.map((extruder, index) => (
        <UserPrinterStatusExtruder key={index} index={index} extruder={extruder} />
    ));
}