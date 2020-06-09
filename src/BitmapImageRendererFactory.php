<?php

namespace Optimal\ImageRenderer;

interface BitmapImageRendererFactory
{

    /** @return BitmapImageRenderer */
    function create();

}