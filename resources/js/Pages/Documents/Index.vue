<template>
    <AppLayout>
        <div class="flex items-center justify-between mb-8">
            <h1 class="text-3xl font-bold">Documents</h1>
            <Link href="/documents/upload" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 transition">
                + Upload Files
            </Link>
        </div>

        <!-- Filters -->
        <div class="mb-6 flex flex-wrap gap-3">
            <input v-model="searchQuery" type="text" placeholder="Search by filename..."
                @input="debouncedFilter"
                class="rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-white placeholder-gray-500 focus:border-indigo-500 focus:outline-none w-64" />
            <select @change="applyFilter" v-model="selectedTopic"
                class="rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-white focus:border-indigo-500 focus:outline-none">
                <option value="">All Topics</option>
                <option v-for="topic in topics" :key="topic.id" :value="topic.id">
                    {{ topic.name }}
                </option>
            </select>
            <select @change="applyFilter" v-model="selectedStatus"
                class="rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-white focus:border-indigo-500 focus:outline-none">
                <option value="">All Status</option>
                <option value="pending">Pending</option>
                <option value="processing">Processing</option>
                <option value="completed">Completed</option>
                <option value="failed">Failed</option>
            </select>
            <button v-if="hasActiveFilters" @click="clearFilters" class="rounded-lg border border-gray-700 px-3 py-2 text-xs text-gray-400 hover:text-white transition">
                Clear filters
            </button>
        </div>

        <!-- Documents Table -->
        <div v-if="documents.data.length === 0" class="rounded-xl border border-gray-800 bg-gray-900 p-12 text-center">
            <p class="text-gray-400">No documents uploaded yet.</p>
        </div>

        <div v-else class="overflow-hidden rounded-xl border border-gray-800">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-gray-800 bg-gray-900">
                    <tr>
                        <th class="px-4 py-3 font-medium text-gray-400">File</th>
                        <th class="px-4 py-3 font-medium text-gray-400">Topic</th>
                        <th class="px-4 py-3 font-medium text-gray-400">Type</th>
                        <th class="px-4 py-3 font-medium text-gray-400">Size</th>
                        <th class="px-4 py-3 font-medium text-gray-400">Status</th>
                        <th class="px-4 py-3 font-medium text-gray-400">Uploaded</th>
                        <th class="px-4 py-3 font-medium text-gray-400"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800 bg-gray-950">
                    <tr v-for="doc in documents.data" :key="doc.id" class="hover:bg-gray-900/50">
                        <td class="px-4 py-3 font-medium text-white">{{ doc.original_name }}</td>
                        <td class="px-4 py-3 text-gray-400">{{ getDocTopic(doc) }}</td>
                        <td class="px-4 py-3 text-gray-400">{{ formatType(doc.mime_type) }}</td>
                        <td class="px-4 py-3 text-gray-400">{{ formatSize(doc.file_size) }}</td>
                        <td class="px-4 py-3">
                            <div class="min-w-[140px]">
                                <span :class="liveStatusClass(doc)" class="rounded-full px-2 py-0.5 text-xs font-medium">
                                    {{ liveStatusLabel(doc) }}
                                </span>
                                <!-- Progress bar for processing documents -->
                                <div v-if="isProcessing(doc)" class="mt-1.5">
                                    <div class="h-1 rounded-full bg-gray-800 overflow-hidden">
                                        <div class="h-full rounded-full bg-indigo-500 transition-all duration-500"
                                            :style="{ width: (getProgress(doc.id)?.percent || 0) + '%' }"></div>
                                    </div>
                                    <p class="text-[10px] text-gray-500 mt-0.5">{{ getProgress(doc.id)?.message || 'Queued...' }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-gray-500">{{ formatDate(doc.created_at) }}</td>
                        <td class="px-4 py-3">
                            <button @click="confirmDeleteDoc(doc)" class="text-gray-500 hover:text-red-400 transition">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div v-if="documents.last_page > 1" class="mt-4 flex gap-2">
            <Link v-for="link in documents.links" :key="link.label"
                :href="link.url || '#'"
                class="rounded-lg border border-gray-700 px-3 py-1 text-sm transition"
                :class="link.active ? 'bg-indigo-600 border-indigo-600 text-white' : 'text-gray-400 hover:text-white'"
                v-html="link.label" />
        </div>

        <!-- Confirm Delete Modal -->
        <div v-if="confirmDelete" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="confirmDelete = null">
            <div class="w-full max-w-sm rounded-xl border border-gray-700 bg-gray-900 p-6">
                <h2 class="text-lg font-semibold mb-2">Delete Document</h2>
                <p class="text-sm text-gray-400 mb-6">Are you sure you want to delete "{{ confirmDelete.original_name }}"? This will remove all its chunks and embeddings.</p>
                <div class="flex justify-end gap-3">
                    <button @click="confirmDelete = null" class="rounded-lg border border-gray-700 px-4 py-2 text-sm text-gray-400 hover:text-white transition">Cancel</button>
                    <button @click="deleteDoc" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-500 transition">Delete</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useDocumentProgress } from '@/composables/useWebSocket.js';

const props = defineProps({
    documents: Object,
    topics: Array,
    filters: Object,
});

const { progress, getProgress } = useDocumentProgress();

const searchQuery = ref(props.filters?.search || '');
const selectedTopic = ref(props.filters?.topic_id || '');
const selectedStatus = ref(props.filters?.status || '');
const confirmDelete = ref(null);

let debounceTimer = null;

const hasActiveFilters = computed(() => searchQuery.value || selectedTopic.value || selectedStatus.value);

function applyFilter() {
    const params = {};
    if (searchQuery.value) params.search = searchQuery.value;
    if (selectedTopic.value) params.topic_id = selectedTopic.value;
    if (selectedStatus.value) params.status = selectedStatus.value;
    router.get('/documents', params, { preserveState: true });
}

function debouncedFilter() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(applyFilter, 400);
}

function clearFilters() {
    searchQuery.value = '';
    selectedTopic.value = '';
    selectedStatus.value = '';
    router.get('/documents', {}, { preserveState: true });
}

function isProcessing(doc) {
    const p = getProgress(doc.id);
    if (p && p.stage !== 'completed' && p.stage !== 'failed') return true;
    return doc.status === 'pending' || doc.status === 'processing';
}

function liveStatusLabel(doc) {
    const p = getProgress(doc.id);
    if (p) {
        if (p.stage === 'completed') return 'completed';
        if (p.stage === 'failed') return 'failed';
        return 'processing';
    }
    return doc.status;
}

function liveStatusClass(doc) {
    const status = liveStatusLabel(doc);
    return {
        'bg-yellow-400/10 text-yellow-400': status === 'pending',
        'bg-blue-400/10 text-blue-400': status === 'processing',
        'bg-emerald-400/10 text-emerald-400': status === 'completed',
        'bg-red-400/10 text-red-400': status === 'failed',
    };
}

function getDocTopic(doc) {
    const p = getProgress(doc.id);
    if (p && p.stage === 'completed' && !doc.topic?.name) {
        return 'Classified';
    }
    return doc.topic?.name || '—';
}

function confirmDeleteDoc(doc) {
    confirmDelete.value = doc;
}

function deleteDoc() {
    if (confirmDelete.value) {
        router.delete(`/documents/${confirmDelete.value.id}`);
        confirmDelete.value = null;
    }
}

function formatType(mime) {
    if (mime?.includes('pdf')) return 'PDF';
    if (mime?.startsWith('image/')) return 'Image';
    if (mime?.includes('word') || mime?.includes('.document')) return 'Word';
    if (mime?.includes('sheet') || mime?.includes('excel')) return 'Excel';
    if (mime?.includes('presentation') || mime?.includes('powerpoint')) return 'PPT';
    if (mime?.includes('text')) return 'Text';
    return mime || 'Unknown';
}

function formatSize(bytes) {
    if (!bytes) return '—';
    const units = ['B', 'KB', 'MB', 'GB'];
    let i = 0;
    let size = bytes;
    while (size >= 1024 && i < units.length - 1) { size /= 1024; i++; }
    return `${size.toFixed(1)} ${units[i]}`;
}

function formatDate(date) {
    return new Date(date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}
</script>
