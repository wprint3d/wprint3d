import { TabScreen, Tabs, TabsProvider } from "react-native-paper-tabs";
import UserPane                 from "./UserPane";
import UserPaneLoadingIndicator from "./UserPaneLoadingIndicator";

import { Text, useTheme } from "react-native-paper";
import UserPrinterTerminal from "./UserPrinterTerminal";

export default function UserRightPane({ selectedPrinter, maxHeight }) {
    const { colors } = useTheme();

    return (
        <UserPane style={{
            display:    'flex',
            flexGrow:   '1',
            maxHeight:  maxHeight,
            height:     maxHeight,
            width:      '1%' // NOTE: I have no idea why this works but I'm not willing to ask any further questions.
        }}>
            {
                selectedPrinter.isFetching
                    ? <UserPaneLoadingIndicator message={"Preparing actions menu"} />
                    : <TabsProvider defaultIndex={0}>
                        <Tabs
                            style={{ backgroundColor: colors.background }}
                            mode="scrollable"
                            showLeadingSpace={false}
                        // uppercase={false} // true/false | default=true (on material v2) | labels are uppercase
                        // showTextLabel={false} // true/false | default=false (KEEP PROVIDING LABEL WE USE IT AS KEY INTERNALLY + SCREEN READERS)
                        // iconPosition // leading, top | default=leading
                        // style={{ backgroundColor:'#fff' }} // works the same as AppBar in react-native-paper
                        // dark={false} // works the same as AppBar in react-native-paper
                        // theme={} // works the same as AppBar in react-native-paper
                        //  (default=true) show leading space in scrollable tabs inside the header
                        // disableSwipe={false} // (default=false) disable swipe to left/right gestures
                        >
                            <TabScreen label="Terminal" icon="console">
                                <UserPrinterTerminal selectedPrinter={selectedPrinter} />
                            </TabScreen>
                            <TabScreen label="Preview" icon="eye">
                                <Text>test</Text>
                            </TabScreen>
                            <TabScreen label="Control" icon="camera-control">
                                <Text>test</Text>
                            </TabScreen>
                            <TabScreen label="Recordings" icon="record-circle-outline">
                                <Text>test</Text>
                            </TabScreen>
                        </Tabs>
                      </TabsProvider>
            }
        </UserPane>
    )
}