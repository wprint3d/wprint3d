import { Button, Icon } from "react-native-paper";

const BackButton = ({ onPress }) => (
    <Button onPress={onPress} style={{
        position: "absolute",
        top: 20,
        left: 12,
        zIndex: 99999
    }}>
        <Icon source="arrow-left" size={20} />
    </Button>
);

export default BackButton;