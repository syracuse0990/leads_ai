# Leads AI — Document-Trained AI Assistant

An intelligent document training and chat system that lets you upload documents, automatically classifies them into topics, generates semantic embeddings, and provides accurate AI-powered answers grounded in your uploaded knowledge base.

## Features

### Core
- **Document Upload & Processing** — Upload PDF, Word, Excel, PowerPoint, images, and text files (up to 15MB). Documents are automatically processed via a background queue.
- **AI-Powered Topic Classification** — Documents are auto-classified into topics using DeepSeek V3.2. New topics are created on the fly; existing ones are reused.
- **Semantic Search (pgvector + HNSW)** — Document chunks are embedded using `all-MiniLM-L6-v2` sentence-transformers via a Python microservice. Search uses pgvector's native `<=>` cosine operator with HNSW indexing for sub-millisecond retrieval at scale.
- **Hybrid Retrieval** — Combines vector similarity with keyword matching (content + document name) for high-accuracy results.
- **Streaming AI Chat** — Real-time streamed responses via Server-Sent Events, powered by DeepSeek V3.2 with retrieved document context and conversation history.
- **Multi-Turn Conversations** — Last 10 messages are included as context, enabling follow-up questions and coherent multi-turn dialogue.
- **Source Attribution** — AI responses include source document references so users can verify answers.
- **Image/Vision Processing** — KIMI K2.5 (Moonshot AI) extracts text from uploaded images for indexing.

### Dashboard & Real-Time
- **Live Dashboard** — Topic count, document count, chunk count, active conversations, and queue health — all updated in real-time via WebSocket.
- **Topic Hits Chart** — Horizontal bar chart showing the top 15 most-queried topics.
- **WebSocket Integration** — Pusher-compatible WebSocket server for live updates: document processing progress, toast notifications, dashboard stats, chat thinking indicator, queue health, and topic auto-updates.

### UX
- **Responsive Design** — Mobile hamburger menu, adaptive layout for all screen sizes.
- **Chat Features** — Copy message button, thumbs up/down feedback, system prompt editor per conversation, auto-scroll during streaming, confirmation dialogs.
- **Document Management** — Search by name, filter by topic/status, confirmation dialogs for deletion.
- **Loading Indicators** — Animated loading bar on page navigation, processing progress bars for documents.

## Tech Stack

| Layer | Technology |
|-------|-----------|
| **Backend** | Laravel 13, PHP 8.3 |
| **Frontend** | Vue 3.5, Inertia.js 2, Tailwind CSS 4, Vite 8 |
| **Database** | PostgreSQL 18 + pgvector (HNSW index) |
| **Text AI** | DeepSeek V3.2 (chat, streaming, classification) |
| **Vision AI** | KIMI K2.5 / Moonshot AI (image text extraction) |
| **Embeddings** | sentence-transformers `all-MiniLM-L6-v2` (384d) via Python Flask microservice |
| **WebSocket** | Pusher-compatible server (custom hosted) |
| **Queue** | Laravel database queue with supervisor |

## Architecture

```
┌─────────────┐     ┌──────────────┐     ┌─────────────────┐
│  Vue 3 SPA  │────▶│  Laravel API │────▶│  PostgreSQL +   │
│  (Inertia)  │◀────│  Controllers │◀────│  pgvector       │
└─────────────┘     └──────┬───────┘     └─────────────────┘
                           │
              ┌────────────┼────────────┐
              ▼            ▼            ▼
      ┌──────────┐ ┌────────────┐ ┌──────────────┐
      │ DeepSeek │ │ Embedding  │ │  WebSocket   │
      │ + KIMI   │ │ Server     │ │  Server      │
      │ (APIs)   │ │ (Python)   │ │  (Pusher)    │
      └──────────┘ └────────────┘ └──────────────┘
```

## Setup

### Prerequisites
- PHP 8.3+, Composer
- Node.js 20+, npm
- PostgreSQL 16+ with pgvector extension
- Python 3.10+ (for embedding server)

### Installation

```bash
# Clone
git clone https://github.com/syracuse0990/leads_ai.git
cd leads_ai

# PHP dependencies
composer install

# JavaScript dependencies
npm install

# Environment
cp .env.example .env
php artisan key:generate
# Edit .env with your database, API keys, and WebSocket settings

# Database
php artisan migrate

# Build frontend
npm run build
```

### Embedding Server

```bash
cd embedding-server
python3 -m venv venv
source venv/bin/activate
pip install -r requirements.txt

# Run (development)
python server.py

# Run (production with gunicorn)
gunicorn -w 2 -b 127.0.0.1:9500 server:app
```

For production, install the systemd service:
```bash
sudo cp embedding-server.service /etc/systemd/system/
sudo systemctl enable --now embedding-server
```

### Queue Worker

```bash
php artisan queue:work --queue=training,default
```

### Re-embed Existing Documents

After setting up the embedding server, re-embed all chunks with the semantic model:
```bash
php artisan documents:reembed
```

## Environment Variables

| Variable | Description |
|----------|------------|
| `DEEPSEEK_API_KEY` | DeepSeek API key for chat & classification |
| `KIMI_API_KEY` | Moonshot AI API key for image processing |
| `EMBEDDING_SERVER_URL` | Embedding microservice URL (default: `http://127.0.0.1:9500`) |
| `SIMILARITY_THRESHOLD` | Cosine distance cutoff (default: `0.3`, lower = stricter) |
| `WEBSOCKET_URL` | WebSocket server URL |
| `WEBSOCKET_APP_KEY` | WebSocket app key |
| `WEBSOCKET_APP_SECRET` | WebSocket app secret |

## Deployment

Production deployment configs are in `deployment/`:
- `nginx.conf` — Nginx site configuration
- `supervisor.conf` — Queue worker process manager
- `php-fpm.conf` — PHP-FPM pool settings
- `embedding-server/embedding-server.service` — Systemd service for the embedding server
