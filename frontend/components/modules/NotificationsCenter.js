
import { useMutation, useQuery } from "@tanstack/react-query";
import dayjs from "dayjs";
import { useEffect, useRef, useState } from "react";
import { FlatList, TouchableOpacity, useWindowDimensions, View } from "react-native";
import { ActivityIndicator, Badge, Divider, IconButton, List, Portal, Text, Tooltip, useTheme } from "react-native-paper";
import Markdown from '@ronradtke/react-native-markdown-display';
import API from "../../includes/API";
import { useEcho } from "../../hooks/useEcho";

const Item = ({ notification, onDelete, colors, markManyAsReadMutation }) => {
    if (!notification) return null;

    return (
        <View key={notification._id} testID={`notification-${notification._id}`}>
            <List.Item
                title={notification.data?.title}
                titleStyle={{ paddingVertical: 2 }}
                left={() =>
                    <View style={{ alignSelf: 'center', paddingHorizontal: 16 }}>
                        <Badge
                            size={8}
                            theme={{
                                colors: {
                                    error:
                                        !notification?.read_at
                                            ? colors.error
                                            : 'transparent'
                                }
                            }}
                        />
                    </View>
                }
                description={
                    <Text>
                        <Markdown style={{ marginTop: 2, paddingVertical: 2, display: 'inline-block' }}>   
                            {notification.data?.description}
                        </Markdown>
                        {'\n'}
                        <Text
                            style={{
                                fontSize: 12,
                                color: colors.onSurfaceVariant
                            }}
                        >
                            {dayjs().format('DD/MM/YYYY HH:mm')}
                        </Text>
                    </Text>
                }
                descriptionNumberOfLines={4}
                style={{ paddingVertical: 3 }}
                contentStyle={{ paddingLeft: 0 }}
                onPress={() => {
                    console.debug(`Open notification ${notification.id}`)

                    markManyAsReadMutation.mutate({ ids: [notification.id] });
                }}
                right={props => (
                    <IconButton
                        {...props}
                        icon="delete"
                        onPress={() => {
                            console.debug(`Delete notification ${notification.id}`),

                            onDelete(notification.id);
                        }}
                    />
                )}
            />
            <Divider key={`divider-${notification.id}`} />
        </View>
    );
}

const NotificationsCenter = ({
    isSmallTablet = false,
    enqueueSnackbar = () => {},
    headerHeight
}) => {
    const echo = useEcho();

    const { colors } = useTheme();

    const [ layout, setLayout ] = useState(null);

    const [ ids, setIds ] = useState([]);

    const [ viewedNotificationIds, setViewedNotificationIds ] = useState([]);

    const [ isViewing, setIsViewing ] = useState(false);

    const [ notifications, setNotifications ] = useState([]);

    const [ unreadCount, setUnreadCount ] = useState(0);

    const [ triggerPosition, setTriggerPosition ] = useState(null);

    const windowWidth = useWindowDimensions().width;

    const userQuery = useQuery({
        queryKey: ['user'],
        queryFn:  () => API.get('/user')
    });

    const notificationsQuery = useQuery({
        queryKey: ['notifications'],
        queryFn:  () => API.get('/user/notifications')
    });

    const notificationsRef = useRef(notifications);
    useEffect(() => {
        notificationsRef.current = notifications;
    }, [notifications]);

    const onViewableItemsChanged = ({ viewableItems }) => {
        console.debug('NotificationsCenter.onViewableItemsChanged', viewableItems);
        console.debug('NotificationsCenter.onViewableItemsChanged: notifications', notificationsRef.current);

        const nextViewedNotificationIds = [];

        viewableItems.forEach(({ index }) => {
            console.debug('NotificationsCenter.onViewableItemsChanged: index', index);

            const notification = notificationsRef.current[index] ?? null;

            console.debug('NotificationsCenter.onViewableItemsChanged: notification', notification);

            if (
                notification
                &&
                !notification?.read_at
                &&
                !viewedNotificationIds.includes(notification.id)
            ) { nextViewedNotificationIds.push(notification.id); }
        });

        console.debug('NotificationsCenter.onViewableItemsChanged: nextViewedNotificationIds', nextViewedNotificationIds);

        setViewedNotificationIds(nextViewedNotificationIds);
    };

    const viewabilityConfigCallbackPairs = useRef([ { onViewableItemsChanged } ]);

    const markManyAsReadMutation = useMutation({
        mutationFn: ({ ids }) => API.post(`/user/notifications/read`, { ids }),
        onSuccess: (data, variables) => {
            console.debug('NotificationsCenter.markAsReadMutation: onSuccess', data, variables);

            const { ids } = variables;

            setNotifications(prev => {
                const newNotifications = prev.map(notification => {
                    if (ids.includes(notification.id)) {
                        return { ...notification, read_at: dayjs().format() };
                    }
                    return notification;
                });

                console.debug('NotificationsCenter.markAsReadMutation: newNotifications', newNotifications);

                return newNotifications;
            });
        },
        onError: error => {
            console.error('NotificationsCenter.markAsReadMutation: onError', error);
        }
    });

    const deleteNotificationMutation = useMutation({
        mutationFn: ({ id }) => API.delete(`/user/notifications/${id}`),
        onMutate: ({ id }) => {
            console.debug('NotificationsCenter.deleteNotificationMutation: onMutate', id);

            setNotifications(prev => {
                const newNotifications = prev.filter(notification => notification.id !== id);

                console.debug('NotificationsCenter.deleteNotificationMutation: newNotifications', newNotifications);

                return newNotifications;
            });
        },
        onSuccess: (data, variables) => {
            console.debug('NotificationsCenter.deleteNotificationMutation', data, variables);
        },
        onError: error => {
            console.error('NotificationsCenter.deleteNotificationMutation', error);

            enqueueSnackbar({
                message: error?.message || 'An error occurred while deleting the notification.',
                variant: 'error',
                action: { label: 'Dismiss' }
            });
        }
    });

    useEffect(() => {
        setIds(
            notifications.map(notification => notification.id)
        );

        setUnreadCount(
            notifications.filter(notification => !notification?.read_at).length
        );
    }, [notifications, isViewing]);

    useEffect(() => {
        if (isViewing || viewedNotificationIds.length === 0) return;

        markManyAsReadMutation.mutate({ ids: viewedNotificationIds });
    }, [isViewing, viewedNotificationIds]);

    useEffect(() => {
        if (!echo) {
            console.warn('Echo is not initialized');

            return;
        }

        const userId = userQuery.data?.data?._id;

        if (!userId) {
            console.warn('User ID is not available');

            return;
        }

        const channel = echo.private(`App.Models.User.${userId}`);

        channel.notification((notification) => {
            console.debug('New notification', notification);

            setNotifications(notifications => {
                notification.data = {
                    title:        notification.title,
                    description:  notification.description
                };

                return [notification, ...notifications];
            });
        });

        return () => { channel.stopListening('.Illuminate\\Notifications\\Events\\BroadcastNotificationCreated'); };
    }, [echo, userQuery.data]);

    useEffect(() => {
        console.debug('NavBarMenu: notifications:', notifications);
    
        if (!notificationsQuery.data) { return; }
    
        const savedNotifications = notificationsQuery.data?.data;
    
        if (!savedNotifications) { return; }
    
        setNotifications(notifications => {
            return [...notifications, ...savedNotifications];
        });
    }, [notificationsQuery.data]);

    useEffect(() => {
        console.log('NotificationsCenter', { isViewing });
    }, [isViewing]);

    useEffect(() => {
        console.log('NotificationsCenter', { notifications });
    }, [notifications]);

    useEffect(() => {
        console.log('NotificationsCenter', { ids });
    }, [ids]);

    useEffect(() => {
        console.log('NotificationsCenter', { unreadCount });
    }, [unreadCount]);

    useEffect(() => {
        console.log('NotificationsCenter', { viewedNotificationIds });
    }, [viewedNotificationIds]);

    return (
        <>
            <Tooltip title='Notifications center'>
                <View style={{ position: 'relative' }}>
                    <IconButton
                        key={windowWidth}
                        animated={true}
                        icon={isViewing ? 'bell-outline' : 'bell'}
                        loading={notificationsQuery.isFetching}
                        onPress={() => setIsViewing(!isViewing)}
                        onLayout={(event) => setTriggerPosition(event.nativeEvent.layout)}
                    />
                    <Badge
                        visible={unreadCount > 0}
                        size={18}
                        style={{ position: 'absolute', top: 6, right: 6 }}
                        theme={{ colors: { onError: colors.white } }}
                    >
                        {
                            unreadCount > 99
                                ? '99+'
                                : unreadCount
                        }
                    </Badge>
                </View>
            </Tooltip>

            {isViewing &&
                <Portal>
                    <TouchableOpacity
                        onPress={() => setIsViewing(false)}
                        style={{
                            position: 'absolute', width: '100%', height: '100%',
                            top: 0, left: 0, right: 0, bottom: 0, zIndex: 1
                        }}
                    >
                        <View style={{
                            position: 'absolute',
                            top: headerHeight - 10,
                            left: (triggerPosition?.left || 0) + 10,
                            width: 20,
                            height: 20,
                            backgroundColor: colors.elevation.level1,
                            transform: [{ rotate: '45deg' }],
                            borderTopColor: colors.elevation.level2,
                            borderTopWidth: 1,
                            borderLeftColor: colors.elevation.level2,
                            borderLeftWidth: 1,
                            zIndex: 2,
                        }} />

                        <View
                            style={{
                                position: 'absolute',
                                top: headerHeight,
                                left: (
                                    isSmallTablet
                                        ? 0
                                        : (triggerPosition?.left || 0) - (layout?.width / 2.25 || 0)
                                ),
                                width:      isSmallTablet ? '100%' : 'auto',
                                maxWidth:   isSmallTablet ? '100%' : 350,
                                maxHeight: '65vh',
                                overflowY: 'scroll',
                                padding: 15,
                                backgroundColor: colors.elevation.level1,
                                boxShadow: '0 0 10px rgba(0, 0, 0, 0.1)',
                                opacity: (triggerPosition && layout) ? 1 : 0,
                            }}
                            onLayout={event => setLayout(event.nativeEvent.layout)}
                        >
                            <Text style={{ marginBottom: 10, color: colors.text }}>Notifications</Text>
                            <List.Section style={{ flexGrow: 1, flexShrink: 1, overflowY: 'scroll' }}>
                                {notificationsQuery.isFetching &&
                                    <List.Item
                                        title="Loading notifications..."
                                        titleStyle={{ textAlign: 'right' }}
                                        style={{ paddingLeft: 16 }}
                                        left={() => <ActivityIndicator />}
                                    />
                                }

                                {(!notificationsQuery.isFetching && ids.length === 0) &&
                                    <List.Item
                                        description="You don't have any notifications yet."
                                        descriptionNumberOfLines={4}
                                        style={{ paddingVertical: 3 }}
                                        contentStyle={{ paddingLeft: 0 }}
                                    />
                                }

                                <FlatList
                                    data={ids}
                                    renderItem={({ item }) => (
                                        <Item
                                            notification={notifications.find(n => n.id === item)}
                                            onDelete={id => deleteNotificationMutation.mutate({ id })}
                                            colors={colors}
                                            markManyAsReadMutation={markManyAsReadMutation}
                                        />
                                    )}
                                    keyExtractor={item => item}
                                    viewabilityConfigCallbackPairs={ viewabilityConfigCallbackPairs.current }
                                />
                            </List.Section>
                        </View>
                    </TouchableOpacity>
                </Portal>
            }
        </>
    );
}

export default NotificationsCenter;