import { useEffect, useState } from "react";

import { ProgressBar, Text, useTheme } from "react-native-paper";

import { View } from "react-native";

import dayjs from "dayjs";
import duration from 'dayjs/plugin/duration';
import relativeTime from 'dayjs/plugin/relativeTime';
import { useLocalization } from "../includes/LocalizationProvider";

export default function UserPrinterFileProgress({ lastTerminalMessage }) {
    const { colors } = useTheme();
    const { t } = useLocalization();

    const [ lastActionMeaning,      setLastActionMeaning     ] = useState(null);
    const [ lastStopTimestampSecs,  setLastStopTimestampSecs ] = useState(null);
    const [ remainingTime,          setRemainingTime         ] = useState(t("files.aFewSeconds"));
    const [ clockHasTicked,         setClockHasTicked        ] = useState(false);

    useEffect(() => {
        dayjs.extend(duration);
        dayjs.extend(relativeTime);
    }, []);

    useEffect(() => {
        const interval = setInterval(() => {
            setClockHasTicked(prev => !prev);
        }, 1000);

        return () => clearInterval(interval);
    }, []);

    useEffect(() => {
        if (!lastStopTimestampSecs || lastTerminalMessage?.running === false) { return; }

        console.debug('UserPrinterFileProgress: lastStopTimestampSecs:', lastStopTimestampSecs);
        console.debug('UserPrinterFileProgress: clockHasTicked:', clockHasTicked);

        const remainingTime = dayjs(lastStopTimestampSecs * 1000).diff();

        console.debug('UserPrinterFileProgress: remainingTime:', remainingTime);

        if (remainingTime < 0) {
            setRemainingTime(t("files.aFewSeconds"));

            return;
        }

        const remainingTimeDuration = dayjs.duration(remainingTime);

        console.debug('UserPrinterFileProgress: remainingTimeDuration:', remainingTimeDuration);

        if (remainingTimeDuration.asDays() >= 1) {
            setRemainingTime(remainingTimeDuration.format('D[d] H[h] m[m] s[s]'));
        } else if (remainingTimeDuration.asHours() > 1) {
            setRemainingTime(remainingTimeDuration.format('H[h] m[m] s[s]'));
        } else if (remainingTimeDuration.asMinutes() > 1) {
            setRemainingTime(remainingTimeDuration.format('m[m] s[s]'));
        } else if (remainingTimeDuration.asSeconds() > 1) {
            setRemainingTime(remainingTimeDuration.format('s[s]'));
        } else {
            setRemainingTime(t("files.aFewSeconds"));
        }
    }, [ lastStopTimestampSecs, clockHasTicked, lastTerminalMessage?.running, t ]);

    useEffect(() => {
        console.debug('UserPrinterFileProgress: lastTerminalMessage:', lastTerminalMessage);

        if (
            !lastTerminalMessage
            ||
            !lastTerminalMessage.maxLine
        ) { return; }

        setLastActionMeaning(lastTerminalMessage.meaning ?? null);
        setLastStopTimestampSecs(lastTerminalMessage.stopTimestampSecs ?? null);
    }, [ lastTerminalMessage ]);

    if (!lastTerminalMessage || !lastTerminalMessage.maxLine) { return; }

    const { line, maxLine } = lastTerminalMessage;

    const progress = line / maxLine;

    console.debug('UserPrinterFileProgress: progress:', progress);

    return (
        <View style={{ paddingVertical: 8 }}>
            <View style={{ paddingVertical: 14 }}>
                <ProgressBar progress={progress} color={colors.primary} />
            </View>
            <View>
                <Text style={{ textAlign: 'center' }}>
                    <Text style={{
                        textAlign: 'center',
                        textOverflow: 'ellipsis',
                        whiteSpace: 'nowrap',
                        display: 'block',
                        maxWidth: '100%',
                        overflowX: 'hidden'
                    }}>
                        {lastActionMeaning ?? t("files.waitingForServer")}
                    </Text>
                    {'\n'}
                    <Text>
                        {lastStopTimestampSecs
                            ? (remainingTime === t("files.aFewSeconds")
                                ? t("files.fewSecondsLeft")
                                : t("files.timeLeft", { time: remainingTime }))
                            : t("files.etaRecalculating")}
                    </Text>
                </Text>
            </View>
        </View>
    );
}
