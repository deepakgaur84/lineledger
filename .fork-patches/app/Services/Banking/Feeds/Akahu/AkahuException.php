<?php

namespace App\Services\Banking\Feeds\Akahu;

use App\Services\Banking\Feeds\BankFeedException;

/**
 * The Akahu API could not be reached, or refused the request (bad or revoked
 * tokens, rate limit). The message is safe to show the user.
 */
class AkahuException extends BankFeedException {}
