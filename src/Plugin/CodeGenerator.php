<?php

/**
 * Generator.php - Jaxon code generator
 *
 * Generate HTML, CSS and Javascript code for Jaxon.
 *
 * @package jaxon-core
 * @author Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @copyright 2016 Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/jaxon-php/jaxon-core
 */

namespace Jaxon\Plugin;

use Jaxon\Utils\Template\Engine as TemplateEngine;
use Jaxon\Utils\Http\URI;

class CodeGenerator
{
    use \Jaxon\Features\Config;
    use \Jaxon\Features\Minifier;

    /**
     * The response type.
     *
     * @var string
     */
    const RESPONSE_TYPE = 'JSON';

    /**
     * The plugin manager
     *
     * @var Manager
     */
    protected $xPluginManager;

    /**
     * The Jaxon template engine
     *
     * @var TemplateEngine
     */
    protected $xTemplate;

    /**
     * Generated CSS code
     *
     * @var string
     */
    protected $sCssCode = '';

    /**
     * Generated Javascript code
     *
     * @var string
     */
    protected $sJsCode = '';

    /**
     * Generated Javascript ready script
     *
     * @var string
     */
    protected $sJsScript = '';

    /**
     * Code already generated
     *
     * @var boolean
     */
    protected $sCodeGenerated = false;

    /**
     * Default library URL
     *
     * @var string
     */
    protected $sJsLibraryUrl = 'https://cdn.jsdelivr.net/gh/jaxon-php/jaxon-js@3.0/dist';

    /**
     * The constructor
     *
     * @param Manager    $xPluginManager
     */
    public function __construct(Manager $xPluginManager, TemplateEngine $xTemplate)
    {
        $this->xPluginManager = $xPluginManager;
        $this->xTemplate = $xTemplate;
    }

    /**
     * Get the base URI of the Jaxon library javascript files
     *
     * @return string
     */
    private function getJsLibUri()
    {
        return rtrim($this->getOption('js.lib.uri', $this->sJsLibraryUrl), '/') . '/';
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
        // - The js.app.export option must be set to true
        // - The js.app.uri and js.app.dir options must be set to non null values
        if(!$this->getOption('js.app.export') ||
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
     * Generate a hash for all the javascript code generated by the library
     *
     * @return string
     */
    private function generateHash()
    {
        $sHash = jaxon()->getVersion();
        foreach($this->xPluginManager->getRequestPlugins() as $xPlugin)
        {
            $sHash .= $xPlugin->generateHash();
        }
        foreach($this->xPluginManager->getResponsePlugins() as $xPlugin)
        {
            $sHash .= $xPlugin->generateHash();
        }
        return md5($sHash);
    }

    /**
     * Get the HTML tags to include Jaxon javascript files into the page
     *
     * @return string
     */
    private function makePluginsCode()
    {
        if(!$this->sCodeGenerated)
        {
            $this->sCodeGenerated = true;

            foreach($this->xPluginManager->getPlugins() as $xPlugin)
            {
                if($xPlugin instanceof Response)
                {
                    if(($sCssCode = trim($xPlugin->getCss())))
                    {
                        $this->sCssCode .= rtrim($sCssCode, " \n") . "\n";
                    }
                    if(($sJsCode = trim($xPlugin->getJs())))
                    {
                        $this->sJsCode .= rtrim($sJsCode, " \n") . "\n";
                    }
                }
                if(($sJsScript = trim($xPlugin->getScript())))
                {
                    $this->sJsScript .= trim($sJsScript, " \n") . "\n";
                }
            }

            // foreach($this->xPluginManager->getPackages() as $sPackageClass)
            // {
            //     $xPackage = jaxon()->di()->get($sPackageClass);
            //     if(($sCssCode = trim($xPackage->css())))
            //     {
            //         $this->sCssCode .= rtrim($sCssCode, " \n") . "\n";
            //     }
            //     if(($sJsCode = trim($xPackage->js())))
            //     {
            //         $this->sJsCode .= rtrim($sJsCode, " \n") . "\n";
            //     }
            //     if(($sJsScript = trim($xPackage->ready())))
            //     {
            //         $this->sJsScript .= trim($sJsScript, " \n") . "\n";
            //     }
            // }
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
        $aJsFiles = [$sJsCoreUrl];
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
        $this->makePluginsCode();

        return $this->xTemplate->render('jaxon::plugins/includes.js', [
            'sJsOptions' => $this->getOption('js.app.options', ''),
            'aUrls' => $aJsFiles,
        ]) . $this->sJsCode;
    }

    /**
     * Get the HTML tags to include Jaxon CSS code and files into the page
     *
     * @return string
     */
    public function getCss()
    {
        // Set the template engine cache dir
        $this->makePluginsCode();

        return $this->sCssCode;
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
        return [
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
            'sDefer'                    => $this->getOption('js.app.options', ''),
        ];
    }

    /**
     * Get the javascript code to be sent to the browser
     *
     * @return string
     */
    private function _getScript()
    {
        $aVars = $this->getOptionVars();
        $sYesScript = 'jaxon.ajax.response.process(command.response)';
        $sNoScript = 'jaxon.confirm.skip(command);jaxon.ajax.response.process(command.response)';
        $sConfirmScript = jaxon()->dialog()->confirm('msg', $sYesScript, $sNoScript);
        $aVars['sConfirmScript'] = $this->xTemplate->render('jaxon::plugins/confirm.js', [
            'sConfirmScript' => $sConfirmScript,
        ]);

        return $this->xTemplate->render('jaxon::plugins/config.js', $aVars) . "\n" . $this->sJsScript . '
jaxon.dom.ready(function() {
    jaxon.command.handler.register("cc", jaxon.confirm.commands);
});
';
    }

    /**
     * Get the javascript code to be sent to the browser
     *
     * Also call each of the request plugins giving them the opportunity
     * to output some javascript to the page being generated.
     * This is called only when the page is being loaded initially.
     * This is not called when processing a request.
     *
     * @param boolean        $bIncludeJs            Also get the JS files
     * @param boolean        $bIncludeCss        Also get the CSS files
     *
     * @return string
     */
    public function getScript($bIncludeJs = false, $bIncludeCss = false)
    {
        if(!$this->getOption('core.request.uri'))
        {
            $this->setOption('core.request.uri', jaxon()->di()->get(URI::class)->detect());
        }

        // Set the template engine cache dir
        $this->makePluginsCode();

        $sScript = '';
        if(($bIncludeCss))
        {
            $sScript .= $this->getCss() . "\n";
        }
        if(($bIncludeJs))
        {
            $sScript .= $this->getJs() . "\n";
        }

        if($this->canExportJavascript())
        {
            $sJsAppURI = rtrim($this->getOption('js.app.uri'), '/') . '/';
            $sJsAppDir = rtrim($this->getOption('js.app.dir'), '/') . '/';
            $sFinalFile = $this->getOption('js.app.file');
            $sExtension = $this->getJsLibExt();

            // Check if the final file already exists
            if(($sFinalFile) && is_file($sJsAppDir . $sFinalFile . $sExtension))
            {
                $sOutFile = $sFinalFile . $sExtension;
            }
            else
            {
                // The plugins scripts are written into the javascript app dir
                $sHash = $this->generateHash();
                $sOutFile = $sHash . '.js';
                $sMinFile = $sHash . '.min.js';
                if(!is_file($sJsAppDir . $sOutFile))
                {
                    file_put_contents($sJsAppDir . $sOutFile, $this->_getScript());
                }
                if(($this->getOption('js.app.minify')))
                {
                    if(is_file($sJsAppDir . $sMinFile))
                    {
                        $sOutFile = $sMinFile; // The file was already minified
                    }
                    elseif(($this->minify($sJsAppDir . $sOutFile, $sJsAppDir . $sMinFile)))
                    {
                        $sOutFile = $sMinFile;
                    }
                }
                // Copy the file to its final location
                if(($sFinalFile))
                {
                    if(copy($sJsAppDir . $sOutFile, $sJsAppDir . $sFinalFile . $sExtension))
                    {
                        $sOutFile = $sFinalFile . $sExtension;
                    }
                }
            }

            // The returned code loads the generated javascript file
            $sScript .= $this->xTemplate->render('jaxon::plugins/include.js', [
                'sJsOptions' => $this->getOption('js.app.options', ''),
                'sUrl' => $sJsAppURI . $sOutFile,
            ]);
        }
        else
        {
            // The plugins scripts are wrapped with javascript tags
            $sScript .= $this->xTemplate->render('jaxon::plugins/wrapper.js', [
                'sJsOptions' => $this->getOption('js.app.options', ''),
                'sScript' => $this->_getScript(),
            ]);
        }

        return $sScript;
    }
}
