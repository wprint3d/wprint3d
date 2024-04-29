import { Banner, Icon } from "react-native-paper";

export default function UserPrinterPickerNoPrintersBanner({ printersList }) {
    return (
        <Banner
            visible={true || printersList.isSuccess && printersList.data.data.length == 0}
            actions={[{
                label: 'Refresh',
                onPress: () => printersList.refetch()
            }]}
            icon="information"
        >
            To get started, plug a compatible printer and wait for a few seconds.
            {'\n'}
            {'\n'}
            Once the printer is ready to go, it'll be selected automatically.
        </Banner>
    );
}