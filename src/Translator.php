<?php
/**
 * Main Translator Class
 * 
 * @package PublishPress\Translations
 */

namespace PublishPress\Translations;

use Exception;

class Translator
{
    /**
     * Plugin root directory
     * 
     * @var string
     */
    private $pluginRoot;
    
    /**
     * Languages directory
     * 
     * @var string
     */
    private $languagesDir;
    
    /**
     * Target languages
     * 
     * @var array
     */
    private $targetLanguages = [
        'de_DE', 'es_ES', 'fr_FR', 'he_IL', 'it_IT',
        'ja', 'ko_KR', 'ru_RU', 'zh_CN', 'zh_TW'
    ];
    
    /**
     * Dry run mode
     * 
     * @var bool
     */
    private $dryRun = false;
    
    /**
     * Force translate mode
     * 
     * @var bool
     */
    private $forceTranslate = false;
    
    /**
     * Potomatic settings
     * 
     * @var array
     */
    private $potomaticSettings = [
        'model' => 'gpt-4o-mini',
        'batch_size' => 20,
        'jobs' => 2,
        'max_cost' => 5.0,
        'verbose_level' => 2,
    ];
    
    /**
     * Constructor
     * 
     * @param string $pluginRoot Plugin root directory
     * @throws Exception
     */
    public function __construct($pluginRoot)
    {
        $this->pluginRoot = rtrim($pluginRoot, '/\\');
        $this->languagesDir = $this->pluginRoot . '/languages';
        
        if (!is_dir($this->languagesDir)) {
            throw new Exception("Languages directory not found: {$this->languagesDir}");
        }
    }
    
    /**
     * Set dry run mode
     * 
     * @param bool $dryRun
     */
    public function setDryRun($dryRun)
    {
        $this->dryRun = (bool) $dryRun;
    }
    
    /**
     * Set force translate mode
     * 
     * @param bool $forceTranslate
     */
    public function setForceTranslate($forceTranslate)
    {
        $this->forceTranslate = (bool) $forceTranslate;
    }
    
    /**
     * Set target languages
     * 
     * @param array $languages
     */
    public function setTargetLanguages(array $languages)
    {
        $this->targetLanguages = $languages;
    }
    
    /**
     * Get plugin name from directory
     * 
     * @return string
     */
    private function getPluginName()
    {
        return basename($this->pluginRoot);
    }
    
    /**
     * Find all POT files
     * 
     * @return array
     */
    private function findPotFiles()
    {
        $potFiles = [];
        
        if (!is_dir($this->languagesDir)) {
            return $potFiles;
        }
        
        $files = scandir($this->languagesDir);
        foreach ($files as $file) {
            if (substr($file, -4) === '.pot') {
                $potFiles[] = $this->languagesDir . '/' . $file;
            }
        }
        
        return $potFiles;
    }
    
    /**
     * Get Potomatic executable path
     * 
     * @return string
     * @throws Exception
     */
    private function getPotomaticPath()
    {
        $isDevWorkspace = $this->isDevWorkspace();
        
        $possiblePaths = [];
        
        if ($isDevWorkspace) {
            $possiblePaths[] = $this->pluginRoot . '/lib/vendor/publishpress/translations/potomatic/potomatic';
            $possiblePaths[] = $this->pluginRoot . '/vendor/publishpress/translations/potomatic/potomatic';
        } else {
            $possiblePaths[] = $this->pluginRoot . '/vendor/publishpress/translations/potomatic/potomatic';
            $possiblePaths[] = $this->pluginRoot . '/lib/vendor/publishpress/translations/potomatic/potomatic';
        }
        
        // Always check library's own potomatic (for development)
        $possiblePaths[] = __DIR__ . '/../potomatic/potomatic';
        
        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }
        
        $message = "Potomatic not found.\n\n";
        $message .= "Environment: " . ($isDevWorkspace ? "dev-workspace (Docker)" : "plugin root") . "\n";
        $message .= "Searched in:\n";
        foreach ($possiblePaths as $path) {
            $message .= "  - $path\n";
        }
        $message .= "\nPlease ensure the library was installed correctly via Composer.\n";
        
        throw new Exception($message);
    }
    
    /**
     * Detect if running in dev-workspace environment
     * 
     * @return bool
     */
    private function isDevWorkspace()
    {
        $indicators = [
            getenv('DOCKER_CONTAINER') !== false,
            getenv('CONTAINER') !== false,
            strpos($this->pluginRoot, '/project') === 0,
            file_exists($this->pluginRoot . '/lib/composer.json'),
            is_dir($this->pluginRoot . '/dev-workspace'),
        ];
        
        return in_array(true, $indicators, true);
    }
    
    /**
     * Get OpenAI API key
     * 
     * @return string|null
     */
    private function getApiKey()
    {
        return getenv('OPENAI_API_KEY') ?: null;
    }
    
    /**
     * Build Potomatic command
     * 
     * @param string $potFile
     * @param string $textDomain
     * @return string
     */
    private function buildCommand($potFile, $textDomain)
    {
        $potomatic = $this->getPotomaticPath();
        
        // On Windows, we need to run with node explicitly
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        if ($isWindows) {
            $cmd = 'node ' . escapeshellarg($potomatic);
        } else {
            $cmd = escapeshellarg($potomatic);
        }
        
        $cmd .= ' --target-languages ' . escapeshellarg(implode(',', $this->targetLanguages));
        $cmd .= ' --pot-file-path ' . escapeshellarg($potFile);
        $cmd .= ' --output-dir ' . escapeshellarg($this->languagesDir);
        $cmd .= ' --po-file-prefix ' . escapeshellarg($textDomain . '-');
        $cmd .= ' --model ' . escapeshellarg($this->potomaticSettings['model']);
        $cmd .= ' --batch-size ' . (int) $this->potomaticSettings['batch_size'];
        $cmd .= ' --jobs ' . (int) $this->potomaticSettings['jobs'];
        $cmd .= ' --max-cost ' . (float) $this->potomaticSettings['max_cost'];
        $cmd .= ' --verbose-level ' . (int) $this->potomaticSettings['verbose_level'];
        
        if ($this->forceTranslate) {
            $cmd .= ' --force-translate';
        }
        
        if ($this->dryRun) {
            $cmd .= ' --dry-run';
        }
        
        $apiKey = $this->getApiKey();
        if ($apiKey) {
            $cmd .= ' --api-key ' . escapeshellarg($apiKey);
        }
        
        return $cmd;
    }
    
    /**
     * Execute translation
     * 
     * @return bool
     */
    public function translate()
    {
        $pluginName = $this->getPluginName();
        
        echo "\n🌍 PublishPress Translation Tool\n";
        echo str_repeat('=', 50) . "\n\n";
        echo "Plugin: {$pluginName}\n";
        echo "Path: {$this->pluginRoot}\n";
        echo "Languages: " . implode(', ', $this->targetLanguages) . "\n";
        echo "Mode: " . ($this->dryRun ? 'DRY RUN (no API calls)' : 'LIVE TRANSLATION') . "\n\n";
        
        if (!$this->dryRun && !$this->getApiKey()) {
            fwrite(STDERR, "Error: OPENAI_API_KEY environment variable not set.\n");
            fwrite(STDERR, "Please set your OpenAI API key:\n");
            fwrite(STDERR, "  export OPENAI_API_KEY=your-api-key-here\n\n");
            return false;
        }
        
        $potFiles = $this->findPotFiles();
        
        if (empty($potFiles)) {
            fwrite(STDERR, "Error: No .pot files found in {$this->languagesDir}\n");
            return false;
        }
        
        echo "POT files found: " . count($potFiles) . "\n\n";
        
        $success = true;
        foreach ($potFiles as $index => $potFile) {
            $potFileName = basename($potFile);
            $textDomain = str_replace('.pot', '', $potFileName);
            
            echo "[" . ($index + 1) . "/" . count($potFiles) . "] Processing: {$potFileName}\n";
            echo "Text domain: {$textDomain}\n";
            
            try {
                $command = $this->buildCommand($potFile, $textDomain);
                
                echo "\nExecuting translation...\n\n";
                
                $output = [];
                $returnCode = 0;
                exec($command . ' 2>&1', $output, $returnCode);
                
                echo implode("\n", $output) . "\n";
                
                if ($returnCode === 0) {
                    echo "\n✅ Successfully processed {$potFileName}\n\n";
                } else {
                    fwrite(STDERR, "\n❌ Error processing {$potFileName}\n\n");
                    $success = false;
                }
                
            } catch (Exception $e) {
                fwrite(STDERR, "\n❌ Error: " . $e->getMessage() . "\n\n");
                $success = false;
            }
        }
        
        echo str_repeat('=', 50) . "\n";
        echo "✨ Translation " . ($success ? 'complete' : 'finished with errors') . " for {$pluginName}!\n\n";
        
        return $success;
    }
}