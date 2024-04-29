import { useEffect, useState } from "react";
import { View } from "react-native";
import { Dialog, PaperProvider, Portal } from "react-native-paper";

export default function SimpleDialog({ visible, setVisible, title, content, actions }) {
  const hideDialog = () => setVisible(false);

  return (
    <Portal>
      <Dialog
        visible={visible}
        onDismiss={hideDialog}
        style={{ maxWidth: 800, alignSelf: 'center' }}
      >
        <Dialog.Title>{title}</Dialog.Title>
        <Dialog.Content>{content}</Dialog.Content>
        <Dialog.Actions>{actions}</Dialog.Actions>
      </Dialog>
    </Portal>
  );
}