<?php
/**
 * Weblate API Client
 * 
 * @package PublishPress\Translations
 */

namespace PublishPress\Translations;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Exception;

class WeblateClient
{
    /**
     * Weblate API base URL
     * 
     * @var string
     */
    private $apiUrl;
    
    /**
     * Weblate API token
     * 
     * @var string
     */
    private $apiToken;
    
    /**
     * HTTP client
     * 
     * @var Client
     */
    private $client;
    
    /**
     * Constructor
     * 
     * @param string|null $apiUrl
     * @param string|null $apiToken
     * @throws Exception
     */
    public function __construct($apiUrl = null, $apiToken = null)
    {
        $this->apiUrl = $apiUrl ?: getenv('WEBLATE_API_URL') ?: 'https://hosted.weblate.org/api/';
        $this->apiToken = $apiToken ?: getenv('WEBLATE_API_TOKEN');
        
        if (!$this->apiToken) {
            throw new Exception(
                "Weblate API token not found.\n" .
                "Please set WEBLATE_API_TOKEN environment variable.\n" .
                "Get your token from: https://hosted.weblate.org/accounts/profile/#api"
            );
        }
        
        $this->apiUrl = rtrim($this->apiUrl, '/') . '/';
        
        $this->client = new Client([
            'base_uri' => $this->apiUrl,
            'headers' => [
                'Authorization' => 'Token ' . $this->apiToken,
                'Accept' => 'application/json',
            ],
            'timeout' => 30,
        ]);
    }
    
    /**
     * Check if project exists
     * 
     * @param string $projectSlug
     * @return bool
     */
    public function projectExists($projectSlug)
    {
        try {
            $response = $this->client->get("projects/{$projectSlug}/");
            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            if ($e->getCode() === 404) {
                return false;
            }
            throw new Exception("Error checking project: " . $e->getMessage());
        }
    }
    
    /**
     * Create a new project
     * 
     * @param string $projectSlug
     * @param string $projectName
     * @return array
     * @throws Exception
     */
    public function createProject($projectSlug, $projectName)
    {
        try {
            $response = $this->client->post('projects/', [
                'json' => [
                    'name' => $projectName,
                    'slug' => $projectSlug,
                    'web' => "https://github.com/publishpress/{$projectSlug}",
                ]
            ]);
            
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            throw new Exception("Error creating project: " . $e->getMessage());
        }
    }
    
    /**
     * Check if component exists
     * 
     * @param string $projectSlug
     * @param string $componentSlug
     * @return bool
     */
    public function componentExists($projectSlug, $componentSlug)
    {
        try {
            $response = $this->client->get("components/{$projectSlug}/{$componentSlug}/");
            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            if ($e->getCode() === 404) {
                return false;
            }
            throw new Exception("Error checking component: " . $e->getMessage());
        }
    }
    
    /**
     * Create a new component
     * 
     * @param string $projectSlug
     * @param string $componentSlug
     * @param string $componentName
     * @param string $potFilePath
     * @param string|null $pluginSlug GitHub repo slug (defaults to projectSlug)
     * @return array
     * @throws Exception
     */
    public function createComponent($projectSlug, $componentSlug, $componentName, $potFilePath, $pluginSlug = null)
    {
        try {
            // Read POT file content
            $potContent = file_get_contents($potFilePath);
            if ($potContent === false) {
                throw new Exception("Failed to read POT file: {$potFilePath}");
            }
            
            // Use GitHub repo for .pot file reference, but disable auto-updates
            // We'll upload .po files manually via API to keep them current
            $repoSlug = $pluginSlug ?: $projectSlug;
            $repoUrl = "https://github.com/publishpress/{$repoSlug}.git";
            
            $response = $this->client->post("projects/{$projectSlug}/components/", [
                'json' => [
                    'name' => $componentName,
                    'slug' => $componentSlug,
                    'repo' => $repoUrl,
                    'branch' => 'development',
                    'push' => '',
                    'vcs' => 'git',
                    'file_format' => 'po',
                    'filemask' => "languages/{$componentSlug}-*.po",
                    'new_base' => "languages/{$componentSlug}.pot",
                    'new_lang' => 'add',
                    'manage_units' => false,
                    'update_on_commit' => false,
                ]
            ]);
            
            $result = json_decode($response->getBody()->getContents(), true);
            
            // After creating component, upload the POT file
            $this->uploadPot($projectSlug, $componentSlug, $potFilePath);
            
            return $result;
        } catch (GuzzleException $e) {
            $errorBody = '';
            if (method_exists($e, 'getResponse') && $e->getResponse()) {
                $errorBody = $e->getResponse()->getBody()->getContents();
            }
            throw new Exception("Error creating component: " . $e->getMessage() . "\n" . $errorBody);
        }
    }
    
    /**
     * Upload POT file to component
     * 
     * @param string $projectSlug
     * @param string $componentSlug
     * @param string $potFilePath
     * @return array
     * @throws Exception
     */
    public function uploadPot($projectSlug, $componentSlug, $potFilePath)
    {
        try {
            $response = $this->client->post(
                "translations/{$projectSlug}/{$componentSlug}/en/file/",
                [
                    'multipart' => [
                        [
                            'name' => 'file',
                            'contents' => fopen($potFilePath, 'r'),
                            'filename' => basename($potFilePath),
                        ],
                        [
                            'name' => 'method',
                            'contents' => 'replace',
                        ],
                    ]
                ]
            );
            
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            throw new Exception("Error uploading POT file: " . $e->getMessage());
        }
    }
    
    /**
     * Upload PO file for a language
     * 
     * @param string $projectSlug
     * @param string $componentSlug
     * @param string $language
     * @param string $poFilePath
     * @return array
     * @throws Exception
     */
    public function uploadPo($projectSlug, $componentSlug, $language, $poFilePath)
    {
        try {
            // Ensure translation exists for this language
            $this->ensureTranslation($projectSlug, $componentSlug, $language);
            
            $response = $this->client->post(
                "translations/{$projectSlug}/{$componentSlug}/{$language}/file/",
                [
                    'multipart' => [
                        [
                            'name' => 'file',
                            'contents' => fopen($poFilePath, 'r'),
                            'filename' => basename($poFilePath),
                        ],
                        [
                            'name' => 'method',
                            'contents' => 'replace',
                        ],
                    ]
                ]
            );
            
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            throw new Exception("Error uploading PO file for {$language}: " . $e->getMessage());
        }
    }
    
    /**
     * Ensure translation exists for a language
     * 
     * @param string $projectSlug
     * @param string $componentSlug
     * @param string $language
     * @return void
     * @throws Exception
     */
    private function ensureTranslation($projectSlug, $componentSlug, $language)
    {
        try {
            // Check if translation exists
            $this->client->get("translations/{$projectSlug}/{$componentSlug}/{$language}/");
        } catch (GuzzleException $e) {
            if ($e->getCode() === 404) {
                // Translation doesn't exist, create it
                try {
                    $this->client->post("components/{$projectSlug}/{$componentSlug}/translations/", [
                        'json' => [
                            'language_code' => $language,
                        ]
                    ]);
                } catch (GuzzleException $createError) {
                    throw new Exception("Error creating translation for {$language}: " . $createError->getMessage());
                }
            } else {
                throw new Exception("Error checking translation for {$language}: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Download PO file for a language
     * 
     * @param string $projectSlug
     * @param string $componentSlug
     * @param string $language
     * @return string|null PO file content or null if not found
     * @throws Exception
     */
    public function downloadPo($projectSlug, $componentSlug, $language)
    {
        try {
            $response = $this->client->get(
                "translations/{$projectSlug}/{$componentSlug}/{$language}/file/"
            );
            
            return $response->getBody()->getContents();
        } catch (GuzzleException $e) {
            if ($e->getCode() === 404) {
                return null; // Translation doesn't exist yet
            }
            throw new Exception("Error downloading PO file for {$language}: " . $e->getMessage());
        }
    }
    
    /**
     * Get component statistics
     * 
     * @param string $projectSlug
     * @param string $componentSlug
     * @return array
     * @throws Exception
     */
    public function getComponentStats($projectSlug, $componentSlug)
    {
        try {
            $response = $this->client->get("components/{$projectSlug}/{$componentSlug}/statistics/");
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            throw new Exception("Error getting component stats: " . $e->getMessage());
        }
    }
}