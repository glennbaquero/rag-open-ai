<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';
import { ApiError, apiDelete, apiPost } from '@/lib/api';

interface Message {
    role: 'user' | 'assistant';
    content: string;
    toolOutput?: string;
}

interface MessageResponse {
    reply: string;
    toolOutput: string | null;
    context: string;
    history: Message[];
}

interface ContextResponse {
    success: boolean;
    context: string;
}

const PRESETS: Record<string, string> = {
    color: 'My favorite color is red.',
    food:  'My favorite food is green apples because I love green.',
};

const sessionId      = ref(crypto.randomUUID());
const userInput      = ref('');
const history        = ref<Message[]>([]);
const loading        = ref(false);
const activeCtxKey   = ref<string>('color');
const currentContext = ref(PRESETS['color']);
const customCtx      = ref('');
const toolLog        = ref<string[]>([]);
const msgEl          = ref<HTMLDivElement | null>(null);

async function send() {
    const msg = userInput.value.trim();
    if (!msg || loading.value) return;

    userInput.value = '';
    loading.value   = true;
    history.value.push({ role: 'user', content: msg });
    await scrollBottom();

    try {
        const data = await apiPost<MessageResponse>('/api/chat/message', {
            session_id: sessionId.value,
            message:    msg,
        });

        const serverHistory = data.history as Message[];
        const last = serverHistory[serverHistory.length - 1];
        if (last?.role === 'assistant' && data.toolOutput) {
            last.toolOutput = data.toolOutput;
            toolLog.value.unshift(data.toolOutput);
        }

        history.value        = serverHistory;
        currentContext.value = data.context;
    } catch (e) {
        const msg = e instanceof ApiError && e.status === 429
            ? '⏳ Rate limit reached — please wait a moment and try again.'
            : (e as Error).message;
        history.value.push({ role: 'assistant', content: `⚠ ${msg}` });
    } finally {
        loading.value = false;
        await scrollBottom();
    }
}

async function switchContext(key: string) {
    const payload: Record<string, unknown> = { session_id: sessionId.value };

    if (key === 'custom') {
        payload['custom_context'] = customCtx.value;
    } else {
        payload['context_key'] = key;
    }

    try {
        const data            = await apiPost<ContextResponse>('/api/chat/context', payload);
        currentContext.value  = data.context;
        activeCtxKey.value    = key;
    } catch (e) {
        console.error(e);
    }
}

async function newSession() {
    await apiDelete(`/api/chat/session/${sessionId.value}`);
    sessionId.value      = crypto.randomUUID();
    history.value        = [];
    toolLog.value        = [];
    activeCtxKey.value   = 'color';
    currentContext.value = PRESETS['color'];
    customCtx.value      = '';
    await apiPost('/api/chat/context', { session_id: sessionId.value, context_key: 'color' });
}

async function scrollBottom() {
    await nextTick();
    if (msgEl.value) msgEl.value.scrollTop = msgEl.value.scrollHeight;
}
</script>

<template>
    <Head title="Context Switching Chatbot" />

    <div class="min-h-screen bg-gray-950 text-gray-100">
        <!-- Nav -->
        <nav class="border-b border-gray-800 bg-gray-900 px-6 py-3 flex items-center gap-6">
            <Link href="/" class="text-sm text-gray-400 hover:text-white transition-colors">← Home</Link>
            <span class="text-gray-600">|</span>
            <span class="text-sm font-semibold text-emerald-400">Assignment 2 — Context Switching Chatbot</span>
            <Link href="/rag" class="ml-auto text-sm text-gray-400 hover:text-white transition-colors">RAG Demo →</Link>
        </nav>

        <div class="mx-auto max-w-5xl px-6 py-10">
            <h1 class="text-2xl font-bold mb-1">Live Context Switching Chatbot</h1>
            <p class="text-sm text-gray-400 mb-8 leading-relaxed">
                Chat history persists across context switches — only the system prompt is swapped.
                The <code class="text-emerald-400">get_preference_color</code> tool call is resolved
                server-side against the active context at call time.
            </p>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_280px]">

                <!-- Chat window -->
                <div class="flex flex-col rounded-xl border border-gray-800 bg-gray-900 h-[560px]">
                    <div class="flex items-center justify-between border-b border-gray-800 px-4 py-2.5 text-xs text-gray-500">
                        <span>Session <code class="text-gray-400">{{ sessionId.slice(0, 8) }}…</code></span>
                        <button
                            class="rounded-md bg-red-900/60 px-2.5 py-1 text-red-300 text-xs font-semibold hover:bg-red-800 transition-colors"
                            @click="newSession"
                        >New session</button>
                    </div>

                    <!-- Messages -->
                    <div ref="msgEl" class="flex-1 overflow-y-auto p-4 flex flex-col gap-3 scroll-smooth">
                        <p v-if="!history.length" class="m-auto text-sm text-gray-500 text-center">
                            Try: "What is my preference?" or "Call the color tool."
                        </p>

                        <div
                            v-for="(m, i) in history"
                            :key="i"
                            class="flex flex-col gap-1 max-w-[82%]"
                            :class="m.role === 'user' ? 'self-end items-end' : 'self-start items-start'"
                        >
                            <div
                                class="rounded-2xl px-3.5 py-2.5 text-sm leading-relaxed whitespace-pre-wrap"
                                :class="m.role === 'user'
                                    ? 'bg-violet-600 text-white rounded-br-sm'
                                    : 'bg-gray-800 border border-gray-700 rounded-bl-sm'"
                            >{{ m.content }}</div>

                            <div class="flex items-center gap-2 px-1 text-xs text-gray-500">
                                <span>{{ m.role === 'user' ? 'You' : 'Assistant' }}</span>
                                <span
                                    v-if="m.toolOutput"
                                    class="inline-flex items-center gap-1 rounded bg-emerald-950 border border-emerald-800 px-2 py-0.5 text-emerald-300 font-semibold text-[11px]"
                                >
                                    ⚙ get_preference_color() → {{ m.toolOutput }}
                                </span>
                            </div>
                        </div>

                        <div v-if="loading" class="self-start">
                            <div class="rounded-2xl rounded-bl-sm bg-gray-800 border border-gray-700 px-3.5 py-2.5">
                                <svg class="h-4 w-4 animate-spin text-gray-400" viewBox="0 0 24 24" fill="none">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                                </svg>
                            </div>
                        </div>
                    </div>

                    <!-- Input -->
                    <div class="flex gap-2 border-t border-gray-800 p-3">
                        <input
                            v-model="userInput"
                            type="text"
                            placeholder="Type a message…"
                            class="flex-1 rounded-lg bg-gray-800 border border-gray-700 px-3 py-2 text-sm outline-none transition-colors focus:border-violet-500"
                            @keydown.enter="send"
                        />
                        <button
                            class="rounded-lg bg-violet-600 hover:bg-violet-500 disabled:opacity-40 px-4 py-2 text-sm font-semibold transition-colors"
                            :disabled="!userInput.trim() || loading"
                            @click="send"
                        >Send</button>
                    </div>
                </div>

                <!-- Sidebar -->
                <div class="flex flex-col gap-4">

                    <!-- Context switcher -->
                    <div class="rounded-xl border border-gray-800 bg-gray-900 p-4">
                        <p class="text-xs font-bold uppercase tracking-widest text-gray-500 mb-3">Live Context Switch</p>

                        <button
                            v-for="(text, key) in PRESETS"
                            :key="key"
                            class="w-full text-left rounded-lg border px-3 py-2.5 mb-2 text-sm transition-colors"
                            :class="activeCtxKey === key
                                ? 'border-emerald-600 bg-emerald-950/40'
                                : 'border-gray-700 bg-gray-800 hover:border-gray-500'"
                            @click="switchContext(key)"
                        >
                            <span class="block text-xs font-bold text-emerald-400 mb-0.5">
                                Context {{ key === 'color' ? 'A' : 'B' }}
                            </span>
                            <span class="text-gray-400">{{ text }}</span>
                        </button>

                        <p class="text-xs text-gray-500 mb-1.5 mt-3">Custom system prompt</p>
                        <textarea
                            v-model="customCtx"
                            rows="3"
                            placeholder="Enter any context…"
                            class="w-full rounded-lg bg-gray-800 border border-gray-700 px-3 py-2 text-xs text-gray-200 outline-none resize-y transition-colors focus:border-violet-500 mb-2"
                        ></textarea>
                        <button
                            class="w-full rounded-lg border border-gray-700 bg-gray-800 hover:border-gray-500 px-3 py-2 text-xs font-semibold transition-colors"
                            @click="switchContext('custom')"
                        >Apply custom</button>
                    </div>

                    <!-- Active context -->
                    <div class="rounded-xl border border-gray-800 bg-gray-900 p-4">
                        <p class="text-xs font-bold uppercase tracking-widest text-gray-500 mb-2">Active system prompt</p>
                        <p class="text-xs text-gray-400 italic leading-relaxed bg-gray-800 rounded-lg p-2.5 border border-gray-700 break-words">
                            {{ currentContext }}
                        </p>
                    </div>

                    <!-- Tool call log -->
                    <div class="rounded-xl border border-gray-800 bg-gray-900 p-4">
                        <p class="text-xs font-bold uppercase tracking-widest text-gray-500 mb-2">Tool call log</p>
                        <p v-if="!toolLog.length" class="text-xs text-gray-600">No tool calls yet.</p>
                        <div v-for="(entry, i) in toolLog" :key="i"
                             class="flex items-center justify-between gap-2 rounded bg-gray-800 border border-gray-700 px-2.5 py-1.5 mt-1.5 text-xs">
                            <span class="font-mono text-indigo-400">get_preference_color()</span>
                            <span class="font-bold text-emerald-400">{{ entry }}</span>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>
</template>
