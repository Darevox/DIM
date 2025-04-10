<?php

    use Illuminate\Foundation\Application;
    use Illuminate\Foundation\Configuration\Exceptions;
    use Illuminate\Foundation\Configuration\Middleware;
    use Illuminate\Auth\AuthenticationException;
    use App\Http\Middleware\FilamentAdminMiddleware;
    use App\Http\Middleware\SetLocale;
    use Illuminate\Console\Scheduling\Schedule;
    use App\Console\Commands\ExpireSubscriptions;

return Application::configure(basePath: dirname(__DIR__))
                      ->withRouting(
                          web: __DIR__.'/../routes/web.php',
                          api: __DIR__.'/../routes/api.php',
                          commands: __DIR__.'/../routes/console.php',
                          health: '/up',
                      )
                      ->withMiddleware(function (Middleware $middleware) {
                        $middleware->web(append: [
                            SetLocale::class
                        ]);
                          $middleware->alias([
                              'admin' => FilamentAdminMiddleware::class,
                          ]);
                      })
                      ->withSchedule(function (Schedule $schedule) {
                          $schedule->command('subscriptions:expire')->daily();
                      })
                      ->withExceptions(function (Exceptions $exceptions) {
                          //
                      })->create();
