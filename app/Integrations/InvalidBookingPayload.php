<?php

namespace App\Integrations;

use RuntimeException;

/** A booking site sent something we cannot read. The message is safe to show to the organiser. */
class InvalidBookingPayload extends RuntimeException {}
