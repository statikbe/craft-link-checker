<?php

namespace statikbe\linkchecker\helpers;

/**
 * A request didn't connect to the address that was checked (a proxy, a pin that didn't apply): its answer isn't used.
 */
class UnpinnedException extends \RuntimeException
{
}
