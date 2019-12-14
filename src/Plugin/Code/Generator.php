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

namespace Jaxon\Plugin\Code;

use Jaxon\Utils\Template\Engine as TemplateEngine;
use Jaxon\Utils\Http\URI;

use Jaxon\Plugin\Code\Contracts\Generator as GeneratorContract;

class Generator
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
     * The objects that generate code
     *
     * @var array<GeneratorContract>
     */
    protected $aGenerators = [];

    /**
     * The Jaxon template engine
     *
     * @var TemplateEngine
     */
    protected $xTemplateEngine;

    /**
     * HTML tags to include CSS code and files into the page
     *
     * @var string
     */
    protected $sCssCode = '';

    /**
     * HTML tags to include javascript code and files into the page
     *
     * @var string
     */
    protected $sJsCode = '';

    /**
     * Javascript code to include into the page
     *
     * @var string
     */
    protected $sJsScript = '';

    /**
     * Javascript code to execute after page load
     *
     * @var string
     */
    protected $sJsReadyScript = '';

    /**
     * Javascript code to include in HTML code and execute after page load
     *
     * @var string
     */
    protected $sJsInlineScript = '';

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
     * @param TemplateEngine        $xTemplateEngine      The template engine
     */
    public function __construct(TemplateEngine $xTemplateEngine)
    {
        $this->xTemplateEngine = $xTemplateEngine;
    }

    /**
     * Add a new generator to the list
     *
     * @param GeneratorContract     $xGenerator     The code generator
     * @param integer               $nPriority      The desired priority, used to order the plugins
     *
     * @return void
     */
    public function addGenerator(GeneratorContract $xGenerator, $nPriority)
    {
        while(isset($this->aGenerators[$nPriority]))
        {
            $nPriority++;
        }
        $this->aGenerators[$nPriority] = $xGenerator;
        // Sort the array by ascending keys
        ksort($this->aGenerators);
    }

    /**
     * Generate a hash for all the javascript code generated by the library
     *
     * @return string
     */
    private function getHash()
    {
        $sHash = jaxon()->getVersion();
        foreach($this->aGenerators as $xGenerator)
        {
            $sHash .= $xGenerator->getHash();
        }
        return md5($sHash);
    }

    /**
     * Render a template in the 'plugins' subdir
     *
     * @param string    $sTemplate      The template filename
     * @param array     $aVars          The template variables
     *
     * @return string
     */
    private function render($sTemplate, array $aVars = [])
    {
        $aVars['sJsOptions'] = $this->getOption('js.app.options', '');
        return $this->xTemplateEngine->render("jaxon::plugins/$sTemplate", $aVars);
    }

    /**
     * Get the HTML tags to include Jaxon javascript files into the page
     *
     * @return void
     */
    private function generateCode()
    {
        if($this->sCodeGenerated)
        {
            return;
        }
        $this->sCodeGenerated = true;

        foreach($this->aGenerators as $xGenerator)
        {
            if(($sCssCode = $xGenerator->getCss()))
            {
                $this->sCssCode .= rtrim($sCssCode, " \n") . "\n";
            }
            if(($sJsCode = $xGenerator->getJs()))
            {
                $this->sJsCode .= rtrim($sJsCode, " \n") . "\n";
            }
            if(($sJsScript = $xGenerator->getScript()))
            {
                $this->sJsScript .= rtrim($sJsScript, " \n") . "\n";
            }
            if(!$xGenerator->readyInlined() &&
                ($sJsReadyScript = $xGenerator->getReadyScript()))
            {
                $this->sJsReadyScript .= rtrim($sJsReadyScript, " \n") . "\n";
            }
            if($xGenerator->readyInlined() &&
                $xGenerator->readyEnabled() &&
                ($sJsInlineScript = $xGenerator->getReadyScript()))
            {
                $this->sJsInlineScript .= rtrim($sJsInlineScript, " \n") . "\n";
            }
        }

        if(($this->sJsInlineScript))
        {
            $this->sJsInlineScript = $this->render('ready.js', ['script' => $this->sJsInlineScript]);
        }
        if(($this->sJsReadyScript))
        {
            // These two parts are always rendered together
            $this->sJsScript .= "\n" . $this->render('ready.js', ['script' => $this->sJsReadyScript]);
        }
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
        $this->generateCode();

        return $this->render('includes.js', ['aUrls' => $aJsFiles]) . $this->sJsCode;
    }

    /**
     * Get the HTML tags to include Jaxon CSS code and files into the page
     *
     * @return string
     */
    public function getCss()
    {
        // Set the template engine cache dir
        $this->generateCode();

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
        $aConfigVars = $this->getOptionVars();
        $sYesScript = 'jaxon.ajax.response.process(command.response)';
        $sNoScript = 'jaxon.confirm.skip(command);jaxon.ajax.response.process(command.response)';
        $sQuestionScript = jaxon()->dialog()->confirm('msg', $sYesScript, $sNoScript);

        $aConfigVars['sQuestionScript'] = $this->render('confirm.js', [
            'sQuestionScript' => $sQuestionScript,
        ]);

        return $this->render('config.js', $aConfigVars) . "\n" . $this->sJsScript;
    }

    /**
     * Write javascript files and return the corresponding URI
     *
     * @return string
     */
    private function _writeFiles()
    {
        $sJsAppURI = rtrim($this->getOption('js.app.uri'), '/') . '/';
        $sJsAppDir = rtrim($this->getOption('js.app.dir'), '/') . '/';
        $sFinalFile = $this->getOption('js.app.file');
        $sExtension = $this->getJsLibExt();

        // Check if the final file already exists
        if(($sFinalFile) && is_file($sJsAppDir . $sFinalFile . $sExtension))
        {
            return $sJsAppURI . $sFinalFile . $sExtension;
        }

        // The plugins scripts are written into the javascript app dir
        $sHash = $this->getHash();
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

        return $sJsAppURI . $sOutFile;
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
    public function getScript($bIncludeJs, $bIncludeCss)
    {
        if(!$this->getOption('core.request.uri'))
        {
            $this->setOption('core.request.uri', jaxon()->di()->get(URI::class)->detect());
        }

        // Set the template engine cache dir
        $this->generateCode();

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
            $sJsInlineScript = '';
            if(($this->sJsInlineScript))
            {
                $sJsInlineScript = $this->render('wrapper.js', ['sScript' => $this->sJsInlineScript]);
            }
            // The returned code loads the generated javascript file
            return $sScript . $this->render('include.js', ['sUrl' => $this->_writeFiles()]) . $sJsInlineScript;
        }

        // The plugins scripts are wrapped with javascript tags
        return $sScript . $this->render('wrapper.js', [
            'sScript' => $this->_getScript() . "\n" . $this->sJsInlineScript,
        ]);
    }
}
