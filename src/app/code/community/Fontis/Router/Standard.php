<?php
/**
 * Fontis Router Extension
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/osl-3.0.php
 *
 * @category   Fontis
 * @package    Fontis_Router
 * @author     Matthew Gamble
 * @copyright  Copyright (c) 2016 Fontis Pty. Ltd. (https://www.fontis.com.au)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

class Fontis_Router_Standard extends Mage_Core_Controller_Varien_Router_Abstract
{
    /**
     * @var array
     */
    protected $_modules = array();

    /**
     * @param string $configArea
     * @param string $useRouterName
     */
    public function collectRoutes($configArea, $useRouterName)
    {
        $routers = array();
        $routersConfigNode = Mage::getConfig()->getNode($configArea . "/routers");
        if ($routersConfigNode) {
            $routers = $routersConfigNode->children();
        }
        foreach ($routers as $routerName => $routerConfig) {
            $use = (string) $routerConfig->use;
            if ($use == $useRouterName) {
                $modules = array((string) $routerConfig->args->module);
                if ($routerConfig->args->extra) {
                    foreach ($routerConfig->args->extra->children() as $customModule) {
                        /** @var $customModule Varien_Simplexml_Element */
                        if ((string) $customModule) {
                            if ($before = $customModule->getAttribute("before")) {
                                $position = array_search($before, $modules);
                                if ($position === false) {
                                    $position = 0;
                                }
                                array_splice($modules, $position, 0, (string) $customModule);
                            } elseif ($after = $customModule->getAttribute("after")) {
                                $position = array_search($after, $modules);
                                if ($position === false) {
                                    $position = count($modules);
                                }
                                array_splice($modules, $position + 1, 0, (string) $customModule);
                            } else {
                                $modules[] = (string) $customModule;
                            }
                        }
                    }
                }

                $this->addModule($routerName, $modules);
            }
        }
    }

    /**
     * @param string $frontName
     * @param array $modules
     * @return Fontis_Router_Standard
     */
    public function addModule($frontName, $modules)
    {
        $this->_modules[$frontName] = $modules;
        return $this;
    }

    /**
     * Match the request.
     *
     * Ideally, we could use Mage_Core_Controller_Request_Http in the type declaration and PHPDoc.
     * Unfortunately, Magento's Abstract router class that this extends from uses the Zend parent
     * class in the function signature, meaning we have to use it here too. The result of this type
     * mismatch is that the IDE thinks calls to $request->setRouteName() and $request->setControllerModule()
     * are invalid.
     *
     * @param Zend_Controller_Request_Http $request
     * @return bool
     */
    public function match(Zend_Controller_Request_Http $request)
    {
        /** @var $request Mage_Core_Controller_Request_Http */

        // Check before matching to see whether or not
        // the current module should use this router.
        if (!$this->_beforeModuleMatch()) {
            return false;
        }

        // Get the specified module name.
        $module = $request->getModuleName();
        if (!$module) {
            return false;
        }

        // Search for modules that match the specified module name.
        $modules = $this->getModuleByFrontName($module);
        if ($modules === null) {
            return false;
        }

        // Get the specified controller name.
        $controller = $request->getControllerName();
        if (!$controller) {
            return false;
        }

        // Get the specified action name.
        $action = $request->getActionName();
        if (!$action) {
            return false;
        }

        // Check after matching to see whether or not
        // the current module should use this router.
        if (!$this->_afterModuleMatch()) {
            return false;
        }

        // Search through modules specified for this route to find the appropriate controller.
        $foundModule = null;
        $controllerInstance = null;
        foreach ($modules as $realModule) {
            // Check if this route should be secure.
            $this->_checkShouldBeSecure($request, "/" . $module . "/" . $controller . "/" . $action);

            // Get the class name of the specified controller.
            $controllerClassName = $this->validateControllerClassName($realModule, $controller);
            if (!$controllerClassName) {
                continue;
            }

            // Instantiate controller class.
            $controllerInstance = Mage::getControllerInstance($controllerClassName, $request, $this->getFront()->getResponse());

            if (!$this->_validateControllerInstance($controllerInstance)) {
                continue;
            }

            // Make sure the controller has the specified action.
            if (!$controllerInstance->hasAction($action)) {
                continue;
            }

            $foundModule = $realModule;
            break;
        }

        // If we did not found any suitable modules, move on.
        if ($foundModule === null || $controllerInstance == null) {
            return false;
        }

        // Set the route being used for this request.
        $request->setRouteName(str_replace("/", "_", $request->getUserParam("fontis_frontname")));
        // Set the module that contains this controller on the request object.
        $request->setModuleName($module);
        $request->setControllerModule($foundModule);

        // Dispatch to the controller.
        $request->setDispatched(true);
        $controllerInstance->dispatch($action);

        return true;
    }

    /**
     * This isn't an admin router.
     * Make sure we're not trying to process admin routes.
     *
     * @return bool
     */
    protected function _beforeModuleMatch()
    {
        if (Mage::app()->getStore()->isAdmin()) {
            return false;
        }
        return true;
    }

    /**
     * Dummy call. Used for subclasses.
     *
     * @return bool
     */
    protected function _afterModuleMatch()
    {
        return true;
    }

    /**
     * @param string $frontName
     * @return array|bool
     */
    public function getModuleByFrontName($frontName)
    {
        if (isset($this->_modules[$frontName])) {
            return $this->_modules[$frontName];
        }
        return null;
    }

    /**
     * getFrontNameByRoute() and getRouteByFrontName() do the same thing.
     * The reason we need both is because the Fontis Standard router does
     * not differentiate between the two, as this reduces the complexity
     * without reducing the featureset.
     *
     * The function's interface should match that of the Varien Standard
     * router. The only reason this function exists is because other
     * parts of Magento expect it to exist.
     *
     * @param string $routeName
     * @return string|bool
     */
    public function getFrontNameByRoute($routeName)
    {
        if (array_key_exists($routeName, $this->_modules)) {
            return $routeName;
        } else {
            return false;
        }
    }

    /**
     * getRouteByFrontName() and getFrontNameByRoute() do the same thing.
     * The reason we need both is because the Fontis Standard router does
     * not differentiate between the two, as this reduces the complexity
     * without reducing the featureset.
     *
     * The function's interface should match that of the Varien Standard
     * router. The only reason this function exists is because other
     * parts of Magento expect it to exist.
     *
     * @param string $frontName
     * @return string|bool
     */
    public function getRouteByFrontName($frontName)
    {
        if (array_key_exists($frontName, $this->_modules)) {
            return $frontName;
        } else {
            return false;
        }
    }

    /**
     * Generate and validate class file name.
     * If the class exists, include it.
     *
     * @param string $realModule
     * @param string $controller
     * @return mixed
     */
    protected function validateControllerClassName($realModule, $controller)
    {
        $controllerFileName = $this->getControllerFileName($realModule, $controller);
        if (!$this->validateControllerFileName($controllerFileName)) {
            return false;
        }

        $controllerClassName = $this->getControllerClassName($realModule, $controller);
        if (!$controllerClassName) {
            return false;
        }

        // include controller file if needed
        if (!$this->includeControllerClass($controllerFileName, $controllerClassName)) {
            return false;
        }

        return $controllerClassName;
    }

    /**
     * @param string $realModule
     * @param string $controller
     * @return string|null
     */
    public function getControllerFileName($realModule, $controller)
    {
        $parts = explode("_", $realModule);
        $file = implode(DS, array_splice($parts, 0, 2)) . DS . "controllers";
        if (count($parts)) {
            $file .= DS . implode(DS, $parts);
        }
        $file .= DS . uc_words($controller, DS) . "Controller.php";

        $paths = explode(PATH_SEPARATOR, get_include_path());
        foreach ($paths as $path) {
            $fullPath = $path . DS . $file;
            if (file_exists($fullPath)) {
                return $fullPath;
            }
        }

        return null;
    }

    /**
     * @param string $fileName
     * @return bool
     */
    public function validateControllerFileName($fileName)
    {
        if ($fileName && is_readable($fileName) && strpos($fileName, "//") === false) {
            return true;
        }
        return false;
    }

    /**
     * @param string $realModule
     * @param string $controller
     * @return string
     */
    public function getControllerClassName($realModule, $controller)
    {
        $class = $realModule . "_" . uc_words($controller) . "Controller";
        return $class;
    }

    /**
     * Include the file containing the specified controller class if
     * this class is not defined yet.
     *
     * @param string $controllerFileName
     * @param string $controllerClassName
     * @return bool
     * @throws Mage_Core_Exception
     */
    protected function includeControllerClass($controllerFileName, $controllerClassName)
    {
        if (!class_exists($controllerClassName, false)) {
            if (!file_exists($controllerFileName)) {
                return false;
            }
            include $controllerFileName;

            if (!class_exists($controllerClassName, false)) {
                throw Mage::exception("Mage_Core", Mage::helper("core")->__("Controller file was loaded but class does not exist"));
            }
        }
        return true;
    }

    /**
     * Check if current controller instance is allowed in current router.
     *
     * @param Mage_Core_Controller_Varien_Action $controllerInstance
     * @return bool
     */
    protected function _validateControllerInstance($controllerInstance)
    {
        return $controllerInstance instanceof Mage_Core_Controller_Front_Action;
    }

    /**
     * Check that request uses HTTPS protocol if it should.
     * Function redirects user to correct URL if needed.
     *
     * @param Mage_Core_Controller_Request_Http $request
     * @param string $path
     * @return void
     */
    protected function _checkShouldBeSecure(Mage_Core_Controller_Request_Http $request, $path = "")
    {
        if (!Mage::isInstalled() || $request->isPost()) {
            return;
        }

        if ($this->_shouldBeSecure($path) && !$request->isSecure()) {
            $url = $this->_getCurrentSecureUrl($request);
            if ($request->getRouteName() != "adminhtml" && Mage::app()->getUseSessionInUrl()) {
                $url = Mage::getSingleton("core/url")->getRedirectUrl($url);
            }

            Mage::app()->getFrontController()->getResponse()
                ->setRedirect($url)
                ->sendResponse();
            exit;
        }
    }

    /**
     * @param Mage_Core_Controller_Request_Http $request
     * @return string
     */
    protected function _getCurrentSecureUrl(Mage_Core_Controller_Request_Http $request)
    {
        if ($alias = $request->getAlias(Mage_Core_Model_Url_Rewrite::REWRITE_REQUEST_PATH_ALIAS)) {
            return Mage::getBaseUrl("link", true) . ltrim($alias, "/");
        }

        return Mage::getBaseUrl("link", true) . ltrim($request->getPathInfo(), "/");
    }

    /**
     * Check whether URL for corresponding path should use HTTPS protocol.
     *
     * @param string $path
     * @return bool
     */
    protected function _shouldBeSecure($path)
    {
        return substr(Mage::getStoreConfig("web/unsecure/base_url"), 0, 5) === "https"
            || Mage::getStoreConfigFlag("web/secure/use_in_frontend")
            && substr(Mage::getStoreConfig("web/secure/base_url"), 0, 5) == "https"
            && Mage::getConfig()->shouldUrlBeSecure($path);
    }
}
