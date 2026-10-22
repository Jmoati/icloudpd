<?php

namespace Foundation\Http;

/** The request timed out. Catching this error will catch both ConnectTimeout and ReadTimeout errors. */
class Timeout extends RequestException
{
}
