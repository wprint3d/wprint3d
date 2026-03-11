import { useEffect, useState } from "react";

import { View } from "react-native";

import { List, Text, TextInput, Tooltip, useTheme } from "react-native-paper";

import DropDown from "react-native-paper-dropdown";

const NavBarMenuSettingsModalSystemItem = ({ setting, dataTypes, isSmallTablet, isSmallLaptop, enqueueSnackbar, disabled, options, onChange = () => {} }) => {
    console.debug('NavBarMenuSettingsModalSystemItem: setting:', setting);

    const { colors } = useTheme();

    const [ showDropDown, setShowDropDown ] = useState(false),
          [ value,        setValue        ] = useState(setting.initialValue),
          [ isValid,      setIsValid      ] = useState(true);

    useEffect(() => setValue(setting.initialValue), [setting.initialValue]);

    const handleValueChange = (nextValue) => {
        if (!setting.writeable) { return; }

        setValue(nextValue);

        if (setting.type === dataTypes.INTEGER || setting.type === dataTypes.ENUM) {
            nextValue = parseInt(nextValue);
        } else if (setting.type === dataTypes.FLOAT) {
            nextValue = parseFloat(nextValue);
        } else if (setting.type !== dataTypes.BOOLEAN) {
            nextValue = nextValue.toString();
        }

        console.debug(`NavBarMenuSettingsModalSystemItem: handleValueChange (${setting.type}): nextValue: ${nextValue}`);

        onChange(setting.key, nextValue);

        setIsValid(
            ((setting.type === dataTypes.INTEGER || setting.type === dataTypes.ENUM) && !isNaN(nextValue)) ||
            (setting.type === dataTypes.FLOAT   && !isNaN(nextValue))               ||
            (setting.type === dataTypes.BOOLEAN && typeof nextValue === 'boolean')  ||
            (setting.type === dataTypes.STRING  && nextValue.length > 0)
        );
    };

    const RevertButton = () => {
        console.debug(`NavBarMenuSettingsModalSystemItem: RevertButton: ${setting.key}: setting.value: ${value}, setting.initialValue: ${setting.initialValue}`);

        if (setting.initialValue === value) { return null; }

        return (
            <TextInput.Icon
                icon='undo-variant'
                onPress={() => handleValueChange(setting.initialValue)}
                disabled={disabled}
            />
        );
    };

    const getInput = () => {
        if (setting.type === dataTypes.BOOLEAN) {
            return (
                <DropDown
                    label=" "
                    mode="outlined"
                    value={value}
                    setValue={handleValueChange}
                    showDropDown={() => !disabled && setShowDropDown(true)}
                    onDismiss={() => setShowDropDown(false)}
                    visible={showDropDown}
                    list={[
                        { label: 'Yes', value: true  },
                        { label: 'No',  value: false }
                    ]}
                    inputProps={{
                        right: RevertButton(),
                        outlineColor:       (isValid ? colors.onSurfaceVariant : colors.error),
                        activeOutlineColor: (isValid ? colors.onSurfaceVariant : colors.error)
                    }}
                />
            );
        }

        if (setting.type === dataTypes.ENUM) {
            const list = options.map((option, index) => ({ label: option, value: index }));

            return (
                <DropDown
                    label=" "
                    mode="outlined"
                    value={value}
                    setValue={handleValueChange}
                    showDropDown={() => (!disabled && list.length > 0) && setShowDropDown(true)}
                    onDismiss={() => setShowDropDown(false)}
                    visible={showDropDown}
                    list={list.length > 0 ? list : [{ label: 'No options available', value: -1 }]}
                    inputProps={{
                        right: RevertButton(),
                        outlineColor:       (isValid ? colors.onSurfaceVariant : colors.error),
                        activeOutlineColor: (isValid ? colors.onSurfaceVariant : colors.error)
                    }}
                />
            );
        }

        return (
            <TextInput
                label=" "
                mode="outlined"
                value={value}
                onChangeText={handleValueChange}
                readOnly={!setting.writeable}
                right={RevertButton()}
                activeOutlineColor={isValid ? colors.onSurfaceVariant : colors.error}
                outlineColor={isValid ? colors.onSurfaceVariant : colors.error}
                disabled={disabled}
            />
        );
    };

    return (
        <View key={setting.key} style={{
            paddingVertical: 8,
            justifyContent: 'space-between',
            width: (
                isSmallTablet
                    ? '100%'
                    : (
                        isSmallLaptop
                            ? '50%'
                            : '33.3333%'
                    )
            )
        }}>
            <List.Item
                title={setting.hint}
                description={
                    <Text style={{ color: colors.onSurfaceVariant }}>
                        {setting.description}
                        {!setting.writeable &&
                            <Text variant="bodySmall" style={{ color: colors.onSurfaceVariant }}>
                                {'\n\nThis setting is read-only.'}
                            </Text>
                        }
                    </Text>
                }
                descriptionNumberOfLines={10}
            />
            <View style={{ paddingHorizontal: 16 }}>
                {getInput(setting)}
            </View>
        </View>
    );
}

export default NavBarMenuSettingsModalSystemItem;
