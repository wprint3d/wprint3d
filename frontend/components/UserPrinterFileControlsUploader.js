import { useState } from 'react';

import { Menu, Icon, useTheme } from 'react-native-paper';

import SmallButton from './SmallButton';

import * as DocumentPicker from 'expo-document-picker';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useSnackbar } from 'react-native-paper-snackbar-stack';
import API from '../includes/API';

export default function UserPrinterFileControlsUploader({ subDirectory, setSelectedFileName }) {
  const { colors } = useTheme();

  const { enqueueSnackbar } = useSnackbar();

  const queryClient = useQueryClient();

  const fileUploadMutation = useMutation({
    mutationFn:  body => API.post('/user/file/upload', body),
    onSuccess:   result => {
      console.debug('fileUploadMutation onSuccess:', result);

      queryClient.invalidateQueries({ queryKey: ['fileList'] });

      const uploadedFileNames = result?.data;

      if (uploadedFileNames?.length === 1) {
        setSelectedFileName(uploadedFileNames[0]);
      }
    },
    onError:     (error) => {
      console.error('fileUploadMutation onError:', error);

      enqueueSnackbar({
        message: 'Failed to upload file: ' + (error.response?.data?.message ?? error.message).toLowerCase(),
        variant: 'error',
        action:  { label: 'Got it' }
      });
    }
  });

  return (
    <>
      <SmallButton
        disabled={fileUploadMutation.isPending}
        loading={fileUploadMutation.isPending}
        onPress={() => {
          DocumentPicker.getDocumentAsync({ multiple: true }).then(({ assets, canceled, output }) => {
            console.debug('DocumentPicker.getDocumentAsync:', { assets, canceled, output });

            if (canceled || !assets.length) { return; }

            fileUploadMutation.mutate({
              subDirectory: subDirectory,
              files:        assets.map(item => item.file)
            });
          });
        }}
        right={
          <Icon
            source='upload'
            color={colors.onPrimary}
            size={16}
          />
        }
      > Upload </SmallButton>
    </>
  );
};