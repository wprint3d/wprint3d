import { ActivityIndicator, Text, TouchableRipple, useTheme } from "react-native-paper";

export default function SmallButton({
    children,
    onPress,
    left       = null,
    right      = null,
    style      = {},
    textStyle  = {},
    loading    = false,
    disabled   = false,
    loaderSize = null
}) {
    const { colors } = useTheme();

    const dynamicLoaderSize = (
        loaderSize ??
            (left ? left.props.size :
                (right ? right.props.size : null)
            )
    );

    return (
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
                    loading
                        ? <ActivityIndicator
                            size={
                                left
                                    ? left.props.size
                                    : dynamicLoaderSize ?? 10
                            }
                            style={{ marginRight: 8 }}
                            animating={true}
                            color={colors.onPrimary}
                        />
                        : left
                }
                {children}
                {right}
            </Text>
        </TouchableRipple>
    );
}