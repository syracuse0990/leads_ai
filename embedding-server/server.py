"""
Embedding microservice using sentence-transformers (all-MiniLM-L6-v2).
384-dimensional vectors with true semantic understanding.

Run: gunicorn -w 2 -b 127.0.0.1:9500 server:app
Dev:  python server.py
"""

import os
import json
from flask import Flask, request, jsonify
from sentence_transformers import SentenceTransformer

app = Flask(__name__)

MODEL_NAME = os.environ.get("EMBEDDING_MODEL", "all-MiniLM-L6-v2")
model = None


def get_model():
    global model
    if model is None:
        model = SentenceTransformer(MODEL_NAME)
    return model


@app.route("/health", methods=["GET"])
def health():
    return jsonify({"status": "ok", "model": MODEL_NAME, "dimensions": 384})


@app.route("/embed", methods=["POST"])
def embed():
    data = request.get_json(force=True)
    texts = data.get("texts", [])

    if not texts or not isinstance(texts, list):
        return jsonify({"error": "texts must be a non-empty array of strings"}), 400

    if len(texts) > 100:
        return jsonify({"error": "max 100 texts per batch"}), 400

    m = get_model()
    embeddings = m.encode(texts, normalize_embeddings=True, show_progress_bar=False)

    return jsonify({
        "embeddings": embeddings.tolist(),
        "dimensions": embeddings.shape[1],
        "count": len(texts),
    })


if __name__ == "__main__":
    # Pre-load model on startup
    print(f"Loading model: {MODEL_NAME}...")
    get_model()
    print("Model loaded. Starting server on port 9500...")
    app.run(host="127.0.0.1", port=9500, debug=False)
