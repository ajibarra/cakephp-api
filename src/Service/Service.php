<?php
/**
 * Copyright 2016, Cake Development Corporation (http://cakedc.com)
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright 2016, Cake Development Corporation (http://cakedc.com)
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */

namespace CakeDC\Api\Service;

use Cake\Event\EventDispatcherInterface;
use Cake\Event\EventDispatcherTrait;
use Cake\Event\EventListenerInterface;
use Cake\Event\EventManager;
use CakeDC\Api\Routing\ApiRouter;
use CakeDC\Api\Service\Action\DummyAction;
use CakeDC\Api\Service\Action\Result;
use CakeDC\Api\Service\Exception\MissingActionException;
use CakeDC\Api\Service\Exception\MissingParserException;
use CakeDC\Api\Service\Exception\MissingRendererException;
use CakeDC\Api\Service\Renderer\BaseRenderer;
use CakeDC\Api\Service\RequestParser\BaseParser;
use Cake\Core\App;
use Cake\Core\Configure;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\Http\Client\Response;
use Cake\Routing\RouteBuilder;
use Cake\Utility\Hash;
use Cake\Utility\Inflector;
use Exception;

/**
 * Class Service
 */
abstract class Service implements EventListenerInterface, EventDispatcherInterface
{
    use EventDispatcherTrait;

    /**
     * Extensions to load and attach to listener
     *
     * @var array
     */
    public $extensions = [];

    /**
     * Actions routes description map, indexed by action name.
     *
     * @var array
     */
    protected $_actions = [];

    /**
     * Actions classes map, indexed by action name.
     *
     * @var array
     */
    protected $_actionsClassMap = [];

    /**
     * Service url acceptable extensions list.
     *
     * @var array
     */
    protected $_routeExtensions = ['json'];

    /**
     *
     *
     * @var string
     */
    protected $_routePrefix = '';

    /**
     * Service name
     *
     * @var string
     */
    protected $_name = null;

    /**
     * Service version.
     *
     * @var int
     */
    protected $_version;

    /**
     * Parser class to process the HTTP request.
     *
     * @var BaseParser
     */
    protected $_parser;

    /**
     * Renderer class to build the HTTP response.
     *
     * @var BaseRenderer
     */
    protected $_renderer;

    /**
     * The parser class.
     *
     * @var string
     */
    protected $_parserClass = null;

    /**
     * The Renderer class.
     *
     * @var string
     */
    protected $_rendererClass = null;

    /**
     * Dependent services names list
     *
     * @var array<string>
     */
    protected $_innerServices = [];

    /**
     * Parent service instance.
     *
     * @var Service
     */
    protected $_parentService;

    /**
     * Service Action Result object.
     *
     * @var Result
     */
    protected $_result;

    /**
     * Base url for service.
     *
     * @var string
     */
    protected $_baseUrl;

    /**
     * Request
     *
     * @var \Cake\Network\Request
     */
    protected $_request;

    /**
     * Request
     *
     * @var \Cake\Network\Response
     */
    protected $_response;

    /**
     * @var string
     */
    protected $_corsSuffix = '_cors';

    /**
     * Extension registry.
     *
     * @var \CakeDC\Api\Service\ExtensionRegistry
     */
    protected $_extensions;

    /**
     * Service constructor.
     *
     * @param array $config Service configuration.
     */
    public function __construct(array $config = [])
    {
        if (isset($config['request'])) {
            $this->request($config['request']);
        }
        if (isset($config['response'])) {
            $this->response($config['response']);
        }
        if (isset($config['baseUrl'])) {
            $this->_baseUrl = $config['baseUrl'];
        }
        if (isset($config['service'])) {
            $this->name($config['service']);
        }
        if (isset($config['version'])) {
            $this->version($config['version']);
        }
        if (isset($config['classMap'])) {
            $this->_actionsClassMap = Hash::merge($this->_actionsClassMap, $config['classMap']);
        }
		
        if (!empty($config['Extension'])) {
            $this->extensions = (Hash::merge($this->extensions, $config['Extension']));
        }
        $extensionRegistry = $eventManager = null;
        if (!empty($config['eventManager'])) {
            $eventManager = $config['eventManager'];
        }
        $this->_eventManager = $eventManager ?: new EventManager();

        $this->initialize();
        $this->_initializeParser($config);
        $this->_initializeRenderer($config);
        $this->_eventManager->on($this);
        $this->extensions($extensionRegistry);
        $this->_loadExtensions();
		
    }

    /**
     * Get and set service name.
     *
     * @param string $name Service name.
     * @return string
     */
    public function name($name = null)
    {
        if ($name === null) {
            return $this->_name;
        }
        $this->_name = $name;

        return $this->_name;
    }

    /**
     * Get and set service version.
     *
     * @param int $version Version number.
     * @return int
     */
    public function version($version = null)
    {
        if ($version === null) {
            return $this->_version;
        }
        $this->_version = $version;

        return $this->_version;
    }

    /**
     * Initialize method
     *
     * @return void
     */
    public function initialize()
    {
        if ($this->_name === null) {
            $className = (new \ReflectionClass($this))->getShortName();
            $this->name(Inflector::underscore(str_replace('Service', '', $className)));
        }
    }

    /**
     * Service parser configuration method.
     *
     * @param BaseParser $parser A Parser instance.
     * @return BaseParser
     */
    public function parser(BaseParser $parser = null)
    {
        if ($parser === null) {
            return $this->_parser;
        }
        $this->_parser = $parser;

        return $this->_parser;
    }

    /**
     * Get and set request.
     *
     * @param \Cake\Network\Request $request A request object.
     * @return \Cake\Network\Request
     */
    public function request($request = null)
    {
        if ($request === null) {
            return $this->_request;
        }

        $this->_request = $request;

        return $this->_request;
    }

    /**
     * Get the service route scopes and their connected routes.
     *
     * @return array
     */
    public function routes()
    {
        return $this->_routesWrapper(function () {
            return ApiRouter::routes();
        });
    }

    /**
     * @param callable $callable Wrapped router instance.
     * @return mixed
     */
    protected function _routesWrapper(callable $callable)
    {
        $this->resetRoutes();
        $this->loadRoutes();
        ApiRouter::$initialized = true;
        $result = $callable();
        $this->resetRoutes();

        return $result;
    }

    /**
     * Reset to default application routes.
     *
     * @return void
     */
    public function resetRoutes()
    {
        ApiRouter::reload();
    }

    /**
     * Initialize service level routes
     *
     * @return void
     */
    public function loadRoutes()
    {
        $defaultOptions = $this->routerDefaultOptions();
        ApiRouter::scope('/', $defaultOptions, function (RouteBuilder $routes) use ($defaultOptions) {
            if (is_array($this->_routeExtensions)) {
                $routes->extensions($this->_routeExtensions);
            }
            if (!empty($defaultOptions['map'])) {
                $routes->resources($this->name(), $defaultOptions);
            }
        });
    }

    /**
     * Build router settings.
     * This implementation build action map for resource routes based on Service actions.
     *
     * @return array
     */
    public function routerDefaultOptions()
    {
        $mapList = [];
        foreach ($this->_actions as $alias => $map) {
            if (is_numeric($alias)) {
                $alias = $map;
                $map = [];
            }
            $mapCors = false;
            if (!empty($map['mapCors'])) {
                $mapCors = $map['mapCors'];
                unset($map['mapCors']);
            }
            $mapList[$alias] = $map;
            $mapList[$alias] += ['method' => 'GET', 'path' => '', 'action' => $alias];
            if ($mapCors) {
                $map['method'] = 'OPTIONS';
                $map += ['path' => '', 'action' => $alias . $this->_corsSuffix];
                $mapList[$alias . $this->_corsSuffix] = $map;
            }
        }

        return [
            'map' => $mapList
        ];
    }

    /**
     * Finds URL for specified action.
     *
     * Returns an URL pointing to a combination of controller and action.
     *
     * @param string|array|null $route An array specifying any of the following:
     *   'controller', 'action', 'plugin' additionally, you can provide routed
     *   elements or query string parameters. If string it can be name any valid url
     *   string.
     * @return string Full translated URL with base path.
     * @throws \Cake\Core\Exception\Exception When the route name is not found
     */
    public function routeUrl($route)
    {
        return $this->_routesWrapper(function () use ($route) {
            return ApiRouter::url($route);
        });
    }

    /**
     * Reverses a parsed parameter array into a string.
     *
     * @param \Cake\Network\Request|array $params The params array or
     *     Cake\Network\Request object that needs to be reversed.
     * @return string The string that is the reversed result of the array
     */
    public function routeReverse($params)
    {
        return $this->_routesWrapper(function () use ($params) {
            try {
                return ApiRouter::reverse($params);
            } catch (Exception $e) {
                return null;
            }
        });
    }

    /**
     * Dispatch service call.
     *
     * @return \CakeDC\Api\Service\Action\Result
     */
    public function dispatch()
    {
        try {
			$this->dispatchEvent('Service.beforeDispatch', ['service' => $this]);
            $action = $this->buildAction();
			$this->dispatchEvent('Service.beforeProcess', ['service' => $this, 'action' => $this]);
            $result = $action->process();

            if ($result instanceof Result) {
                $this->result($result);
            }  else {
                $this->result()->data($result);
                $this->result()->code(200);
            }
        } catch (RecordNotFoundException $e) {
            $this->result()->code(404);
            $this->result()->exception($e);
        } catch (Exception $e) {
            $code = $e->getCode();
            if (!is_int($code) || $code < 100 || $code >= 600) {
                $this->result()->code(500);
            }
            $this->result()->exception($e);
        }
		$this->dispatchEvent('Service.afterDispatch', ['service' => $this]);

        return $this->result();
    }

    /**
     * Build action instance
     *
     * @return \CakeDC\Api\Service\Action\Action
     * @throws Exception
     */
    public function buildAction()
    {
        $route = $this->parseRoute($this->baseUrl());
        if (empty($route)) {
            throw new MissingActionException('Invalid Action Route:' . $this->baseUrl()); // InvalidActionException
        }
        $service = null;
        $serviceName = Inflector::underscore($route['controller']);
        if ($serviceName == $this->name()) {
            $service = $this;
        }
        if (in_array($serviceName, $this->_innerServices)) {
            $options = [
                'version' => $this->version(),
                'request' => $this->request(),
                'response' => $this->response(),
                'refresh' => true,
            ];
            $service = ServiceRegistry::get($serviceName, $options);
            $service->parent($this);
        }
        $action = $route['action'];
        list($namespace, $serviceClass) = namespaceSplit(get_class($service));
        $actionPrefix = substr($serviceClass, 0, -7);
        $actionClass = $namespace . '\\Action\\' . $actionPrefix . Inflector::camelize($action) . 'Action';
        if (class_exists($actionClass)) {
            return $service->buildActionClass($actionClass, $route);
        }
        if (array_key_exists($action, $this->_actionsClassMap)) {
            $actionClass = $this->_actionsClassMap[$action];

            return $service->buildActionClass($actionClass, $route);
        }
        throw new MissingActionException(['class' => $actionClass]);
    }

    /**
     * Parses given URL string. Returns 'routing' parameters for that URL.
     *
     * @param string $url URL to be parsed
     * @return array Parsed elements from URL
     * @throws \Cake\Routing\Exception\MissingRouteException When a route cannot be handled
     */
    public function parseRoute($url)
    {
        return $this->_routesWrapper(function () use ($url) {
            return ApiRouter::parse($url);
        });
    }

    /**
     * Build base url
     *
     * @return string
     */
    public function baseUrl()
    {
        if (!empty($this->_baseUrl)) {
            return $this->_baseUrl;
        }

        $result = '/' . $this->name();

        return $result;
    }

    /**
     * Parent service get and set methods
     *
     * @param Service $service Parent Service instance.
     * @return Service
     */
    public function parent(Service $service = null)
    {
        if ($service === null) {
            return $this->_parentService;
        }
        $this->_parentService = $service;

        return $this->_parentService;
    }

    /**
     * Build action class
     *
     * @param string $class Class name.
     * @param array $route Activated route.
     * @return mixed
     */
    public function buildActionClass($class, $route)
    {
        $instance = new $class($this->_actionOptions($route));

        return $instance;
    }

    /**
     * Action constructor options.
     *
     * @param array $route Activated route.
     * @return array
     */
    protected function _actionOptions($route)
    {
        $actionName = $route['action'];

        $options = [
            'name' => $actionName,
            'service' => $this,
            'route' => $route,
        ];
        $options += (new ConfigReader())->actionOptions($this->name(), $actionName, $this->version());

        return $options;
    }

    /**
     * @return \CakeDC\Api\Service\Action\Result
     */
    public function result($value = null)
    {
        if ($this->_parentService !== null) {
            return $this->_parentService->result($value);
        }
        if ($value instanceof Result) {
            $this->_result = $value;
        }
        if ($this->_result === null) {
            $this->_result = new Result();
        }

        return $this->_result;
    }

    /**
     * Fill up response and stop execution.
     *
     * @param Result $result A Result instance.
     * @return Response
     */
    public function respond($result = null)
    {
        if ($result === null) {
            $result = $this->result();
        }
        $this->response()
             ->statusCode($result->code());
        if ($result->exception() !== null) {
            $this->renderer()
                 ->error($result->exception());
        } else {
            $this->renderer()
                 ->response($result);
        }

        return $this->response();
    }

    /**
     * Get and set response.
     *
     * @param \Cake\Network\Response $response  A Response object.
     * @return \Cake\Network\Response
     */
    public function response($response = null)
    {
        if ($response === null) {
            return $this->_response;
        }

        $this->_response = $response;

        return $this->_response;
    }

    /**
     * Service renderer configuration method.
     *
     * @param BaseRenderer $renderer A Renderer instance.
     * @return BaseRenderer
     */
    public function renderer(BaseRenderer $renderer = null)
    {
        if ($renderer === null) {
            return $this->_renderer;
        }
        $this->_renderer = $renderer;

        return $this->_renderer;
    }

    /**
     * Define action config.
     *
     * @param string $actionName Action name.
     * @param string $className Class name.
     * @param array $route Route config.
     * @return void
     */
    public function mapAction($actionName, $className, $route)
    {
        $route += ['mapCors' => false];
        $this->_actionsClassMap[$actionName] = $className;
        if ($route['mapCors']) {
            $this->_actionsClassMap[$actionName . $this->_corsSuffix] = DummyAction::class;
        }
        if (!isset($route['path'])) {
            $route['path'] = $actionName;
        }
        $this->_actions[$actionName] = $route;
    }
	
    /**
     * @return array
     */
    public function implementedEvents()
    {
        $eventMap = [
            'Service.beforeDispatch' => 'beforeDispatch',
            'Service.beforeProcess' => 'beforeProcess',
            'Service.afterDispatch' => 'afterDispatch',
        ];
        $events = [];

        foreach ($eventMap as $event => $method) {
            if (!method_exists($this, $method)) {
                continue;
            }
            $events[$event] = $method;
        }

        return $events;
    }

    /**
     * Get the extension registry for this service.
     *
     * If called with the first parameter, it will be set as the action $this->_extensions property
     *
     * @param \CakeDC\Api\Service\ExtensionRegistry|null $extensions Extension registry.
     *
     * @return \CakeDC\Api\Service\ExtensionRegistry
     */
    public function extensions($extensions = null)
    {
        if ($extensions === null && $this->_extensions === null) {
            $this->_extensions = new ExtensionRegistry($this);
        }
        if ($extensions !== null) {
            $this->_extensions = $extensions;
        }

        return $this->_extensions;
    }

    /**
     * Loads the defined extensions using the Extension factory.
     *
     * @return void
     */
    protected function _loadExtensions()
    {
        if (empty($this->extensions)) {
            return;
        }
        $registry = $this->extensions();
        $extensions = $registry->normalizeArray($this->extensions);
        foreach ($extensions as $properties) {
            $instance = $registry->load($properties['class'], $properties['config']);
            $this->_eventManager->on($instance);
        }
    }	

    /**
     * Initialize parser.
     *
     * @param array $config Service options
     * @return void
     */
    protected function _initializeParser(array $config)
    {
        if (empty($this->_parserClass) && isset($config['parserClass'])) {
            $this->_parserClass = $config['parserClass'];
        }
        $parserClass = Configure::read('Api.parser');
        if (empty($this->_parserClass) && !empty($parserClass)) {
            $this->_parserClass = $parserClass;
        }

        $class = App::className($this->_parserClass, 'Service/RequestParser', 'Parser');
        if (!class_exists($class)) {
            throw new MissingParserException(['class' => $this->_parserClass]);
        }
        $this->_parser = new $class($this);
    }

    /**
     * Initialize renderer.
     *
     * @param array $config Service options.
     * @return void
     */
    protected function _initializeRenderer(array $config)
    {
        if (empty($this->_rendererClass) && isset($config['rendererClass'])) {
            $this->_rendererClass = $config['rendererClass'];
        }
        $rendererClass = Configure::read('Api.renderer');
        if (empty($this->_rendererClass) && !empty($rendererClass)) {
            $this->_rendererClass = $rendererClass;
        }

        $class = App::className($this->_rendererClass, 'Service/Renderer', 'Renderer');
        if (!class_exists($class)) {
            throw new MissingRendererException(['class' => $this->_rendererClass]);
        }
        $this->_renderer = new $class($this);
    }
}
