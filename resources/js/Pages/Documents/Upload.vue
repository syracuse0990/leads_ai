<template>
    <AppLayout>
        <div class="mb-8">
            <Link href="/documents" class="text-sm text-gray-400 hover:text-white transition">&larr; Back to Documents</Link>
            <h1 class="text-3xl font-bold mt-2">Upload Documents</h1>
            <p class="text-gray-400 mt-1">Upload PDFs, images, or text files to train your AI. Topics are auto-assigned by AI.</p>
        </div>

        <!-- Phase 1: Upload Form -->
        <form v-if="!processing" @submit.prevent="upload" class="max-w-2xl">
            <!-- File Drop Zone -->
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-400 mb-2">Files</label>
                <div
                    @dragover.prevent="isDragging = true"
                    @dragleave="isDragging = false"
                    @drop.prevent="handleDrop"
                    :class="isDragging ? 'border-indigo-500 bg-indigo-500/5' : 'border-gray-700'"
                    class="relative flex flex-col items-center justify-center rounded-xl border-2 border-dashed bg-gray-900 p-10 transition cursor-pointer"
                    @click="$refs.fileInput.click()"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-10 w-10 text-gray-500 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" /></svg>
                    <p class="text-gray-400 text-sm">Drop files here or <span class="text-indigo-400 underline">browse</span></p>
                    <p class="text-gray-600 text-xs mt-1">PDF, Images, TXT, MD, CSV, Word, Excel, PowerPoint — max 50MB each</p>
                    <input ref="fileInput" type="file" multiple accept=".pdf,.png,.jpg,.jpeg,.gif,.webp,.txt,.md,.csv,.doc,.docx,.xls,.xlsx,.ppt,.pptx" class="hidden" @change="handleFileSelect" />
                </div>
                <p v-if="form.errors.files" class="mt-1 text-sm text-red-400">{{ form.errors.files }}</p>
            </div>

            <!-- Selected Files -->
            <div v-if="form.files.length > 0" class="mb-6 space-y-2">
                <div v-for="(file, i) in form.files" :key="i"
                    class="flex items-center justify-between rounded-lg border border-gray-800 bg-gray-900 px-4 py-2">
                    <div class="flex items-center gap-3">
                        <span class="text-xs font-mono rounded bg-gray-800 px-2 py-0.5 text-gray-400">{{ fileExt(file.name) }}</span>
                        <span class="text-sm text-white">{{ file.name }}</span>
                        <span class="text-xs text-gray-500">{{ formatSize(file.size) }}</span>
                    </div>
                    <button type="button" @click="removeFile(i)" class="text-gray-500 hover:text-red-400 transition">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>
            </div>

            <!-- Upload Button -->
            <button type="submit" :disabled="form.processing || form.files.length === 0"
                class="rounded-lg bg-indigo-600 px-6 py-2.5 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50 transition">
                <span v-if="form.processing">Uploading...</span>
                <span v-else>Upload {{ form.files.length }} file{{ form.files.length !== 1 ? 's' : '' }}</span>
            </button>

            <!-- Upload Progress -->
            <div v-if="form.progress" class="mt-4">
                <div class="h-2 rounded-full bg-gray-800 overflow-hidden">
                    <div class="h-full rounded-full bg-indigo-500 transition-all" :style="{ width: form.progress.percentage + '%' }"></div>
                </div>
                <p class="text-xs text-gray-500 mt-1">Uploading... {{ form.progress.percentage }}%</p>
            </div>
        </form>

        <!-- Phase 2: Processing Progress -->
        <div v-if="processing" class="max-w-2xl">
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-6">
                <h2 class="text-lg font-semibold mb-4 flex items-center gap-2">
                    <svg v-if="!allDone" class="h-5 w-5 text-indigo-400 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <svg v-else class="h-5 w-5 text-emerald-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                    {{ allDone ? 'All files processed!' : 'Processing your files...' }}
                </h2>

                <div class="space-y-4">
                    <div v-for="doc in uploadedDocs" :key="doc.id" class="rounded-lg border border-gray-800 bg-gray-950 px-4 py-3">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-sm font-medium text-white">{{ doc.name }}</span>
                            <span :class="docStatusClass(doc)" class="rounded-full px-2 py-0.5 text-xs font-medium">
                                {{ docStatusLabel(doc) }}
                            </span>
                        </div>
                        <div class="h-1.5 rounded-full bg-gray-800 overflow-hidden">
                            <div class="h-full rounded-full transition-all duration-500"
                                :class="docProgressColor(doc)"
                                :style="{ width: (getDocProgress(doc.id)?.percent || 0) + '%' }"></div>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">{{ getDocProgress(doc.id)?.message || 'Queued for processing...' }}</p>
                    </div>
                </div>

                <div class="mt-6 flex gap-3">
                    <Link href="/documents" class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-medium text-white hover:bg-indigo-500 transition">
                        View Documents
                    </Link>
                    <button @click="resetUpload" class="rounded-lg border border-gray-700 px-5 py-2 text-sm font-medium text-gray-400 hover:text-white transition">
                        Upload More
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { Link, useForm, usePage, router as inertiaRouter } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useDocumentProgress } from '@/composables/useWebSocket.js';

const isDragging = ref(false);
const processing = ref(false);
const uploadedDocs = ref([]);

const { progress: wsProgress, getProgress: getDocProgress } = useDocumentProgress();

const page = usePage();

const form = useForm({
    files: [],
});

// Check if we returned from a successful upload with flash data
onMounted(() => {
    const flash = page.props.flash;
    if (flash?.uploadedDocuments && flash.uploadedDocuments.length > 0) {
        uploadedDocs.value = flash.uploadedDocuments;
        processing.value = true;
    }
});

const allDone = computed(() => {
    if (uploadedDocs.value.length === 0) return false;
    return uploadedDocs.value.every(doc => {
        const p = getDocProgress(doc.id);
        return p && (p.stage === 'completed' || p.stage === 'failed');
    });
});

// Auto-redirect to Documents table when all files are done
let redirectTimer = null;
watch(allDone, (done) => {
    if (done && !redirectTimer) {
        redirectTimer = setTimeout(() => {
            inertiaRouter.visit('/documents');
        }, 1500);
    }
});

function handleFileSelect(e) {
    addFiles(e.target.files);
}

function handleDrop(e) {
    isDragging.value = false;
    addFiles(e.dataTransfer.files);
}

function addFiles(fileList) {
    for (const file of fileList) {
        if (!form.files.some(f => f.name === file.name && f.size === file.size)) {
            form.files.push(file);
        }
    }
}

function removeFile(index) {
    form.files.splice(index, 1);
}

function upload() {
    form.post('/documents', {
        forceFormData: true,
        preserveScroll: true,
    });
}

function resetUpload() {
    processing.value = false;
    uploadedDocs.value = [];
    form.reset();
    form.clearErrors();
}

function docStatusLabel(doc) {
    const p = getDocProgress(doc.id);
    if (!p) return 'Queued';
    return p.stage === 'completed' ? 'Done' : p.stage === 'failed' ? 'Failed' : p.stage.charAt(0).toUpperCase() + p.stage.slice(1);
}

function docStatusClass(doc) {
    const p = getDocProgress(doc.id);
    if (!p) return 'bg-yellow-400/10 text-yellow-400';
    if (p.stage === 'completed') return 'bg-emerald-400/10 text-emerald-400';
    if (p.stage === 'failed') return 'bg-red-400/10 text-red-400';
    return 'bg-blue-400/10 text-blue-400';
}

function docProgressColor(doc) {
    const p = getDocProgress(doc.id);
    if (!p) return 'bg-gray-600';
    if (p.stage === 'completed') return 'bg-emerald-500';
    if (p.stage === 'failed') return 'bg-red-500';
    return 'bg-indigo-500';
}

function fileExt(name) {
    return name.split('.').pop().toUpperCase();
}

function formatSize(bytes) {
    const units = ['B', 'KB', 'MB', 'GB'];
    let i = 0;
    let size = bytes;
    while (size >= 1024 && i < units.length - 1) { size /= 1024; i++; }
    return `${size.toFixed(1)} ${units[i]}`;
}
</script>
