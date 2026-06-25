# RAG + Context Switching — OpenAI Demo

A Laravel 13 + Vue 3 project demonstrating two OpenAI integration patterns.

---

## Stack

| Layer | Technology |
|---|---|
| Backend | Laravel 13, PHP 8.2 |
| Frontend | Vue 3 (Composition API), TypeScript, Inertia.js |
| Styling | Tailwind CSS v4 |
| AI | OpenAI API (`gpt-4o-mini`) via `openai-php/laravel` |
| PDF parsing | `smalot/pdfparser` |
| Retrieval | TF-IDF (local, no extra API call) |
| Session store | Laravel Cache (database driver) |
| Chunk store | Laravel Cache (file driver) |

---

## Assignment 1 — RAG AI Agent (`/rag`)

Upload a PDF and ask questions about it.

**Pipeline:**

1. **Upload** — PDF is parsed with `smalot/pdfparser`, split into 400-word overlapping chunks, and stored in the file cache. No API call.
2. **Query** — Chunks are ranked against the question using local TF-IDF (no embeddings API). The top 3 chunks are injected into the system prompt of a `gpt-4o-mini` completion. One API call per question.

---

## Assignment 2 — Live Context Switching Chatbot (`/chat`)

Full chat session with live system-prompt swapping and a server-side tool call.

**Features:**

- **Persistent history** — conversation is stored in Laravel Cache, keyed by a UUID generated in the browser. History survives context switches.
- **Live context switch** — switching Context A / B (or entering a custom prompt) calls `POST /api/chat/context`, which updates the cached system prompt for the session. The next message uses the new prompt while keeping all prior messages.
- **`get_preference_color` tool** — defined as an OpenAI function tool. When triggered, the server resolves the color from the active context string (no extra API call for resolution). The result is fed back to the model in a second completion.

**Preset contexts:**

| | System prompt |
|---|---|
| Context A | `My favorite color is red.` |
| Context B | `My favorite food is green apples because I love green.` |

When `get_preference_color` runs against Context A it returns `Red`; against Context B it returns `Green` — demonstrating live context-driven tool output.

---

## Setup

### Requirements

- PHP 8.2+
- Composer
- Node.js 18+
- SQLite (default) or any Laravel-supported database
- An [OpenAI API key](https://platform.openai.com/api-keys)

### Install

```bash
git clone https://github.com/glennbaquero/rag-open-ai.git
cd rag-open-ai

composer install
npm install

cp .env.example .env
php artisan key:generate
```

### Configure `.env`

```env
OPENAI_API_KEY=sk-...
OPENAI_ORGANIZATION=org-...   # optional
```

### Database & cache

```bash
php artisan migrate
```

The app uses:
- **SQLite** for sessions and the default cache
- **File cache** (`storage/framework/cache/data/`) for PDF chunks

### Run

```bash
npm run build
php artisan serve
```

Open `http://localhost:8000`.

### Verify API key

```
GET http://localhost:8000/api/health
```

Returns `{"ok": true}` if the key is valid and has quota, or a descriptive error otherwise.

---

## Project structure

```
app/Http/Controllers/
  RagController.php          # Upload + TF-IDF query + GPT answer
  ChatController.php         # Session chat + context switch + tool call
  DiagnosticController.php   # /api/health — API key check

resources/js/
  pages/
    Welcome.vue              # Home with links to both demos
    Rag.vue                  # Assignment 1 UI
    Chat.vue                 # Assignment 2 UI
  lib/
    api.ts                   # Typed fetch helpers (apiPost, apiUpload, apiDelete)

routes/
  web.php                    # Inertia page routes (no CSRF issues)
  api.php                    # JSON API routes (no CSRF middleware)
```

---

## API reference

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/api/health` | Verify OpenAI key and quota |
| `POST` | `/api/rag/upload` | Upload PDF (multipart `pdf` field) |
| `POST` | `/api/rag/query` | `{ query }` → `{ answer, sources }` |
| `POST` | `/api/chat/message` | `{ session_id, message }` → `{ reply, toolOutput, context, history }` |
| `POST` | `/api/chat/context` | `{ session_id, context_key? }` or `{ session_id, custom_context }` |
| `DELETE` | `/api/chat/session/{id}` | Clear session history |

---

## Rate limiting

The app uses a single `gpt-4o-mini` call per RAG query and one to two calls per chat message (two only when the `get_preference_color` tool is triggered). On a paid OpenAI account this runs without delay. On a free-tier account the built-in retry logic reads the `Retry-After` header and backs off automatically up to 5 attempts.
