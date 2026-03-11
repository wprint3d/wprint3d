import { PaperProvider } from "react-native-paper";

import Theme from "./includes/Theme";

export default function ViewWrapper({ children }) {
    return (
        <PaperProvider theme={Theme()}>
            {children}
        </PaperProvider>
    );
}