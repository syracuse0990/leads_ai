<template>
    <div class="min-h-screen bg-gray-950 text-white">
        <!-- Navigation -->
        <nav class="border-b border-gray-800 bg-gray-900">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex h-16 items-center justify-between">
                    <div class="flex items-center gap-8">
                        <Link href="/" class="flex items-center gap-2">
                            <img src="/images/leads-logo.png" alt="Leads AI" class="h-8 w-auto" />
                            <span class="text-xl font-bold text-indigo-400">Leads AI</span>
                        </Link>
                        <!-- Desktop nav -->
                        <div class="hidden md:flex gap-4">
                            <Link href="/" :class="navClass('/')">Dashboard</Link>
                            <Link href="/topics" :class="navClass('/topics')">Topics</Link>
                            <Link href="/documents" :class="navClass('/documents')">Documents</Link>
                            <Link href="/chat" :class="navClass('/chat')">Chat</Link>
                        </div>
                    </div>
                    <div class="flex items-center gap-4">
                        <!-- Queue Health Indicator -->
                        <div v-if="queueStats.pending > 0 || queueStats.processing > 0" class="hidden sm:flex items-center gap-2 text-xs">
                            <span class="relative flex h-2 w-2">
                                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-indigo-400 opacity-75"></span>
                                <span class="relative inline-flex h-2 w-2 rounded-full bg-indigo-500"></span>
                            </span>
                            <span class="text-gray-400">
                                <span v-if="queueStats.processing > 0" class="text-blue-400">{{ queueStats.processing }} processing</span>
                                <span v-if="queueStats.processing > 0 && queueStats.pending > 0"> · </span>
                                <span v-if="queueStats.pending > 0" class="text-yellow-400">{{ queueStats.pending }} queued</span>
                            </span>
                        </div>
                        <!-- Mobile hamburger -->
                        <button @click="mobileOpen = !mobileOpen" class="md:hidden rounded-lg p-2 text-gray-400 hover:bg-gray-800 hover:text-white transition">
                            <svg v-if="!mobileOpen" class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" /></svg>
                            <svg v-else class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>
                </div>
                <!-- Mobile menu -->
                <div v-if="mobileOpen" class="md:hidden border-t border-gray-800 py-3 space-y-1">
                    <Link href="/" :class="mobileNavClass('/')" @click="mobileOpen = false">Dashboard</Link>
                    <Link href="/topics" :class="mobileNavClass('/topics')" @click="mobileOpen = false">Topics</Link>
                    <Link href="/documents" :class="mobileNavClass('/documents')" @click="mobileOpen = false">Documents</Link>
                    <Link href="/chat" :class="mobileNavClass('/chat')" @click="mobileOpen = false">Chat</Link>
                </div>
            </div>
        </nav>

        <!-- Page loading bar -->
        <div v-if="isNavigating" class="fixed top-0 left-0 right-0 z-50 h-0.5 bg-gray-900">
            <div class="h-full bg-indigo-500 animate-loading-bar"></div>
        </div>

        <!-- Page Content -->
        <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            <slot />
        </main>

        <!-- Toast Notifications -->
        <div class="fixed top-4 right-4 z-50 flex flex-col gap-2 w-80">
            <TransitionGroup name="toast">
                <div v-for="toast in toasts" :key="toast.id"
                    class="rounded-lg border px-4 py-3 shadow-lg backdrop-blur-sm cursor-pointer"
                    :class="toastClass(toast.type)"
                    @click="dismiss(toast.id)">
                    <div class="flex items-start gap-3">
                        <div class="mt-0.5">
                            <svg v-if="toast.type === 'success'" class="h-4 w-4 text-emerald-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                            <svg v-else-if="toast.type === 'error'" class="h-4 w-4 text-red-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                            <svg v-else-if="toast.type === 'warning'" class="h-4 w-4 text-amber-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
                            <svg v-else class="h-4 w-4 text-blue-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium">{{ toast.title }}</p>
                            <p v-if="toast.body" class="text-xs opacity-75 mt-0.5">{{ toast.body }}</p>
                        </div>
                    </div>
                </div>
            </TransitionGroup>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import { computed, TransitionGroup } from 'vue';
import { useToastNotifications, useQueueStats } from '@/composables/useWebSocket.js';

const page = usePage();
const currentPath = computed(() => page.url);
const mobileOpen = ref(false);
const isNavigating = ref(false);

function navClass(href) {
    const active = href === '/' ? currentPath.value === '/' : currentPath.value.startsWith(href);
    return [
        'rounded-md px-3 py-2 text-sm font-medium transition',
        active
            ? 'bg-indigo-500/15 text-indigo-400 border border-indigo-500/30'
            : 'text-gray-300 hover:text-white hover:bg-gray-800',
    ];
}

function mobileNavClass(href) {
    const active = href === '/' ? currentPath.value === '/' : currentPath.value.startsWith(href);
    return [
        'block rounded-md px-3 py-2 text-base font-medium transition',
        active
            ? 'bg-indigo-500/15 text-indigo-400'
            : 'text-gray-300 hover:text-white hover:bg-gray-800',
    ];
}

// Loading bar on navigation
let removeStart, removeFinish;
onMounted(() => {
    removeStart = router.on('start', () => { isNavigating.value = true; });
    removeFinish = router.on('finish', () => { isNavigating.value = false; });
});
onUnmounted(() => {
    removeStart?.();
    removeFinish?.();
});

const { toasts, dismiss } = useToastNotifications();
const { stats: queueStats } = useQueueStats();

function toastClass(type) {
    return {
        'border-emerald-500/30 bg-gray-900/95 text-emerald-100': type === 'success',
        'border-red-500/30 bg-gray-900/95 text-red-100': type === 'error',
        'border-amber-500/30 bg-gray-900/95 text-amber-100': type === 'warning',
        'border-blue-500/30 bg-gray-900/95 text-blue-100': type === 'info',
    };
}
</script>

<style scoped>
.toast-enter-active { transition: all 0.3s ease-out; }
.toast-leave-active { transition: all 0.2s ease-in; }
.toast-enter-from { opacity: 0; transform: translateX(100%); }
.toast-leave-to { opacity: 0; transform: translateX(100%); }
.toast-move { transition: transform 0.2s ease; }

@keyframes loading-bar {
    0% { width: 0%; }
    50% { width: 70%; }
    100% { width: 95%; }
}
.animate-loading-bar {
    animation: loading-bar 2s ease-in-out infinite;
}
</style>
