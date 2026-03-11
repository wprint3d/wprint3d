import UserPrinterStatusExtruder from "./UserPrinterStatusExtruder";

export default function UserPrinterStatusExtruders({ connectionStatus }) {
    if (!connectionStatus?.statistics) { return <></>; }

    const { extruders } = connectionStatus?.statistics;

    if (!extruders) { return <></>; }

    return extruders.map((extruder, index) => (
        <UserPrinterStatusExtruder key={index} index={index} extruder={extruder} />
    ));
}