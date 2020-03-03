<?php

namespace App\Components\ImageRenderer;

use Nette\Application\UI;
use Optimal\FileManaging\Exception\DirectoryException;
use Optimal\FileManaging\Exception\FileNotFoundException;
use Optimal\FileManaging\FileCommander;
use Optimal\FileManaging\ImagesManager;
use Optimal\FileManaging\resources\ImageFileResource;

use Optimal\FileManaging\Utils\ImageResolutionSettings;
use Optimal\FileManaging\Utils\ImageResolutionsSettings;

class ImageRenderer extends UI\Control
{

    /** @var UI\ITemplateFactory */
    public $templateFactory;

    /** @var FileCommander */
    private $imageDirectoryCommander;

    /** @var FileCommander */
    private $imageCacheDirCommander;

    /** @var ImagesManager */
    private $imagesManager;

    /** @var ImageResolutionsSettings */
    protected $resolutionSizes = null;

    /** @var ImageResolutionsSettings */
    protected $thumbResolutionSizes = null;

    public function __construct(UI\ITemplateFactory $templateFactory)
    {
        $this->templateFactory = $templateFactory;
        $this->imageDirectoryCommander = new FileCommander();
        $this->imagesManager = new ImagesManager();
    }

    /**
     * @param string $directory
     * @throws Exception\DirectoryNotFoundException
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
     * @param ImageFileResource $image
     * @param string $destinationPath
     * @param string $newName
     * @param string $extension
     * @param int $width
     * @param int $height
     * @return ImageFileResource
     * @throws FileNotFoundException
     * @throws \ImagickException
     * @throws \Optimal\FileManaging\Exception\DirectoryNotFoundException
     * @throws \Optimal\FileManaging\Exception\FileException
     * @throws \Optimal\FileManaging\Exception\GDException
     */
    protected function createImageSize(ImageFileResource $image, string $destinationPath, string $newName, string $extension, int $width = 0, int $height = 0){

        if (!$this->imageDirectoryCommander->fileExists($newName, $extension))
        {
            $this->imagesManager->setOutputDirectory($destinationPath);

            $imageManageResource = $this->imagesManager->loadImageManageResource($image->getName(), $image->getExtension());

            $imageManageResource->resize($width, $height);
            $imageManageResource->getSourceImageResource()->setNewName($newName);
            $imageManageResource->save(null, $extension);

            $thumbResource = $imageManageResource->getOutputImageResource();

            return $thumbResource;
        } else {
            $thumbResource = $this->imageDirectoryCommander->getImage($newName, $extension);
            return $thumbResource;
        }

    }

    /**
     * @param string $imagePath
     * @param array $sizes
     * @param array $thumbsSizes
     * @return array
     * @throws FileNotFoundException
     * @throws \ImagickException
     * @throws \Optimal\FileManaging\Exception\CreateDirectoryException
     * @throws \Optimal\FileManaging\Exception\DirectoryNotFoundException
     * @throws \Optimal\FileManaging\Exception\FileException
     * @throws \Optimal\FileManaging\Exception\GDException
     */
    protected function checkImage(string $imagePath){

        $image = new ImageFileResource($imagePath);
        $this->imageDirectoryCommander->setPath($image->getFileDirectoryPath());

        $imageVariants = [];
        $imageThumbsVariants = [];

        $imageName = $image->getName();
        $this->imagesManager->setSourceDirectory($image->getFileDirectoryPath());

        if($this->imageCacheDirCommander) {
            $cacheDirPath = $this->imageCacheDirCommander->getRelativePath();
            $pathParts = explode("/", $this->imageDirectoryCommander->getRelativePath());
            foreach ($pathParts as $pathPart) {
                $this->imageCacheDirCommander->addDirectory($pathPart, true);
            }
        }

        if($this->resolutionSizes != null){

            if(!$this->imageCacheDirCommander){
                throw new DirectoryException("Images variants cache directory is not defined");
            }

            $this->imageCacheDirCommander->addDirectory('image_variants', true);
            $this->imageCacheDirCommander->addDirectory($image->getName(), true);

            /** @var ImageResolutionSettings $resolutionSize */
            foreach ($this->resolutionSizes->getResolutionsSettings() as $resolutionSize) {

                $width = $resolutionSize->getWidth();
                $height = $resolutionSize->getHeight();

                if(($width > $image->getWidth()) || ($height > $image->getHeight())) continue;

                $newName = $imageName . (($width > 0) ? '-w' . $width : '') . (($height > 0) ? '-h' . $height : '');
                $extension = $resolutionSize->getExtension() == "default" ? $image->getExtension() : $resolutionSize->getExtension();
                array_push($imageVariants, $this->createImageSize($image,$this->imageCacheDirCommander->getRelativePath(),$newName,$extension,$width,$height));

                $this->imageCacheDirCommander->moveUp();
            }

        }

        if($this->thumbResolutionSizes != null) {

            if(!$this->imageCacheDirCommander){
                throw new DirectoryException("Images variants cache directory is not defined");
            }

            $this->imageDirectoryCommander->addDirectory('thumb_variants', true);
            $this->imageDirectoryCommander->addDirectory($image->getName(), true);

            /** @var ImageResolutionSettings $resolutionSize */
            foreach ($this->thumbResolutionSizes->getResolutionsSettings() as $resolutionSize) {

                $width = $resolutionSize->getWidth();
                $height = $resolutionSize->getHeight();

                if(($width > $image->getWidth()) || ($height > $image->getHeight())) continue;

                $newName = $imageName.'-thumb-'.(($width > 0) ? '-w' . $width : '') . (($height > 0) ? '-h' . $height : '');
                $extension = $resolutionSize->getExtension() == "default" ? $image->getExtension() : $resolutionSize->getExtension();
                array_push($imageThumbsVariants, $this->createImageSize($image, $this->imageCacheDirCommander->getRelativePath(), $newName, $extension, $width, $height));

                $this->imageCacheDirCommander->moveUp();
            }
        }

        if($this->imageCacheDirCommander) {
            $this->imageCacheDirCommander->setPath($cacheDirPath);
        }

        if($this->resolutionSizes == null && $this->thumbResolutionSizes == null){
            throw new \Exception('No image resolutions defined');
        }

        return ['variants' => $imageVariants, 'thumb_variants' => $imageThumbsVariants];
    }

    protected function prepareSrcSet(array $variants)
    {
        $template = $this->templateFactory->createTemplate();
        $template->variants = $variants;
        $template->setFile(__DIR__ . '/templates/srcset.latte');
        return trim(preg_replace('/\s\s+/', ' ', $template));
    }

    protected function prepareClass(array $classes)
    {
        $template = $this->templateFactory->createTemplate();
        $template->classes = $classes;
        $template->setFile(__DIR__ . '/templates/class.latte');
        return trim(preg_replace('/\s\s+/', ' ', $template));
    }

    /**
     * @param ImageFileResource[] $imageData
     * @param string $alt
     * @param string $devicesSizes
     * @param bool $lazyLoad
     * @param array $attributes
     * @return string
     */
    protected function renderImgTag(array $imageData, string $alt, string $devicesSizes, bool $lazyLoad = false, array $attributes = [])
    {
        $template = $this->templateFactory->createTemplate();
        $template->src = $imageData[0]->getFileRelativePath();
        $template->alt = $alt;
        $template->srcset = $this->prepareSrcSet($imageData);

        $classes = [];

        if($lazyLoad){
            array_push($classes, 'lazy-image');
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

    public function renderImageThumb(string $imagePath, string $alt, string $devicesSizes, bool $lazyLoad = false, array $attributes = [])
    {
        $imageData = $this->checkImage($imagePath);
        $this->template->setFile(__DIR__ . '/templates/image.latte');
        $this->template->imgTag = $this->renderImgTag($imageData["thumb_variants"], $alt, $devicesSizes, $lazyLoad, $attributes);
        if($this->presenter->isAjax()){
            return (string) $this->template;
        } else {
            $this->template->render();
            return null;
        }
    }

    public function renderImage(string $imagePath, string $alt, string $devicesSizes, bool $lazyLoad = false, array $attributes = [])
    {
        $imageData = $this->checkImage($imagePath);
        $this->template->setFile(__DIR__ . '/templates/image.latte');
        $this->template->imgTag = $this->renderImgTag($imageData["variants"], $alt, $devicesSizes, $lazyLoad, $attributes);
        if($this->presenter->isAjax()){
            return (string) $this->template;
        } else {
            $this->template->render();
            return null;
        }
    }

    public function renderImageThumbWithCaption(string $imagePath, string $alt, string $devicesSizes, string $caption, bool $lazyLoad = false, array $attributes = [])
    {
        $imageData = $this->checkImage($imagePath);
        $this->template->setFile(__DIR__ . '/templates/image.latte');
        $this->template->imgTag = $this->renderImgTag($imageData["thumb_variants"], $alt, $devicesSizes, $lazyLoad, $attributes);
        $this->template->caption = $caption;
        if($this->presenter->isAjax()){
            return (string) $this->template;
        } else {
            $this->template->render();
            return null;
        }
    }

    public function renderImageWithCaption(string $imagePath, string $alt, string $devicesSizes, string $caption, bool $lazyLoad = false, array $attributes = [])
    {
        $imageData = $this->checkImage($imagePath);
        $this->template->setFile(__DIR__ . '/templates/image.latte');
        $this->template->imgTag = $this->renderImgTag($imageData["variants"], $alt, $devicesSizes, $lazyLoad, $attributes);
        $this->template->caption = $caption;
        if($this->presenter->isAjax()){
            return (string) $this->template;
        } else {
            $this->template->render();
            return null;
        }
    }

}