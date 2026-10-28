<?php

namespace App\Services\Banking\Feeds;

use RuntimeException;

/**
 * A bank-feed provider could not be reached or refused the request. The
 * message is safe to show the user. Each provider throws its own subclass.
 */
class BankFeedException extends RuntimeException {}
