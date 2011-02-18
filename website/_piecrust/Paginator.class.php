<?php

class Paginator
{
    protected $pieCrust;
	protected $pageUri;
	protected $pageNumber;
    
    public function __construct(PieCrust $pieCrust, Page $page)
    {
        $this->pieCrust = $pieCrust;
		$this->pageUri = $page->getUri();
		$this->pageNumber = $page->getPageNumber();
    }
	
	protected $paginationData;
    
    public function getPaginationData()
	{
		if ($this->paginationData === null)
		{
			$postsData = array();
			$nextPageIndex = null;
			$previousPageIndex = ($this->pageNumber > 2) ? $this->pageNumber - 1 : '';
			
			// Find all HTML posts in the posts directory.
			$pathPattern = $this->pieCrust->getPostsDir() . '*.html';
			$paths = glob($pathPattern, GLOB_ERR);
			if ($paths === false)
				throw new PieCrustException('An error occured while reading the posts directory.');
			if (count($paths) > 0)
			{
				// Posts will be named year-month-day_title.html so reverse-sorting the files by name
				// should arrange them in a nice counter-chronological order.
				rsort($paths);
				
				// Load all the posts for the requested page number (page numbers start at '1').
				$postsPerPage = $this->pieCrust->getConfigValue('site', 'posts_per_page');
				$postsDateFormat = $this->pieCrust->getConfigValue('site', 'posts_date_format');
				$offset = ($this->pageNumber - 1) * $postsPerPage;
				$upperLimit = min($offset + $postsPerPage, count($paths));
				for ($i = $offset; $i < $upperLimit; ++$i)
				{
					$matches = array();
					$filename = pathinfo($paths[$i], PATHINFO_FILENAME);
					if (preg_match('/^((\d+)-(\d+)-(\d+))_(.*)$/', $filename, $matches) == false)
						continue;
						
					$post = new Page($this->pieCrust, '/' . $matches[2] . '/' . $matches[3] . '/' . $matches[4] . '/' . $matches[5]);
					$postConfig = $post->getConfig();
					$postDateTime = strtotime($matches[1]);
					$postContents = $post->getContents();
					$postContentsSplit = preg_split('/^<!--\s*(more|(page)?break)\s*-->\s*$/m', $postContents, 2);
					$postUri = $post->getUri();
					
					array_push($postsData, array(
						'title' => $postConfig['title'],
						'url' => $postUri,
						'date' => date($postsDateFormat, $postDateTime),
						'content' => $postContentsSplit[0]
					));
				}
				
				if ($offset + $postsPerPage < count($paths))
				{
					// There's another page following this one.
					$nextPageIndex = $this->pageNumber + 1;
				}
			}
			
			$this->paginationData = array(
									'posts' => $postsData,
									'prev_page' => ($this->pageUri == '_index' && $previousPageIndex == null) ? '' : $this->pageUri . '/' . $previousPageIndex,
									'this_page' => $this->pageUri . '/' . $this->pageNumber,
									'next_page' => $this->pageUri . '/' . $nextPageIndex
									);
		}
        return $this->paginationData;
    }
	
	public function posts()
	{
		$pagination = $this->getPaginationData();
		return $pagination['posts'];
	}
	
	public function prev_page()
	{
		$pagination = $this->getPaginationData();
		return $pagination['prev_page'];
	}
	
	public function this_page()
	{
		$pagination = $this->getPaginationData();
		return $pagination['this_page'];
	}
	
	public function next_page()
	{
		$pagination = $this->getPaginationData();
		return $pagination['next_page'];
	}
}