FROM python:3.11-slim

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY . .

EXPOSE 8080

# Credentials are supplied at runtime via env vars (docker run --env-file .env ...).
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8080"]
