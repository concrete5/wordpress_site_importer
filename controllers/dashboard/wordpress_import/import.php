<?php  defined('C5_EXECUTE') or die(_("Access Denied."));

Loader::model('page_lite','wordpress_site_importer');
//something that would make this truly nice and almost 1:1 with a wordpress site would be to have a page for each "category"
//basically that's the only thing this isn't doing aside from bringing in comments in some form.

class DashboardWordpressImportImportController extends Controller{
	protected $fileset;
	protected $createdFiles = array();
	protected $importImages = false;
	protected $importFiles;
	protected $filesetname = 'Wordpress Files';
	protected $createFileSet;

	function on_start(){
		$cts = array();
		Loader::model('collection_types');
		$list = CollectionType::getList();
		//this just lists out the page_types in concrete5, nothing hard
		foreach($list as $ct){
			$cts[$ct->getCollectionTypeID()] = $ct->getCollectionTypeName();
		}
		$this->set('collectiontypes',$cts);
	}

	public function get_root_page() {
		Loader::model('page');
		$json = Loader::helper('json');
		$data = array();
		$rootPage = Page::getByID($this->post('new-root-wordpress'));
		$data['title'] = $rootPage->getCollectionName();
		$data['url'] = $rootPage->getCollectionPath();
		echo $json->encode($data);
		exit;
	}

	function import_wordpress_site(){
		$db = Loader::db();
		Loader::library('formatting','wordpress_site_importer');
		$this->importImages = $this->post('import-images');
		$this->createFileSet = $this->importImages;

		$unImported = $db->GetOne("SELECT COUNT(*) FROM WordpressItems where imported = 0");
		if ($unImported > 0) {
			$data = array('remain'=>$unImported,'processed'=>'','titles'=>array());
			
			$xml = $db->GetAll("SELECT wpItem,id FROM WordpressItems where imported = 0 LIMIT 10");
			$data['processed'] = sizeof($xml);
		/*	var_dump($xml);
			exit;
			*/
		} else {
			echo 0;
			exit;
		}

		$ids = array();
		foreach($xml as $wpItem){

			libxml_use_internal_errors;
			$item = @new SimpleXMLElement($wpItem['wpItem']);

			$p = new PageLite();

			//Use that namespace
			$title = (string)$item->title;
			$datePublic = $item->pubDate;
			$namespaces = $item->getNameSpaces(true);
			//Now we don't have the URL hard-coded

			$wp = $item->children($namespaces['wp']);
			$wpPostID = (int)$wp->post_id;
			$content = $item->children($namespaces['content']);
			$content = wpautop((string)$content->encoded);
			/*
				find the caption in the caption.
				replace [caption ... with <div class="wp-caption-frame"> //alternatively there should be a custom image template that creates a caption.
				that might be the more c5 way to do this.
				then [/caption] with the image and then <span class="wp-caption-text">$caption_text</span></div>
				???
			*/
			/*
				comments...
				Can't figure out why this is not working for the comments.
			*/
		/*	$comments = $wp->comment->asXML();
			var_dump($comments);
			var_dump($comments->asXML());
			 //echo $comments->asXML();
			exit;*/
			$excerpt = $item->children($namespaces['excerpt']);
			$postDate = (string)$wp->post_date;
			$postType = (string)$wp->post_type;
			$dc = $item->children($namespaces['dc']);
			$author = (string)$dc->creator;
			$parentID = (int)$wp->post_parent;
			$category = (string)$item->category;

			$p->setTitle($title);
			$p->setContent($content);
			$p->setAuthor($author);
			$p->setWpParentID($parentID);
			$p->setPostDate($postDate);
			$p->setPostType($postType);
			$p->setCategory($category);
			$p->setPostID($wpPostID);
			$p->setExcerpt($excerpt);

			//so we just throw these guys in an array
			$pages[$p->getPostID()] = $p; //postID is unique
			$ids[] = $wpItem['id'];
			$data['titles'][] = $title;
			
		}
		//call the function below
		$this->buildSiteFromWordPress($pages);

		$db->Execute('UPDATE WordpressItems set imported=1 where id in('.implode(',',$ids).')');
		$json = Loader::helper('json');
		echo $json->encode($data);
		exit;

		//foreach ($xml->id as $id)
		//idarray
		//delete or set imported
		
	}
	
	
	function buildSiteFromWordPress(array $pages){
		Loader::model('page');
		//this creates the fileset and sets it as a protected property of this controller class so we can reference it without throwing these defines around, i'll get rid of em
		//eventually
		if($this->createFileSet){
			Loader::model('file_set');
			$fs = new FileSet();
			$u = new User();
			$uID = User::getUserID();
			$newFs = FileSet::createAndGetSet($this->filesetname, 1,$uID);
			$this->fileset = $newFs;
		}



		$errors = array();
		//$message = '';
		//get our root page
		$rootPage = Page::getByID($this->post('new-root-wordpress'));
		//this is how / where to set another page for page-type pages.

		//ok so basically our keys in our array are wordpress postIDs, which are pages in the system
		//so what we need to do now (thinking here) is that we need to arrange these posts into a tree
		//$pages is in the format of the postID => pageLiteObject
		Loader::model('collection_types');
		$ctPagesID = CollectionType::getByID($this->post('wordpress-pages'));
		$ctBlogID = CollectionType::getByID($this->post('wordpress-blogs'));
		//we want to reference the collection type we are adding based on either a post or a page
		$collectionTypesForWordpress = array("POST"=>$ctBlogID,"PAGE"=>$ctPagesID);

		$parentIDPageLiteRel = array();
		$createdPages = array();
		$createdPagesReal = array();

		$fakeCreatedPages = array();
		//so our homepage is zero, and we need that in our created page, even though it isn't a page that is created for association issues but it absolutely has to be 0.
		//Then it is a relational mapping issue, this puppy took a bit of thought
		//
		$createdPagesReal[0] = $rootPage;
		//so foreach pages
		foreach($pages as $pageLite){
			$ct = $collectionTypesForWordpress[$pageLite->getPostType()];


			//create the pages
			//right now i am only handling posts and pages, we have to ignore attachments as they are posted elsewhere or referenced in posts or pages
			if(is_a($ct,CollectionType)){
				$createdPagesReal[$pageLite->getPostID()] =  $this->addWordpressPage($rootPage, $ct, $pageLite);
				//here's how we map our pages to pages
				$parentIDPageLiteRel[$pageLite->getWpParentID()][] = $pageLite->getPostID();
			}else{
				//this is kind of spooky and frustrating to see.
				$errors[] = t("Un-supported post type for post - ").$pageLite->getTitle();
			}
		}
		//so right here basically all we do is move the kid page right under the parent page. what is cool about concrete5 is that you don't need any sort of
		//order or anything, pages can be added under pages and it is all just figured out here rather elegantly
		foreach($parentIDPageLiteRel as $parentID => $kids){
			if(is_array($kids) && count($kids)){
				//move our pages to whatever is specified
				foreach($kids as $pageThatNeedsMoved){
					if(intval($createdPagesReal[$parentID])) {
						$createdPagesReal[$pageThatNeedsMoved]->move($createdPagesReal[$parentID]);
					}
				}
			}
		}
		$this->set('message',t('Wordpress Export Imported under ').$rootPage->getCollectionName());
		$this->set('errors',$errors);
	}
	
	
	// this function takes a page as an arguement, the collection type and a page-lite object.
	function addWordpressPage(Page $p, CollectionType $ct, PageLite $pl){
/*		echo $pl->getPostdate();
		exit;
		*/
		$pageData = array('cName' => $pl->getTitle(),'cDatePublic'=>$pl->getPostdate(),'cDateAdded'=>$pl->getPostdate(),'cDescription' => $pl->getExcerpt());
		$newPage = $p->add($ct,$pageData);
		Loader::model('block_types');
		$bt = BlockType::getByHandle('content');
		$data = array();

		$data['content'] = ($this->importImages) ? $this->determineImportableMediaFromContent($pl->getContent(),$pageData) : $pl->getContent(); //we're either importing images or not
		$newPage->addBlock($bt, "Main", $data);
		return $newPage;
	}

	
	function determineImportableMediaFromContent($content,$pageData){
		/*
		After looking at how wordpress actually does this I was completely wrong.  This is actually working ok but could probably use some sprucing up.
		*/
		
		//TODO: continually revisit this regex;
		$pattern = '/<a href="([^"]*)"><img.*(?:title="([^"]*)")? src="([^"]*)".*\/><\/a>/';
		$matches = array();
		if(preg_match_all($pattern,$content,$matches)){
			Loader::library('wordpress_file_post_importer','wordpress_site_importer');
			Loader::model('file');
			//get how many potential file matches we have here
			//match all fills an array so we iternate node 0 which is the match then get use that node as a key to access the rest
			$count = 0;
			$matchedFiles = array();
			foreach($matches[0] as $key => $value){
				$matchesFiles[$key] = array('thumb' => $matches[3][$key], 'main'=> $matches[1][$key],'fullMatch'=>$matches[0][$key]);
				//print_r matches if you need to see how it works
			}
			foreach($matchesFiles as $mfers){  //at this point the variable name made sense
				$tBase = basename($mfers['thumb']);
				$mBase  = basename($mfers['main']);

				if(array_key_exists($tBase,$this->createdFiles) && is_a($this->createdFiles[$tBase],'FileVersion')){
					$thumbFile = $this->createdFiles[$tBase];
				}else{
					$thumbFile = WordpressFileImporter::importFile($mfers['thumb']);
					$this->createdFiles[$tBase] = $thumbFile;
				}
				if(array_key_exists($mBase,$this->createdFiles) && is_a($this->createdFiles[$mBase],'FileVersion')){
					$fullFile = $this->createdFiles[$mBase];
				}else{
					$fullFile = WordpressFileImporter::importFile($mfers['main']);
					$this->createdFiles[$mBase] = $fullFile;
				}
				if($thumbFile instanceof FileVersion && $fullFile instanceof FileVersion){
					$this->fileset->addFileToSet($thumbFile);
					$this->fileset->addFileToSet($fullFile);
					$thumbID = $thumbFile->getFileID();
					$mainID = $fullFile->getFileID();
					$replacement = '<a href="{CCM:FID_'.$mainID.'}"><img src="{CCM:FID_'.$thumbID.'}" alt="'.$fullFile->getTitle().'" title="'.$fullFile->getTitle().'" /></a>';
					//replace the matched one with what we want.
					$content = str_replace($mfers['fullMatch'],$replacement,$content);
				}
			}
		}

		return $content;
	}
}
?>
