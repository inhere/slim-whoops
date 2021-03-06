<?php

namespace Inhere\Whoops;

use Inhere\Whoops\Handler\RecordLogHandler;
use Inhere\Whoops\Handler\ErrorHandler;
use Whoops\Run as WhoopsRun;
use Whoops\Util\Misc;
use Whoops\Handler\PrettyPageHandler;
use Whoops\Handler\JsonResponseHandler;
use Slim\App;
use Slim\Http\Environment;
use Slim\Http\Request;
use Slim\Container;

/**
 * Class WhoopsMiddleware
 * @package Inhere\Whoops
 */
class WhoopsMiddleware
{
    /**
     * @var App
     */
    private $app;

    /**
     * @var array
     */
    private $handlers = [];

    public function __construct(App $app, $handlers = [])
    {
        $this->app = $app;
        $this->handlers = $handlers;
    }

    /**
     * @param Request $request
     * @param         $response
     * @param         $next
     * @return mixed
     */
    public function __invoke(Request $request, $response, $next)
    {
        $app = $this->app ?: $next;

        $whoops = new WhoopsRun;
        $container = $this->app->getContainer();
        $settings = $container['settings']->get('whoops');

        if (isset($settings['debug']) && (bool)$settings['debug'] === true) {
            /** @var Environment $environment */
            $environment = $container['environment'];

            // Enable PrettyPageHandler with editor options
            $prettyPageHandler = new PrettyPageHandler();

            if (!empty($settings['editor'])) {
                $prettyPageHandler->setEditor($settings['editor']);
            }

            // Add more information to the PrettyPageHandler
            $prettyPageHandler->addDataTable('Slim Application', [
                'Application Class' => get_class($this->app),
                'Script Name'       => $environment->get('SCRIPT_NAME'),
                'Request URI'       => $environment->get('PATH_INFO') ?: '<none>',
            ]);

            $prettyPageHandler->addDataTable('Slim Application (Request)', array(
                'Accept Charset'  => $request->getHeader('ACCEPT_CHARSET') ?: '<none>',
                'Content Charset' => $request->getContentCharset() ?: '<none>',
                'Path'            => $request->getUri()->getPath(),
                'Query String'    => $request->getUri()->getQuery() ?: '<none>',
                'HTTP Method'     => $request->getMethod(),
                'Base URL'        => (string)$request->getUri(),
                'REMOTE ADDR'     => $environment->get('REMOTE_ADDR'),
                'Scheme'          => $request->getUri()->getScheme(),
                'Port'            => $request->getUri()->getPort(),
                'Host'            => $request->getUri()->getHost(),
            ));

            // Set Whoops to default exception handler
            $whoops->pushHandler($prettyPageHandler);

            // Enable JsonResponseHandler when request is AJAX
            if (Misc::isAjaxRequest()) {
                $whoops->pushHandler(new JsonResponseHandler());
            }
        }

        // record log to file
        $logHandler = new RecordLogHandler();

        $logger = isset($container['errLogger']) ? $container['errLogger'] : $container['logger'];
        $logHandler->setLogger($logger);
        $logHandler->setOptions($settings);

        $whoops->pushHandler($logHandler);
        $whoops->register();

        $container['whoops'] = $whoops;
        $container['phpErrorHandler'] = $container['errorHandler'] = function ($c) use ($logHandler) {
            /** @var Container $c */
            return new ErrorHandler($logHandler, $c->has('whoops') ? $c->get('whoops') : null);
        };

        return $app($request, $response);
    }

}
