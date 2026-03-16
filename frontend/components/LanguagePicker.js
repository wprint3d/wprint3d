import { useState } from "react";
import { StyleSheet, View } from "react-native";
import { Button, Menu } from "react-native-paper";

import { AUTO_LANGUAGE } from "../utils/localization";
import { useLocalization } from "../includes/LocalizationProvider";

export default function LanguagePicker({ compact = false, style }) {
    const [menuVisible, setMenuVisible] = useState(false);
    const {
        effectiveLanguage,
        getLanguageOption,
        languageOptions,
        selectedLanguage,
        setSelectedLanguage,
        t,
    } = useLocalization();

    const effectiveOption = getLanguageOption(effectiveLanguage);
    const selectedOption = (
        selectedLanguage === AUTO_LANGUAGE
            ? languageOptions[0]
            : getLanguageOption(selectedLanguage)
    );

    return (
        <View style={[styles.container, style]}>
            <Menu
                visible={menuVisible}
                onDismiss={() => setMenuVisible(false)}
                anchor={(
                    <Button
                        mode={compact ? "text" : "outlined"}
                        onPress={() => setMenuVisible(true)}
                        contentStyle={styles.buttonContent}
                        style={compact ? styles.compactButton : styles.button}
                        labelStyle={styles.buttonLabel}
                        icon="translate"
                    >
                        {`${selectedOption.flag} ${
                            selectedLanguage === AUTO_LANGUAGE
                                ? t("language.autoDetect")
                                : selectedOption.nativeLabel
                        }`}
                    </Button>
                )}
            >
                {languageOptions.map((option) => {
                    const isAuto = option.value === AUTO_LANGUAGE;
                    const title = isAuto
                        ? `${option.flag} ${t("language.autoDetect")} (${t("language.detectedLabel")}: ${effectiveOption.nativeLabel})`
                        : `${option.flag} ${option.nativeLabel}`;

                    return (
                        <Menu.Item
                            key={option.value}
                            title={title}
                            trailingIcon={selectedLanguage === option.value ? "check" : undefined}
                            onPress={async () => {
                                setMenuVisible(false);
                                await setSelectedLanguage(option.value);
                            }}
                        />
                    );
                })}
            </Menu>
        </View>
    );
}

const styles = StyleSheet.create({
    container: {
        alignItems: "flex-end",
    },
    button: {
        width: "100%",
    },
    compactButton: {
        minWidth: 0,
    },
    buttonContent: {
        justifyContent: "flex-start",
        minHeight: 40,
    },
    buttonLabel: {
        marginVertical: 8,
    },
});
