<?php

namespace App\Components\ImageRenderer;

interface ImageRendererFactory
{

    /** @return ImageRenderer */
    function create();

}