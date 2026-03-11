import { Appbar, Tooltip, useTheme } from "react-native-paper";

export default function AppbarActionWithTooltip({
    title,
    icon,
    onPress  = () => {},
    disabled = false,
    loading  = false
}) {
    const { colors } = useTheme();

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
                    ),
                    backgroundColor: (
                        disabled
                            ? colors.disabled
                            : colors.primary
                    )
                }}
                loading={loading}
                color={
                    disabled
                        ? colors.disabled
                        : colors.onPrimary
                }
            />
        </Tooltip>
    );
}