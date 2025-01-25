import { useEffect } from "react";
import { Button, Icon, useTheme } from "react-native-paper";

const UserPrinterControlMovementButton = ({ styles, handleMovement, icon, lastDirection, targetDirection, distance, feedrate }) => {
    const { colors } = useTheme();

    useEffect(() => {
        console.debug('UserPrinterControlMovementButton: lastDirection:', lastDirection);
    }, [ lastDirection ]);

    return (
        <Button
            mode="contained" style={styles.controlButton}
            onPress={() => handleMovement({
                direction: targetDirection,
                distance: distance,
                feedrate: feedrate
            })}
            disabled={lastDirection === targetDirection}
        >
            <Icon color={
                lastDirection === targetDirection
                    ? colors.elevation.level5
                    : colors.onPrimary
                }
                size={24}
                source={icon}
            />
        </Button>
    );
}

export default UserPrinterControlMovementButton;