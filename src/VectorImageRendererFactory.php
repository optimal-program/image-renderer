<?php declare(strict_types=1);

namespace Optimal\ImageRenderer;

interface VectorImageRendererFactory
{

    /** @return VectorImageRenderer */
    public function create():VectorImageRenderer;

}