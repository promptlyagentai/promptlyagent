<?php

namespace App\Tools;

use App\Tools\Concerns\SafeJsonResponse;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Prism\Prism\Facades\Tool;

/**
 * RouteInspectorTool - Laravel Route and Endpoint Discovery.
 *
 * Prism tool for inspecting Laravel application routes, controllers, and endpoints.
 * Provides detailed information about available routes without executing them.
 *
 * Route Information:
 * - HTTP methods (GET, POST, PUT, DELETE, etc.)
 * - URI patterns and parameters
 * - Controller and action names
 * - Middleware applied
 * - Route names
 *
 * Filtering Options:
 * - By HTTP method
 * - By URI pattern
 * - By controller
 * - By middleware
 * - By route name
 *
 * Response Format:
 * - Route list with full details
 * - Parameter placeholders identified
 * - Middleware stack per route
 * - Controller method signatures
 *
 * Use Cases:
 * - API endpoint discovery
 * - Understanding application structure
 * - Planning API requests
 * - Debugging routing issues
 *
 * @see \Illuminate\Routing\Router
 */
class RouteInspectorTool
{
    use SafeJsonResponse;

    public static function create()
    {
        return Tool::as('route_inspector')
            ->for('Inspect Laravel routes and map them to code files. List all routes, find specific routes, or trace route handlers to their implementation files.')
            ->withStringParameter('action', 'Action: list_routes, find_route, trace_handler')
            ->withStringParameter('query', 'Search query (route name, URI, or handler name) - required for find_route and trace_handler', false)
            ->withStringParameter('method', 'Filter by HTTP method (GET, POST, etc.) - optional for list_routes', false)
            ->withNumberParameter('limit', 'Limit number of results for list_routes (default: 50, max: 200)', false)
            ->using(function (
                string $action,
                ?string $query = null,
                ?string $method = null,
                ?int $limit = null
            ) {
                return static::executeRouteInspection([
                    'action' => $action,
                    'query' => $query,
                    'method' => $method,
                    'limit' => $limit ?? 50,
                ]);
            });
    }

    protected static function executeRouteInspection(array $arguments = []): string
    {
        // Get StatusReporter for progress updates
        $statusReporter = app()->has('status_reporter') ? app('status_reporter') : null;

        try {
            // Validate input
            $validator = Validator::make($arguments, [
                'action' => 'required|string|in:list_routes,find_route,trace_handler',
                'query' => 'nullable|string|max:500',
                'method' => 'nullable|string|in:GET,POST,PUT,PATCH,DELETE,OPTIONS,HEAD',
                'limit' => 'integer|min:1|max:200',
            ]);

            if ($validator->fails()) {
                Log::warning('RouteInspectorTool: Validation failed', [
                    'errors' => $validator->errors()->all(),
                ]);

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Invalid arguments: '.implode(', ', $validator->errors()->all()),
                ], 'RouteInspectorTool');
            }

            $validated = $validator->validated();
            $action = $validated['action'];
            $query = $validated['query'] ?? null;
            $method = $validated['method'] ?? null;
            $limit = $validated['limit'];

            // Report what we're doing
            if ($statusReporter) {
                $message = match ($action) {
                    'list_routes' => 'Listing application routes...',
                    'find_route' => "Searching for route: {$query}",
                    'trace_handler' => "Tracing handler for: {$query}",
                    default => 'Inspecting routes...',
                };
                $statusReporter->report('route_inspector', $message, true, false);
            }

            // Route to appropriate action
            $result = match ($action) {
                'list_routes' => static::listRoutes($method, $limit, $statusReporter),
                'find_route' => static::findRoute($query, $statusReporter),
                'trace_handler' => static::traceHandler($query, $statusReporter),
                default => ['success' => false, 'error' => 'Unknown action'],
            };

            return static::safeJsonEncode($result, 'RouteInspectorTool');

        } catch (\Exception $e) {
            Log::error('RouteInspectorTool: Exception during execution', [
                'action' => $arguments['action'] ?? 'unknown',
                'error_message' => $e->getMessage(),
                'error_type' => get_class($e),
            ]);

            return static::safeJsonEncode([
                'success' => false,
                'error' => 'Route inspection failed: '.$e->getMessage(),
            ], 'RouteInspectorTool');
        }
    }

    protected static function listRoutes(?string $method, int $limit, $statusReporter = null): array
    {
        try {
            $routes = Route::getRoutes();
            $routeData = [];

            foreach ($routes as $route) {
                // Filter by method if specified
                if ($method && ! in_array($method, $route->methods())) {
                    continue;
                }

                $routeData[] = static::formatRouteData($route);

                if (count($routeData) >= $limit) {
                    break;
                }
            }

            if ($statusReporter) {
                $statusReporter->report('route_inspector', 'Listed '.count($routeData).' routes', false, false);
            }

            return [
                'success' => true,
                'data' => [
                    'routes' => $routeData,
                    'count' => count($routeData),
                    'filter_method' => $method,
                    'note' => count($routeData) >= $limit ? "Results limited to {$limit} routes" : null,
                ],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to list routes: '.$e->getMessage(),
            ];
        }
    }

    protected static function findRoute(?string $query, $statusReporter = null): array
    {
        if (! $query) {
            return [
                'success' => false,
                'error' => 'query parameter is required for find_route action',
            ];
        }

        try {
            $routes = Route::getRoutes();
            $matchingRoutes = [];

            foreach ($routes as $route) {
                // Search by name
                if ($route->getName() && str_contains(strtolower($route->getName()), strtolower($query))) {
                    $matchingRoutes[] = static::formatRouteData($route);

                    continue;
                }

                // Search by URI
                if (str_contains(strtolower($route->uri()), strtolower($query))) {
                    $matchingRoutes[] = static::formatRouteData($route);

                    continue;
                }

                // Search by action
                if (str_contains(strtolower($route->getActionName()), strtolower($query))) {
                    $matchingRoutes[] = static::formatRouteData($route);

                    continue;
                }
            }

            if ($statusReporter) {
                $statusReporter->report('route_inspector', 'Found '.count($matchingRoutes).' matching routes', false, false);
            }

            return [
                'success' => true,
                'data' => [
                    'query' => $query,
                    'routes' => $matchingRoutes,
                    'count' => count($matchingRoutes),
                ],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => "Failed to find route '{$query}': ".$e->getMessage(),
            ];
        }
    }

    protected static function traceHandler(?string $query, $statusReporter = null): array
    {
        if (! $query) {
            return [
                'success' => false,
                'error' => 'query parameter is required for trace_handler action',
            ];
        }

        try {
            // First find the route
            $routes = Route::getRoutes();
            $targetRoute = null;

            // Try to find by name first
            if ($routes->hasNamedRoute($query)) {
                $targetRoute = $routes->getByName($query);
            } else {
                // Try to find by URI or action
                foreach ($routes as $route) {
                    if ($route->uri() === $query || $route->getActionName() === $query) {
                        $targetRoute = $route;
                        break;
                    }
                }
            }

            if (! $targetRoute) {
                return [
                    'success' => false,
                    'error' => "Route not found: '{$query}'",
                ];
            }

            $handler = $targetRoute->getActionName();
            $handlerInfo = static::analyzeHandler($handler);

            if ($statusReporter) {
                $filePath = $handlerInfo['file_path'] ?? 'not found';
                $statusReporter->report('route_inspector', "Traced handler to: {$filePath}", false, false);
            }

            return [
                'success' => true,
                'data' => [
                    'route' => static::formatRouteData($targetRoute),
                    'handler' => $handlerInfo,
                ],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => "Failed to trace handler for '{$query}': ".$e->getMessage(),
            ];
        }
    }

    protected static function formatRouteData($route): array
    {
        return [
            'uri' => $route->uri(),
            'name' => $route->getName(),
            'methods' => $route->methods(),
            'action' => $route->getActionName(),
            'middleware' => $route->middleware(),
            'domain' => $route->domain(),
        ];
    }

    protected static function analyzeHandler(string $handler): array
    {
        $handlerType = static::detectHandlerType($handler);
        $filePath = null;
        $details = [];

        switch ($handlerType) {
            case 'controller':
                $filePath = static::findControllerFile($handler);
                $details = static::parseControllerHandler($handler);
                break;

            case 'livewire':
                $filePath = static::findLivewireComponent($handler);
                $details = ['component_class' => $handler];
                break;

            case 'filament':
                $details = ['resource' => $handler, 'note' => 'FilamentPHP resource or page'];
                break;

            case 'closure':
                $details = ['note' => 'Closure/anonymous function'];
                break;

            default:
                $details = ['handler' => $handler];
        }

        return [
            'type' => $handlerType,
            'handler' => $handler,
            'file_path' => $filePath,
            'details' => $details,
        ];
    }

    protected static function detectHandlerType(string $handler): string
    {
        if ($handler === 'Closure') {
            return 'closure';
        }

        if (str_contains($handler, 'Livewire\\')) {
            return 'livewire';
        }

        if (str_contains($handler, 'Filament\\')) {
            return 'filament';
        }

        if (str_contains($handler, 'Controller')) {
            return 'controller';
        }

        return 'unknown';
    }

    protected static function findControllerFile(string $handler): ?string
    {
        // Parse handler like "App\Http\Controllers\FooController@method"
        $parts = explode('@', $handler);
        $className = $parts[0];

        // Convert namespace to file path
        $path = str_replace('\\', '/', str_replace('App\\', 'app/', $className)).'.php';

        $fullPath = base_path($path);
        if (File::exists($fullPath)) {
            return $path;
        }

        return null;
    }

    protected static function parseControllerHandler(string $handler): array
    {
        $parts = explode('@', $handler);

        return [
            'controller_class' => $parts[0] ?? null,
            'method' => $parts[1] ?? null,
        ];
    }

    protected static function findLivewireComponent(string $handler): ?string
    {
        // Livewire components can be in app/Livewire or app/Http/Livewire
        $className = str_replace('Livewire\\Volt\\', '', $handler);
        $className = str_replace('App\\Livewire\\', '', $className);
        $className = str_replace('App\\Http\\Livewire\\', '', $className);

        $possiblePaths = [
            'app/Livewire/'.$className.'.php',
            'app/Http/Livewire/'.$className.'.php',
        ];

        foreach ($possiblePaths as $path) {
            $fullPath = base_path($path);
            if (File::exists($fullPath)) {
                return $path;
            }
        }

        // Check for Volt components
        $voltPath = 'resources/views/livewire/'.strtolower(str_replace('\\', '/', $className)).'.blade.php';
        if (File::exists(base_path($voltPath))) {
            return $voltPath;
        }

        return null;
    }
}
