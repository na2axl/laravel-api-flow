<?php

declare(strict_types=1);

namespace Na2axl\LaravelApiFlow;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

class LaravelApiFlowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/flow.php', 'flow');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/flow.php' => config_path('flow.php'),
            ], 'api-flow-config');
        }

        $this->registerFlowRouteMacro();
    }

    /**
     * Route::flow('auth/register', RegisterFlowController::class) registers
     * one route per public step* method on the controller: stepInitiate
     * becomes GET /{prefix}/initiate, every other stepXxx becomes
     * POST /{prefix}/xxx (kebab-cased). Routes are named flow.{prefix}.{step}.
     */
    private function registerFlowRouteMacro(): void
    {
        if (Route::hasMacro('flow')) {
            return;
        }

        Route::macro('flow', function (string $prefix, string $controller) {
            return Route::prefix($prefix)
                ->controller($controller)
                ->group(function () use ($controller, $prefix): void {
                    try {
                        /** @var class-string $controller */
                        $reflection = new ReflectionClass($controller);
                        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

                        foreach ($methods as $method) {
                            $methodName = $method->getName();

                            if ($method->isStatic() || !Str::startsWith($methodName, 'step')) {
                                continue;
                            }

                            $uri = Str::kebab(substr($methodName, 4));
                            $httpMethod = $methodName === 'stepInitiate' ? 'get' : 'post';

                            Route::{$httpMethod}($uri, $methodName)
                                ->name("flow.{$prefix}.{$uri}");
                        }
                    } catch (ReflectionException) {
                        Log::warning("Flow controller '{$controller}' does not exist.");
                    }
                });
        });
    }
}
