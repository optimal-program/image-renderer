<?php declare(strict_types=1);

namespace Optimal\ImageRenderer;

use Exception;
use Nette\Application\UI\Control;
use Nette\Application\UI\TemplateFactory;
use Optimal\FileManaging\FileCommander;
use RuntimeException;

class VectorImageRenderer extends Control
{
    private TemplateFactory $templateFactory;
    protected string $noImagePath;

    public function __construct(TemplateFactory $templateFactory)
    {
        $this->templateFactory = $templateFactory;
    }

    /**
     * @param string $noImagePath
     * @throws Exception
     */
    public function setNoImagePath(string $noImagePath): void
    {
        if(FileCommander::isBitmapImage(pathinfo($noImagePath, PATHINFO_EXTENSION))){
            throw new RuntimeException('No-image is not vector.');
        }

        $this->noImagePath = $noImagePath;
    }

    /**
     * @param string|null $imagePath
     * @return string
     * @throws Exception
     */
    protected function checkImage(?string $imagePath):string
    {

        if (!is_null($imagePath)) {

            if(FileCommander::isBitmapImage(pathinfo($imagePath, PATHINFO_EXTENSION))){
                throw new RuntimeException('Image is not vector.');
            }

            if(filter_var($imagePath, FILTER_VALIDATE_URL)) {
                $headers=get_headers($imagePath);
                if(stripos($headers[0],"200 OK")) {
                    return $imagePath;
                }
            } elseif (file_exists($imagePath)) {
                return $imagePath;
            }

        }

        if(is_null($this->noImagePath)){
            throw new RuntimeException('No image is not set.');
        }

        return $this->noImagePath;
    }

    protected function prepareClass(array $classes): string
    {
        $template = $this->templateFactory->createTemplate();
        $template->classes = $classes;
        $template->setFile(__DIR__ . '/templates/class.latte');
        return trim(preg_replace('/\s\s+/', ' ', $template));
    }

    /**
     * @param string|null $svgPath
     * @param string $alt
     * @param array $attributes
     * @throws Exception
     */
    public function render(?string $svgPath, string $alt, array $attributes = []):void
    {
        $svgPath = $this->checkImage($svgPath);

        $this->template->setFile(__DIR__ . '/templates/imgtag.latte');

        $this->template->src = $svgPath;
        $this->template->alt = $alt;
        $this->template->srcset = '';

        $classes = [];

        if (isset($attributes["class"])) {
            $classes[] = $attributes["class"];
            unset($attributes["class"]);
        }

        $this->template->class = $this->prepareClass($classes);

        $this->template->sizes = '';
        $this->template->attributes = $attributes;
        $this->template->lazyLoad = false;

        $this->template->render();
    }

    /**
     * @param string|null $svgPath
     * @param string $alt
     * @param array $attributes
     * @return string
     * @throws Exception
     */
    public function renderAsString(?string $svgPath, string $alt, array $attributes = []):string
    {
        ob_start();
        $this->render($svgPath, $alt, $attributes);
        return ob_end_flush();
    }

    /**
     * @param string|null $svgPath
     * @throws Exception
     */
    public function renderInline(?string $svgPath):void
    {
        $svgPath = $this->checkImage($svgPath);

        $this->template->setFile(__DIR__ . '/templates/inlineSvg.latte');
        $this->template->svgContent = mb_convert_encoding(file_get_contents($svgPath), 'HTML-ENTITIES', "UTF-8");
        $this->template->render();
    }

    /**
     * @param string|null $svgPath
     * @return string
     * @throws Exception
     */
    public function renderInlineAsString(?string $svgPath):string
    {
        ob_start();
        $this->renderInline($svgPath);
        return ob_end_flush();
    }

}