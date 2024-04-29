import { Text } from "react-native-paper";

export default function TextBold({ children }, ...props) {
    let style = [];

    if (typeof props.style !== 'undefined') {
        style.push(props.style);
    }

    style.push({ fontWeight: 'bold' });

    return (
        <Text style={style} {...props}>
            {children}
        </Text>
    );
}