import { useEffect, useState } from "react";
import { ToggleButton, Tooltip } from "react-native-paper";

const TooltipToggleButton = ({
    tooltip = null,
    disabledTooltip = null,
    icon = 'check',
    disabledIcon = null,
    isChecked = false,
    checkedColor = null,
    disabled = false,
    onPress = () => {}
}) => {
    const [ nextCheckedState, setNextCheckedState ] = useState(isChecked);

    useEffect(() => {
        setNextCheckedState(isChecked);

        console.debug('TooltipToggleButton: isChecked:', isChecked);
    }, [ isChecked ]);

    return (
        <Tooltip title={
            nextCheckedState
                ? (tooltip          ?? 'Disable')
                : (disabledTooltip  ?? 'Enable')
        }>
            <ToggleButton
                icon={nextCheckedState ? icon : (disabledIcon ?? icon)}
                iconColor={nextCheckedState && !disabled ? checkedColor : null}
                onPress={() => {
                    onPress({ wasChecked: nextCheckedState });

                    setNextCheckedState(!nextCheckedState);
                }}
                status={nextCheckedState ? 'checked' : 'unchecked'}
                disabled={disabled}
            />
        </Tooltip>
    );
}

export default TooltipToggleButton;