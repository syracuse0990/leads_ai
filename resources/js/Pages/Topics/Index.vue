<template>
    <AppLayout>
        <div class="mb-8">
            <h1 class="text-3xl font-bold">Topics</h1>
            <p class="text-gray-400 mt-1">Topics are auto-created by AI when you upload documents. The system classifies content automatically.</p>
        </div>

        <!-- Topics List -->
        <div v-if="allTopics.length === 0" class="rounded-xl border border-gray-800 bg-gray-900 p-12 text-center">
            <p class="text-gray-400">No topics yet. Upload documents and the AI will create topics automatically.</p>
        </div>

        <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <div v-for="topic in allTopics" :key="topic.id" class="rounded-xl border border-gray-800 bg-gray-900 p-5 hover:border-gray-700 transition">
                <div class="flex items-start justify-between mb-3">
                    <h3 class="font-semibold text-lg">{{ topic.name }}</h3>
                    <button @click="confirmDeleteTopic(topic)" class="rounded p-1 text-gray-500 hover:text-red-400 transition" title="Delete topic">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                    </button>
                </div>
                <p v-if="topic.description" class="text-sm text-gray-400 mb-4">{{ topic.description }}</p>
                <div class="flex gap-4 text-xs text-gray-500">
                    <span>{{ topic.documents_count }} docs</span>
                    <span>{{ topic.chunks_count }} chunks</span>
                </div>
            </div>
        </div>
        <!-- Confirm Delete Modal -->
        <div v-if="confirmDelete" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60">
            <div class="bg-gray-900 border border-gray-700 rounded-xl p-6 w-full max-w-sm mx-4">
                <h3 class="text-lg font-semibold mb-2">Delete Topic</h3>
                <p class="text-gray-400 text-sm mb-4">Delete "{{ confirmDelete.name }}" and all its documents? This cannot be undone.</p>
                <div class="flex gap-3 justify-end">
                    <button @click="confirmDelete = null" class="px-4 py-2 rounded-lg text-sm bg-gray-800 hover:bg-gray-700 transition">Cancel</button>
                    <button @click="deleteTopic" class="px-4 py-2 rounded-lg text-sm bg-red-600 hover:bg-red-700 transition">Delete</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTopicUpdates } from '@/composables/useWebSocket.js';

const props = defineProps({
    topics: Array,
});

const { newTopics } = useTopicUpdates();

// Merge server topics with any newly pushed via WS (avoid duplicates)
const allTopics = computed(() => {
    const existingIds = new Set(props.topics.map(t => t.id));
    const added = newTopics.value.filter(t => !existingIds.has(t.id));
    return [...props.topics, ...added];
});

const confirmDelete = ref(null);

function confirmDeleteTopic(topic) {
    confirmDelete.value = topic;
}

function deleteTopic() {
    if (confirmDelete.value) {
        router.delete(`/topics/${confirmDelete.value.id}`);
        confirmDelete.value = null;
    }
}
</script>
