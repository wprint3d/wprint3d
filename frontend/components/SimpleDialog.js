import { useEffect } from "react";
import { View } from "react-native";
import { Portal, Text, useTheme } from "react-native-paper";

export default function SimpleDialog({ visible, setVisible = () => {}, title, content, actions, left = null, style = { maxWidth: 800 }, onDismiss = null }) {
  const theme = useTheme();
  const hideDialog = () => {
    if (typeof onDismiss === "function") {
      onDismiss();
      return;
    }

    setVisible(false);
  };

  useEffect(() => {
    if (!visible || typeof window === "undefined") {
      return undefined;
    }

    const handleKeyDown = (event) => {
      if (event.key === "Escape") {
        if (typeof onDismiss === "function") {
          onDismiss();
          return;
        }

        hideDialog();
      }
    };

    window.addEventListener("keydown", handleKeyDown);

    return () => window.removeEventListener("keydown", handleKeyDown);
  }, [ visible, onDismiss, setVisible ]);

  if (!visible) {
    return null;
  }

  return (
    <Portal>
      <View
        style={{
          flex: 1,
          backgroundColor: "rgba(0, 0, 0, 0.45)",
          justifyContent: "center",
          alignItems: "center",
          padding: 16,
        }}
      >
        <View
          style={[{
            width: "90%",
            maxWidth: 800,
            borderRadius: 24,
            backgroundColor: theme.colors.elevation.level3,
            paddingHorizontal: 24,
            paddingVertical: 20,
          }, style]}
        >
          <View style={{ marginBottom: 16, flexDirection: "row", alignItems: "center" }}>
            {left}
            <Text variant="headlineSmall" style={left ? { marginLeft: 8, flexShrink: 1 } : { flexShrink: 1 }}>
              {title}
            </Text>
          </View>
          <View style={{ marginBottom: 16 }}>
            {content}
          </View>
          <View style={{ flexDirection: "row", justifyContent: "flex-end", flexWrap: "wrap", gap: 8 }}>
            {actions}
          </View>
        </View>
      </View>
    </Portal>
  );
}
