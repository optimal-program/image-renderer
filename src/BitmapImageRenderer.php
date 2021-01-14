<?php declare(strict_types=1);

namespace Optimal\ImageRenderer;

use Nette\Application\UI;
use Nette\Caching\Cache;

use Optimal\FileManaging\Exception\DirectoryException;
use Optimal\FileManaging\Exception\FileNotFoundException;
use Optimal\FileManaging\FileCommander;
use Optimal\FileManaging\ImagesManager;

use Optimal\FileManaging\resources\BitmapImageFileResource;
use Optimal\FileManaging\Utils\ImageResolutionSettings;
use Optimal\FileManaging\Utils\ImageResolutionsSettings;

class BitmapImageRenderer extends UI\Control
{

    /** @var UI\ITemplateFactory */
    private $templateFactory;

    /** @var FileCommander */
    private $imageDirectoryCommander;

    /** @var FileCommander */
    private $imageCacheDirCommander;

    /** @var ImagesManager */
    private $imagesManager;

    /** @var Cache */
    private $cache;

    /** @var ImageResolutionsSettings */
    protected $resolutionSizes = null;

    /** @var ImageResolutionsSettings */
    protected $thumbResolutionSizes = null;

    /** @var ?bool */
    protected $defaultLazyLoad = null;

    /** @var string */
    protected $defaultThumbSizes = '';

    /** @var string */
    protected $defaultSizes = '';

    protected $preferredExtension = null;

    public function __construct(UI\ITemplateFactory $templateFactory, Cache $cache)
    {
        $this->templateFactory = $templateFactory;
        $this->imageDirectoryCommander = new FileCommander();
        $this->imagesManager = new ImagesManager();
        $this->cache = $cache;
    }

    /**
     * @param string $directory
     * @throws \Optimal\FileManaging\Exception\DirectoryNotFoundException
     */
    public function setImagesVariantsCacheDirectory(string $directory){
        $this->imageCacheDirCommander = new FileCommander();
        $this->imageCacheDirCommander->setPath($directory);
    }

    /**
     * @param ImageResolutionsSettings $sizes
     */
    public function setImageVariantsResolutions(ImageResolutionsSettings $sizes){
        $this->resolutionSizes = $sizes;
    }

    /**
     * @param ImageResolutionsSettings $sizes
     */
    public function setImageThumbVariantsResolutions(ImageResolutionsSettings $sizes){
        $this->thumbResolutionSizes = $sizes;
    }

    /**
     * @param bool $defaultLazyLoad
     */
    public function setDefaultLazyLoad(bool $defaultLazyLoad): void
    {
        $this->defaultLazyLoad = $defaultLazyLoad;
    }

    /**
     * @param string $defaultSizes
     */
    public function setDefaultSizes(string $defaultSizes): void
    {
        $this->defaultSizes = $defaultSizes;
    }

    /**
     * @param string $defaultThumbSizes
     */
    public function setDefaultThumbSizes(string $defaultThumbSizes): void
    {
        $this->defaultThumbSizes = $defaultThumbSizes;
    }

    /**
     * @param string $preferredExtension
     */
    public function setPreferredExtension(string $preferredExtension): void
    {
        $this->preferredExtension = $preferredExtension;
    }

    protected function lcs2($first, $second)
    {
        $len1 = strlen($first);
        $len2 = strlen($second);

        if ($len1 < $len2) {
            $shortest = $first;
            $longest = $second;
            $len_shortest = $len1;
        } else {
            $shortest = $second;
            $longest = $first;
            $len_shortest = $len2;
        }

        //check max len
        $pos = strpos($longest, $shortest);
        if($pos !== false) return $shortest;

        for ($i = 1, $j = $len_shortest - 1; $j > 0; --$j, ++$i) {
            for($k = 0; $k <= $i; ++$k){
                $substr = substr($shortest, $k, $j);
                $pos = strpos($longest, $substr);
                if($pos !== false) return $substr;
            }
        }

        return "";
    }

    /**
     * @param string $imagePath
     * @param string $cacheDirPath
     */
    protected function checkImagesCachePath(string $imagePath, string $cacheDirPath)
    {
        $commonPart = $this->lcs2($imagePath, $cacheDirPath);
        $imageDirWithoutCommonPart = str_replace($commonPart, "", $imagePath);

        if($this->imageCacheDirCommander->directoryExists($this->imageCacheDirCommander->getAbsolutePath()."/".$imageDirWithoutCommonPart)){
            $this->imageCacheDirCommander->setPath($this->imageCacheDirCommander->getAbsolutePath()."/".$imageDirWithoutCommonPart);
        } else {
            $pathParts = explode("/", $imageDirWithoutCommonPart);
            foreach ($pathParts as $pathPart) {
                $this->imageCacheDirCommander->addDirectory($pathPart, true);
            }
        }
    }

    /**
     * @param BitmapImageFileResource $image
     * @param string $destinationPath
     * @param string $newName
     * @param string $extension
     * @param int|null $width
     * @param int|null $height
     * @return mixed
     */
    protected function createImageSize(BitmapImageFileResource $image, string $destinationPath, string $newName, string $extension, ?int $width = null, ?int $height = null)
    {

        if (!$this->imageCacheDirCommander->fileExists($newName, $extension)) {

            $this->imagesManager->setOutputDirectory($destinationPath);

            $imageManageResource = $this->imagesManager->loadImageManageResource($image->getName(), $image->getExtension());

            $imageManageResource->resize($width ? $width : 0, $height ? $height : 0);
            $imageManageResource->getSourceImageResource()->setNewName($newName);
            $imageManageResource->save(null, $extension);

            return $imageManageResource->getOutputImageResource();

        } else {
            return $this->imageCacheDirCommander->getImage($newName, $extension);
        }

    }

    /**
     * @param string $imagePath
     * @param bool $fileIsChanged
     * @return array
     * @throws DirectoryException
     * @throws FileNotFoundException
     * @throws \ImagickException
     * @throws \Optimal\FileManaging\Exception\CreateDirectoryException
     * @throws \Optimal\FileManaging\Exception\DirectoryNotFoundException
     * @throws \Optimal\FileManaging\Exception\FileException
     * @throws \Optimal\FileManaging\Exception\GDException
     */
    public function createImageVariants(string $imagePath, bool $fileIsChanged)
    {

        $image = new BitmapImageFileResource($imagePath);
        $this->imageDirectoryCommander->setPath($image->getFileDirectoryPath());

        $imageVariants = [];

        $imageName = $image->getName();
        $this->imagesManager->setSourceDirectory($image->getFileDirectoryPath());

        $cacheDirPath = $this->imageCacheDirCommander->getAbsolutePath();
        $imagePath = $this->imageDirectoryCommander->getAbsolutePath();

        $this->checkImagesCachePath($imagePath, $cacheDirPath);

        if ($this->resolutionSizes != null) {

            if (!$this->imageCacheDirCommander) {
                throw new DirectoryException("Images variants cache directory is not defined");
            }

            $this->imageCacheDirCommander->addDirectory($image->getName(), true);
            $this->imageCacheDirCommander->addDirectory('image_variants', true);

            if($fileIsChanged){
                $this->imageCacheDirCommander->clearDir();
            }

            $this->imageCacheDirCommander->addDirectory($this->preferredExtension, true);

            /** @var ImageResolutionSettings $resolutionSize */
            foreach ($this->resolutionSizes->getResolutionsSettings() as $resolutionSize) {

                $width = $resolutionSize->getWidth();
                $height = $resolutionSize->getHeight();

                if($width && $height){
                    if (($width > $image->getWidth()) && ($height > $image->getHeight())) continue;
                } elseif ($height){
                    if($height > $image->getHeight()) continue;
                } elseif ($width > $image->getWidth()) {
                    continue;
                }

                foreach ($resolutionSize->getExtensions() as $extension) {

                    if($extension == "default"){
                        $extension = $image->getExtension();
                    }

                    if($extension == $this->preferredExtension) {
                        $newName = $imageName . (($width > 0) ? '-w' . $width : '') . (($height > 0) ? '-h' . $height : '');

                        /** @var BitmapImageFileResource $imageVariant */
                        $imageVariant = $this->createImageSize($image, $this->imageCacheDirCommander->getAbsolutePath(), $newName, $extension, $width, $height);
                        array_push($imageVariants, $imageVariant);
                    }

                }

            }

            if(empty($imageVariants)){
                $newName = $imageName . (($image->getWidth() > 0) ? '-w' . $image->getWidth() : '') . (($image->getHeight() > 0) ? '-h' . $image->getHeight() : '');
                copy($image->getFilePath(), $this->imageCacheDirCommander->getAbsolutePath()."/".$newName.".".$image->getExtension());
                array_push($imageVariants, $this->imageCacheDirCommander->getImage($newName, $image->getExtension(), false, false));
            }

            $this->imageCacheDirCommander->moveUp();

        }

        $this->imageCacheDirCommander->setPath($cacheDirPath);

        return $imageVariants;
    }

    /**
     * @param string $imageThumbPath
     * @param bool $fileIsChanged
     * @return array
     * @throws DirectoryException
     * @throws FileNotFoundException
     * @throws \ImagickException
     * @throws \Optimal\FileManaging\Exception\CreateDirectoryException
     * @throws \Optimal\FileManaging\Exception\DirectoryNotFoundException
     * @throws \Optimal\FileManaging\Exception\FileException
     * @throws \Optimal\FileManaging\Exception\GDException
     */
    public function createImageThumbVariants(string $imageThumbPath, bool $fileIsChanged)
    {
        $image = new BitmapImageFileResource($imageThumbPath);
        $this->imageDirectoryCommander->setPath($image->getFileDirectoryPath());

        $imageThumbsVariants = [];

        $imageName = $image->getName();
        $this->imagesManager->setSourceDirectory($image->getFileDirectoryPath());

        $cacheDirPath = $this->imageCacheDirCommander->getAbsolutePath();
        $imageThumbPath = $this->imageDirectoryCommander->getAbsolutePath();

        $this->checkImagesCachePath($imageThumbPath, $cacheDirPath);

        if ($this->thumbResolutionSizes != null) {

            if (!$this->imageCacheDirCommander) {
                throw new DirectoryException("Images variants cache directory is not defined");
            }

            $this->imageCacheDirCommander->addDirectory($image->getName(), true);
            $this->imageCacheDirCommander->addDirectory('thumbs', true);

            if($fileIsChanged){
                $this->imageCacheDirCommander->clearDir();
            }

            $this->imageCacheDirCommander->addDirectory($this->preferredExtension, true);

            /** @var ImageResolutionSettings $resolutionSize */
            foreach ($this->thumbResolutionSizes->getResolutionsSettings() as $resolutionSize) {

                $width = $resolutionSize->getWidth();
                $height = $resolutionSize->getHeight();

                if($width && $height){
                    if (($width > $image->getWidth()) && ($height > $image->getHeight())) continue;
                } elseif ($height){
                    if($height > $image->getHeight()) continue;
                } elseif ($width > $image->getWidth()) {
                    continue;
                }

                foreach ($resolutionSize->getExtensions() as $extension) {

                    if($extension == "default"){
                        $extension = $image->getExtension();
                    }

                    if ($extension == $this->preferredExtension) {

                        $newName = $imageName . '-thumb' . (($width > 0) ? '-w' . $width : '') . (($height > 0) ? '-h' . $height : '');

                        /** @var BitmapImageFileResource $imageVariant */
                        $imageVariant = $this->createImageSize($image, $this->imageCacheDirCommander->getAbsolutePath(), $newName, $extension, $width, $height);

                        array_push($imageThumbsVariants, $imageVariant);
                    }
                }

            }

            if(empty($imageThumbsVariants)){
                $newName = $imageName .'-thumb'. (($image->getWidth() > 0) ? '-w' . $image->getWidth() : '') . (($image->getHeight() > 0) ? '-h' . $image->getHeight() : '');
                copy($image->getFilePath(), $this->imageCacheDirCommander->getAbsolutePath()."/".$newName.".".$image->getExtension());
                array_push($imageThumbsVariants, $this->imageCacheDirCommander->getImage($newName, $image->getExtension(), false, false));
            }

        }

        $this->imageCacheDirCommander->setPath($cacheDirPath);

        return $imageThumbsVariants;
    }

    /**
     * @param string $imagePath
     * @param string $imageThumbPath
     * @return array
     * @throws DirectoryException
     * @throws FileNotFoundException
     * @throws \ImagickException
     * @throws \Optimal\FileManaging\Exception\CreateDirectoryException
     * @throws \Optimal\FileManaging\Exception\DirectoryNotFoundException
     * @throws \Optimal\FileManaging\Exception\FileException
     * @throws \Optimal\FileManaging\Exception\GDException
     */
    public function createImageAndThumbResolutionsVariants(string $imagePath, string $imageThumbPath)
    {

        if(!$this->imageCacheDirCommander){
            throw new \Exception('Images variants cache directory is not set');
        }

        if ($this->resolutionSizes == null && $this->thumbResolutionSizes == null) {
            throw new \Exception('No image resolutions defined');
        }

        $imageVariants = $this->createImageVariants($imagePath);
        $imageThumbsVariants = $this->createImageThumbVariants($imageThumbPath);

        return ['variants' => $imageVariants, 'thumb_variants' => $imageThumbsVariants];
    }

    /**
     * @param array $variants
     * @return string
     */
    protected function prepareSrcSet(array $variants)
    {
        $template = $this->templateFactory->createTemplate();
        $template->variants = $variants;
        $template->setFile(__DIR__ . '/templates/srcset.latte');
        return trim(preg_replace('/\s\s+/', ' ', $template));
    }

    /**
     * @param array $classes
     * @return string
     */
    protected function prepareClass(array $classes)
    {
        $template = $this->templateFactory->createTemplate();
        $template->classes = $classes;
        $template->setFile(__DIR__ . '/templates/class.latte');
        return trim(preg_replace('/\s\s+/', ' ', $template));
    }

    /**
     * @param BitmapImageFileResource[] $imageData
     * @param string $alt
     * @param string $devicesSizes
     * @param bool|null $lazyLoad
     * @param array $attributes
     * @return string
     */
    protected function renderImgTag(array $imageData, string $alt, string $devicesSizes, ?bool $lazyLoad, array $attributes = [])
    {
        $template = $this->templateFactory->createTemplate();
        $template->src = $imageData[0]->getFileRelativePath();
        $template->alt = $alt;
        $template->srcset = $this->prepareSrcSet($imageData);

        $classes = [];

        if($lazyLoad != null) {
            if ($lazyLoad) {
                array_push($classes, 'lazy-image');
            }
        }

        if(isset($attributes["class"])){
            array_push($classes, $attributes["class"]);
            unset($attributes["class"]);
        }

        $template->class = $this->prepareClass($classes);
        $template->sizes = $devicesSizes;
        $template->attributes = $attributes;
        $template->lazyLoad = $lazyLoad;

        $template->setFile(__DIR__ . '/templates/imgtag.latte');
        return trim(preg_replace('/\s\s+/', ' ', $template));
    }

    /**
     * @param ImageResolutionsSettings $resolutions
     * @return string
     */
    protected function serializeResolutionSizes(ImageResolutionsSettings $resolutions)
    {
        $arr = [];
        foreach ($resolutions as $resolutionSize) {
            $arr[] = 'w'.$resolutionSize->getWidth().",h".$resolutionSize->getHeight();
        }
        return join(';', $arr);
    }

    protected function checkThumbDefaultParams(?bool $lazyLoad = null, string $devicesSizes = "")
    {

        if($lazyLoad == null && $this->defaultLazyLoad != null){
            $lazyLoad = $this->defaultLazyLoad;
        }

        if(empty($devicesSizes) && !empty($this->defaultThumbSizes)){
            $devicesSizes = $this->defaultThumbSizes;
        }

        return [$lazyLoad, $devicesSizes];
    }

    protected function checkDefaultParams(?bool $lazyLoad = null, string $devicesSizes = "")
    {

        if($lazyLoad == null && $this->defaultLazyLoad != null){
            $lazyLoad = $this->defaultLazyLoad;
        }

        if(empty($devicesSizes) && !empty($this->defaultSizes)){
            $devicesSizes = $this->defaultSizes;
        }

        return [$lazyLoad, $devicesSizes];
    }

    /**
     * @param string $imageThumbPath
     * @param string $alt
     * @param bool|null $lazyLoad
     * @param string $devicesSizes
     * @param array $attributes
     * @return string
     * @throws \ImagickException
     */
    protected function prepareImageThumb(string $imageThumbPath, string $alt, ?bool $lazyLoad = null, string $devicesSizes = "", array $attributes = [])
    {
        if(!$this->imageCacheDirCommander){
            throw new \Exception('Images variants cache directory is not set');
        }

        if ($this->thumbResolutionSizes == null) {
            throw new \Exception('No image thumb resolutions defined');
        }

        [$lazyLoad, $devicesSizes] = $this->checkThumbDefaultParams($lazyLoad, $devicesSizes);

        $key = md5($imageThumbPath.$this->serializeResolutionSizes($this->thumbResolutionSizes).$alt.$devicesSizes.$lazyLoad.join(';',$attributes).$this->preferredExtension);
        $data = $this->cache->load($key);

        $fileHash = sha1(file_get_contents($imageThumbPath));
        $lastTime = filectime($imageThumbPath);
        $fileChanged = !$data ? false : $data['lastHash'] != $fileHash || $data['lastModifiedTime'] != $lastTime;

        if(!$data || $fileChanged){

            $imageData = $this->createImageThumbVariants($imageThumbPath, $fileChanged);
            $imgTag = $this->renderImgTag($imageData, $alt, $devicesSizes, $lazyLoad, $attributes);

            $data = [
                'img' => $imgTag,
                'lastHash' => $fileHash,
                'lastModifiedTime' => $lastTime
            ];

            $this->cache->save($key, $data, [
                Cache::EXPIRE => '12 months',
                Cache::SLIDING => true,
            ]);

        }

        return $data['img'];
    }

    /**
     * @param string $imageThumbPath
     * @throws \ImagickException
     * @throws \Exception
     */
    public function renderImageThumbSrcSet(string $imageThumbPath)
    {
        if(!$this->preferredExtension){
            throw new \Exception('Preferred image extension is required');
        }

        $key = md5($imageThumbPath.file_get_contents($imageThumbPath).$this->preferredExtension);

        $data = $this->cache->load($key);

        $fileHash = sha1(file_get_contents($imageThumbPath));
        $lastTime = filectime($imageThumbPath);
        $fileChanged = !$data ? false : $data['lastHash'] != $fileHash || $data['lastModifiedTime'] != $lastTime;

        if(!$data || $fileChanged) {
            $imageData = $this->createImageThumbVariants($imageThumbPath, $fileChanged);
            $srcSet = $this->prepareSrcSet($imageData);

            $data = [
                'srcSet' => $srcSet,
                'lastHash' => $fileHash,
                'lastModifiedTime' => $lastTime
            ];

            $this->cache->save($key, $data, [
                Cache::EXPIRE => '12 months',
                Cache::SLIDING => true,
            ]);
        }

        echo $data['srcSet'];
    }

    /**
     * @param string $imageThumbPath
     * @return string
     * @throws \ImagickException
     */
    public function getImageThumbSrcSet(string $imageThumbPath)
    {
        ob_start();
        $this->renderImageThumbSrcSet($imageThumbPath);
        return ob_get_contents();
    }

    public function renderDefaultThumbSizes()
    {
        echo $this->defaultThumbSizes;
    }

    /**
     * @return string
     */
    public function getDefaultThumbSizes()
    {
        return $this->defaultThumbSizes;
    }

    /**
     * @param string $imageThumbPath
     * @param string $alt
     * @param bool|null $lazyLoad
     * @param string $devicesSizes
     * @param string|null $caption
     * @param array $attributes
     * @throws \ImagickException
     */
    public function renderImageThumb(string $imageThumbPath, string $alt, ?bool $lazyLoad = null, string $devicesSizes = "", string $caption = null, array $attributes = [])
    {
        if(!$this->preferredExtension){
            throw new \Exception('Preferred image extension is required');
        }

        $this->template->setFile(__DIR__ . '/templates/image.latte');
        $this->template->imgTag = $imgTag = $this->prepareImageThumb($imageThumbPath, $alt, $lazyLoad, $devicesSizes, $attributes);
        $this->template->caption = $caption;

        $this->template->render();
    }

    /**
     * @param string $imageThumbPath
     * @param string $alt
     * @param bool|null $lazyLoad
     * @param string $devicesSizes
     * @param string|null $caption
     * @param array $attributes
     * @return string
     * @throws \ImagickException
     */
    public function renderImageThumbAsString(string $imageThumbPath, string $alt, ?bool $lazyLoad = null, string $devicesSizes = "", string $caption = null, array $attributes = [])
    {
        ob_start();
        $this->renderImageThumb($imageThumbPath, $alt, $lazyLoad, $devicesSizes,$caption, $attributes);
        return ob_get_contents();
    }

    /**
     * @param string $imagePath
     * @param string $alt
     * @param bool|null $lazyLoad
     * @param string $devicesSizes
     * @param array $attributes
     * @return string
     * @throws \ImagickException
     */
    protected function prepareImage(string $imagePath, string $alt, ?bool $lazyLoad = null, string $devicesSizes = "", array $attributes = [])
    {

        if(!$this->imageCacheDirCommander){
            throw new \Exception('Images variants cache directory is not set');
        }

        if ($this->resolutionSizes == null) {
            throw new \Exception('No image resolutions defined');
        }

        [$lazyLoad, $devicesSizes] = $this->checkDefaultParams($lazyLoad, $devicesSizes);

        $key = md5($imagePath.file_get_contents($imagePath).$this->serializeResolutionSizes($this->resolutionSizes).$alt.$devicesSizes.$lazyLoad.join(';',$attributes).$this->preferredExtension);

        $data  = $this->cache->load($key);

        $fileHash = sha1(file_get_contents($imagePath));
        $lastTime = filectime($imagePath);
        $fileChanged = !$data ? false : $data['lastHash'] != $fileHash || $data['lastModifiedTime'] != $lastTime;

        if(!$data || $fileChanged){

            $imageData = $this->createImageVariants($imagePath, $fileChanged);
            $imgTag = $this->renderImgTag($imageData, $alt, $devicesSizes, $lazyLoad, $attributes);

            $data = [
                'img' => $imgTag,
                'lastHash' => $fileHash,
                'lastModifiedTime' => $lastTime
            ];

            $this->cache->save($key, $data, [
                Cache::EXPIRE => '12 months',
                Cache::SLIDING => true,
            ]);
        }

        return $data['img'];
    }

    /**
     * @param string $imagePath
     * @return string
     * @throws \ImagickException
     * @throws \Exception
     */
    public function renderImageSrcSet(string $imagePath)
    {
        if(!$this->preferredExtension){
            throw new \Exception('Preferred image extension is required');
        }

        $key = md5($imagePath.file_get_contents($imagePath).$this->preferredExtension);
        $data = $this->cache->load($key);

        $fileHash = sha1(file_get_contents($imagePath));
        $lastTime = filectime($imagePath);
        $fileChanged = !$data ? false : $data['lastHash'] != $fileHash || $data['lastModifiedTime'] != $lastTime;

        if(!$data || $fileChanged) {

            $imageData = $this->createImageVariants($imagePath, $fileChanged);
            $srcSet = $this->prepareSrcSet($imageData);

            $data = [
                'srcSet' => $srcSet,
                'lastHash' => $fileHash,
                'lastModifiedTime' => $lastTime
            ];

            $this->cache->save($key, $data, [
                Cache::EXPIRE => '12 months',
                Cache::SLIDING => true,
            ]);
        }

        echo $data['srcSet'];
    }

    /**
     * @param string $imagePath
     * @return string
     * @throws \ImagickException
     */
    public function getImageSrcSet(string $imagePath)
    {
        ob_start();
        $this->renderImageSrcSet($imagePath);
        return ob_get_contents();
    }

    public function renderDefaultSizes()
    {
        echo $this->defaultSizes;
    }

    public function getDefaultSizes()
    {
        return $this->defaultSizes;
    }

    /**
     * @param string $imagePath
     * @param string $alt
     * @param bool|null $lazyLoad
     * @param string $devicesSizes
     * @param string|null $caption
     * @param array $attributes
     * @throws \ImagickException
     * @throws \Exception
     */
    public function renderImage(string $imagePath, string $alt, ?bool $lazyLoad = null, string $devicesSizes = "", string $caption = null, array $attributes = [])
    {
        if(!$this->preferredExtension){
            throw new \Exception('Preferred image extension is required');
        }

        $this->template->setFile(__DIR__ . '/templates/image.latte');
        $this->template->imgTag = $imgTag = $this->prepareImage($imagePath, $alt, $lazyLoad, $devicesSizes, $attributes);

        $this->template->lightbox = false;
        $this->template->caption = $caption;

        $this->template->render();
    }

    /**
     * @param string $imagePath
     * @param string $alt
     * @param bool|null $lazyLoad
     * @param string $devicesSizes
     * @param string|null $caption
     * @param array $attributes
     * @return string
     * @throws \ImagickException
     */
    public function renderImageAsString(string $imagePath, string $alt, ?bool $lazyLoad = null, string $devicesSizes = "", string $caption = null, array $attributes = []):string
    {
        ob_start();
        $this->renderImage($imagePath, $alt, $lazyLoad, $devicesSizes, $caption, $attributes);
        return ob_get_contents();
    }

}