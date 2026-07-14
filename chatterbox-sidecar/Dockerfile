# Chatterbox TTS sidecar as a DDEV service (CPU inference only — containers
# get no GPU/Metal on macOS; on Apple Silicon prefer running app.py natively
# on the host instead). See docs/CHATTERBOX-LOCAL.md.
# libgomp1 is the OpenMP runtime some numeric wheels expect on slim images.
FROM python:3.11-slim

RUN apt-get update \
    && apt-get install -y --no-install-recommends libgomp1 \
    && rm -rf /var/lib/apt/lists/*

COPY requirements.txt /tmp/requirements.txt
RUN pip install --no-cache-dir -r /tmp/requirements.txt

# Models download once into /models (a persisted named volume, ~3.8GB per
# engine), then stay warm in memory across requests.
ENV CHATTERBOX_DEVICE=cpu \
    HF_HOME=/models \
    PYTHONUNBUFFERED=1

WORKDIR /app
EXPOSE 8766
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8766"]
