<?php

namespace PieCrust\Baker;

use \Exception;
use \RecursiveDirectoryIterator;
use \RecursiveIteratorIterator;
use PieCrust\IPage;
use PieCrust\IPieCrust;
use PieCrust\PieCrustDefaults;
use PieCrust\PieCrustCacheInfo;
use PieCrust\PieCrustException;
use PieCrust\Util\UriBuilder;
use PieCrust\Util\PageHelper;
use PieCrust\Util\PathHelper;


/**
 * A class that 'bakes' a PieCrust website into a bunch of static HTML files.
 */
class PieCrustBaker
{
    /**
     * Default directories and files.
     */
    const DEFAULT_BAKE_DIR = '_counter';
    const BAKE_INFO_FILE = 'bakeinfo.json';

    protected $bakeRecord;
    protected $logger;
    
    protected $pieCrust;
    /**
     * Get the app hosted in the baker.
     */
    public function getApp()
    {
        return $this->pieCrust;
    }
    
    protected $parameters;
    /**
     * Gets the baking parameters.
     */
    public function getParameters()
    {
        return $this->parameters;
    }
    
    /**
     * Get a baking parameter's value.
     */
    public function getParameterValue($key)
    {
        return $this->parameters[$key];
    }
    
    /**
     * Sets a baking parameter's value.
     */
    public function setParameterValue($key, $value)
    {
        $this->parameters[$key] = $value;
    }
    
    protected $bakeDir;
    /**
     * Gets the bake (output) directory.
     */
    public function getBakeDir()
    {
        if ($this->bakeDir === null)
        {
            $defaultBakeDir = $this->pieCrust->getRootDir() . self::DEFAULT_BAKE_DIR;
            $this->setBakeDir($defaultBakeDir);
        }
        return $this->bakeDir;
    }
    
    /**
     * Sets the bake (output) directory.
     */
    public function setBakeDir($dir)
    {
        $this->bakeDir = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR;
        if (is_writable($this->bakeDir) === false)
        {
            try
            {
                if (!is_dir($this->bakeDir))
                {
                    if (@mkdir($this->bakeDir, 0777, true) === false)
                        throw new PieCrustException("Can't create bake directory: " . $this->bakeDir);
                }
                else
                {
                    if (@chmod($this->bakeDir, 0777) === false)
                        throw new PieCrustException("Can't make bake directory writeable: " . $this->bakeDir);
                }
            }
            catch (Exception $e)
            {
                throw new PieCrustException('The bake directory must exist and be writable, and we can\'t create it or change the permissions ourselves: ' . $this->bakeDir, 0, $e);
            }
        }
    }
    
    /**
     * Creates a new instance of the PieCrustBaker.
     */
    public function __construct(IPieCrust $pieCrust, array $bakerParameters = array(), $logger = null)
    {
        $this->pieCrust = $pieCrust;
        $this->pieCrust->getConfig()->setValue('baker/is_baking', false);
        
        $bakerParametersFromApp = $this->pieCrust->getConfig()->getValue('baker');
        if ($bakerParametersFromApp == null)
            $bakerParametersFromApp = array();
        $this->parameters = array_merge(array(
                'smart' => true,
                'clean_cache' => false,
                'info_only' => false,
                'config_variant' => null,
                'copy_assets' => true,
                'processors' => '*',
                'skip_patterns' => array(),
                'force_patterns' => array(),
                'tag_combinations' => array()
            ),
            $bakerParametersFromApp,
            $bakerParameters
        );

        if ($logger == null)
        {
            require_once 'Log.php';
            $logger = \Log::singleton('null', '', '');
        }
        $this->logger = $logger;
        
        // Validate and explode the tag combinations.
        $combinations = $this->parameters['tag_combinations'];
        if ($combinations)
        {
            if (!is_array($combinations))
                $combinations = array($combinations);
            $combinationsExploded = array();
            foreach ($combinations as $comb)
            {
                $combExploded = explode('/', $comb);
                if (count($combExploded) > 1)
                    $combinationsExploded[] = $combExploded;
            }
            $this->parameters['tag_combinations'] = $combinationsExploded;
        }
        
        // Apply the default configuration variant, if it exists.
        $variants = $this->pieCrust->getConfig()->getValue('baker/config_variants');
        if ($variants and isset($variants['default']))
        {
            if (!is_array($variants['default']))
            {
                throw new PieCrustException("Baker configuration variant '".$variantName."' is not an array. Check your configuration file.");
            }
            $this->pieCrust->getConfig()->merge($variants['default']);
        }
        
        // Apply the specified configuration variant, if any.
        if ($this->parameters['config_variant'])
        {
            if (!$variants)
            {
                throw new PieCrustException("No baker configuration variants have been defined. You need to create a 'baker/config_variants' section in the configuration file.");
            }
            $variantName = $this->parameters['config_variant'];
            if (!isset($variants[$variantName]))
            {
                throw new PieCrustException("Baker configuration variant '".$variantName."' does not exist. Check your configuration file.");
            }
            $configVariant = $variants[$variantName];
            if (!is_array($configVariant))
            {
                throw new PieCrustException("Baker configuration variant '".$variantName."' is not an array. Check your configuration file.");
            }
            $this->pieCrust->getConfig()->merge($configVariant);
        }
    }
    
    /**
     * Bakes the website.
     */
    public function bake()
    {
        $overallStart = microtime(true);
        
        // Set the root for file-url mode, if specified.
        if ($this->pieCrust->getConfig()->getValue('baker/file_urls'))
        {
            $this->pieCrust->getConfig()->setValue('site/root', str_replace(DIRECTORY_SEPARATOR, '/', $this->getBakeDir()));
        }
        
        // Display the banner.
        $bannerLevel = PEAR_LOG_DEBUG;
        if ($this->parameters['info_only'])
            $bannerLevel = PEAR_LOG_NOTICE;
        $this->logger->log("PieCrust Baker v." . PieCrustDefaults::VERSION, $bannerLevel);
        $this->logger->log("  website :  " . $this->pieCrust->getRootDir(), $bannerLevel);
        $this->logger->log("  output  :  " . $this->getBakeDir(), $bannerLevel);
        $this->logger->log("  url     :  " . $this->pieCrust->getConfig()->getValueUnchecked('site/root'), $bannerLevel);
        if ($this->parameters['info_only'])
            return;
        
        // Setup the PieCrust environment.
        if ($this->parameters['copy_assets'])
            $this->pieCrust->getEnvironment()->getPageRepository()->setAssetUrlBaseRemap('%site_root%%uri%');
        $this->pieCrust->getConfig()->setValue('baker/is_baking', true);
        
        // Create the bake record.
        $blogKeys = $this->pieCrust->getConfig()->getValueUnchecked('site/blogs');
        $bakeInfoPath = false;
        if ($this->pieCrust->isCachingEnabled())
            $bakeInfoPath = $this->pieCrust->getCacheDir() . self::BAKE_INFO_FILE;
        $this->bakeRecord = new BakeRecord($blogKeys, $bakeInfoPath);
        
        // Get the cache validity information.
        $cacheInfo = new PieCrustCacheInfo($this->pieCrust);
        $cacheValidity = $cacheInfo->getValidity(false);
        
        // Figure out if we need to clean the cache.
        if ($this->pieCrust->isCachingEnabled())
            $this->cleanCacheIfNeeded($cacheValidity);

        // Bake!
        $this->bakePosts();
        $this->bakePages();
        $this->bakeRecord->collectTagCombinations($this->pieCrust->getEnvironment()->getLinkCollector());
        $this->bakeTags();
        $this->bakeCategories();
    
        $dirBaker = new DirectoryBaker($this->pieCrust,
            $this->getBakeDir(),
            array(
                'smart' => $this->parameters['smart'],
                'skip_patterns' => $this->parameters['skip_patterns'],
                'force_patterns' => $this->parameters['force_patterns'],
                'processors' => $this->parameters['processors']
            ),
            $this->logger
        );
        $dirBaker->bake();
        
        // Save the bake record and clean up.
        if ($bakeInfoPath)
            $this->bakeRecord->saveBakeInfo($bakeInfoPath);
        $this->bakeRecord = null;
        
        $this->pieCrust->getConfig()->setValue('baker/is_baking', false);
        
        $this->logger->info('-------------------------');
        $this->logger->notice(self::formatTimed($overallStart, 'done baking'));
    }

    protected function cleanCacheIfNeeded(array $cacheValidity)
    {
        $cleanCache = $this->parameters['clean_cache'];
        $cleanCacheReason = "ordered to";
        if (!$cleanCache)
        {
            if (!$cacheValidity['is_valid'])
            {
                $cleanCache = true;
                $cleanCacheReason = "not valid anymore";
            }
        }
        if (!$cleanCache)
        {
            if ($this->bakeRecord->shouldDoFullBake())
            {
                $cleanCache = true;
                $cleanCacheReason = "need bake info regen";
            }
        }
        // If any template file changed since last time, we also need to re-bake everything
        // (there's no way to know what weird conditional template inheritance/inclusion
        //  could be in use...).
        if (!$cleanCache)
        {
            $maxMTime = 0;
            foreach ($this->pieCrust->getTemplatesDirs() as $dir)
            {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dir), 
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($iterator as $path)
                {
                    if ($path->isFile())
                    {
                        $maxMTime = max($maxMTime, $path->getMTime());
                    }
                }
            }
            if ($maxMTime >= $this->bakeRecord->getLast('time'))
            {
                $cleanCache = true;
                $cleanCacheReason = "templates modified";
            }
        }
        if ($cleanCache)
        {
            $start = microtime(true);
            PathHelper::deleteDirectoryContents($this->pieCrust->getCacheDir());
            file_put_contents($cacheValidity['path'], $cacheValidity['hash']);
            $this->logger->info(self::formatTimed($start, 'cleaned cache (reason: ' . $cleanCacheReason . ')'));
            
            $this->parameters['smart'] = false;
        }
    }
    
    protected function bakePages()
    {
        if ($this->bakeRecord == null)
            throw new PieCrustException("Can't bake pages without a bake-record active.");
        if (!$this->hasPages())
            return;

        $pages = PageHelper::getPages($this->pieCrust);
        foreach ($pages as $page)
        {
            $this->bakePage($page);
        }
    }
    
    protected function bakePage(IPage $page)
    {
        // Don't bake this file if it is up-to-date and 
        // is not using any posts (if any was rebaked).
        $relativePath = PageHelper::getRelativePath($page);
        if (!$this->shouldRebakeFile($page->getPath()) and 
                (!$this->bakeRecord->wasAnyPostBaked() or 
                 !$this->bakeRecord->isPageUsingPosts($relativePath))
           )
        {
            return false;
        }
        
        $start = microtime(true);
        $baker = new PageBaker($this->getBakeDir(), $this->getPageBakerParameters());
        $baker->bake($page);
        if ($baker->wasPaginationDataAccessed())
        {
            $this->bakeRecord->addPageUsingPosts($relativePath);
        }
        
        $pageCount = $baker->getPageCount();
        $this->logger->info(self::formatTimed($start, $relativePath . (($pageCount > 1) ? " [{$pageCount}]" : "")));
        return true;
    }
    
    protected function bakePosts()
    {
        if ($this->bakeRecord == null)
            throw new PieCrustException("Can't bake posts without a bake-record active.");
        if (!$this->hasPosts())
            return;

        $blogKeys = $this->pieCrust->getConfig()->getValue('site/blogs');
        foreach ($blogKeys as $blogKey)
        {
            $posts = PageHelper::getPosts($this->pieCrust, $blogKey);
            foreach ($posts as $post)
            {
                $this->bakePost($post);
            }
        }
    }

    protected function bakePost(IPage $post)
    {
        $postWasBaked = false;
        if ($this->shouldRebakeFile($post->getPath()))
        {
            $start = microtime(true);
            $baker = new PageBaker($this->getBakeDir(), $this->getPageBakerParameters());
            $baker->bake($post);
            $postWasBaked = true;
            $this->logger->info(self::formatTimed($start, $post->getUri()));
        }

        $postInfo = array();
        $postInfo['blogKey'] = $post->getBlogKey();
        $postInfo['tags'] = $post->getConfig()->getValue('tags');
        $postInfo['category'] = $post->getConfig()->getValue('category');
        $postInfo['wasBaked'] = $postWasBaked;
        $this->bakeRecord->addPostInfo($postInfo);
    }
    
    protected function bakeTags()
    {
        if ($this->bakeRecord == null)
            throw new PieCrustException("Can't bake tags without a bake-record active.");
        if (!$this->hasPages() or !$this->hasPosts())
            return;
        
        $blogKeys = $this->pieCrust->getConfig()->getValueUnchecked('site/blogs');
        foreach ($blogKeys as $blogKey)
        {
            // Check that there is a tag listing page to bake.
            $prefix = '';
            if ($blogKey != PieCrustDefaults::DEFAULT_BLOG_KEY)
                $prefix = $blogKey . DIRECTORY_SEPARATOR;
            $tagPagePath = $this->pieCrust->getPagesDir() . $prefix . PieCrustDefaults::TAG_PAGE_NAME . '.html';
            if (!is_file($tagPagePath))
                continue;
            
            // Get single and multi tags to bake.
            $tagsToBake = $this->bakeRecord->getTagsToBake($blogKey);
            $combinations = $this->parameters['tag_combinations'];
            if ($blogKey != PieCrustDefaults::DEFAULT_BLOG_KEY)
            {
                if (array_key_exists($blogKey, $combinations))
                    $combinations = $combinations[$blogKey];
                else
                    $combinations = array();
            }
            $lastKnownCombinations = $this->bakeRecord->getLast('knownTagCombinations');
            if (array_key_exists($blogKey, $lastKnownCombinations))
            {
                $combinations = array_merge($combinations, $lastKnownCombinations[$blogKey]);
                $combinations = array_unique($combinations);
            }
            if (count($combinations) > 0)
            {
                // Filter combinations that contain tags that got invalidated.
                $combinationsToBake = array();
                foreach ($combinations as $comb)
                {
                    $explodedComb = explode('/', $comb);
                    if (count(array_intersect($explodedComb, $tagsToBake)) > 0)
                        $combinationsToBake[] = $explodedComb;
                }
                $tagsToBake = array_merge($combinationsToBake, $tagsToBake);
            }

            // Order tags so it looks nice when we bake.
            usort($tagsToBake, function ($t1, $t2) {
                if (is_array($t1))
                    $t1 = implode('+', $t1);
                if (is_array($t2))
                    $t2 = implode('+', $t2);
                return strcmp($t1, $t2);
            });
            
            // Bake!
            $pageRepository = $this->pieCrust->getEnvironment()->getPageRepository();
            foreach ($tagsToBake as $tag)
            {
                $start = microtime(true);
                
                $formattedTag = $tag;
                if (is_array($tag))
                    $formattedTag = implode('+', $tag);
                
                $postInfos = $this->bakeRecord->getPostsTagged($blogKey, $tag);
                if (count($postInfos) > 0)
                {
                    $uri = UriBuilder::buildTagUri($this->pieCrust->getConfig()->getValue($blogKey.'/tag_url'), $tag);
                    $page = $pageRepository->getOrCreatePage(
                        $uri,
                        $tagPagePath,
                        IPage::TYPE_TAG,
                        $blogKey,
                        $tag
                    );
                    $baker = new PageBaker($this->getBakeDir(), $this->getPageBakerParameters());
                    $baker->bake($page);

                    $pageCount = $baker->getPageCount();
                    $this->logger->info(self::formatTimed($start, $formattedTag . (($pageCount > 1) ? " [{$pageCount}]" : "")));
                }
            }
        }
    }
    
    protected function bakeCategories()
    {
        if ($this->bakeRecord == null)
            throw new PieCrustException("Can't bake categories without a bake-record active.");
        if (!$this->hasPages() or !$this->hasPosts())
            return;
        
        $blogKeys = $this->pieCrust->getConfig()->getValueUnchecked('site/blogs');
        $pageRepository = $this->pieCrust->getEnvironment()->getPageRepository();
        foreach ($blogKeys as $blogKey)
        {
            // Check that there is a category listing page to bake.
            $prefix = '';
            if ($blogKey != PieCrustDefaults::DEFAULT_BLOG_KEY)
                $prefix = $blogKey . DIRECTORY_SEPARATOR;
            $categoryPagePath = $this->pieCrust->getPagesDir() . $prefix . PieCrustDefaults::CATEGORY_PAGE_NAME . '.html';
            if (!is_file($categoryPagePath))
                continue;

            // Order categories so it looks nicer when we bake.
            $categoriesToBake = $this->bakeRecord->getCategoriesToBake($blogKey);
            sort($categoriesToBake);

            // Bake!
            foreach ($categoriesToBake as $category)
            {
                $start = microtime(true);
                $postInfos = $this->bakeRecord->getPostsInCategory($blogKey, $category);
                $uri = UriBuilder::buildCategoryUri($this->pieCrust->getConfig()->getValue($blogKey.'/category_url'), $category);
                $page = $pageRepository->getOrCreatePage(
                    $uri, 
                    $categoryPagePath,
                    IPage::TYPE_CATEGORY,
                    $blogKey,
                    $category
                );
                $baker = new PageBaker($this->getBakeDir(), $this->getPageBakerParameters());
                $baker->bake($page, $postInfos);

                $pageCount = $baker->getPageCount();
                $this->logger->info(self::formatTimed($start, $category . (($pageCount > 1) ? " [{$pageCount}]" : "")));
            }
        }
    }

    protected function hasPages()
    { 
        return ($this->pieCrust->getPagesDir() !== false);
    }

    protected function hasPosts()
    {
        return ($this->pieCrust->getPostsDir() !== false);
    }
    
    protected function shouldRebakeFile($path)
    {
        if ($this->parameters['smart'])
        {
            if (filemtime($path) < $this->bakeRecord->getLast('time'))
            {
                return false;
            }
        }
        return true;
    }
    
    protected function getPageBakerParameters()
    {
        return array(
            'copy_assets' => $this->parameters['copy_assets']
        );
    }
    
    public static function formatTimed($startTime, $message)
    {
        $endTime = microtime(true);
        return sprintf('[%8.1f ms] ', ($endTime - $startTime)*1000.0) . $message;
    }
}
