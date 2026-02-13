<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class EmailBindingVerificationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $token)
    {
    }

    public function build(): self
    {
        $content = sprintf(
            '<p>Your email binding verification token is:</p><p><strong>%s</strong></p>',
            e($this->token)
        );

        return $this
            ->subject('Email Binding Verification')
            ->html($content);
    }
}
