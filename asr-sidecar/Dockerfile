# Whisper ASR sidecar as a DDEV service. faster-whisper (CTranslate2) on CPU.
# libgomp1 is CTranslate2's OpenMP runtime dependency.
FROM python:3.11-slim

RUN apt-get update \
    && apt-get install -y --no-install-recommends libgomp1 \
    && rm -rf /var/lib/apt/lists/*

COPY requirements.txt /tmp/requirements.txt
RUN pip install --no-cache-dir -r /tmp/requirements.txt

# Defaults match the host sidecar; the model downloads once into /models (a
# persisted named volume), then stays warm in memory across requests.
ENV ASR_MODEL=tiny \
    ASR_DEVICE=cpu \
    ASR_COMPUTE_TYPE=int8 \
    ASR_LANGUAGE=en \
    ASR_DOWNLOAD_ROOT=/models \
    PYTHONUNBUFFERED=1

WORKDIR /app
EXPOSE 8765
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8765"]
