<?php

namespace Foundation\Http;

/** http.cookiejar.LoadError (an OSError). Also thrown for an unreadable cookie file (FileNotFoundError...). */
class LoadError extends \Foundation\OSError
{
}
