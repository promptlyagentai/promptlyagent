import os
import tempfile
import requests
from fastapi import FastAPI, HTTPException, UploadFile, File
from pydantic import BaseModel
from markitdown import MarkItDown
import logging
from openai import OpenAI

# Set up logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

app = FastAPI(title="MarkItDown Web Service", version="1.0.0")

def get_markitdown_instance():
    """Create a MarkItDown instance with proper configuration for images"""
    # Check for OpenAI API key for image processing
    openai_api_key = os.getenv('OPENAI_API_KEY')
    
    if openai_api_key:
        try:
            # Configure OpenAI client for image analysis
            client = OpenAI(api_key=openai_api_key)
            logger.info("Using OpenAI for enhanced image processing")
            return MarkItDown(llm_client=client, llm_model="gpt-4.1-mini")
        except Exception as e:
            logger.warning(f"Failed to configure OpenAI client: {e}")
    
    # Fallback to basic MarkItDown without LLM
    logger.info("Using basic MarkItDown without LLM image analysis")
    return MarkItDown()

def is_image_file(filename: str) -> bool:
    """Check if file is an image based on extension"""
    if not filename:
        return False
    
    image_extensions = {'.jpg', '.jpeg', '.png', '.gif', '.bmp', '.webp', '.tiff', '.tif'}
    ext = os.path.splitext(filename.lower())[1]
    return ext in image_extensions

class ConvertRequest(BaseModel):
    url: str
    format: str = "markdown"

class ConvertResponse(BaseModel):
    markdown: str
    url: str = None
    filename: str = None
    success: bool
    metadata: dict

@app.get("/health")
async def health_check():
    return {"status": "healthy", "service": "markitdown"}

@app.post("/convert", response_model=ConvertResponse)
async def convert_url(request: ConvertRequest):
    try:
        logger.info(f"Converting URL: {request.url}")
        
        # Download the content from URL
        response = requests.get(request.url, timeout=30, headers={
            'User-Agent': 'MarkItDown-Service/1.0.0'
        })
        response.raise_for_status()
        
        # Create temporary file
        with tempfile.NamedTemporaryFile(delete=False, suffix='.html') as tmp_file:
            tmp_file.write(response.content)
            tmp_file_path = tmp_file.name
        
        try:
            # Convert using MarkItDown
            md = get_markitdown_instance()
            result = md.convert(tmp_file_path)
            
            markdown_content = result.text_content
            
            logger.info(f"Successfully converted URL: {request.url}")
            
            return ConvertResponse(
                markdown=markdown_content,
                url=request.url,
                success=True,
                metadata={
                    "content_type": response.headers.get('content-type', 'unknown'),
                    "content_length": len(response.content),
                    "markdown_length": len(markdown_content)
                }
            )
            
        finally:
            # Clean up temporary file
            os.unlink(tmp_file_path)
            
    except requests.RequestException as e:
        logger.error(f"Failed to download URL {request.url}: {str(e)}")
        raise HTTPException(status_code=400, detail=f"Failed to download URL: {str(e)}")
    except Exception as e:
        logger.error(f"Failed to convert URL {request.url}: {str(e)}")
        raise HTTPException(status_code=500, detail=f"Conversion failed: {str(e)}")

@app.post("/convert-file", response_model=ConvertResponse)
async def convert_file(file: UploadFile = File(...)):
    try:
        logger.info(f"Converting uploaded file: {file.filename}")
        
        # Validate file size (max 50MB)
        file_content = await file.read()
        if len(file_content) > 50 * 1024 * 1024:
            raise HTTPException(status_code=413, detail="File too large (max 50MB)")
        
        # Create temporary file with original extension to help MarkItDown detect file type
        file_extension = ""
        if file.filename and "." in file.filename:
            file_extension = "." + file.filename.split(".")[-1].lower()
        
        with tempfile.NamedTemporaryFile(delete=False, suffix=file_extension) as tmp_file:
            tmp_file.write(file_content)
            tmp_file_path = tmp_file.name
        
        try:
            # Convert using MarkItDown
            md = get_markitdown_instance()
            result = md.convert(tmp_file_path)
            
            markdown_content = result.text_content
            
            # Enhanced metadata for images
            metadata = {
                "original_filename": file.filename,
                "content_type": file.content_type,
                "file_size": len(file_content),
                "markdown_length": len(markdown_content),
                "file_extension": file_extension,
                "is_image": is_image_file(file.filename or "")
            }
            
            # Add conversion metadata if available
            if hasattr(result, 'metadata') and result.metadata:
                metadata["conversion_metadata"] = result.metadata
            
            # Log processing details
            if is_image_file(file.filename or ""):
                logger.info(f"Successfully processed image file: {file.filename} (OCR/Vision: {len(markdown_content)} chars)")
            else:
                logger.info(f"Successfully converted file: {file.filename}")
            
            return ConvertResponse(
                markdown=markdown_content,
                filename=file.filename,
                success=True,
                metadata=metadata
            )
            
        finally:
            # Clean up temporary file
            os.unlink(tmp_file_path)
            
    except HTTPException:
        # Re-raise HTTP exceptions
        raise
    except Exception as e:
        logger.error(f"Failed to convert file {file.filename}: {str(e)}")
        raise HTTPException(status_code=500, detail=f"File conversion failed: {str(e)}")

if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="0.0.0.0", port=8000)
