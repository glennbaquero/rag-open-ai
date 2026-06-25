<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import { ApiError, apiPost, apiUpload } from '@/lib/api';

interface Source {
    preview: string;
    score: number;
}

interface UploadResponse {
    success: boolean;
    chunks: number;
}

interface QueryResponse {
    answer: string;
    sources: Source[];
}

interface Status {
    ok: boolean;
    msg: string;
}

const fileInput  = ref<HTMLInputElement | null>(null);
const file       = ref<File | null>(null);
const dragging   = ref(false);
const uploading  = ref(false);
const querying   = ref(false);
const ready      = ref(false);
const query      = ref('');
const answer     = ref('');
const sources    = ref<Source[]>([]);
const error      = ref('');
const status     = ref<Status | null>(null);

function onDrop(e: DragEvent) {
    dragging.value = false;
    const f = e.dataTransfer?.files[0];
    if (f?.type === 'application/pdf') { file.value = f; status.value = null; ready.value = false; }
}
function onFileChange(e: Event) {
    file.value     = (e.target as HTMLInputElement).files?.[0] ?? null;
    status.value   = null;
    ready.value    = false;
}

async function uploadPdf() {
    if (!file.value) return;
    uploading.value = true;
    status.value    = null;
    error.value     = '';

    const fd = new FormData();
    fd.append('pdf', file.value);

    try {
        const data = await apiUpload<UploadResponse>('/api/rag/upload', fd);
        ready.value  = true;
        status.value = { ok: true, msg: `✓ ${data.chunks} chunks indexed` };
    } catch (e) {
        const msg = e instanceof ApiError && e.status === 429
            ? '⏳ Rate limit reached — please wait a moment and try again.'
            : (e as Error).message;
        status.value = { ok: false, msg };
    } finally {
        uploading.value = false;
    }
}

async function runQuery() {
    if (!query.value.trim() || !ready.value) return;
    querying.value = true;
    answer.value   = '';
    sources.value  = [];
    error.value    = '';

    try {
        const data    = await apiPost<QueryResponse>('/api/rag/query', { query: query.value });
        answer.value  = data.answer;
        sources.value = data.sources;
    } catch (e) {
        error.value = e instanceof ApiError && e.status === 429
            ? '⏳ Rate limit reached — please wait a moment and try again.'
            : (e as Error).message;
    } finally {
        querying.value = false;
    }
}
</script>

<template>
    <Head title="RAG AI Agent" />

    <div class="min-h-screen bg-gray-950 text-gray-100">
        <!-- Nav -->
        <nav class="border-b border-gray-800 bg-gray-900 px-6 py-3 flex items-center gap-6">
            <Link href="/" class="text-sm text-gray-400 hover:text-white transition-colors">← Home</Link>
            <span class="text-gray-600">|</span>
            <span class="text-sm font-semibold text-violet-400">Assignment 1 — RAG AI Agent</span>
            <Link href="/chat" class="ml-auto text-sm text-gray-400 hover:text-white transition-colors">Context Switching →</Link>
        </nav>

        <div class="mx-auto max-w-3xl px-6 py-10">
            <h1 class="text-2xl font-bold mb-1">RAG AI Agent — PDF Q&amp;A</h1>
            <p class="text-sm text-gray-400 mb-8 leading-relaxed">
                Upload any PDF. Text is chunked and indexed server-side using TF-IDF. Queries
                rank chunks by relevance locally (no extra API call), then pass the top results
                as grounded context to <code class="text-violet-400">gpt-4o-mini</code>.
            </p>

            <!-- Upload zone -->
            <div
                class="rounded-xl border-2 border-dashed border-gray-700 p-10 text-center cursor-pointer transition-colors"
                :class="{ 'border-violet-500 bg-gray-900': dragging || file, 'hover:border-gray-500': !dragging }"
                @click="fileInput?.click()"
                @dragover.prevent="dragging = true"
                @dragleave.prevent="dragging = false"
                @drop.prevent="onDrop"
            >
                <input ref="fileInput" type="file" accept=".pdf" class="hidden" @change="onFileChange" />
                <p v-if="!file" class="text-gray-400 text-sm">
                    <span class="text-violet-400 font-semibold">Click to upload</span> or drag &amp; drop a PDF (max 20 MB)
                </p>
                <p v-else class="text-sm">
                    📄 <span class="font-semibold">{{ file.name }}</span>
                    <span class="text-gray-400 ml-2">({{ (file.size / 1024).toFixed(0) }} KB)</span>
                </p>
            </div>

            <div class="flex items-center gap-3 mt-4">
                <button
                    class="rounded-lg bg-violet-600 hover:bg-violet-500 disabled:opacity-40 px-4 py-2 text-sm font-semibold transition-colors"
                    :disabled="!file || uploading"
                    @click="uploadPdf"
                >
                    <span v-if="uploading" class="flex items-center gap-2">
                        <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                        </svg>
                        Processing…
                    </span>
                    <span v-else>Embed PDF</span>
                </button>

                <span
                    v-if="status"
                    class="rounded-full px-3 py-1 text-xs font-semibold"
                    :class="status.ok ? 'bg-emerald-900 text-emerald-300' : 'bg-red-900 text-red-300'"
                >
                    {{ status.msg }}
                </span>
            </div>

            <!-- Query -->
            <div class="flex gap-2 mt-8">
                <input
                    v-model="query"
                    type="text"
                    placeholder="Ask a question about the PDF…"
                    :disabled="!ready"
                    class="flex-1 rounded-lg bg-gray-800 border border-gray-700 px-4 py-2.5 text-sm outline-none transition-colors focus:border-violet-500 disabled:opacity-40"
                    @keydown.enter="runQuery"
                />
                <button
                    class="rounded-lg bg-violet-600 hover:bg-violet-500 disabled:opacity-40 px-4 py-2.5 text-sm font-semibold transition-colors"
                    :disabled="!ready || !query.trim() || querying"
                    @click="runQuery"
                >
                    <span v-if="querying">
                        <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                        </svg>
                    </span>
                    <span v-else>Ask</span>
                </button>
            </div>

            <!-- Answer -->
            <div v-if="answer" class="mt-6 rounded-xl bg-gray-900 border border-gray-800 p-5">
                <p class="text-xs font-bold uppercase tracking-widest text-gray-500 mb-3">Answer</p>
                <p class="text-sm leading-relaxed whitespace-pre-wrap">{{ answer }}</p>

                <template v-if="sources.length">
                    <p class="text-xs font-bold uppercase tracking-widest text-gray-500 mt-5 mb-2">
                        Retrieved chunks (top {{ sources.length }})
                    </p>
                    <div v-for="(s, i) in sources" :key="i"
                         class="flex items-baseline gap-3 rounded-lg bg-gray-800 border border-gray-700 px-3 py-2 mt-1.5 text-xs">
                        <span class="text-violet-400 font-bold shrink-0">[{{ i + 1 }}]</span>
                        <span class="text-gray-400 flex-1">{{ s.preview }}</span>
                        <span class="text-emerald-400 font-bold shrink-0">{{ s.score }}</span>
                    </div>
                </template>
            </div>

            <p v-if="error" class="mt-4 text-sm text-red-400">⚠ {{ error }}</p>
        </div>
    </div>
</template>
