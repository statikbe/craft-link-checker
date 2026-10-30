<?php

namespace statikbe\linkchecker\helpers;

/**
 * Stops a GET request once its headers are in: the body of an external page isn't needed.
 */
class HeadersReceived extends \RuntimeException
{
}
