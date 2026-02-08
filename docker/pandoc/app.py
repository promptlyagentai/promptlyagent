from fastapi import FastAPI, File, UploadFile, Form, HTTPException
from fastapi.responses import Response
import subprocess
import tempfile
import os
import shutil
from pathlib import Path
from typing import Optional
import logging
import json
import httpx
import hashlib

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

app = FastAPI(title="Pandoc Conversion Service", version="1.0.0")

TEMPLATES_DIR = Path("/app/templates")
SUPPORTED_FORMATS = ["pdf", "docx", "odt", "latex", "csv", "html", "epub"]
MAX_FILE_SIZE = 50 * 1024 * 1024
MAX_ASSET_SIZE = 10 * 1024 * 1024
ASSET_TIMEOUT = 30
LARAVEL_BASE_URL = os.getenv("LARAVEL_URL", "http://laravel.test")

async def download_url(url: str, dest_dir: Path) -> Optional[Path]:
    """Download external URL to destination directory"""
    try:
        headers = {
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
        }
        async with httpx.AsyncClient(timeout=ASSET_TIMEOUT) as client:
            response = await client.get(url, headers=headers, follow_redirects=True)
            response.raise_for_status()

            if len(response.content) > MAX_ASSET_SIZE:
                logger.warning(f"Asset too large: {url} ({len(response.content)} bytes)")
                return None

            url_hash = hashlib.md5(url.encode()).hexdigest()[:8]
            ext = Path(url).suffix or '.jpg'
            filename = f"url_{url_hash}{ext}"
            dest = dest_dir / filename

            dest.write_bytes(response.content)
            logger.info(f"Downloaded URL: {url} -> {filename}")
            return dest

    except Exception as e:
        logger.error(f"Failed to download URL {url}: {e}")
        return None

async def download_asset(asset_id: int, dest_dir: Path, token: Optional[str] = None) -> Optional[Path]:
    """Download Laravel asset to destination directory"""
    try:
        url = f"{LARAVEL_BASE_URL}/internal/assets/{asset_id}/download"
        if token:
            url = f"{url}?token={token}"

        async with httpx.AsyncClient(timeout=ASSET_TIMEOUT) as client:
            response = await client.get(url, follow_redirects=True)
            response.raise_for_status()

            if len(response.content) > MAX_ASSET_SIZE:
                logger.warning(f"Asset {asset_id} too large ({len(response.content)} bytes)")
                return None

            content_disposition = response.headers.get('content-disposition', '')
            filename = f"asset_{asset_id}.jpg"
            if 'filename=' in content_disposition:
                filename = content_disposition.split('filename=')[1].strip('"')

            dest = dest_dir / filename
            dest.write_bytes(response.content)
            logger.info(f"Downloaded asset {asset_id} -> {filename}")
            return dest

    except Exception as e:
        logger.error(f"Failed to download asset {asset_id}: {e}")
        return None

async def download_attachment(attachment_id: int, dest_dir: Path, token: Optional[str] = None) -> Optional[Path]:
    """Download Laravel chat attachment to destination directory"""
    try:
        url = f"{LARAVEL_BASE_URL}/internal/attachments/{attachment_id}/download"
        if token:
            url = f"{url}?token={token}"

        async with httpx.AsyncClient(timeout=ASSET_TIMEOUT) as client:
            response = await client.get(url, follow_redirects=True)
            response.raise_for_status()

            if len(response.content) > MAX_ASSET_SIZE:
                logger.warning(f"Attachment {attachment_id} too large ({len(response.content)} bytes)")
                return None

            content_disposition = response.headers.get('content-disposition', '')
            filename = f"attachment_{attachment_id}.jpg"
            if 'filename=' in content_disposition:
                filename = content_disposition.split('filename=')[1].strip('"')

            dest = dest_dir / filename
            dest.write_bytes(response.content)
            logger.info(f"Downloaded attachment {attachment_id} -> {filename}")
            return dest

    except Exception as e:
        logger.error(f"Failed to download attachment {attachment_id}: {e}")
        return None

@app.get("/health")
async def health_check():
    try:
        result = subprocess.run(
            ["pandoc", "--version"],
            capture_output=True,
            text=True,
            timeout=5
        )
        return {
            "status": "healthy",
            "pandoc_version": result.stdout.split('\n')[0]
        }
    except Exception as e:
        logger.error(f"Health check failed: {e}")
        raise HTTPException(status_code=503, detail="Service unhealthy")

@app.get("/templates")
async def list_templates():
    templates = []
    if TEMPLATES_DIR.exists():
        templates = [f.stem for f in TEMPLATES_DIR.glob("*.latex")]
    return {"templates": templates}

@app.post("/convert")
async def convert_text(
    content: str = Form(...),
    output_format: str = Form(...),
    template: Optional[str] = Form(None),
    title: Optional[str] = Form(None),
    author: Optional[str] = Form(None),
    assets: Optional[str] = Form(None),
    fonts: Optional[str] = Form(None),
    colors: Optional[str] = Form(None)
):
    if output_format not in SUPPORTED_FORMATS:
        raise HTTPException(
            status_code=400,
            detail=f"Unsupported format. Supported: {SUPPORTED_FORMATS}"
        )

    with tempfile.TemporaryDirectory() as tmpdir:
        tmpdir_path = Path(tmpdir)
        input_file = tmpdir_path / "input.md"
        output_file = tmpdir_path / f"output.{output_format}"
        assets_dir = tmpdir_path / "assets"
        assets_dir.mkdir(exist_ok=True)

        asset_map = {}
        if assets:
            try:
                asset_data = json.loads(assets)
                token = asset_data.get('token')

                # Download internal assets
                for asset_id in asset_data.get('assets', []):
                    local_path = await download_asset(asset_id, assets_dir, token)
                    if local_path:
                        asset_map[f"asset://{asset_id}"] = str(local_path)

                # Download chat attachments
                attachment_ids = asset_data.get('attachments', [])
                attachment_urls = asset_data.get('attachment_urls', [])
                for i, attachment_id in enumerate(attachment_ids):
                    local_path = await download_attachment(attachment_id, assets_dir, token)
                    if local_path and i < len(attachment_urls):
                        # Use the original URL from markdown for replacement
                        asset_map[attachment_urls[i]] = str(local_path)

                # Download external URLs
                for url in asset_data.get('urls', []):
                    local_path = await download_url(url, assets_dir)
                    if local_path:
                        asset_map[url] = str(local_path)
                    else:
                        # If download fails, remove the image reference from markdown
                        logger.warning(f"Removing failed image reference: {url}")
                        # Remove markdown image syntax: ![alt](url)
                        import re
                        content = re.sub(rf'!\[([^\]]*)\]\({re.escape(url)}\)', r'[Image unavailable: \1]', content)

            except Exception as e:
                logger.error(f"Failed to process assets: {e}")

        for original, local in asset_map.items():
            logger.info(f"Replacing '{original}' with '{local}'")
            content = content.replace(original, local)
            logger.info(f"Replacement result: URL found={original in content}")

        # Check if content has YAML frontmatter
        has_frontmatter = False
        frontmatter_fields = set()
        if content.strip().startswith('---'):
            lines = content.split('\n')
            if len(lines) > 2:
                # Find the closing --- for frontmatter
                for i in range(1, min(100, len(lines))):  # Check first 100 lines
                    if lines[i].strip() == '---':
                        has_frontmatter = True
                        # Extract field names from frontmatter
                        for line in lines[1:i]:
                            if ':' in line and not line.strip().startswith('#'):
                                field = line.split(':')[0].strip()
                                frontmatter_fields.add(field)
                        break

        logger.info(f"YAML frontmatter detected: {has_frontmatter}, fields: {frontmatter_fields}")

        input_file.write_text(content)

        cmd = ["pandoc", str(input_file), "-o", str(output_file)]

        if output_format == "pdf":
            # Create header file for image sizing constraints
            header_file = tmpdir_path / "header.tex"
            header_file.write_text(r"""
\usepackage{graphicx}
\setkeys{Gin}{width=\linewidth,height=\textheight,keepaspectratio}
""")

            cmd.extend([
                "--pdf-engine", "xelatex",
                "--variable", "colorlinks:true",
                "--listings",
                "--include-in-header", str(header_file)
            ])

            if colors:
                try:
                    color_vars = json.loads(colors)
                    for key, value in color_vars.items():
                        cmd.extend(["--variable", f"{key}:{value}"])
                except Exception as e:
                    logger.error(f"Failed to parse colors: {e}")
                    cmd.extend([
                        "--variable", "linkcolor:blue",
                        "--variable", "urlcolor:blue",
                        "--variable", "toccolor:blue"
                    ])
            else:
                cmd.extend([
                    "--variable", "linkcolor:blue",
                    "--variable", "urlcolor:blue",
                    "--variable", "toccolor:blue"
                ])

            if fonts:
                try:
                    font_vars = json.loads(fonts)
                    for key, value in font_vars.items():
                        cmd.extend(["--variable", f"{key}:{value}"])
                except Exception as e:
                    logger.error(f"Failed to parse fonts: {e}")

            if template:
                template_path = TEMPLATES_DIR / f"{template}.latex"
                if template_path.exists():
                    cmd.extend(["--template", str(template_path)])

        # Only add metadata if not present in frontmatter
        if title and 'title' not in frontmatter_fields:
            cmd.extend(["-M", f"title={title}"])
        if author and 'author' not in frontmatter_fields:
            cmd.extend(["-M", f"author={author}"])

        from datetime import datetime
        if 'date' not in frontmatter_fields:
            cmd.extend(["-M", f"date={datetime.now().strftime('%B %d, %Y')}"])

        try:
            logger.info(f"Running: {' '.join(cmd)}")
            result = subprocess.run(
                cmd,
                capture_output=True,
                text=True,
                timeout=120
            )

            if result.returncode != 0:
                error_details = result.stderr
                logger.error(f"Pandoc stderr: {error_details}")
                logger.error(f"Pandoc stdout: {result.stdout}")

                if output_format == "pdf" and template and "! LaTeX Error" in error_details:
                    logger.warning(f"Template {template} failed, retrying without template")
                    cmd_fallback = [
                        "pandoc", str(input_file), "-o", str(output_file),
                        "--pdf-engine", "xelatex",
                        "--variable", "colorlinks:true",
                        "--variable", "linkcolor:blue",
                        "--variable", "urlcolor:blue",
                        "--variable", "toccolor:blue",
                        "--listings"
                    ]
                    if title:
                        cmd_fallback.extend(["-M", f"title={title}"])
                    if author:
                        cmd_fallback.extend(["-M", f"author={author}"])
                    cmd_fallback.extend(["-M", f"date={datetime.now().strftime('%B %d, %Y')}"])

                    result = subprocess.run(
                        cmd_fallback,
                        capture_output=True,
                        text=True,
                        timeout=120
                    )

                    if result.returncode != 0:
                        logger.error(f"Fallback also failed: {result.stderr}")
                        raise HTTPException(
                            status_code=500,
                            detail=f"Conversion failed even without template: {result.stderr[:500]}"
                        )
                else:
                    raise HTTPException(
                        status_code=500,
                        detail=f"Conversion failed: {error_details[:500]}"
                    )

            output_content = output_file.read_bytes()

            mime_types = {
                "pdf": "application/pdf",
                "docx": "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
                "odt": "application/vnd.oasis.opendocument.text",
                "latex": "application/x-latex",
                "csv": "text/csv",
                "html": "text/html",
                "epub": "application/epub+zip"
            }

            return Response(
                content=output_content,
                media_type=mime_types.get(output_format, "application/octet-stream")
            )

        except subprocess.TimeoutExpired:
            raise HTTPException(status_code=504, detail="Conversion timeout")
        except Exception as e:
            logger.error(f"Conversion error: {e}")
            raise HTTPException(status_code=500, detail=str(e))

@app.post("/convert-file")
async def convert_file(
    file: UploadFile = File(...),
    output_format: str = Form(...),
    template: Optional[str] = Form(None)
):
    file.file.seek(0, 2)
    file_size = file.file.tell()
    file.file.seek(0)

    if file_size > MAX_FILE_SIZE:
        raise HTTPException(
            status_code=413,
            detail=f"File too large. Max size: {MAX_FILE_SIZE / 1024 / 1024}MB"
        )

    with tempfile.TemporaryDirectory() as tmpdir:
        input_file = Path(tmpdir) / file.filename
        output_file = Path(tmpdir) / f"output.{output_format}"

        with input_file.open("wb") as f:
            shutil.copyfileobj(file.file, f)

        cmd = ["pandoc", str(input_file), "-o", str(output_file)]

        if output_format == "pdf" and template:
            template_path = TEMPLATES_DIR / f"{template}.latex"
            if template_path.exists():
                cmd.extend(["--template", str(template_path)])
                cmd.extend(["--pdf-engine", "xelatex"])

        try:
            result = subprocess.run(
                cmd,
                capture_output=True,
                text=True,
                timeout=120
            )

            if result.returncode != 0:
                raise HTTPException(
                    status_code=500,
                    detail=f"Conversion failed: {result.stderr}"
                )

            output_content = output_file.read_bytes()

            mime_types = {
                "pdf": "application/pdf",
                "docx": "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
                "odt": "application/vnd.oasis.opendocument.text",
                "latex": "application/x-latex"
            }

            return Response(
                content=output_content,
                media_type=mime_types.get(output_format, "application/octet-stream")
            )

        except Exception as e:
            logger.error(f"File conversion error: {e}")
            raise HTTPException(status_code=500, detail=str(e))
