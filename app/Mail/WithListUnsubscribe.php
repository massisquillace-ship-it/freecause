<?php

namespace App\Mail;

use Symfony\Component\Mime\Email;

trait WithListUnsubscribe
{
    protected function listUnsubscribe(string $url): static
    {
        return $this->withSymfonyMessage(function (Email $message) use ($url) {
            $message->getHeaders()
                ->addTextHeader('List-Unsubscribe', '<'.$url.'>')
                ->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        });
    }
}
