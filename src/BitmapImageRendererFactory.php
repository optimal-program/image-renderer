<?php declare(strict_types=1);

namespace Optimal\ImageRenderer;

interface BitmapImageRendererFactory
{

    /** @return BitmapImageRenderer */
    public function create():BitmapImageRenderer;

}