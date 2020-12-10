<?php

namespace Optimal\ImageRenderer;

interface VectorImageRendererFactory
{

    /** @return VectorImageRenderer */
    function create();

}