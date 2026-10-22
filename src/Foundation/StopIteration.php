<?php

namespace Foundation;

/** Python's `StopIteration`: `Json::first` returns an instance of it for an empty iterable */
class StopIteration extends \Exception
{
}
