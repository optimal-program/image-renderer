<?php declare(strict_types=1);

namespace Optimal\ImageRenderer;

use Nette\Application\UI;
use Nette\Caching\Cache;

use Nette\Caching\Storages\FileStorage;
use Optimal\FileManaging\Exception\DirectoryException;
use Optimal\FileManaging\Exception\FileNotFoundException;
use Optimal\FileManaging\FileCommander;
use Optimal\FileManaging\ImagesManager;

use Optimal\FileManaging\resources\AbstractImageFileResource;
use Optimal\FileManaging\resources\BitmapImageFileResource;
use Optimal\FileManaging\Utils\FilesTypes;
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

    private $extensionsMap = [
        'jpg' => ['webp', 'jpg'],
        'jpeg' => ['webp', 'jpg'],
        'jfif' => ['webp', 'jpg'],
        'png' => ['webp', 'png'],
        'gif' => ['gif'],
        'bmp' => ['bmp']
    ];

    /** @var ImageResolutionsSettings */
    protected $resolutionSizes;

    /** @var ImageResolutionsSettings */
    protected $thumbResolutionSizes;

    /** @var bool */
    protected $defaultLazyLoad = false;

    /** @var string */
    protected $defaultThumbSizes = '';

    /** @var string */
    protected $defaultSizes = '';

    /** @var bool */
    protected static $isWebPSupported = false;

    /** @var int */
    protected $createVariantsBottomLimit = 500;

    /** @var string */
    protected $noImagePath;

    /**
     * BitmapImageRenderer constructor.
     * @param UI\ITemplateFactory $templateFactory
     */
    public function __construct(UI\ITemplateFactory $templateFactory)
    {
        $this->templateFactory = $templateFactory;
        $this->imageDirectoryCommander = new FileCommander();
        $this->imagesManager = new ImagesManager();

        $cacheDir = '../temp/cache/images';

        if (!file_exists($cacheDir)) {
            if (!mkdir($cacheDir) && !is_dir($cacheDir)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $cacheDir));
            }
        }

        $storage = new FileStorage($cacheDir);
        $this->cache = new Cache($storage);
    }

    public static function checkWebPSupport(): void
    {

        if (!isset($_COOKIE['webp-support'])) {
            $isWebpSupported = isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'image/webp') !== false;
            setcookie('webp-support', (string)$isWebpSupported, time() + 60 * 60 * 24);
        }
        else {
            $isWebpSupported = (bool)$_COOKIE['webp-support'];
        }

        self::$isWebPSupported = $isWebpSupported;
    }

    /**
     * @return bool
     */
    public function isWebPSupported(): bool
    {
        return self::$isWebPSupported;
    }

    /**
     * @param string $directory
     * @throws \Optimal\FileManaging\Exception\DirectoryNotFoundException
     */
    public function setImagesVariantsCacheDirectory(string $directory): void
    {
        $this->imageCacheDirCommander = new FileCommander();
        $this->imageCacheDirCommander->setPath($directory);
    }

    /**
     * @param ImageResolutionsSettings $sizes
     */
    public function setImageVariantsResolutions(ImageResolutionsSettings $sizes): void
    {
        $this->resolutionSizes = $sizes;
    }

    /**
     * @param ImageResolutionsSettings $sizes
     */
    public function setImageThumbVariantsResolutions(ImageResolutionsSettings $sizes): void
    {
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
     * @param int $createVariantsBottomLimit
     */
    public function setCreateVariantsBottomLimit(int $createVariantsBottomLimit): void
    {
        $this->createVariantsBottomLimit = $createVariantsBottomLimit;
    }

    /**
     * @param string $noImagePath
     */
    public function setNoImagePath(string $noImagePath): void
    {
        $this->noImagePath = $noImagePath;
    }

    /**
     * @param string|null $imagePath
     * @return string
     * @throws \Exception
     */
    protected function checkImage(?string $imagePath):string
    {
        if (!is_null($imagePath) && file_exists($imagePath) && @getimagesize($imagePath)) {
            return $imagePath;
        }

        if(is_null($this->noImagePath)){
            throw new \Exception('No image is not set.');
        }

        return $this->noImagePath;
    }

    /**
     * @param $first
     * @param $second
     * @return string
     */
    protected function lcs2($first, $second): string
    {
        $len1 = strlen($first);
        $len2 = strlen($second);

        if ($len1 < $len2) {
            $shortest = $first;
            $longest = $second;
            $len_shortest = $len1;
        }
        else {
            $shortest = $second;
            $longest = $first;
            $len_shortest = $len2;
        }

        //check max len
        $pos = strpos($longest, $shortest);
        if ($pos !== false) {
            return $shortest;
        }

        for ($i = 1, $j = $len_shortest - 1; $j > 0; --$j, ++$i) {
            for ($k = 0; $k <= $i; ++$k) {
                $substr = substr($shortest, $k, $j);
                $pos = strpos($longest, $substr);
                if ($pos !== false) {
                    return $substr;
                }
            }
        }

        return "";
    }

    /**
     * @param string $imagePath
     * @param string $cacheDirPath
     * @throws \Optimal\FileManaging\Exception\CreateDirectoryException
     * @throws \Optimal\FileManaging\Exception\DirectoryNotFoundException
     */
    protected function checkImagesCachePath(string $imagePath, string $cacheDirPath): void
    {
        $commonPart = $this->lcs2($imagePath, $cacheDirPath);
        $imageDirWithoutCommonPart = str_replace($commonPart, "", $imagePath);

        if ($this->imageCacheDirCommander->directoryExists($this->imageCacheDirCommander->getAbsolutePath() . "/" . $imageDirWithoutCommonPart)) {
            $this->imageCacheDirCommander->setPath($this->imageCacheDirCommander->getAbsolutePath() . "/" . $imageDirWithoutCommonPart);
        }
        else {
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
     * @return AbstractImageFileResource
     * @throws FileNotFoundException
     * @throws \ImagickException
     * @throws \Optimal\FileManaging\Exception\DirectoryNotFoundException
     * @throws \Optimal\FileManaging\Exception\FileException
     */
    protected function createImageSize(BitmapImageFileResource $image, string $destinationPath, string $newName, string $extension, ?int $width = null, ?int $height = null): AbstractImageFileResource
    {

        if (!$this->imageCacheDirCommander->fileExists($newName, $extension)) {

            $imageManageResource = $this->imagesManager->loadImageManageResource($image->getName(), $image->getExtension());

            $imageManageResource->resize($width ?: 0, $height ?: 0);
            $imageManageResource->save($destinationPath, $newName, $extension);

            return $imageManageResource->getOutputImageResource();
        }

        return $this->imageCacheDirCommander->getImage($newName, $extension);
    }

    /**
     * @param string $imageName
     * @param int $width
     * @param int $height
     * @param bool $thumb
     * @return string
     */
    protected function getVariantName(string $imageName, int $width = 0, $height = 0, bool $thumb = false): string
    {
        return $imageName . ($thumb ? '-thumb' : '') . (($width > 0) ? '-w' . $width : '') . (($height > 0) ? '-h' . $height : '');
    }

    /**
     * @param BitmapImageFileResource $image
     * @param ImageResolutionSettings $resolutionSize
     * @param bool $extensionDirCreated
     * @param bool $thumb
     * @return BitmapImageFileResource|null
     * @throws FileNotFoundException
     * @throws \ImagickException
     * @throws \Optimal\FileManaging\Exception\CreateDirectoryException
     * @throws \Optimal\FileManaging\Exception\DirectoryNotFoundException
     * @throws \Optimal\FileManaging\Exception\FileException
     */
    protected function createVariant(BitmapImageFileResource $image, ImageResolutionSettings $resolutionSize, bool &$extensionDirCreated, bool $thumb): ?BitmapImageFileResource
    {
        $imageName = $image->getName();

        $width = $resolutionSize->getWidth();
        $height = $resolutionSize->getHeight();

        if ($width && $height) {
            if (($width > $image->getWidth()) && ($height > $image->getHeight())) {
                return null;
            }
        }
        elseif ($height) {
            if ($height > $image->getHeight()) {
                return null;
            }
        }
        elseif ($width > $image->getWidth()) {
            return null;
        }

        $extensionMap = $this->extensionsMap[$image->getExtension()];

        foreach ($extensionMap as $extension) {

            if ($extension === "default") {
                $extension = $image->getExtension();
            }

            if ($extension === FilesTypes::IMAGES_WEBP[0] && !$this->isWebPSupported()) {
                continue;
            }

            if (!$extensionDirCreated) {
                $this->imageCacheDirCommander->addDirectory($extension, true);
                $extensionDirCreated = true;
            }

            $newName = $this->getVariantName($imageName, $width, $height, $thumb);

            /** @var BitmapImageFileResource $imageVariant */
            $imageVariant = $this->createImageSize($image, $this->imageCacheDirCommander->getAbsolutePath(), $newName, $extension, $width, $height);
            return $imageVariant;
        }

        return null;
    }

    /**
     * @param string $imagePath
     * @param ImageResolutionsSettings|null $resolutionsSettings
     * @param bool $fileIsChanged
     * @param bool $thumb
     * @return array
     * @throws DirectoryException
     * @throws FileNotFoundException
     * @throws \ImagickException
     * @throws \Optimal\FileManaging\Exception\CreateDirectoryException
     * @throws \Optimal\FileManaging\Exception\DeleteDirectoryException
     * @throws \Optimal\FileManaging\Exception\DeleteFileException
     * @throws \Optimal\FileManaging\Exception\DirectoryNotFoundException
     * @throws \Optimal\FileManaging\Exception\FileException
     */
    protected function createVariants(string $imagePath, ?ImageResolutionsSettings $resolutionsSettings, bool $fileIsChanged, bool $thumb = false): array
    {
        $image = new BitmapImageFileResource($imagePath);
        $this->imageDirectoryCommander->setPath($image->getFileDirectoryPath());

        $imageVariants = [];

        $this->imagesManager->setSourceDirectory($image->getFileDirectoryPath());

        $cacheDirPath = $this->imageCacheDirCommander->getAbsolutePath();
        $imagePath = $this->imageDirectoryCommander->getAbsolutePath();

        $this->checkImagesCachePath($imagePath, $cacheDirPath);

        if (!is_null($resolutionsSettings) && $image->getWidth() >= $this->createVariantsBottomLimit) {

            if (!$this->imageCacheDirCommander) {
                throw new DirectoryException("Images variants cache directory is not defined");
            }

            $this->imageCacheDirCommander->addDirectory($image->getName(), true);
            $this->imageCacheDirCommander->addDirectory($thumb ? 'thumbs' : 'image_variants', true);

            if ($fileIsChanged) {
                $this->imageCacheDirCommander->clearDir();
            }

            $extensionDirCreated = false;

            /** @var ImageResolutionSettings $resolutionSize */
            foreach ($this->resolutionSizes->getResolutionsSettings() as $resolutionSize) {
                $variant = $this->createVariant($image, $resolutionSize, $extensionDirCreated, $thumb);
                if (!is_null($variant)) {
                    $imageVariants[] = $variant;
                }
            }

            $this->imageCacheDirCommander->moveUp();
            $this->imageCacheDirCommander->setPath($cacheDirPath);
        }

        $this->imageCacheDirCommander->setPath($cacheDirPath);

        return $imageVariants;
    }

    /**
     * @param string $imagePath
     * @param bool $fileIsChanged
     * @return array
     * @throws DirectoryException
     * @throws FileNotFoundException
     * @throws \ImagickException
     * @throws \Optimal\FileManaging\Exception\CreateDirectoryException
     * @throws \Optimal\FileManaging\Exception\DeleteDirectoryException
     * @throws \Optimal\FileManaging\Exception\DeleteFileException
     * @throws \Optimal\FileManaging\Exception\DirectoryNotFoundException
     * @throws \Optimal\FileManaging\Exception\FileException
     */
    public function createImageVariants(string $imagePath, bool $fileIsChanged): array
    {
        return $this->createVariants($imagePath, $this->resolutionSizes, $fileIsChanged);
    }

    /**
     * @param string $imageThumbPath
     * @param bool $fileIsChanged
     * @return array
     * @throws DirectoryException
     * @throws FileNotFoundException
     * @throws \ImagickException
     * @throws \Optimal\FileManaging\Exception\CreateDirectoryException
     * @throws \Optimal\FileManaging\Exception\DeleteDirectoryException
     * @throws \Optimal\FileManaging\Exception\DeleteFileException
     * @throws \Optimal\FileManaging\Exception\DirectoryNotFoundException
     * @throws \Optimal\FileManaging\Exception\FileException
     */
    public function createImageThumbVariants(string $imageThumbPath, bool $fileIsChanged): array
    {
        return $this->createVariants($imageThumbPath, $this->thumbResolutionSizes, $fileIsChanged, true);
    }

    /**
     * @param array $variants
     * @return string|null
     */
    protected function prepareSrcSet(array $variants): ?string
    {

        if (empty($variants)) {
            return null;
        }

        $template = $this->templateFactory->createTemplate();
        $template->variants = $variants;
        $template->setFile(__DIR__ . '/templates/srcset.latte');
        return trim(preg_replace('/\s\s+/', ' ', $template));
    }

    /**
     * @param array $classes
     * @return string
     */
    protected function prepareClass(array $classes): string
    {
        $template = $this->templateFactory->createTemplate();
        $template->classes = $classes;
        $template->setFile(__DIR__ . '/templates/class.latte');
        return trim(preg_replace('/\s\s+/', ' ', $template));
    }

    /**
     * @param string $imgPath
     * @param array $imageData
     * @param string $alt
     * @param string $devicesSizes
     * @param bool|null $lazyLoad
     * @param array $attributes
     * @return string
     */
    protected function prepareImgTag(string $imgPath, array $imageData, string $alt, string $devicesSizes, ?bool $lazyLoad, array $attributes = []): string
    {
        $template = $this->templateFactory->createTemplate();
        $template->src = $imgPath;
        $template->alt = $alt;
        $template->srcset = $this->prepareSrcSet($imageData);

        $classes = [];

        if ($lazyLoad != null) {
            if ($lazyLoad) {
                $classes[] = 'lazy-image';
            }
        }

        if (isset($attributes["class"])) {
            $classes[] = $attributes["class"];
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
    protected function serializeResolutionSizes(ImageResolutionsSettings $resolutions): string
    {
        $arr = [];
        foreach ($resolutions->getResolutionsSettings() as $resolutionSize) {
            $arr[] = 'w' . $resolutionSize->getWidth() . ",h" . $resolutionSize->getHeight();
        }
        return implode(';', $arr);
    }

    /**
     * @param bool|null $lazyLoad
     * @param string $devicesSizes
     * @return array
     */
    protected function checkThumbDefaultParams(?bool $lazyLoad = null, string $devicesSizes = ""): array
    {

        if (is_null($lazyLoad) && !is_null($this->defaultLazyLoad)) {
            $lazyLoad = $this->defaultLazyLoad;
        }

        if (empty($devicesSizes) && !empty($this->defaultThumbSizes)) {
            $devicesSizes = $this->defaultThumbSizes;
        }

        return [$lazyLoad, $devicesSizes];
    }

    /**
     * @param bool|null $lazyLoad
     * @param string $devicesSizes
     * @return array
     */
    protected function checkDefaultParams(?bool $lazyLoad = null, string $devicesSizes = ""): array
    {
        if (is_null($lazyLoad) && !is_null($this->defaultLazyLoad)) {
            $lazyLoad = $this->defaultLazyLoad;
        }

        if (empty($devicesSizes) && !empty($this->defaultSizes)) {
            $devicesSizes = $this->defaultSizes;
        }

        return [$lazyLoad, $devicesSizes];
    }

    /**
     * @param $data
     * @param $fileHash
     * @param $lastTime
     * @return bool
     */
    protected function isFileChanged($data, $fileHash, $lastTime): bool
    {
        return !$data ? false : $data['lastHash'] != $fileHash || $data['lastModifiedTime'] != $lastTime;
    }

    /**
     * @param string $imagePath
     * @param string $alt
     * @param ImageResolutionsSettings $resolutionsSettings
     * @param bool|null $lazyLoad
     * @param string $devicesSizes
     * @param array $attributes
     * @param bool $thumb
     * @return string
     * @throws DirectoryException
     * @throws FileNotFoundException
     * @throws \ImagickException
     * @throws \Optimal\FileManaging\Exception\CreateDirectoryException
     * @throws \Optimal\FileManaging\Exception\DeleteDirectoryException
     * @throws \Optimal\FileManaging\Exception\DeleteFileException
     * @throws \Optimal\FileManaging\Exception\DirectoryNotFoundException
     * @throws \Optimal\FileManaging\Exception\FileException
     * @throws \Throwable
     */
    protected function prepareImageWithMultipleVariants(string $imagePath, string $alt, ImageResolutionsSettings $resolutionsSettings, ?bool $lazyLoad = null, string $devicesSizes = "", array $attributes = [], bool $thumb = false): string
    {
        if (!$this->imageCacheDirCommander) {
            throw new \Exception('Images variants cache directory is not set');
        }

        [$lazyLoad, $devicesSizes] = $this->checkDefaultParams($lazyLoad, $devicesSizes);

        $key = md5((string)$this->isWebPSupported() . $imagePath . file_get_contents($imagePath) . $this->serializeResolutionSizes($resolutionsSettings) . $alt . $devicesSizes . $lazyLoad . implode(';', $attributes));

        $data = $this->cache->load($key);

        $fileHash = sha1(file_get_contents($imagePath));
        $lastTime = filectime($imagePath);
        $fileChanged = $this->isFileChanged($data, $fileHash, $lastTime);

        if (!$data || $fileChanged) {

            $imageData = $this->createVariants($imagePath, $thumb ? $this->thumbResolutionSizes : $this->resolutionSizes, $fileChanged, $thumb);
            $imgTag = $this->prepareImgTag($imagePath, $imageData, $alt, $devicesSizes, $lazyLoad, $attributes);

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
     * @param ImageResolutionSettings $resolutionSettings
     * @param string $alt
     * @param bool|null $lazyLoad
     * @param array $attributes
     * @param bool $thumb
     * @return array
     * @throws DirectoryException
     * @throws FileNotFoundException
     * @throws \ImagickException
     * @throws \Optimal\FileManaging\Exception\CreateDirectoryException
     * @throws \Optimal\FileManaging\Exception\DeleteDirectoryException
     * @throws \Optimal\FileManaging\Exception\DeleteFileException
     * @throws \Optimal\FileManaging\Exception\DirectoryNotFoundException
     * @throws \Optimal\FileManaging\Exception\FileException
     * @throws \Throwable
     */
    protected function prepareImageWithSingleVariant(string $imagePath, ImageResolutionSettings $resolutionSettings, string $alt, ?bool $lazyLoad, array $attributes, bool $thumb = false):array
    {
        $key = md5((string) $this->isWebPSupported() . $imagePath . $resolutionSettings->getWidth() . $resolutionSettings->getHeight() . file_get_contents($imagePath));

        $data = $this->cache->load($key);

        $fileHash = sha1(file_get_contents($imagePath));
        $lastTime = filectime($imagePath);
        $fileChanged = $this->isFileChanged($data, $fileHash, $lastTime);

        if (!$data || $fileChanged) {

            $image = new BitmapImageFileResource($imagePath);

            $this->imagesManager->setSourceDirectory($image->getFileDirectoryPath());
            $this->imageDirectoryCommander->setPath($image->getFileDirectoryPath());

            $cacheDirPath = $this->imageCacheDirCommander->getAbsolutePath();

            $this->checkImagesCachePath($this->imageDirectoryCommander->getAbsolutePath(), $cacheDirPath);

            if (!$this->imageCacheDirCommander) {
                throw new DirectoryException("Images variants cache directory is not defined");
            }

            $this->imageCacheDirCommander->addDirectory($image->getName(), true);
            $this->imageCacheDirCommander->addDirectory($thumb ? 'thumbs' : 'image_variants', true);

            if ($fileChanged) {
                $this->imageCacheDirCommander->clearDir();
            }

            $extensionDirCreated = false;
            $variant = $this->createVariant($image, $resolutionSettings, $extensionDirCreated, $thumb);

            $this->imageCacheDirCommander->setPath($cacheDirPath);

            if(!is_null($variant)){
                $imgTag = $this->prepareImgTag($variant->getFileRelativePath(), [], $alt, '', $lazyLoad, $attributes);
                $src = $variant->getFileRelativePath();
            } else {
                $imgTag = $this->prepareImgTag($imagePath, [], $alt, '', $lazyLoad, $attributes);
                $src = $imagePath;
            }

            $data = [
                'img' => $imgTag,
                'src' => $src,
                'lastHash' => $fileHash,
                'lastModifiedTime' => $lastTime
            ];

            $this->cache->save($key, $data, [
                Cache::EXPIRE => '12 months',
                Cache::SLIDING => true,
            ]);

        }

        return [$data['img'], $data['src']];
    }

    /**
     * @param string $imagePath
     * @param ImageResolutionsSettings $resolutionsSettings
     * @param bool $thumb
     * @throws DirectoryException
     * @throws FileNotFoundException
     * @throws \ImagickException
     * @throws \Optimal\FileManaging\Exception\CreateDirectoryException
     * @throws \Optimal\FileManaging\Exception\DeleteDirectoryException
     * @throws \Optimal\FileManaging\Exception\DeleteFileException
     * @throws \Optimal\FileManaging\Exception\DirectoryNotFoundException
     * @throws \Optimal\FileManaging\Exception\FileException
     * @throws \Throwable
     */
    protected function renderSrcSet(string $imagePath, ImageResolutionsSettings $resolutionsSettings, bool $thumb = false): void
    {
        $key = md5((string) $this->isWebPSupported() . $imagePath . file_get_contents($imagePath));

        $data = $this->cache->load($key);

        $fileHash = sha1(file_get_contents($imagePath));
        $lastTime = filectime($imagePath);
        $fileChanged = $this->isFileChanged($data, $fileHash, $lastTime);

        if (!$data || $fileChanged) {
            $imageData = $this->createVariants($imagePath, $resolutionsSettings, $fileChanged, $thumb);
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
     * @param string|null $imageThumbPath
     * @throws DirectoryException
     * @throws FileNotFoundException
     * @throws \ImagickException
     * @throws \Optimal\FileManaging\Exception\CreateDirectoryException
     * @throws \Optimal\FileManaging\Exception\DeleteDirectoryException
     * @throws \Optimal\FileManaging\Exception\DeleteFileException
     * @throws \Optimal\FileManaging\Exception\DirectoryNotFoundException
     * @throws \Optimal\FileManaging\Exception\FileException
     * @throws \Throwable
     */
    public function renderImageThumbSrcSet(?string $imageThumbPath): void
    {
        $imageThumbPath = $this->checkImage($imageThumbPath);
        $this->renderSrcSet($imageThumbPath, $this->thumbResolutionSizes, true);
    }

    /**
     * @param string|null $imageThumbPath
     * @return string
     * @throws DirectoryException
     * @throws FileNotFoundException
     * @throws \ImagickException
     * @throws \Optimal\FileManaging\Exception\CreateDirectoryException
     * @throws \Optimal\FileManaging\Exception\DeleteDirectoryException
     * @throws \Optimal\FileManaging\Exception\DeleteFileException
     * @throws \Optimal\FileManaging\Exception\DirectoryNotFoundException
     * @throws \Optimal\FileManaging\Exception\FileException
     * @throws \Throwable
     */
    public function getImageThumbSrcSet(?string $imageThumbPath): string
    {
        ob_start();
        $this->renderImageThumbSrcSet($imageThumbPath);
        return ob_get_contents();
    }

    public function renderDefaultThumbSizes(): void
    {
        echo $this->defaultThumbSizes;
    }

    /**
     * @return string
     */
    public function getDefaultThumbSizes(): string
    {
        return $this->defaultThumbSizes;
    }

    /**
     * @param string|null $imageThumbPath
     * @param string $alt
     * @param bool|null $lazyLoad
     * @param string $sizes
     * @param string|null $caption
     * @param array $attributes
     * @throws DirectoryException
     * @throws FileNotFoundException
     * @throws \ImagickException
     * @throws \Optimal\FileManaging\Exception\CreateDirectoryException
     * @throws \Optimal\FileManaging\Exception\DeleteDirectoryException
     * @throws \Optimal\FileManaging\Exception\DeleteFileException
     * @throws \Optimal\FileManaging\Exception\DirectoryNotFoundException
     * @throws \Optimal\FileManaging\Exception\FileException
     * @throws \Throwable
     */
    public function renderImageThumb(?string $imageThumbPath, string $alt, ?bool $lazyLoad = null, string $sizes = "", string $caption = null, array $attributes = []): void
    {
        $imageThumbPath = $this->checkImage($imageThumbPath);
        $this->template->setFile(__DIR__ . '/templates/image.latte');
        $this->template->imgTag = $this->prepareImageWithMultipleVariants($imageThumbPath, $alt, $this->thumbResolutionSizes, $lazyLoad, $sizes, $attributes, true);
        $this->template->caption = $caption;
        $this->template->render();
    }

    /**
     * @param string|null $imageThumbPath
     * @param string $alt
     * @param bool|null $lazyLoad
     * @param string $devicesSizes
     * @param string|null $caption
     * @param array $attributes
     * @return string
     * @throws DirectoryException
     * @throws FileNotFoundException
     * @throws \ImagickException
     * @throws \Optimal\FileManaging\Exception\CreateDirectoryException
     * @throws \Optimal\FileManaging\Exception\DeleteDirectoryException
     * @throws \Optimal\FileManaging\Exception\DeleteFileException
     * @throws \Optimal\FileManaging\Exception\DirectoryNotFoundException
     * @throws \Optimal\FileManaging\Exception\FileException
     * @throws \Throwable
     */
    public function getImageThumb(?string $imageThumbPath, string $alt, ?bool $lazyLoad = null, string $devicesSizes = "", string $caption = null, array $attributes = []): string
    {
        ob_start();
        $this->renderImageThumb($imageThumbPath, $alt, $lazyLoad, $devicesSizes, $caption, $attributes);
        return ob_get_contents();
    }

    /**
     * @param string|null $imageThumbPath
     * @param ImageResolutionSettings $resolutionSettings
     * @param string $alt
     * @param string $caption
     * @param bool|null $lazyLoad
     * @param array $attributes
     * @throws DirectoryException
     * @throws FileNotFoundException
     * @throws \ImagickException
     * @throws \Optimal\FileManaging\Exception\CreateDirectoryException
     * @throws \Optimal\FileManaging\Exception\DeleteDirectoryException
     * @throws \Optimal\FileManaging\Exception\DeleteFileException
     * @throws \Optimal\FileManaging\Exception\DirectoryNotFoundException
     * @throws \Optimal\FileManaging\Exception\FileException
     * @throws \Throwable
     */
    public function renderImageThumbVariant(?string $imageThumbPath, ImageResolutionSettings $resolutionSettings, string $alt, string $caption, ?bool $lazyLoad, array $attributes):void
    {
        $imageThumbPath = $this->checkImage($imageThumbPath);

        $this->template->setFile(__DIR__ . '/templates/image.latte');

        [$imgTag] = $this->prepareImageWithSingleVariant($imageThumbPath, $resolutionSettings, $alt, $lazyLoad, $attributes, true);

        $this->template->imgTag = $imgTag;
        $this->template->caption = $caption;
        $this->template->render();
    }

    /**
     * @param string|null $imageThumbPath
     * @param ImageResolutionSettings $resolutionSettings
     * @param string $alt
     * @param string $caption
     * @param bool|null $lazyLoad
     * @param array $attributes
     * @return string
     * @throws DirectoryException
     * @throws FileNotFoundException
     * @throws \ImagickException
     * @throws \Optimal\FileManaging\Exception\CreateDirectoryException
     * @throws \Optimal\FileManaging\Exception\DeleteDirectoryException
     * @throws \Optimal\FileManaging\Exception\DeleteFileException
     * @throws \Optimal\FileManaging\Exception\DirectoryNotFoundException
     * @throws \Optimal\FileManaging\Exception\FileException
     * @throws \Throwable
     */
    public function getImageThumbVariant(?string $imageThumbPath, ImageResolutionSettings $resolutionSettings, string $alt, string $caption, ?bool $lazyLoad, array $attributes):string
    {
        ob_start();
        $this->renderImageThumbVariant($imageThumbPath, $resolutionSettings, $alt, $caption, $lazyLoad, $attributes);
        return ob_get_contents();
    }

    /**
     * @param string|null $imageThumbPath
     * @param ImageResolutionSettings $resolutionSettings
     * @return string
     * @throws DirectoryException
     * @throws FileNotFoundException
     * @throws \ImagickException
     * @throws \Optimal\FileManaging\Exception\CreateDirectoryException
     * @throws \Optimal\FileManaging\Exception\DeleteDirectoryException
     * @throws \Optimal\FileManaging\Exception\DeleteFileException
     * @throws \Optimal\FileManaging\Exception\DirectoryNotFoundException
     * @throws \Optimal\FileManaging\Exception\FileException
     * @throws \Throwable
     */
    public function getImageThumbVariantSrc(?string $imageThumbPath, ImageResolutionSettings $resolutionSettings):string
    {
        $imageThumbPath = $this->checkImage($imageThumbPath);
        [, $src] = $this->prepareImageWithSingleVariant($imageThumbPath, $resolutionSettings, '', null, []);
        return $src;
    }

    /**
     * @param string|null $imagePath
     * @throws DirectoryException
     * @throws FileNotFoundException
     * @throws \ImagickException
     * @throws \Optimal\FileManaging\Exception\CreateDirectoryException
     * @throws \Optimal\FileManaging\Exception\DeleteDirectoryException
     * @throws \Optimal\FileManaging\Exception\DeleteFileException
     * @throws \Optimal\FileManaging\Exception\DirectoryNotFoundException
     * @throws \Optimal\FileManaging\Exception\FileException
     * @throws \Throwable
     */
    public function renderImageSrcSet(?string $imagePath): void
    {
        $imagePath = $this->checkImage($imagePath);
        $this->renderSrcSet($imagePath, $this->resolutionSizes);
    }

    /**
     * @param string|null $imagePath
     * @return string
     * @throws DirectoryException
     * @throws FileNotFoundException
     * @throws \ImagickException
     * @throws \Optimal\FileManaging\Exception\CreateDirectoryException
     * @throws \Optimal\FileManaging\Exception\DeleteDirectoryException
     * @throws \Optimal\FileManaging\Exception\DeleteFileException
     * @throws \Optimal\FileManaging\Exception\DirectoryNotFoundException
     * @throws \Optimal\FileManaging\Exception\FileException
     * @throws \Throwable
     */
    public function getImageSrcSet(?string $imagePath): string
    {
        ob_start();
        $this->renderImageSrcSet($imagePath);
        return ob_get_contents();
    }

    public function renderDefaultSizes(): void
    {
        echo $this->defaultSizes;
    }

    /**
     * @return string
     */
    public function getDefaultSizes(): string
    {
        return $this->defaultSizes;
    }

    /**
     * @param string|null $imagePath
     * @param string $alt
     * @param bool|null $lazyLoad
     * @param string $sizes
     * @param string|null $caption
     * @param array $attributes
     * @throws DirectoryException
     * @throws FileNotFoundException
     * @throws \ImagickException
     * @throws \Optimal\FileManaging\Exception\CreateDirectoryException
     * @throws \Optimal\FileManaging\Exception\DeleteDirectoryException
     * @throws \Optimal\FileManaging\Exception\DeleteFileException
     * @throws \Optimal\FileManaging\Exception\DirectoryNotFoundException
     * @throws \Optimal\FileManaging\Exception\FileException
     * @throws \Throwable
     */
    public function renderImage(?string $imagePath, string $alt, ?bool $lazyLoad = null, string $sizes = "", string $caption = null, array $attributes = []): void
    {
        $imagePath = $this->checkImage($imagePath);

        $this->template->setFile(__DIR__ . '/templates/image.latte');
        $this->template->imgTag = $this->prepareImageWithMultipleVariants($imagePath, $alt, $this->resolutionSizes, $lazyLoad, $sizes, $attributes);

        $this->template->lightbox = false;
        $this->template->caption = $caption;

        $this->template->render();
    }

    /**
     * @param string|null $imagePath
     * @param string $alt
     * @param bool|null $lazyLoad
     * @param string $devicesSizes
     * @param string|null $caption
     * @param array $attributes
     * @return string
     * @throws DirectoryException
     * @throws FileNotFoundException
     * @throws \ImagickException
     * @throws \Optimal\FileManaging\Exception\CreateDirectoryException
     * @throws \Optimal\FileManaging\Exception\DeleteDirectoryException
     * @throws \Optimal\FileManaging\Exception\DeleteFileException
     * @throws \Optimal\FileManaging\Exception\DirectoryNotFoundException
     * @throws \Optimal\FileManaging\Exception\FileException
     * @throws \Throwable
     */
    public function getImage(?string $imagePath, string $alt, ?bool $lazyLoad = null, string $devicesSizes = "", string $caption = null, array $attributes = []): string
    {
        ob_start();
        $this->renderImage($imagePath, $alt, $lazyLoad, $devicesSizes, $caption, $attributes);
        return ob_get_contents();
    }

    /**
     * @param string|null $imagePath
     * @param ImageResolutionSettings $resolutionSettings
     * @param string $alt
     * @param string $caption
     * @param bool|null $lazyLoad
     * @param array $attributes
     * @throws DirectoryException
     * @throws FileNotFoundException
     * @throws \ImagickException
     * @throws \Optimal\FileManaging\Exception\CreateDirectoryException
     * @throws \Optimal\FileManaging\Exception\DeleteDirectoryException
     * @throws \Optimal\FileManaging\Exception\DeleteFileException
     * @throws \Optimal\FileManaging\Exception\DirectoryNotFoundException
     * @throws \Optimal\FileManaging\Exception\FileException
     * @throws \Throwable
     */
    public function renderImageVariant(?string $imagePath, ImageResolutionSettings $resolutionSettings, string $alt, string $caption, ?bool $lazyLoad, array $attributes):void
    {
        $imagePath = $this->checkImage($imagePath);

        $this->template->setFile(__DIR__ . '/templates/image.latte');

        [$imgTag] = $this->prepareImageWithSingleVariant($imagePath, $resolutionSettings, $alt, $lazyLoad, $attributes);

        $this->template->imgTag = $imgTag;
        $this->template->caption = $caption;
        $this->template->render();
    }

    /**
     * @param string|null $imagePath
     * @param ImageResolutionSettings $resolutionSettings
     * @param string $alt
     * @param string $caption
     * @param bool|null $lazyLoad
     * @param array $attributes
     * @return string
     * @throws DirectoryException
     * @throws FileNotFoundException
     * @throws \ImagickException
     * @throws \Optimal\FileManaging\Exception\CreateDirectoryException
     * @throws \Optimal\FileManaging\Exception\DeleteDirectoryException
     * @throws \Optimal\FileManaging\Exception\DeleteFileException
     * @throws \Optimal\FileManaging\Exception\DirectoryNotFoundException
     * @throws \Optimal\FileManaging\Exception\FileException
     * @throws \Throwable
     */
    public function getImageVariant(?string $imagePath, ImageResolutionSettings $resolutionSettings, string $alt, string $caption, ?bool $lazyLoad, array $attributes):string
    {
        ob_start();
        $this->renderImageVariant($imagePath, $resolutionSettings, $alt, $caption, $lazyLoad, $attributes);
        return ob_get_contents();
    }

    /**
     * @param string|null $imagePath
     * @param ImageResolutionSettings $resolutionSettings
     * @return string
     * @throws DirectoryException
     * @throws FileNotFoundException
     * @throws \ImagickException
     * @throws \Optimal\FileManaging\Exception\CreateDirectoryException
     * @throws \Optimal\FileManaging\Exception\DeleteDirectoryException
     * @throws \Optimal\FileManaging\Exception\DeleteFileException
     * @throws \Optimal\FileManaging\Exception\DirectoryNotFoundException
     * @throws \Optimal\FileManaging\Exception\FileException
     * @throws \Throwable
     */
    public function getImageVariantSrc(?string $imagePath, ImageResolutionSettings $resolutionSettings):string
    {
        $imagePath = $this->checkImage($imagePath);

        [, $src] = $this->prepareImageWithSingleVariant($imagePath, $resolutionSettings, '', null, []);
        return $src;
    }

}