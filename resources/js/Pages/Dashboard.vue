<template>
    <AppLayout>
        <h1 class="text-3xl font-bold mb-8">Dashboard</h1>

        <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-5 gap-6">
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-6">
                <h2 class="text-sm font-medium text-gray-400">Topics</h2>
                <p class="mt-2 text-3xl font-bold text-indigo-400">{{ liveStats.topics }}</p>
            </div>
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-6">
                <h2 class="text-sm font-medium text-gray-400">Documents</h2>
                <p class="mt-2 text-3xl font-bold text-emerald-400">{{ liveStats.documents }}</p>
            </div>
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-6">
                <h2 class="text-sm font-medium text-gray-400">Chunks Indexed</h2>
                <p class="mt-2 text-3xl font-bold text-amber-400">{{ liveStats.chunks }}</p>
            </div>
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-6">
                <h2 class="text-sm font-medium text-gray-400">Conversations</h2>
                <p class="mt-2 text-3xl font-bold text-cyan-400">{{ liveStats.conversations }}</p>
            </div>
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-6">
                <h2 class="text-sm font-medium text-gray-400">Active Chats</h2>
                <div class="flex items-center gap-2 mt-2">
                    <span v-if="liveStats.active_chats > 0" class="h-2.5 w-2.5 rounded-full bg-green-400 animate-pulse"></span>
                    <p class="text-3xl font-bold text-green-400">{{ liveStats.active_chats }}</p>
                </div>
                <p class="text-xs text-gray-500 mt-1">last 5 min</p>
            </div>
        </div>

        <!-- Queue Status -->
        <div v-if="queueStats.pending > 0 || queueStats.processing > 0" class="mt-6">
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-6">
                <h2 class="text-sm font-medium text-gray-400 mb-3">Queue Status</h2>
                <div class="flex gap-6">
                    <div class="flex items-center gap-2">
                        <span class="h-2.5 w-2.5 rounded-full bg-yellow-400"></span>
                        <span class="text-sm text-gray-300">{{ queueStats.pending }} pending</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="h-2.5 w-2.5 rounded-full bg-blue-400 animate-pulse"></span>
                        <span class="text-sm text-gray-300">{{ queueStats.processing }} processing</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="h-2.5 w-2.5 rounded-full bg-emerald-400"></span>
                        <span class="text-sm text-gray-300">{{ queueStats.completed }} completed</span>
                    </div>
                    <div v-if="queueStats.failed > 0" class="flex items-center gap-2">
                        <span class="h-2.5 w-2.5 rounded-full bg-red-400"></span>
                        <span class="text-sm text-gray-300">{{ queueStats.failed }} failed</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Topic Hits Chart -->
        <div class="mt-6">
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-6">
                <h2 class="text-sm font-medium text-gray-400 mb-4">Most Searched Topics</h2>
                <div v-if="topicHits.length === 0" class="text-gray-500 text-sm py-8 text-center">
                    No topic search data yet. Start chatting to see which topics are most used.
                </div>
                <div v-else class="space-y-3">
                    <div v-for="topic in topicHits" :key="topic.id" class="flex items-center gap-3">
                        <span class="text-sm text-gray-300 w-36 truncate shrink-0" :title="topic.name">{{ topic.name }}</span>
                        <div class="flex-1 bg-gray-800 rounded-full h-6 overflow-hidden">
                            <div
                                class="h-full rounded-full flex items-center px-3 transition-all duration-500"
                                :class="barColor(topic._rank)"
                                :style="{ width: barWidth(topic.hits) + '%' }"
                            >
                                <span v-if="barWidth(topic.hits) > 12" class="text-xs font-medium text-white">{{ topic.hits }}</span>
                            </div>
                        </div>
                        <span v-if="barWidth(topic.hits) <= 12" class="text-xs text-gray-400 w-8 text-right shrink-0">{{ topic.hits }}</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-10 grid grid-cols-1 md:grid-cols-2 gap-6">
            <Link href="/documents" class="flex items-center gap-4 rounded-xl border border-gray-800 bg-gray-900 p-6 hover:border-indigo-500 transition">
                <div class="rounded-lg bg-indigo-500/10 p-3 text-indigo-400">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" /></svg>
                </div>
                <div>
                    <h3 class="font-semibold">Upload Documents</h3>
                    <p class="text-sm text-gray-400">Train AI with PDFs, images, and text files</p>
                </div>
            </Link>
            <Link href="/chat" class="flex items-center gap-4 rounded-xl border border-gray-800 bg-gray-900 p-6 hover:border-emerald-500 transition">
                <div class="rounded-lg bg-emerald-500/10 p-3 text-emerald-400">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" /></svg>
                </div>
                <div>
                    <h3 class="font-semibold">Start Chat</h3>
                    <p class="text-sm text-gray-400">Ask questions about your trained data</p>
                </div>
            </Link>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useDashboardStats, useQueueStats } from '@/composables/useWebSocket.js';

const props = defineProps({
    stats: {
        type: Object,
        default: () => ({ topics: 0, documents: 0, chunks: 0 }),
    },
    topicHits: {
        type: Array,
        default: () => [],
    },
});

const { stats: liveStats } = useDashboardStats(props.stats);
const { stats: queueStats } = useQueueStats();

const maxHits = computed(() => Math.max(...props.topicHits.map(t => t.hits), 1));

const topicHits = computed(() =>
    props.topicHits.map((t, i) => ({ ...t, _rank: i }))
);

function barWidth(hits) {
    return Math.max((hits / maxHits.value) * 100, 4);
}

const colors = [
    'bg-indigo-500', 'bg-emerald-500', 'bg-amber-500', 'bg-cyan-500',
    'bg-pink-500', 'bg-violet-500', 'bg-teal-500', 'bg-orange-500',
    'bg-lime-500', 'bg-rose-500', 'bg-sky-500', 'bg-fuchsia-500',
    'bg-yellow-500', 'bg-red-500', 'bg-blue-500',
];

function barColor(rank) {
    return colors[rank % colors.length];
}
</script>
