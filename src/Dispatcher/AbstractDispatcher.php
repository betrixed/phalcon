<?php

/**
 * This file is part of the Phalcon Framework.
 *
 * (c) Phalcon Team <team@phalcon.io>
 *
 * For the full copyright and license information, please view the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Phalcon\Dispatcher;

use Exception;
use Phalcon\Di\DiInterface;
use Phalcon\Di\AbstractInjectionAware;
use Phalcon\Dispatcher\Exception as DisException;
use Phalcon\Events\EventsAwareInterface;
use Phalcon\Events\ManagerInterface;
use Phalcon\Filter\FilterInterface;
use Phalcon\Mvc\Model\Binder;
use Phalcon\Mvc\Model\BinderInterface;


/**
 * This is the base class for Phalcon\Mvc\Dispatcher and Phalcon\Cli\Dispatcher.
 * This class can't be instantiated directly, you can use it to create your own
 * dispatchers.
 */
abstract class AbstractDispatcher extends AbstractInjectionAware implements DispatcherInterface, EventsAwareInterface
{
    protected $activeHandler;

    /**
     * @var array
     */
    protected $activeMethodMap = [];

    protected $actionName = null;

    /**
     * @var string
     */
    protected $actionSuffix = "Action";

    /**
     * @var array
     */
    protected $camelCaseMap = [];

    /**
     * @var string
     */
    protected $defaultAction = "";

    protected $defaultNamespace = null;

    protected $defaultHandler = null;

    /**
     * @var array
     */
    protected $handlerHashes = [];

    protected $handlerName = null;

    /**
     * @var string
     */
    protected $handlerSuffix = "";

    protected $eventsManager;

    /**
     * @var bool
     */
    protected $finished = false;

    /**
     * @var bool
     */
    protected $forwarded = false;

    /**
     * @var bool
     */
    protected $isControllerInitialize = false;
    protected $lastHandler = null;
    protected $modelBinder = null;

    /**
     * @var bool
     */
    protected $modelBinding = false;
    protected $moduleName = null;
    protected $namespaceName = null;

    /**
     * @var array
     */
    protected $params = [];
    protected $previousActionName = null;
    protected $previousHandlerName = null;
    protected $previousNamespaceName = null;
    protected $returnedValue = null;

    protected final function getContainer($msg) : DiInterface {
         $result = $this->container;
         if (!is_object($result)) {
             $this->{"throwDispatchException"}(
                DisException::containerServiceNotFound($msg, DisException::EXCEPTION_NO_DI));
         }
         return $result;
    }
    public function callActionMethod($handler, string $actionMethod, array $params = [])
    {
        return call_user_func_array( [$handler, $actionMethod],$params );
    }

    /**
     * Process the results of the router by calling into the appropriate
     * controller action(s) including any routing data or injected parameters.
     *
     * @return object|false Returns the dispatched handler class (the Controller for Mvc dispatching or a Task
     *                      for CLI dispatching) or <tt>false</tt> if an exception occurred and the operation was
     *                      stopped by returning <tt>false</tt> in the exception handler.
     *
     * @throws \Exception if any uncaught or unhandled exception occurs during the dispatcher process.
     */
    public function dispatch()
    {
        /*bool hasService, hasEventsManager;
        int numberDispatches;
        var value, handler, container, namespaceName, handlerName, actionName,
            params, eventsManager, handlerClass, status, actionMethod,
            modelBinder, bindCacheKey, isNewHandler, handlerHash, e; */

        $container = $this->getContainer("related dispatching services");

        $eventsManager = $this->eventsManager;
        $hasEM = is_object($eventsManager);
        $this->finished = true;

        if ($hasEM) {
            try {
                // Calling beforeDispatchLoop event
                // Note: Allow user to forward in the beforeDispatchLoop.
                if (($eventsManager->fire("dispatch:beforeDispatchLoop", $this) === false) && ($this->$finished !== false)) {
                    return false;
                }
            } catch (\Exception $e) {
                // Exception occurred in beforeDispatchLoop.

                /**
                 * The user can optionally forward now in the
                 * `dispatch:beforeException` event or return <tt>false</tt> to
                 * handle the exception and prevent it from bubbling. In the
                 * event the user does forward but does or does not return
                 * false, we assume the forward takes precedence. The returning
                 * false intuitively makes more sense when inside the dispatch
                 * loop and technically we are not here. Therefore, returning
                 * false only impacts whether non-forwarded exceptions are
                 * silently handled or bubbled up the stack. Note that this
                 * behavior is slightly different than other subsequent events
                 * handled inside the dispatch loop.
                 */

                $status = $this->{"handleException"}($e);

                if ($this->finished !== false) {
                    // No forwarding
                    if ($status === false) {
                        return false;
                    }

                    // Otherwise, bubble Exception
                    throw $e;
                }

                // Otherwise, user forwarded, continue
            }
        }

        $value = null;
        $handler = null;
         $numberDispatches = 0;
//            actionSuffix = this->actionSuffix, // never used
            $this->finished = false;

        while (!$this->finished) {
            $numberDispatches++;

            // Throw an exception after 256 consecutive forwards
            if ($numberDispatches === 256) {
                $this->{"throwDispatchException"}(
                    "Dispatcher has detected a cyclic routing causing stability problems",
                    DisException::EXCEPTION_CYCLIC_ROUTING
                );

                break;
            }

            $this->finished = true;

            $this->resolveEmptyProperties();

            if ($hasEM){
                try {
                    // Calling "dispatch:beforeDispatch" event
                    if ($eventsManager->fire("dispatch:beforeDispatch", this) === false || $this->finished === false) {
                        continue;
                    }
                } catch (\Exception $e) {
                    if ($this->{"handleException"}($e) === false || $this->finished === false) {
                        continue;
                    }

                    throw $e;
                }
            }

            $handlerClass =$this->getHandlerClass();

            /**
             * Handlers are retrieved as shared instances from the Service
             * Container
             */
            $hasService =  $container->has($handlerClass);

            if (!$hasService) {
                /**
                 * DI doesn't have a service with that name, try to load it
                 * using an autoloader
                 */
                $hasService =  class_exists($handlerClass);
            }

            // If the service can be loaded we throw an exception
            if (!$hasService) {
                $status =$this->{"throwDispatchException"}(
                    $handlerClass . " handler class cannot be loaded",
                    DisException::EXCEPTION_HANDLER_NOT_FOUND
                );

                if ($status === false && $this->finished === false) {
                    continue;
                }

                break;
            }

            $handler = $container->getShared($handlerClass);

            // Handlers must be only objects
            if (!is_object($handler)) {
                $status = $this->{"throwDispatchException"}(
                    "Invalid handler returned from the services container",
                    DisException::EXCEPTION_INVALID_HANDLER
                );

                if ($status === false && $this->finished === false) {
                    continue;
                }

                break;
            }

            // Check if the handler is new (hasn't been initialized).
            $handlerHash = spl_object_hash($handler);

            $isNewHandler = !(isset ($this->handlerHashes[$handlerHash]));

            if ($isNewHandler) {
                $this->handlerHashes[$handlerHash] = true;
            }

            $this->activeHandler = $handler;

            $namespaceName = $this->namespaceName;
            $handlerName = $this->handlerName;
            $actionName = $this->actionName;
            $params = $this->params;

            /**
             * Check if the params is an array
             */
            if (!is_array($params)) {
                /**
                 * An invalid parameter variable was passed throw an exception
                 */
                $status = $this->{"throwDispatchException"}(
                    "Action parameters must be an Array",
                    DisException::EXCEPTION_INVALID_PARAMS
                );

                if ($status === false && $this->finished === false) {
                    continue;
                }

                break;
            }

            // Check if the method exists in the handler
            $actionMethod = $this->getActiveMethod();

            if(!is_callable([$handler, $actionMethod])) {
                if ($hasEM) {
                    if ($eventsManager->fire("dispatch:beforeNotFoundAction", $this) === false) {
                        continue;
                    }

                    if ($this->finished === false) {
                        continue;
                    }
                }

                /**
                 * Try to throw an exception when an action isn't defined on the
                 * object
                 */
                $status = $this->{"throwDispatchException"}(
                    "Action '" . $actionName . "' was not found on handler '" . $handlerName . "'",
                    DisException::EXCEPTION_ACTION_NOT_FOUND
                );

                if ($status === false && $this->finished === false) {
                    continue;
                }

                break;
            }

            /**
             * In order to ensure that the `initialize()` gets called we'll
             * destroy the current handlerClass from the DI container in the
             * event that an error occurs and we continue out of this block.
             * This is necessary because there is a disjoin between retrieval of
             * the instance and the execution of the `initialize()` event. From
             * a coding perspective, it would have made more sense to probably
             * put the `initialize()` prior to the beforeExecuteRoute which
             * would have solved this. However, for posterity, and to remain
             * consistency, we'll ensure the default and documented behavior
             * works correctly.
             */
            if ($hasEM){
                try {
                    // Calling "dispatch:beforeExecuteRoute" event
                    if ($eventsManager->fire("dispatch:beforeExecuteRoute", $this) === false || $this->finished === false) {
                        $container->remove($handlerClass);
                        continue;
                    }
                } catch (\Exception $e) {
                    if ($this->{"handleException"}($e) === false || $this->finished === false) {
                        $container->remove($handlerClass);

                        continue;
                    }

                    throw $e;
                }
            }

            if (method_exists($handler, "beforeExecuteRoute")) {
                try {
                    // Calling "beforeExecuteRoute" as direct method
                    if ($handler->beforeExecuteRoute($this) === false || $this->finished === false) {
                        $container->remove($handlerClass);

                        continue;
                    }
                } catch (\Exception $e) {
                    if ($this->{"handleException"}($e) === false || $this->finished === false) {
                        $container->remove($handlerClass);
                        continue;
                    }

                    throw $e;
                }
            }

            /**
             * Call the "initialize" method just once per request
             *
             * Note: The `dispatch:afterInitialize` event is called regardless
             *       of the presence of an `initialize()` method. The naming is
             *       poor; however, the intent is for a more global "constructor
             *       is ready to go" or similarly "__onConstruct()" methodology.
             *
             * Note: In Phalcon 4.0, the `initialize()` and
             * `dispatch:afterInitialize` event will be handled prior to the
             * `beforeExecuteRoute` event/method blocks. This was a bug in the
             * original design that was not able to change due to widespread
             * implementation. With proper documentation change and blog posts
             * for 4.0, this change will happen.
             *
             * @see https://github.com/phalcon/cphalcon/pull/13112
             */
            if ($isNewHandler) {
                if (method_exists($handler, "initialize")) {
                    try {
                        $this->isControllerInitialize = true;

                        $handler->initialize();
                    } catch (\Exception $e) {
                        $this->isControllerInitialize = false;

                        /**
                         * If this is a dispatch exception (e.g. From
                         * forwarding) ensure we don't handle this twice. In
                         * order to ensure this doesn't happen all other
                         * exceptions thrown outside this method in this class
                         * should not call "throwDispatchException" but instead
                         * throw a normal Exception.
                         */
                        if ($this->{"handleException"}($e) === false || $this->finished === false) {
                            continue;
                        }

                        throw $e;
                    }
                }

                $this->isControllerInitialize = false;

                /**
                 * Calling "dispatch:afterInitialize" event
                 */
                if ($hasEM) {
                    try {
                        if ($eventsManager->fire("dispatch:afterInitialize", this) === false || $this->finished === false) {
                            continue;
                        }
                    } catch (\Exception $e) {
                        if ($this->{"handleException"}($e) === false || $this->finished === false) {
                            continue;
                        }

                        throw $e;
                    }
                }
            }

            if ($this->modelBinding) {
                $modelBinder = $this->modelBinder;
                $bindCacheKey = "_PHMB_" . $handlerClass . "_" . $actionMethod;

                $params = $modelBinder->bindToHandler(
                    $handler,
                    $params,
                    $bindCacheKey,
                    $actionMethod
                );
            }

            /**
             * Calling afterBinding
             */
            if ($hasEM) {
                if ($eventsManager->fire("dispatch:afterBinding", $this) === false) {
                    continue;
                }

                /**
                 * Check if the user made a forward in the listener
                 */
                if ($this->finished === false) {
                    continue;
                }
            }

            /**
             * Calling afterBinding as callback and event
             */
            if (method_exists($handler, "afterBinding")) {
                if ($handler->afterBinding($this) === false) {
                    continue;
                }

                /**
                 * Check if the user made a forward in the listener
                 */
                if ($this->finished === false) {
                    continue;
                }
            }

            /**
             * Save the current handler
             */
            $this->lastHandler = $handler;

            try {
                /**
                 * We update the latest value produced by the latest handler
                 */
                $this->returnedValue = $this->callActionMethod(
                    $handler,
                    $actionMethod,
                    $params
                );

                if ($this->finished === false) {
                    continue;
                }
            } catch (\Exception  $e) {
                if ($this->{"handleException"}($e) === false || $this->finished === false) {
                    continue;
                }

                throw $e;
            }

            /**
             * Calling "dispatch:afterExecuteRoute" event
             */
            if ($hasEM) {
                try {
                    if ($eventsManager->fire("dispatch:afterExecuteRoute", $this, $value) === false || $this->finished === false) {
                        continue;
                    }
                } catch (\Exception $e) {
                    if ($this->{"handleException"}($e) === false || $this->finished === false) {
                        continue;
                    }

                    throw $e;
                }
            }

            /**
             * Calling "afterExecuteRoute" as direct method
             */
            if (method_exists($handler, "afterExecuteRoute")) {
                try {
                    if ($handler->afterExecuteRoute($this, $value) === false || $this->finished === false) {
                        continue;
                    }
                } catch (\Exception $e) {
                    if ($this->{"handleException"}($e) === false || $this->finished === false) {
                        continue;
                    }

                    throw e;
                }
            }

            // Calling "dispatch:afterDispatch" event
             if($hasEM) {
                try {
                    $eventsManager->fire("dispatch:afterDispatch", $this, $value);
                } catch (\Exception $e) {
                    /**
                     * Still check for finished here as we want to prioritize
                     * `forwarding()` calls
                     */
                    if ($this->{"handleException"}($e) === false || $this->finished === false) {
                        continue;
                    }

                    throw $e;
                }
            }
        }

         if($hasEM) {
            try {
                // Calling "dispatch:afterDispatchLoop" event
                // Note: We don't worry about forwarding in after dispatch loop.
                $eventsManager->fire("dispatch:afterDispatchLoop", $this);
            } catch (\Exception $e) {
                // Exception occurred in afterDispatchLoop.
                if ($this->{"handleException"}($e) === false) {
                    return false;
                }

                // Otherwise, bubble Exception
                throw $e;
            }
        }

        return $handler;
    }

    /**
     * Forwards the execution flow to another controller/action.
     *
     * ```php
     * $this->dispatcher->forward(
     *     [
     *         "controller" => "posts",
     *         "action"     => "index",
     *     ]
     * );
     * ```
     *
     * @throws \Phalcon\Exception
     */
    public function forward(array $forward) : void
    {
        //var namespaceName, controllerName, params, actionName, taskName;

        if  ($this->isControllerInitialize === true) {
            /**
             * Note: Important that we do not throw a "throwDispatchException"
             * call here. This is important because it would allow the
             * application to break out of the defined logic inside the
             * dispatcher which handles all dispatch exceptions.
             */
            throw new DisException(
                "Forwarding inside a controller's initialize() method is forbidden"
            );
        }

        /**
         * Save current values as previous to ensure calls to getPrevious
         * methods don't return null.
         */
        $this->previousNamespaceName = $this->namespaceName;
            $this->previousHandlerName = $this->handlerName;
            $this->previousActionName = $this->actionName;

        // Check if we need to forward to another namespace
        $namespaceName = $forward["namespace"] ?? null;
        if ($namespaceName !== null) {
            $this->namespaceName = namespaceName;
        }

        // Check if we need to forward to another controller.
        $controllerName = $forward["controller"] ?? null;
        if ($controllerName !== null) {
            $this->handlerName = $controllerName;
        } else {
            $taskName = $forward["task"] ?? null;
            if ($taskName !== null) {
                $this->handlerName = $taskName;
            }
        }

        // Check if we need to forward to another action
        $actionName = $forward["action"] ?? null;
        if ($actionName !== null) {
            $this->actionName = $actionName;
        }

        // Check if we need to forward changing the current parameters
        $params = $forward["params"] ?? null;
        if ($params !== null) {
            $this->params = $params;
        }

        $this->finished = false;
        $this->forwarded = true;
    }

    /**
     * Gets the latest dispatched action name
     */
    public function getActionName() : string
    {
        return $this->actionName;
    }

    /**
     * Gets the default action suffix
     */
    public function getActionSuffix() : string
    {
        return $this->actionSuffix;
    }

    /**
     * Returns the current method to be/executed in the dispatcher
     */
    public function getActiveMethod() : string
    {
        //var activeMethodName;
        $activeMethodName = $this->activeMethodMap[$this->actionName] ?? null;
        if  ($activeMethodName === null)  {
            $activeMethodName = lcfirst( $this->toCamelCase($this->actionName ) );
            $this->activeMethodMap[$this->actionName] = $activeMethodName;
        }

        return $activeMethodName . $this->actionSuffix;
    }

    /**
     * Returns bound models from binder instance
     *
     * ```php
     * class UserController extends Controller
     * {
     *     public function showAction(User $user)
     *     {
     *         // return array with $user
     *         $boundModels = $this->dispatcher->getBoundModels();
     *     }
     * }
     * ```
     */
    public function getBoundModels() : array
    {
        //var modelBinder;

        $modelBinder = $this->modelBinder;

        if ($modelBinder === null) {
            return [];
        }

        return $modelBinder->getBoundModels();
    }

    /**
     * Returns the default namespace
     */
    public function getDefaultNamespace() : string
    {
        return $this->defaultNamespace;
    }

    /**
     * Returns the internal event manager
     */
    public function getEventsManager() : ManagerInterface
    {
        return $this->eventsManager;
    }

    /**
     * Gets the default handler suffix
     */
    public function getHandlerSuffix() : string
    {
        return $this->handlerSuffix;
    }

    /**
     * Gets model binder
     */
    public function getModelBinder()  : ?BinderInterface
    {
        return $this->modelBinder;
    }

    /**
     * Gets the module where the controller class is
     */
    public function getModuleName() : ?string
    {
        return $this->moduleName;
    }

    /**
     * Gets a namespace to be prepended to the current handler name
     */
    public function getNamespaceName() : ?string
    {
        return $this->namespaceName;
    }

    /**
     * Gets a param by its name or numeric index
     *
     * @param  mixed param
     * @param  string|array filters
     * @param  mixed defaultValue
     * @return mixed
     */
    public function getParam($param, $filters = null, $defaultValue = null)
    {
        //var params, filter, paramValue, container;

        $params = $this->params;
        $paramValue = $params[$param];
        if ($paramValue === null) {
            return $defaultValue;
        }
        if ($filters === null) {
            return $paramValue;
        }

        $container = $this->getContainer( "the 'filter' service");
        $filter = $container->getShared("filter");

        return $filter->sanitize($paramValue, $filters);
    }

    /**
     * Gets action params
     */
    public function getParams() : array
    {
        return $this->params;
    }

    /**
     * Check if a param exists
     */
    public function hasParam( $param) : bool
    {
        return isset ($this->params[$param]);
    }

    /**
     * Checks if the dispatch loop is finished or has more pendent
     * controllers/tasks to dispatch
     */
    public function isFinished() : bool
    {
        return $this->finished;
    }

    /**
     * Sets the action name to be dispatched
     */
    public function setActionName(?string $actionName) : void
    {
        $this->actionName = $actionName;
    }


    /**
     * Sets the default action name
     */
    public function setDefaultAction(?string $actionName) : void
    {
        $this->defaultAction = $actionName;
    }

    /**
     * Sets the default namespace
     */
    public function setDefaultNamespace(?string $namespaceName) : void
    {
        $this->defaultNamespace = $namespaceName;
    }

    /**
     * Possible class name that will be located to dispatch the request
     */
    public function getHandlerClass() : string
    {
        //var handlerSuffix, handlerName, namespaceName, camelizedClass,
        //    handlerClass;

        $this->resolveEmptyProperties();

        $handlerSuffix = $this->handlerSuffix;
        $handlerName = $this->handlerName;
            $namespaceName = $this->namespaceName;

        // We don't camelize the classes if they are in namespaces
        if (strpos($handlerName, "\\")===false) {
            $camelizedClass = $this->toCamelCase($handlerName);
        } else {
            $camelizedClass = $handlerName;
        }

        // Create the complete controller class name prepending the namespace
        if ($namespaceName) {
            if (!str_ends_with($namespaceName, "\\") ) {
                $namespaceName .= "\\";
            }

            $handlerClass = $namespaceName . $camelizedClass . $handlerSuffix;
        } else {
            $handlerClass = $camelizedClass . $handlerSuffix;
        }

        return $handlerClass;
    }

    /**
     * Set a param by its name or numeric index
     */
    public function setParam($param, $value) : void
    {
        $this->params[$param] = $value;
    }

    /**
     * Sets action params to be dispatched
     */
    public function setParams(array $params) : void
    {
        $this->params = $params;
    }

    /**
     * Sets the latest returned value by an action manually
     */
    public function setReturnedValue($value): void
    {
        $this->returnedValue = $value;
    }

    /**
     * Sets the default action suffix
     */
    public function setActionSuffix(?string $actionSuffix):  void
    {
        $this->actionSuffix = $actionSuffix;
    }

    /**
     * Sets the events manager
     */
    public function setEventsManager( ManagerInterface $eventsManager): void
    {
        $this->eventsManager = $eventsManager;
    }

    /**
     * Sets the default suffix for the handler
     */
    public function setHandlerSuffix(?string $handlerSuffix): void
    {
        $this->handlerSuffix = $handlerSuffix;
    }

    /**
     * Enable model binding during dispatch
     *
     * ```php
     * $di->set(
     *     'dispatcher',
     *     function() {
     *         $dispatcher = new Dispatcher();
     *
     *         $dispatcher->setModelBinder(
     *             new Binder(),
     *             'cache'
     *         );
     *
     *         return $dispatcher;
     *     }
     * );
     * ```
     */
    public function setModelBinder( BinderInterface $modelBinder,  $cache = null): DispatcherInterface
    {
        //var container;

        if (is_string($cache)){
            $container = $this->container; // no concern now for real container
            $cache = $container->get($cache);
        }

        if ($cache !== null) {
            $modelBinder->setCache($cache);
        }

        $this->modelBinding = true;
        $this->modelBinder = $modelBinder;

        return this;
    }

    /**
     * Sets the module where the controller is (only informative)
     */
    public function setModuleName(?string $moduleName) : void
    {
        $this->moduleName = $moduleName;
    }

    /**
     * Sets the namespace where the controller class is
     */
    public function setNamespaceName(?string $namespaceName): void
    {
        $this->namespaceName = $namespaceName;
    }

    /**
     * Returns value returned by the latest dispatched action
     * TODO: return mixed
     */
    public function getReturnedValue()
    {
        return $this->returnedValue;
    }

    /**
     * Check if the current executed action was forwarded by another one
     */
    public function wasForwarded() : bool
    {
        return $this->forwarded;
    }

    /**
     * Set empty properties to their defaults (where defaults are available)
     */
    protected function resolveEmptyProperties(): void
    {
        // If the current namespace is null we use the default namespace
        if (!$this->namespaceName) {
            $this->namespaceName = $this->defaultNamespace;
        }

        // If the handler is null we use the default handler
        if (!$this->handlerName) {
            $this->handlerName = $this->defaultHandler;
        }

        // If the action is null we use the default action
        if (!$this->actionName) {
            $this->actionName = $this->defaultAction;
        }
    }

    /** 
     * Check and maintain a cache. Wonder how many hits.
     * @global type $gCamelize
     * @param string $input
     * @return string
     */
    protected function toCamelCase(string $input) :  string
    {
        $camelCaseInput = $this->camelCaseMap[$input] ?? null;
        if ($camelCaseInput === null) {
            global $gCamelize;
            
            $camelCaseInput =  $gCamelize($input);
            $this->camelCaseMap[$input] = $camelCaseInput;
        }
        return $camelCaseInput;
    }
}
