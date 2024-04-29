import { Appbar, Tooltip } from "react-native-paper";

export default function AppbarActionWithTooltip({
    title,
    icon,
    onPress  = () => {},
    disabled = false,
    loading  = false
}) {
    return (
        <Tooltip title={title} enterTouchDelay={100} leaveTouchDelay={100}>
            <Appbar.Action
                icon={icon}
                onPress={onPress}
                style={{
                    opacity: (
                        disabled
                            ? .5
                            : 1
                    )
                }}
                loading={loading}
            />
        </Tooltip>
    );
}