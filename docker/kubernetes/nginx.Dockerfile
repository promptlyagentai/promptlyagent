# ==============================================================================
# PromptlyAgent Nginx Sidecar Dockerfile
# ==============================================================================
# Lightweight Nginx image to run as sidecar with PHP-FPM in Kubernetes pods
# ==============================================================================

FROM nginx:alpine

# Set labels
LABEL maintainer="PromptlyAgent" \
      description="Nginx sidecar for PromptlyAgent Laravel application"

# Copy custom Nginx configuration
COPY docker/kubernetes/nginx.conf /etc/nginx/nginx.conf
COPY docker/kubernetes/nginx-site.conf /etc/nginx/conf.d/default.conf

# Create necessary directories
RUN mkdir -p /var/www/html/public

# Health check
HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
    CMD wget --quiet --tries=1 --spider http://localhost/health || exit 1

# Expose HTTP port
EXPOSE 80

# Run Nginx in foreground
CMD ["nginx", "-g", "daemon off;"]
