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
        'de_DE',
        'pt_BR', 
        'id_ID',
        'fil',
        'ru_RU',
        'yo',
        'fi',
        'ja',
        'ko_KR'
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
     * Weblate integration enabled
     * 
     * @var bool
     */
    private $weblateEnabled = false;
    
    /**
     * Weblate client
     * 
     * @var WeblateClient|null
     */
    private $weblateClient = null;
    
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
        
        // Check if Weblate is enabled
        if (getenv('WEBLATE_API_TOKEN')) {
            try {
                $this->weblateClient = new WeblateClient();
                $this->weblateEnabled = true;
            } catch (Exception $e) {
                // Weblate not configured, continue without it
                $this->weblateEnabled = false;
            }
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
     * Enable or disable Weblate integration
     * 
     * @param bool $enabled
     */
    public function setWeblateEnabled($enabled)
    {
        $this->weblateEnabled = (bool) $enabled;
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
     * Get plugin slug from composer.json
     * Falls back to directory name if not found
     * 
     * @return string
     */
    private function getPluginSlug()
    {
        $composerFile = $this->pluginRoot . '/composer.json';
        if (file_exists($composerFile)) {
            $composerData = json_decode(file_get_contents($composerFile), true);
            if (isset($composerData['extra']['plugin-slug'])) {
                return $composerData['extra']['plugin-slug'];
            }
            if (isset($composerData['name'])) {
                $parts = explode('/', $composerData['name']);
                if (count($parts) === 2) {
                    return $parts[0] . '-' . $parts[1];
                }
            }
        }
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
     * Upload translations to Weblate (internal method)
     * 
     * @param string $potFile
     * @param string $textDomain
     * @throws Exception
     */
    private function uploadToWeblateInternal($potFile, $textDomain)
    {
        if (!$this->weblateClient) {
            throw new Exception('Weblate client not initialized');
        }
        
        echo "\n Uploading to Weblate...\n";
        
        $pluginSlug = $this->getPluginSlug();
        $projectSlug = $pluginSlug;
        $componentSlug = $textDomain;
        
        // Step 1: Ensure project exists
        echo "  • Checking project '{$projectSlug}'...\n";
        if (!$this->weblateClient->projectExists($projectSlug)) {
            echo "  • Creating project '{$projectSlug}'...\n";
            $this->weblateClient->createProject($projectSlug, $pluginSlug);
        }
        
        // Step 2: Check if component exists
        echo "  • Checking component '{$componentSlug}'...\n";
        if (!$this->weblateClient->componentExists($projectSlug, $componentSlug)) {
            echo "  ⚠️  Component '{$componentSlug}' does not exist on Weblate.\n";
            echo "\n";
            echo "  Please create it manually in Weblate UI:\n";
            echo "  1. Go to: https://hosted.weblate.org/projects/{$projectSlug}/\n";
            echo "  2. Click 'Add new translation component'\n";
            echo "  3. Use these settings:\n";
            echo "     - Component name: {$textDomain}\n";
            echo "     - Component slug: {$componentSlug}\n";
            echo "     - File format: Gettext PO file\n";
            echo "     - File mask: languages/{$componentSlug}-*.po\n";
            echo "     - Template: languages/{$componentSlug}.pot\n";
            echo "     - VCS: No VCS (file uploads only)\n";
            echo "  4. Then run this command again to upload files.\n";
            echo "\n";
            throw new Exception("Component not found. Please create it manually first.");
        }
        
        // Update POT file
        echo "  • Updating POT file...\n";
        $this->weblateClient->uploadPot($projectSlug, $componentSlug, $potFile);
        
        // Step 3: Upload PO files
        $poFiles = [];
        $files = scandir($this->languagesDir);
        foreach ($files as $file) {
            if (substr($file, 0, strlen($textDomain . '-')) === $textDomain . '-' && substr($file, -3) === '.po') {
                $poFiles[] = $this->languagesDir . '/' . $file;
            }
        }
        
        if (empty($poFiles)) {
            echo "  ⚠️  No PO files found to upload\n";
            return;
        }
        
        echo "  • Uploading " . count($poFiles) . " translation files...\n";
        foreach ($poFiles as $poFile) {
            $fileName = basename($poFile);
            // Extract language code from filename (e.g., "plugin-name-de_DE.po" -> "de_DE")
            $language = str_replace($textDomain . '-', '', str_replace('.po', '', $fileName));
            
            try {
                $this->weblateClient->uploadPo($projectSlug, $componentSlug, $language, $poFile);
                echo "    ✓ {$language}\n";
            } catch (Exception $e) {
                echo "    ✗ {$language}: " . $e->getMessage() . "\n";
            }
        }
        
        echo "\n✅ Weblate upload complete!\n";
        echo "  View at: https://hosted.weblate.org/projects/{$projectSlug}/{$componentSlug}/\n\n";
    }
    
    /**
     * Upload existing translations to Weblate (public method)
     * 
     * @return bool
     */
    public function uploadToWeblate()
    {
        if (!$this->weblateClient) {
            fwrite(STDERR, "Error: Weblate not configured.\n");
            fwrite(STDERR, "Please set WEBLATE_API_TOKEN environment variable.\n\n");
            return false;
        }
        
        $pluginName = $this->getPluginName();
        
        echo "\n📤 PublishPress Translation Upload\n";
        echo str_repeat('=', 50) . "\n\n";
        echo "Plugin: {$pluginName}\n";
        echo "Path: {$this->pluginRoot}\n\n";
        
        $potFiles = $this->findPotFiles();
        
        if (empty($potFiles)) {
            fwrite(STDERR, "Error: No .pot files found in {$this->languagesDir}\n");
            return false;
        }
        
        echo "📤 Uploading translations to Weblate...\n";
        echo "POT files found: " . count($potFiles) . "\n\n";
        
        $success = true;
        foreach ($potFiles as $potFile) {
            $potFileName = basename($potFile);
            $textDomain = str_replace('.pot', '', $potFileName);
            
            echo "[" . basename($potFile) . "]\n";
            
            try {
                $this->uploadToWeblateInternal($potFile, $textDomain);
            } catch (Exception $e) {
                fwrite(STDERR, "⚠️  Warning: Weblate upload failed for {$textDomain}: " . $e->getMessage() . "\n\n");
                $success = false;
            }
        }
        
        echo str_repeat('=', 50) . "\n";
        echo "✨ Upload " . ($success ? 'complete' : 'finished with errors') . " for {$pluginName}!\n\n";
        
        return $success;
    }
    
    /**
     * Download translations from Weblate
     * 
     * @param bool $silent If true, suppress output messages
     * @return bool
     */
    public function downloadFromWeblate($silent = false)
    {
        if (!$this->weblateClient) {
            if (!$silent) {
                fwrite(STDERR, "Error: Weblate not configured.\n");
                fwrite(STDERR, "Please set WEBLATE_API_TOKEN environment variable.\n\n");
            }
            return false;
        }
        
        $pluginSlug = $this->getPluginSlug();
        $projectSlug = $pluginSlug;
        
        if (!$silent) {
            echo "\n⬇️  Downloading Translations from Weblate\n";
            echo str_repeat('=', 50) . "\n\n";
            echo "Plugin: {$pluginSlug}\n";
            echo "Project: {$projectSlug}\n\n";
        }
        
        // Find all POT files to determine components
        $potFiles = $this->findPotFiles();
        
        if (empty($potFiles)) {
            if (!$silent) {
                fwrite(STDERR, "Error: No .pot files found in {$this->languagesDir}\n");
            }
            return false;
        }
        
        $success = true;
        $totalDownloaded = 0;
        
        foreach ($potFiles as $potFile) {
            $potFileName = basename($potFile);
            $textDomain = str_replace('.pot', '', $potFileName);
            
            if (!$silent) {
                echo "Component: {$textDomain}\n";
            }
            
            // Check if component exists
            try {
                if (!$this->weblateClient->componentExists($projectSlug, $textDomain)) {
                    if (!$silent) {
                        echo "  ⚠️  Component not found on Weblate, skipping...\n\n";
                    }
                    continue;
                }
            } catch (Exception $e) {
                if (!$silent) {
                    fwrite(STDERR, "  ❌ Error checking component: " . $e->getMessage() . "\n\n");
                }
                $success = false;
                continue;
            }
            
            // Download translations for each target language
            foreach ($this->targetLanguages as $language) {
                try {
                    $poContent = $this->weblateClient->downloadPo($projectSlug, $textDomain, $language);
                    
                    if ($poContent) {
                        $poFile = $this->languagesDir . '/' . $textDomain . '-' . $language . '.po';
                        file_put_contents($poFile, $poContent);
                        
                        // Convert PO to MO
                        $moFile = $this->languagesDir . '/' . $textDomain . '-' . $language . '.mo';
                        $this->convertPoToMo($poFile, $moFile);
                        
                        if (!$silent) {
                            echo "  ✓ {$language}\n";
                        }
                        $totalDownloaded++;
                    } else {
                        if (!$silent) {
                            echo "  ⊘ {$language} (not available)\n";
                        }
                    }
                } catch (Exception $e) {
                    if (!$silent) {
                        echo "  ✗ {$language}: " . $e->getMessage() . "\n";
                    }
                }
            }
            
            if (!$silent) {
                echo "\n";
            }
        }
        
        if (!$silent) {
            echo str_repeat('=', 50) . "\n";
            echo "✨ Downloaded {$totalDownloaded} translation files!\n\n";
        }
        
        return $success;
    }
    
    /**
     * Convert PO file to MO file
     * 
     * @param string $poFile Path to PO file
     * @param string $moFile Path to output MO file
     * @return bool True on success
     */
    private function convertPoToMo($poFile, $moFile)
    {
        // Simple PO to MO conversion
        // This is a basic implementation - for production, consider using gettext tools
        
        $entries = [];
        $currentEntry = null;
        $lines = file($poFile, FILE_IGNORE_NEW_LINES);
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            if (empty($line) || $line[0] === '#') {
                continue;
            }
            
            if (strpos($line, 'msgid') === 0) {
                if ($currentEntry && !empty($currentEntry['msgid']) && !empty($currentEntry['msgstr'])) {
                    $entries[] = $currentEntry;
                }
                $currentEntry = ['msgid' => $this->extractString($line), 'msgstr' => ''];
            } elseif (strpos($line, 'msgstr') === 0 && $currentEntry) {
                $currentEntry['msgstr'] = $this->extractString($line);
            }
        }
        
        if ($currentEntry && !empty($currentEntry['msgid']) && !empty($currentEntry['msgstr'])) {
            $entries[] = $currentEntry;
        }
        
        // Write MO file (simplified binary format)
        // For production, use proper gettext library or msgfmt command
        $mo = $this->buildMoFile($entries);
        return file_put_contents($moFile, $mo) !== false;
    }
    
    /**
     * Extract string from PO line
     * 
     * @param string $line
     * @return string
     */
    private function extractString($line)
    {
        if (preg_match('/"(.*)"/', $line, $matches)) {
            return stripcslashes($matches[1]);
        }
        return '';
    }
    
    /**
     * Build MO file content
     * 
     * @param array $entries
     * @return string
     */
    private function buildMoFile($entries)
    {
        // MO file magic number
        $magic = 0x950412de;
        $revision = 0;
        $count = count($entries);
        
        $idsOffset = 28;
        $strsOffset = $idsOffset + 8 * $count;
        
        $ids = '';
        $strs = '';
        $idsIndex = [];
        $strsIndex = [];
        
        foreach ($entries as $entry) {
            $idsIndex[] = [strlen($ids), strlen($entry['msgid'])];
            $ids .= $entry['msgid'] . "\0";
            
            $strsIndex[] = [strlen($strs), strlen($entry['msgstr'])];
            $strs .= $entry['msgstr'] . "\0";
        }
        
        $keysOffset = $strsOffset + 8 * $count;
        $valsOffset = $keysOffset + strlen($ids);
        
        $mo = pack('Iiiiiii', $magic, $revision, $count, $idsOffset, $strsOffset, 0, 0);
        
        foreach ($idsIndex as $index) {
            $mo .= pack('ii', $index[1], $keysOffset + $index[0]);
        }
        
        foreach ($strsIndex as $index) {
            $mo .= pack('ii', $index[1], $valsOffset + $index[0]);
        }
        
        $mo .= $ids . $strs;
        
        return $mo;
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
        echo "Mode: " . ($this->dryRun ? 'DRY RUN (no API calls)' : 'LIVE TRANSLATION') . "\n";
        echo "Weblate: " . ($this->weblateEnabled ? 'Enabled' : 'Disabled') . "\n\n";
        
        if (!$this->dryRun && !$this->getApiKey()) {
            fwrite(STDERR, "Error: OPENAI_API_KEY environment variable not set.\n");
            fwrite(STDERR, "Please set your OpenAI API key:\n");
            fwrite(STDERR, "  export OPENAI_API_KEY=your-api-key-here\n\n");
            return false;
        }
        
        // Step 1: Download existing translations from Weblate (if enabled)
        if ($this->weblateEnabled && !$this->dryRun) {
            echo "📥 Step 1: Downloading existing translations from Weblate...\n";
            try {
                $this->downloadFromWeblate(true); // Silent mode
                echo "✓ Existing translations downloaded\n\n";
            } catch (Exception $e) {
                echo "⚠️  No existing translations found on Weblate (this is normal for new projects)\n\n";
            }
        }
        
        $potFiles = $this->findPotFiles();
        
        if (empty($potFiles)) {
            fwrite(STDERR, "Error: No .pot files found in {$this->languagesDir}\n");
            return false;
        }
        
        echo "📝 Step 2: Running AI translation with Potomatic...\n";
        echo "POT files found: " . count($potFiles) . "\n\n";
        
        $success = true;
        foreach ($potFiles as $index => $potFile) {
            $potFileName = basename($potFile);
            $textDomain = str_replace('.pot', '', $potFileName);
            
            echo "[" . ($index + 1) . "/" . count($potFiles) . "] Processing: {$potFileName}\n";
            echo "Text domain: {$textDomain}\n";
            
            try {
                $command = $this->buildCommand($potFile, $textDomain);
                
                echo "\n" . str_repeat('-', 50) . "\n";
                echo "🤖 Running Potomatic AI Translation...\n";
                echo "This may take several minutes depending on the number of strings.\n";
                echo str_repeat('-', 50) . "\n\n";
                
                // Use passthru for real-time output
                $returnCode = 0;
                passthru($command . ' 2>&1', $returnCode);
                
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
        
        // Step 3: Upload updated translations to Weblate (if enabled)
        if ($this->weblateEnabled && !$this->dryRun && $success) {
            echo "\n📤 Step 3: Uploading updated translations to Weblate...\n\n";
            foreach ($potFiles as $potFile) {
                $potFileName = basename($potFile);
                $textDomain = str_replace('.pot', '', $potFileName);
                
                try {
                    $this->uploadToWeblate($potFile, $textDomain);
                } catch (Exception $e) {
                    fwrite(STDERR, "⚠️  Warning: Weblate upload failed for {$textDomain}: " . $e->getMessage() . "\n\n");
                    // Don't fail the whole process if Weblate upload fails
                }
            }
        }
        
        echo str_repeat('=', 50) . "\n";
        echo "✨ Translation " . ($success ? 'complete' : 'finished with errors') . " for {$pluginName}!\n\n";
        
        return $success;
    }
}