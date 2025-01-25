import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useEffect, useState } from "react";

import { StyleSheet, View } from "react-native";

import { Icon, Text, TextInput, useTheme } from "react-native-paper";

import API from "../includes/API";

import UserPrinterStatusConnection from "./UserPrinterStatusConnection";
import UserPrinterStatusExtruders from "./UserPrinterStatusExtruders";
import UserPrinterStatusBed from "./UserPrinterStatusBed";
import UserPaneLoadingIndicator from "./UserPaneLoadingIndicator";
import UserPrinterStatus from "./UserPrinterStatus";

export default function UserPrinterStatusWrapper({ connectionStatus }) {
    return <UserPrinterStatus connectionStatus={connectionStatus} />;
}