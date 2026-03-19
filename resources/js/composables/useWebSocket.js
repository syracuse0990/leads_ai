import Pusher from 'pusher-js';
import { ref, onUnmounted, reactive } from 'vue';

let pusherInstance = null;

function getPusher() {
    if (!pusherInstance) {
        pusherInstance = new Pusher(import.meta.env.VITE_WEBSOCKET_APP_KEY, {
            wsHost: import.meta.env.VITE_WEBSOCKET_HOST,
            wsPort: 443,
            wssPort: 443,
            forceTLS: true,
            disableStats: true,
            enabledTransports: ['ws', 'wss'],
            cluster: 'mt1',
        });
    }
    return pusherInstance;
}

function parseData(data) {
    return typeof data === 'string' ? JSON.parse(data) : data;
}

/**
 * Generic channel subscription composable.
 */
export function useWebSocket(channelName) {
    const pusher = getPusher();
    const channel = pusher.subscribe(channelName);
    const bindings = [];

    function listen(eventName, callback) {
        channel.bind(eventName, (data) => callback(parseData(data)));
        bindings.push({ event: eventName, callback });
    }

    onUnmounted(() => {
        bindings.forEach(({ event, callback }) => channel.unbind(event, callback));
        pusher.unsubscribe(channelName);
    });

    return { channel, listen };
}

/**
 * Track document processing progress.
 */
export function useDocumentProgress() {
    const progress = ref(new Map());

    const { listen } = useWebSocket('documents');

    listen('processing.progress', (data) => {
        progress.value = new Map(progress.value);
        progress.value.set(data.document_id, {
            stage: data.stage,
            percent: data.percent,
            message: data.message,
        });
    });

    function getProgress(documentId) {
        return progress.value.get(documentId) || null;
    }

    return { progress, getProgress };
}

/**
 * Global toast notifications. Any page using this will receive server-pushed toasts.
 */
export function useToastNotifications() {
    const toasts = ref([]);
    let toastId = 0;

    const { listen } = useWebSocket('notifications');

    listen('toast', (data) => {
        const id = ++toastId;
        toasts.value.push({ id, ...data });
        // Auto-dismiss after 6 seconds
        setTimeout(() => {
            toasts.value = toasts.value.filter(t => t.id !== id);
        }, 6000);
    });

    function dismiss(id) {
        toasts.value = toasts.value.filter(t => t.id !== id);
    }

    return { toasts, dismiss };
}

/**
 * Real-time dashboard stats.
 */
export function useDashboardStats(initialStats) {
    const stats = reactive({ ...initialStats });

    const { listen } = useWebSocket('dashboard');

    listen('stats.updated', (data) => {
        Object.assign(stats, data);
    });

    return { stats };
}

/**
 * Chat thinking indicator — per conversation.
 */
export function useChatThinking(conversationId) {
    const thinking = ref(false);

    if (conversationId) {
        const { listen } = useWebSocket(`chat.${conversationId}`);
        listen('thinking.start', () => { thinking.value = true; });
        listen('thinking.stop', () => { thinking.value = false; });
    }

    return { thinking };
}

/**
 * Queue health stats (real-time + initial fetch).
 */
export function useQueueStats() {
    const stats = reactive({ pending: 0, processing: 0, completed: 0, failed: 0 });

    // Fetch initial stats
    fetch('/api/queue-stats')
        .then(r => r.json())
        .then(data => Object.assign(stats, data))
        .catch(() => {});

    const { listen } = useWebSocket('queue');
    listen('queue.stats', (data) => {
        Object.assign(stats, data);
    });

    return { stats };
}

/**
 * Real-time new topic notifications.
 */
export function useTopicUpdates() {
    const newTopics = ref([]);

    const { listen } = useWebSocket('topics');

    listen('topic.created', (data) => {
        newTopics.value.push(data);
    });

    return { newTopics };
}

/**
 * Reclassification progress.
 */
export function useReclassifyProgress() {
    const progress = reactive({ current: 0, total: 0, percent: 0, document: '', topic: null });

    const { listen } = useWebSocket('reclassify');

    listen('reclassify.progress', (data) => {
        Object.assign(progress, data);
    });

    return { progress };
}
