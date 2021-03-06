<?php

namespace Optimal\ImageRenderer;

use Nette\Application\UI;

class VectorImageRenderer extends UI\Control
{

    /** @var UI\TemplateFactory */
    private $templateFactory;

    public function __construct(UI\TemplateFactory $templateFactory)
    {
        $this->templateFactory = $templateFactory;
    }

    protected function prepareClass(array $classes)
    {
        $template = $this->templateFactory->createTemplate();
        $template->classes = $classes;
        $template->setFile(__DIR__ . '/templates/class.latte');
        return trim(preg_replace('/\s\s+/', ' ', $template));
    }

    /**
     * @param string $svgPath
     * @param string $alt
     * @param array $attributes
     */
    public function render(string $svgPath, string $alt, array $attributes = [])
    {
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
     * @param string $svgPath
     * @param string $alt
     * @param array $attributes
     * @return string
     */
    public function renderAsString(string $svgPath, string $alt, array $attributes = []):string
    {
        ob_start();
        $this->render($svgPath, $alt, $attributes);
        return ob_end_flush();
    }

    /**
     * @param string $svgPath
     */
    public function renderInline(string $svgPath):void
    {
        $this->template->setFile(__DIR__ . '/templates/inlineSvg.latte');
        $this->template->svgContent = file_get_contents($svgPath);
        $this->template->render();
    }

    /**
     * @param string $svgPath
     * @return string
     */
    public function renderInlineAsString(string $svgPath):string
    {
        ob_start();
        $this->renderInline($svgPath);
        return ob_end_flush();
    }

}