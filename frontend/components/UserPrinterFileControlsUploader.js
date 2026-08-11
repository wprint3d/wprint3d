import { Icon, useTheme } from 'react-native-paper';

import SmallButton from './SmallButton';

import * as DocumentPicker from 'expo-document-picker';
import { useLocalization } from '../includes/LocalizationProvider';

export default function UserPrinterFileControlsUploader({ disabled = false, onFilesSelected }) {
  const { colors } = useTheme();
  const { t } = useLocalization();

  return (
    <>
      <SmallButton
        disabled={disabled}
        loading={disabled}
        onPress={() => {
          DocumentPicker.getDocumentAsync({
            multiple: true,
            type: ['text/plain', 'text/x-gcode', 'application/gzip', 'application/octet-stream'],
          }).then(({ assets, canceled, output }) => {
            console.debug('DocumentPicker.getDocumentAsync:', { assets, canceled, output });

            if (canceled || !assets.length) { return; }

            onFilesSelected(assets.map(item => item.file));
          });
        }}
        right={
          <Icon
            source='upload'
            color={colors.onPrimary}
            size={16}
          />
        }
      > {t("files.upload")} </SmallButton>
    </>
  );
};
