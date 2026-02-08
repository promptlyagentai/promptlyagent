FROM python:3.11-slim

# Install system dependencies
RUN apt-get update && apt-get install -y \
    wget \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Set working directory
WORKDIR /app

# Install MarkItDown and web framework with increased timeout
RUN pip install --upgrade pip \
    && pip install --default-timeout=300 --retries 5 \
        'markitdown[all]' fastapi uvicorn python-multipart openai

# Copy the web service
COPY app.py .

# Expose port
EXPOSE 8000

# Run the web service
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
