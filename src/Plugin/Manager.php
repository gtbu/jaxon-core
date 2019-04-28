<?php

/**
 * Manager.php - Jaxon plugin manager
 *
 * Register Jaxon plugins, generate corresponding code, handle request
 * and redirect them to the right plugin.
 *
 * @package jaxon-core
 * @author Jared White
 * @author J. Max Wilson
 * @author Joseph Woolley
 * @author Steffen Konerow
 * @author Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @copyright Copyright (c) 2005-2007 by Jared White & J. Max Wilson
 * @copyright Copyright (c) 2008-2010 by Joseph Woolley, Steffen Konerow, Jared White  & J. Max Wilson
 * @copyright 2016 Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/jaxon-php/jaxon-core
 */

namespace Jaxon\Plugin;

use Jaxon\Jaxon;
use Jaxon\Plugin\Package;
use Jaxon\Config\Config;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use RecursiveRegexIterator;
use Closure;

class Manager
{
    use \Jaxon\Utils\Traits\Manager;
    use \Jaxon\Utils\Traits\Config;
    use \Jaxon\Utils\Traits\Cache;
    use \Jaxon\Utils\Traits\Event;
    use \Jaxon\Utils\Traits\Minifier;
    use \Jaxon\Utils\Traits\Template;
    use \Jaxon\Utils\Traits\Translator;

    /**
     * The response type.
     *
     * @var string
     */
    const RESPONSE_TYPE = 'JSON';

    /**
     * All plugins, indexed by priority
     *
     * @var array
     */
    private $aPlugins = [];

    /**
     * Request plugins, indexed by name
     *
     * @var array
     */
    private $aRequestPlugins = [];

    /**
     * Response plugins, indexed by name
     *
     * @var array
     */
    private $aResponsePlugins = [];

    /**
     * An array of package names
     *
     * @var array
     */
    private $aPackages = [];

    /**
     * Javascript confirm function
     *
     * @var Dialogs\Interfaces\Confirm
     */
    private $xConfirm;

    /**
     * Default javascript confirm function
     *
     * @var Dialogs\Confirm
     */
    private $xDefaultConfirm;

    /**
     * Javascript alert function
     *
     * @var Dialogs\Interfaces\Alert
     */
    private $xAlert;

    /**
     * Default javascript alert function
     *
     * @var Dialogs\Alert
     */
    private $xDefaultAlert;

    /**
     * Initialize the Jaxon Plugin Manager
     */
    public function __construct()
    {
        // Javascript confirm function
        $this->xConfirm = null;
        $this->xDefaultConfirm = new Dialogs\Confirm();

        // Javascript alert function
        $this->xAlert = null;
        $this->xDefaultAlert = new Dialogs\Alert();
    }

    /**
     * Set the javascript confirm function
     *
     * @param Dialogs\Interfaces\Confirm         $xConfirm     The javascript confirm function
     *
     * @return void
     */
    public function setConfirm(Dialogs\Interfaces\Confirm $xConfirm)
    {
        $this->xConfirm = $xConfirm;
    }

    /**
     * Get the javascript confirm function
     *
     * @return Dialogs\Interfaces\Confirm
     */
    public function getConfirm()
    {
        return (($this->xConfirm) ? $this->xConfirm : $this->xDefaultConfirm);
    }

    /**
     * Get the default javascript confirm function
     *
     * @return Dialogs\Confirm
     */
    public function getDefaultConfirm()
    {
        return $this->xDefaultConfirm;
    }

    /**
     * Set the javascript alert function
     *
     * @param Dialogs\Interfaces\Alert           $xAlert       The javascript alert function
     *
     * @return void
     */
    public function setAlert(Dialogs\Interfaces\Alert $xAlert)
    {
        $this->xAlert = $xAlert;
    }

    /**
     * Get the javascript alert function
     *
     * @return Dialogs\Interfaces\Alert
     */
    public function getAlert()
    {
        return (($this->xAlert) ? $this->xAlert : $this->xDefaultAlert);
    }

    /**
     * Get the default javascript alert function
     *
     * @return Dialogs\Alert
     */
    public function getDefaultAlert()
    {
        return $this->xDefaultAlert;
    }

    /**
     * Inserts an entry into an array given the specified priority number
     *
     * If a plugin already exists with the given priority, the priority is automatically incremented until a free spot is found.
     * The plugin is then inserted into the empty spot in the array.
     *
     * @param Plugin         $xPlugin               An instance of a plugin
     * @param integer        $nPriority             The desired priority, used to order the plugins
     *
     * @return void
     */
    private function setPluginPriority(Plugin $xPlugin, $nPriority)
    {
        while (isset($this->aPlugins[$nPriority]))
        {
            $nPriority++;
        }
        $this->aPlugins[$nPriority] = $xPlugin;
        // Sort the array by ascending keys
        ksort($this->aPlugins);
    }

    /**
     * Register a plugin
     *
     * Below is a table for priorities and their description:
     * - 0 thru 999: Plugins that are part of or extensions to the jaxon core
     * - 1000 thru 8999: User created plugins, typically, these plugins don't care about order
     * - 9000 thru 9999: Plugins that generally need to be last or near the end of the plugin list
     *
     * @param Plugin         $xPlugin               An instance of a plugin
     * @param integer        $nPriority             The plugin priority, used to order the plugins
     *
     * @return void
     */
    public function registerPlugin(Plugin $xPlugin, $nPriority = 1000)
    {
        $bIsAlert = ($xPlugin instanceof Dialogs\Interfaces\Alert);
        $bIsConfirm = ($xPlugin instanceof Dialogs\Interfaces\Confirm);
        if($xPlugin instanceof Request)
        {
            // The name of a request plugin is used as key in the plugin table
            $this->aRequestPlugins[$xPlugin->getName()] = $xPlugin;
        }
        elseif($xPlugin instanceof Response)
        {
            // The name of a response plugin is used as key in the plugin table
            $this->aResponsePlugins[$xPlugin->getName()] = $xPlugin;
        }
        elseif(!$bIsConfirm && !$bIsAlert)
        {
            throw new \Jaxon\Exception\Error($this->trans('errors.register.invalid', array('name' => get_class($xPlugin))));
        }
        // This plugin implements the Alert interface
        if($bIsAlert)
        {
            $this->setAlert($xPlugin);
        }
        // This plugin implements the Confirm interface
        if($bIsConfirm)
        {
            $this->setConfirm($xPlugin);
        }
        // Register the plugin as an event listener
        if($xPlugin instanceof \Jaxon\Utils\Interfaces\EventListener)
        {
            $this->addEventListener($xPlugin);
        }

        $this->setPluginPriority($xPlugin, $nPriority);
    }

    /**
     * Register a package
     *
     * @param string         $sPackageClass         The package class name
     * @param Closure        $xClosure              A closure to create package instance
     *
     * @return void
     */
    public function registerPackage(string $sPackageClass, Closure $xClosure)
    {
        $this->aPackages[] = $sPackageClass;
        jaxon()->di()->set($sPackageClass, $xClosure);
    }

    /**
     * Generate a hash for all the javascript code generated by the library
     *
     * @return string
     */
    private function generateHash()
    {
        $sHash = jaxon()->getVersion();
        foreach($this->aPlugins as $xPlugin)
        {
            $sHash .= $xPlugin->generateHash();
        }
        return md5($sHash);
    }

    /**
     * Check if the current request can be processed
     *
     * Calls each of the request plugins and determines if the current request can be processed by one of them.
     * If no processor identifies the current request, then the request must be for the initial page load.
     *
     * @return boolean
     */
    public function canProcessRequest()
    {
        foreach($this->aRequestPlugins as $xPlugin)
        {
            if($xPlugin->getName() != Jaxon::FILE_UPLOAD && $xPlugin->canProcessRequest())
            {
                return true;
            }
        }
        return false;
    }

    /**
     * Process the current request
     *
     * Calls each of the request plugins to request that they process the current request.
     * If any plugin processes the request, it will return true.
     *
     * @return boolean
     */
    public function processRequest()
    {
        $xUploadPlugin = $this->getRequestPlugin(Jaxon::FILE_UPLOAD);
        foreach($this->aRequestPlugins as $xPlugin)
        {
            if($xPlugin->getName() != Jaxon::FILE_UPLOAD && $xPlugin->canProcessRequest())
            {
                // Process uploaded files
                if($xUploadPlugin != null)
                {
                    $xUploadPlugin->processRequest();
                }
                // Process the request
                return $xPlugin->processRequest();
            }
        }
        // Todo: throw an exception
        return false;
    }

    /**
     * Register a function, event or callable object
     *
     * Call the request plugin with the $sType defined as name.
     *
     * @param string        $sType          The type of request handler being registered
     * @param string        $sCallable      The callable entity being registered
     * @param array|string  $aOptions       The associated options
     *
     * @return mixed
     */
    public function register($sType, $sCallable, $aOptions = [])
    {
        if(!key_exists($sType, $this->aRequestPlugins))
        {
            throw new \Jaxon\Exception\Error($this->trans('errors.register.plugin', ['name' => $sType]));
        }

        $xPlugin = $this->aRequestPlugins[$sType];
        return $xPlugin->register($sType, $sCallable, $aOptions);
        // foreach($this->aRequestPlugins as $xPlugin)
        // {
        //     if($mResult instanceof \Jaxon\Request\Request || is_array($mResult) || $mResult === true)
        //     {
        //         return $mResult;
        //     }
        // }
        // throw new \Jaxon\Exception\Error($this->trans('errors.register.method', ['args' => print_r($aArgs, true)]));
    }

    /**
     * Read and set Jaxon options from a JSON config file
     *
     * @param Config        $xAppConfig        The config options
     *
     * @return void
     */
    public function registerFromConfig($xAppConfig)
    {
        // Register user functions
        $aFunctionsConfig = $xAppConfig->getOption('functions', []);
        foreach($aFunctionsConfig as $xKey => $xValue)
        {
            if(is_integer($xKey) && is_string($xValue))
            {
                $sFunction = $xValue;
                // Register a function without options
                $this->register(Jaxon::USER_FUNCTION, $sFunction);
            }
            elseif(is_string($xKey) && is_array($xValue))
            {
                $sFunction = $xKey;
                $aOptions = $xValue;
                // Register a function with options
                $this->register(Jaxon::USER_FUNCTION, $sFunction, $aOptions);
            }
            else
            {
                continue;
                // Todo: throw an exception
            }
        }

        // Register classes and directories
        $aClassesConfig = $xAppConfig->getOption('classes', []);
        foreach($aClassesConfig as $xKey => $xValue)
        {
            if(is_integer($xKey) && is_string($xValue))
            {
                $sClass = $xValue;
                // Register a class without options
                $this->register(Jaxon::CALLABLE_CLASS, $sClass);
            }
            elseif(is_string($xKey) && is_array($xValue))
            {
                $sClass = $xKey;
                $aOptions = $xValue;
                // Register a class with options
                $this->register(Jaxon::CALLABLE_CLASS, $sClass, $aOptions);
            }
            elseif(is_integer($xKey) && is_array($xValue))
            {
                // The directory path is required
                if(!key_exists('directory', $xValue))
                {
                    continue;
                    // Todo: throw an exception
                }
                // Registering a directory
                $sDirectory = $xValue['directory'];
                $aOptions = [];
                if(key_exists('options', $xValue) &&
                    is_array($xValue['options']) || is_string($xValue['options']))
                {
                    $aOptions = $xValue['options'];
                }
                // Setup directory options
                if(key_exists('namespace', $xValue))
                {
                    $aOptions['namespace'] = $xValue['namespace'];
                }
                if(key_exists('separator', $xValue))
                {
                    $aOptions['separator'] = $xValue['separator'];
                }
                // Register a class without options
                $this->register(Jaxon::CALLABLE_DIR, $sDirectory, $aOptions);
            }
            else
            {
                continue;
                // Todo: throw an exception
            }
        }
    }

    /**
     * Get the base URI of the Jaxon library javascript files
     *
     * @return string
     */
    private function getJsLibUri()
    {
        if(!$this->hasOption('js.lib.uri'))
        {
            // return 'https://cdn.jsdelivr.net/jaxon/1.2.0/';
            return 'https://cdn.jsdelivr.net/gh/jaxon-php/jaxon-js@2.0/dist/';
        }
        // Todo: check the validity of the URI
        return rtrim($this->getOption('js.lib.uri'), '/') . '/';
    }

    /**
     * Get the extension of the Jaxon library javascript files
     *
     * The returned string is '.min.js' if the files are minified.
     *
     * @return string
     */
    private function getJsLibExt()
    {
        // $jsDelivrUri = 'https://cdn.jsdelivr.net';
        // $nLen = strlen($jsDelivrUri);
        // The jsDelivr CDN only hosts minified files
        // if(($this->getOption('js.app.minify')) || substr($this->getJsLibUri(), 0, $nLen) == $jsDelivrUri)
        // Starting from version 2.0.0 of the js lib, the jsDelivr CDN also hosts non minified files.
        if(($this->getOption('js.app.minify')))
        {
            return '.min.js';
        }
        return '.js';
    }

    /**
     * Check if the javascript code generated by Jaxon can be exported to an external file
     *
     * @return boolean
     */
    public function canExportJavascript()
    {
        // Check config options
        // - The js.app.extern option must be set to true
        // - The js.app.uri and js.app.dir options must be set to non null values
        if(!$this->getOption('js.app.extern') ||
            !$this->getOption('js.app.uri') ||
            !$this->getOption('js.app.dir'))
        {
            return false;
        }
        // Check dir access
        // - The js.app.dir must be writable
        $sJsAppDir = $this->getOption('js.app.dir');
        if(!is_dir($sJsAppDir) || !is_writable($sJsAppDir))
        {
            return false;
        }
        return true;
    }

    /**
     * Set the cache directory for the template engine
     *
     * @return void
     */
    private function setTemplateCacheDir()
    {
        if($this->hasOption('core.template.cache_dir'))
        {
            $this->setCacheDir($this->getOption('core.template.cache_dir'));
        }
    }

    /**
     * Get the HTML tags to include Jaxon javascript files into the page
     *
     * @return string
     */
    public function getJs()
    {
        $sJsLibUri = $this->getJsLibUri();
        $sJsLibExt = $this->getJsLibExt();
        $sJsCoreUrl = $sJsLibUri . 'jaxon.core' . $sJsLibExt;
        $sJsDebugUrl = $sJsLibUri . 'jaxon.debug' . $sJsLibExt;
        // $sJsVerboseUrl = $sJsLibUri . 'jaxon.verbose' . $sJsLibExt;
        $sJsLanguageUrl = $sJsLibUri . 'lang/jaxon.' . $this->getOption('core.language') . $sJsLibExt;

        // Add component files to the javascript file array;
        $aJsFiles = array($sJsCoreUrl);
        if($this->getOption('core.debug.on'))
        {
            $aJsFiles[] = $sJsDebugUrl;
            $aJsFiles[] = $sJsLanguageUrl;
            /*if($this->getOption('core.debug.verbose'))
            {
                $aJsFiles[] = $sJsVerboseUrl;
            }*/
        }

        // Set the template engine cache dir
        $this->setTemplateCacheDir();
        $sCode = $this->render('jaxon::plugins/includes.js', array(
            'sJsOptions' => $this->getOption('js.app.options'),
            'aUrls' => $aJsFiles,
        ));
        foreach($this->aResponsePlugins as $xPlugin)
        {
            if(($str = trim($xPlugin->getJs())))
            {
                $sCode .= rtrim($str, " \n") . "\n";
            }
        }
        foreach($this->aPackages as $sClass)
        {
            $xPackage = jaxon()->di()->get($sClass);
            if(($str = trim($xPackage->js())))
            {
                $sCode .= rtrim($str, " \n") . "\n";
            }
        }
        return $sCode;
    }

    /**
     * Get the HTML tags to include Jaxon CSS code and files into the page
     *
     * @return string
     */
    public function getCss()
    {
        // Set the template engine cache dir
        $this->setTemplateCacheDir();

        $sCode = '';
        foreach($this->aResponsePlugins as $xPlugin)
        {
            if(($str = trim($xPlugin->getCss())))
            {
                $sCode .= rtrim($str, " \n") . "\n";
            }
        }
        foreach($this->aPackages as $sClass)
        {
            $xPackage = jaxon()->di()->get($sClass);
            if(($str = trim($xPackage->css())))
            {
                $sCode .= rtrim($str, " \n") . "\n";
            }
        }
        return $sCode;
    }

    /**
     * Get the correspondances between previous and current config options
     *
     * They are used to keep the deprecated config options working.
     * They will be removed when the deprecated options will lot be supported anymore.
     *
     * @return array
     */
    private function getOptionVars()
    {
        return array(
            'sResponseType'             => self::RESPONSE_TYPE,
            'sVersion'                  => $this->getOption('core.version'),
            'sLanguage'                 => $this->getOption('core.language'),
            'bLanguage'                 => $this->hasOption('core.language') ? true : false,
            'sRequestURI'               => $this->getOption('core.request.uri'),
            'sDefaultMode'              => $this->getOption('core.request.mode'),
            'sDefaultMethod'            => $this->getOption('core.request.method'),
            'sCsrfMetaName'             => $this->getOption('core.request.csrf_meta'),
            'bDebug'                    => $this->getOption('core.debug.on'),
            'bVerboseDebug'             => $this->getOption('core.debug.verbose'),
            'sDebugOutputID'            => $this->getOption('core.debug.output_id'),
            'nResponseQueueSize'        => $this->getOption('js.lib.queue_size'),
            'sStatusMessages'           => $this->getOption('js.lib.show_status') ? 'true' : 'false',
            'sWaitCursor'               => $this->getOption('js.lib.show_cursor') ? 'true' : 'false',
            'sDefer'                    => $this->getOption('js.app.options'),
        );
    }

    /**
     * Get the javascript code for Jaxon client side configuration
     *
     * @return string
     */
    private function getConfigScript()
    {
        $aVars = $this->getOptionVars();
        $sYesScript = 'jaxon.ajax.response.process(command.response)';
        $sNoScript = 'jaxon.confirm.skip(command);jaxon.ajax.response.process(command.response)';
        $sConfirmScript = $this->getConfirm()->confirm('msg', $sYesScript, $sNoScript);
        $aVars['sConfirmScript'] = $this->render('jaxon::plugins/confirm.js', array('sConfirmScript' => $sConfirmScript));

        return $this->render('jaxon::plugins/config.js', $aVars);
    }

    /**
     * Get the javascript code to be run after page load
     *
     * Also call each of the response plugins giving them the opportunity
     * to output some javascript to the page being generated.
     *
     * @return string
     */
    private function getReadyScript()
    {
        $sPluginScript = '';
        foreach($this->aResponsePlugins as $xPlugin)
        {
            if(($str = trim($xPlugin->getScript())))
            {
                $sPluginScript .= "\n" . trim($str, " \n");
            }
        }
        foreach($this->aPackages as $sClass)
        {
            $xPackage = jaxon()->di()->get($sClass);
            if(($str = trim($xPackage->ready())))
            {
                $sPluginScript .= "\n" . trim($str, " \n");
            }
        }

        return $this->render('jaxon::plugins/ready.js', ['sPluginScript' => $sPluginScript]);
    }

    /**
     * Get the javascript code to be sent to the browser
     *
     * Also call each of the request plugins giving them the opportunity
     * to output some javascript to the page being generated.
     * This is called only when the page is being loaded initially.
     * This is not called when processing a request.
     *
     * @return string
     */
    private function getAllScripts()
    {
        // Get the config and plugins scripts
        $sScript = $this->getConfigScript() . "\n" . $this->getReadyScript() . "\n";
        foreach($this->aRequestPlugins as $xPlugin)
        {
            $sScript .= "\n" . trim($xPlugin->getScript(), " \n");
        }
        return $sScript;
    }

    /**
     * Get the javascript code to be sent to the browser
     *
     * Also call each of the request plugins giving them the opportunity
     * to output some javascript to the page being generated.
     * This is called only when the page is being loaded initially.
     * This is not called when processing a request.
     *
     * @return string
     */
    public function getScript()
    {
        // Set the template engine cache dir
        $this->setTemplateCacheDir();

        if($this->canExportJavascript())
        {
            $sJsAppURI = rtrim($this->getOption('js.app.uri'), '/') . '/';
            $sJsAppDir = rtrim($this->getOption('js.app.dir'), '/') . '/';

            // The plugins scripts are written into the javascript app dir
            $sHash = $this->generateHash();
            $sOutFile = $sHash . '.js';
            $sMinFile = $sHash . '.min.js';
            if(!is_file($sJsAppDir . $sOutFile))
            {
                file_put_contents($sJsAppDir . $sOutFile, $this->getAllScripts());
            }
            if(($this->getOption('js.app.minify')) && !is_file($sJsAppDir . $sMinFile))
            {
                if(($this->minify($sJsAppDir . $sOutFile, $sJsAppDir . $sMinFile)))
                {
                    $sOutFile = $sMinFile;
                }
            }

            // The returned code loads the generated javascript file
            $sScript = $this->render('jaxon::plugins/include.js', array(
                'sJsOptions' => $this->getOption('js.app.options'),
                'sUrl' => $sJsAppURI . $sOutFile,
            ));
        }
        else
        {
            // The plugins scripts are wrapped with javascript tags
            $sScript = $this->render('jaxon::plugins/wrapper.js', array(
                'sJsOptions' => $this->getOption('js.app.options'),
                'sScript' => $this->getAllScripts(),
            ));
        }

        return $sScript;
    }

    /**
     * Find the specified response plugin by name and return a reference to it if one exists
     *
     * @param string        $sName                The name of the plugin
     *
     * @return \Jaxon\Plugin\Response
     */
    public function getResponsePlugin($sName)
    {
        if(array_key_exists($sName, $this->aResponsePlugins))
        {
            return $this->aResponsePlugins[$sName];
        }
        return null;
    }

    /**
     * Find the specified request plugin by name and return a reference to it if one exists
     *
     * @param string        $sName                The name of the plugin
     *
     * @return \Jaxon\Plugin\Request
     */
    public function getRequestPlugin($sName)
    {
        if(array_key_exists($sName, $this->aRequestPlugins))
        {
            return $this->aRequestPlugins[$sName];
        }
        return null;
    }
}
