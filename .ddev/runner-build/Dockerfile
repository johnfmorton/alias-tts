# Genblaze runner as a DDEV service. The published genblaze packages come from
# PyPI; the local sidecar packages (genblaze_runner, genblaze_alias) are
# provided via a bind-mount of the repo + PYTHONPATH, so edits are live under
# `uvicorn --reload` without a rebuild.
FROM python:3.13-slim

RUN pip install --no-cache-dir \
    "genblaze-core>=0.3,<0.4" \
    "genblaze-s3>=0.3,<0.4" \
    "genblaze-google>=0.3,<0.4" \
    "genblaze-openai>=0.3,<0.4" \
    "httpx>=0.27" \
    "fastapi>=0.110" \
    "uvicorn[standard]>=0.29" \
    "pydantic>=2"

# Local packages live in the bind-mounted repo (see docker-compose.runner.yaml):
#   /app/genblaze-runner/genblaze_runner
#   /app/connectors/genblaze-alias/genblaze_alias
ENV PYTHONPATH=/app/genblaze-runner:/app/connectors/genblaze-alias \
    PYTHONUNBUFFERED=1

COPY entrypoint.sh /runner-entrypoint.sh

WORKDIR /app
EXPOSE 8800
ENTRYPOINT ["sh", "/runner-entrypoint.sh"]
CMD ["uvicorn", "genblaze_runner.app:app", "--host", "0.0.0.0", "--port", "8800", "--reload"]
