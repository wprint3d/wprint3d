import { ActivityIndicator, Text, Tooltip, TouchableRipple, useTheme } from "react-native-paper";

export default function SmallButton({
    children,
    onPress,
    left        = null,
    right       = null,
    style       = {},
    textStyle   = {},
    loading     = false,
    disabled    = false,
    tooltipText = null,
    loaderSize  = null
}) {
    const { colors } = useTheme();

    const MARGIN_HORIZONTAL = 4;

    const acitivtyIndicator = <ActivityIndicator
        size={ loaderSize ?? 14 }
        style={
            (left || (!left && !right))
                ? { marginRight: MARGIN_HORIZONTAL }
                : { marginLeft:  MARGIN_HORIZONTAL }
        }
        animating={true}
        color={colors.onPrimary}
    />;

    const button = (
        <TouchableRipple
            disabled={loading || disabled}
            onPress={() => {
                if (loading || disabled) { return null; }

                return onPress();
            }}
            style={{
                backgroundColor: colors.primary,
                borderRadius: 8,
                padding: 12,
                display: 'flex',
                opacity: (loading || disabled ? .5 : 1),
                ...style
            }}
        >
            <Text style={{
                display: 'flex',
                color:   colors.onPrimary,
                ...textStyle
            }}>
                {
                    loading && (left || (!left && !right))
                        ? acitivtyIndicator
                        : left
                }
                {children}
                {
                    loading && right
                        ? acitivtyIndicator
                        : right
                }
            </Text>
        </TouchableRipple>
    );

    if (tooltipText && (!loading && !disabled)) {
        return (
            <Tooltip
                title={tooltipText}
                enterTouchDelay={0}
                leaveTouchDelay={0}
            >
                {button}
            </Tooltip>
        );
    }

    return button;
}