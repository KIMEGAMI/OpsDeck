<?php

namespace App\Mail;

use Symfony\Component\Mailer\Exception\TransportException;

class ResendIdempotencyConflictException extends TransportException {}
