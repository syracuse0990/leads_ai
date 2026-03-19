<template>
    <AppLayout>
        <div class="flex h-[calc(100vh-8rem)] gap-4">
            <!-- Sidebar: Conversations -->
            <div class="w-64 shrink-0 hidden md:flex flex-col rounded-xl border border-gray-800 bg-gray-900 overflow-hidden">
                <div class="border-b border-gray-800 p-3">
                    <button @click="showNewChat = true" class="w-full rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500 transition">
                        + New Chat
                    </button>
                </div>
                <div class="flex-1 overflow-y-auto p-2 space-y-1">
                    <div v-for="conv in conversations" :key="conv.id"
                        class="group flex items-center rounded-lg text-sm transition"
                        :class="activeConversation?.id === conv.id ? 'bg-gray-800 text-white' : 'text-gray-400 hover:bg-gray-800/50 hover:text-white'">
                        <Link :href="`/chat/${conv.id}`" class="flex-1 truncate px-3 py-2 font-medium">
                            {{ conv.title }}
                        </Link>
                        <button @click.prevent="confirmDeleteConversation(conv)"
                            class="shrink-0 mr-1 rounded p-1 text-gray-600 opacity-0 group-hover:opacity-100 hover:text-red-400 transition">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>
                    <p v-if="conversations.length === 0" class="text-center text-xs text-gray-600 py-4">No conversations yet</p>
                </div>
            </div>

            <!-- Main Chat Area -->
            <div class="flex-1 flex flex-col rounded-xl border border-gray-800 bg-gray-900 overflow-hidden">
                <!-- Chat header with system prompt toggle -->
                <div v-if="activeConversation" class="border-b border-gray-800 px-4 py-2 flex items-center justify-between">
                    <!-- Mobile: conversation selector -->
                    <div class="md:hidden">
                        <select @change="navigateConversation($event.target.value)" class="rounded-lg border border-gray-700 bg-gray-800 px-2 py-1 text-xs text-white">
                            <option v-for="conv in conversations" :key="conv.id" :value="conv.id" :selected="conv.id === activeConversation?.id">{{ conv.title }}</option>
                        </select>
                    </div>
                    <span class="hidden md:block text-sm text-gray-400 truncate">{{ activeConversation.title }}</span>
                    <button @click="showSystemPrompt = !showSystemPrompt"
                        class="rounded-lg px-2 py-1 text-xs transition"
                        :class="activeConversation.system_prompt ? 'bg-indigo-500/15 text-indigo-400' : 'text-gray-500 hover:text-gray-300'">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 inline mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                        Persona
                    </button>
                </div>

                <!-- System Prompt Editor -->
                <div v-if="showSystemPrompt && activeConversation" class="border-b border-gray-800 bg-gray-950 px-4 py-3">
                    <label class="block text-xs text-gray-400 mb-1">System Prompt — customize AI personality</label>
                    <textarea v-model="systemPromptDraft" rows="3" placeholder="e.g. You are a senior Laravel developer. Answer concisely with code examples."
                        class="w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-white placeholder-gray-500 focus:border-indigo-500 focus:outline-none resize-none"></textarea>
                    <div class="flex justify-end gap-2 mt-2">
                        <button @click="showSystemPrompt = false" class="rounded px-3 py-1 text-xs text-gray-400 hover:text-white transition">Cancel</button>
                        <button @click="saveSystemPrompt" :disabled="savingPrompt" class="rounded bg-indigo-600 px-3 py-1 text-xs font-medium text-white hover:bg-indigo-500 disabled:opacity-50 transition">
                            {{ savingPrompt ? 'Saving...' : 'Save' }}
                        </button>
                    </div>
                </div>

                <!-- Messages -->
                <div ref="messagesContainer" class="flex-1 overflow-y-auto p-4 space-y-4">
                    <div v-if="!activeConversation" class="flex h-full items-center justify-center">
                        <div class="text-center">
                            <img src="/images/leads-logo.png" alt="Leads AI" class="h-16 w-16 mx-auto mb-4 rounded-full ring-2 ring-gray-700 opacity-60" />
                            <h2 class="text-xl font-semibold text-gray-400 mb-2">Kumusta! Ask me anything about farming</h2>
                            <p class="text-sm text-gray-600">Pests, diseases, plant health, weed control — I'll search your documents and help you out.</p>
                            <!-- Mobile: new chat button -->
                            <button @click="showNewChat = true" class="mt-4 md:hidden rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 transition">+ New Chat</button>
                        </div>
                    </div>

                    <div v-for="msg in allMessages" :key="msg.id || msg.tempId" class="flex" :class="msg.role === 'user' ? 'justify-end' : 'justify-start'">
                        <div class="max-w-[75%] group/msg" :class="msg.role === 'assistant' ? 'flex gap-2.5' : ''">
                            <!-- AI avatar -->
                            <img v-if="msg.role === 'assistant'" src="/images/leads-logo.png" alt="Leads AI" class="h-7 w-7 rounded-full shrink-0 mt-1 ring-1 ring-gray-700" />
                            <div>
                                <div class="rounded-xl px-4 py-3 text-sm"
                                    :class="msg.role === 'user'
                                        ? 'bg-indigo-600 text-white rounded-br-sm'
                                        : 'bg-gray-800 text-gray-200 rounded-bl-sm'">
                                    <div v-if="msg.role === 'user'" class="whitespace-pre-wrap">{{ msg.content }}</div>
                                    <div v-else class="prose prose-invert prose-sm max-w-none" v-html="renderMarkdown(msg.content)"></div>
                                    <span v-if="msg.streaming" class="inline-block w-1.5 h-4 ml-0.5 bg-indigo-400 animate-pulse"></span>
                                </div>
                                <!-- Actions for assistant messages -->
                                <div v-if="msg.role === 'assistant' && msg.content && !msg.streaming" class="flex items-center gap-1 mt-1 opacity-0 group-hover/msg:opacity-100 transition">
                                <button @click="copyMessage(msg.content)" class="rounded p-1 text-gray-500 hover:text-white transition" title="Copy">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>
                                </button>
                                <button v-if="msg.id" @click="sendFeedback(msg, 'up')"
                                    class="rounded p-1 transition" :class="msg.feedback === 'up' ? 'text-emerald-400' : 'text-gray-500 hover:text-emerald-400'" title="Good response">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 10h4.764a2 2 0 011.789 2.894l-3.5 7A2 2 0 0115.263 21h-4.017c-.163 0-.326-.02-.485-.06L7 20m7-10V5a2 2 0 00-2-2h-.095c-.5 0-.905.405-.905.905 0 .714-.211 1.412-.608 2.006L7 11v9m7-10h-2M7 20H5a2 2 0 01-2-2v-6a2 2 0 012-2h2.5" /></svg>
                                </button>
                                <button v-if="msg.id" @click="sendFeedback(msg, 'down')"
                                    class="rounded p-1 transition" :class="msg.feedback === 'down' ? 'text-red-400' : 'text-gray-500 hover:text-red-400'" title="Bad response">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14H5.236a2 2 0 01-1.789-2.894l3.5-7A2 2 0 018.736 3h4.018a2 2 0 01.485.06l3.76.94m-7 10v5a2 2 0 002 2h.096c.5 0 .905-.405.905-.904 0-.715.211-1.413.608-2.008L17 13V4m-7 10h2m5-10h2a2 2 0 012 2v6a2 2 0 01-2 2h-2.5" /></svg>
                                </button>
                                <span v-if="copiedId === (msg.id || msg.tempId)" class="text-[10px] text-emerald-400 ml-1">Copied!</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- AI Thinking / Searching indicator (animated chat bubble) -->
                    <div v-if="aiThinking" class="flex justify-start">
                        <div class="flex gap-2.5">
                            <img src="/images/leads-logo.png" alt="Leads AI" class="h-7 w-7 rounded-full shrink-0 mt-1 ring-1 ring-gray-700 animate-pulse" />
                            <div class="rounded-xl bg-gray-800 rounded-bl-sm px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <div class="flex gap-1">
                                        <span class="h-2 w-2 rounded-full bg-indigo-400 animate-bounce" style="animation-delay: 0ms"></span>
                                        <span class="h-2 w-2 rounded-full bg-indigo-400 animate-bounce" style="animation-delay: 150ms"></span>
                                        <span class="h-2 w-2 rounded-full bg-indigo-400 animate-bounce" style="animation-delay: 300ms"></span>
                                    </div>
                                    <span class="text-xs text-gray-400 ml-1">Thinking...</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-if="isLoading && !streamingMessage && !aiThinking" class="flex justify-start">
                        <div class="flex gap-2.5">
                            <img src="/images/leads-logo.png" alt="Leads AI" class="h-7 w-7 rounded-full shrink-0 mt-1 ring-1 ring-gray-700 opacity-50" />
                            <div class="rounded-xl bg-gray-800 rounded-bl-sm px-4 py-3">
                                <div class="flex gap-1">
                                    <span class="h-2 w-2 rounded-full bg-gray-500 animate-bounce" style="animation-delay: 0ms"></span>
                                    <span class="h-2 w-2 rounded-full bg-gray-500 animate-bounce" style="animation-delay: 150ms"></span>
                                    <span class="h-2 w-2 rounded-full bg-gray-500 animate-bounce" style="animation-delay: 300ms"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Input -->
                <div v-if="activeConversation" class="border-t border-gray-800 p-4">
                    <form @submit.prevent="sendMessage" class="flex gap-3">
                        <input v-model="messageInput" type="text" placeholder="Ask a question..."
                            :disabled="isLoading"
                            class="flex-1 rounded-lg border border-gray-700 bg-gray-800 px-4 py-2.5 text-white placeholder-gray-500 focus:border-indigo-500 focus:outline-none disabled:opacity-50"
                            @keydown.enter.prevent="sendMessage" />
                        <button type="submit" :disabled="!messageInput.trim() || isLoading"
                            class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50 transition">
                            Send
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- New Chat Modal -->
        <div v-if="showNewChat" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="showNewChat = false">
            <div class="w-full max-w-md rounded-xl border border-gray-700 bg-gray-900 p-6">
                <h2 class="text-lg font-semibold mb-2">New Conversation</h2>
                <p class="text-sm text-gray-400 mb-6">Start a new chat. The AI will automatically search across all topics to find the best answers.</p>
                <div class="flex justify-end gap-3">
                    <button type="button" @click="showNewChat = false" class="rounded-lg border border-gray-700 px-4 py-2 text-sm text-gray-400 hover:text-white transition">Cancel</button>
                    <button @click="startChat" :disabled="newChatForm.processing" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50 transition">
                        Start Chat
                    </button>
                </div>
            </div>
        </div>

        <!-- Confirm Delete Modal -->
        <div v-if="confirmDelete" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="confirmDelete = null">
            <div class="w-full max-w-sm rounded-xl border border-gray-700 bg-gray-900 p-6">
                <h2 class="text-lg font-semibold mb-2">Delete Conversation</h2>
                <p class="text-sm text-gray-400 mb-6">Are you sure you want to delete "{{ confirmDelete.title }}"? This cannot be undone.</p>
                <div class="flex justify-end gap-3">
                    <button @click="confirmDelete = null" class="rounded-lg border border-gray-700 px-4 py-2 text-sm text-gray-400 hover:text-white transition">Cancel</button>
                    <button @click="deleteConversation" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-500 transition">Delete</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, computed, nextTick, watch } from 'vue';
import { Link, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { marked } from 'marked';
import { useChatThinking } from '@/composables/useWebSocket.js';

marked.setOptions({
    breaks: true,
    gfm: true,
});

function renderMarkdown(text) {
    if (!text) return '';
    return marked.parse(text);
}

const props = defineProps({
    conversations: Array,
    activeConversation: Object,
    messages: Array,
});

const messageInput = ref('');
const isLoading = ref(false);
const showNewChat = ref(false);
const streamingMessage = ref(null);
const messagesContainer = ref(null);
const localMessages = ref([]);
const confirmDelete = ref(null);
const copiedId = ref(null);
const showSystemPrompt = ref(false);
const systemPromptDraft = ref(props.activeConversation?.system_prompt || '');
const savingPrompt = ref(false);

const { thinking: aiThinking } = useChatThinking(props.activeConversation?.id);

const allMessages = computed(() => {
    const msgs = [...props.messages, ...localMessages.value];
    if (streamingMessage.value) {
        msgs.push(streamingMessage.value);
    }
    return msgs;
});

const newChatForm = useForm({});

function confirmDeleteConversation(conv) {
    confirmDelete.value = conv;
}

function deleteConversation() {
    if (confirmDelete.value) {
        router.delete(`/chat/${confirmDelete.value.id}`);
        confirmDelete.value = null;
    }
}

function navigateConversation(id) {
    router.visit(`/chat/${id}`);
}

function startChat() {
    newChatForm.post('/chat', {
        onSuccess: () => {
            showNewChat.value = false;
        },
    });
}

async function copyMessage(content) {
    try {
        await navigator.clipboard.writeText(content);
        copiedId.value = Date.now();
        setTimeout(() => { copiedId.value = null; }, 2000);
    } catch {}
}

async function sendFeedback(msg, type) {
    const newFeedback = msg.feedback === type ? 'null' : type;
    msg.feedback = newFeedback === 'null' ? null : newFeedback;
    try {
        await fetch(`/messages/${msg.id}/feedback`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({ feedback: newFeedback }),
        });
    } catch {}
}

async function saveSystemPrompt() {
    if (!props.activeConversation) return;
    savingPrompt.value = true;
    try {
        await fetch(`/chat/${props.activeConversation.id}/system-prompt`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({ system_prompt: systemPromptDraft.value }),
        });
        props.activeConversation.system_prompt = systemPromptDraft.value || null;
        showSystemPrompt.value = false;
    } catch {} finally {
        savingPrompt.value = false;
    }
}

async function sendMessage() {
    const message = messageInput.value.trim();
    if (!message || isLoading.value || !props.activeConversation) return;

    messageInput.value = '';
    isLoading.value = true;

    // Add user message locally
    localMessages.value.push({
        tempId: Date.now(),
        role: 'user',
        content: message,
    });

    await nextTick();
    scrollToBottom();

    // Stream response
    streamingMessage.value = {
        tempId: Date.now() + 1,
        role: 'assistant',
        content: '',
        streaming: true,
    };

    try {
        const response = await fetch(`/chat/${props.activeConversation.id}/stream`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content
                    || document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1]
                    || '',
                'Accept': 'text/event-stream',
            },
            body: JSON.stringify({ message }),
        });

        const reader = response.body.getReader();
        const decoder = new TextDecoder();

        while (true) {
            const { done, value } = await reader.read();
            if (done) break;

            const text = decoder.decode(value);
            const lines = text.split('\n');

            for (const line of lines) {
                if (line.startsWith('data: ')) {
                    const data = line.slice(6);
                    if (data === '[DONE]') continue;
                    try {
                        const parsed = JSON.parse(data);
                        if (parsed.chunk) {
                            streamingMessage.value.content += parsed.chunk;
                            scrollToBottom();
                        }
                    } catch {}
                }
            }
        }

        // Finalize: move streaming message to local messages
        localMessages.value.push({
            tempId: Date.now() + 2,
            role: 'assistant',
            content: streamingMessage.value.content,
        });
        streamingMessage.value = null;

    } catch (err) {
        // Fallback: non-streaming request
        streamingMessage.value = null;

        try {
            const formData = new FormData();
            formData.append('message', message);

            const resp = await fetch(`/chat/${props.activeConversation.id}/message`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: formData,
            });

            if (resp.redirected) {
                window.location.href = resp.url;
                return;
            }
        } catch {
            localMessages.value.push({
                tempId: Date.now() + 3,
                role: 'assistant',
                content: 'Sorry, something went wrong. Please try again.',
            });
        }
    } finally {
        isLoading.value = false;
    }
}

function scrollToBottom() {
    nextTick(() => {
        if (messagesContainer.value) {
            messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight;
        }
    });
}

// Auto-scroll during streaming
watch(() => streamingMessage.value?.content, () => {
    scrollToBottom();
});

// Reset local messages when conversation changes
watch(() => props.activeConversation?.id, () => {
    localMessages.value = [];
    streamingMessage.value = null;
    systemPromptDraft.value = props.activeConversation?.system_prompt || '';
    showSystemPrompt.value = false;
    nextTick(scrollToBottom);
});

// Scroll to bottom on mount
watch(() => props.messages, () => {
    nextTick(scrollToBottom);
}, { immediate: true });
</script>
