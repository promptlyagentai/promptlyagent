FROM ubuntu:22.04

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y \
    pandoc \
    texlive-full \
    texlive-xetex \
    texlive-luatex \
    python3 \
    python3-pip \
    wget \
    curl \
    librsvg2-bin \
    librsvg2-2 \
    && rm -rf /var/lib/apt/lists/*

RUN pip3 install fastapi uvicorn python-multipart aiofiles httpx

WORKDIR /app

RUN useradd -m -u 1000 pandoc && chown -R pandoc:pandoc /app
USER pandoc

COPY --chown=pandoc:pandoc app.py /app/
COPY --chown=pandoc:pandoc templates/ /app/templates/

EXPOSE 9000

HEALTHCHECK --interval=30s --timeout=10s --start-period=40s --retries=3 \
    CMD curl -f http://localhost:9000/health || exit 1

CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "9000"]
