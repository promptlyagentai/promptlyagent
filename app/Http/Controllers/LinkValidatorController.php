<?php

namespace App\Http\Controllers;

use App\Services\LinkValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Utility
 *
 * Utility endpoints for URL validation, metadata extraction, and link previews.
 * Used internally by the UI but available for API integration.
 *
 * ## Rate Limiting
 * - Validation operations: 60 requests/minute
 */
class LinkValidatorController extends Controller
{
    /**
     * The LinkValidator service instance.
     *
     * @var \App\Services\LinkValidator
     */
    protected $linkValidator;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(LinkValidator $linkValidator)
    {
        $this->linkValidator = $linkValidator;
    }

    /**
     * Validate URL and extract metadata
     *
     * Validate a URL and extract rich metadata including title, description, images,
     * and Open Graph tags. Useful for generating link previews and validating external
     * URLs before processing.
     *
     * **Security**: URL validation includes checks for private IP ranges, localhost,
     * and suspicious schemes to prevent SSRF attacks.
     *
     * @authenticated
     *
     * @bodyParam url string required The URL to validate and extract metadata from. Must be a valid HTTP/HTTPS URL. Example: https://example.com/article
     *
     * @response 200 scenario="Success" {"valid": true, "url": "https://example.com/article", "title": "Example Article", "description": "An interesting article about...", "image": "https://example.com/og-image.jpg", "domain": "example.com", "metadata": {"og:title": "Example Article", "og:description": "An interesting article...", "og:image": "https://example.com/og-image.jpg", "twitter:card": "summary_large_image"}}
     * @response 200 scenario="Invalid URL" {"valid": false, "error": "Invalid URL format", "url": "not-a-valid-url"}
     * @response 200 scenario="Unreachable URL" {"valid": false, "error": "URL is not reachable", "url": "https://nonexistent-domain-12345.com"}
     * @response 422 scenario="Validation Failed" {"message": "The url field is required.", "errors": {"url": ["The url field is required."]}}
     *
     * @responseField valid boolean Whether the URL is valid and reachable
     * @responseField url string The validated URL (normalized)
     * @responseField title string Page title extracted from HTML or Open Graph tags
     * @responseField description string Page description from meta tags or Open Graph
     * @responseField image string Primary image URL (og:image, twitter:image, or first image)
     * @responseField domain string Domain name extracted from URL
     * @responseField metadata object Raw metadata extracted (Open Graph, Twitter Card, etc.)
     * @responseField error string Error message if validation failed (only present when valid=false)
     */
    public function validate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => 'required|url',
        ]);

        $url = $validated['url'];

        $linkInfo = $this->linkValidator->validateAndExtractLinkInfo($url);

        return response()->json($linkInfo);
    }
}
