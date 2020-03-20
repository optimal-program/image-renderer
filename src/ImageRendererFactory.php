<?php

namespace Optimal\ImageRenderer;

interface ImageRendererFactory
{

    /** @return ImageRenderer */
    function create();

}